<?php
/**
 * Porta de acesso do painel Brasil Gigante: tudo exige a senha do painel.
 *
 * - Primeiro acesso: quem abrir o painel cria a senha (mínimo 8 caracteres).
 * - Depois disso, o painel só abre com a senha. Sessão liberada por 30 minutos, por usuário.
 * - 5 erros seguidos bloqueiam novas tentativas por 15 minutos.
 * A senha é guardada só como hash (wp_hash_password). Não existe recuperação pelo painel.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class PN_Gate {

	const OPT_HASH = 'pn_gate_hash';
	const TTL      = 30 * MINUTE_IN_SECONDS;
	const MAX_FAIL = 5;
	const LOCK     = 15 * MINUTE_IN_SECONDS;

	public static function init() {
		if ( is_admin() ) {
			add_action( 'admin_post_pn_gate_set', array( __CLASS__, 'handle_set' ) );
			add_action( 'admin_post_pn_gate_unlock', array( __CLASS__, 'handle_unlock' ) );
			add_action( 'admin_post_pn_gate_lock', array( __CLASS__, 'handle_lock' ) );
			add_action( 'admin_post_pn_gate_change', array( __CLASS__, 'handle_change' ) );
		}
	}

	public static function has_password() {
		return '' !== (string) get_option( self::OPT_HASH, '' );
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

	public static function handle_set() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Sem permissão.' );
		}
		check_admin_referer( 'pn_gate_set' );
		if ( self::has_password() ) {
			self::back( 'A senha já foi criada.', 'error' );
		}
		$a = self::post( 'senha' );
		$b = self::post( 'senha2' );
		if ( strlen( $a ) < 8 ) {
			self::back( 'A senha precisa ter pelo menos 8 caracteres.', 'error' );
		}
		if ( $a !== $b ) {
			self::back( 'As duas senhas não são iguais.', 'error' );
		}
		update_option( self::OPT_HASH, wp_hash_password( $a ), false );
		set_transient( self::key(), 1, self::TTL );
		self::back( 'Senha criada. Guarde-a: não há recuperação pelo painel.' );
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
		if ( self::has_password() && wp_check_password( self::post( 'senha' ), (string) get_option( self::OPT_HASH, '' ) ) ) {
			delete_transient( $fkey );
			set_transient( self::key(), 1, self::TTL );
			self::back();
		}
		set_transient( $fkey, $n + 1, self::LOCK );
		self::back( 'Senha incorreta.', 'error' );
	}

	public static function handle_lock() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Sem permissão.' );
		}
		check_admin_referer( 'pn_gate_lock' );
		delete_transient( self::key() );
		self::back( 'Painel bloqueado.' );
	}

	public static function handle_change() {
		self::require_unlocked();
		check_admin_referer( 'pn_gate_change' );
		if ( ! wp_check_password( self::post( 'atual' ), (string) get_option( self::OPT_HASH, '' ) ) ) {
			self::back( 'A senha atual está incorreta.', 'error' );
		}
		$a = self::post( 'nova' );
		if ( strlen( $a ) < 8 || $a !== self::post( 'nova2' ) ) {
			self::back( 'A nova senha precisa ter 8 caracteres ou mais, igual nos dois campos.', 'error' );
		}
		update_option( self::OPT_HASH, wp_hash_password( $a ), false );
		self::back( 'Senha alterada.' );
	}

	/** Tela exibida no lugar do painel enquanto estiver bloqueado. */
	public static function render_lock() {
		echo '<div style="max-width:420px;background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:24px;margin-top:16px">';
		if ( ! self::has_password() ) {
			echo '<h2 style="margin-top:0">Crie a senha do painel</h2><p>O painel do Brasil Gigante só abre com senha. Quem criar agora é quem controla o acesso. Mínimo de 8 caracteres.</p>';
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="pn_gate_set">';
			wp_nonce_field( 'pn_gate_set' );
			echo '<p><input type="password" name="senha" class="regular-text" placeholder="Senha" autocomplete="new-password" required></p>';
			echo '<p><input type="password" name="senha2" class="regular-text" placeholder="Repita a senha" autocomplete="new-password" required></p>';
			submit_button( 'Criar senha', 'primary', 'submit', false );
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
