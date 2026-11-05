<?php
/**
 * Atualização automática do plugin a partir de um repositório do GitHub (Releases).
 *
 * Como funciona: o WordPress consulta a última "Release" do repositório. Se a versão da Release
 * (tag, ex.: v3.2.0) for maior que a instalada, o plugin aparece em Painel > Atualizações e em
 * Plugins com o botão "Atualizar agora" (e a opção de atualização automática).
 *
 * Configuração (uma vez): defina o repositório em wp-config.php:
 *     define( 'PN_UPDATE_REPO', 'dono/nome-do-repositorio' );
 * ou informe na aba "Brasil Gigante" do plugin. Repositório privado: defina também
 *     define( 'PN_UPDATE_TOKEN', 'token_somente_leitura' );
 *
 * Para publicar uma versão nova: suba o .zip do plugin como arquivo da Release (nome livre, termina
 * em .zip) e use como tag o número da versão. Sem arquivo .zip na Release, usa o código-fonte da tag.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class PN_Updater {

	const OPT_REPO  = 'pn_update_repo';
	const CACHE_KEY = 'pn_update_release';
	const CACHE_TTL = 1 * HOUR_IN_SECONDS;
	const DEFAULT_REPO = 'grupoprumo-hq/-Pedidos_nuvemshop';

	private $file;
	private $basename;
	private $slug;
	private $version;
	private $repo;
	private $token;

	public static function init( $file, $version ) {
		$u = new self( $file, $version );
		if ( '' !== $u->repo ) {
			$u->hooks();
		}
		add_action( 'admin_post_pn_check_update', array( $u, 'handle_check_now' ) );
		return $u;
	}

	private function __construct( $file, $version ) {
		$this->file     = $file;
		$this->basename = plugin_basename( $file );
		$this->slug     = dirname( $this->basename );
		$this->version  = $version;
		$this->repo     = self::get_repo();
		$this->token    = ( defined( 'PN_UPDATE_TOKEN' ) && PN_UPDATE_TOKEN ) ? (string) PN_UPDATE_TOKEN : '';
	}

	public static function get_repo() {
		if ( defined( 'PN_UPDATE_REPO' ) && PN_UPDATE_REPO ) {
			$r = (string) PN_UPDATE_REPO;
		} else {
			$r = (string) get_option( self::OPT_REPO, '' );
		}
		if ( '' === trim( $r ) ) {
			$r = self::DEFAULT_REPO; // Repositório oficial: a atualização funciona sem configurar nada.
		}
		$r = trim( $r );
		return preg_match( '#^[A-Za-z0-9_.\-]+/[A-Za-z0-9_.\-]+$#', $r ) ? $r : '';
	}

	private function hooks() {
		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'inject' ) );
		add_filter( 'plugins_api', array( $this, 'plugin_info' ), 20, 3 );
		add_filter( 'upgrader_pre_download', array( $this, 'pre_download' ), 10, 3 );
		add_filter( 'upgrader_source_selection', array( $this, 'fix_folder' ), 10, 4 );
	}

	private function headers( $accept = 'application/vnd.github+json' ) {
		$h = array(
			'Accept'     => $accept,
			'User-Agent' => 'Pedidos-Nuvemshop-WP',
		);
		if ( '' !== $this->token ) {
			$h['Authorization'] = 'Bearer ' . $this->token;
		}
		return $h;
	}

	/**
	 * Última release, com cache. Retorna array( version, package, url, notes, published ) ou null.
	 */
	public function latest( $force = false ) {
		if ( ! $force ) {
			$c = get_site_transient( self::CACHE_KEY );
			if ( is_array( $c ) ) {
				return empty( $c['version'] ) ? null : $c;
			}
		}

		$resp = wp_remote_get(
			'https://api.github.com/repos/' . $this->repo . '/releases/latest',
			array( 'timeout' => 15, 'headers' => $this->headers() )
		);
		if ( is_wp_error( $resp ) || 200 !== (int) wp_remote_retrieve_response_code( $resp ) ) {
			// Guarda o "vazio" por pouco tempo para não consultar a cada página.
			set_site_transient( self::CACHE_KEY, array( 'version' => '', 'erro' => is_wp_error( $resp ) ? $resp->get_error_message() : 'HTTP ' . (int) wp_remote_retrieve_response_code( $resp ) ), 15 * MINUTE_IN_SECONDS );
			return null;
		}
		$r = json_decode( wp_remote_retrieve_body( $resp ), true );
		if ( ! is_array( $r ) || empty( $r['tag_name'] ) ) {
			return null;
		}

		$package = '';
		if ( ! empty( $r['assets'] ) && is_array( $r['assets'] ) ) {
			foreach ( $r['assets'] as $a ) {
				if ( ! empty( $a['name'] ) && preg_match( '/\.zip$/i', $a['name'] ) ) {
					$package = ( '' !== $this->token && ! empty( $a['url'] ) ) ? $a['url'] : ( isset( $a['browser_download_url'] ) ? $a['browser_download_url'] : '' );
					break;
				}
			}
		}
		if ( '' === $package && ! empty( $r['zipball_url'] ) ) {
			$package = $r['zipball_url'];
		}

		$out = array(
			'version'   => ltrim( (string) $r['tag_name'], 'vV' ),
			'package'   => $package,
			'url'       => isset( $r['html_url'] ) ? $r['html_url'] : 'https://github.com/' . $this->repo,
			'notes'     => isset( $r['body'] ) ? (string) $r['body'] : '',
			'published' => isset( $r['published_at'] ) ? $r['published_at'] : '',
		);
		set_site_transient( self::CACHE_KEY, $out, self::CACHE_TTL );
		return $out;
	}

	public function inject( $transient ) {
		if ( ! is_object( $transient ) ) {
			return $transient;
		}
		$rel = $this->latest();
		if ( ! $rel ) {
			return $transient;
		}

		$item              = new stdClass();
		$item->id          = 'github.com/' . $this->repo;
		$item->slug        = $this->slug;
		$item->plugin      = $this->basename;
		$item->new_version = $rel['version'];
		$item->url         = $rel['url'];
		$item->package     = $rel['package'];

		if ( version_compare( $rel['version'], $this->version, '>' ) && '' !== $rel['package'] ) {
			$transient->response[ $this->basename ] = $item;
			unset( $transient->no_update[ $this->basename ] );
		} else {
			// Entrada em no_update faz o WordPress mostrar o interruptor de atualização automática.
			$item->new_version                      = $this->version;
			$transient->no_update[ $this->basename ] = $item;
		}
		return $transient;
	}

	public function plugin_info( $res, $action, $args ) {
		if ( 'plugin_information' !== $action || empty( $args->slug ) || $args->slug !== $this->slug ) {
			return $res;
		}
		$rel = $this->latest();
		if ( ! $rel ) {
			return $res;
		}
		$info                = new stdClass();
		$info->name          = 'Pedidos Nuvemshop';
		$info->slug          = $this->slug;
		$info->version       = $rel['version'];
		$info->author        = 'Café Zé Velho';
		$info->homepage      = $rel['url'];
		$info->download_link = $rel['package'];
		$info->last_updated  = $rel['published'];
		$info->sections      = array(
			'description' => 'Conecta o WordPress à API da Nuvemshop e acompanha os pedidos do Brasil Gigante.',
			'changelog'   => nl2br( esc_html( $rel['notes'] ) ),
		);
		return $info;
	}

	/** Repositório privado: baixa o arquivo pela API com o token e segue o redirecionamento sem o token. */
	public function pre_download( $reply, $package, $upgrader ) {
		if ( '' === $this->token || 0 !== strpos( (string) $package, 'https://api.github.com/repos/' . $this->repo . '/' ) ) {
			return $reply;
		}
		$resp = wp_remote_get(
			$package,
			array( 'timeout' => 30, 'redirection' => 0, 'headers' => $this->headers( 'application/octet-stream' ) )
		);
		if ( is_wp_error( $resp ) ) {
			return $resp;
		}
		$code = (int) wp_remote_retrieve_response_code( $resp );
		$loc  = wp_remote_retrieve_header( $resp, 'location' );
		if ( in_array( $code, array( 301, 302, 307 ), true ) && $loc ) {
			if ( ! function_exists( 'download_url' ) ) {
				require_once ABSPATH . 'wp-admin/includes/file.php';
			}
			return download_url( $loc );
		}
		return new WP_Error( 'pn_update_download', 'Não foi possível baixar a atualização (HTTP ' . $code . ').' );
	}

	/** O zip do código-fonte do GitHub vem numa pasta com outro nome; devolve para o nome certo. */
	public function fix_folder( $source, $remote_source, $upgrader, $hook_extra ) {
		global $wp_filesystem;
		if ( empty( $hook_extra['plugin'] ) || $hook_extra['plugin'] !== $this->basename || ! $wp_filesystem ) {
			return $source;
		}
		$wanted = trailingslashit( $remote_source ) . $this->slug . '/';
		if ( untrailingslashit( $source ) === untrailingslashit( $wanted ) ) {
			return $source;
		}
		if ( $wp_filesystem->move( $source, $wanted, true ) ) {
			return $wanted;
		}
		return new WP_Error( 'pn_update_folder', 'Não foi possível preparar a pasta da atualização.' );
	}

	public function handle_check_now() {
		if ( ! current_user_can( 'update_plugins' ) ) {
			wp_die( 'Sem permissão.' );
		}
		PN_Gate::require_unlocked();
		check_admin_referer( 'pn_check_update' );
		delete_site_transient( self::CACHE_KEY );
		delete_site_transient( 'update_plugins' );
		wp_update_plugins();
		wp_safe_redirect( admin_url( 'admin.php?page=pedidos-nuvemshop' ) );
		exit;
	}

	/** Dados para a tela. */
	public static function status() {
		$repo = self::get_repo();
		$c    = get_site_transient( self::CACHE_KEY );
		return array(
			'repo'    => $repo,
			'latest'  => ( is_array( $c ) && ! empty( $c['version'] ) ) ? $c['version'] : '',
			'erro'    => ( is_array( $c ) && ! empty( $c['erro'] ) ) ? $c['erro'] : '',
			'private' => ( defined( 'PN_UPDATE_TOKEN' ) && PN_UPDATE_TOKEN ),
		);
	}
}
