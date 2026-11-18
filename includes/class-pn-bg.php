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
			add_action( 'admin_post_pn_bg_egtest', array( __CLASS__, 'handle_egtest' ) );
			add_action( 'admin_post_pn_bg_export', array( __CLASS__, 'handle_export' ) );
			add_action( 'wp_ajax_pn_bg_egcheck', array( __CLASS__, 'ajax_egcheck' ) );
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

	/** Os 3 cafés Brasil Gigante (travados no código). O código do eGestor de cada um é editável no painel. */
	private static function produtos_fixos() {
		return array(
			array( 'nome' => 'Gourmet Moído 250g', 'product_id' => '378563445', 'variant_id' => '1631936515', 'sku' => 'BG-GOURMET-MOIDO-250' ),
			array( 'nome' => 'Tradicional Moído 500g', 'product_id' => '381473266', 'variant_id' => '1640468839', 'sku' => 'BG-TRADICIONAL-MOIDO-500' ),
			array( 'nome' => 'Gourmet em Grãos 250g', 'product_id' => '381479034', 'variant_id' => '1640484262', 'sku' => 'BG-GOURMET-GRAOS-250' ),
		);
	}

	/** Códigos dos cafés no eGestor (cadastro de 17/11/2026). Podem ser trocados no painel. */
	const COD_EGESTOR_PADRAO = array(
		'BG-GOURMET-MOIDO-250'     => 9,
		'BG-GOURMET-GRAOS-250'     => 10,
		'BG-TRADICIONAL-MOIDO-500' => 11,
	);

	public static function catalogo() {
		$c    = self::cfg();
		$cods = isset( $c['egestor_cods'] ) && is_array( $c['egestor_cods'] ) ? $c['egestor_cods'] : array();
		$out  = array();
		foreach ( self::produtos_fixos() as $p ) {
			$sku      = $p['sku'];
			$p['cod'] = '';
			if ( ! empty( $cods[ $sku ] ) ) {
				$p['cod'] = (string) $cods[ $sku ];
			} elseif ( 'BG-GOURMET-MOIDO-250' === $sku && ! empty( $c['egestor_cod_prod'] ) ) {
				$p['cod'] = (string) $c['egestor_cod_prod']; // código já cadastrado na versão anterior.
			} elseif ( isset( self::COD_EGESTOR_PADRAO[ $sku ] ) ) {
				$p['cod'] = (string) self::COD_EGESTOR_PADRAO[ $sku ]; // códigos cadastrados no eGestor da Zé Velho.
			}
			$out[] = $p;
		}
		return $out;
	}

	/** Devolve a entrada do catálogo que corresponde à linha do pedido, ou null. */
	public static function match_item( array $it ) {
		foreach ( self::catalogo() as $p ) {
			if ( isset( $it['product_id'] ) && (string) $it['product_id'] === $p['product_id'] ) {
				return $p;
			}
			if ( isset( $it['variant_id'] ) && (string) $it['variant_id'] === $p['variant_id'] ) {
				return $p;
			}
			if ( isset( $it['sku'] ) && '' !== (string) $it['sku'] && (string) $it['sku'] === $p['sku'] ) {
				return $p;
			}
		}
		return null;
	}

	private static function modos() {
		return array(
			'off'       => 'Desligado',
			'simulado'  => 'Simulado (não chama o eGestor, só registra o que faria)',
			'venda'     => 'Criar contato e venda no eGestor (sem emitir nota)',
			'venda_nfe_rascunho' => 'Criar venda e gerar a NF-e como RASCUNHO (não envia à SEFAZ; revisar e transmitir no eGestor)',
			'venda_nfe' => 'Criar venda e emitir a NF-e (envia à SEFAZ)',
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
		return null !== self::match_item( $it );
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
		$linhas = array();
		$qtd    = 0;
		foreach ( (array) $o['products'] as $it ) {
			$m = is_array( $it ) ? self::match_item( $it ) : null;
			if ( $m ) {
				$bg       = $bg ? $bg : $it;
				$q        = isset( $it['quantity'] ) ? (int) $it['quantity'] : 0;
				$qtd     += $q;
				$linhas[] = array(
					'nome'       => $m['nome'],
					'sku'        => ( isset( $it['sku'] ) && '' !== (string) $it['sku'] ) ? (string) $it['sku'] : $m['sku'],
					'quantidade' => $q,
					'unitario'   => isset( $it['price'] ) ? (float) $it['price'] : 0.0,
					'cod'        => $m['cod'],
				);
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
				'quantidade' => $qtd,
				'unitario'   => isset( $bg['price'] ) ? (float) $bg['price'] : 0.0,
			),
			'produtos' => $linhas,
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
		$L[] = 'PRODUTOS';
		foreach ( $p['produtos'] as $l ) {
			$L[] = $l['quantidade'] . ' x ' . $l['nome'] . ' (SKU ' . ( $l['sku'] ? $l['sku'] : '-' ) . ') a ' . self::brl( $l['unitario'] ) . ' = ' . self::brl( $l['quantidade'] * $l['unitario'] );
		}
		$L[] = 'Total de pacotes: ' . $p['produto']['quantidade'];
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

		$itens   = array();
		$sem_cod = array();
		foreach ( $p['produtos'] as $l ) {
			if ( empty( $l['cod'] ) ) {
				$sem_cod[] = $l['sku'];
			}
			$itens[] = array( 'cod_produto' => (int) $l['cod'], 'quant' => $l['quantidade'], 'preco' => $l['unitario'] );
		}
		if ( $sem_cod && 'simulado' !== $modo ) {
			$m = 'eGestor: NÃO automático (sem o código do produto no eGestor para: ' . implode( ', ', $sem_cod ) . '). Preencha no painel e reenvie.';
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
			'itens'             => $itens,
			'total'             => $p['valores']['total'],
			'cod_transportador' => (int) $c['egestor_cod_transp'],
		);

		if ( 'simulado' === $modo ) {
			$vb = PN_EGestor::sale_body( $venda );
			$m  = 'eGestor (simulado): acharia/criaria o contato com ' . count( $cbody ) . ' campos e criaria a venda com ' . count( $vb['produtos'] ) . ' item(ns)' . ( $sem_cod ? ' [FALTA código eGestor de: ' . implode( ', ', $sem_cod ) . ']' : '' ) . ', frete ' . self::brl( $venda['frete'] ) . ', total ' . self::brl( $venda['total'] ) . ( 'venda_nfe' === $modo ? '; emitiria a NF-e.' : ( 'venda_nfe_rascunho' === $modo ? '; geraria a NF-e como rascunho.' : '; NF-e não seria pedida neste modo.' ) );
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
		if ( in_array( $modo, array( 'venda_nfe', 'venda_nfe_rascunho' ), true ) && empty( $reg['nfe_pedida'] ) ) {
			$enviar = ( 'venda_nfe' === $modo );
			$r      = PN_EGestor::request( 'POST', 'v1/vendas/' . (int) $reg['venda'] . '/gerarNfe', array(), array( 'enviar' => $enviar, 'contigOffline' => false ) );
			if ( is_wp_error( $r ) || ! in_array( $r['code'], array( 200, 201 ), true ) ) {
				$m = 'eGestor: NF-e NÃO emitida (' . PN_EGestor::err( $r ) . '). Emitir pelo eGestor.';
				self::log( $num, 'egestor', $m );
				self::save_reg( $num, $reg );
				return array_merge( $L, array( $m ) );
			}
			$reg['nfe_pedida'] = 1;
			$L[]               = $enviar ? 'eGestor: emissão da NF-e solicitada. O resultado (autorizada ou rejeitada) chega em outro aviso.' : 'eGestor: NF-e gerada como rascunho (código ' . (int) ( isset( $r['body']['codNota'] ) ? $r['body']['codNota'] : 0 ) . '). Revisar e transmitir pelo eGestor.';
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

	private static function sanitize_cods( $raw ) {
		$out = array();
		foreach ( self::produtos_fixos() as $p ) {
			$v = ( is_array( $raw ) && isset( $raw[ $p['sku'] ] ) ) ? preg_replace( '/\D/', '', (string) $raw[ $p['sku'] ] ) : '';
			if ( '' !== $v ) {
				$out[ $p['sku'] ] = $v;
			}
		}
		return $out;
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
			'egestor_cods'       => self::sanitize_cods( isset( $in['cods'] ) ? $in['cods'] : array() ),
			'egestor_cod_transp' => preg_replace( '/\D/', '', isset( $in['egestor_cod_transp'] ) ? $in['egestor_cod_transp'] : '' ),
		);
		update_option( self::OPT_CFG, $new, false );

		// Token do eGestor: só grava se vier preenchido; nunca é exibido de volta.
		$tk = isset( $_POST['pn_egestor_token'] ) ? PN_EGestor::clean_token( wp_unslash( $_POST['pn_egestor_token'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		if ( ! empty( $_POST['pn_egestor_token_remover'] ) ) {
			delete_option( PN_EGestor::OPT_TOKEN );
			delete_transient( PN_EGestor::TOKEN_KEY );
		} elseif ( '' !== $tk ) {
			if ( preg_match( '/^[A-Za-z0-9._\-+\/=]{20,2000}$/', $tk ) ) {
				update_option( PN_EGestor::OPT_TOKEN, $tk, false );
				delete_transient( PN_EGestor::TOKEN_KEY );
			} else {
				self::back( 'Token do eGestor com formato inválido. Cole o token inteiro, sem espaços.', 'error' );
			}
		}

		$hh = isset( $_POST['pn_sync_horas'] ) ? sanitize_text_field( wp_unslash( $_POST['pn_sync_horas'] ) ) : '2'; // phpcs:ignore WordPress.Security.NonceVerification
		update_option( 'pn_sync_horas', in_array( $hh, array( '0.5', '1', '2' ), true ) ? $hh : '2', false );
		PN_Pedidos_Nuvemshop::ensure_cron();
		self::back( 'Configurações salvas.' );
	}

	/** Valida o token enquanto é colado (não salva nada). */
	public static function ajax_egcheck() {
		check_ajax_referer( 'pn_bg_egcheck', 'n' );
		if ( ! current_user_can( 'manage_options' ) || ! PN_Gate::is_unlocked() ) {
			wp_send_json_error( array( 'msg' => 'Painel bloqueado.' ) );
		}
		$raw = isset( $_POST['t'] ) ? wp_unslash( $_POST['t'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$r   = PN_EGestor::check_token( $raw );
		wp_send_json_success( array( 'ok' => $r['ok'], 'msg' => $r['msg'] ) );
	}

	/** Testa a conexão com o eGestor (só leitura: GET /empresa). Não cria venda nem nota. */
	public static function handle_egtest() {
		self::guard();
		check_admin_referer( 'pn_bg_egtest' );
		// Token colado no campo: valida e já salva (corrigindo o "=" final, se faltar).
		$novo = isset( $_POST['pn_egestor_token'] ) ? PN_EGestor::clean_token( wp_unslash( $_POST['pn_egestor_token'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		if ( '' !== $novo ) {
			$chk = PN_EGestor::check_token( $novo );
			if ( ! $chk['ok'] ) {
				self::back( 'eGestor: token colado não foi aceito — ' . $chk['msg'], 'error' );
			}
			update_option( PN_EGestor::OPT_TOKEN, $chk['token'], false );
			delete_transient( PN_EGestor::TOKEN_KEY );
		}
		if ( ! PN_EGestor::has_credentials() ) {
			self::back( 'eGestor: token não informado.', 'error' );
		}
		delete_transient( PN_EGestor::TOKEN_KEY );
		$r = PN_EGestor::request( 'GET', 'v1/empresa' );
		if ( is_wp_error( $r ) || 200 !== $r['code'] ) {
			self::back( 'eGestor: conexão falhou (' . PN_EGestor::err( $r ) . ') Token salvo: ' . strlen( PN_EGestor::token() ) . ' caracteres, termina em "' . substr( PN_EGestor::token(), -3 ) . '".', 'error' );
		}
		$b    = $r['body'];
		$nome = '';
		foreach ( array( 'nome', 'razaoSocial', 'fantasia' ) as $k ) {
			if ( ! empty( $b[ $k ] ) && is_string( $b[ $k ] ) ) {
				$nome = $b[ $k ];
				break;
			}
		}
		self::back( 'eGestor: conexão OK' . ( '' !== $nome ? ' (empresa: ' . $nome . ')' : '' ) . '. Nenhuma venda ou nota foi criada.', 'success' );
	}

	public static function handle_test() {
		self::guard();
		check_admin_referer( 'pn_bg_test' );
		$c = self::cfg();
		$o = array(
			'number'   => 'TESTE',
			'id'       => '0',
			'paid_at'  => gmdate( 'c' ),
			'products' => array(
				array( 'product_id' => '378563445', 'variant_id' => '1631936515', 'sku' => 'BG-GOURMET-MOIDO-250', 'quantity' => 2, 'price' => '35.00' ),
				array( 'product_id' => '381473266', 'variant_id' => '1640468839', 'sku' => 'BG-TRADICIONAL-MOIDO-500', 'quantity' => 1, 'price' => '38.00' ),
			),
			'subtotal' => '108.00',
			'shipping_cost_customer' => '0.00',
			'discount' => '0.00',
			'total'    => '108.00',
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

	/* ------------------------------------------------------------------
	 * Visual do painel (3.5)
	 * ---------------------------------------------------------------- */

	private static function css() {
		return '<style>
.pn{max-width:1320px;color:#1d2327}
.pn *{box-sizing:border-box}
.pn h1{margin-bottom:4px}
.pn-bar{display:flex;gap:10px;align-items:center;flex-wrap:wrap;background:#fff;border:1px solid #dcdcde;border-radius:10px;padding:12px 16px;margin:12px 0 18px}
.pn-bar .sp{flex:1}
.pn-chip{display:inline-flex;align-items:center;gap:6px;padding:3px 10px;border-radius:999px;font-size:12px;font-weight:600;line-height:18px;white-space:nowrap;border:1px solid transparent}
.pn-chip.ok{background:#e6f4ea;color:#14672c;border-color:#b7dfc4}
.pn-chip.warn{background:#fff4e0;color:#8a5a00;border-color:#f0d6a0}
.pn-chip.err{background:#fde8e8;color:#a3252a;border-color:#f3b9bb}
.pn-chip.info{background:#e8f0fe;color:#1a4fbf;border-color:#bcd0f7}
.pn-chip.mut{background:#f0f0f1;color:#50575e;border-color:#dcdcde}
.pn-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:12px}
.pn-card{background:#fff;border:1px solid #dcdcde;border-radius:10px;padding:14px 16px}
.pn-card .t{color:#646970;font-size:12px;display:flex;align-items:center;gap:6px}
.pn-card .v{font-size:24px;font-weight:700;margin-top:4px;line-height:1.2}
.pn-card .s{color:#646970;font-size:12px;margin-top:2px}
.pn-sec{display:flex;align-items:center;gap:10px;margin:28px 0 10px}
.pn-sec h2{margin:0;font-size:17px}
.pn-sec .sp{flex:1}
.pn-panel{background:#fff;border:1px solid #dcdcde;border-radius:10px;padding:16px}
.pn-tablewrap{background:#fff;border:1px solid #dcdcde;border-radius:10px;overflow:auto}
.pn-table{width:100%;border-collapse:collapse;font-size:13px}
.pn-table th{background:#f6f7f7;text-align:left;padding:10px 12px;font-weight:600;color:#3c434a;border-bottom:1px solid #dcdcde;white-space:nowrap}
.pn-table td{padding:10px 12px;border-bottom:1px solid #f0f0f1;vertical-align:middle}
.pn-table tr:last-child td{border-bottom:0}
.pn-table tbody tr:hover{background:#fafafa}
.pn-table .num{text-align:right;font-variant-numeric:tabular-nums}
.pn-q{display:inline-flex;align-items:center;justify-content:center;width:18px;height:18px;border-radius:50%;border:1px solid #8c8f94;background:#fff;color:#50575e;font-size:11px;font-weight:700;line-height:1;cursor:pointer;padding:0;vertical-align:middle;position:relative}
.pn-q:hover,.pn-q.open{background:#2271b1;border-color:#2271b1;color:#fff}
.pn-pop{position:absolute;z-index:99999;max-width:320px;min-width:220px;background:#1d2327;color:#fff;border-radius:8px;padding:10px 12px;font-size:12.5px;line-height:1.45;font-weight:400;box-shadow:0 6px 24px rgba(0,0,0,.25);text-align:left;white-space:normal}
.pn-row{display:grid;grid-template-columns:230px 1fr;gap:6px 18px;padding:14px 0;border-bottom:1px solid #f0f0f1;align-items:start}
.pn-row:last-child{border-bottom:0}
.pn-row .lb{font-weight:600;display:flex;align-items:center;gap:8px}
.pn-row .ct small{display:block;color:#646970;margin-top:4px}
.pn-filters{display:flex;gap:8px;flex-wrap:wrap;align-items:center}
.pn-filters select,.pn-filters input[type=text]{min-height:30px}
.pn-chart{width:100%;height:auto}
.pn-legend{display:flex;gap:16px;color:#646970;font-size:12px;margin-top:6px;flex-wrap:wrap}
.pn-actions form{display:inline-block;margin:0 6px 6px 0}
@media (max-width:782px){.pn-row{grid-template-columns:1fr}}
</style>';
	}

	private static function js() {
		return '<script>(function(){var pop=null,btn=null;function close(){if(pop){pop.remove();pop=null}if(btn){btn.classList.remove("open");btn=null}}
document.addEventListener("click",function(e){var b=e.target.closest(".pn-q");if(!b){if(!e.target.closest(".pn-pop"))close();return}e.preventDefault();e.stopPropagation();var same=(btn===b);close();if(same)return;
pop=document.createElement("div");pop.className="pn-pop";pop.textContent=b.getAttribute("data-h");document.body.appendChild(pop);btn=b;b.classList.add("open");
var r=b.getBoundingClientRect(),w=pop.offsetWidth,x=Math.min(Math.max(8,r.left+window.scrollX-8),window.scrollX+document.documentElement.clientWidth-w-12);pop.style.left=x+"px";pop.style.top=(r.bottom+window.scrollY+8)+"px";});
document.addEventListener("keydown",function(e){if(e.key==="Escape")close()});})();</script>';
	}

	/** Botão "?" que abre a explicação do item. */
	private static function help( $texto ) {
		return '<button type="button" class="pn-q" aria-label="O que é isto?" data-h="' . esc_attr( $texto ) . '">?</button>';
	}

	private static function chip( $texto, $tipo = 'mut', $dica = '' ) {
		return '<span class="pn-chip ' . esc_attr( $tipo ) . '"' . ( '' !== $dica ? ' title="' . esc_attr( $dica ) . '"' : '' ) . '>' . esc_html( $texto ) . '</span>';
	}

	private static function card( $titulo, $valor, $sub = '', $ajuda = '' ) {
		echo '<div class="pn-card"><div class="t">' . esc_html( $titulo ) . ( '' !== $ajuda ? self::help( $ajuda ) : '' ) . '</div>';
		echo '<div class="v">' . esc_html( $valor ) . '</div>';
		if ( '' !== $sub ) {
			echo '<div class="s">' . esc_html( $sub ) . '</div>';
		}
		echo '</div>';
	}

	private static function section( $titulo, $ajuda = '', $extra = '' ) {
		echo '<div class="pn-sec"><h2>' . esc_html( $titulo ) . '</h2>' . ( '' !== $ajuda ? self::help( $ajuda ) : '' ) . '<span class="sp"></span>' . $extra . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput
	}

	/** Linha de configuração: rótulo + "?" + campo. */
	private static function row( $rotulo, $ajuda, $html ) {
		echo '<div class="pn-row"><div class="lb">' . esc_html( $rotulo ) . ( '' !== $ajuda ? self::help( $ajuda ) : '' ) . '</div><div class="ct">' . $html . '</div></div>'; // phpcs:ignore WordPress.Security.EscapeOutput
	}

	private static function brl_s( $v ) {
		return 'R$ ' . number_format_i18n( (float) $v, 2 );
	}

	/** Situação do pedido no eGestor, a partir da venda criada ou do último registro. */
	private static function egestor_chip( $num, $reg, $modo ) {
		if ( ! empty( $reg['venda'] ) ) {
			$t = 'Venda ' . (int) $reg['venda'];
			if ( ! empty( $reg['nfe_pedida'] ) ) {
				$t .= ' · NF-e';
			}
			return self::chip( '✔ ' . $t, 'ok', 'Venda criada no eGestor.' );
		}
		foreach ( (array) get_option( self::OPT_LOG, array() ) as $l ) {
			if ( ! is_array( $l ) || (string) $l['pedido'] !== (string) $num || 'egestor' !== $l['evento'] ) {
				continue;
			}
			$m = (string) $l['resultado'];
			if ( false !== strpos( $m, 'NÃO automático' ) ) {
				return self::chip( 'Manual (desconto)', 'warn', $m );
			}
			if ( false !== strpos( $m, 'CPF/CNPJ' ) ) {
				return self::chip( 'Sem CPF/CNPJ', 'warn', $m );
			}
			if ( false !== strpos( $m, '(simulado)' ) ) {
				return self::chip( 'Simulado ✔', 'info', $m );
			}
			if ( false !== stripos( $m, 'falh' ) || false !== stripos( $m, 'erro' ) ) {
				return self::chip( 'Erro', 'err', $m );
			}
			return self::chip( 'Registrado', 'info', $m );
		}
		if ( 'off' === $modo ) {
			return self::chip( 'Desligado', 'mut', 'O eGestor está desligado nas configurações.' );
		}
		return self::chip( 'Não processado', 'mut', 'Este pedido ainda não passou pelo eGestor (ele pode ser anterior à ativação). Use "Reprocessar pedido pago" para processar.' );
	}

	private static function envio_chip( $v ) {
		$tipo = array( 'delivered' => 'ok', 'shipped' => 'info', 'unshipped' => 'warn', 'unpacked' => 'warn', 'in_preparation' => 'warn', 'packed' => 'warn', 'cancelled' => 'err' );
		return self::chip( self::pt( 'envio', $v ), isset( $tipo[ $v ] ) ? $tipo[ $v ] : 'mut' );
	}

	private static function pag_chip( $v ) {
		$tipo = array( 'paid' => 'ok', 'pending' => 'warn', 'authorized' => 'info', 'voided' => 'err', 'refunded' => 'err', 'abandoned' => 'mut' );
		return self::chip( self::pt( 'pagamento', $v ), isset( $tipo[ $v ] ) ? $tipo[ $v ] : 'mut' );
	}

	private static function chart( $dias ) {
		$w  = 900;
		$h  = 190;
		$pl = 34;
		$pb = 22;
		$n  = count( $dias );
		$max = 1;
		foreach ( $dias as $d ) {
			$max = max( $max, (int) $d['pacotes'] );
		}
		$max = max( 4, (int) ( ceil( $max / 4 ) * 4 ) ); // escala em números inteiros.
		$bw = ( $w - $pl - 8 ) / max( 1, $n );
		$o  = '<svg class="pn-chart" viewBox="0 0 ' . $w . ' ' . $h . '" role="img" aria-label="Pacotes vendidos por dia nos últimos ' . (int) $n . ' dias">';
		for ( $g = 0; $g <= 4; $g++ ) {
			$y = 8 + ( $h - $pb - 16 ) * ( $g / 4 );
			$o .= '<line x1="' . $pl . '" x2="' . ( $w - 4 ) . '" y1="' . round( $y, 1 ) . '" y2="' . round( $y, 1 ) . '" stroke="#e5e5e5"/>';
			$o .= '<text x="' . ( $pl - 6 ) . '" y="' . round( $y + 4, 1 ) . '" text-anchor="end" font-size="10" fill="#8c8f94">' . (int) round( $max * ( 1 - $g / 4 ) ) . '</text>';
		}
		$i = 0;
		foreach ( $dias as $dia => $d ) {
			$bh = ( $h - $pb - 16 ) * ( (int) $d['pacotes'] / $max );
			$x  = $pl + $i * $bw + 2;
			$y  = $h - $pb - $bh;
			$tt = wp_date( 'd/m', strtotime( $dia ) ) . ': ' . (int) $d['n'] . ' pedido(s), ' . (int) $d['pacotes'] . ' pacote(s), ' . self::brl_s( $d['receita'] );
			$o .= '<g><title>' . esc_html( $tt ) . '</title><rect x="' . round( $x, 1 ) . '" y="' . round( $y, 1 ) . '" width="' . round( max( 2, $bw - 4 ), 1 ) . '" height="' . round( max( 0, $bh ), 1 ) . '" rx="2" fill="' . ( $d['pacotes'] > 0 ? '#2271b1' : '#dcdcde' ) . '"/>';
			if ( 0 === $d['pacotes'] ) {
				$o .= '<rect x="' . round( $x, 1 ) . '" y="' . ( $h - $pb - 2 ) . '" width="' . round( max( 2, $bw - 4 ), 1 ) . '" height="2" fill="#dcdcde"/>';
			}
			$o .= '</g>';
			if ( 0 === $i % 5 || $i === $n - 1 ) {
				$o .= '<text x="' . round( $x + $bw / 2, 1 ) . '" y="' . ( $h - 6 ) . '" text-anchor="middle" font-size="10" fill="#646970">' . esc_html( wp_date( 'd/m', strtotime( $dia ) ) ) . '</text>';
			}
			$i++;
		}
		return $o . '</svg>';
	}

	/** Baixa os pedidos em CSV (sem dados pessoais: nome já mascarado). */
	public static function handle_export() {
		self::guard();
		check_admin_referer( 'pn_bg_export' );
		$f   = array(
			'envio' => isset( $_GET['f_envio'] ) ? sanitize_key( wp_unslash( $_GET['f_envio'] ) ) : '',
			'pag'   => isset( $_GET['f_pag'] ) ? sanitize_key( wp_unslash( $_GET['f_pag'] ) ) : '',
			'q'     => isset( $_GET['q'] ) ? preg_replace( '/\D/', '', wp_unslash( $_GET['q'] ) ) : '',
		);
		$res = PN_Pedidos_Nuvemshop::instance()->bg_orders( 0, 1, $f );
		$st  = self::state();
		$c   = self::cfg();
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="pedidos-brasil-gigante-' . wp_date( 'Y-m-d' ) . '.csv"' );
		$out = fopen( 'php://output', 'w' );
		fwrite( $out, "\xEF\xBB\xBF" ); // BOM p/ abrir certo no Excel.
		fputcsv( $out, array( 'Pedido', 'Data', 'Pagamento', 'Pacotes', 'Total', 'Frete', 'Servico de envio', 'Envio', 'Rastreio', 'Cidade', 'UF', 'Cliente', 'eGestor (venda)' ), ';' );
		foreach ( $res['rows'] as $r ) {
			$reg = isset( $st['orders'][ (string) $r->numero ] ) ? $st['orders'][ (string) $r->numero ] : array();
			fputcsv(
				$out,
				array(
					$r->numero,
					get_date_from_gmt( $r->criado_em, 'd/m/Y H:i' ),
					self::pt( 'pagamento', $r->status_pagamento ),
					(int) $r->pacotes_250,
					number_format( (float) $r->total, 2, ',', '' ),
					number_format( (float) $r->frete_cliente, 2, ',', '' ),
					$r->servico_envio,
					self::pt( 'envio', $r->status_envio ),
					$r->rastreio,
					$r->cidade,
					$r->uf,
					$r->cliente_nome,
					! empty( $reg['venda'] ) ? $reg['venda'] : '',
				),
				';'
			);
		}
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		exit;
	}

	private static function render_panel() {
		$pn   = PN_Pedidos_Nuvemshop::instance();
		$c    = self::cfg();
		$st   = self::state();
		$conn = $pn->bg_connection_status();
		$sum  = $pn->bg_summary();
		$ult  = $pn->bg_last_sync();
		$up   = PN_Updater::status();
		$modo = $c['egestor_modo'];
		$modos = self::modos();

		echo self::css(); // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<div class="pn">';

		// Barra de topo: situação + ações.
		echo '<div class="pn-bar">';
		echo self::chip( ( $conn['ok'] ? '● ' : '● ' ) . 'Nuvemshop: ' . ( $conn['ok'] ? 'conectada' : 'desconectada' ), $conn['ok'] ? 'ok' : 'err', $conn['texto'] );
		if ( PN_EGestor::has_credentials() ) {
			echo self::chip( 'eGestor: token salvo', 'ok' );
		} else {
			echo self::chip( 'eGestor: sem token', 'warn' );
		}
		echo self::chip( 'Modo: ' . ( array( 'off' => 'Desligado', 'simulado' => 'Simulado', 'venda' => 'Criar venda', 'venda_nfe_rascunho' => 'NF-e rascunho', 'venda_nfe' => 'NF-e real' )[ $modo ] ?? $modo ), 'off' === $modo ? 'mut' : ( 'simulado' === $modo ? 'info' : 'warn' ), isset( $modos[ $modo ] ) ? $modos[ $modo ] : '' );
		echo '<span style="color:#646970;font-size:12px">Última sincronização: ' . esc_html( $ult ? get_date_from_gmt( gmdate( 'Y-m-d H:i:s', strtotime( $ult ) ), 'd/m/Y H:i' ) : 'nunca' ) . '</span>';
		echo '<span class="sp"></span><span class="pn-actions">';
		self::postform( 'pn_bg_sync', 'pn_bg_sync', '⟳ Sincronizar agora', 'button button-primary' );
		self::postform( 'pn_gate_lock', 'pn_gate_lock', '🔒 Bloquear painel' );
		echo '</span></div>';

		if ( 0 === strpos( $conn['texto'], 'Falta' ) ) {
			echo '<div class="notice notice-warning inline"><p><strong>Por isso a sincronização não roda:</strong> ' . esc_html( $conn['texto'] ) . '. Vá em "Conexão com a Nuvemshop" (no fim da página), cole o token e clique em "Salvar e testar conexão".</p></div>';
		}

		// 1. Resumo.
		self::section( 'Resumo de vendas', 'Conta apenas pedidos PAGOS que têm Brasil Gigante. Os valores vêm do total do pedido na Nuvemshop (já com descontos).' );
		echo '<div class="pn-grid">';
		self::card( 'Hoje', (int) $sum['hoje']['n'] . ' pedidos', self::brl_s( $sum['hoje']['receita'] ) );
		self::card( 'Últimos 7 dias', (int) $sum['7d']['n'] . ' pedidos', self::brl_s( $sum['7d']['receita'] ) );
		self::card( 'Últimos 30 dias', (int) $sum['30d']['n'] . ' pedidos', self::brl_s( $sum['30d']['receita'] ) );
		self::card( 'Pacotes (30 dias)', (string) (int) $sum['30d']['pacotes'], 'Frete médio ' . self::brl_s( $sum['30d']['frete'] ), 'Soma das unidades vendidas (cada café conta 1 pacote, independente do peso).' );
		self::card( 'Último pedido pago', $sum['ultimo'] ? get_date_from_gmt( $sum['ultimo'], 'd/m H:i' ) : '—' );
		echo '</div>';

		// 2. Gráfico.
		self::section( 'Pacotes vendidos por dia', 'Barras = pacotes dos pedidos pagos em cada dia (últimos 30 dias). Passe o mouse sobre uma barra para ver pedidos e valor do dia.' );
		echo '<div class="pn-panel">' . self::chart( $pn->bg_daily( 30 ) ) . '<div class="pn-legend"><span>■ <span style="color:#2271b1">pacotes por dia</span></span><span>Passe o mouse na barra para ver o detalhe do dia</span></div></div>'; // phpcs:ignore WordPress.Security.EscapeOutput

		// 3. Envios.
		self::section( 'Situação dos envios', '"Envio" é a fase da entrega do pedido na Nuvemshop: A enviar → Enviado → Entregue. O "Rastreio" é o código da transportadora para acompanhar a encomenda.' );
		$e = $sum['envios'];
		$n = function ( $k ) use ( $e ) {
			return isset( $e[ $k ] ) ? (int) $e[ $k ]->n : 0;
		};
		echo '<div class="pn-grid">';
		self::card( 'A enviar', (string) ( $n( 'unshipped' ) + $n( 'unpacked' ) + $n( 'in_preparation' ) + $n( 'packed' ) ), 'Pagos, ainda não despachados' );
		self::card( 'Enviados', (string) $n( 'shipped' ), 'A caminho do cliente' );
		self::card( 'Entregues', (string) $n( 'delivered' ), 'Concluídos' );
		echo '</div>';

		// 4. Pedidos.
		$f_envio = isset( $_GET['f_envio'] ) ? sanitize_key( wp_unslash( $_GET['f_envio'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$f_pag   = isset( $_GET['f_pag'] ) ? sanitize_key( wp_unslash( $_GET['f_pag'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$f_q     = isset( $_GET['q'] ) ? preg_replace( '/\D/', '', wp_unslash( $_GET['q'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$pag     = isset( $_GET['pg'] ) ? max( 1, (int) $_GET['pg'] ) : 1; // phpcs:ignore WordPress.Security.NonceVerification
		$filtros = array( 'envio' => $f_envio, 'pag' => $f_pag, 'q' => $f_q );
		$res     = $pn->bg_orders( 20, $pag, $filtros );
		$exp_url = wp_nonce_url( add_query_arg( array( 'action' => 'pn_bg_export', 'f_envio' => $f_envio, 'f_pag' => $f_pag, 'q' => $f_q ), admin_url( 'admin-post.php' ) ), 'pn_bg_export' );
		self::section( 'Pedidos do Brasil Gigante', 'Cada linha é um pedido com o produto. Use os filtros para achar um pedido e "Exportar CSV" para abrir na planilha (sem CPF, e-mail ou telefone).', '<a class="button" href="' . esc_url( $exp_url ) . '">⬇ Exportar CSV</a>' );
		echo '<form method="get" class="pn-filters" style="margin-bottom:10px"><input type="hidden" name="page" value="pedidos-nuvemshop">';
		echo '<input type="text" name="q" value="' . esc_attr( $f_q ) . '" placeholder="Nº do pedido" size="12">';
		echo '<select name="f_envio">';
		foreach ( array( '' => 'Todos os envios', 'unshipped' => 'A enviar', 'shipped' => 'Enviados', 'delivered' => 'Entregues' ) as $k => $lab ) {
			echo '<option value="' . esc_attr( $k ) . '"' . selected( $f_envio, $k, false ) . '>' . esc_html( $lab ) . '</option>';
		}
		echo '</select><select name="f_pag">';
		foreach ( array( '' => 'Todos os pagamentos', 'paid' => 'Pagos', 'pending' => 'Pendentes', 'voided' => 'Anulados' ) as $k => $lab ) {
			echo '<option value="' . esc_attr( $k ) . '"' . selected( $f_pag, $k, false ) . '>' . esc_html( $lab ) . '</option>';
		}
		echo '</select><button class="button">Filtrar</button>';
		if ( $f_envio || $f_pag || $f_q ) {
			echo '<a href="' . esc_url( add_query_arg( 'page', 'pedidos-nuvemshop', admin_url( 'admin.php' ) ) ) . '">limpar</a>';
		}
		echo '<span style="color:#646970;margin-left:auto">' . (int) $res['total'] . ' pedido(s)</span></form>';

		if ( ! $res['rows'] ) {
			echo '<div class="pn-panel">Nenhum pedido encontrado. Se for a primeira vez, clique em "Sincronizar agora".</div>';
		} else {
			$h_envio = self::help( 'Fase da entrega na Nuvemshop: A enviar, Enviado ou Entregue. Atualiza a cada sincronização.' );
			$h_rast  = self::help( 'Código de rastreio da transportadora. Fica "—" se a loja ainda não informou o código (pedidos marcados como entregues manualmente, por exemplo, podem não ter).' );
			$h_eg    = self::help( 'O que aconteceu com o pedido no eGestor: Venda criada, Simulado (só registrou o que faria), Manual (pedido com desconto não é lançado automaticamente), Não processado, Erro. Passe o mouse no selo para ver o detalhe.' );
			echo '<div class="pn-tablewrap"><table class="pn-table"><thead><tr><th>Nº</th><th>Data</th><th>Pagamento</th><th class="num">Pacotes</th><th class="num">Total</th><th class="num">Frete</th><th>Serviço</th><th>Envio ' . $h_envio . '</th><th>Rastreio ' . $h_rast . '</th><th>Destino</th><th>Cliente</th><th>eGestor ' . $h_eg . '</th></tr></thead><tbody>'; // phpcs:ignore WordPress.Security.EscapeOutput
			foreach ( $res['rows'] as $r ) {
				$reg = isset( $st['orders'][ (string) $r->numero ] ) ? $st['orders'][ (string) $r->numero ] : array();
				echo '<tr><td><strong>#' . (int) $r->numero . '</strong></td>';
				echo '<td>' . esc_html( get_date_from_gmt( $r->criado_em, 'd/m/Y H:i' ) ) . '</td>';
				echo '<td>' . self::pag_chip( $r->status_pagamento ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput
				echo '<td class="num">' . (int) $r->pacotes_250 . '</td>';
				echo '<td class="num">' . esc_html( self::brl_s( $r->total ) ) . '</td>';
				echo '<td class="num">' . esc_html( self::brl_s( $r->frete_cliente ) ) . '</td>';
				echo '<td>' . esc_html( $r->servico_envio ? $r->servico_envio : '—' ) . '</td>';
				echo '<td>' . self::envio_chip( $r->status_envio ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput
				echo '<td>' . ( $r->rastreio ? '<code>' . esc_html( $r->rastreio ) . '</code>' : ( in_array( $r->status_envio, array( 'shipped', 'delivered' ), true ) ? '<span style="color:#8c8f94" title="A Nuvemshop não tem código de rastreio neste pedido (comum em pedidos de teste ou marcados como entregues manualmente).">sem código na loja</span>' : '<span style="color:#8c8f94">—</span>' ) ) . '</td>';
				echo '<td>' . esc_html( trim( $r->cidade . ' ' . $r->uf ) ) . '</td>';
				echo '<td>' . esc_html( $r->cliente_nome ) . '</td>';
				echo '<td>' . self::egestor_chip( $r->numero, $reg, $modo ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
			}
			echo '</tbody></table></div>';
			$paginas = (int) ceil( $res['total'] / 20 );
			if ( $paginas > 1 ) {
				echo '<p style="margin-top:10px">';
				for ( $i = 1; $i <= $paginas; $i++ ) {
					$u = add_query_arg( array( 'page' => 'pedidos-nuvemshop', 'pg' => $i, 'f_envio' => $f_envio, 'f_pag' => $f_pag, 'q' => $f_q ), admin_url( 'admin.php' ) );
					echo $i === $pag ? '<strong>' . (int) $i . '</strong> ' : '<a href="' . esc_url( $u ) . '">' . (int) $i . '</a> ';
				}
				echo '</p>';
			}
		}

		// 5. Configurações.
		self::section( 'Configurações' );
		echo '<div class="pn-panel"><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="pn_bg_save">';
		wp_nonce_field( 'pn_bg_save' );

		self::row( 'Avisar pedido pago', 'Quando um pedido do Brasil Gigante é pago, o plugin registra um aviso na seção "Registro" e, se houver webhook, também envia o aviso para ele.', '<label><input type="checkbox" name="pn_bg[avisos]" value="1"' . checked( $c['avisos'], 1, false ) . '> Ativado</label>' );
		self::row( 'Webhook do aviso (opcional)', 'Endereço https que recebe o aviso do pedido pago em formato JSON (para ligar a WhatsApp, Slack, Zapier etc.). Em branco: o aviso fica só no painel.', '<input type="url" class="regular-text" name="pn_bg[webhook_url]" value="' . esc_attr( $c['webhook_url'] ) . '" placeholder="https://">' );

		$h    = PN_Pedidos_Nuvemshop::sync_horas();
		$prox = wp_next_scheduled( PN_Pedidos_Nuvemshop::CRON_HOOK );
		$sel  = '<select name="pn_sync_horas">';
		foreach ( array( '0.5' => 'a cada 30 minutos', '1' => 'a cada 1 hora', '2' => 'a cada 2 horas (recomendado)' ) as $k => $lab ) {
			$sel .= '<option value="' . esc_attr( $k ) . '"' . selected( $h, (string) $k, false ) . '>' . esc_html( $lab ) . '</option>';
		}
		$sel .= '</select>' . ( $prox ? '<small>Próxima automática: ' . esc_html( get_date_from_gmt( gmdate( 'Y-m-d H:i:s', $prox ), 'd/m H:i' ) ) . '</small>' : '' );
		self::row( 'Sincronização automática', 'Com que frequência o plugin busca na Nuvemshop os pedidos novos e atualiza envio e rastreio. O botão "Sincronizar agora" faz o mesmo na hora.', $sel );

		$sel = '<select name="pn_bg[egestor_modo]">';
		foreach ( $modos as $k => $lab ) {
			$sel .= '<option value="' . esc_attr( $k ) . '"' . selected( $modo, $k, false ) . '>' . esc_html( $lab ) . '</option>';
		}
		$sel .= '</select>';
		self::row( 'Modo do eGestor', 'Desligado: não faz nada. Simulado: só registra o que faria (não cria nada no eGestor). Venda: cria contato e venda. NF-e como rascunho: cria a nota sem enviar à SEFAZ. NF-e: emite a nota de verdade. Comece sempre em Simulado.', $sel );

		$tem = PN_EGestor::has_credentials();
		$tk  = '<input type="password" class="regular-text" name="pn_egestor_token" value="" autocomplete="new-password" placeholder="' . ( $tem ? 'token salvo — deixe em branco para manter' : 'cole o token (eGestor › Configurações › API)' ) . '"> ';
		$tk .= '<label style="margin-left:8px"><input type="checkbox" name="pn_egestor_token_remover" value="1"> remover token salvo</label>';
		$tk .= '<div id="pn-egchk" style="margin-top:6px;font-weight:600"></div>';
		$tk .= '<small>Status: ' . ( $tem ? '<strong>token definido</strong> (' . (int) strlen( PN_EGestor::token() ) . ' caracteres, termina em ' . esc_html( substr( PN_EGestor::token(), -3 ) ) . ')' . ( ( defined( 'PN_EGESTOR_PERSONAL_TOKEN' ) && PN_EGESTOR_PERSONAL_TOKEN ) ? ' (via wp-config.php)' : '' ) : 'não definido' ) . '</small>';
		self::row( 'Token do eGestor', 'Gerado no eGestor em Configurações › API. Ao colar, o plugin já confere se o eGestor aceita (✔ verde ou ✖ vermelho). O token fica só neste site e nunca é exibido de volta.', $tk );

		$at = '<span class="pn-chip ok">🔒 Automáticas · repositório oficial</span><small>Versão instalada: ' . esc_html( PN_Pedidos_Nuvemshop::VERSION ) . ( $up['latest'] ? ' · última publicada: ' . esc_html( $up['latest'] ) : '' ) . '</small>' . self::update_line( $up );
		self::row( 'Atualizações', 'O plugin se atualiza sozinho a partir das versões publicadas no GitHub oficial do projeto. Não precisa reenviar o .zip.', $at );

		echo '<details style="margin:12px 0 4px"><summary style="cursor:pointer;font-weight:600">Avançado (códigos do eGestor, transportadora e e-mail de teste)</summary>';
		$cat = '<table class="pn-table"><thead><tr><th>Café</th><th>Produto / variação</th><th>SKU</th><th>Código no eGestor</th></tr></thead><tbody>';
		foreach ( self::catalogo() as $cp ) {
			$cat .= '<tr><td>' . esc_html( $cp['nome'] ) . '</td><td><code>' . esc_html( $cp['product_id'] ) . '</code> / <code>' . esc_html( $cp['variant_id'] ) . '</code></td><td><code>' . esc_html( $cp['sku'] ) . '</code></td><td><input type="text" size="8" name="pn_bg[cods][' . esc_attr( $cp['sku'] ) . ']" value="' . esc_attr( $cp['cod'] ) . '"></td></tr>';
		}
		$cat .= '</tbody></table>';
		self::row( 'Cafés Brasil Gigante', 'Travado: só estes 3 cafés entram no painel. O código do eGestor de cada um é obrigatório para criar a venda automática.', $cat );
		self::row( 'Transportadora no eGestor', 'Código da transportadora cadastrada no eGestor, para sair já na venda. Opcional.', '<input type="text" size="8" name="pn_bg[egestor_cod_transp]" value="' . esc_attr( $c['egestor_cod_transp'] ) . '">' );
		self::row( 'E-mail do comprador de teste', 'Pedido feito com este e-mail é marcado como teste e não aciona o eGestor.', '<input type="email" class="regular-text" name="pn_bg[email_teste]" value="' . esc_attr( $c['email_teste'] ) . '">' );
		echo '</details>';
		submit_button( 'Salvar configurações', 'primary', 'submit', false );
		echo '</form></div>';

		// Ações de teste.
		self::section( 'Testes e manutenção', 'Botões para conferir se tudo funciona. Nenhum deles cria nota fiscal.' );
		echo '<div class="pn-panel pn-actions">';
		self::postform( 'pn_bg_test', 'pn_bg_test', 'Enviar aviso de teste' );
		echo '<form id="pn-egtest-form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="pn_bg_egtest">';
		wp_nonce_field( 'pn_bg_egtest' );
		echo '<input type="hidden" name="pn_egestor_token" id="pn-egtest-tk" value=""><button type="submit" class="button">Testar conexão com o eGestor</button></form>';
		$nonce_chk = wp_create_nonce( 'pn_bg_egcheck' );
		echo '<script>(function(){var f=document.querySelector("input[name=pn_egestor_token]"),o=document.getElementById("pn-egchk"),h=document.getElementById("pn-egtest-tk"),tf=document.getElementById("pn-egtest-form"),t;if(!f||!o)return;'
			. 'function chk(){var v=f.value.replace(/[\\s"\']+/g,"");if(!v){o.textContent="";return;}o.style.color="#555";o.textContent="Verificando no eGestor…";'
			. 'var d=new FormData();d.append("action","pn_bg_egcheck");d.append("n","' . esc_js( $nonce_chk ) . '");d.append("t",v);'
			. 'fetch(ajaxurl,{method:"POST",credentials:"same-origin",body:d}).then(function(r){return r.json()}).then(function(j){if(!j||!j.success){o.style.color="#b32d2e";o.textContent="✖ "+((j&&j.data&&j.data.msg)||"Não foi possível verificar.");return;}'
			. 'if(j.data.ok){o.style.color="#1a7f37";o.textContent="✔ Token válido — "+j.data.msg+" Clique em \"Testar conexão\" (já salva) ou em Salvar.";}else{o.style.color="#b32d2e";o.textContent="✖ Token recusado: "+j.data.msg;}}).catch(function(){o.style.color="#b32d2e";o.textContent="✖ Falha ao verificar.";});}'
			. 'f.addEventListener("paste",function(){setTimeout(chk,150)});f.addEventListener("input",function(){clearTimeout(t);t=setTimeout(chk,900)});'
			. 'if(tf)tf.addEventListener("submit",function(){h.value=f.value;});})();</script>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="pn_bg_resend">';
		wp_nonce_field( 'pn_bg_resend' );
		echo '<input type="text" name="pn_num" size="8" placeholder="Nº do pedido"> <button type="submit" class="button">Reprocessar pedido pago</button></form>';
		self::postform( 'pn_check_update', 'pn_check_update', 'Verificar atualização' );
		echo '</div>';

		// 6. Conexão com a Nuvemshop.
		self::section( 'Conexão com a Nuvemshop', 'Loja e token usados para ler os pedidos. O token só precisa da permissão "Pedidos › Ler pedidos".' );
		echo '<div class="pn-panel">';
		$s_id = ( defined( 'NUVEMSHOP_STORE_ID' ) && NUVEMSHOP_STORE_ID ) ? (string) NUVEMSHOP_STORE_ID : (string) ( ( get_option( 'pn_settings', array() )['store_id'] ?? '8063546' ) );
		$hint      = $pn->bg_connection_hint();
		$conectado = ! empty( $conn['ok'] );
		if ( $conectado ) {
			echo '<p style="margin-top:0">🔒 Conexão travada: loja <code>' . esc_html( $hint['store_id'] ) . '</code> · token terminado em <code>' . esc_html( $hint['token_fim'] ) . '</code>.</p>';
			echo '<details><summary style="cursor:pointer">Trocar a conexão (pede a senha do painel)</summary>';
		}
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="pn_bg_conn">';
		wp_nonce_field( 'pn_bg_conn' );
		self::row( 'ID da loja', '', '<input type="text" class="regular-text" name="store_id" value="' . esc_attr( $hint['store_id'] ? $hint['store_id'] : $s_id ) . '">' );
		self::row( 'Token', 'Deixe em branco para manter o token salvo. Ele nunca é exibido de volta.', '<input type="password" class="regular-text" name="token" value="" autocomplete="new-password" placeholder="Deixe em branco para manter o token salvo">' );
		if ( $conectado ) {
			self::row( 'Senha do painel', 'Necessária para trocar a conexão.', '<input type="password" class="regular-text" name="senha" autocomplete="current-password" required>' );
		}
		submit_button( 'Salvar e testar conexão', 'secondary', 'submit', false );
		echo '</form>';
		if ( $conectado ) {
			echo '</details>';
		}
		echo '</div>';

		self::section( 'Senha do painel' );
		echo '<div class="pn-panel">';
		PN_Gate::render_change();
		echo '</div>';

		// 7. Registro.
		self::section( 'Registro (sem dados pessoais)', 'Histórico do que o plugin fez em cada pedido: avisos enviados e o que faria/fez no eGestor. Guarda os 40 eventos mais recentes.' );
		$log = get_option( self::OPT_LOG, array() );
		if ( empty( $log ) || ! is_array( $log ) ) {
			echo '<div class="pn-panel">Nada registrado ainda.</div>';
		} else {
			echo '<div class="pn-tablewrap"><table class="pn-table"><thead><tr><th style="width:150px">Quando</th><th style="width:90px">Pedido</th><th style="width:110px">Evento</th><th>Resultado</th></tr></thead><tbody>';
			foreach ( $log as $l ) {
				echo '<tr><td>' . esc_html( $l['quando'] ) . '</td><td>' . esc_html( $l['pedido'] ) . '</td><td>' . esc_html( $l['evento'] ) . '</td><td>' . esc_html( $l['resultado'] ) . '</td></tr>';
			}
			echo '</tbody></table></div>';
		}
		echo '<p style="color:#646970;margin-top:20px;font-size:12px">Privacidade: este painel mostra e guarda apenas pedidos com o Brasil Gigante. Nome do cliente mascarado; CPF, e-mail, telefone e endereço completo não ficam gravados.</p>';
		echo '</div>';
		echo self::js(); // phpcs:ignore WordPress.Security.EscapeOutput
	}
}
