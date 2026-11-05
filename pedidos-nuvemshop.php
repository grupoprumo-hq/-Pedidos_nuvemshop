<?php
/**
 * Plugin Name:       Pedidos Nuvemshop
 * Description:       Painel exclusivo do Café Brasil Gigante: lê somente os pedidos do produto (vendas e envios), avisa pedido pago e prepara a venda e a NF-e no eGestor. Acesso protegido por senha.
 * Version:           3.2.2
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            Café Zé Velho
 * License:           GPL-2.0-or-later
 * Text Domain:       pedidos-nuvemshop
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/includes/class-pn-updater.php';
require_once __DIR__ . '/includes/class-pn-egestor.php';
require_once __DIR__ . '/includes/class-pn-gate.php';
require_once __DIR__ . '/includes/class-pn-bg.php';

final class PN_Pedidos_Nuvemshop {

	const VERSION      = '3.2.2';
	const DB_VERSION   = '4';
	const OPT_SETTINGS = 'pn_settings';
	const OPT_STATE    = 'pn_state3';
	const OPT_CAPS     = 'pn_caps';
	const OPT_DBV      = 'pn_db_version';
	const CRON_HOOK    = 'pn_sync_event';
	const API_BASE     = 'https://api.nuvemshop.com.br/v1/';
	const PER_PAGE     = 100;
	const MAX_PAGES    = 15;
	const CAPS_TTL     = 6 * HOUR_IN_SECONDS;
	const DIAS_INICIAL = 90;

	/** Único dado da loja que o plugin consulta, além de "store" (nome da loja, para testar a conexão). */
	const RECURSOS_PERMITIDOS = array( 'store', 'orders' );

	/** @var PN_Pedidos_Nuvemshop|null */
	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_filter( 'cron_schedules', array( $this, 'add_cron_schedule' ) );
		add_action( self::CRON_HOOK, array( $this, 'cron_sync' ) );
		add_action( 'plugins_loaded', array( $this, 'maybe_upgrade' ), 20 );

		PN_Updater::init( __FILE__, self::VERSION );
		PN_Gate::init();
		PN_BG::init();

		if ( is_admin() ) {
			add_action( 'admin_menu', array( $this, 'admin_menu' ) );
			add_action( 'admin_init', array( $this, 'maybe_redirect_after_activation' ) );
			add_action( 'admin_notices', array( $this, 'notice_not_configured' ) );
			add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), array( $this, 'plugin_action_links' ) );
		}
	}

	/* ==================================================================
	 * Banco de dados
	 * ================================================================ */

	public static function table_orders() {
		global $wpdb;
		return $wpdb->prefix . 'nuvemshop_pedidos';
	}

	public static function create_tables() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		$orders  = self::table_orders();

		// Só pedidos do Brasil Gigante. Sem CPF, e-mail, telefone ou endereço completo: o nome vai mascarado.
		dbDelta(
			"CREATE TABLE {$orders} (
			order_id bigint(20) unsigned NOT NULL,
			numero bigint(20) unsigned NOT NULL DEFAULT 0,
			criado_em datetime NOT NULL,
			atualizado_em datetime NULL,
			status varchar(20) NOT NULL DEFAULT '',
			status_pagamento varchar(20) NOT NULL DEFAULT '',
			status_envio varchar(20) NOT NULL DEFAULT '',
			total decimal(12,2) NOT NULL DEFAULT 0,
			subtotal decimal(12,2) NOT NULL DEFAULT 0,
			desconto decimal(12,2) NOT NULL DEFAULT 0,
			frete_cliente decimal(12,2) NOT NULL DEFAULT 0,
			moeda varchar(8) NOT NULL DEFAULT 'BRL',
			gateway varchar(60) NOT NULL DEFAULT '',
			cliente_id bigint(20) unsigned NOT NULL DEFAULT 0,
			cliente_nome varchar(120) NOT NULL DEFAULT '',
			cidade varchar(100) NOT NULL DEFAULT '',
			uf varchar(60) NOT NULL DEFAULT '',
			servico_envio varchar(100) NOT NULL DEFAULT '',
			rastreio varchar(80) NOT NULL DEFAULT '',
			itens longtext NULL,
			pacotes_250 int(11) NOT NULL DEFAULT 0,
			pacotes_500 int(11) NOT NULL DEFAULT 0,
			kg decimal(10,3) NOT NULL DEFAULT 0,
			PRIMARY KEY  (order_id),
			KEY criado_em (criado_em),
			KEY status_pagamento (status_pagamento)
		) {$charset};"
		);
		update_option( self::OPT_DBV, self::DB_VERSION, false );
	}

	public static function activate() {
		self::create_tables();
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + 120, 'pn_30min', self::CRON_HOOK );
		}
		set_transient( 'pn_redirect_after_activation', 1, 60 );
	}

	public static function deactivate() {
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	/** Ao atualizar de uma versão antiga, apaga o que não é do Brasil Gigante (outros dados e pedidos) e reimporta só o produto. */
	public function maybe_upgrade() {
		$antes = get_option( self::OPT_DBV );
		if ( $antes !== self::DB_VERSION ) {
			global $wpdb;
			if ( false !== $antes && (int) $antes < 4 ) {
				// phpcs:disable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL
				$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'nuvemshop_dados' );
				$wpdb->query( 'TRUNCATE TABLE ' . self::table_orders() );
				// phpcs:enable
				delete_option( self::OPT_STATE );
				delete_option( self::OPT_CAPS );
				delete_option( 'pn_sync_state' );
			}
			self::create_tables();
		}
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + 120, 'pn_30min', self::CRON_HOOK );
		}
	}

	public function add_cron_schedule( $schedules ) {
		$schedules['pn_30min'] = array(
			'interval' => 30 * MINUTE_IN_SECONDS,
			'display'  => 'A cada 30 minutos (Pedidos Nuvemshop)',
		);
		return $schedules;
	}

	/* ==================================================================
	 * Menu e avisos
	 * ================================================================ */

	public function admin_menu() {
		add_menu_page( 'Brasil Gigante', 'Brasil Gigante', 'manage_options', 'pedidos-nuvemshop', array( 'PN_BG', 'render_page' ), 'dashicons-cart', 56 );
	}

	public function maybe_redirect_after_activation() {
		if ( ! get_transient( 'pn_redirect_after_activation' ) ) {
			return;
		}
		delete_transient( 'pn_redirect_after_activation' );
		if ( wp_doing_ajax() || is_network_admin() || isset( $_GET['activate-multi'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		if ( current_user_can( 'manage_options' ) ) {
			wp_safe_redirect( admin_url( 'admin.php?page=pedidos-nuvemshop' ) );
			exit;
		}
	}

	public function plugin_action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=pedidos-nuvemshop' ) ) . '"><strong>Abrir painel</strong></a>' );
		return $links;
	}

	public function notice_not_configured() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( '' !== $this->get_store_id() && '' !== $this->get_token() ) {
			return;
		}
		$tela = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( $tela && 'toplevel_page_pedidos-nuvemshop' === $tela->id ) {
			return;
		}
		echo '<div class="notice notice-warning"><p><strong>Brasil Gigante:</strong> falta conectar a loja. <a href="' . esc_url( admin_url( 'admin.php?page=pedidos-nuvemshop' ) ) . '">Abrir painel</a></p></div>';
	}

	/* ==================================================================
	 * Configuração da conexão
	 * ================================================================ */

	private function settings() {
		return wp_parse_args( get_option( self::OPT_SETTINGS, array() ), array( 'store_id' => '8063546', 'token' => '' ) );
	}

	private function get_token() {
		if ( defined( 'NUVEMSHOP_TOKEN' ) && NUVEMSHOP_TOKEN ) {
			return (string) NUVEMSHOP_TOKEN;
		}
		$s = $this->settings();
		return (string) $s['token'];
	}

	private function get_store_id() {
		if ( defined( 'NUVEMSHOP_STORE_ID' ) && NUVEMSHOP_STORE_ID ) {
			return preg_replace( '/\D/', '', (string) NUVEMSHOP_STORE_ID );
		}
		$s = $this->settings();
		return preg_replace( '/\D/', '', (string) $s['store_id'] );
	}

	/** Salva ID da loja e token (token em branco = mantém o atual) e testa a conexão. */
	public function bg_save_connection( $store_id, $token ) {
		$old = $this->settings();
		$new = array(
			'store_id' => preg_replace( '/\D/', '', (string) $store_id ),
			'token'    => ( '' === trim( (string) $token ) ) ? $old['token'] : preg_replace( '/[^A-Za-z0-9_\-\.]/', '', trim( (string) $token ) ),
		);
		update_option( self::OPT_SETTINGS, $new, false );
		$caps = $this->probe_all();
		if ( ! empty( $caps['erro'] ) ) {
			return array( 'ok' => false, 'msg' => 'Falhou: ' . $caps['erro'] );
		}
		if ( ! $this->can_read_orders() ) {
			return array( 'ok' => false, 'msg' => 'Conectado à loja, mas o token não tem permissão "Pedidos › Ler pedidos".' );
		}
		return array( 'ok' => true, 'msg' => 'Conexão OK com a loja "' . $caps['loja'] . '".' );
	}

	public function bg_connection_status() {
		$caps = get_option( self::OPT_CAPS, array() );
		if ( '' === $this->get_token() || '' === $this->get_store_id() ) {
			return array( 'ok' => false, 'texto' => 'Não conectado' );
		}
		if ( empty( $caps['testado_em'] ) ) {
			return array( 'ok' => false, 'texto' => 'Ainda não testado' );
		}
		if ( ! empty( $caps['erro'] ) ) {
			return array( 'ok' => false, 'texto' => 'Erro na conexão' );
		}
		return $this->can_read_orders() ? array( 'ok' => true, 'texto' => 'Conectado' ) : array( 'ok' => false, 'texto' => 'Sem permissão de ler pedidos' );
	}

	private function can_read_orders() {
		$caps = get_option( self::OPT_CAPS, array() );
		$st   = isset( $caps['recursos']['orders']['status'] ) ? $caps['recursos']['orders']['status'] : '';
		return in_array( $st, array( 'ok', 'vazio' ), true );
	}

	/* ==================================================================
	 * API da Nuvemshop (trava: só "store" e "orders")
	 * ================================================================ */

	public function bg_api_get( $path, $query = array() ) {
		return $this->api_get( $path, $query );
	}

	private function api_get( $path, $query = array() ) {
		$primeiro = strtok( trim( (string) $path, '/' ), '/' );
		if ( ! in_array( $primeiro, self::RECURSOS_PERMITIDOS, true ) ) {
			return new WP_Error( 'pn_trava', 'Bloqueado: este plugin só lê pedidos do Brasil Gigante.' );
		}
		$store_id = $this->get_store_id();
		$token    = $this->get_token();
		if ( '' === $store_id || '' === $token ) {
			return new WP_Error( 'pn_config', 'Informe o ID da loja e o token.' );
		}

		$url  = add_query_arg( $query, self::API_BASE . $store_id . '/' . ltrim( $path, '/' ) );
		$args = array(
			'timeout' => 30,
			'headers' => array(
				'Authentication' => 'bearer ' . $token,
				'User-Agent'     => 'Brasil Gigante WP (' . get_option( 'admin_email' ) . ')',
				'Content-Type'   => 'application/json',
			),
		);

		$tentativas = 0;
		do {
			$resp = wp_remote_get( $url, $args );
			if ( is_wp_error( $resp ) ) {
				return $resp;
			}
			$code = (int) wp_remote_retrieve_response_code( $resp );
			if ( 429 === $code && $tentativas < 3 ) {
				$reset  = (int) wp_remote_retrieve_header( $resp, 'x-rate-limit-reset' );
				$espera = $reset > 0 ? min( 5, max( 1, (int) ceil( $reset / 1000 ) ) ) : 2;
				sleep( $espera );
				$tentativas++;
				continue;
			}
			break;
		} while ( true );

		$body = json_decode( wp_remote_retrieve_body( $resp ), true );
		return array(
			'code' => $code,
			'body' => is_array( $body ) ? $body : null,
		);
	}

	private function localized( $v ) {
		if ( is_array( $v ) ) {
			foreach ( array( 'pt', 'pt-BR', 'pt_BR', 'es', 'en' ) as $k ) {
				if ( ! empty( $v[ $k ] ) && is_scalar( $v[ $k ] ) ) {
					return (string) $v[ $k ];
				}
			}
			foreach ( $v as $x ) {
				if ( is_scalar( $x ) && '' !== (string) $x ) {
					return (string) $x;
				}
			}
			return '';
		}
		return is_scalar( $v ) ? (string) $v : '';
	}

	/** Testa a loja e a leitura de pedidos (nada mais). */
	private function probe_all() {
		$caps = array( 'testado_em' => gmdate( 'c' ), 'loja' => '', 'erro' => '', 'recursos' => array() );

		$loja = $this->api_get( 'store' );
		if ( is_wp_error( $loja ) ) {
			$caps['erro'] = $loja->get_error_message();
		} elseif ( 401 === $loja['code'] || 403 === $loja['code'] ) {
			$caps['erro'] = 'token recusado pela Nuvemshop (confira o ID da loja e o token).';
		} elseif ( 200 !== $loja['code'] ) {
			$caps['erro'] = 'resposta inesperada da Nuvemshop (HTTP ' . (int) $loja['code'] . ').';
		} else {
			$caps['loja'] = $this->localized( isset( $loja['body']['name'] ) ? $loja['body']['name'] : '' );
		}

		if ( '' === $caps['erro'] ) {
			$r  = $this->api_get( 'orders', array( 'per_page' => 1 ) );
			$st = 'erro';
			if ( ! is_wp_error( $r ) ) {
				if ( 401 === $r['code'] || 403 === $r['code'] ) {
					$st = 'negado';
				} elseif ( 404 === $r['code'] ) {
					$st = 'vazio';
				} elseif ( $r['code'] >= 200 && $r['code'] < 300 ) {
					$st = 'ok';
				}
			}
			$caps['recursos']['orders'] = array( 'status' => $st );
		}

		update_option( self::OPT_CAPS, $caps, false );
		return $caps;
	}

	/* ==================================================================
	 * Sincronização (somente pedidos com o Brasil Gigante)
	 * ================================================================ */

	private function get_state() {
		$s = get_option( self::OPT_STATE, array() );
		return is_array( $s ) ? $s : array();
	}

	public function cron_sync() {
		$this->sync( false );
	}

	public function bg_sync_now() {
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 300 ); // phpcs:ignore
		}
		return $this->sync( true );
	}

	public function sync( $forcar_teste = false ) {
		if ( '' === $this->get_token() || '' === $this->get_store_id() ) {
			return array( 'ok' => false, 'linhas' => array( 'Informe o ID da loja e o token.' ) );
		}
		if ( get_transient( 'pn_sync_lock' ) ) {
			return array( 'ok' => false, 'linhas' => array( 'Já existe uma sincronização em andamento. Tente de novo em alguns minutos.' ) );
		}
		set_transient( 'pn_sync_lock', 1, 10 * MINUTE_IN_SECONDS );

		$caps  = get_option( self::OPT_CAPS, array() );
		$velho = empty( $caps['testado_em'] ) || ( time() - strtotime( $caps['testado_em'] ) ) > self::CAPS_TTL;
		if ( $forcar_teste || $velho ) {
			$caps = $this->probe_all();
		}
		if ( ! empty( $caps['erro'] ) ) {
			delete_transient( 'pn_sync_lock' );
			return array( 'ok' => false, 'linhas' => array( 'Falhou: ' . $caps['erro'] ) );
		}
		if ( ! $this->can_read_orders() ) {
			delete_transient( 'pn_sync_lock' );
			return array( 'ok' => false, 'linhas' => array( 'O token não tem permissão "Pedidos › Ler pedidos".' ) );
		}

		$state = $this->get_state();
		$res   = $this->sync_orders( isset( $state['orders'] ) ? $state['orders'] : array() );
		$state['orders'] = $res['state'];
		update_option( self::OPT_STATE, $state, false );
		delete_transient( 'pn_sync_lock' );

		do_action( 'pedidos_nuvemshop_synced', $state );

		$ok = ( '' === $res['erro'] );
		return array(
			'ok'     => $ok,
			'linhas' => array( $ok ? (int) $res['total'] . ' pedido(s) do Brasil Gigante sincronizado(s).' : 'Falhou: ' . $res['erro'] ),
		);
	}

	private function sync_orders( $state_antigo ) {
		if ( ! empty( $state_antigo['ultima_atualizacao'] ) ) {
			$desde = gmdate( 'c', strtotime( $state_antigo['ultima_atualizacao'] ) - HOUR_IN_SECONDS );
		} else {
			$desde = gmdate( 'c', time() - self::DIAS_INICIAL * DAY_IN_SECONDS );
		}

		$inicio = gmdate( 'c' );
		$total  = 0;
		$erro   = '';

		for ( $page = 1; $page <= self::MAX_PAGES; $page++ ) {
			$r = $this->api_get( 'orders', array( 'per_page' => self::PER_PAGE, 'page' => $page, 'updated_at_min' => $desde ) );

			if ( is_wp_error( $r ) ) {
				$erro = $r->get_error_message();
				break;
			}
			if ( 404 === $r['code'] ) {
				break;
			}
			if ( 401 === $r['code'] || 403 === $r['code'] ) {
				$erro = 'acesso negado (permissão removida no app da Nuvemshop?).';
				break;
			}
			if ( $r['code'] < 200 || $r['code'] >= 300 || ! is_array( $r['body'] ) ) {
				$erro = 'resposta inesperada da API (HTTP ' . (int) $r['code'] . ').';
				break;
			}
			if ( empty( $r['body'] ) ) {
				break;
			}
			foreach ( $r['body'] as $item ) {
				if ( is_array( $item ) && ! empty( $item['id'] ) && PN_BG::order_has_bg( $item ) ) {
					$this->upsert_order( $item );
					$total++;
				}
			}
			if ( count( $r['body'] ) < self::PER_PAGE ) {
				break;
			}
			usleep( 600000 );
		}

		return array(
			'total' => $total,
			'erro'  => $erro,
			'state' => array(
				'ultima_execucao'    => $inicio,
				'total'              => $total,
				'erro'               => $erro,
				'ultima_atualizacao' => ( '' === $erro ) ? $inicio : ( isset( $state_antigo['ultima_atualizacao'] ) ? $state_antigo['ultima_atualizacao'] : '' ),
			),
		);
	}

	/* ------------------------------------------------------------------
	 * Gravação (somente as linhas do Brasil Gigante; sem dados pessoais)
	 * ---------------------------------------------------------------- */

	private function to_mysql_gmt( $iso ) {
		if ( empty( $iso ) ) {
			return null;
		}
		$ts = strtotime( $iso );
		return $ts ? gmdate( 'Y-m-d H:i:s', $ts ) : null;
	}

	private function mask_name( $nome ) {
		$out = array();
		foreach ( (array) preg_split( '/\s+/', trim( (string) $nome ) ) as $p ) {
			if ( '' !== $p ) {
				$out[] = mb_substr( $p, 0, 1 ) . '***';
			}
		}
		return implode( ' ', $out );
	}

	private function upsert_order( array $o ) {
		global $wpdb;

		$itens = array();
		$pac   = 0;
		$kg    = 0.0;
		foreach ( (array) $o['products'] as $item ) {
			if ( ! is_array( $item ) || ! PN_BG::is_bg_line( $item ) ) {
				continue; // linhas de outros produtos não são guardadas.
			}
			$qtd    = isset( $item['quantity'] ) ? (int) $item['quantity'] : 0;
			$pac   += $qtd;
			$kg    += 0.25 * $qtd;
			$itens[] = array(
				'nome'  => $this->localized( isset( $item['name'] ) ? $item['name'] : '' ),
				'qtd'   => $qtd,
				'preco' => isset( $item['price'] ) ? (float) $item['price'] : 0,
				'sku'   => isset( $item['sku'] ) ? (string) $item['sku'] : '',
			);
		}

		$cust = ( isset( $o['customer'] ) && is_array( $o['customer'] ) ) ? $o['customer'] : array();
		$nome = isset( $cust['name'] ) ? $cust['name'] : ( isset( $o['contact_name'] ) ? $o['contact_name'] : '' );
		$ad   = ( isset( $o['shipping_address'] ) && is_array( $o['shipping_address'] ) ) ? $o['shipping_address'] : array();

		$wpdb->replace(
			self::table_orders(),
			array(
				'order_id'         => (int) $o['id'],
				'numero'           => isset( $o['number'] ) ? (int) $o['number'] : 0,
				'criado_em'        => $this->to_mysql_gmt( isset( $o['created_at'] ) ? $o['created_at'] : '' ),
				'atualizado_em'    => $this->to_mysql_gmt( isset( $o['updated_at'] ) ? $o['updated_at'] : '' ),
				'status'           => isset( $o['status'] ) ? sanitize_key( $o['status'] ) : '',
				'status_pagamento' => isset( $o['payment_status'] ) ? sanitize_key( $o['payment_status'] ) : '',
				'status_envio'     => isset( $o['shipping_status'] ) ? sanitize_key( $o['shipping_status'] ) : '',
				'total'            => isset( $o['total'] ) ? (float) $o['total'] : 0,
				'subtotal'         => isset( $o['subtotal'] ) ? (float) $o['subtotal'] : 0,
				'desconto'         => isset( $o['discount'] ) ? (float) $o['discount'] : 0,
				'frete_cliente'    => isset( $o['shipping_cost_customer'] ) ? (float) $o['shipping_cost_customer'] : 0,
				'moeda'            => isset( $o['currency'] ) ? substr( sanitize_text_field( $o['currency'] ), 0, 8 ) : 'BRL',
				'gateway'          => isset( $o['gateway'] ) ? substr( sanitize_text_field( (string) $o['gateway'] ), 0, 60 ) : '',
				'cliente_id'       => 0,
				'cliente_nome'     => substr( $this->mask_name( $nome ), 0, 120 ),
				'cidade'           => substr( sanitize_text_field( isset( $ad['city'] ) ? $ad['city'] : '' ), 0, 100 ),
				'uf'               => substr( sanitize_text_field( isset( $ad['province'] ) ? $ad['province'] : '' ), 0, 60 ),
				'servico_envio'    => substr( sanitize_text_field( isset( $o['shipping_option'] ) ? (string) $o['shipping_option'] : '' ), 0, 100 ),
				'rastreio'         => substr( sanitize_text_field( isset( $o['shipping_tracking_number'] ) ? (string) $o['shipping_tracking_number'] : '' ), 0, 80 ),
				'itens'            => wp_json_encode( $itens ),
				'pacotes_250'      => $pac,
				'pacotes_500'      => 0,
				'kg'               => round( $kg, 3 ),
			)
		);
	}

	/* ==================================================================
	 * Dados para o painel
	 * ================================================================ */

	public function bg_summary() {
		global $wpdb;
		$t   = self::table_orders();
		$out = array();
		foreach ( array( 'hoje' => 1, '7d' => 7, '30d' => 30 ) as $k => $dias ) {
			$de = ( 1 === $dias ) ? get_gmt_from_date( wp_date( 'Y-m-d 00:00:00' ) ) : gmdate( 'Y-m-d H:i:s', time() - $dias * DAY_IN_SECONDS );
			// phpcs:ignore WordPress.DB
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT COUNT(*) n, COALESCE(SUM(total),0) receita, COALESCE(SUM(pacotes_250),0) pacotes, COALESCE(AVG(frete_cliente),0) frete FROM {$t} WHERE status_pagamento = 'paid' AND criado_em >= %s", $de ), ARRAY_A );
			$out[ $k ] = $row ? $row : array( 'n' => 0, 'receita' => 0, 'pacotes' => 0, 'frete' => 0 );
		}
		// phpcs:disable WordPress.DB
		$out['ultimo'] = $wpdb->get_var( "SELECT criado_em FROM {$t} WHERE status_pagamento = 'paid' ORDER BY criado_em DESC LIMIT 1" );
		$out['envios'] = $wpdb->get_results( "SELECT status_envio, COUNT(*) n FROM {$t} WHERE status_pagamento = 'paid' GROUP BY status_envio", OBJECT_K );
		// phpcs:enable
		return $out;
	}

	public function bg_orders( $por_pagina, $pagina ) {
		global $wpdb;
		$t      = self::table_orders();
		$offset = max( 0, ( (int) $pagina - 1 ) * (int) $por_pagina );
		// phpcs:disable WordPress.DB
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t}" );
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} ORDER BY criado_em DESC LIMIT %d OFFSET %d", (int) $por_pagina, $offset ) );
		// phpcs:enable
		return array( 'total' => $total, 'rows' => $rows ? $rows : array() );
	}

	public function bg_last_sync() {
		$s = $this->get_state();
		return isset( $s['orders']['ultima_execucao'] ) ? $s['orders']['ultima_execucao'] : '';
	}
}

register_activation_hook( __FILE__, array( 'PN_Pedidos_Nuvemshop', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'PN_Pedidos_Nuvemshop', 'deactivate' ) );
add_action( 'plugins_loaded', array( 'PN_Pedidos_Nuvemshop', 'instance' ), 1 );
