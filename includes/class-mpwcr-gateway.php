<?php
/**
 * Pasarela de pago Mercado Pago (suscripciones / preapproval).
 *
 * @package MPWCR
 */

defined( 'ABSPATH' ) || exit;

class MPWCR_Gateway extends WC_Payment_Gateway {

	/**
	 * Monedas admitidas por Mercado Pago para suscripciones.
	 *
	 * @var string[]
	 */
	private $supported_currencies = array( 'ARS', 'BRL', 'CLP', 'MXN', 'COP', 'PEN', 'UYU' );

	public function __construct() {
		$this->id                 = MPWCR_GATEWAY_ID;
		$this->method_title       = __( 'Mercado Pago - Suscripciones', 'mp-wc-pagos-recurrentes' );
		$this->method_description = __( 'Cobros recurrentes automáticos con Mercado Pago (API de Preapproval). Mercado Pago gestiona el calendario de cobros y la tienda se sincroniza mediante Webhooks.', 'mp-wc-pagos-recurrentes' );
		$this->has_fields         = false;
		$this->supports           = array(
			'subscriptions',
			'subscription_cancellation',
			'subscription_suspension',
			'subscription_reactivation',
			// Mercado Pago controla el calendario: WCS no debe programar cobros propios.
			'gateway_scheduled_payments',
		);

		$this->init_form_fields();
		$this->init_settings();

		$this->title       = $this->get_option( 'title' );
		$this->description = $this->get_option( 'description' );
		$this->enabled     = $this->get_option( 'enabled' );

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
		add_action( 'woocommerce_thankyou_' . $this->id, array( $this, 'sync_on_return' ) );
	}

	/* ---------------------------------------------------------------------
	 * Ajustes
	 * ------------------------------------------------------------------ */

	public function init_form_fields() {
		$this->form_fields = array(
			'enabled'             => array(
				'title'   => __( 'Activar/Desactivar', 'mp-wc-pagos-recurrentes' ),
				'type'    => 'checkbox',
				'label'   => __( 'Activar Mercado Pago para suscripciones', 'mp-wc-pagos-recurrentes' ),
				'default' => 'no',
			),
			'title'               => array(
				'title'       => __( 'Título', 'mp-wc-pagos-recurrentes' ),
				'type'        => 'text',
				'description' => __( 'Texto que ve el cliente en el checkout.', 'mp-wc-pagos-recurrentes' ),
				'default'     => __( 'Mercado Pago (pago recurrente)', 'mp-wc-pagos-recurrentes' ),
				'desc_tip'    => true,
			),
			'description'         => array(
				'title'       => __( 'Descripción', 'mp-wc-pagos-recurrentes' ),
				'type'        => 'textarea',
				'description' => __( 'Descripción que ve el cliente en el checkout.', 'mp-wc-pagos-recurrentes' ),
				'default'     => __( 'Serás redirigido a Mercado Pago para autorizar el cobro automático de tu suscripción.', 'mp-wc-pagos-recurrentes' ),
				'desc_tip'    => true,
			),
			'section_env'         => array(
				'title'       => __( 'Entorno', 'mp-wc-pagos-recurrentes' ),
				'type'        => 'title',
				'description' => '',
			),
			'sandbox'             => array(
				'title'       => __( 'Modo Sandbox', 'mp-wc-pagos-recurrentes' ),
				'type'        => 'checkbox',
				'label'       => __( 'Activar modo de pruebas (usa las credenciales de prueba)', 'mp-wc-pagos-recurrentes' ),
				'description' => __( 'En pruebas, el email de facturación del pedido debe ser el de un usuario comprador de prueba de Mercado Pago.', 'mp-wc-pagos-recurrentes' ),
				'default'     => 'yes',
			),
			'section_test'        => array(
				'title'       => __( 'Credenciales de prueba', 'mp-wc-pagos-recurrentes' ),
				'type'        => 'title',
				'description' => '',
			),
			'test_public_key'     => array(
				'title'   => __( 'Public Key (prueba)', 'mp-wc-pagos-recurrentes' ),
				'type'    => 'text',
				'default' => '',
			),
			'test_access_token'   => array(
				'title'   => __( 'Access Token (prueba)', 'mp-wc-pagos-recurrentes' ),
				'type'    => 'password',
				'default' => '',
			),
			'section_live'        => array(
				'title'       => __( 'Credenciales de producción', 'mp-wc-pagos-recurrentes' ),
				'type'        => 'title',
				'description' => '',
			),
			'live_public_key'     => array(
				'title'   => __( 'Public Key (producción)', 'mp-wc-pagos-recurrentes' ),
				'type'    => 'text',
				'default' => '',
			),
			'live_access_token'   => array(
				'title'   => __( 'Access Token (producción)', 'mp-wc-pagos-recurrentes' ),
				'type'    => 'password',
				'default' => '',
			),
			'section_webhook'     => array(
				'title'       => __( 'Webhooks', 'mp-wc-pagos-recurrentes' ),
				'type'        => 'title',
				'description' => '',
			),
			'webhook_secret'      => array(
				'title'       => __( 'Clave secreta del Webhook', 'mp-wc-pagos-recurrentes' ),
				'type'        => 'password',
				'description' => __( 'Opcional pero recomendado. Es la "clave secreta" que Mercado Pago muestra al configurar el Webhook; se usa para validar la firma de cada notificación.', 'mp-wc-pagos-recurrentes' ),
				'desc_tip'    => true,
				'default'     => '',
			),
			'webhook_status'      => array(
				'title' => __( 'URL y estado del Webhook', 'mp-wc-pagos-recurrentes' ),
				'type'  => 'mpwcr_webhook',
			),
			'section_debug'       => array(
				'title'       => __( 'Depuración', 'mp-wc-pagos-recurrentes' ),
				'type'        => 'title',
				'description' => '',
			),
			'debug'               => array(
				'title'       => __( 'Registro de depuración', 'mp-wc-pagos-recurrentes' ),
				'type'        => 'checkbox',
				'label'       => __( 'Activar registro detallado (los errores se registran siempre)', 'mp-wc-pagos-recurrentes' ),
				'description' => __( 'Los tokens y claves nunca se escriben en el registro.', 'mp-wc-pagos-recurrentes' ),
				'default'     => 'no',
			),
			'logs'                => array(
				'title' => __( 'Registros', 'mp-wc-pagos-recurrentes' ),
				'type'  => 'mpwcr_logs',
			),
		);
	}

