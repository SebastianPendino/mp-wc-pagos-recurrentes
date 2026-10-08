<?php
/**
 * Clase principal: verifica dependencias y registra los componentes.
 *
 * @package MPWCR
 */

defined( 'ABSPATH' ) || exit;

class MPWCR_Plugin {

	/**
	 * @var MPWCR_Plugin|null
	 */
	private static $instance = null;

	/**
	 * @var MPWCR_Webhook|null
	 */
	public $webhook = null;

	/**
	 * @return MPWCR_Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'init', array( $this, 'load_textdomain' ) );

		if ( ! $this->dependencies_ok() ) {
			add_action( 'admin_notices', array( $this, 'missing_dependencies_notice' ) );
			return;
		}

		$this->includes();

		$this->webhook = new MPWCR_Webhook();
		new MPWCR_Subscription_Sync();

		if ( is_admin() ) {
			new MPWCR_Admin();
		}

		add_filter( 'woocommerce_payment_gateways', array( $this, 'register_gateway' ) );
		add_action( 'woocommerce_blocks_payment_method_type_registration', array( $this, 'register_blocks_support' ) );
	}

	public function load_textdomain() {
		load_plugin_textdomain( 'mp-wc-pagos-recurrentes', false, dirname( plugin_basename( MPWCR_FILE ) ) . '/languages' );
	}

	/**
	 * @return bool
	 */
	private function dependencies_ok() {
		$wc  = class_exists( 'WooCommerce' );
		$wcs = class_exists( 'WC_Subscriptions' ) || class_exists( 'WC_Subscriptions_Core_Plugin' ) || function_exists( 'wcs_get_subscription' );
		return $wc && $wcs;
	}

	public function missing_dependencies_notice() {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		$missing = array();
		if ( ! class_exists( 'WooCommerce' ) ) {
			$missing[] = 'WooCommerce';
		}
		if ( ! ( class_exists( 'WC_Subscriptions' ) || class_exists( 'WC_Subscriptions_Core_Plugin' ) || function_exists( 'wcs_get_subscription' ) ) ) {
			$missing[] = 'WooCommerce Subscriptions';
		}
		printf(
			'<div class="notice notice-error"><p><strong>%s</strong> %s</p></div>',
			esc_html__( 'Mercado Pago - Pagos Recurrentes:', 'mp-wc-pagos-recurrentes' ),
			esc_html(
				sprintf(
					/* translators: %s: lista de plugins */
					__( 'requiere que estén activos los siguientes plugins: %s.', 'mp-wc-pagos-recurrentes' ),
					implode( ', ', $missing )
				)
			)
		);
	}

	private function includes() {
		require_once MPWCR_PATH . 'includes/class-mpwcr-logger.php';
		require_once MPWCR_PATH . 'includes/class-mpwcr-api.php';
		require_once MPWCR_PATH . 'includes/class-mpwcr-gateway.php';
		require_once MPWCR_PATH . 'includes/class-mpwcr-webhook.php';
		require_once MPWCR_PATH . 'includes/class-mpwcr-subscription-sync.php';
		require_once MPWCR_PATH . 'includes/class-mpwcr-admin.php';
	}

	/**
	 * @param array $gateways Pasarelas registradas.
	 * @return array
	 */
	public function register_gateway( $gateways ) {
		$gateways[] = 'MPWCR_Gateway';
		return $gateways;
	}

	/**
	 * Soporte para el checkout basado en bloques.
	 *
	 * @param \Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry $registry Registro.
	 */
	public function register_blocks_support( $registry ) {
		if ( ! class_exists( '\Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType' ) ) {
			return;
		}
		require_once MPWCR_PATH . 'includes/class-mpwcr-blocks-support.php';
		$registry->register( new MPWCR_Blocks_Support() );
	}

	/**
	 * Devuelve la instancia de la pasarela registrada en WooCommerce.
	 *
	 * @return MPWCR_Gateway|null
	 */
	public static function gateway() {
		if ( ! function_exists( 'WC' ) || ! WC() ) {
			return null;
		}
		$gateways = WC()->payment_gateways()->payment_gateways();
		return isset( $gateways[ MPWCR_GATEWAY_ID ] ) ? $gateways[ MPWCR_GATEWAY_ID ] : null;
	}
}
