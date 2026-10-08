<?php
/**
 * Cliente mínimo de la API de Mercado Pago (Preapproval / Authorized payments).
 *
 * @package MPWCR
 */

defined( 'ABSPATH' ) || exit;

class MPWCR_API {

	const BASE_URL = 'https://api.mercadopago.com';

	/**
	 * @var string
	 */
	private $access_token;

	/**
	 * @param string $access_token Access Token (producción o prueba).
	 */
	public function __construct( $access_token ) {
		$this->access_token = (string) $access_token;
	}

	/**
	 * Crea un contrato (preapproval).
	 *
	 * @param array $payload Cuerpo de la petición.
	 * @return array|WP_Error
	 */
	public function create_preapproval( array $payload ) {
		return $this->request( 'POST', '/preapproval', $payload );
	}

	/**
	 * @param string $id ID del preapproval.
	 * @return array|WP_Error
	 */
	public function get_preapproval( $id ) {
		return $this->request( 'GET', '/preapproval/' . rawurlencode( (string) $id ) );
	}

	/**
	 * Actualiza un contrato (p. ej. status: cancelled | paused | authorized).
	 *
	 * @param string $id   ID del preapproval.
	 * @param array  $data Datos a actualizar.
	 * @return array|WP_Error
	 */
	public function update_preapproval( $id, array $data ) {
		return $this->request( 'PUT', '/preapproval/' . rawurlencode( (string) $id ), $data );
	}

	/**
	 * @param string $id ID del pago autorizado (cobro de un ciclo).
	 * @return array|WP_Error
	 */
	public function get_authorized_payment( $id ) {
		return $this->request( 'GET', '/authorized_payments/' . rawurlencode( (string) $id ) );
	}

	/**
	 * Datos de la cuenta; sirve para validar el Access Token.
	 *
	 * @return array|WP_Error
	 */
	public function get_me() {
		return $this->request( 'GET', '/users/me' );
	}

	/**
	 * @param string     $method HTTP.
	 * @param string     $path   Ruta.
	 * @param array|null $body   Cuerpo JSON.
	 * @return array|WP_Error
	 */
	private function request( $method, $path, $body = null ) {
		if ( '' === $this->access_token ) {
			return new WP_Error( 'mpwcr_no_token', __( 'No hay un Access Token configurado.', 'mp-wc-pagos-recurrentes' ) );
		}

		$args = array(
			'timeout' => 30,
			'headers' => array(
				'Authorization' => 'Bearer ' . $this->access_token,
				'Content-Type'  => 'application/json',
				'Accept'        => 'application/json',
			),
		);

		if ( null !== $body ) {
			$args['body'] = wp_json_encode( $body );
		}

		$url = self::BASE_URL . $path;

		MPWCR_Logger::debug( "API {$method} {$path}", array( 'body' => $body ) );

		switch ( $method ) {
			case 'POST':
				$response = wp_remote_post( $url, $args );
				break;
			case 'GET':
				$response = wp_remote_get( $url, $args );
				break;
			default:
				$args['method'] = $method;
				$response       = wp_remote_request( $url, $args );
		}

		if ( is_wp_error( $response ) ) {
			MPWCR_Logger::error( "API {$method} {$path} falló", array( 'error' => $response->get_error_message() ) );
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		MPWCR_Logger::debug( "API {$method} {$path} respuesta {$code}", array( 'response' => $data ) );

		if ( $code < 200 || $code >= 300 ) {
			$message = is_array( $data ) && ! empty( $data['message'] ) ? $data['message'] : sprintf( 'HTTP %d', $code );
			MPWCR_Logger::error(
				"API {$method} {$path} devolvió error {$code}",
				array( 'response' => $data )
			);
			return new WP_Error(
				'mpwcr_api_error',
				$message,
				array(
					'status' => $code,
					'body'   => $data,
				)
			);
		}

		return is_array( $data ) ? $data : array();
	}
}