	/**
	 * Campo personalizado: URL del webhook + estado.
	 */
	public function generate_mpwcr_webhook_html( $key, $data ) {
		$url  = MPWCR_Webhook::get_url();
		$last = get_option( MPWCR_Webhook::OPTION_LAST );

		ob_start();
		?>
		<tr valign="top">
			<th scope="row" class="titledesc"><label><?php echo esc_html( $data['title'] ); ?></label></th>
			<td class="forminp">
				<input type="text" id="mpwcr-webhook-url" class="input-text regular-input" style="width:100%;max-width:520px" readonly value="<?php echo esc_attr( $url ); ?>" onclick="this.select();" />
				<button type="button" class="button" onclick="var i=document.getElementById('mpwcr-webhook-url');i.select();navigator.clipboard&&navigator.clipboard.writeText(i.value);this.textContent='<?php echo esc_js( __( '¡Copiada!', 'mp-wc-pagos-recurrentes' ) ); ?>';">
					<?php esc_html_e( 'Copiar URL', 'mp-wc-pagos-recurrentes' ); ?>
				</button>
				<p class="description">
					<?php esc_html_e( 'Pega esta URL en Mercado Pago (Tus integraciones > Webhooks) y activa los eventos "Planes y suscripciones" (suscripción y pago recurrente).', 'mp-wc-pagos-recurrentes' ); ?>
				</p>
				<?php if ( 0 !== strpos( $url, 'https://' ) ) : ?>
					<p class="description" style="color:#b32d2e">
						<?php esc_html_e( 'Atención: Mercado Pago solo envía notificaciones a URLs HTTPS accesibles públicamente.', 'mp-wc-pagos-recurrentes' ); ?>
					</p>
				<?php endif; ?>
				<p style="margin-top:10px">
					<strong><?php esc_html_e( 'Última notificación recibida:', 'mp-wc-pagos-recurrentes' ); ?></strong>
					<?php if ( is_array( $last ) && ! empty( $last['time'] ) ) : ?>
						<?php
						echo esc_html(
							sprintf(
								/* translators: 1: hace cuánto, 2: tema, 3: id, 4: resultado */
								__( 'hace %1$s - %2$s #%3$s - %4$s', 'mp-wc-pagos-recurrentes' ),
								human_time_diff( (int) $last['time'], time() ),
								$last['topic'],
								$last['id'],
								$last['result'] . ( ! empty( $last['message'] ) ? ' (' . $last['message'] . ')' : '' )
							)
						);
						?>
					<?php else : ?>
						<span style="color:#996800"><?php esc_html_e( 'Aún no se ha recibido ninguna notificación.', 'mp-wc-pagos-recurrentes' ); ?></span>
					<?php endif; ?>
				</p>
				<p>
					<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=mpwcr_check_credentials' ), 'mpwcr_check_credentials' ) ); ?>">
						<?php esc_html_e( 'Verificar credenciales (guarda los cambios antes)', 'mp-wc-pagos-recurrentes' ); ?>
					</a>
				</p>
			</td>
		</tr>
		<?php
		return ob_get_clean();
	}

