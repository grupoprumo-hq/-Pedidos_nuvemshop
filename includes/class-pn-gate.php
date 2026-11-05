<?php
/**
 * Porta de acesso do painel Brasil Gigante: tudo exige a senha do painel.
 *
 * A senha NUNCA é criada na tela do plugin: o responsável gera o código (hash) fora e ele é importado
 * (colado uma vez) no painel, ou definido em PN_GATE_HASH no wp-config.php (modo mais seguro).
 * Trocar exige a senha atual + novo código.
 * Sessão liberada por 30 minutos, por usuário. 5 erros seguidos bloqueiam por 15 minutos.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class PN_Gate {

	const TTL      = 30 * MINUTE_IN_SECONDS;
	const MAX_FAIL = 5;
	const LOCK     = 15 * MINUTE_IN_SECONDS;

	public static function init() {
		if ( is_admin() ) {
			add_action( 'admin_post_pn_gate_unlock', array( __CLASS__, 'handle_unlock' ) );
			add_action( 'admin_post_pn_gate_lock', array( __CLASS__, 'handle_lock' ) );
			add_action( 'admin_post_pn_gate_create', array( __CLASS__, 'handle_create' ) );
			add_action( 'admin_post_pn_gate_change', array( __CLASS__, 'handle_change' ) );
		}
	}

	public static function from_config() {
		return defined( 'PN_GATE_HASH' ) && is_string( PN_GATE_HASH ) && '' !== trim( PN_GATE_HASH );
	}

	private static function hash() {
		if ( self::from_config() ) {
			return trim( PN_GATE_HASH );
		}
		return (string) get_option( 'pn_gate_hash', '' );
	}

	public static function has_password() {
		return '' !== self::hash();
	}

	private static function verify( $senha ) {
		$h = self::hash();
		return '' !== $h && password_verify( (string) $senha, $h );
	}

	private static function key() {
		return 'pn_gate_ok_' . get_current_user_id();
	}

	public static function is_unlocked() {
		if ( ! current_user_can( 'manage_options' ) || ! self::has_password() ) {
			return false;
		}
		if ( get_transient( self::key() ) ) {
			set_transient( self::key(), 1, self::TTL ); // renova a cada uso.
			return true;
		}
		return false;
	}

	/** Usado por todas as ações do painel. */
	public static function require_unlocked() {
		if ( ! self::is_unlocked() ) {
			wp_die( 'Acesso bloqueado. Abra o painel Brasil Gigante e informe a senha.', 'Acesso bloqueado', array( 'response' => 403 ) );
		}
	}

	private static function back( $msg = '', $tipo = 'success' ) {
		if ( '' !== $msg ) {
			set_transient( 'pn_flash_' . get_current_user_id(), array( 'tipo' => $tipo, 'linhas' => array( $msg ) ), 5 * MINUTE_IN_SECONDS );
		}
		wp_safe_redirect( admin_url( 'admin.php?page=pedidos-nuvemshop' ) );
		exit;
	}

	private static function post( $k ) {
		return isset( $_POST[ $k ] ) ? (string) wp_unslash( $_POST[ $k ] ) : ''; // phpcs:ignore WordPress.Security
	}

	public static function handle_unlock() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Sem permissão.' );
		}
		check_admin_referer( 'pn_gate_unlock' );
		$fkey = 'pn_gate_fail_' . get_current_user_id();
		$n    = (int) get_transient( $fkey );
		if ( $n >= self::MAX_FAIL ) {
			self::back( 'Muitas tentativas. Aguarde 15 minutos.', 'error' );
		}
		if ( self::verify( self::post( 'senha' ) ) ) {
			delete_transient( $fkey );
			set_transient( self::key(), 1, self::TTL );
			self::back();
		}
		set_transient( $fkey, $n + 1, self::LOCK );
		self::back( 'Senha incorreta.', 'error' );
	}

	private static function valid_hash( $h ) {
		$h = trim( $h );
		$i = password_get_info( $h );
		return ( ! empty( $i['algo'] ) && 60 === strlen( $h ) ) ? $h : '';
	}

	/** Importa o hash da senha (gerado fora do plugin). Só funciona se ainda não houver senha. */
	public static function handle_create() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Sem permissão.' );
		}
		check_admin_referer( 'pn_gate_create' );
		if ( self::has_password() ) {
			self::back( 'A senha já foi importada.', 'error' );
		}
		$h = self::valid_hash( self::post( 'hash' ) );
		if ( '' === $h ) {
			self::back( 'Código inválido. Cole o código completo entregue pelo responsável.', 'error' );
		}
		update_option( 'pn_gate_hash', $h, false );
		self::back( 'Senha importada. Agora o painel só abre com a senha.' );
	}

	/** Troca a senha importada: exige a senha atual e o novo código. */
	public static function handle_change() {
		self::require_unlocked();
		check_admin_referer( 'pn_gate_change' );
		if ( self::from_config() ) {
			self::back( 'A senha está definida no wp-config.php e não pode ser alterada aqui.', 'error' );
		}
		if ( ! self::verify( self::post( 'atual' ) ) ) {
			self::back( 'Senha atual incorreta.', 'error' );
		}
		$h = self::valid_hash( self::post( 'hash' ) );
		if ( '' === $h ) {
			self::back( 'Código inválido.', 'error' );
		}
		update_option( 'pn_gate_hash', $h, false );
		self::back( 'Senha trocada.' );
	}

	/** Formulário de troca (painel liberado). */
	public static function render_change() {
		if ( self::from_config() ) {
			echo '<p>Senha definida no <code>wp-config.php</code>. Só quem tem acesso ao servidor altera.</p>';
			return;
		}
		echo '<p>Para trocar, informe a senha atual e cole o novo código entregue pelo responsável.</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="pn_gate_change">';
		wp_nonce_field( 'pn_gate_change' );
		echo '<p><input type="password" name="atual" placeholder="Senha atual" autocomplete="current-password" required> ';
		echo '<input type="text" name="hash" class="regular-text" placeholder="Novo código da senha" autocomplete="off" required> ';
		submit_button( 'Trocar senha', 'secondary', 'submit', false );
		echo '</p></form>';
	}

	public static function handle_lock() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Sem permissão.' );
		}
		check_admin_referer( 'pn_gate_lock' );
		delete_transient( self::key() );
		self::back( 'Painel bloqueado.' );
	}

	/** Tela exibida no lugar do painel enquanto estiver bloqueado. */
	public static function render_lock() {
		echo '<div style="max-width:440px;background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:24px;margin-top:16px">';
		if ( ! self::has_password() ) {
			echo '<h2 style="margin-top:0">Importar senha do painel</h2><p>A senha não é criada aqui. Cole o código da senha entregue pelo responsável do Brasil Gigante. Até importar, ninguém acessa os dados.</p>';
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="pn_gate_create">';
			wp_nonce_field( 'pn_gate_create' );
			echo '<p><input type="text" name="hash" class="large-text" placeholder="Código da senha (começa com $2y$)" autocomplete="off" required autofocus></p>';
			submit_button( 'Importar senha', 'primary', 'submit', false );
			echo '</form>';
		} else {
			echo '<h2 style="margin-top:0">Painel protegido</h2><p>Informe a senha do painel Brasil Gigante.</p>';
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="pn_gate_unlock">';
			wp_nonce_field( 'pn_gate_unlock' );
			echo '<p><input type="password" name="senha" class="regular-text" placeholder="Senha" autocomplete="current-password" required autofocus></p>';
			submit_button( 'Entrar', 'primary', 'submit', false );
			echo '</form>';
		}
		echo '</div>';
	}
}
