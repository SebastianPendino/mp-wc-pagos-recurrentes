<?php
/**
 * Utilidades de administración: acciones del panel, avisos y enlaces.
 *
 * @package MPWCR
 */

defined( 'ABSPATH' ) || exit;

class MPWCR_Admin {

	public function __construct() {
		add_action( 'admin_post_mpwcr_clear_logs', array( $this, 'clear_logs' ) );
		add_action( 'admin_post_mpwcr_check_credentials', array( $this, 'check_credentials' ) );
		add_action( 'admin_notices', array( $this, 'notices' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( MPWCR_FILE ), array( $this, 'action_links' ) );
	}

	/**
	 * @return string
	 */
	public static function settings_url() {
		return admin_url( 'admin.php?page=wc-settings&tab=checkout&section=' . MPWCR_GATEWAY_ID );
	}

	public function action_links( $links ) {
		array_unshift(
			$links,
			'<a href="' . esc_url( self::settings_url() ) . '">' . esc_html__( 'Ajustes', 'mp-wc-pagos-recurrentes' ) . '</a>'
		);
		return $links;
	}

	public function clear_logs() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'No tienes permisos para realizar esta acción.', 'mp-wc-pagos-recurrentes' ) );
		}
		check_admin_referer( 'mpwcr_clear_logs' );

		MPWCR_Logger::clear();

		wp_safe_redirect( add_query_arg( 'mpwcr_notice', 'logs_cleared', self::settings_url() ) );
		exit;
	}

	public function check_credentials() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'No tienes permisos para realizar esta acción.', 'mp-wc-pagos-recurrentes' ) );
		}
		check_admin_referer( 'mpwcr_check_credentials' );

		$gateway = MPWCR_Plugin::gateway();
		$args    = array( 'mpwcr_notice' => 'cred_fail' );

		if ( $gateway ) {
			$me = $gateway->get_api()->get_me();
			if ( is_wp_error( $me ) ) {
				$args['mpwcr_msg'] = rawurlencode( $me->get_error_message() );
			} else {
				$args['mpwcr_notice'] = 'cred_ok';
				$args['mpwcr_msg']    = rawurlencode( isset( $me['email'] ) ? $me['email'] : ( isset( $me['nickname'] ) ? $me['nickname'] : '' ) );
			}
		}

		wp_safe_redirect( add_query_arg( $args, self::settings_url() ) );
		exit;
	}

	public function notices() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( empty( $_GET['mpwcr_notice'] ) ) {
			return;
		}
		$type = sanitize_key( wp_unslash( $_GET['mpwcr_notice'] ) );
		$msg  = isset( $_GET['mpwcr_msg'] ) ? sanitize_text_field( rawurldecode( wp_unslash( $_GET['mpwcr_msg'] ) ) ) : '';
		// phpcs:enable

		switch ( $type ) {
			case 'logs_cleared':
				$class = 'notice-success';
				$text  = __( 'Registros vaciados.', 'mp-wc-pagos-recurrentes' );
				break;
			case 'cred_ok':
				$class = 'notice-success';
				/* translators: %s: cuenta de Mercado Pago */
				$text = sprintf( __( 'Credenciales válidas. Cuenta de Mercado Pago: %s', 'mp-wc-pagos-recurrentes' ), $msg );
				break;
			case 'cred_fail':
				$class = 'notice-error';
				/* translators: %s: error */
				$text = sprintf( __( 'No se pudieron validar las credenciales: %s', 'mp-wc-pagos-recurrentes' ), $msg );
				break;
			default:
				return;
		}

		printf( '<div class="notice %s is-dismissible"><p>%s</p></div>', esc_attr( $class ), esc_html( $text ) );
	}
}