	/**
	 * Campo personalizado: visor de logs.
	 */
	public function generate_mpwcr_logs_html( $key, $data ) {
		$log = MPWCR_Logger::tail( 100 );

		ob_start();
		?>
		<tr valign="top">
			<th scope="row" class="titledesc"><label><?php echo esc_html( $data['title'] ); ?></label></th>
			<td class="forminp">
				<textarea readonly rows="12" class="large-text code" style="font-size:11px;white-space:pre;overflow:auto"><?php echo esc_textarea( '' !== $log ? $log : __( 'El registro está vacío.', 'mp-wc-pagos-recurrentes' ) ); ?></textarea>
				<p>
					<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=mpwcr_clear_logs' ), 'mpwcr_clear_logs' ) ); ?>">
						<?php esc_html_e( 'Vaciar registros', 'mp-wc-pagos-recurrentes' ); ?>
					</a>
					<span class="description"><?php esc_html_e( 'Se muestran las últimas 100 líneas.', 'mp-wc-pagos-recurrentes' ); ?></span>
				</p>
			</td>
		</tr>
		<?php
		return ob_get_clean();
	}

	/** Los campos informativos no se guardan. */
	public function validate_mpwcr_webhook_field( $key, $value ) {
		return '';
	}

	public function validate_mpwcr_logs_field( $key, $value ) {
		return '';
	}

	/* ---------------------------------------------------------------------
	 * Credenciales / API
	 * ------------------------------------------------------------------ */

	/**
	 * @return bool
	 */
	public function is_sandbox() {
		return 'yes' === $this->get_option( 'sandbox' );
	}

	/**
	 * @return string
	 */
	public function get_access_token() {
		return trim( (string) $this->get_option( $this->is_sandbox() ? 'test_access_token' : 'live_access_token' ) );
	}

	/**
	 * @return string
	 */
	public function get_public_key() {
		return trim( (string) $this->get_option( $this->is_sandbox() ? 'test_public_key' : 'live_public_key' ) );
	}

	/**
	 * @return string
	 */
	public function get_webhook_secret() {
		return trim( (string) $this->get_option( 'webhook_secret' ) );
	}

	/**
	 * @return MPWCR_API
	 */
	public function get_api() {
		return new MPWCR_API( $this->get_access_token() );
	}

	/* ---------------------------------------------------------------------
	 * Disponibilidad
	 * ------------------------------------------------------------------ */

	public function is_available() {
		if ( ! parent::is_available() ) {
			return false;
		}

		if ( '' === $this->get_access_token() ) {
			return false;
		}

		if ( is_admin() && ! wp_doing_ajax() ) {
			return true;
		}

		if ( ! in_array( get_woocommerce_currency(), apply_filters( 'mpwcr_supported_currencies', $this->supported_currencies ), true ) ) {
			return false;
		}

		// Página "pagar pedido": solo para pedidos que originan una suscripción.
		if ( function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url( 'order-pay' ) ) {
			$order = wc_get_order( absint( get_query_var( 'order-pay' ) ) );
			return $order && function_exists( 'wcs_order_contains_subscription' ) && wcs_order_contains_subscription( $order, 'parent' );
		}

		// Solo se ofrece cuando el carrito contiene una suscripción.
		if ( function_exists( 'WC' ) && WC()->cart && class_exists( 'WC_Subscriptions_Cart' ) ) {
			return (bool) WC_Subscriptions_Cart::cart_contains_subscription();
		}

		return false;
	}

