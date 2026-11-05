<?php
/**
 * Porta de acesso do painel Brasil Gigante: tudo exige a senha do painel.
 *
 * Senha: se existir a constante PN_GATE_HASH no wp-config.php, ela manda (modo mais seguro, não pode ser
 * alterada pelo painel). Sem a constante, a senha é criada no primeiro acesso, na própria tela do plugin,
 * e guardada como hash (nunca em texto puro). Alterar exige a senha atual.
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

	private static function valid_new( $a, $b ) {
		if ( strlen( $a ) < 8 ) {
			return 'A senha precisa ter pelo menos 8 caracteres.';
		}
		if ( $a !== $b ) {
			return 'As duas senhas não são iguais.';
		}
		return '';
	}

	/** Primeiro acesso: cria a senha (só se ainda não existir nenhuma). */
	public static function handle_create() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Sem permissão.' );
		}
		check_admin_referer( 'pn_gate_create' );
		if ( self::has_password() ) {
			self::back( 'A senha já existe.', 'error' );
		}
		$erro = self::valid_new( self::post( 'nova' ), self::post( 'nova2' ) );
		if ( $erro ) {
			self::back( $erro, 'error' );
		}
		update_option( 'pn_gate_hash', password_hash( self::post( 'nova' ), PASSWORD_DEFAULT ), false );
		set_transient( self::key(), 1, self::TTL );
		self::back( 'Senha criada. Guarde-a: ela não pode ser recuperada, só redefinida pelo wp-config.php.' );
	}

	/** Altera a senha: exige a senha atual e não funciona se a senha vier do wp-config. */
	public static function handle_change() {
		self::require_unlocked();
		check_admin_referer( 'pn_gate_change' );
		if ( self::from_config() ) {
			self::back( 'A senha está definida no wp-config.php e não pode ser alterada aqui.', 'error' );
		}
		if ( ! self::verify( self::post( 'atual' ) ) ) {
			self::back( 'Senha atual incorreta.', 'error' );
		}
		$erro = self::valid_new( self::post( 'nova' ), self::post( 'nova2' ) );
		if ( $erro ) {
			self::back( $erro, 'error' );
		}
		update_option( 'pn_gate_hash', password_hash( self::post( 'nova' ), PASSWORD_DEFAULT ), false );
		self::back( 'Senha alterada.' );
	}

	/** Formulário de troca de senha (usado dentro do painel liberado). */
	public static function render_change() {
		if ( self::from_config() ) {
			echo '<p>Senha definida no <code>wp-config.php</code> (modo protegido). Só quem tem acesso ao servidor altera.</p>';
			return;
		}
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="pn_gate_change">';
		wp_nonce_field( 'pn_gate_change' );
		echo '<p><input type="password" name="atual" placeholder="Senha atual" autocomplete="current-password" required> ';
		echo '<input type="password" name="nova" placeholder="Nova senha (mín. 8)" autocomplete="new-password" required minlength="8"> ';
		echo '<input type="password" name="nova2" placeholder="Repita a nova senha" autocomplete="new-password" required minlength="8"> ';
		submit_button( 'Alterar senha', 'secondary', 'submit', false );
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
			echo '<h2 style="margin-top:0">Crie a senha do painel</h2><p>Primeiro acesso: defina a senha do painel Brasil Gigante. Sem ela, ninguém vê os dados. Quem cria deve ser o responsável pelo negócio.</p>';
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="pn_gate_create">';
			wp_nonce_field( 'pn_gate_create' );
			echo '<p><input type="password" name="nova" class="regular-text" placeholder="Nova senha (mín. 8 caracteres)" autocomplete="new-password" required minlength="8" autofocus></p>';
			echo '<p><input type="password" name="nova2" class="regular-text" placeholder="Repita a senha" autocomplete="new-password" required minlength="8"></p>';
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
