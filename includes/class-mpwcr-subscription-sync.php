<?php
/**
 * Sincroniza los cambios de estado hechos en la tienda hacia Mercado Pago
 * (cancelación, suspensión y reactivación del contrato).
 *
 * @package MPWCR
 */

defined( 'ABSPATH' ) || exit;

class MPWCR_Subscription_Sync {

	public function __construct() {
		add_action( 'woocommerce_subscription_status_updated', array( $this, 'on_status_updated' ), 10, 3 );

		// Mercado Pago controla el calendario: nunca se intenta un cobro directo desde WooCommerce.
		add_action( 'woocommerce_scheduled_subscription_payment_' . MPWCR_GATEWAY_ID, '__return_null', 10, 2 );
	}

	/**
	 * @param WC_Subscription $subscription Suscripción.
	 * @param string          $new_status   Nuevo estado.
	 * @param string          $old_status   Estado anterior.
	 */
	public function on_status_updated( $subscription, $new_status, $old_status ) {
		// El cambio viene de un Webhook: no se devuelve a la API.
		if ( MPWCR_Webhook::$is_processing ) {
			return;
		}

		if ( MPWCR_GATEWAY_ID !== $subscription->get_payment_method() ) {
			return;
		}

		$preapproval_id = (string) $subscription->get_meta( '_mpwcr_preapproval_id' );
		if ( '' === $preapproval_id ) {
			return;
		}

		$gateway = MPWCR_Plugin::gateway();
		if ( ! $gateway ) {
			return;
		}

		$current = (string) $subscription->get_meta( '_mpwcr_status' );

		switch ( $new_status ) {
			case 'cancelled':
			case 'pending-cancel':
			case 'expired':
				if ( 'cancelled' !== $current ) {
					$this->update_remote( $gateway, $subscription, $preapproval_id, 'cancelled' );
				}
				break;

			case 'on-hold':
				// Un pedido recién creado pasa de "pending" a "on-hold" sin contrato autorizado.
				if ( 'pending' !== $old_status && in_array( $current, array( 'authorized' ), true ) ) {
					$this->update_remote( $gateway, $subscription, $preapproval_id, 'paused' );
				}
				break;

			case 'active':
				if ( 'on-hold' === $old_status && 'paused' === $current ) {
					$this->update_remote( $gateway, $subscription, $preapproval_id, 'authorized' );
				}
				break;
		}
	}

	/**
	 * Envía el PUT a /preapproval/{id} y registra el resultado.
	 *
	 * @param MPWCR_Gateway   $gateway        Pasarela.
	 * @param WC_Subscription $subscription   Suscripción.
	 * @param string          $preapproval_id ID del contrato.
	 * @param string          $status         cancelled | paused | authorized.
	 */
	private function update_remote( $gateway, $subscription, $preapproval_id, $status ) {
		$response = $gateway->get_api()->update_preapproval( $preapproval_id, array( 'status' => $status ) );

		if ( is_wp_error( $response ) ) {
			MPWCR_Logger::error(
				'No se pudo actualizar el contrato en Mercado Pago',
				array(
					'subscription_id' => $subscription->get_id(),
					'status'          => $status,
					'error'           => $response->get_error_message(),
				)
			);
			$subscription->add_order_note(
				sprintf(
					/* translators: 1: estado, 2: error */
					__( 'Error al sincronizar con Mercado Pago (estado "%1$s"): %2$s', 'mp-wc-pagos-recurrentes' ),
					$status,
					$response->get_error_message()
				)
			);
			return;
		}

		$subscription->update_meta_data( '_mpwcr_status', $status );
		$subscription->save();

		MPWCR_Logger::info(
			'Contrato actualizado en Mercado Pago',
			array(
				'subscription_id' => $subscription->get_id(),
				'status'          => $status,
			)
		);
		$subscription->add_order_note(
			sprintf(
				/* translators: %s: estado */
				__( 'Mercado Pago: contrato actualizado a "%s".', 'mp-wc-pagos-recurrentes' ),
				$status
			)
		);
	}
}
