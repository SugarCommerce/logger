<?php
/**
 * PHPUnit bootstrap.
 *
 * There is no WordPress to load: the core functions the library touches are
 * stubbed here, and uploads is a fresh folder under the system's temp
 * directory for each run.
 *
 * @package SugarCommerce\Logger
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__ ) . '/' );
}

require_once dirname( __DIR__ ) . '/vendor/autoload.php';

$GLOBALS['lg_uploads'] = sys_get_temp_dir() . '/sc-logger-' . getmypid() . '-' . bin2hex( random_bytes( 4 ) );

register_shutdown_function(
	static function (): void {
		lg_remove( $GLOBALS['lg_uploads'] );
	}
);

/**
 * Remove a folder and everything in it.
 *
 * @param string $path The folder.
 *
 * @return void
 */
function lg_remove( string $path ): void {
	if ( ! is_dir( $path ) ) {
		return;
	}

	foreach ( (array) scandir( $path ) as $file ) {
		if ( '.' === $file || '..' === $file ) {
			continue;
		}

		$full = $path . '/' . $file;
		is_dir( $full ) ? lg_remove( $full ) : unlink( $full );
	}

	rmdir( $path );
}

if ( ! class_exists( 'WP_Error' ) ) {
	/**
	 * Core's error object, near enough.
	 */
	class WP_Error {

		/**
		 * @param string $code    Code.
		 * @param string $message Message.
		 * @param mixed  $data    Data.
		 */
		public function __construct( public string $code = '', public string $message = '', public mixed $data = '' ) {}

		/**
		 * @return string
		 */
		public function get_error_code(): string {
			return $this->code;
		}

		/**
		 * @return string
		 */
		public function get_error_message(): string {
			return $this->message;
		}

		/**
		 * @return mixed
		 */
		public function get_error_data(): mixed {
			return $this->data;
		}
	}
}

/**
 * @param string $key Key.
 *
 * @return string
 */
function sanitize_key( $key ) {
	return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
}

/**
 * @param string $name File name.
 *
 * @return string
 */
function sanitize_file_name( $name ) {
	return preg_replace( '/[^A-Za-z0-9._-]/', '', (string) $name );
}

/**
 * @param string $path Path.
 *
 * @return string
 */
function trailingslashit( $path ) {
	return rtrim( (string) $path, '/\\' ) . '/';
}

/**
 * @return array
 */
function wp_upload_dir( ...$args ) {
	return array( 'basedir' => $GLOBALS['lg_uploads'] );
}

/**
 * @param string $data Data.
 *
 * @return string
 */
function wp_hash( $data ) {
	return hash_hmac( 'md5', (string) $data, 'site-salt' );
}

/**
 * @param string $dir Folder.
 *
 * @return bool
 */
function wp_mkdir_p( $dir ) {
	return is_dir( $dir ) || mkdir( $dir, 0755, true );
}

/**
 * @param string $type Format.
 *
 * @return string
 */
function current_time( $type ) {
	return '2026-11-18T10:00:00+00:00';
}

/**
 * @param mixed $value Value.
 * @param int   $flags Flags.
 *
 * @return string|false
 */
function wp_json_encode( $value, $flags = 0 ) {
	return json_encode( $value, $flags );
}
