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
		}
	}

	/* ------------------------------------------------------------------
	 * Configuração
	 * ---------------------------------------------------------------- */

	public static function cfg() {
		$d = array(
			'somente_bg'        => 1,
			'avisos'            => 1,
			'email_aviso'       => get_option( 'admin_email', '' ),
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
		$c = self::cfg();
		return ! empty( $c['somente_bg'] );
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

	/** Retorna true se algum canal aceitou. */
	private static function notify( $assunto, $texto, $payload = null ) {
		$c  = self::cfg();
		$ok = false;

		if ( is_email( $c['email_aviso'] ) ) {
			if ( wp_mail( $c['email_aviso'], $assunto, $texto ) ) {
				$ok = true;
			}
		}
		$url = trim( (string) $c['webhook_url'] );
		if ( '' !== $url && 0 === strpos( $url, 'https://' ) ) {
			$corpo = wp_json_encode( array( 'assunto' => $assunto, 'texto' => $texto, 'dados' => $payload ) );
			$h     = array( 'Content-Type' => 'application/json' );
			if ( defined( 'PN_BG_WEBHOOK_SECRET' ) && PN_BG_WEBHOOK_SECRET ) {
				$h['X-PN-Signature'] = hash_hmac( 'sha256', $corpo, (string) PN_BG_WEBHOOK_SECRET );
			}
			$r = wp_remote_post( $url, array( 'timeout' => 15, 'headers' => $h, 'body' => $corpo ) );
			if ( ! is_wp_error( $r ) && (int) wp_remote_retrieve_response_code( $r ) < 300 ) {
				$ok = true;
			}
		}
		return $ok;
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
			self::log( $num, 'aviso', $enviado ? 'enviado' : 'FALHOU: nenhum canal aceitou (e-mail ou webhook)' );
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
	 * Telas e ações do painel
	 * ---------------------------------------------------------------- */

	private static function back( $msg = '', $tipo = 'success' ) {
		if ( '' !== $msg ) {
			set_transient( 'pn_flash_' . get_current_user_id(), array( 'tipo' => $tipo, 'linhas' => array( $msg ) ), 5 * MINUTE_IN_SECONDS );
		}
		wp_safe_redirect( admin_url( 'admin.php?page=pedidos-nuvemshop&tab=brasilgigante' ) );
		exit;
	}

	public static function handle_save() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Sem permissão.' );
		}
		check_admin_referer( 'pn_bg_save' );
		$in    = isset( $_POST['pn_bg'] ) ? wp_unslash( (array) $_POST['pn_bg'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$modos = self::modos();
		$new   = array(
			'somente_bg'         => empty( $in['somente_bg'] ) ? 0 : 1,
			'avisos'             => empty( $in['avisos'] ) ? 0 : 1,
			'email_aviso'        => sanitize_email( isset( $in['email_aviso'] ) ? $in['email_aviso'] : '' ),
			'webhook_url'        => esc_url_raw( isset( $in['webhook_url'] ) ? trim( $in['webhook_url'] ) : '', array( 'https' ) ),
			'email_teste'        => sanitize_email( isset( $in['email_teste'] ) ? $in['email_teste'] : '' ),
			'product_id'         => preg_replace( '/\D/', '', isset( $in['product_id'] ) ? $in['product_id'] : '' ),
			'variant_id'         => preg_replace( '/\D/', '', isset( $in['variant_id'] ) ? $in['variant_id'] : '' ),
			'sku'                => preg_replace( '/[^A-Za-z0-9_\-]/', '', isset( $in['sku'] ) ? $in['sku'] : '' ),
			'egestor_modo'       => ( isset( $in['egestor_modo'] ) && isset( $modos[ $in['egestor_modo'] ] ) ) ? $in['egestor_modo'] : 'off',
			'egestor_cod_prod'   => preg_replace( '/\D/', '', isset( $in['egestor_cod_prod'] ) ? $in['egestor_cod_prod'] : '' ),
			'egestor_cod_transp' => preg_replace( '/\D/', '', isset( $in['egestor_cod_transp'] ) ? $in['egestor_cod_transp'] : '' ),
		);
		update_option( self::OPT_CFG, $new, false );

		$repo = isset( $_POST['pn_update_repo'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['pn_update_repo'] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		update_option( PN_Updater::OPT_REPO, preg_match( '#^[A-Za-z0-9_.\-]+/[A-Za-z0-9_.\-]+$#', $repo ) ? $repo : '', false );
		delete_site_transient( PN_Updater::CACHE_KEY );

		self::back( 'Configuração do Brasil Gigante salva.' );
	}

	public static function handle_test() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Sem permissão.' );
		}
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
			'customer' => array( 'name' => 'Cliente de Teste', 'email' => $c['email_teste'] ? $c['email_teste'] : 'teste@exemplo.com', 'phone' => '+5511999999999', 'identification' => '00000000000' ),
			'shipping_address' => array( 'zipcode' => '30575360', 'address' => 'Rua de Teste', 'number' => '1', 'locality' => 'Centro', 'city' => 'Belo Horizonte', 'province' => 'MG' ),
		);
		$p  = self::payload( $o );
		$p['pedido']['teste'] = true;
		$ok = self::notify( '[Brasil Gigante] TESTE de aviso', self::message( $p, array( 'Teste manual: nenhuma venda foi criada no eGestor.' ) ), $p );
		self::log( 'TESTE', 'aviso_teste', $ok ? 'enviado' : 'FALHOU: nenhum canal aceitou' );
		self::back( $ok ? 'Aviso de teste enviado.' : 'O aviso de teste não foi aceito por nenhum canal. Confira o e-mail e o endereço do webhook.', $ok ? 'success' : 'error' );
	}

	public static function handle_resend() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Sem permissão.' );
		}
		check_admin_referer( 'pn_bg_resend' );
		$num = isset( $_POST['pn_num'] ) ? preg_replace( '/\D/', '', wp_unslash( $_POST['pn_num'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		if ( '' === $num ) {
			self::back( 'Informe o número do pedido.', 'error' );
		}
		$r = PN_Pedidos_Nuvemshop::instance()->bg_api_get( 'orders', array( 'q' => $num, 'per_page' => 10 ) );
		if ( is_wp_error( $r ) || 200 !== $r['code'] || ! is_array( $r['body'] ) ) {
			self::back( 'Não consegui consultar a Nuvemshop.', 'error' );
		}
		$c = self::cfg();
		foreach ( $r['body'] as $o ) {
			if ( is_array( $o ) && isset( $o['number'] ) && (string) $o['number'] === $num ) {
				$tem = false;
				foreach ( (array) $o['products'] as $it ) {
					if ( is_array( $it ) && self::is_bg_item( $it, $c ) ) {
						$tem = true;
					}
				}
				if ( ! $tem ) {
					self::back( 'Esse pedido não tem o produto Brasil Gigante.', 'error' );
				}
				if ( empty( $o['payment_status'] ) || 'paid' !== $o['payment_status'] ) {
					self::back( 'Esse pedido ainda não está pago.', 'error' );
				}
				self::process_order( $o, true );
				self::back( 'Pedido #' . $num . ' reprocessado. Veja o registro abaixo.' );
			}
		}
		self::back( 'Pedido não encontrado.', 'error' );
	}

	public static function render( $plugin_version ) {
		$c   = self::cfg();
		$st  = self::state();
		$up  = PN_Updater::status();
		$log = get_option( self::OPT_LOG, array() );
		$eg  = PN_EGestor::has_credentials();

		if ( isset( $_GET['pn_checked'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			echo '<div class="notice notice-info inline"><p>Atualizações verificadas agora.</p></div>';
		}

		echo '<h2>Estado</h2><table class="widefat striped" style="max-width:760px"><tbody>';
		$linhas = array(
			'Versão instalada'             => $plugin_version,
			'Aviso de pedido pago'         => $c['avisos'] ? 'Ligado' : 'Desligado',
			'Modo do eGestor'              => self::modos()[ $c['egestor_modo'] ],
			'Credencial do eGestor'        => $eg ? 'Definida no wp-config.php' : 'Não definida (PN_EGESTOR_PERSONAL_TOKEN)',
			'Webhook do eGestor (NF-e)'    => ( defined( 'PN_BG_EGESTOR_WH_TOKEN' ) && PN_BG_EGESTOR_WH_TOKEN ) ? 'Token definido' : 'Token não definido (PN_BG_EGESTOR_WH_TOKEN)',
			'Pedidos já tratados'          => count( $st['done'] ),
			'Atualização automática'       => $up['repo'] ? 'Repositório: ' . $up['repo'] . ( $up['latest'] ? ' (última versão publicada: ' . $up['latest'] . ')' : ( $up['erro'] ? ' (erro: ' . $up['erro'] . ')' : '' ) ) : 'Repositório não definido',
		);
		foreach ( $linhas as $k => $v ) {
			echo '<tr><th style="width:240px">' . esc_html( $k ) . '</th><td>' . esc_html( (string) $v ) . '</td></tr>';
		}
		echo '</tbody></table>';
		echo '<p class="description">Endereço para cadastrar no eGestor (webhook de NF-e): <code>' . esc_html( rest_url( 'pn/v1/egestor' ) ) . '</code></p>';

		echo '<h2>Configuração</h2><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="pn_bg_save">';
		wp_nonce_field( 'pn_bg_save' );
		echo '<table class="form-table" role="presentation">';

		echo '<tr><th>Trava de privacidade</th><td><label><input type="checkbox" name="pn_bg[somente_bg]" value="1"' . checked( $c['somente_bg'], 1, false ) . '> Guardar e processar somente pedidos que tenham o produto Brasil Gigante</label><p class="description">Recomendado. Pedidos de outros produtos da loja são ignorados e não entram no banco do WordPress.</p></td></tr>';
		echo '<tr><th>Avisar pedido pago</th><td><label><input type="checkbox" name="pn_bg[avisos]" value="1"' . checked( $c['avisos'], 1, false ) . '> Enviar o aviso quando um pedido do Brasil Gigante for pago</label></td></tr>';
		echo '<tr><th>E-mail do aviso</th><td><input type="email" class="regular-text" name="pn_bg[email_aviso]" value="' . esc_attr( $c['email_aviso'] ) . '"></td></tr>';
		echo '<tr><th>Webhook do aviso (opcional)</th><td><input type="url" class="regular-text" name="pn_bg[webhook_url]" value="' . esc_attr( $c['webhook_url'] ) . '" placeholder="https://"><p class="description">Recebe o aviso em JSON. Só https. Assinatura HMAC no cabeçalho X-PN-Signature se PN_BG_WEBHOOK_SECRET estiver no wp-config.php.</p></td></tr>';
		echo '<tr><th>E-mail de teste</th><td><input type="email" class="regular-text" name="pn_bg[email_teste]" value="' . esc_attr( $c['email_teste'] ) . '"><p class="description">Pedido feito com este e-mail leva a faixa "PEDIDO DE TESTE" e não aciona o eGestor.</p></td></tr>';
		echo '<tr><th>Produto Brasil Gigante</th><td>ID do produto <input type="text" size="12" name="pn_bg[product_id]" value="' . esc_attr( $c['product_id'] ) . '"> ID da variação <input type="text" size="12" name="pn_bg[variant_id]" value="' . esc_attr( $c['variant_id'] ) . '"> SKU <input type="text" size="24" name="pn_bg[sku]" value="' . esc_attr( $c['sku'] ) . '"></td></tr>';

		echo '<tr><th>Modo do eGestor</th><td><select name="pn_bg[egestor_modo]">';
		foreach ( self::modos() as $k => $lab ) {
			echo '<option value="' . esc_attr( $k ) . '"' . selected( $c['egestor_modo'], $k, false ) . '>' . esc_html( $lab ) . '</option>';
		}
		echo '</select><p class="description">Comece em "Simulado". Só passe para "venda" ou "venda e NF-e" depois de validar com um pedido real e com o contador.</p></td></tr>';
		echo '<tr><th>Código do produto no eGestor</th><td><input type="text" size="8" name="pn_bg[egestor_cod_prod]" value="' . esc_attr( $c['egestor_cod_prod'] ) . '"></td></tr>';
		echo '<tr><th>Código da transportadora no eGestor (opcional)</th><td><input type="text" size="8" name="pn_bg[egestor_cod_transp]" value="' . esc_attr( $c['egestor_cod_transp'] ) . '"></td></tr>';

		echo '<tr><th>Repositório de atualizações</th><td><input type="text" class="regular-text" name="pn_update_repo" value="' . esc_attr( (string) get_option( PN_Updater::OPT_REPO, '' ) ) . '" placeholder="dono/repositorio"' . ( ( defined( 'PN_UPDATE_REPO' ) && PN_UPDATE_REPO ) ? ' disabled' : '' ) . '><p class="description">Formato dono/repositorio do GitHub. Sempre que houver uma versão nova publicada lá, o WordPress mostra "Atualizar agora".</p></td></tr>';
		echo '</table>';
		submit_button( 'Salvar' );
		echo '</form>';

		echo '<h2>Atualizações do plugin</h2><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline-block;margin-right:12px">';
		echo '<input type="hidden" name="action" value="pn_check_update">';
		wp_nonce_field( 'pn_check_update' );
		submit_button( 'Verificar atualização agora', 'secondary', 'submit', false );
		echo '</form>';

		echo '<h2>Ferramentas</h2><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline-block;margin-right:24px">';
		echo '<input type="hidden" name="action" value="pn_bg_test">';
		wp_nonce_field( 'pn_bg_test' );
		submit_button( 'Enviar aviso de teste', 'secondary', 'submit', false );
		echo '</form>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline-block">';
		echo '<input type="hidden" name="action" value="pn_bg_resend">';
		wp_nonce_field( 'pn_bg_resend' );
		echo '<input type="text" name="pn_num" size="8" placeholder="Nº do pedido"> ';
		submit_button( 'Reprocessar pedido pago', 'secondary', 'submit', false );
		echo '</form>';

		echo '<h2>Registro (sem dados pessoais)</h2>';
		if ( empty( $log ) || ! is_array( $log ) ) {
			echo '<p>Nada registrado ainda.</p>';
		} else {
			echo '<table class="widefat striped"><thead><tr><th style="width:150px">Quando</th><th style="width:90px">Pedido</th><th style="width:110px">Evento</th><th>Resultado</th></tr></thead><tbody>';
			foreach ( $log as $l ) {
				echo '<tr><td>' . esc_html( $l['quando'] ) . '</td><td>' . esc_html( $l['pedido'] ) . '</td><td>' . esc_html( $l['evento'] ) . '</td><td>' . esc_html( $l['resultado'] ) . '</td></tr>';
			}
			echo '</tbody></table>';
		}
	}
}
