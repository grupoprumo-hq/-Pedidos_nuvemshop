<?php
/**
 * Módulo Brasil Gigante: pedido pago -> aviso ao Claudio -> venda e NF-e no eGestor.
 *
 * Regras:
 *  - Só pedidos pagos que tenham o produto Brasil Gigante (ID do produto, ID da variação ou SKU).
 *  - Dados pessoais (CPF, e-mail, telefone, endereço) são montados na hora e enviados; NÃO são gravados
 *    no banco do WordPress. O que fica gravado: número do pedido, etapa e códigos do eGestor.
 *  - Aviso uma única vez por pedido (controle pelo número do pedido).
 *  - eGestor: modo "desligado" por padrão. "simulado" não chama o eGestor. "venda" cria contato e venda.
 *    "venda_nfe" também pede a emissão da NF-e.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class PN_BG {

	const OPT_CFG   = 'pn_bg';
	const OPT_STATE = 'pn_bg_state';
	const OPT_LOG   = 'pn_bg_log';
	const MAX_DONE  = 1000;
	const MAX_LOG   = 40;

	public static function init() {
		add_action( 'pedidos_nuvemshop_synced', array( __CLASS__, 'on_synced' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'register_rest' ) );
		if ( is_admin() ) {
			add_action( 'admin_post_pn_bg_save', array( __CLASS__, 'handle_save' ) );
			add_action( 'admin_post_pn_bg_test', array( __CLASS__, 'handle_test' ) );
			add_action( 'admin_post_pn_bg_resend', array( __CLASS__, 'handle_resend' ) );
			add_action( 'admin_post_pn_bg_conn', array( __CLASS__, 'handle_conn' ) );
			add_action( 'admin_post_pn_bg_sync', array( __CLASS__, 'handle_sync' ) );
		}
	}

	/* ------------------------------------------------------------------
	 * Configuração
	 * ---------------------------------------------------------------- */

	public static function cfg() {
		$d = array(
			'avisos'            => 1,
			'webhook_url'       => '',
			'email_teste'       => '',
			'product_id'        => '378563445',
			'variant_id'        => '1631936515',
			'sku'               => 'BG-GOURMET-MOIDO-250',
			'egestor_modo'      => 'simulado',
			'egestor_cod_prod'  => '9',
			'egestor_cod_transp' => '',
		);
		return wp_parse_args( get_option( self::OPT_CFG, array() ), $d );
	}

	private static function modos() {
		return array(
			'off'       => 'Desligado',
			'simulado'  => 'Simulado (não chama o eGestor, só registra o que faria)',
			'venda'     => 'Criar contato e venda no eGestor (sem emitir nota)',
			'venda_nfe' => 'Criar venda e pedir a emissão da NF-e',
		);
	}

	private static function state() {
		$s = get_option( self::OPT_STATE, array() );
		return wp_parse_args( is_array( $s ) ? $s : array(), array( 'cursor' => '', 'done' => array(), 'orders' => array() ) );
	}

	private static function log( $pedido, $evento, $resultado ) {
		$l = get_option( self::OPT_LOG, array() );
		if ( ! is_array( $l ) ) {
			$l = array();
		}
		array_unshift( $l, array( 'quando' => current_time( 'mysql' ), 'pedido' => (string) $pedido, 'evento' => $evento, 'resultado' => mb_substr( (string) $resultado, 0, 300 ) ) );
		update_option( self::OPT_LOG, array_slice( $l, 0, self::MAX_LOG ), false );
	}

	/* ------------------------------------------------------------------
	 * Detecção
	 * ---------------------------------------------------------------- */

	public static function on_synced( $unused = null ) {
		self::run();
	}

	/** Trava: com "somente Brasil Gigante" ligado (padrão), o plugin só guarda e processa pedidos com o produto. */
	public static function only_bg() {
		return true; // trava fixa: o plugin só trata o Brasil Gigante.
	}

	public static function order_has_bg( $o ) {
		if ( ! is_array( $o ) || empty( $o['products'] ) ) {
			return false;
		}
		$c = self::cfg();
		foreach ( (array) $o['products'] as $it ) {
			if ( is_array( $it ) && self::is_bg_item( $it, $c ) ) {
				return true;
			}
		}
		return false;
	}

	public static function is_bg_line( array $it ) {
		return self::is_bg_item( $it, self::cfg() );
	}

	private static function is_bg_item( array $it, array $c ) {
		if ( ! empty( $c['product_id'] ) && isset( $it['product_id'] ) && (string) $it['product_id'] === (string) $c['product_id'] ) {
			return true;
		}
		if ( ! empty( $c['variant_id'] ) && isset( $it['variant_id'] ) && (string) $it['variant_id'] === (string) $c['variant_id'] ) {
			return true;
		}
		return ( ! empty( $c['sku'] ) && isset( $it['sku'] ) && (string) $it['sku'] === (string) $c['sku'] );
	}

	public static function run() {
		$c = self::cfg();
		if ( empty( $c['avisos'] ) && 'off' === $c['egestor_modo'] ) {
			return;
		}
		if ( get_transient( 'pn_bg_lock' ) ) {
			return;
		}
		set_transient( 'pn_bg_lock', 1, 5 * MINUTE_IN_SECONDS );

		$st    = self::state();
		$agora = time();
		if ( '' === $st['cursor'] ) {
			// Primeira vez: só pedidos novos daqui para a frente (não avisa o histórico).
			$st['cursor'] = gmdate( 'c', $agora - 60 );
			update_option( self::OPT_STATE, $st, false );
			delete_transient( 'pn_bg_lock' );
			return;
		}

		$r = PN_Pedidos_Nuvemshop::instance()->bg_api_get(
			'orders',
			array( 'payment_status' => 'paid', 'updated_at_min' => $st['cursor'], 'per_page' => 50 )
		);
		if ( ! is_wp_error( $r ) && 404 === (int) $r['code'] ) {
			$r = array( 'code' => 200, 'body' => array() ); // a Nuvemshop responde 404 quando não há pedidos no filtro: não é erro.
		}
		if ( is_wp_error( $r ) || 200 !== $r['code'] || ! is_array( $r['body'] ) ) {
			self::log( '-', 'consulta', is_wp_error( $r ) ? $r->get_error_message() : 'HTTP ' . (int) $r['code'] );
			delete_transient( 'pn_bg_lock' );
			return;
		}

		foreach ( $r['body'] as $o ) {
			if ( ! is_array( $o ) || empty( $o['number'] ) || empty( $o['products'] ) ) {
				continue;
			}
			$tem = false;
			foreach ( (array) $o['products'] as $it ) {
				if ( is_array( $it ) && self::is_bg_item( $it, $c ) ) {
					$tem = true;
					break;
				}
			}
			if ( ! $tem ) {
				continue;
			}
			$st = self::state(); // recarrega (cada pedido atualiza o estado).
			if ( in_array( (string) $o['number'], $st['done'], true ) ) {
				continue;
			}
			self::process_order( $o, false );
		}

		$st           = self::state();
		$st['cursor'] = gmdate( 'c', $agora - 5 * MINUTE_IN_SECONDS ); // sobreposição curta; a trava por número evita repetição.
		update_option( self::OPT_STATE, $st, false );
		delete_transient( 'pn_bg_lock' );
	}

	/* ------------------------------------------------------------------
	 * Montagem dos dados (nada disto é gravado)
	 * ---------------------------------------------------------------- */

	public static function payload( array $o ) {
		$c   = self::cfg();
		$bg  = null;
		$out = 0;
		foreach ( (array) $o['products'] as $it ) {
			if ( is_array( $it ) && self::is_bg_item( $it, $c ) ) {
				$bg = $bg ? $bg : $it;
			} else {
				$out++;
			}
		}
		$bg   = $bg ? $bg : array();
		$cust = isset( $o['customer'] ) && is_array( $o['customer'] ) ? $o['customer'] : array();
		$ad   = isset( $o['shipping_address'] ) && is_array( $o['shipping_address'] ) ? $o['shipping_address'] : array();
		$mail = isset( $cust['email'] ) ? (string) $cust['email'] : ( isset( $o['contact_email'] ) ? (string) $o['contact_email'] : '' );
		$cpf  = preg_replace( '/\D/', '', (string) ( isset( $cust['identification'] ) ? $cust['identification'] : ( isset( $o['contact_identification'] ) ? $o['contact_identification'] : '' ) ) );
		$pd   = isset( $o['payment_details'] ) && is_array( $o['payment_details'] ) ? $o['payment_details'] : array();

		$is_test = ( '' !== trim( (string) $c['email_teste'] ) && '' !== $mail && 0 === strcasecmp( $mail, trim( (string) $c['email_teste'] ) ) );

		$url   = isset( $o['landing_url'] ) ? (string) $o['landing_url'] : '';
		$utm   = array();
		$query = $url ? wp_parse_url( $url, PHP_URL_QUERY ) : '';
		if ( $query ) {
			parse_str( $query, $q );
			foreach ( array( 'utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term', 'src', 'sck' ) as $k ) {
				if ( ! empty( $q[ $k ] ) && is_string( $q[ $k ] ) ) {
					$utm[ $k ] = mb_substr( $q[ $k ], 0, 80 );
				}
			}
		}

		return array(
			'pedido'   => array(
				'numero'  => (string) $o['number'],
				'id'      => isset( $o['id'] ) ? (string) $o['id'] : '',
				'pago_em' => isset( $o['paid_at'] ) ? (string) $o['paid_at'] : '',
				'status'  => 'pago',
				'teste'   => $is_test,
				'misto'   => $out > 0,
			),
			'origem'   => $utm,
			'produto'  => array(
				'sku'        => isset( $bg['sku'] ) ? (string) $bg['sku'] : '',
				'quantidade' => isset( $bg['quantity'] ) ? (int) $bg['quantity'] : 0,
				'unitario'   => isset( $bg['price'] ) ? (float) $bg['price'] : 0.0,
			),
			'valores'  => array(
				'produtos'  => isset( $o['subtotal'] ) ? (float) $o['subtotal'] : 0.0,
				'frete'     => isset( $o['shipping_cost_customer'] ) ? (float) $o['shipping_cost_customer'] : 0.0,
				'desconto'  => isset( $o['discount'] ) ? (float) $o['discount'] : 0.0,
				'total'     => isset( $o['total'] ) ? (float) $o['total'] : 0.0,
				'pagamento' => isset( $pd['method'] ) ? (string) $pd['method'] : ( isset( $o['gateway_name'] ) ? (string) $o['gateway_name'] : '' ),
				'parcelas'  => isset( $pd['installments'] ) ? (int) $pd['installments'] : 1,
			),
			'envio'    => array(
				'servico' => isset( $o['shipping_option'] ) ? (string) $o['shipping_option'] : '',
				'status'  => isset( $o['shipping_status'] ) ? (string) $o['shipping_status'] : '',
				'rastreio' => isset( $o['shipping_tracking_number'] ) ? (string) $o['shipping_tracking_number'] : '',
			),
			'cliente'  => array(
				'nome'     => isset( $cust['name'] ) ? (string) $cust['name'] : ( isset( $o['contact_name'] ) ? (string) $o['contact_name'] : '' ),
				'cpf'      => $cpf,
				'email'    => $mail,
				'telefone' => preg_replace( '/\D/', '', (string) ( isset( $cust['phone'] ) ? $cust['phone'] : ( isset( $o['contact_phone'] ) ? $o['contact_phone'] : '' ) ) ),
			),
			'entrega'  => array(
				'cep'         => preg_replace( '/\D/', '', (string) ( isset( $ad['zipcode'] ) ? $ad['zipcode'] : '' ) ),
				'logradouro'  => isset( $ad['address'] ) ? (string) $ad['address'] : '',
				'numero'      => isset( $ad['number'] ) ? (string) $ad['number'] : '',
				'complemento' => isset( $ad['floor'] ) ? (string) $ad['floor'] : '',
				'bairro'      => isset( $ad['locality'] ) ? (string) $ad['locality'] : '',
				'cidade'      => isset( $ad['city'] ) ? (string) $ad['city'] : '',
				'uf'          => isset( $ad['province'] ) ? (string) $ad['province'] : '',
			),
		);
	}

	private static function brl( $v ) {
		return 'R$ ' . number_format( (float) $v, 2, ',', '.' );
	}

	public static function message( array $p, array $extra_linhas = array() ) {
		$L   = array();
		$L[] = 'CAFÉ BRASIL GIGANTE | NOVO PEDIDO';
		if ( $p['pedido']['teste'] ) {
			$L[] = '*** PEDIDO DE TESTE: não emitir NF nem despachar ***';
		}
		$L[] = '';
		$L[] = 'Pedido: #' . $p['pedido']['numero'];
		$L[] = 'Pago em: ' . ( $p['pedido']['pago_em'] ? $p['pedido']['pago_em'] : '-' );
		$L[] = 'Status: PAGAMENTO CONFIRMADO';
		if ( $p['pedido']['misto'] ) {
			$L[] = 'ATENÇÃO: PEDIDO MISTO (tem outros produtos além do Brasil Gigante)';
		}
		if ( $p['origem'] ) {
			$o = array();
			foreach ( $p['origem'] as $k => $v ) {
				$o[] = $k . '=' . $v;
			}
			$L[] = 'Origem: ' . implode( ' | ', $o );
		}
		$L[] = '';
		$L[] = 'PRODUTO';
		$L[] = 'SKU: ' . ( $p['produto']['sku'] ? $p['produto']['sku'] : '-' );
		$L[] = 'Quantidade: ' . $p['produto']['quantidade'];
		$L[] = 'Valor unitário: ' . self::brl( $p['produto']['unitario'] );
		$L[] = '';
		$L[] = 'RESUMO FINANCEIRO';
		$L[] = 'Produtos: ' . self::brl( $p['valores']['produtos'] );
		$L[] = 'Frete: ' . self::brl( $p['valores']['frete'] ) . ( $p['envio']['servico'] ? ' (' . $p['envio']['servico'] . ')' : '' );
		if ( $p['valores']['desconto'] > 0 ) {
			$L[] = 'Desconto: ' . self::brl( $p['valores']['desconto'] );
		}
		$L[] = 'Total pago: ' . self::brl( $p['valores']['total'] );
		$L[] = 'Pagamento: ' . ( $p['valores']['pagamento'] ? $p['valores']['pagamento'] : '-' ) . ( $p['valores']['parcelas'] > 1 ? ' em ' . $p['valores']['parcelas'] . 'x' : '' );
		$L[] = '';
		$L[] = 'CLIENTE';
		$L[] = 'Nome: ' . $p['cliente']['nome'];
		$L[] = 'CPF: ' . $p['cliente']['cpf'];
		$L[] = 'E-mail: ' . $p['cliente']['email'];
		$L[] = 'Telefone: ' . $p['cliente']['telefone'];
		$L[] = '';
		$L[] = 'ENTREGA';
		$e   = $p['entrega'];
		$L[] = trim( $e['logradouro'] . ', ' . $e['numero'] . ( $e['complemento'] ? ' - ' . $e['complemento'] : '' ) );
		$L[] = trim( $e['bairro'] . ' - ' . $e['cidade'] . '/' . $e['uf'] . ' - CEP ' . $e['cep'] );
		if ( $extra_linhas ) {
			$L[] = '';
			$L[] = 'ETAPAS AUTOMÁTICAS';
			foreach ( $extra_linhas as $x ) {
				$L[] = '- ' . $x;
			}
		}
		return implode( "\n", $L );
	}

	/* ------------------------------------------------------------------
	 * Envio do aviso
	 * ---------------------------------------------------------------- */

	/** Envia o aviso ao webhook (se houver). Sem webhook, o aviso fica só no painel e conta como entregue. */
	private static function notify( $assunto, $texto, $payload = null ) {
		$c   = self::cfg();
		$url = trim( (string) $c['webhook_url'] );
		if ( '' === $url ) {
			return true;
		}
		if ( 0 !== strpos( $url, 'https://' ) ) {
			return false;
		}
		$corpo = wp_json_encode( array( 'assunto' => $assunto, 'texto' => $texto, 'dados' => $payload ) );
		$h     = array( 'Content-Type' => 'application/json' );
		if ( defined( 'PN_BG_WEBHOOK_SECRET' ) && PN_BG_WEBHOOK_SECRET ) {
			$h['X-PN-Signature'] = hash_hmac( 'sha256', $corpo, (string) PN_BG_WEBHOOK_SECRET );
		}
		$r = wp_remote_post( $url, array( 'timeout' => 15, 'headers' => $h, 'body' => $corpo ) );
		return ( ! is_wp_error( $r ) && (int) wp_remote_retrieve_response_code( $r ) < 300 );
	}

	/**
	 * Processa um pedido: aviso + eGestor. $forcar ignora a trava de "já avisado" (reprocessar).
	 */
	public static function process_order( array $o, $forcar ) {
		$c   = self::cfg();
		$num = (string) $o['number'];
		$st  = self::state();
		if ( ! $forcar && in_array( $num, $st['done'], true ) ) {
			return;
		}
		$p      = self::payload( $o );
		$etapas = array();

		// eGestor (não roda em pedido de teste).
		if ( 'off' !== $c['egestor_modo'] ) {
			if ( $p['pedido']['teste'] ) {
				$etapas[] = 'eGestor: ignorado (pedido de teste).';
			} else {
				$etapas = array_merge( $etapas, self::egestor_step( $p, $c, $num ) );
			}
		}

		$enviado = true;
		if ( ! empty( $c['avisos'] ) ) {
			$enviado = self::notify( '[Brasil Gigante] Novo pedido pago #' . $num . ( $p['pedido']['teste'] ? ' (TESTE)' : '' ), self::message( $p, $etapas ), $p );
			self::log( $num, 'aviso', $enviado ? ( '' === trim( (string) $c['webhook_url'] ) ? 'registrado no painel' : 'enviado ao webhook' ) : 'FALHOU: o webhook não aceitou (tentará de novo)' );
		}

		if ( $enviado ) {
			$st = self::state();
			if ( ! in_array( $num, $st['done'], true ) ) {
				$st['done'][] = $num;
				$st['done']   = array_slice( $st['done'], -self::MAX_DONE );
			}
			update_option( self::OPT_STATE, $st, false );
		}
	}

	/* ------------------------------------------------------------------
	 * eGestor: contato -> venda -> NF-e
	 * ---------------------------------------------------------------- */

	private static function egestor_step( array $p, array $c, $num ) {
		$L    = array();
		$modo = $c['egestor_modo'];

		if ( $p['valores']['desconto'] > 0 || $p['pedido']['misto'] ) {
			$m = 'eGestor: NÃO automático (' . ( $p['pedido']['misto'] ? 'pedido misto' : 'pedido com desconto' ) . '). Revisar e lançar manualmente.';
			self::log( $num, 'egestor', $m );
			return array( $m );
		}
		$cpf = $p['cliente']['cpf'];
		if ( ! in_array( strlen( $cpf ), array( 11, 14 ), true ) ) {
			$m = 'eGestor: CPF/CNPJ ausente ou inválido no pedido. Lançar manualmente.';
			self::log( $num, 'egestor', $m );
			return array( $m );
		}

		$cbody = PN_EGestor::contact_body(
			array(
				'nome'       => $p['cliente']['nome'],
				'cpf'        => $cpf,
				'email'      => $p['cliente']['email'],
				'fone'       => $p['cliente']['telefone'],
				'cep'        => $p['entrega']['cep'],
				'logradouro' => $p['entrega']['logradouro'],
				'numero'     => $p['entrega']['numero'],
				'uf'         => $p['entrega']['uf'],
			)
		);
		$venda = array(
			'cod_contato'       => 0,
			'data'              => gmdate( 'Y-m-d', $p['pedido']['pago_em'] ? (int) strtotime( $p['pedido']['pago_em'] ) : time() ),
			'frete'             => $p['valores']['frete'],
			'observacao'        => 'Pedido Nuvemshop #' . $num,
			'cod_produto'       => (int) $c['egestor_cod_prod'],
			'quant'             => $p['produto']['quantidade'],
			'preco'             => $p['produto']['unitario'],
			'total'             => $p['valores']['total'],
			'cod_transportador' => (int) $c['egestor_cod_transp'],
		);

		if ( 'simulado' === $modo ) {
			$vb = PN_EGestor::sale_body( $venda );
			$m  = 'eGestor (simulado): acharia/criaria o contato com ' . count( $cbody ) . ' campos e criaria a venda com ' . count( $vb['produtos'] ) . ' item, frete ' . self::brl( $venda['frete'] ) . ', total ' . self::brl( $venda['total'] ) . ( 'venda_nfe' === $modo ? '; pediria a NF-e.' : '; NF-e não seria pedida neste modo.' );
			self::log( $num, 'egestor', $m );
			return array( $m );
		}

		$stp = self::state();
		$reg = isset( $stp['orders'][ $num ] ) && is_array( $stp['orders'][ $num ] ) ? $stp['orders'][ $num ] : array();

		// 1) Contato.
		$cod = isset( $reg['contato'] ) ? (int) $reg['contato'] : 0;
		if ( ! $cod ) {
			$f = PN_EGestor::find_contact( $cpf );
			if ( is_int( $f ) && $f > 0 ) {
				$cod = $f;
			} elseif ( 0 === $f ) {
				$r = PN_EGestor::request( 'POST', 'v1/contatos', array(), $cbody );
				if ( is_wp_error( $r ) || ! in_array( $r['code'], array( 200, 201 ), true ) ) {
					$m = 'eGestor: falha ao criar o contato (' . PN_EGestor::err( $r ) . ').';
					self::log( $num, 'egestor', $m );
					return array( $m );
				}
				$cod = isset( $r['body']['codigo'] ) ? (int) $r['body']['codigo'] : 0;
			} else {
				$m = 'eGestor: falha ao procurar o contato (' . PN_EGestor::err( $f ) . ').';
				self::log( $num, 'egestor', $m );
				return array( $m );
			}
			if ( ! $cod ) {
				$m = 'eGestor: contato criado, mas o código não veio na resposta. Conferir no eGestor.';
				self::log( $num, 'egestor', $m );
				return array( $m );
			}
			$reg['contato'] = $cod;
		}
		$L[] = 'eGestor: contato OK (código ' . $cod . ').';

		// 2) Venda.
		if ( empty( $reg['venda'] ) ) {
			$venda['cod_contato'] = $cod;
			$r                    = PN_EGestor::request( 'POST', 'v1/vendas', array(), PN_EGestor::sale_body( $venda ) );
			if ( is_wp_error( $r ) || ! in_array( $r['code'], array( 200, 201 ), true ) || empty( $r['body']['codigo'] ) ) {
				$m = 'eGestor: falha ao criar a venda (' . PN_EGestor::err( $r ) . ').';
				self::log( $num, 'egestor', $m );
				self::save_reg( $num, $reg );
				return array_merge( $L, array( $m ) );
			}
			$reg['venda'] = (int) $r['body']['codigo'];
		}
		$L[] = 'eGestor: venda criada (código ' . $reg['venda'] . ').';

		// 3) NF-e.
		if ( 'venda_nfe' === $modo && empty( $reg['nfe_pedida'] ) ) {
			$r = PN_EGestor::request( 'POST', 'v1/vendas/' . (int) $reg['venda'] . '/gerarNfe', array(), array( 'enviar' => true ) );
			if ( is_wp_error( $r ) || ! in_array( $r['code'], array( 200, 201 ), true ) ) {
				$m = 'eGestor: NF-e NÃO emitida (' . PN_EGestor::err( $r ) . '). Emitir pelo eGestor.';
				self::log( $num, 'egestor', $m );
				self::save_reg( $num, $reg );
				return array_merge( $L, array( $m ) );
			}
			$reg['nfe_pedida'] = 1;
			$L[]               = 'eGestor: emissão da NF-e solicitada. O resultado (autorizada ou rejeitada) chega em outro aviso.';
		}

		self::save_reg( $num, $reg );
		self::log( $num, 'egestor', implode( ' ', $L ) );
		return $L;
	}

	private static function save_reg( $num, array $reg ) {
		$st                    = self::state();
		$st['orders'][ $num ]  = $reg;
		if ( count( $st['orders'] ) > self::MAX_DONE ) {
			$st['orders'] = array_slice( $st['orders'], -self::MAX_DONE, null, true );
		}
		update_option( self::OPT_STATE, $st, false );
	}

	/* ------------------------------------------------------------------
	 * Webhook do eGestor (NF-e autorizada / rejeitada / cancelada)
	 * ---------------------------------------------------------------- */

	public static function register_rest() {
		register_rest_route(
			'pn/v1',
			'/egestor',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'rest_egestor' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	public static function rest_egestor( WP_REST_Request $req ) {
		if ( ! defined( 'PN_BG_EGESTOR_WH_TOKEN' ) || ! PN_BG_EGESTOR_WH_TOKEN ) {
			return new WP_REST_Response( array( 'ok' => false ), 503 );
		}
		$b = $req->get_json_params();
		if ( ! is_array( $b ) || empty( $b['securityToken'] ) || ! hash_equals( (string) PN_BG_EGESTOR_WH_TOKEN, (string) $b['securityToken'] ) ) {
			return new WP_REST_Response( array( 'ok' => false ), 401 );
		}
		if ( isset( $b['module'] ) && 'nfe' === $b['module'] && ! empty( $b['codigo'] ) && ! empty( $b['action'] ) ) {
			$acao = sanitize_key( $b['action'] );
			if ( in_array( $acao, array( 'autorizada', 'rejeitada', 'cancelada' ), true ) ) {
				$txt = 'NF-e ' . $acao . ' no eGestor (código ' . (int) $b['codigo'] . ').';
				if ( 'rejeitada' === $acao ) {
					$txt .= "\nCódigo SEFAZ: " . ( isset( $b['cStat'] ) ? (int) $b['cStat'] : '-' ) . "\nMotivo: " . ( isset( $b['motivo'] ) ? mb_substr( sanitize_text_field( $b['motivo'] ), 0, 300 ) : '-' );
				} elseif ( 'autorizada' === $acao ) {
					$r = PN_EGestor::request( 'GET', 'v1/nfe/' . (int) $b['codigo'] );
					if ( ! is_wp_error( $r ) && 200 === $r['code'] ) {
						foreach ( array( 'numero' => 'Número', 'chave' => 'Chave' ) as $k => $lab ) {
							if ( ! empty( $r['body'][ $k ] ) && is_scalar( $r['body'][ $k ] ) ) {
								$txt .= "\n" . $lab . ': ' . sanitize_text_field( (string) $r['body'][ $k ] );
							}
						}
					}
				}
				self::log( '-', 'nfe_' . $acao, $txt );
				self::notify( '[Brasil Gigante] ' . $txt, $txt );
			}
		}
		return new WP_REST_Response( array( 'ok' => true ), 200 );
	}

	/* ------------------------------------------------------------------
	 * Painel (tudo protegido pela senha do painel)
	 * ---------------------------------------------------------------- */

	private static function guard() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Sem permissão.' );
		}
		PN_Gate::require_unlocked();
	}

	private static function back( $msg = '', $tipo = 'success' ) {
		if ( '' !== $msg ) {
			set_transient( 'pn_flash_' . get_current_user_id(), array( 'tipo' => $tipo, 'linhas' => array( $msg ) ), 5 * MINUTE_IN_SECONDS );
		}
		wp_safe_redirect( admin_url( 'admin.php?page=pedidos-nuvemshop' ) );
		exit;
	}

	public static function handle_conn() {
		self::guard();
		check_admin_referer( 'pn_bg_conn' );
		$ja = PN_Pedidos_Nuvemshop::instance()->bg_connection_status();
		if ( ! empty( $ja['ok'] ) ) {
			// Conexão travada: só troca com a senha do painel digitada de novo.
			$senha = isset( $_POST['senha'] ) ? (string) wp_unslash( $_POST['senha'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			if ( ! PN_Gate::verify( $senha ) ) {
				self::back( 'Senha incorreta. A conexão não foi alterada.', 'error' );
			}
		}
		$sid = isset( $_POST['store_id'] ) ? (string) wp_unslash( $_POST['store_id'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$tok = isset( $_POST['token'] ) ? (string) wp_unslash( $_POST['token'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$r   = PN_Pedidos_Nuvemshop::instance()->bg_save_connection( $sid, $tok );
		self::back( $r['msg'], $r['ok'] ? 'success' : 'error' );
	}

	public static function handle_sync() {
		self::guard();
		check_admin_referer( 'pn_bg_sync' );
		$r = PN_Pedidos_Nuvemshop::instance()->bg_sync_now();
		self::back( implode( ' ', $r['linhas'] ), $r['ok'] ? 'success' : 'error' );
	}

	public static function handle_save() {
		self::guard();
		check_admin_referer( 'pn_bg_save' );
		$in    = isset( $_POST['pn_bg'] ) ? wp_unslash( (array) $_POST['pn_bg'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$modos = self::modos();
		$new   = array(
			'avisos'             => empty( $in['avisos'] ) ? 0 : 1,
			'webhook_url'        => esc_url_raw( isset( $in['webhook_url'] ) ? trim( $in['webhook_url'] ) : '', array( 'https' ) ),
			'email_teste'        => sanitize_email( isset( $in['email_teste'] ) ? $in['email_teste'] : '' ),
			// Identificação do produto: travada (não vem do formulário).
			'product_id'         => self::cfg()['product_id'],
			'variant_id'         => self::cfg()['variant_id'],
			'sku'                => self::cfg()['sku'],
			'egestor_modo'       => ( isset( $in['egestor_modo'] ) && isset( $modos[ $in['egestor_modo'] ] ) ) ? $in['egestor_modo'] : 'off',
			'egestor_cod_prod'   => preg_replace( '/\D/', '', isset( $in['egestor_cod_prod'] ) ? $in['egestor_cod_prod'] : '' ),
			'egestor_cod_transp' => preg_replace( '/\D/', '', isset( $in['egestor_cod_transp'] ) ? $in['egestor_cod_transp'] : '' ),
		);
		update_option( self::OPT_CFG, $new, false );

		$hh = isset( $_POST['pn_sync_horas'] ) ? sanitize_text_field( wp_unslash( $_POST['pn_sync_horas'] ) ) : '2'; // phpcs:ignore WordPress.Security.NonceVerification
		update_option( 'pn_sync_horas', in_array( $hh, array( '0.5', '1', '2' ), true ) ? $hh : '2', false );
		PN_Pedidos_Nuvemshop::ensure_cron();
		self::back( 'Configurações salvas.' );
	}

	public static function handle_test() {
		self::guard();
		check_admin_referer( 'pn_bg_test' );
		$c = self::cfg();
		$o = array(
			'number'   => 'TESTE',
			'id'       => '0',
			'paid_at'  => gmdate( 'c' ),
			'products' => array( array( 'product_id' => $c['product_id'], 'variant_id' => $c['variant_id'], 'sku' => $c['sku'], 'quantity' => 3, 'price' => '35.90' ) ),
			'subtotal' => '107.70',
			'shipping_cost_customer' => '0.00',
			'discount' => '0.00',
			'total'    => '107.70',
			'shipping_option' => 'Jadlog Econômico',
			'customer' => array( 'name' => 'Cliente de Teste', 'email' => 'teste@exemplo.com', 'phone' => '+5511999999999', 'identification' => '00000000000' ),
			'shipping_address' => array( 'zipcode' => '30575360', 'address' => 'Rua de Teste', 'number' => '1', 'locality' => 'Centro', 'city' => 'Belo Horizonte', 'province' => 'MG' ),
		);
		$p                    = self::payload( $o );
		$p['pedido']['teste'] = true;
		$tem_webhook          = '' !== trim( (string) $c['webhook_url'] );
		$ok                   = self::notify( '[Brasil Gigante] TESTE de aviso', self::message( $p, array( 'Teste manual: nenhuma venda foi criada no eGestor.' ) ), $p );
		self::log( 'TESTE', 'aviso_teste', $tem_webhook ? ( $ok ? 'enviado ao webhook' : 'FALHOU: o webhook não aceitou' ) : 'sem webhook: o aviso fica só no painel' );
		if ( ! $tem_webhook ) {
			self::back( 'Sem webhook configurado: os avisos aparecem apenas no painel.', 'success' );
		}
		self::back( $ok ? 'Aviso de teste enviado ao webhook.' : 'O webhook não aceitou o teste. Confira o endereço.', $ok ? 'success' : 'error' );
	}

	public static function handle_resend() {
		self::guard();
		check_admin_referer( 'pn_bg_resend' );
		$num = isset( $_POST['pn_num'] ) ? preg_replace( '/\D/', '', wp_unslash( $_POST['pn_num'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		if ( '' === $num ) {
			self::back( 'Informe o número do pedido.', 'error' );
		}
		$r = PN_Pedidos_Nuvemshop::instance()->bg_api_get( 'orders', array( 'q' => $num, 'per_page' => 10 ) );
		if ( is_wp_error( $r ) || 200 !== $r['code'] || ! is_array( $r['body'] ) ) {
			self::back( 'Não consegui consultar a Nuvemshop.', 'error' );
		}
		foreach ( $r['body'] as $o ) {
			if ( is_array( $o ) && isset( $o['number'] ) && (string) $o['number'] === $num ) {
				if ( ! self::order_has_bg( $o ) ) {
					self::back( 'Esse pedido não tem o produto Brasil Gigante.', 'error' );
				}
				if ( empty( $o['payment_status'] ) || 'paid' !== $o['payment_status'] ) {
					self::back( 'Esse pedido ainda não está pago.', 'error' );
				}
				self::process_order( $o, true );
				self::back( 'Pedido #' . $num . ' reprocessado. Veja o registro no fim da página.' );
			}
		}
		self::back( 'Pedido não encontrado.', 'error' );
	}

	private static function pt( $tipo, $v ) {
		$m = array(
			'pagamento' => array( 'paid' => 'Pago', 'pending' => 'Pendente', 'authorized' => 'Autorizado', 'voided' => 'Anulado', 'refunded' => 'Reembolsado', 'abandoned' => 'Abandonado' ),
			'envio'     => array( 'unpacked' => 'Não embalado', 'in_preparation' => 'Em preparação', 'packed' => 'Embalado', 'unshipped' => 'A enviar', 'shipped' => 'Enviado', 'delivered' => 'Entregue', 'ready_for_pickup' => 'Retirada', 'closed' => 'Encerrado', 'cancelled' => 'Cancelado' ),
		);
		return isset( $m[ $tipo ][ $v ] ) ? $m[ $tipo ][ $v ] : ( '' === $v ? '—' : $v );
	}

	private static function postform( $action, $nonce, $label, $class = 'button' ) {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline-block;margin:0 8px 0 0">';
		echo '<input type="hidden" name="action" value="' . esc_attr( $action ) . '">';
		wp_nonce_field( $nonce );
		echo '<button type="submit" class="' . esc_attr( $class ) . '">' . esc_html( $label ) . '</button></form>';
	}

	private static function update_line( $up ) {
		$f = function ( $t ) {
			return get_date_from_gmt( $t, 'd/m/Y H:i' );
		};
		$l = array();
		$c = $up['check'];
		if ( ! empty( $c['em'] ) ) {
			$l[] = 'Última consulta ao GitHub: ' . $f( $c['em'] ) . ( ! empty( $c['ok'] ) ? ' (ok)' : ' (falhou: ' . esc_html( $c['erro'] ) . ')' );
		} else {
			$l[] = 'Ainda não consultou o GitHub. Clique em "Verificar atualização".';
		}
		if ( ! empty( $up['ver_at'] ) ) {
			$l[] = 'Versão ' . esc_html( PN_Pedidos_Nuvemshop::VERSION ) . ' em uso desde ' . $f( $up['ver_at'] ) . ( $up['ver_prev'] ? ' (veio da ' . esc_html( $up['ver_prev'] ) . ')' : '' );
		}
		return '<p class="description">' . implode( '<br>', $l ) . '</p>';
	}

	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Sem permissão.' );
		}
		echo '<div class="wrap"><h1>Brasil Gigante</h1>';

		$fk    = 'pn_flash_' . get_current_user_id();
		$flash = get_transient( $fk );
		if ( $flash ) {
			delete_transient( $fk );
			echo '<div class="notice notice-' . esc_attr( $flash['tipo'] ) . ' is-dismissible">';
			foreach ( $flash['linhas'] as $l ) {
				echo '<p>' . esc_html( $l ) . '</p>';
			}
			echo '</div>';
		}

		if ( ! PN_Gate::is_unlocked() ) {
			PN_Gate::render_lock();
			echo '</div>';
			return;
		}
		self::render_panel();
		echo '</div>';
	}

	private static function card( $titulo, $valor, $sub = '' ) {
		echo '<div style="flex:1 1 160px;background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:14px 16px">';
		echo '<div style="color:#646970;font-size:12px">' . esc_html( $titulo ) . '</div>';
		echo '<div style="font-size:22px;font-weight:600;margin-top:4px">' . esc_html( $valor ) . '</div>';
		if ( '' !== $sub ) {
			echo '<div style="color:#646970;font-size:12px;margin-top:2px">' . esc_html( $sub ) . '</div>';
		}
		echo '</div>';
	}

	private static function section( $titulo ) {
		echo '<h2 style="margin:28px 0 10px">' . esc_html( $titulo ) . '</h2>';
	}

	private static function render_panel() {
		$pn   = PN_Pedidos_Nuvemshop::instance();
		$c    = self::cfg();
		$st   = self::state();
		$conn = $pn->bg_connection_status();
		$sum  = $pn->bg_summary();
		$ult  = $pn->bg_last_sync();
		$up   = PN_Updater::status();

		// Barra de topo.
		echo '<div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin:8px 0 4px">';
		echo '<span style="display:inline-block;padding:3px 10px;border-radius:12px;color:#fff;background:' . ( $conn['ok'] ? '#1a7f37' : '#b32d2e' ) . '">' . esc_html( $conn['texto'] ) . '</span>';
		echo '<span style="color:#646970">Última sincronização: ' . esc_html( $ult ? get_date_from_gmt( gmdate( 'Y-m-d H:i:s', strtotime( $ult ) ), 'd/m/Y H:i' ) : 'nunca' ) . '</span>';
		self::postform( 'pn_bg_sync', 'pn_bg_sync', 'Sincronizar agora' );
		self::postform( 'pn_gate_lock', 'pn_gate_lock', 'Bloquear painel' );
		echo '</div>';

		if ( 0 === strpos( $conn['texto'], 'Falta' ) ) {
			echo '<div class="notice notice-warning inline"><p><strong>Por isso a sincronização não roda:</strong> ' . esc_html( $conn['texto'] ) . '. Role até "Conexão com a Nuvemshop", cole o token e clique em "Salvar e testar conexão".</p></div>';
		}

		// 1. Resumo.
		self::section( 'Resumo de vendas (pedidos pagos)' );
		echo '<div style="display:flex;gap:12px;flex-wrap:wrap">';
		self::card( 'Hoje', (int) $sum['hoje']['n'] . ' pedidos', 'R$ ' . number_format_i18n( (float) $sum['hoje']['receita'], 2 ) );
		self::card( 'Últimos 7 dias', (int) $sum['7d']['n'] . ' pedidos', 'R$ ' . number_format_i18n( (float) $sum['7d']['receita'], 2 ) );
		self::card( 'Últimos 30 dias', (int) $sum['30d']['n'] . ' pedidos', 'R$ ' . number_format_i18n( (float) $sum['30d']['receita'], 2 ) );
		self::card( 'Pacotes vendidos (30 dias)', (string) (int) $sum['30d']['pacotes'], 'Frete médio R$ ' . number_format_i18n( (float) $sum['30d']['frete'], 2 ) );
		self::card( 'Último pedido pago', $sum['ultimo'] ? get_date_from_gmt( $sum['ultimo'], 'd/m H:i' ) : '—' );
		echo '</div>';

		// 2. Envios.
		self::section( 'Envios' );
		$e = $sum['envios'];
		$n = function ( $k ) use ( $e ) {
			return isset( $e[ $k ] ) ? (int) $e[ $k ]->n : 0;
		};
		echo '<div style="display:flex;gap:12px;flex-wrap:wrap">';
		self::card( 'A enviar', (string) ( $n( 'unshipped' ) + $n( 'unpacked' ) ) );
		self::card( 'Enviados', (string) $n( 'shipped' ) );
		self::card( 'Entregues', (string) $n( 'delivered' ) );
		echo '</div>';

		// 3. Pedidos.
		self::section( 'Pedidos do Brasil Gigante' );
		$pag = isset( $_GET['pg'] ) ? max( 1, (int) $_GET['pg'] ) : 1; // phpcs:ignore WordPress.Security.NonceVerification
		$res = $pn->bg_orders( 20, $pag );
		if ( ! $res['rows'] ) {
			echo '<p>Nenhum pedido do Brasil Gigante sincronizado ainda. Clique em "Sincronizar agora".</p>';
		} else {
			echo '<table class="widefat striped"><thead><tr><th>Nº</th><th>Data</th><th>Pagamento</th><th>Pacotes</th><th>Total</th><th>Frete</th><th>Serviço</th><th>Envio</th><th>Rastreio</th><th>Destino</th><th>Cliente</th><th>eGestor</th></tr></thead><tbody>';
			foreach ( $res['rows'] as $r ) {
				$reg = isset( $st['orders'][ (string) $r->numero ] ) ? $st['orders'][ (string) $r->numero ] : array();
				$egs = ! empty( $reg['venda'] ) ? 'Venda ' . (int) $reg['venda'] . ( ! empty( $reg['nfe_pedida'] ) ? ' · NF pedida' : '' ) : '—';
				echo '<tr><td>#' . (int) $r->numero . '</td><td>' . esc_html( get_date_from_gmt( $r->criado_em, 'd/m/Y H:i' ) ) . '</td><td>' . esc_html( self::pt( 'pagamento', $r->status_pagamento ) ) . '</td><td>' . (int) $r->pacotes_250 . '</td><td>R$ ' . esc_html( number_format_i18n( (float) $r->total, 2 ) ) . '</td><td>R$ ' . esc_html( number_format_i18n( (float) $r->frete_cliente, 2 ) ) . '</td><td>' . esc_html( $r->servico_envio ? $r->servico_envio : '—' ) . '</td><td>' . esc_html( self::pt( 'envio', $r->status_envio ) ) . '</td><td>' . esc_html( $r->rastreio ? $r->rastreio : '—' ) . '</td><td>' . esc_html( trim( $r->cidade . ' ' . $r->uf ) ) . '</td><td>' . esc_html( $r->cliente_nome ) . '</td><td>' . esc_html( $egs ) . '</td></tr>';
			}
			echo '</tbody></table>';
			$paginas = (int) ceil( $res['total'] / 20 );
			if ( $paginas > 1 ) {
				echo '<p>';
				for ( $i = 1; $i <= $paginas; $i++ ) {
					echo $i === $pag ? '<strong>' . (int) $i . '</strong> ' : '<a href="' . esc_url( add_query_arg( array( 'page' => 'pedidos-nuvemshop', 'pg' => $i ), admin_url( 'admin.php' ) ) ) . '">' . (int) $i . '</a> ';
				}
				echo '</p>';
			}
		}

		// 4. Avisos e eGestor.
		self::section( 'Avisos e eGestor' );
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="pn_bg_save">';
		wp_nonce_field( 'pn_bg_save' );
		echo '<table class="form-table" role="presentation">';
		echo '<tr><th>Avisar pedido pago</th><td><label><input type="checkbox" name="pn_bg[avisos]" value="1"' . checked( $c['avisos'], 1, false ) . '> Registrar no painel (e no webhook, se houver) quando um pedido do Brasil Gigante for pago</label></td></tr>';
		echo '<tr><th>Webhook do aviso (opcional)</th><td><input type="url" class="regular-text" name="pn_bg[webhook_url]" value="' . esc_attr( $c['webhook_url'] ) . '" placeholder="https://"><p class="description">Recebe o aviso em JSON (só https). Sem webhook, o aviso fica só no painel.</p></td></tr>';
		$h = PN_Pedidos_Nuvemshop::sync_horas();
		echo '<tr><th>Sincronização automática</th><td><select name="pn_sync_horas">';
		foreach ( array( '0.5' => 'a cada 30 minutos', '1' => 'a cada 1 hora', '2' => 'a cada 2 horas (recomendado)' ) as $k => $lab ) {
			echo '<option value="' . esc_attr( $k ) . '"' . selected( $h, (string) $k, false ) . '>' . esc_html( $lab ) . '</option>';
		}
		$prox = wp_next_scheduled( PN_Pedidos_Nuvemshop::CRON_HOOK );
		echo '</select><p class="description">Traz pedidos novos e atualiza o status de envio. O botão "Sincronizar agora" continua disponível.' . ( $prox ? ' Próxima automática: ' . esc_html( get_date_from_gmt( gmdate( 'Y-m-d H:i:s', $prox ), 'd/m H:i' ) ) . '.' : '' ) . '</p></td></tr>';
		echo '<tr><th>Modo do eGestor</th><td><select name="pn_bg[egestor_modo]">';
		foreach ( self::modos() as $k => $lab ) {
			echo '<option value="' . esc_attr( $k ) . '"' . selected( $c['egestor_modo'], $k, false ) . '>' . esc_html( $lab ) . '</option>';
		}
		echo '</select><p class="description">Credencial do eGestor: ' . ( PN_EGestor::has_credentials() ? 'definida' : 'não definida' ) . '. Comece em "Simulado".</p></td></tr>';
		echo '<tr><th>Atualizações</th><td><span style="color:#1a7f37">🔒 Automáticas, repositório oficial (travado)</span><p class="description">Versão instalada: ' . esc_html( PN_Pedidos_Nuvemshop::VERSION ) . ( $up['latest'] ? ' · última publicada: ' . esc_html( $up['latest'] ) : '' ) . '</p>' . self::update_line( $up ) . '</td></tr>';
		echo '</table>';
		echo '<details style="margin:0 0 12px"><summary style="cursor:pointer">Avançado (eGestor e e-mail de teste)</summary><table class="form-table" role="presentation">';
		echo '<tr><th>Produto Brasil Gigante</th><td>🔒 ID do produto <code>' . esc_html( $c['product_id'] ) . '</code> · variação <code>' . esc_html( $c['variant_id'] ) . '</code> · SKU <code>' . esc_html( $c['sku'] ) . '</code><p class="description">Travado: só o Brasil Gigante entra no painel.</p></td></tr>';
		echo '<tr><th>Código do produto no eGestor</th><td><input type="text" size="8" name="pn_bg[egestor_cod_prod]" value="' . esc_attr( $c['egestor_cod_prod'] ) . '"> transportadora (opcional) <input type="text" size="8" name="pn_bg[egestor_cod_transp]" value="' . esc_attr( $c['egestor_cod_transp'] ) . '"></td></tr>';
		echo '<tr><th>E-mail do comprador de teste</th><td><input type="email" class="regular-text" name="pn_bg[email_teste]" value="' . esc_attr( $c['email_teste'] ) . '"><p class="description">Pedido com este e-mail é marcado como teste e não aciona o eGestor.</p></td></tr>';
		echo '</table></details>';
		submit_button( 'Salvar', 'primary', 'submit', false );
		echo '</form>';
		echo '<p style="margin-top:12px">';
		self::postform( 'pn_bg_test', 'pn_bg_test', 'Enviar aviso de teste' );
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline-block"><input type="hidden" name="action" value="pn_bg_resend">';
		wp_nonce_field( 'pn_bg_resend' );
		echo '<input type="text" name="pn_num" size="8" placeholder="Nº do pedido"> <button type="submit" class="button">Reprocessar pedido pago</button></form>';
		echo '</p>';
		echo '<p>';
		self::postform( 'pn_check_update', 'pn_check_update', 'Verificar atualização' );
		echo '</p>';

		// 5. Conexão.
		self::section( 'Conexão com a Nuvemshop' );
		$s_id = ( defined( 'NUVEMSHOP_STORE_ID' ) && NUVEMSHOP_STORE_ID ) ? (string) NUVEMSHOP_STORE_ID : (string) ( ( get_option( 'pn_settings', array() )['store_id'] ?? '8063546' ) );
		$hint      = $pn->bg_connection_hint();
		$conectado = ! empty( $conn['ok'] );
		if ( $conectado ) {
			echo '<p>🔒 Conexão travada: loja <code>' . esc_html( $hint['store_id'] ) . '</code> · token terminado em <code>' . esc_html( $hint['token_fim'] ) . '</code>.</p>';
			echo '<details style="margin:0 0 12px"><summary style="cursor:pointer">Trocar a conexão (pede a senha do painel)</summary>';
		}
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="pn_bg_conn">';
		wp_nonce_field( 'pn_bg_conn' );
		echo '<table class="form-table" role="presentation"><tr><th>ID da loja</th><td><input type="text" class="regular-text" name="store_id" value="' . esc_attr( $hint['store_id'] ? $hint['store_id'] : $s_id ) . '"></td></tr>';
		echo '<tr><th>Token</th><td><input type="password" class="regular-text" name="token" value="" autocomplete="new-password" placeholder="Deixe em branco para manter o token salvo"><p class="description">O token só precisa de "Pedidos › Ler pedidos". Ele nunca é exibido de volta.</p></td></tr>';
		if ( $conectado ) {
			echo '<tr><th>Senha do painel</th><td><input type="password" class="regular-text" name="senha" autocomplete="current-password" required></td></tr>';
		}
		echo '</table>';
		submit_button( 'Salvar e testar conexão', 'secondary', 'submit', false );
		echo '</form>';
		if ( $conectado ) {
			echo '</details>';
		}

		self::section( 'Senha do painel' );
		PN_Gate::render_change();

		// 6. Registro.
		self::section( 'Registro (sem dados pessoais)' );
		$log = get_option( self::OPT_LOG, array() );
		if ( empty( $log ) || ! is_array( $log ) ) {
			echo '<p>Nada registrado ainda.</p>';
		} else {
			echo '<table class="widefat striped"><thead><tr><th style="width:150px">Quando</th><th style="width:90px">Pedido</th><th style="width:110px">Evento</th><th>Resultado</th></tr></thead><tbody>';
			foreach ( $log as $l ) {
				echo '<tr><td>' . esc_html( $l['quando'] ) . '</td><td>' . esc_html( $l['pedido'] ) . '</td><td>' . esc_html( $l['evento'] ) . '</td><td>' . esc_html( $l['resultado'] ) . '</td></tr>';
			}
			echo '</tbody></table>';
		}
		echo '<p class="description" style="margin-top:20px">Privacidade: este painel mostra e guarda apenas pedidos com o Brasil Gigante. Nome do cliente mascarado; CPF, e-mail, telefone e endereço completo não ficam gravados.</p>';
	}
}