	/* ---------------------------------------------------------------------
	 * Pago inicial: creación del contrato
	 * ------------------------------------------------------------------ */

	public function process_payment( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return array( 'result' => 'failure' );
		}

		$subscriptions = wcs_get_subscriptions_for_order( $order, array( 'order_type' => 'parent' ) );
		if ( 1 !== count( $subscriptions ) ) {
			wc_add_notice( __( 'Mercado Pago solo admite una suscripción por pedido. Realiza compras separadas para cada suscripción.', 'mp-wc-pagos-recurrentes' ), 'error' );
			return array( 'result' => 'failure' );
		}
		$subscription = reset( $subscriptions );

		$payload = $this->build_preapproval_payload( $order, $subscription );
		if ( is_wp_error( $payload ) ) {
			wc_add_notice( $payload->get_error_message(), 'error' );
			return array( 'result' => 'failure' );
		}

		$response = $this->get_api()->create_preapproval( $payload );
		if ( is_wp_error( $response ) ) {
			MPWCR_Logger::error(
				'No se pudo crear el preapproval',
				array(
					'order_id' => $order_id,
					'error'    => $response->get_error_message(),
				)
			);
			wc_add_notice(
				sprintf(
					/* translators: %s: mensaje de error */
					__( 'No se pudo iniciar el pago con Mercado Pago: %s', 'mp-wc-pagos-recurrentes' ),
					$response->get_error_message()
				),
				'error'
			);
			return array( 'result' => 'failure' );
		}

		if ( empty( $response['id'] ) || empty( $response['init_point'] ) ) {
			MPWCR_Logger::error( 'Respuesta de preapproval incompleta', array( 'response' => $response ) );
			wc_add_notice( __( 'Mercado Pago devolvió una respuesta inesperada. Inténtalo de nuevo.', 'mp-wc-pagos-recurrentes' ), 'error' );
			return array( 'result' => 'failure' );
		}

		$preapproval_id = (string) $response['id'];

		$subscription->update_meta_data( '_mpwcr_preapproval_id', $preapproval_id );
		$subscription->update_meta_data( '_mpwcr_status', isset( $response['status'] ) ? $response['status'] : 'pending' );
		$subscription->save();

		$order->update_meta_data( '_mpwcr_preapproval_id', $preapproval_id );
		$order->set_transaction_id( $preapproval_id );
		$order->save();

		$order->update_status(
			'on-hold',
			sprintf(
				/* translators: %s: ID del preapproval */
				__( 'Esperando la autorización del cliente en Mercado Pago (contrato %s).', 'mp-wc-pagos-recurrentes' ),
				$preapproval_id
			)
		);
		wc_reduce_stock_levels( $order_id );

		MPWCR_Logger::info(
			'Preapproval creado',
			array(
				'order_id'        => $order_id,
				'subscription_id' => $subscription->get_id(),
				'preapproval_id'  => $preapproval_id,
			)
		);

