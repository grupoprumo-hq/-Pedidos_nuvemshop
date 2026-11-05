<?php
/**
 * Porta de acesso do painel Brasil Gigante: tudo exige a senha do painel.
 *
 * A senha NÃO é criada nem alterada pela tela do plugin. Quem controla é o responsável pelo negócio:
 * ele gera o hash e o dev apenas cola no wp-config.php:
 *     define( 'PN_GATE_HASH', '$2y$...' );
 * Sem essa linha, o painel fica bloqueado para todos.
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
		}
	}

	private static function hash() {
		return ( defined( 'PN_GATE_HASH' ) && is_string( PN_GATE_HASH ) ) ? trim( PN_GATE_HASH ) : '';
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
			echo '<h2 style="margin-top:0">Painel bloqueado</h2><p>A senha deste painel ainda não foi configurada. Peça ao responsável pelo Brasil Gigante a linha <code>PN_GATE_HASH</code> e cole no <code>wp-config.php</code>. Até lá, ninguém acessa os dados.</p>';
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
