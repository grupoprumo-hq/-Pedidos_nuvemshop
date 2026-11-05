<?php
/**
 * Plugin Name:       Pedidos Nuvemshop
 * Description:       Conecta o WordPress à API da Nuvemshop (somente leitura). Detecta o que o token pode ler, importa pedidos, produtos, categorias, clientes e cupons, mostra tudo em abas e exporta CSVs (inclusive o resumo semanal do painel de métricas).
 * Version:           3.1.0
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
require_once __DIR__ . '/includes/class-pn-bg.php';

final class PN_Pedidos_Nuvemshop {

	const VERSION      = '3.1.0';
	const DB_VERSION   = '3';
	const OPT_SETTINGS = 'pn_settings';
	const OPT_STATE    = 'pn_state3';
	const OPT_CAPS     = 'pn_caps';
	const OPT_DBV      = 'pn_db_version';
	const CRON_HOOK    = 'pn_sync_event';
	const API_BASE     = 'https://api.nuvemshop.com.br/v1/';
	const PER_PAGE     = 100;
	const MAX_PAGES    = 15;  // por recurso e por execução.
	const CAPS_TTL     = 6 * HOUR_IN_SECONDS; // reconfere as permissões do token a cada 6 horas.

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

		// Atualização automática (GitHub Releases) e módulo Brasil Gigante.
		PN_Updater::init( __FILE__, self::VERSION );
		PN_BG::init();

		if ( is_admin() ) {
			add_action( 'admin_menu', array( $this, 'admin_menu' ) );
			add_action( 'admin_init', array( $this, 'maybe_redirect_after_activation' ) );
			add_action( 'admin_notices', array( $this, 'notice_not_configured' ) );
			add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), array( $this, 'plugin_action_links' ) );
			add_action( 'admin_post_pn_save', array( $this, 'handle_save' ) );
			add_action( 'admin_post_pn_probe', array( $this, 'handle_probe' ) );
			add_action( 'admin_post_pn_sync_now', array( $this, 'handle_sync_now' ) );
			add_action( 'admin_post_pn_export_csv', array( $this, 'handle_export_csv' ) );
			add_action( 'admin_post_pn_export_products', array( $this, 'handle_export_products' ) );
		}
	}

	/* ==================================================================
	 * Recursos da API
	 * ================================================================ */

	/**
	 * sync = importa para o banco; core = 404 numa lista significa "vazio" (e não "não existe").
	 */
	private function resources() {
		return array(
			'orders'            => array( 'label' => 'Pedidos',                 'path' => 'orders',            'perm' => 'Pedidos › Ler pedidos',     'sync' => true,  'core' => true ),
			'products'          => array( 'label' => 'Produtos',                'path' => 'products',          'perm' => 'Produtos › Ler produtos',   'sync' => true,  'core' => true ),
			'categories'        => array( 'label' => 'Categorias',              'path' => 'categories',        'perm' => 'Produtos › Ler produtos',   'sync' => true,  'core' => true ),
			'customers'         => array( 'label' => 'Clientes',                'path' => 'customers',         'perm' => 'Clientes › Ler clientes',   'sync' => true,  'core' => true ),
			'coupons'           => array( 'label' => 'Cupons',                  'path' => 'coupons',           'perm' => 'Cupons › Ler cupons',       'sync' => true,  'core' => true ),
			'draft_orders'      => array( 'label' => 'Pedidos manuais',         'path' => 'draft_orders',      'perm' => 'Pedidos manuais',           'sync' => false, 'core' => false ),
			'checkout'          => array( 'label' => 'Carrinhos abandonados',   'path' => 'checkout',          'perm' => 'Pedidos',                   'sync' => false, 'core' => false ),
			'pages'             => array( 'label' => 'Páginas',                 'path' => 'pages',             'perm' => 'Conteúdo',                  'sync' => false, 'core' => false ),
			'shipping_carriers' => array( 'label' => 'Transportadoras',         'path' => 'shipping_carriers', 'perm' => 'Envio',                     'sync' => false, 'core' => false ),
		);
	}

	private static function table_orders() {
		global $wpdb;
		return $wpdb->prefix . 'nuvemshop_pedidos';
	}

	private static function table_data() {
		global $wpdb;
		return $wpdb->prefix . 'nuvemshop_dados';
	}

	/* ==================================================================
	 * Instalação / atualização
	 * ================================================================ */

	public static function create_tables() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();
		$orders  = self::table_orders();
		$data    = self::table_data();

		// Pedidos: sem e-mail, telefone ou CPF (LGPD). Só ID do cliente e cidade/UF.
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
			cidade varchar(100) NOT NULL DEFAULT '',
			uf varchar(60) NOT NULL DEFAULT '',
			itens longtext NULL,
			pacotes_250 int(11) NOT NULL DEFAULT 0,
			pacotes_500 int(11) NOT NULL DEFAULT 0,
			kg decimal(10,3) NOT NULL DEFAULT 0,
			PRIMARY KEY  (order_id),
			KEY criado_em (criado_em),
			KEY status_pagamento (status_pagamento)
		) {$charset};"
		);

		// Demais recursos (produtos, categorias, clientes, cupons): um registro por item, em JSON.
		dbDelta(
			"CREATE TABLE {$data} (
			recurso varchar(30) NOT NULL,
			item_id bigint(20) unsigned NOT NULL,
			titulo varchar(255) NOT NULL DEFAULT '',
			dados longtext NULL,
			criado_em datetime NULL,
			atualizado_em datetime NULL,
			PRIMARY KEY  (recurso,item_id),
			KEY titulo (titulo(100))
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

	/** Cobre o caso de o plugin ser substituído por uma versão nova (a ativação não roda de novo). */
	public function maybe_upgrade() {
		if ( get_option( self::OPT_DBV ) !== self::DB_VERSION ) {
			self::create_tables();

			// Traz a data da última sincronização de pedidos da versão anterior.
			$antigo = get_option( 'pn_sync_state', array() );
			if ( ! empty( $antigo['ultima_atualizacao'] ) && ! get_option( self::OPT_STATE ) ) {
				update_option(
					self::OPT_STATE,
					array( 'orders' => array( 'ultima_atualizacao' => $antigo['ultima_atualizacao'], 'ultima_execucao' => $antigo['ultima_execucao'] ?? '', 'total' => 0, 'erro' => '' ) ),
					false
				);
			}
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

	public function maybe_redirect_after_activation() {
		if ( ! get_transient( 'pn_redirect_after_activation' ) ) {
			return;
		}
		delete_transient( 'pn_redirect_after_activation' );
		if ( wp_doing_ajax() || is_network_admin() || isset( $_GET['activate-multi'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		if ( current_user_can( 'manage_options' ) ) {
			wp_safe_redirect( $this->page_url() );
			exit;
		}
	}

	public function plugin_action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( $this->page_url() ) . '"><strong>Configurar</strong></a>' );
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
		echo '<div class="notice notice-warning"><p><strong>Pedidos Nuvemshop:</strong> falta informar o ID da loja e o token. ';
		echo '<a href="' . esc_url( $this->page_url() ) . '">Configurar agora</a></p></div>';
	}

	private function page_url( $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => 'pedidos-nuvemshop' ), $args ), admin_url( 'admin.php' ) );
	}

	/* ==================================================================
	 * Configuração
	 * ================================================================ */

	private function settings() {
		$defaults = array(
			'store_id'     => '8063546',
			'token'        => '',
			'contato'      => get_option( 'admin_email', '' ),
			'dias_inicial' => 90,
		);
		return wp_parse_args( get_option( self::OPT_SETTINGS, array() ), $defaults );
	}

	/** A constante no wp-config.php tem prioridade sobre o valor salvo no banco. */
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

	public function sanitize_settings( $input ) {
		$old = $this->settings();
		$new = array();

		$new['store_id']     = preg_replace( '/\D/', '', isset( $input['store_id'] ) ? $input['store_id'] : '' );
		$new['contato']      = sanitize_email( isset( $input['contato'] ) ? $input['contato'] : '' );
		$new['dias_inicial'] = max( 1, min( 730, (int) ( isset( $input['dias_inicial'] ) ? $input['dias_inicial'] : 90 ) ) );

		// Campo em branco = mantém o token já salvo (o token nunca é exibido de volta).
		$token        = isset( $input['token'] ) ? trim( (string) $input['token'] ) : '';
		$new['token'] = ( '' === $token ) ? $old['token'] : preg_replace( '/[^A-Za-z0-9_\-\.]/', '', $token );

		return $new;
	}

	/* ==================================================================
	 * Cliente da API
	 * ================================================================ */

	/** Acesso público e somente leitura à API para o módulo Brasil Gigante. */
	public function bg_api_get( $path, $query = array() ) {
		return $this->api_get( $path, $query );
	}

	/**
	 * GET na API. Retorna array( code, body, total ) ou WP_Error.
	 */
	private function api_get( $path, $query = array() ) {
		$store_id = $this->get_store_id();
		$token    = $this->get_token();
		if ( '' === $store_id || '' === $token ) {
			return new WP_Error( 'pn_config', 'Informe o ID da loja e o token.' );
		}

		$s   = $this->settings();
		$url = add_query_arg( $query, self::API_BASE . $store_id . '/' . ltrim( $path, '/' ) );

		$args = array(
			'timeout' => 30,
			'headers' => array(
				'Authentication' => 'bearer ' . $token,
				'User-Agent'     => 'Pedidos Nuvemshop WP (' . ( $s['contato'] ? $s['contato'] : get_option( 'admin_email' ) ) . ')',
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

		$body  = json_decode( wp_remote_retrieve_body( $resp ), true );
		$total = wp_remote_retrieve_header( $resp, 'x-total-count' );

		return array(
			'code'  => $code,
			'body'  => is_array( $body ) ? $body : null,
			'total' => ( '' !== $total && null !== $total ) ? (int) $total : null,
		);
	}

	/* ==================================================================
	 * Permissões do token
	 * ================================================================ */

	private function get_caps() {
		$c = get_option( self::OPT_CAPS, array() );
		return is_array( $c ) ? $c : array();
	}

	private function cap_status( $key ) {
		$c = $this->get_caps();
		return isset( $c['recursos'][ $key ]['status'] ) ? $c['recursos'][ $key ]['status'] : 'desconhecido';
	}

	private function can_read( $key ) {
		return in_array( $this->cap_status( $key ), array( 'ok', 'vazio' ), true );
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

	private function sample_label( $key, $item ) {
		if ( ! is_array( $item ) ) {
			return '';
		}
		if ( 'orders' === $key && isset( $item['number'] ) ) {
			return 'Pedido #' . (int) $item['number'];
		}
		if ( 'coupons' === $key && isset( $item['code'] ) ) {
			return (string) $item['code'];
		}
		if ( 'customers' === $key ) {
			return isset( $item['id'] ) ? 'Cliente #' . (int) $item['id'] : '';
		}
		foreach ( array( 'name', 'title', 'handle' ) as $k ) {
			if ( isset( $item[ $k ] ) ) {
				$t = $this->localized( $item[ $k ] );
				if ( '' !== $t ) {
					return wp_html_excerpt( $t, 60, '…' );
				}
			}
		}
		return isset( $item['id'] ) ? '#' . (int) $item['id'] : '';
	}

	/** Classifica a resposta de uma consulta de teste. */
	private function classify_probe( $key, $r ) {
		$def = $this->resources();
		$res = array( 'status' => 'erro', 'http' => 0, 'total' => null, 'amostra' => '', 'msg' => '' );

		if ( is_wp_error( $r ) ) {
			$res['msg'] = $r->get_error_message();
			return $res;
		}

		$res['http'] = $r['code'];

		if ( $r['code'] >= 200 && $r['code'] < 300 && is_array( $r['body'] ) ) {
			$res['status'] = 'ok';
			$res['total']  = $r['total'];
			if ( ! empty( $r['body'] ) ) {
				$primeiro        = reset( $r['body'] );
				$res['amostra']  = $this->sample_label( $key, $primeiro );
			} else {
				$res['status'] = 'vazio';
			}
			return $res;
		}
		if ( 401 === $r['code'] || 403 === $r['code'] ) {
			$res['status'] = 'negado';
			return $res;
		}
		if ( 404 === $r['code'] ) {
			// A loja já foi validada em /store, então 404 numa lista principal = lista vazia.
			$res['status'] = ( ! empty( $def[ $key ]['core'] ) ) ? 'vazio' : 'indisponivel';
			return $res;
		}
		$res['msg'] = 'HTTP ' . (int) $r['code'];
		return $res;
	}

	/**
	 * Testa a loja e cada recurso, e guarda o resultado. Retorna o array de permissões.
	 */
	private function probe_all() {
		$caps = array(
			'testado_em' => gmdate( 'c' ),
			'loja'       => '',
			'erro'       => '',
			'recursos'   => array(),
		);

		$loja = $this->api_get( 'store' );
		if ( is_wp_error( $loja ) ) {
			$caps['erro'] = $loja->get_error_message();
		} elseif ( 401 === $loja['code'] || 403 === $loja['code'] ) {
			$caps['erro'] = 'A Nuvemshop recusou o token (inválido, revogado ou de outra loja).';
		} elseif ( 404 === $loja['code'] ) {
			$caps['erro'] = 'Loja não encontrada. Confira o ID da loja.';
		} elseif ( $loja['code'] < 200 || $loja['code'] >= 300 || ! is_array( $loja['body'] ) ) {
			$caps['erro'] = 'Resposta inesperada da API (HTTP ' . (int) $loja['code'] . ').';
		} else {
			$caps['loja'] = $this->localized( isset( $loja['body']['name'] ) ? $loja['body']['name'] : '' );
		}

		if ( '' === $caps['erro'] ) {
			foreach ( $this->resources() as $key => $def ) {
				usleep( 550000 ); // respeita o limite de 2 requisições por segundo.
				$caps['recursos'][ $key ] = $this->classify_probe( $key, $this->api_get( $def['path'], array( 'per_page' => 1 ) ) );
			}
		}

		update_option( self::OPT_CAPS, $caps, false );
		return $caps;
	}

	private function caps_summary_lines( $caps ) {
		if ( ! empty( $caps['erro'] ) ) {
			return array( 'Falhou: ' . $caps['erro'] );
		}
		$defs   = $this->resources();
		$pode   = array();
		$nega   = array();
		$outros = array();
		foreach ( $caps['recursos'] as $key => $info ) {
			$rotulo = $defs[ $key ]['label'];
			if ( 'ok' === $info['status'] ) {
				$pode[] = $rotulo . ( null !== $info['total'] ? ' (' . (int) $info['total'] . ')' : '' );
			} elseif ( 'vazio' === $info['status'] ) {
				$pode[] = $rotulo . ' (nenhum registro ainda)';
			} elseif ( 'negado' === $info['status'] ) {
				$nega[] = $rotulo;
			} else {
				$outros[] = $rotulo;
			}
		}
		$linhas   = array();
		$linhas[] = 'Conexão OK com a loja "' . $caps['loja'] . '".';
		$linhas[] = 'Este token PODE ler: ' . ( $pode ? implode( ', ', $pode ) : 'nada ainda — marque permissões de leitura no app da Nuvemshop' ) . '.';
		if ( $nega ) {
			$linhas[] = 'Sem permissão: ' . implode( ', ', $nega ) . '.';
		}
		if ( $outros ) {
			$linhas[] = 'Não disponíveis/indeterminados: ' . implode( ', ', $outros ) . '.';
		}
		return $linhas;
	}

	/* ==================================================================
	 * Sincronização
	 * ================================================================ */

	private function get_state() {
		$s = get_option( self::OPT_STATE, array() );
		return is_array( $s ) ? $s : array();
	}

	public function cron_sync() {
		$this->sync( false );
	}

	/**
	 * Sincroniza todos os recursos que o token pode ler.
	 *
	 * @param bool $forcar_teste Se true, reconfere as permissões do token antes.
	 * @return array( 'ok' => bool, 'linhas' => string[] )
	 */
	public function sync( $forcar_teste = false ) {
		if ( '' === $this->get_token() || '' === $this->get_store_id() ) {
			return array( 'ok' => false, 'linhas' => array( 'Informe o ID da loja e o token.' ) );
		}
		if ( get_transient( 'pn_sync_lock' ) ) {
			return array( 'ok' => false, 'linhas' => array( 'Já existe uma sincronização em andamento. Tente de novo em alguns minutos.' ) );
		}
		set_transient( 'pn_sync_lock', 1, 10 * MINUTE_IN_SECONDS );

		$caps = $this->get_caps();
		$velho = empty( $caps['testado_em'] ) || ( time() - strtotime( $caps['testado_em'] ) ) > self::CAPS_TTL;
		if ( $forcar_teste || $velho ) {
			$caps = $this->probe_all();
		}

		if ( ! empty( $caps['erro'] ) ) {
			delete_transient( 'pn_sync_lock' );
			return array( 'ok' => false, 'linhas' => array( 'Falhou: ' . $caps['erro'] ) );
		}

		$linhas = array();
		$ok     = true;
		$state  = $this->get_state();

		foreach ( $this->resources() as $key => $def ) {
			if ( empty( $def['sync'] ) ) {
				continue;
			}
			if ( 'orders' !== $key && PN_BG::only_bg() ) {
				$linhas[] = $def['label'] . ': pulado (trava "somente Brasil Gigante" ligada).';
				continue;
			}
			if ( ! $this->can_read( $key ) ) {
				$linhas[] = $def['label'] . ': pulado (token sem permissão de leitura).';
				continue;
			}

			$res = $this->sync_resource( $key, isset( $state[ $key ] ) ? $state[ $key ] : array() );
			$state[ $key ] = $res['state'];

			if ( '' !== $res['erro'] ) {
				$ok       = false;
				$linhas[] = $def['label'] . ': ' . $res['erro'];
			} else {
				$linhas[] = $def['label'] . ': ' . (int) $res['total'] . ' registro(s) sincronizado(s).';
			}
		}

		update_option( self::OPT_STATE, $state, false );
		delete_transient( 'pn_sync_lock' );

		/**
		 * Disparado ao fim de cada sincronização (para integrações próprias).
		 *
		 * @param array $state Estado por recurso.
		 */
		do_action( 'pedidos_nuvemshop_synced', $state );

		return array( 'ok' => $ok, 'linhas' => $linhas );
	}

	private function sync_resource( $key, $state_antigo ) {
		$def = $this->resources();
		$def = $def[ $key ];
		$s   = $this->settings();

		if ( ! empty( $state_antigo['ultima_atualizacao'] ) ) {
			$desde = gmdate( 'c', strtotime( $state_antigo['ultima_atualizacao'] ) - HOUR_IN_SECONDS );
		} else {
			$desde = gmdate( 'c', time() - (int) $s['dias_inicial'] * DAY_IN_SECONDS );
		}
		// Produtos, categorias e cupons são poucos: na primeira vez, importa tudo (sem filtro de data).
		$primeira_vez_completa = empty( $state_antigo['ultima_atualizacao'] ) && 'orders' !== $key && 'customers' !== $key;

		$inicio = gmdate( 'c' );
		$total  = 0;
		$erro   = '';

		for ( $page = 1; $page <= self::MAX_PAGES; $page++ ) {
			$query = array( 'per_page' => self::PER_PAGE, 'page' => $page );
			if ( ! $primeira_vez_completa ) {
				$query['updated_at_min'] = $desde;
			}

			$r = $this->api_get( $def['path'], $query );

			if ( is_wp_error( $r ) ) {
				$erro = $r->get_error_message();
				break;
			}
			if ( 404 === $r['code'] ) {
				break; // lista vazia.
			}
			if ( 401 === $r['code'] || 403 === $r['code'] ) {
				$erro = 'acesso negado (permissão removida no app?). Clique em "Testar permissões".';
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
				if ( is_array( $item ) && ! empty( $item['id'] ) ) {
					if ( 'orders' === $key && PN_BG::only_bg() && ! PN_BG::order_has_bg( $item ) ) {
						continue; // trava: ignora pedidos sem o Brasil Gigante.
					}
					if ( 'orders' === $key ) {
						$this->upsert_order( $item );
					} else {
						$this->upsert_generic( $key, $item );
					}
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
	 * Gravação
	 * ---------------------------------------------------------------- */

	private function to_mysql_gmt( $iso ) {
		if ( empty( $iso ) ) {
			return null;
		}
		$ts = strtotime( $iso );
		return $ts ? gmdate( 'Y-m-d H:i:s', $ts ) : null;
	}

	private function mask_name( $nome ) {
		$partes = preg_split( '/\s+/', trim( (string) $nome ) );
		$out    = array();
		foreach ( (array) $partes as $p ) {
			if ( '' !== $p ) {
				$out[] = mb_substr( $p, 0, 1 ) . '***';
			}
		}
		return implode( ' ', $out );
	}

	private function parse_peso_gramas( $nome ) {
		if ( preg_match( '/(\d+(?:[\.,]\d+)?)\s*(kg|g)\b/i', (string) $nome, $m ) ) {
			$n = (float) str_replace( ',', '.', $m[1] );
			return ( 'kg' === strtolower( $m[2] ) ) ? $n * 1000 : $n;
		}
		return 0.0;
	}

	private function upsert_order( array $o ) {
		global $wpdb;

		$itens        = array();
		$p250         = 0;
		$p500         = 0;
		$gramas_total = 0.0;

		if ( ! empty( $o['products'] ) && is_array( $o['products'] ) ) {
			foreach ( $o['products'] as $item ) {
				if ( ! is_array( $item ) ) {
					continue;
				}
				$nome = $this->localized( isset( $item['name'] ) ? $item['name'] : '' );
				$qtd  = isset( $item['quantity'] ) ? (int) $item['quantity'] : 0;
				$g    = $this->parse_peso_gramas( $nome );
				if ( 0.0 === $g && isset( $item['weight'] ) ) {
					$g = (float) $item['weight'] * 1000;
				}
				if ( abs( $g - 250 ) < 1 ) {
					$p250 += $qtd;
				} elseif ( abs( $g - 500 ) < 1 ) {
					$p500 += $qtd;
				}
				$gramas_total += $g * $qtd;

				$itens[] = array(
					'nome'  => $nome,
					'qtd'   => $qtd,
					'preco' => isset( $item['price'] ) ? (float) $item['price'] : 0,
					'sku'   => isset( $item['sku'] ) ? (string) $item['sku'] : '',
				);
			}
		}

		$cliente_id = isset( $o['customer']['id'] ) ? (int) $o['customer']['id'] : 0;
		$cidade     = isset( $o['shipping_address']['city'] ) ? (string) $o['shipping_address']['city'] : '';
		$uf         = isset( $o['shipping_address']['province'] ) ? (string) $o['shipping_address']['province'] : '';

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
				'cliente_id'       => $cliente_id,
				'cidade'           => substr( sanitize_text_field( $cidade ), 0, 100 ),
				'uf'               => substr( sanitize_text_field( $uf ), 0, 60 ),
				'itens'            => wp_json_encode( $itens ),
				'pacotes_250'      => $p250,
				'pacotes_500'      => $p500,
				'kg'               => round( $gramas_total / 1000, 3 ),
			)
		);
	}

	private function upsert_generic( $key, array $item ) {
		global $wpdb;

		switch ( $key ) {
			case 'products':
				$titulo = $this->localized( isset( $item['name'] ) ? $item['name'] : '' );
				unset( $item['description'], $item['seo_description'] );
				if ( ! empty( $item['images'] ) && is_array( $item['images'] ) ) {
					$item['images'] = array_slice( $item['images'], 0, 3 );
				}
				break;

			case 'categories':
				$titulo = $this->localized( isset( $item['name'] ) ? $item['name'] : '' );
				unset( $item['description'] );
				break;

			case 'customers':
				// LGPD: não guarda e-mail, telefone, documento nem endereços completos.
				$cidade = '';
				$uf     = '';
				if ( ! empty( $item['default_address'] ) && is_array( $item['default_address'] ) ) {
					$cidade = isset( $item['default_address']['city'] ) ? (string) $item['default_address']['city'] : '';
					$uf     = isset( $item['default_address']['province'] ) ? (string) $item['default_address']['province'] : '';
				}
				$titulo = $this->mask_name( isset( $item['name'] ) ? $item['name'] : '' );
				$item   = array(
					'id'                   => (int) $item['id'],
					'nome_mascarado'       => $titulo,
					'total_spent'          => isset( $item['total_spent'] ) ? $item['total_spent'] : '',
					'total_spent_currency' => isset( $item['total_spent_currency'] ) ? $item['total_spent_currency'] : '',
					'last_order_id'        => isset( $item['last_order_id'] ) ? $item['last_order_id'] : '',
					'cidade'               => $cidade,
					'uf'                   => $uf,
					'created_at'           => isset( $item['created_at'] ) ? $item['created_at'] : '',
					'updated_at'           => isset( $item['updated_at'] ) ? $item['updated_at'] : '',
				);
				break;

			case 'coupons':
				$titulo = isset( $item['code'] ) ? (string) $item['code'] : '';
				break;

			default:
				$titulo = $this->sample_label( $key, $item );
		}

		$wpdb->replace(
			self::table_data(),
			array(
				'recurso'       => $key,
				'item_id'       => (int) $item['id'],
				'titulo'        => substr( sanitize_text_field( $titulo ), 0, 255 ),
				'dados'         => wp_json_encode( $item ),
				'criado_em'     => $this->to_mysql_gmt( isset( $item['created_at'] ) ? $item['created_at'] : '' ),
				'atualizado_em' => $this->to_mysql_gmt( isset( $item['updated_at'] ) ? $item['updated_at'] : '' ),
			)
		);
	}

	private function count_local( $key ) {
		global $wpdb;
		if ( 'orders' === $key ) {
			return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . self::table_orders() ); // phpcs:ignore WordPress.DB
		}
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::table_data() . ' WHERE recurso = %s', $key ) ); // phpcs:ignore WordPress.DB
	}

	/* ==================================================================
	 * Admin: ações
	 * ================================================================ */

	public function admin_menu() {
		add_menu_page( 'Pedidos Nuvemshop', 'Pedidos Nuvemshop', 'manage_options', 'pedidos-nuvemshop', array( $this, 'render_page' ), 'dashicons-cart', 56 );
	}

	private function flash( $tipo, $linhas ) {
		set_transient( 'pn_flash_' . get_current_user_id(), array( 'tipo' => $tipo, 'linhas' => (array) $linhas ), 5 * MINUTE_IN_SECONDS );
	}

	private function redirect_back( $tab = 'geral' ) {
		wp_safe_redirect( $this->page_url( array( 'tab' => $tab ) ) );
		exit;
	}

	public function handle_save() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Sem permissão.' );
		}
		check_admin_referer( 'pn_save' );

		$input = isset( $_POST[ self::OPT_SETTINGS ] ) ? wp_unslash( (array) $_POST[ self::OPT_SETTINGS ] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		update_option( self::OPT_SETTINGS, $this->sanitize_settings( $input ), false );

		$acao = isset( $_POST['pn_acao'] ) ? sanitize_key( wp_unslash( $_POST['pn_acao'] ) ) : 'salvar';
		if ( 'salvar_testar' === $acao ) {
			$caps = $this->probe_all();
			$this->flash( empty( $caps['erro'] ) ? 'success' : 'error', array_merge( array( 'Configuração salva.' ), $this->caps_summary_lines( $caps ) ) );
		} else {
			$this->flash( 'success', 'Configuração salva.' );
		}
		$this->redirect_back();
	}

	public function handle_probe() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Sem permissão.' );
		}
		check_admin_referer( 'pn_probe' );
		$caps = $this->probe_all();
		$this->flash( empty( $caps['erro'] ) ? 'success' : 'error', $this->caps_summary_lines( $caps ) );
		$this->redirect_back();
	}

	public function handle_sync_now() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Sem permissão.' );
		}
		check_admin_referer( 'pn_sync_now' );
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 300 ); // phpcs:ignore
		}
		$res = $this->sync( true ); // sempre reconfere as permissões do token antes de importar.
		$this->flash( $res['ok'] ? 'success' : 'warning', $res['linhas'] );
		$this->redirect_back();
	}

	/* ==================================================================
	 * Admin: telas
	 * ================================================================ */

	private function status_label( $status ) {
		$map = array(
			'ok'            => array( 'Pode ler', '#1a7f37' ),
			'vazio'         => array( 'Pode ler (vazio)', '#1a7f37' ),
			'negado'        => array( 'Sem permissão', '#b32d2e' ),
			'indisponivel'  => array( 'Não disponível', '#8a6d00' ),
			'erro'          => array( 'Erro no teste', '#b32d2e' ),
			'desconhecido'  => array( 'Ainda não testado', '#646970' ),
		);
		$i = isset( $map[ $status ] ) ? $map[ $status ] : $map['desconhecido'];
		return '<span style="display:inline-block;padding:2px 8px;border-radius:10px;background:' . esc_attr( $i[1] ) . ';color:#fff;font-size:12px">' . esc_html( $i[0] ) . '</span>';
	}

	private function pt_status( $tipo, $valor ) {
		$maps = array(
			'status'    => array( 'open' => 'Aberto', 'closed' => 'Fechado', 'cancelled' => 'Cancelado' ),
			'pagamento' => array( 'paid' => 'Pago', 'pending' => 'Pendente', 'authorized' => 'Autorizado', 'voided' => 'Anulado', 'refunded' => 'Reembolsado', 'abandoned' => 'Abandonado' ),
			'envio'     => array( 'unpacked' => 'Não embalado', 'unshipped' => 'Não enviado', 'shipped' => 'Enviado', 'delivered' => 'Entregue', 'ready_for_pickup' => 'Pronto p/ retirada' ),
		);
		return isset( $maps[ $tipo ][ $valor ] ) ? $maps[ $tipo ][ $valor ] : $valor;
	}

	private function brl( $v ) {
		return 'R$ ' . number_format_i18n( (float) $v, 2 );
	}

	private function tabs() {
		return array(
			'geral'      => 'Visão geral',
			'pedidos'    => 'Pedidos',
			'produtos'   => 'Produtos',
			'categorias' => 'Categorias',
			'clientes'   => 'Clientes',
			'cupons'     => 'Cupons',
			'outros'     => 'Outros dados',
			'brasilgigante' => 'Brasil Gigante',
		);
	}

	/** Mapa aba -> recurso exibido nela. */
	private function tab_resource( $tab ) {
		$m = array( 'pedidos' => 'orders', 'produtos' => 'products', 'categorias' => 'categories', 'clientes' => 'customers', 'cupons' => 'coupons' );
		return isset( $m[ $tab ] ) ? $m[ $tab ] : '';
	}

	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Sem permissão.' );
		}

		$tabs = $this->tabs();
		$tab  = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'geral'; // phpcs:ignore WordPress.Security.NonceVerification
		if ( ! isset( $tabs[ $tab ] ) ) {
			$tab = 'geral';
		}

		echo '<div class="wrap"><h1>Pedidos Nuvemshop</h1>';

		$flash_key = 'pn_flash_' . get_current_user_id();
		$flash     = get_transient( $flash_key );
		if ( $flash ) {
			delete_transient( $flash_key );
			echo '<div class="notice notice-' . esc_attr( $flash['tipo'] ) . ' is-dismissible">';
			foreach ( $flash['linhas'] as $l ) {
				echo '<p>' . esc_html( $l ) . '</p>';
			}
			echo '</div>';
		}

		echo '<nav class="nav-tab-wrapper" style="margin-bottom:15px">';
		foreach ( $tabs as $k => $rotulo ) {
			$cls = ( $k === $tab ) ? ' nav-tab-active' : '';
			echo '<a class="nav-tab' . esc_attr( $cls ) . '" href="' . esc_url( $this->page_url( array( 'tab' => $k ) ) ) . '">' . esc_html( $rotulo ) . '</a>';
		}
		echo '</nav>';

		if ( 'geral' === $tab ) {
			$this->render_general();
		} elseif ( 'pedidos' === $tab ) {
			$this->render_orders();
		} elseif ( 'outros' === $tab ) {
			$this->render_others();
		} elseif ( 'brasilgigante' === $tab ) {
			PN_BG::render( self::VERSION );
		} else {
			$this->render_generic_tab( $tab );
		}

		echo '<p class="description" style="margin-top:25px">Privacidade (LGPD): o plugin não guarda e-mail, telefone nem CPF. Clientes aparecem com nome mascarado. Estas telas são restritas a administradores.</p>';
		echo '</div>';
	}

	private function render_general() {
		$s       = $this->settings();
		$caps    = $this->get_caps();
		$state   = $this->get_state();
		$tem_tok = ( '' !== $this->get_token() );
		$tok_cst = ( defined( 'NUVEMSHOP_TOKEN' ) && NUVEMSHOP_TOKEN );
		$defs    = $this->resources();
		?>
		<h2>1. Conexão</h2>
		<p>
			Status:
			<?php if ( $tem_tok && '' !== $this->get_store_id() ) : ?>
				<strong style="color:#1a7f37">configurado</strong>
				<?php if ( ! empty( $caps['loja'] ) ) : ?>
					— loja <strong><?php echo esc_html( $caps['loja'] ); ?></strong>
				<?php endif; ?>
			<?php else : ?>
				<strong style="color:#b32d2e">falta o ID da loja e/ou o token</strong>
			<?php endif; ?>
		</p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="pn_save" />
			<?php wp_nonce_field( 'pn_save' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="pn_store_id">ID da loja</label></th>
					<td><input type="text" id="pn_store_id" name="<?php echo esc_attr( self::OPT_SETTINGS ); ?>[store_id]" value="<?php echo esc_attr( $s['store_id'] ); ?>" class="regular-text" /></td>
				</tr>
				<tr>
					<th scope="row"><label for="pn_token">Token de acesso</label></th>
					<td>
						<?php if ( $tok_cst ) : ?>
							<em>Definido no wp-config.php (NUVEMSHOP_TOKEN). É a forma mais segura.</em>
						<?php else : ?>
							<input type="password" id="pn_token" name="<?php echo esc_attr( self::OPT_SETTINGS ); ?>[token]" value="" class="regular-text" autocomplete="new-password" placeholder="<?php echo $tem_tok ? 'Token salvo (deixe em branco para manter)' : 'Cole o token aqui'; ?>" />
							<p class="description">Mais seguro: no <code>wp-config.php</code>, <code>define( 'NUVEMSHOP_TOKEN', 'seu_token' );</code>. O token nunca é exibido de volta.</p>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="pn_contato">E-mail de contato</label></th>
					<td>
						<input type="email" id="pn_contato" name="<?php echo esc_attr( self::OPT_SETTINGS ); ?>[contato]" value="<?php echo esc_attr( $s['contato'] ); ?>" class="regular-text" />
						<p class="description">A Nuvemshop pede a identificação do app no cabeçalho User-Agent.</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="pn_dias">Primeira importação de pedidos (dias)</label></th>
					<td>
						<input type="number" id="pn_dias" min="1" max="730" name="<?php echo esc_attr( self::OPT_SETTINGS ); ?>[dias_inicial]" value="<?php echo esc_attr( (int) $s['dias_inicial'] ); ?>" class="small-text" />
						<p class="description">Vale para pedidos e clientes. Produtos, categorias e cupons são importados por inteiro na primeira vez.</p>
					</td>
				</tr>
			</table>
			<p class="submit">
				<button type="submit" name="pn_acao" value="salvar_testar" class="button button-primary">Salvar e testar conexão</button>
				<button type="submit" name="pn_acao" value="salvar" class="button">Só salvar</button>
			</p>
		</form>

		<h2>2. O que este token pode ler</h2>
		<p>
			<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=pn_probe' ), 'pn_probe' ) ); ?>">Testar permissões agora</a>
			<?php if ( ! empty( $caps['testado_em'] ) ) : ?>
				<span class="description">Último teste: <?php echo esc_html( get_date_from_gmt( gmdate( 'Y-m-d H:i:s', strtotime( $caps['testado_em'] ) ), 'd/m/Y H:i' ) ); ?>. Mudou as permissões no app da Nuvemshop? Clique aqui (ou em "Sincronizar agora") para o plugin identificar de novo.</span>
			<?php else : ?>
				<span class="description">Ainda não testado.</span>
			<?php endif; ?>
		</p>
		<?php if ( ! empty( $caps['erro'] ) ) : ?>
			<div class="notice notice-error inline"><p><?php echo esc_html( $caps['erro'] ); ?></p></div>
		<?php endif; ?>

		<table class="widefat striped">
			<thead>
				<tr><th>Dado</th><th>Permissão a marcar no app</th><th>Situação</th><th>Exemplo / total na loja</th><th>Importados aqui</th><th>Última sincronização</th></tr>
			</thead>
			<tbody>
			<?php foreach ( $defs as $key => $def ) : ?>
				<?php
				$info   = isset( $caps['recursos'][ $key ] ) ? $caps['recursos'][ $key ] : array();
				$status = isset( $info['status'] ) ? $info['status'] : 'desconhecido';
				$est    = isset( $state[ $key ] ) ? $state[ $key ] : array();
				?>
				<tr>
					<td><strong><?php echo esc_html( $def['label'] ); ?></strong></td>
					<td><?php echo esc_html( $def['perm'] ); ?></td>
					<td><?php echo $this->status_label( $status ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
					<td>
						<?php
						$partes = array();
						if ( ! empty( $info['amostra'] ) ) {
							$partes[] = $info['amostra'];
						}
						if ( isset( $info['total'] ) && null !== $info['total'] ) {
							$partes[] = (int) $info['total'] . ' na loja';
						}
						echo esc_html( $partes ? implode( ' · ', $partes ) : '—' );
						?>
					</td>
					<td><?php echo ! empty( $def['sync'] ) ? (int) $this->count_local( $key ) : '<em>só leitura ao vivo</em>'; // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
					<td>
						<?php
						if ( ! empty( $est['ultima_execucao'] ) ) {
							echo esc_html( get_date_from_gmt( gmdate( 'Y-m-d H:i:s', strtotime( $est['ultima_execucao'] ) ), 'd/m/Y H:i' ) );
							if ( ! empty( $est['erro'] ) ) {
								echo ' <span style="color:#b32d2e">(' . esc_html( $est['erro'] ) . ')</span>';
							}
						} else {
							echo '—';
						}
						?>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<p class="description">As permissões são definidas no app da Nuvemshop (Aplicativos › Aplicativos sob medida › seu app › Perfis de acesso). O plugin só lê; nunca altera a loja.</p>

		<h2>3. Sincronização</h2>
		<p>
			Automática a cada 30 minutos (só dos dados que o token pode ler). As permissões são reconferidas a cada 6 horas.
		</p>
		<p>
			<a class="button button-primary" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=pn_sync_now' ), 'pn_sync_now' ) ); ?>">Sincronizar agora</a>
			<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=pn_export_csv' ), 'pn_export_csv' ) ); ?>">Baixar resumo semanal (CSV do painel 05)</a>
			<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=pn_export_products' ), 'pn_export_products' ) ); ?>">Baixar catálogo de produtos (CSV)</a>
		</p>
		<p class="description">O CSV semanal usa as colunas do <code>05_PAINEL_METRICAS.csv</code> e preenche só o que vem da loja (pedidos pagos e não cancelados). O catálogo traz nome, SKU, preço e estoque de cada variação.</p>
		<?php
	}

	private function render_not_readable( $key ) {
		$defs = $this->resources();
		$st   = $this->cap_status( $key );
		echo '<div class="notice notice-warning inline"><p>';
		if ( 'desconhecido' === $st ) {
			echo 'Ainda não testei o que este token pode ler. Vá em <a href="' . esc_url( $this->page_url() ) . '">Visão geral</a> e clique em <strong>Salvar e testar conexão</strong>.';
		} else {
			echo 'Este token <strong>não tem permissão</strong> para ler <strong>' . esc_html( $defs[ $key ]['label'] ) . '</strong>. No app da Nuvemshop, marque <strong>' . esc_html( $defs[ $key ]['perm'] ) . '</strong> e depois clique em "Testar permissões agora".';
		}
		echo '</p></div>';
	}

	private function render_pagination( $total, $por_pagina, $pagina, $args ) {
		$paginas = max( 1, (int) ceil( $total / $por_pagina ) );
		if ( $paginas < 2 ) {
			return;
		}
		echo '<div class="tablenav"><div class="tablenav-pages">';
		echo wp_kses_post(
			paginate_links(
				array(
					'base'      => add_query_arg( array_merge( $args, array( 'paged' => '%#%' ) ), $this->page_url() ),
					'format'    => '',
					'current'   => $pagina,
					'total'     => $paginas,
					'prev_text' => '‹',
					'next_text' => '›',
				)
			)
		);
		echo '</div></div>';
	}

	private function render_orders() {
		global $wpdb;
		if ( ! $this->can_read( 'orders' ) && 0 === $this->count_local( 'orders' ) ) {
			$this->render_not_readable( 'orders' );
			return;
		}
		if ( ! $this->can_read( 'orders' ) ) {
			$this->render_not_readable( 'orders' );
		}

		$t       = self::table_orders();
		$pagina  = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1; // phpcs:ignore WordPress.Security.NonceVerification
		$por     = 25;
		$total   = $this->count_local( 'orders' );
		$linhas  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} ORDER BY criado_em DESC LIMIT %d OFFSET %d", $por, ( $pagina - 1 ) * $por ) ); // phpcs:ignore WordPress.DB
		$soma    = (float) $wpdb->get_var( "SELECT COALESCE(SUM(total),0) FROM {$t} WHERE status_pagamento = 'paid' AND status <> 'cancelled'" ); // phpcs:ignore WordPress.DB
		$pagos   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t} WHERE status_pagamento = 'paid' AND status <> 'cancelled'" ); // phpcs:ignore WordPress.DB
		?>
		<p><strong><?php echo (int) $total; ?></strong> pedido(s) importado(s). Pagos e não cancelados: <strong><?php echo (int) $pagos; ?></strong>, somando <strong><?php echo esc_html( $this->brl( $soma ) ); ?></strong>.</p>
		<table class="widefat striped">
			<thead><tr><th>Nº</th><th>Data</th><th>Status</th><th>Pagamento</th><th>Envio</th><th>Total</th><th>Itens</th><th>Cliente</th><th>Destino</th></tr></thead>
			<tbody>
			<?php if ( empty( $linhas ) ) : ?>
				<tr><td colspan="9">Nenhum pedido importado ainda.</td></tr>
			<?php else : ?>
				<?php foreach ( $linhas as $r ) : ?>
					<?php
					$txt   = array();
					$lista = json_decode( (string) $r->itens, true );
					if ( is_array( $lista ) ) {
						foreach ( $lista as $it ) {
							$txt[] = ( isset( $it['qtd'] ) ? (int) $it['qtd'] : 0 ) . '× ' . ( isset( $it['nome'] ) ? $it['nome'] : '' );
						}
					}
					?>
					<tr>
						<td>#<?php echo (int) $r->numero; ?></td>
						<td><?php echo esc_html( get_date_from_gmt( $r->criado_em, 'd/m/Y H:i' ) ); ?></td>
						<td><?php echo esc_html( $this->pt_status( 'status', $r->status ) ); ?></td>
						<td><?php echo esc_html( $this->pt_status( 'pagamento', $r->status_pagamento ) ); ?></td>
						<td><?php echo esc_html( $this->pt_status( 'envio', $r->status_envio ) ); ?></td>
						<td><?php echo esc_html( $this->brl( $r->total ) ); ?></td>
						<td><?php echo esc_html( implode( ' | ', $txt ) ); ?></td>
						<td><?php echo $r->cliente_id ? 'Cliente #' . (int) $r->cliente_id : '—'; ?></td>
						<td><?php echo esc_html( trim( $r->cidade . ' ' . $r->uf ) ); ?></td>
					</tr>
				<?php endforeach; ?>
			<?php endif; ?>
			</tbody>
		</table>
		<?php
		$this->render_pagination( $total, $por, $pagina, array( 'tab' => 'pedidos' ) );
	}

	/** Produtos, categorias, clientes e cupons (tabela genérica). */
	private function render_generic_tab( $tab ) {
		global $wpdb;
		$key = $this->tab_resource( $tab );
		if ( '' === $key ) {
			return;
		}
		if ( ! $this->can_read( $key ) ) {
			$this->render_not_readable( $key );
			if ( 0 === $this->count_local( $key ) ) {
				return;
			}
		}

		$t      = self::table_data();
		$busca  = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$pagina = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1; // phpcs:ignore WordPress.Security.NonceVerification
		$por    = 25;
		$like   = '%' . $wpdb->esc_like( $busca ) . '%';

		$total  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE recurso = %s AND titulo LIKE %s", $key, $like ) ); // phpcs:ignore WordPress.DB
		$linhas = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE recurso = %s AND titulo LIKE %s ORDER BY titulo ASC LIMIT %d OFFSET %d", $key, $like, $por, ( $pagina - 1 ) * $por ) ); // phpcs:ignore WordPress.DB

		echo '<form method="get" style="margin-bottom:10px"><input type="hidden" name="page" value="pedidos-nuvemshop" /><input type="hidden" name="tab" value="' . esc_attr( $tab ) . '" />';
		echo '<input type="search" name="s" value="' . esc_attr( $busca ) . '" placeholder="Buscar pelo nome/código" /> <button class="button">Buscar</button></form>';
		echo '<p><strong>' . (int) $total . '</strong> registro(s).</p>';

		if ( 'products' === $key ) {
			$this->render_products_table( $linhas );
		} elseif ( 'categories' === $key ) {
			$this->render_categories_table( $linhas );
		} elseif ( 'customers' === $key ) {
			$this->render_customers_table( $linhas );
		} else {
			$this->render_coupons_table( $linhas );
		}

		$this->render_pagination( $total, $por, $pagina, array( 'tab' => $tab, 's' => $busca ) );
	}

	private function render_products_table( $linhas ) {
		echo '<table class="widefat striped"><thead><tr><th>Produto</th><th>SKU</th><th>Preço</th><th>Promocional</th><th>Estoque</th><th>Variações</th><th>Visível</th><th>Atualizado</th></tr></thead><tbody>';
		if ( empty( $linhas ) ) {
			echo '<tr><td colspan="8">Nenhum produto importado ainda. Clique em "Sincronizar agora" na Visão geral.</td></tr>';
		}
		foreach ( $linhas as $r ) {
			$p   = json_decode( (string) $r->dados, true );
			$p   = is_array( $p ) ? $p : array();
			$vs  = ( ! empty( $p['variants'] ) && is_array( $p['variants'] ) ) ? $p['variants'] : array();
			$precos = array();
			$promos = array();
			$skus   = array();
			$estoque = 0;
			$ilimitado = false;
			foreach ( $vs as $v ) {
				if ( isset( $v['price'] ) && '' !== $v['price'] ) {
					$precos[] = (float) $v['price'];
				}
				if ( isset( $v['promotional_price'] ) && '' !== $v['promotional_price'] && null !== $v['promotional_price'] ) {
					$promos[] = (float) $v['promotional_price'];
				}
				if ( ! empty( $v['sku'] ) ) {
					$skus[] = (string) $v['sku'];
				}
				if ( isset( $v['stock'] ) && null !== $v['stock'] ) {
					$estoque += (int) $v['stock'];
				} else {
					$ilimitado = true;
				}
			}
			$faixa = function ( $arr ) {
				if ( empty( $arr ) ) {
					return '—';
				}
				$min = min( $arr );
				$max = max( $arr );
				return $this->brl( $min ) . ( $max > $min ? ' a ' . $this->brl( $max ) : '' );
			};
			$visivel = ( isset( $p['published'] ) && $p['published'] ) ? 'Sim' : 'Não';
			echo '<tr>';
			echo '<td><strong>' . esc_html( $r->titulo ) . '</strong></td>';
			echo '<td>' . esc_html( $skus ? implode( ', ', array_slice( $skus, 0, 3 ) ) : '—' ) . '</td>';
			echo '<td>' . esc_html( $faixa( $precos ) ) . '</td>';
			echo '<td>' . esc_html( $faixa( $promos ) ) . '</td>';
			echo '<td>' . esc_html( $ilimitado && 0 === $estoque ? 'Sem controle' : (string) $estoque . ( $ilimitado ? ' (+ sem controle)' : '' ) ) . '</td>';
			echo '<td>' . (int) count( $vs ) . '</td>';
			echo '<td>' . esc_html( $visivel ) . '</td>';
			echo '<td>' . esc_html( $r->atualizado_em ? get_date_from_gmt( $r->atualizado_em, 'd/m/Y H:i' ) : '—' ) . '</td>';
			echo '</tr>';
		}
		echo '</tbody></table>';
	}

	private function render_categories_table( $linhas ) {
		echo '<table class="widefat striped"><thead><tr><th>ID</th><th>Categoria</th><th>Categoria-mãe (ID)</th><th>Subcategorias</th><th>Atualizado</th></tr></thead><tbody>';
		if ( empty( $linhas ) ) {
			echo '<tr><td colspan="5">Nenhuma categoria importada ainda.</td></tr>';
		}
		foreach ( $linhas as $r ) {
			$c = json_decode( (string) $r->dados, true );
			$c = is_array( $c ) ? $c : array();
			echo '<tr><td>' . (int) $r->item_id . '</td><td><strong>' . esc_html( $r->titulo ) . '</strong></td>';
			echo '<td>' . esc_html( ! empty( $c['parent'] ) ? (string) $c['parent'] : '—' ) . '</td>';
			echo '<td>' . esc_html( ! empty( $c['subcategories'] ) && is_array( $c['subcategories'] ) ? (string) count( $c['subcategories'] ) : '0' ) . '</td>';
			echo '<td>' . esc_html( $r->atualizado_em ? get_date_from_gmt( $r->atualizado_em, 'd/m/Y H:i' ) : '—' ) . '</td></tr>';
		}
		echo '</tbody></table>';
	}

	private function render_customers_table( $linhas ) {
		echo '<table class="widefat striped"><thead><tr><th>ID</th><th>Nome (mascarado)</th><th>Total gasto</th><th>Último pedido (ID)</th><th>Cidade/UF</th><th>Cliente desde</th></tr></thead><tbody>';
		if ( empty( $linhas ) ) {
			echo '<tr><td colspan="6">Nenhum cliente importado ainda.</td></tr>';
		}
		foreach ( $linhas as $r ) {
			$c = json_decode( (string) $r->dados, true );
			$c = is_array( $c ) ? $c : array();
			echo '<tr><td>' . (int) $r->item_id . '</td><td>' . esc_html( $r->titulo ) . '</td>';
			echo '<td>' . esc_html( isset( $c['total_spent'] ) && '' !== $c['total_spent'] ? $this->brl( $c['total_spent'] ) : '—' ) . '</td>';
			echo '<td>' . esc_html( ! empty( $c['last_order_id'] ) ? (string) $c['last_order_id'] : '—' ) . '</td>';
			echo '<td>' . esc_html( trim( ( isset( $c['cidade'] ) ? $c['cidade'] : '' ) . ' ' . ( isset( $c['uf'] ) ? $c['uf'] : '' ) ) ) . '</td>';
			echo '<td>' . esc_html( $r->criado_em ? get_date_from_gmt( $r->criado_em, 'd/m/Y' ) : '—' ) . '</td></tr>';
		}
		echo '</tbody></table>';
	}

	private function render_coupons_table( $linhas ) {
		echo '<table class="widefat striped"><thead><tr><th>Código</th><th>Tipo</th><th>Valor</th><th>Usos</th><th>Máx. de usos</th><th>Início</th><th>Fim</th><th>Válido</th></tr></thead><tbody>';
		if ( empty( $linhas ) ) {
			echo '<tr><td colspan="8">Nenhum cupom importado ainda.</td></tr>';
		}
		$tipos = array( 'percentage' => 'Percentual', 'absolute' => 'Valor fixo', 'shipping' => 'Frete' );
		foreach ( $linhas as $r ) {
			$c = json_decode( (string) $r->dados, true );
			$c = is_array( $c ) ? $c : array();
			$tipo  = isset( $c['type'] ) ? $c['type'] : '';
			$valor = isset( $c['value'] ) ? $c['value'] : '';
			if ( 'percentage' === $tipo && '' !== $valor ) {
				$valor = $valor . '%';
			} elseif ( 'absolute' === $tipo && '' !== $valor ) {
				$valor = $this->brl( $valor );
			}
			echo '<tr><td><strong>' . esc_html( $r->titulo ) . '</strong></td>';
			echo '<td>' . esc_html( isset( $tipos[ $tipo ] ) ? $tipos[ $tipo ] : $tipo ) . '</td>';
			echo '<td>' . esc_html( (string) $valor ) . '</td>';
			echo '<td>' . esc_html( isset( $c['used'] ) ? (string) $c['used'] : '—' ) . '</td>';
			echo '<td>' . esc_html( isset( $c['max_uses'] ) && null !== $c['max_uses'] ? (string) $c['max_uses'] : 'Ilimitado' ) . '</td>';
			echo '<td>' . esc_html( ! empty( $c['start_date'] ) ? (string) $c['start_date'] : '—' ) . '</td>';
			echo '<td>' . esc_html( ! empty( $c['end_date'] ) ? (string) $c['end_date'] : '—' ) . '</td>';
			echo '<td>' . esc_html( ! empty( $c['valid'] ) ? 'Sim' : 'Não' ) . '</td></tr>';
		}
		echo '</tbody></table>';
	}

	/** Dados que o token pode ler mas que não são importados: consulta ao vivo, sem gravar. */
	private function render_others() {
		$defs = $this->resources();
		$tem  = false;
		foreach ( $defs as $key => $def ) {
			if ( ! empty( $def['sync'] ) || ! $this->can_read( $key ) ) {
				continue;
			}
			$tem = true;
			echo '<h2>' . esc_html( $def['label'] ) . '</h2>';
			$r = $this->api_get( $def['path'], array( 'per_page' => 10 ) );
			if ( is_wp_error( $r ) ) {
				echo '<p>' . esc_html( $r->get_error_message() ) . '</p>';
				continue;
			}
			if ( 404 === $r['code'] || empty( $r['body'] ) || ! is_array( $r['body'] ) ) {
				echo '<p>Nenhum registro.</p>';
				continue;
			}
			$this->render_live_table( $r['body'] );
		}
		if ( ! $tem ) {
			echo '<div class="notice notice-info inline"><p>Nenhum dado adicional liberado para este token (pedidos manuais, carrinhos abandonados, páginas, transportadoras). Marque essas permissões no app da Nuvemshop e clique em "Testar permissões agora" na Visão geral.</p></div>';
		} else {
			echo '<p class="description">Consulta ao vivo (até 10 itens), sem gravar no WordPress. Campos pessoais (e-mail, telefone, documento, endereço) são ocultados.</p>';
		}
	}

	private function render_live_table( array $itens ) {
		$cols = array();
		foreach ( array_slice( $itens, 0, 10 ) as $it ) {
			if ( is_array( $it ) ) {
				foreach ( $it as $k => $v ) {
					if ( ! isset( $cols[ $k ] ) && ( is_scalar( $v ) || null === $v || ( is_array( $v ) && $this->is_lang_map( $v ) ) ) ) {
						$cols[ $k ] = true;
					}
				}
			}
		}
		$cols = array_slice( array_keys( $cols ), 0, 7 );

		echo '<table class="widefat striped"><thead><tr>';
		foreach ( $cols as $c ) {
			echo '<th>' . esc_html( $c ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( array_slice( $itens, 0, 10 ) as $it ) {
			echo '<tr>';
			foreach ( $cols as $c ) {
				$v = ( is_array( $it ) && isset( $it[ $c ] ) ) ? $it[ $c ] : '';
				if ( preg_match( '/mail|phone|telefone|ident|cpf|cnpj|address|endereco|token|secret|password|senha/i', (string) $c ) ) {
					$v = '(oculto)';
				}
				echo '<td>' . esc_html( wp_html_excerpt( is_array( $v ) ? $this->localized( $v ) : (string) $v, 80, '…' ) ) . '</td>';
			}
			echo '</tr>';
		}
		echo '</tbody></table>';
	}

	private function is_lang_map( $v ) {
		foreach ( $v as $x ) {
			if ( ! is_scalar( $x ) && null !== $x ) {
				return false;
			}
		}
		return true;
	}

	/* ==================================================================
	 * Exportações
	 * ================================================================ */

	private function csv_headers( $nome ) {
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $nome . '_' . gmdate( 'Y-m-d' ) . '.csv"' );
		$out = fopen( 'php://output', 'w' );
		fwrite( $out, "\xEF\xBB\xBF" ); // BOM para o Excel abrir os acentos corretamente.
		return $out;
	}

	/** Resumo semanal no formato do 05_PAINEL_METRICAS.csv. */
	public function handle_export_csv() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Sem permissão.' );
		}
		check_admin_referer( 'pn_export_csv' );

		global $wpdb;
		$t    = self::table_orders();
		$rows = $wpdb->get_results( "SELECT * FROM {$t} ORDER BY criado_em ASC" ); // phpcs:ignore WordPress.DB
		$tz   = wp_timezone();

		$semanas = array();
		foreach ( $rows as $r ) {
			if ( 'paid' !== $r->status_pagamento || 'cancelled' === $r->status ) {
				continue;
			}
			$dt = new DateTime( $r->criado_em, new DateTimeZone( 'UTC' ) );
			$dt->setTimezone( $tz );
			$dt->modify( 'monday this week' );
			$chave = $dt->format( 'Y-m-d' );
			if ( ! isset( $semanas[ $chave ] ) ) {
				$semanas[ $chave ] = array( 'pedidos' => 0, 'receita' => 0.0, 'p250' => 0, 'p500' => 0, 'kg' => 0.0 );
			}
			$semanas[ $chave ]['pedidos']++;
			$semanas[ $chave ]['receita'] += (float) $r->total;
			$semanas[ $chave ]['p250']    += (int) $r->pacotes_250;
			$semanas[ $chave ]['p500']    += (int) $r->pacotes_500;
			$semanas[ $chave ]['kg']      += (float) $r->kg;
		}
		ksort( $semanas );

		$colunas = array(
			'semana_inicio', 'sessoes_loja', 'pedidos_loja', 'conversao_loja_pct', 'ticket_medio_rs', 'carrinhos_abandonados',
			'pedidos_whatsapp', 'pedidos_b2b', 'clientes_novos', 'clientes_recorrentes', 'recompra_90d_pct', 'kg_vendidos',
			'pacotes_250g', 'pacotes_500g', 'kits_vendidos', 'estoque_kg', 'seguidores_instagram', 'alcance_instagram',
			'avaliacoes_novas', 'contatos_com_consentimento', 'gasto_marketing_rs', 'cac_rs', 'custos_rs', 'margem_bruta_pct',
			'receita_loja_rs', 'receita_whatsapp_rs', 'receita_b2b_rs', 'receita_rs', 'receita_fonte', 'observacoes',
		);

		$out = $this->csv_headers( 'painel_metricas_nuvemshop' );
		fputcsv( $out, $colunas );
		foreach ( $semanas as $inicio => $d ) {
			$l                    = array_fill_keys( $colunas, '' );
			$l['semana_inicio']   = $inicio;
			$l['pedidos_loja']    = $d['pedidos'];
			$l['ticket_medio_rs'] = $d['pedidos'] ? number_format( $d['receita'] / $d['pedidos'], 2, '.', '' ) : '';
			$l['kg_vendidos']     = number_format( $d['kg'], 2, '.', '' );
			$l['pacotes_250g']    = $d['p250'];
			$l['pacotes_500g']    = $d['p500'];
			$l['receita_loja_rs'] = number_format( $d['receita'], 2, '.', '' );
			$l['receita_fonte']   = 'Nuvemshop API (pedidos pagos, não cancelados)';
			$l['observacoes']     = 'kg e pacotes calculados pelo peso no nome do produto';
			fputcsv( $out, array_values( $l ) );
		}
		fclose( $out );
		exit;
	}

	/** Catálogo: uma linha por variação (nome, SKU, preço, estoque). */
	public function handle_export_products() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Sem permissão.' );
		}
		check_admin_referer( 'pn_export_products' );

		global $wpdb;
		$t    = self::table_data();
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE recurso = %s ORDER BY titulo ASC", 'products' ) ); // phpcs:ignore WordPress.DB

		$out = $this->csv_headers( 'catalogo_nuvemshop' );
		fputcsv( $out, array( 'produto_id', 'produto', 'variacao_id', 'variacao', 'sku', 'preco', 'preco_promocional', 'estoque', 'peso_kg', 'visivel' ) );
		foreach ( $rows as $r ) {
			$p = json_decode( (string) $r->dados, true );
			if ( ! is_array( $p ) ) {
				continue;
			}
			$vs = ( ! empty( $p['variants'] ) && is_array( $p['variants'] ) ) ? $p['variants'] : array( array() );
			foreach ( $vs as $v ) {
				$valores = array();
				if ( ! empty( $v['values'] ) && is_array( $v['values'] ) ) {
					foreach ( $v['values'] as $val ) {
						$valores[] = $this->localized( $val );
					}
				}
				fputcsv(
					$out,
					array(
						(int) $r->item_id,
						$r->titulo,
						isset( $v['id'] ) ? $v['id'] : '',
						implode( ' / ', $valores ),
						isset( $v['sku'] ) ? $v['sku'] : '',
						isset( $v['price'] ) ? $v['price'] : '',
						isset( $v['promotional_price'] ) ? $v['promotional_price'] : '',
						( isset( $v['stock'] ) && null !== $v['stock'] ) ? $v['stock'] : 'sem controle',
						isset( $v['weight'] ) ? $v['weight'] : '',
						! empty( $p['published'] ) ? 'sim' : 'não',
					)
				);
			}
		}
		fclose( $out );
		exit;
	}
}

register_activation_hook( __FILE__, array( 'PN_Pedidos_Nuvemshop', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'PN_Pedidos_Nuvemshop', 'deactivate' ) );
add_action( 'plugins_loaded', array( 'PN_Pedidos_Nuvemshop', 'instance' ), 1 );
