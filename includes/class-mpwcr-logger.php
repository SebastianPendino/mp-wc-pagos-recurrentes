<?php
/**
 * Registro de depuración propio (archivo protegido en uploads).
 *
 * @package MPWCR
 */

defined( 'ABSPATH' ) || exit;

class MPWCR_Logger {

	const MAX_SIZE = 2097152; // 2 MB.

	/**
	 * Claves cuyo valor nunca debe escribirse en el log.
	 *
	 * @var string[]
	 */
	private static $sensitive = array( 'access_token', 'authorization', 'public_key', 'webhook_secret', 'card_token_id' );

	/**
	 * @return string Ruta absoluta del archivo de log.
	 */
	public static function get_path() {
		$upload = wp_upload_dir( null, false );
		$dir    = trailingslashit( $upload['basedir'] ) . 'mpwcr-logs';
		return $dir . '/mpwcr-' . substr( wp_hash( 'mpwcr-log' ), 0, 16 ) . '.log';
	}

	/**
	 * @return bool
	 */
	public static function debug_enabled() {
		$settings = get_option( 'woocommerce_' . MPWCR_GATEWAY_ID . '_settings', array() );
		return is_array( $settings ) && isset( $settings['debug'] ) && 'yes' === $settings['debug'];
	}

	public static function debug( $message, $context = array() ) {
		self::log( 'DEBUG', $message, $context );
	}

	public static function info( $message, $context = array() ) {
		self::log( 'INFO', $message, $context );
	}

	public static function warning( $message, $context = array() ) {
		self::log( 'WARNING', $message, $context );
	}

	public static function error( $message, $context = array() ) {
		self::log( 'ERROR', $message, $context );
	}

	/**
	 * @param string $level   Nivel.
	 * @param string $message Mensaje.
	 * @param array  $context Datos adicionales.
	 */
	public static function log( $level, $message, $context = array() ) {
		// Los errores se registran siempre; el resto solo con el modo depuración.
		if ( 'ERROR' !== $level && ! self::debug_enabled() ) {
			return;
		}

		$path = self::get_path();
		$dir  = dirname( $path );

		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
			// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			file_put_contents( $dir . '/.htaccess', "Order deny,allow\nDeny from all\n" );
			file_put_contents( $dir . '/index.php', "<?php\n// Silence is golden.\n" );
			// phpcs:enable
		}

		if ( file_exists( $path ) && filesize( $path ) > self::MAX_SIZE ) {
			@rename( $path, $path . '.old' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}

		$line = sprintf( '[%s] %s: %s', gmdate( 'Y-m-d H:i:s' ), $level, $message );
		if ( ! empty( $context ) ) {
			$line .= ' ' . wp_json_encode( self::redact( $context ) );
		}
		$line .= "\n";

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		@file_put_contents( $path, $line, FILE_APPEND | LOCK_EX );
	}

	/**
	 * @param mixed $data Datos.
	 * @return mixed
	 */
	private static function redact( $data ) {
		if ( ! is_array( $data ) ) {
			return $data;
		}
		foreach ( $data as $key => $value ) {
			if ( is_string( $key ) && in_array( strtolower( $key ), self::$sensitive, true ) ) {
				$data[ $key ] = '***';
			} elseif ( is_array( $value ) ) {
				$data[ $key ] = self::redact( $value );
			}
		}
		return $data;
	}

	/**
	 * Devuelve las últimas líneas del log.
	 *
	 * @param int $lines Número de líneas.
	 * @return string
	 */
	public static function tail( $lines = 100 ) {
		$path = self::get_path();
		if ( ! file_exists( $path ) || ! is_readable( $path ) ) {
			return '';
		}

		$size   = filesize( $path );
		$length = min( $size, 131072 ); // Últimos 128 KB.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$handle = fopen( $path, 'rb' );
		if ( ! $handle ) {
			return '';
		}
		fseek( $handle, -$length, SEEK_END );
		$content = (string) fread( $handle, $length ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		$all = explode( "\n", trim( $content ) );
		return implode( "\n", array_slice( $all, -$lines ) );
	}

	public static function clear() {
		$path = self::get_path();
		foreach ( array( $path, $path . '.old' ) as $file ) {
			if ( file_exists( $file ) ) {
				@unlink( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.unlink_unlink
			}
		}
	}
}