		return array(
			'result'   => 'success',
			'redirect' => $response['init_point'],
		);
	}

	/**
	 * Convierte un periodo de WooCommerce al formato de Mercado Pago (days | months).
	 *
	 * @param int    $interval Intervalo.
	 * @param string $period   day|week|month|year.
	 * @return array|null [frecuencia, tipo]
	 */
	private function convert_period( $interval, $period ) {
		$interval = max( 1, (int) $interval );
		switch ( $period ) {
			case 'day':
				return array( $interval, 'days' );
			case 'week':
				return array( $interval * 7, 'days' );
			case 'month':
				return array( $interval, 'months' );
			case 'year':
				return array( $interval * 12, 'months' );
		}
		return null;
	}

	/**
	 * Construye el payload de /preapproval a partir de la suscripción de WooCommerce.
	 *
	 * @param WC_Order        $order        Pedido inicial.
	 * @param WC_Subscription $subscription Suscripción.
	 * @return array|WP_Error
	 */
	private function build_preapproval_payload( $order, $subscription ) {
		$interval  = (int) $subscription->get_billing_interval();
		$period    = $subscription->get_billing_period();
		$recurring = $this->convert_period( $interval, $period );

		if ( ! $recurring ) {
			return new WP_Error( 'mpwcr_period', __( 'La frecuencia de esta suscripción no es compatible con Mercado Pago.', 'mp-wc-pagos-recurrentes' ) );
		}

		$amount = round( (float) $subscription->get_total(), wc_get_price_decimals() );
		if ( $amount <= 0 ) {
			return new WP_Error( 'mpwcr_amount', __( 'El importe recurrente debe ser mayor que cero.', 'mp-wc-pagos-recurrentes' ) );
		}

		// Producto de referencia (duración y periodo de prueba).
		$product = null;
		foreach ( $subscription->get_items() as $item ) {
			$product = $item->get_product();
			if ( $product ) {
				break;
			}
		}

		$auto_recurring = array(
			'frequency'          => $recurring[0],
			'frequency_type'     => $recurring[1],
			'transaction_amount' => $amount,
			'currency_id'        => get_woocommerce_currency(),
		);

		$has_trial = false;
		if ( $product && class_exists( 'WC_Subscriptions_Product' ) ) {
			$trial_length = (int) WC_Subscriptions_Product::get_trial_length( $product );
			if ( $trial_length > 0 ) {
				$trial = $this->convert_period( $trial_length, WC_Subscriptions_Product::get_trial_period( $product ) );
				if ( $trial ) {
					$auto_recurring['free_trial'] = array(
						'frequency'      => $trial[0],
						'frequency_type' => $trial[1],
					);
					$has_trial                    = true;
				}
			}

			$length = (int) WC_Subscriptions_Product::get_length( $product );
			if ( $length > 0 ) {
				$repetitions = (int) floor( $length / max( 1, $interval ) );
				if ( $repetitions > 0 ) {
					$auto_recurring['repetitions'] = $repetitions;
				}
			}
		}

		// Mercado Pago cobra siempre el importe recurrente en el primer ciclo (o 0 si hay prueba).
		$order_total = round( (float) $order->get_total(), wc_get_price_decimals() );
		if ( $has_trial && $order_total > 0 ) {
			return new WP_Error( 'mpwcr_signup', __( 'Mercado Pago no admite cargos iniciales (cuota de alta, envío u otros productos) en suscripciones con periodo de prueba.', 'mp-wc-pagos-recurrentes' ) );
		}
		if ( ! $has_trial && abs( $order_total - $amount ) > 0.009 ) {
			return new WP_Error( 'mpwcr_total', __( 'Mercado Pago solo admite que el primer cobro sea igual al cobro recurrente. Elimina cuotas de alta, cupones u otros productos del carrito.', 'mp-wc-pagos-recurrentes' ) );
		}

		$payload = array(
			'reason'             => sprintf(
				/* translators: 1: nombre de la tienda, 2: ID de la suscripción */
				__( '%1$s - Suscripción #%2$d', 'mp-wc-pagos-recurrentes' ),
				wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
				$subscription->get_id()
			),
			'external_reference' => (string) $subscription->get_id(),
			'payer_email'        => $order->get_billing_email(),
			'auto_recurring'     => $auto_recurring,
			'back_url'           => $this->get_return_url( $order ),
			'status'             => 'pending',
		);

		return apply_filters( 'mpwcr_preapproval_payload', $payload, $order, $subscription );
	}

	/**
	 * Al volver desde Mercado Pago se sincroniza el estado sin esperar al Webhook.
	 *
	 * @param int $order_id Pedido.
	 */
	public function sync_on_return( $order_id ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$returned = isset( $_GET['preapproval_id'] ) ? sanitize_text_field( wp_unslash( $_GET['preapproval_id'] ) ) : '';
		if ( '' === $returned ) {
			return;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order || $order->get_meta( '_mpwcr_preapproval_id' ) !== $returned ) {
			return;
		}

		$plugin = MPWCR_Plugin::instance();
		if ( $plugin->webhook ) {
			$plugin->webhook->sync_preapproval( $returned );
		}
	}
}
