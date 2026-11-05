<?php
/**
 * Cliente mínimo da API do eGestor (v1): contato, venda e NF-e.
 *
 * Credencial: defina em wp-config.php (nunca no banco nem no chat):
 *     define( 'PN_EGESTOR_PERSONAL_TOKEN', '...' );
 *
 * ATENÇÃO: o formato do corpo do login (grant_type "personal") e os nomes dos campos de contato
 * além dos exemplos da documentação ainda NÃO foram validados com credenciais reais. Por isso o
 * módulo nasce desligado/simulado. Ajustes possíveis pelos filtros pn_egestor_token_body,
 * pn_egestor_contato_body e pn_egestor_venda_body.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class PN_EGestor {

	const BASE      = 'https://v4.egestor.com.br/api';
	const TOKEN_KEY = 'pn_egestor_access';

	public static function has_credentials() {
		return defined( 'PN_EGESTOR_PERSONAL_TOKEN' ) && PN_EGESTOR_PERSONAL_TOKEN;
	}

	/** access_token vale 15 minutos; guarda por 13. */
	private static function access_token() {
		$t = get_transient( self::TOKEN_KEY );
		if ( is_string( $t ) && '' !== $t ) {
			return $t;
		}
		if ( ! self::has_credentials() ) {
			return new WP_Error( 'pn_eg_cred', 'Credencial do eGestor não definida (PN_EGESTOR_PERSONAL_TOKEN no wp-config.php).' );
		}
		$body = apply_filters(
			'pn_egestor_token_body',
			array(
				'grant_type'     => 'personal',
				'personal_token' => (string) PN_EGESTOR_PERSONAL_TOKEN,
			)
		);
		$resp = wp_remote_post(
			self::BASE . '/oauth/access_token',
			array(
				'timeout' => 20,
				'headers' => array( 'Content-Type' => 'application/json', 'Accept' => 'application/json' ),
				'body'    => wp_json_encode( $body ),
			)
		);
		if ( is_wp_error( $resp ) ) {
			return $resp;
		}
		$code = (int) wp_remote_retrieve_response_code( $resp );
		$json = json_decode( wp_remote_retrieve_body( $resp ), true );
		if ( 200 !== $code || empty( $json['access_token'] ) ) {
			return new WP_Error( 'pn_eg_auth', 'Login no eGestor falhou (HTTP ' . $code . ').' );
		}
		set_transient( self::TOKEN_KEY, (string) $json['access_token'], 13 * MINUTE_IN_SECONDS );
		return (string) $json['access_token'];
	}

	/**
	 * @return array|WP_Error array( code, body )
	 */
	public static function request( $method, $path, $query = array(), $body = null ) {
		$token = self::access_token();
		if ( is_wp_error( $token ) ) {
			return $token;
		}
		$url  = add_query_arg( $query, self::BASE . '/' . ltrim( $path, '/' ) );
		$args = array(
			'method'  => $method,
			'timeout' => 30,
			'headers' => array(
				'Authorization' => 'Bearer ' . $token,
				'Accept'        => 'application/json',
				'Content-Type'  => 'application/json',
			),
		);
		if ( null !== $body ) {
			$args['body'] = wp_json_encode( $body );
		}
		$resp = wp_remote_request( $url, $args );
		if ( is_wp_error( $resp ) ) {
			return $resp;
		}
		$code = (int) wp_remote_retrieve_response_code( $resp );
		if ( 401 === $code ) {
			delete_transient( self::TOKEN_KEY );
		}
		$json = json_decode( wp_remote_retrieve_body( $resp ), true );
		return array( 'code' => $code, 'body' => is_array( $json ) ? $json : array() );
	}

	/** Mensagem curta de erro, sem dados pessoais. */
	public static function err( $r ) {
		if ( is_wp_error( $r ) ) {
			return $r->get_error_message();
		}
		$msg = '';
		foreach ( array( 'message', 'mensagem', 'error', 'erro' ) as $k ) {
			if ( ! empty( $r['body'][ $k ] ) && is_string( $r['body'][ $k ] ) ) {
				$msg = $r['body'][ $k ];
				break;
			}
		}
		return 'HTTP ' . (int) $r['code'] . ( '' !== $msg ? ': ' . mb_substr( $msg, 0, 200 ) : '' );
	}

	/** Procura o cliente pelo CPF. Retorna o código ou 0 se não achar. */
	public static function find_contact( $cpf ) {
		$r = self::request( 'GET', 'v1/contatos', array( 'tipo' => 'cliente', 'filtro' => $cpf ) );
		if ( is_wp_error( $r ) || 200 !== $r['code'] ) {
			return $r;
		}
		$lista = isset( $r['body']['data'] ) && is_array( $r['body']['data'] ) ? $r['body']['data'] : array();
		foreach ( $lista as $c ) {
			$doc = isset( $c['cpfcnpj'] ) ? preg_replace( '/\D/', '', (string) $c['cpfcnpj'] ) : '';
			if ( '' !== $doc && $doc === $cpf && ! empty( $c['codigo'] ) ) {
				return (int) $c['codigo'];
			}
		}
		return 0;
	}

	public static function contact_body( array $c ) {
		$body = array(
			'nome'       => $c['nome'],
			'tipo'       => array( 'cliente' ),
			'cpfcnpj'    => $c['cpf'],
			'emails'     => $c['email'] ? array( $c['email'] ) : array(),
			'fones'      => $c['fone'] ? array( $c['fone'] ) : array(),
			'cep'        => (int) $c['cep'],
			'logradouro' => $c['logradouro'],
			'numero'     => $c['numero'],
			'uf'         => $c['uf'],
		);
		return apply_filters( 'pn_egestor_contato_body', $body, $c );
	}

	public static function sale_body( array $v ) {
		$body = array(
			'codContato'  => (int) $v['cod_contato'],
			'dtVenda'     => $v['data'],
			'situacao'    => 50,
			'valorFrete'  => round( (float) $v['frete'], 2 ),
			'customizado' => array( 'xCampo1' => $v['observacao'] ),
			'produtos'    => array(
				array(
					'codProduto' => (int) $v['cod_produto'],
					'quant'      => (int) $v['quant'],
					'preco'      => round( (float) $v['preco'], 2 ),
					'vDesc'      => 0,
				),
			),
			'financeiros' => array(
				array(
					'dtVenc' => $v['data'],
					'valor'  => round( (float) $v['total'], 2 ),
				),
			),
		);
		if ( ! empty( $v['cod_transportador'] ) ) {
			$body['codTransportador'] = (int) $v['cod_transportador'];
		}
		return apply_filters( 'pn_egestor_venda_body', $body, $v );
	}
}
