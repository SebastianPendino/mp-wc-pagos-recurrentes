<?php
/**
 * Receptor de Webhooks / IPN de Mercado Pago.
 *
 * El plugin no cobra por cron: Mercado Pago debita automáticamente y esta clase
 * reacciona a las notificaciones para crear/pagar las órdenes de renovación.
 *
 * @package MPWCR
 */

defined( 'ABSPATH' ) || exit;

class MPWCR_Webhook {

	const OPTION_LAST = 'mpwcr_last_webhook';

	/**
	 * Verdadero mientras se procesa un cambio originado en Mercado Pago, para no
	 * devolver el mismo cambio de estado a la API (evita bucles).
	 *
	 * @var bool
	 */
	public static $is_processing = false;

	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * @return string
	 */
	public static function get_url() {
		return rest_url( 'mpwcr/v1/webhook' );
	}

	public function register_routes() {
		register_rest_route(
			'mpwcr/v1',
			'/webhook',
			array(
				'methods'             => array( 'GET', 'POST' ),
				'callback'            => array( $this, 'handle' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/* ---------------------------------------------------------------------
	 * Recepción
	 * ------------------------------------------------------------------ */

	/**
	 * @param WP_REST_Request $request Petición.
	 * @return WP_REST_Response
	 */
	public function handle( WP_REST_Request $request ) {
		$body  = $request->get_json_params();
		$body  = is_array( $body ) ? $body : array();
		$topic = '';
		$id    = '';

		// Formato Webhook: {"type": "...", "data": {"id": "..."}}.
		if ( ! empty( $body['type'] ) ) {
			$topic = (string) $body['type'];
		} elseif ( $request->get_param( 'type' ) ) {
			$topic = (string) $request->get_param( 'type' );
		} elseif ( $request->get_param( 'topic' ) ) { // Formato IPN.
			$topic = (string) $request->get_param( 'topic' );
		}

		if ( ! empty( $body['data']['id'] ) ) {
			$id = (string) $body['data']['id'];
		} elseif ( $request->get_param( 'data_id' ) ) { // PHP convierte "data.id" en "data_id".
			$id = (string) $request->get_param( 'data_id' );
		} elseif ( $request->get_param( 'data.id' ) ) {
			$id = (string) $request->get_param( 'data.id' );
		} elseif ( $request->get_param( 'id' ) ) {
			$id = (string) $request->get_param( 'id' );
		}

		$topic = sanitize_key( $topic );
		$id    = preg_replace( '/[^A-Za-z0-9_\-]/', '', $id );

		if ( '' === $topic || '' === $id ) {
			return new WP_REST_Response( array( 'status' => 'ok' ), 200 );
		}

		MPWCR_Logger::info( 'Webhook recibido', array( 'topic' => $topic, 'id' => $id ) );

		$gateway = MPWCR_Plugin::gateway();
		if ( ! $gateway ) {
			MPWCR_Logger::error( 'Webhook recibido pero la pasarela no está disponible' );
			return new WP_REST_Response( array( 'status' => 'error' ), 500 );
		}

		$secret = $gateway->get_webhook_secret();
		if ( '' !== $secret && ! $this->verify_signature( $request, $secret ) ) {
			MPWCR_Logger::error( 'Firma de Webhook inválida', array( 'topic' => $topic, 'id' => $id ) );
			$this->record( $topic, $id, 'rechazado', __( 'firma inválida', 'mp-wc-pagos-recurrentes' ) );
			return new WP_REST_Response( array( 'status' => 'invalid_signature' ), 401 );
		}

		switch ( $topic ) {
			case 'subscription_preapproval':
			case 'preapproval':
				$result = $this->sync_preapproval( $id );
				break;

			case 'subscription_authorized_payment':
			case 'authorized_payment':
				$result = $this->process_authorized_payment( $id );
				break;

			default:
				MPWCR_Logger::debug( 'Webhook ignorado (tema no gestionado)', array( 'topic' => $topic ) );
				$this->record( $topic, $id, 'ignorado' );
				return new WP_REST_Response( array( 'status' => 'ignored' ), 200 );
		}

		if ( is_wp_error( $result ) ) {
			$data   = $result->get_error_data();
			$status = is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 500;

			// Recurso inexistente (p. ej. la simulación de Mercado Pago): se confirma para no reintentar.
			if ( 404 === $status ) {
				$this->record( $topic, $id, 'ignorado', __( 'recurso no encontrado', 'mp-wc-pagos-recurrentes' ) );
				return new WP_REST_Response( array( 'status' => 'not_found' ), 200 );
			}

			$this->record( $topic, $id, 'error', $result->get_error_message() );
			// Un código distinto de 2xx hace que Mercado Pago reintente.
			return new WP_REST_Response( array( 'status' => 'error' ), 500 );
		}

		$this->record( $topic, $id, 'ok', is_string( $result ) ? $result : '' );
		return new WP_REST_Response( array( 'status' => 'ok' ), 200 );
	}

	/**
	 * Valida la cabecera x-signature (HMAC SHA-256).
	 *
	 * @param WP_REST_Request $request Petición.
	 * @param string          $secret  Clave secreta.
	 * @return bool
	 */
	private function verify_signature( WP_REST_Request $request, $secret ) {
		$header     = (string) $request->get_header( 'x-signature' );
		$request_id = (string) $request->get_header( 'x-request-id' );
		if ( '' === $header ) {
			return false;
		}

		$ts = '';
		$v1 = '';
		foreach ( explode( ',', $header ) as $part ) {
			$pair = explode( '=', trim( $part ), 2 );
			if ( 2 !== count( $pair ) ) {
				continue;
			}
			if ( 'ts' === $pair[0] ) {
				$ts = $pair[1];
			} elseif ( 'v1' === $pair[0] ) {
				$v1 = $pair[1];
			}
		}
		if ( '' === $ts || '' === $v1 ) {
			return false;
		}

		$data_id = $request->get_param( 'data_id' );
		if ( null === $data_id ) {
			$data_id = $request->get_param( 'data.id' );
		}
		if ( null === $data_id ) {
			$body    = $request->get_json_params();
			$data_id = is_array( $body ) && isset( $body['data']['id'] ) ? $body['data']['id'] : '';
		}
		$data_id = (string) $data_id;
		if ( ctype_alnum( $data_id ) ) {
			$data_id = strtolower( $data_id );
		}

		$manifest = sprintf( 'id:%s;request-id:%s;ts:%s;', $data_id, $request_id, $ts );
		$expected = hash_hmac( 'sha256', $manifest, $secret );

		return hash_equals( $expected, $v1 );
	}

	/**
	 * Guarda la última notificación para mostrarla en el panel.
	 */
	private function record( $topic, $id, $result, $message = '' ) {
		update_option(
			self::OPTION_LAST,
			array(
				'time'    => time(),
				'topic'   => $topic,
				'id'      => $id,
				'result'  => $result,
				'message' => $message,
			),
			false
		);
	}

	/* ---------------------------------------------------------------------
	 * Bloqueo para evitar procesamiento concurrente del mismo recurso
	 * ------------------------------------------------------------------ */

	private function lock( $key ) {
		$name = 'mpwcr_lock_' . md5( $key );
		$now  = time();
		if ( add_option( $name, $now, '', 'no' ) ) {
			return $name;
		}
		if ( $now - (int) get_option( $name ) > 60 ) { // Bloqueo huérfano.
			update_option( $name, $now, false );
			return $name;
		}
		return false;
	}

	private function unlock( $name ) {
		delete_option( $name );
	}

	/**
	 * @param string   $key      Clave del recurso.
	 * @param callable $callback Acción a ejecutar.
	 * @return mixed|WP_Error
	 */
	private function with_lock( $key, $callback ) {
		$lock = $this->lock( $key );
		if ( ! $lock ) {
			return new WP_Error( 'mpwcr_locked', __( 'El recurso ya se está procesando.', 'mp-wc-pagos-recurrentes' ), array( 'status' => 409 ) );
		}

		$previous            = self::$is_processing;
		self::$is_processing = true;
		try {
			return call_user_func( $callback );
		} finally {
			self::$is_processing = $previous;
			$this->unlock( $lock );
		}
	}

	/* ---------------------------------------------------------------------
	 * Localización de suscripciones / pedidos
	 * ------------------------------------------------------------------ */

	/**
	 * @param array $preapproval Datos del preapproval devueltos por la API.
	 * @return WC_Subscription|null
	 */
	private function find_subscription( array $preapproval ) {
		$pid = isset( $preapproval['id'] ) ? (string) $preapproval['id'] : '';
		if ( '' === $pid ) {
			return null;
		}

		$ref = isset( $preapproval['external_reference'] ) ? (string) $preapproval['external_reference'] : '';
		if ( '' !== $ref && ctype_digit( $ref ) ) {
			$subscription = wcs_get_subscription( (int) $ref );
			if ( $subscription && (string) $subscription->get_meta( '_mpwcr_preapproval_id' ) === $pid ) {
				return $subscription;
			}
		}

		$ids = wc_get_orders(
			array(
				'type'       => 'shop_subscription',
				'limit'      => 1,
				'return'     => 'ids',
				'status'     => 'any',
				'meta_query' => array(
					array(
						'key'   => '_mpwcr_preapproval_id',
						'value' => $pid,
					),
				),
			)
		);

		if ( ! empty( $ids ) ) {
			$subscription = wcs_get_subscription( (int) $ids[0] );
			return $subscription ? $subscription : null;
		}

		return null;
	}

	/**
	 * @param string $authorized_payment_id ID del cobro autorizado.
	 * @return WC_Order|null
	 */
	private function find_order_by_authorized_payment( $authorized_payment_id ) {
		$orders = wc_get_orders(
			array(
				'limit'      => 1,
				'status'     => 'any',
				'meta_query' => array(
					array(
						'key'   => '_mpwcr_authorized_payment_id',
						'value' => (string) $authorized_payment_id,
					),
				),
			)
		);
		return ! empty( $orders ) ? $orders[0] : null;
	}

	/* ---------------------------------------------------------------------
	 * Preapproval (estado del contrato)
	 * ------------------------------------------------------------------ */

	/**
	 * Sincroniza el estado del contrato de Mercado Pago con la suscripción.
	 *
	 * @param string $preapproval_id ID del contrato.
	 * @return string|WP_Error Resumen o error.
	 */
	public function sync_preapproval( $preapproval_id ) {
		$gateway = MPWCR_Plugin::gateway();
		if ( ! $gateway ) {
			return new WP_Error( 'mpwcr_no_gateway', 'Pasarela no disponible' );
		}

		return $this->with_lock(
			'preapproval_' . $preapproval_id,
			function () use ( $gateway, $preapproval_id ) {
				$preapproval = $gateway->get_api()->get_preapproval( $preapproval_id );
				if ( is_wp_error( $preapproval ) ) {
					return $preapproval;
				}

				$subscription = $this->find_subscription( $preapproval );
				if ( ! $subscription ) {
					MPWCR_Logger::warning( 'Preapproval sin suscripción asociada', array( 'preapproval_id' => $preapproval_id ) );
					return 'sin suscripción asociada';
				}

				$status   = isset( $preapproval['status'] ) ? (string) $preapproval['status'] : '';
				$previous = (string) $subscription->get_meta( '_mpwcr_status' );

				$subscription->update_meta_data( '_mpwcr_status', $status );
				$subscription->save();

				MPWCR_Logger::info(
					'Estado del contrato',
					array(
						'subscription_id' => $subscription->get_id(),
						'previous'        => $previous,
						'status'          => $status,
					)
				);

				switch ( $status ) {
					case 'authorized':
						$this->complete_free_parent_order( $subscription, $preapproval_id );

						if ( 'paused' === $previous && $subscription->has_status( 'on-hold' ) ) {
							$subscription->update_status( 'active', __( 'Mercado Pago: contrato reactivado.', 'mp-wc-pagos-recurrentes' ) );
						}
						break;

					case 'paused':
						if ( $subscription->has_status( 'active' ) ) {
							$subscription->update_status( 'on-hold', __( 'Mercado Pago: contrato pausado.', 'mp-wc-pagos-recurrentes' ) );
						}
						break;

					case 'cancelled':
						if ( ! $subscription->has_status( array( 'cancelled', 'expired', 'pending-cancel' ) ) ) {
							$subscription->update_status( 'cancelled', __( 'Mercado Pago: contrato cancelado.', 'mp-wc-pagos-recurrentes' ) );
						}
						break;
				}

				return $status;
			}
		);
	}

	/**
	 * Con periodo de prueba el pedido inicial vale 0: se completa al autorizarse el contrato.
	 *
	 * @param WC_Subscription $subscription   Suscripción.
	 * @param string          $preapproval_id ID del contrato.
	 */
	private function complete_free_parent_order( $subscription, $preapproval_id ) {
		$parent = $subscription->get_parent();
		if ( ! $parent ) {
			return;
		}
		if ( (float) $parent->get_total() > 0 ) {
			return; // Se completará con el primer cobro autorizado.
		}
		if ( ! $parent->has_status( array( 'pending', 'on-hold', 'failed', 'cancelled' ) ) ) {
			return;
		}

		$parent->add_order_note( __( 'Mercado Pago: contrato autorizado (periodo de prueba).', 'mp-wc-pagos-recurrentes' ) );
		$parent->payment_complete( (string) $preapproval_id );
	}

	/* ---------------------------------------------------------------------
	 * Cobros de cada ciclo
	 * ------------------------------------------------------------------ */

	/**
	 * @param string $authorized_payment_id ID del cobro autorizado.
	 * @return string|WP_Error
	 */
	public function process_authorized_payment( $authorized_payment_id ) {
		$gateway = MPWCR_Plugin::gateway();
		if ( ! $gateway ) {
			return new WP_Error( 'mpwcr_no_gateway', 'Pasarela no disponible' );
		}

		return $this->with_lock(
			'authorized_payment_' . $authorized_payment_id,
			function () use ( $gateway, $authorized_payment_id ) {
				$api = $gateway->get_api();

				$authorized = $api->get_authorized_payment( $authorized_payment_id );
				if ( is_wp_error( $authorized ) ) {
					return $authorized;
				}

				$preapproval_id = isset( $authorized['preapproval_id'] ) ? (string) $authorized['preapproval_id'] : '';
				if ( '' === $preapproval_id ) {
					return 'sin contrato asociado';
				}

				$preapproval = $api->get_preapproval( $preapproval_id );
				if ( is_wp_error( $preapproval ) ) {
					return $preapproval;
				}

				$subscription = $this->find_subscription( $preapproval );
				if ( ! $subscription ) {
					MPWCR_Logger::warning( 'Cobro sin suscripción asociada', array( 'preapproval_id' => $preapproval_id ) );
					return 'sin suscripción asociada';
				}

				$ap_status      = isset( $authorized['status'] ) ? (string) $authorized['status'] : '';
				$payment        = isset( $authorized['payment'] ) && is_array( $authorized['payment'] ) ? $authorized['payment'] : array();
				$payment_status = isset( $payment['status'] ) ? (string) $payment['status'] : '';
				$payment_id     = isset( $payment['id'] ) ? (string) $payment['id'] : (string) $authorized_payment_id;

				if ( 'approved' === $payment_status ) {
					return $this->handle_approved( $subscription, $authorized, $authorized_payment_id, $payment_id );
				}

				if ( in_array( $payment_status, array( 'rejected', 'cancelled' ), true ) || in_array( $ap_status, array( 'recycling', 'cancelled' ), true ) ) {
					return $this->handle_failed( $subscription, $authorized_payment_id, $ap_status, $payment_status );
				}

				MPWCR_Logger::debug(
					'Cobro sin cambios relevantes',
					array(
						'authorized_payment_id' => $authorized_payment_id,
						'status'                => $ap_status,
						'payment_status'        => $payment_status,
					)
				);
				return 'sin cambios (' . $ap_status . ')';
			}
		);
	}

	/**
	 * Cobro aprobado: genera (o reutiliza) la orden y la marca como pagada.
	 *
	 * @return string|WP_Error
	 */
	private function handle_approved( $subscription, $authorized, $authorized_payment_id, $payment_id ) {
		$order = $this->find_order_by_authorized_payment( $authorized_payment_id );

		if ( ! $order ) {
			$parent = $subscription->get_parent();

			// Primer cobro (sin periodo de prueba): corresponde al pedido inicial.
			if ( $parent && (float) $parent->get_total() > 0 && $parent->has_status( array( 'pending', 'on-hold', 'failed', 'cancelled' ) ) && ! $parent->get_meta( '_mpwcr_authorized_payment_id' ) ) {
				$order = $parent;
			} else {
				$order = wcs_create_renewal_order( $subscription );
				if ( is_wp_error( $order ) ) {
					MPWCR_Logger::error( 'No se pudo crear la orden de renovación', array( 'error' => $order->get_error_message() ) );
					return $order;
				}
				$order->set_payment_method( MPWCR_GATEWAY_ID );
			}

			$order->update_meta_data( '_mpwcr_authorized_payment_id', (string) $authorized_payment_id );
			$order->save();
		}

		if ( $order->is_paid() ) {
			return 'orden #' . $order->get_id() . ' ya estaba pagada';
		}

		$amount = isset( $authorized['transaction_amount'] ) ? (float) $authorized['transaction_amount'] : 0;
		if ( $amount > 0 && abs( $amount - (float) $order->get_total() ) > 0.009 ) {
			$order->add_order_note(
				sprintf(
					/* translators: 1: importe cobrado, 2: total de la orden */
					__( 'Aviso: Mercado Pago cobró %1$s y el total de la orden es %2$s.', 'mp-wc-pagos-recurrentes' ),
					$amount,
					$order->get_total()
				)
			);
		}

		$order->add_order_note(
			sprintf(
				/* translators: 1: ID del pago, 2: ID del cobro autorizado */
				__( 'Mercado Pago: pago aprobado (pago %1$s, cobro autorizado %2$s).', 'mp-wc-pagos-recurrentes' ),
				$payment_id,
				$authorized_payment_id
			)
		);
		$order->payment_complete( $payment_id );

		MPWCR_Logger::info(
			'Cobro aprobado aplicado',
			array(
				'order_id'        => $order->get_id(),
				'subscription_id' => $subscription->get_id(),
				'payment_id'      => $payment_id,
			)
		);

		return 'orden #' . $order->get_id() . ' pagada';
	}

	/**
	 * Cobro rechazado / en reintento.
	 *
	 * @return string|WP_Error
	 */
	private function handle_failed( $subscription, $authorized_payment_id, $ap_status, $payment_status ) {
		$order = $this->find_order_by_authorized_payment( $authorized_payment_id );

		if ( $order && $order->is_paid() ) {
			return 'orden ya pagada';
		}

		if ( ! $order ) {
			$parent = $subscription->get_parent();

			// Fallo del primer cobro: MP reintenta; se deja constancia sin cambiar el estado.
			if ( $parent && (float) $parent->get_total() > 0 && ! $parent->is_paid() && ! $parent->get_meta( '_mpwcr_authorized_payment_id' ) ) {
				$parent->update_meta_data( '_mpwcr_authorized_payment_id', (string) $authorized_payment_id );
				$parent->update_meta_data( '_mpwcr_last_ap_status', $ap_status . '/' . $payment_status );
				$parent->save();
				$parent->add_order_note(
					sprintf(
						/* translators: 1: estado del cobro, 2: estado del pago */
						__( 'Mercado Pago: el primer cobro no se pudo completar (estado %1$s / %2$s). Mercado Pago puede reintentarlo.', 'mp-wc-pagos-recurrentes' ),
						$ap_status,
						$payment_status
					)
				);
				return 'primer cobro fallido';
			}

			$order = wcs_create_renewal_order( $subscription );
			if ( is_wp_error( $order ) ) {
				MPWCR_Logger::error( 'No se pudo crear la orden de renovación', array( 'error' => $order->get_error_message() ) );
				return $order;
			}
			$order->set_payment_method( MPWCR_GATEWAY_ID );
			$order->update_meta_data( '_mpwcr_authorized_payment_id', (string) $authorized_payment_id );
			$order->save();
		}

		$signature = $ap_status . '/' . $payment_status;
		if ( $order->get_meta( '_mpwcr_last_ap_status' ) === $signature ) {
			return 'sin cambios';
		}
		$order->update_meta_data( '_mpwcr_last_ap_status', $signature );
		$order->save();

		// Pone la orden en "fallida"; WooCommerce Subscriptions deja la suscripción en espera.
		$order->update_status(
			'failed',
			sprintf(
				/* translators: 1: estado del cobro, 2: estado del pago */
				__( 'Mercado Pago: cobro no aprobado (estado %1$s / %2$s).', 'mp-wc-pagos-recurrentes' ),
				$ap_status,
				$payment_status
			)
		);

		return 'orden #' . $order->get_id() . ' fallida';
	}
}
