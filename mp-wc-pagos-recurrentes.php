<?php
/**
 * Plugin Name:       Mercado Pago - Pagos Recurrentes para WooCommerce Subscriptions
 * Plugin URI:        https://github.com/
 * Description:       Pasarela de pago que conecta WooCommerce Subscriptions con la API de Preapproval de Mercado Pago. Mercado Pago controla el calendario de cobros y el plugin reacciona a los Webhooks.
 * Version:           1.0.0
 * Author:            mp-wc-pagos-recurrentes
 * License:           GPL-2.0-or-later
 * Text Domain:       mp-wc-pagos-recurrentes
 * Domain Path:       /languages
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Requires Plugins:  woocommerce
 * WC requires at least: 6.0
 *
 * @package MPWCR
 */

defined( 'ABSPATH' ) || exit;

define( 'MPWCR_VERSION', '1.0.0' );
define( 'MPWCR_FILE', __FILE__ );
define( 'MPWCR_PATH', plugin_dir_path( __FILE__ ) );
define( 'MPWCR_URL', plugin_dir_url( __FILE__ ) );
define( 'MPWCR_GATEWAY_ID', 'mpwcr' );

/**
 * Declara compatibilidad con HPOS (tablas personalizadas de pedidos) y checkout por bloques.
 */
add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', MPWCR_FILE, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', MPWCR_FILE, true );
		}
	}
);

require_once MPWCR_PATH . 'includes/class-mpwcr-plugin.php';

add_action( 'plugins_loaded', array( 'MPWCR_Plugin', 'instance' ), 30 );
