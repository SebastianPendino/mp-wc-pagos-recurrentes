<?php
/**
 * Integración con el checkout basado en bloques de WooCommerce.
 *
 * @package MPWCR
 */

defined( 'ABSPATH' ) || exit;

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

final class MPWCR_Blocks_Support extends AbstractPaymentMethodType {

	/**
	 * @var string
	 */
	protected $name = MPWCR_GATEWAY_ID;

	public function initialize() {
		$this->settings = get_option( 'woocommerce_' . MPWCR_GATEWAY_ID . '_settings', array() );
	}

	public function is_active() {
		return ! empty( $this->settings['enabled'] ) && 'yes' === $this->settings['enabled'];
	}

	public function get_payment_method_script_handles() {
		wp_register_script(
			'mpwcr-blocks',
			MPWCR_URL . 'assets/js/blocks.js',
			array( 'wc-blocks-registry', 'wc-settings', 'wp-element', 'wp-html-entities' ),
			MPWCR_VERSION,
			true
		);
		return array( 'mpwcr-blocks' );
	}

	public function get_payment_method_data() {
		return array(
			'title'       => isset( $this->settings['title'] ) ? $this->settings['title'] : '',
			'description' => isset( $this->settings['description'] ) ? $this->settings['description'] : '',
			'supports'    => array( 'products' ),
		);
	}
}
