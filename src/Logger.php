<?php
/**
 * Logger
 *
 * @package   SugarCommerce\Logger
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Logger;

use SugarCommerce\Logger\Traits\Logger\Files;
use SugarCommerce\Logger\Traits\Logger\Redaction;
use Throwable;
use WP_Error;

/**
 * Class Logger
 *
 * One plugin's log file.
 *
 * `error_log()` puts every plugin in one file, which is useless the moment
 * two things misbehave. A log of its own in uploads is the usual fix, but a
 * predictable path is a public URL to customer emails, addresses and gateway
 * responses -- and the `.htaccess` written beside it does nothing on nginx.
 * So the file is named with a hash salted per site, the way WooCommerce does
 * it, and cannot be guessed from outside.
 *
 * Off unless `WP_DEBUG` is on or the plugin's own `{NAME}_DEBUG` constant
 * says so.
 *
 * @since 1.0.0
 */
class Logger {

	use Files;
	use Redaction;

	/**
	 * The size, in bytes, at which the log rotates: 5 MB.
	 */
	public const int DEFAULT_MAX_SIZE = 5242880;

	/**
	 * The levels, quietest first.
	 */
	public const array LEVELS = array(
		'DEBUG'   => 100,
		'INFO'    => 200,
		'WARNING' => 300,
		'ERROR'   => 400,
	);

	/**
	 * Context keys written as `[redacted]`, whatever their case and however
	 * deep: things a log has no business keeping.
	 *
	 * Each is matched as a word of the key, not the whole key: `secret`
	 * catches `client_secret`, `webhookSecret` and `stripe_secret_key`, and
	 * `token` catches `access_token`, while `passenger` and `tokenizer` stay.
	 */
	public const array REDACT = array( 'password', 'pass', 'passphrase', 'secret', 'token', 'api_key', 'apikey', 'private_key', 'authorization', 'cookie', 'card_number', 'cvc', 'cvv' );

	/**
	 * Values written as `[redacted]` whatever their key: live and test
	 * Stripe secret and restricted keys and webhook signing secrets.
	 */
	private const string SECRET_VALUE = '/\b(?:(?:sk|rk)_(?:live|test)_|whsec_)[A-Za-z0-9]+/';

	/**
	 * The plugin's name, as a key.
	 *
	 * @var string
	 */
	private string $name;

	/**
	 * The file written to.
	 *
	 * @var string
	 */
	private string $log_file;

	/**
	 * Whether anything is written.
	 *
	 * @var bool
	 */
	private bool $enabled;

	/**
	 * Bytes after which the log rotates; 0 never.
	 *
	 * @var int
	 */
	private int $max_size;

	/**
	 * The quietest level written.
	 *
	 * @var string
	 */
	private string $level;

	/**
	 * Context keys left out, lower case.
	 *
	 * @var string[]
	 */
	private array $redact;

	/**
	 * The words of each redacted key, matched against the words of a
	 * context key.
	 *
	 * @var array<int, string[]>
	 */
	private array $redact_words;

	/**
	 * Whether the folder has been made and protected.
	 *
	 * @var bool
	 */
	private bool $ready = false;

	/**
	 * Build a logger.
	 *
	 * Nothing is touched on disk until the first entry is written.
	 *
	 * @since 1.0.0
	 *
	 * @param string $name    The plugin's name; also the folder in uploads,
	 *                        and the start of its `{NAME}_DEBUG` constant.
	 * @param array  $options {
	 *     Optional settings.
	 *
	 *     @type bool     $enabled  Whether to write. Default: `{NAME}_DEBUG`,
	 *                              else `WP_DEBUG`.
	 *     @type string   $log_file A file name, put in the plugin's folder in
	 *                              uploads, or a whole path. Default
	 *                              `uploads/{name}/{name}-{hash}.log`.
	 *     @type int      $max_size Bytes after which the log rotates, keeping
	 *                              one previous file as `.1`. 0 never.
	 *                              Default 5 MB.
	 *     @type string   $level    The quietest level written: `debug`,
	 *                              `info`, `warning` or `error`. Default
	 *                              `debug`, everything.
	 *     @type string[] $redact   Context keys written as `[redacted]`.
	 *                              Default Logger::REDACT; an empty array
	 *                              keeps everything.
	 * }
	 */
	public function __construct( string $name, array $options = array() ) {
		$this->name     = sanitize_key( $name );
		$this->enabled  = (bool) ( $options['enabled'] ?? $this->debugging() );
		$this->max_size = max( 0, (int) ( $options['max_size'] ?? self::DEFAULT_MAX_SIZE ) );
		$this->level    = self::level( (string) ( $options['level'] ?? 'DEBUG' ) );
		$this->redact   = array_map( 'strtolower', array_map( 'strval', (array) ( $options['redact'] ?? self::REDACT ) ) );

		$this->redact_words = array_values( array_filter( array_map( array( self::class, 'words' ), $this->redact ) ) );
		$this->log_file = $this->path( isset( $options['log_file'] ) ? (string) $options['log_file'] : '' );
	}

	/**
	 * Log an error.
	 *
	 * @since 1.0.0
	 *
	 * @param string $message What happened.
	 * @param array  $context Anything that helps, written as JSON.
	 *
	 * @return void
	 */
	public function error( string $message, array $context = array() ): void {
		$this->log( $message, $context, 'ERROR' );
	}

	/**
	 * Log a warning.
	 *
	 * @since 1.0.0
	 *
	 * @param string $message What happened.
	 * @param array  $context Anything that helps.
	 *
	 * @return void
	 */
	public function warning( string $message, array $context = array() ): void {
		$this->log( $message, $context, 'WARNING' );
	}

	/**
	 * Log something worth knowing.
	 *
	 * @since 1.0.0
	 *
	 * @param string $message What happened.
	 * @param array  $context Anything that helps.
	 *
	 * @return void
	 */
	public function info( string $message, array $context = array() ): void {
		$this->log( $message, $context, 'INFO' );
	}

	/**
	 * Log detail for debugging.
	 *
	 * @since 1.0.0
	 *
	 * @param string $message What happened.
	 * @param array  $context Anything that helps.
	 *
	 * @return void
	 */
	public function debug( string $message, array $context = array() ): void {
		$this->log( $message, $context, 'DEBUG' );
	}

	/**
	 * Log an exception as an error, with its class, file, line and trace.
	 *
	 * @since 1.0.0
	 *
	 * @param Throwable $exception The exception.
	 * @param array     $context   Anything that helps.
	 *
	 * @return void
	 */
	public function exception( Throwable $exception, array $context = array() ): void {
		$this->error(
			sprintf( '[%s] %s', get_class( $exception ), $exception->getMessage() ),
			array_merge(
				$context,
				array(
					'file'  => $exception->getFile(),
					'line'  => $exception->getLine(),
					'trace' => $exception->getTraceAsString(),
				)
			)
		);
	}

	/**
	 * Log a WP_Error as an error, with its code and data.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_Error $wp_error The error.
	 * @param array    $context  Anything that helps.
	 *
	 * @return void
	 */
	public function wp_error( WP_Error $wp_error, array $context = array() ): void {
		$context['error_code'] = $wp_error->get_error_code();
		$context['error_data'] = $wp_error->get_error_data();

		$this->error( $wp_error->get_error_message(), $context );
	}

	/**
	 * Write an entry: `[2026-11-18T10:00:00+00:00] ERROR: message {"context":1}`.
	 *
	 * One entry is one line. A new line in the message is written as `\n`,
	 * so nothing a customer typed can forge an entry of its own.
	 *
	 * @since 1.0.0
	 *
	 * @param string $message What happened.
	 * @param array  $context Anything that helps.
	 * @param string $level   `DEBUG`, `INFO`, `WARNING`, `ERROR`, or a level
	 *                        of your own, written as given and filtered as
	 *                        `INFO`.
	 *
	 * @return void
	 */
	public function log( string $message, array $context = array(), string $level = 'INFO' ): void {
		$level = strtoupper( trim( $level ) );

		if ( ! $this->enabled || ! $this->writes( $level ) ) {
			return;
		}

		$context = $this->redacted( $context );

		// The message too: an exception's message is where a key a
		// provider echoed back usually turns up.
		if ( array() !== $this->redact ) {
			$message = (string) preg_replace( self::SECRET_VALUE, '[redacted]', $message );
		}
		$entry   = sprintf(
			"[%s] %s: %s%s\n",
			current_time( 'c' ),
			'' === $level ? 'INFO' : preg_replace( '/[^A-Z0-9_]/', '', $level ),
			str_replace( array( "\r\n", "\r", "\n" ), '\n', $message ),
			array() === $context ? '' : ' ' . (string) wp_json_encode( $context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR )
		);

		if ( ! $this->prepare() ) {
			return;
		}

		$this->maybe_rotate( strlen( $entry ) );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- an append under a lock, which WP_Filesystem cannot do.
		file_put_contents( $this->log_file, $entry, FILE_APPEND | LOCK_EX );
	}

	/**
	 * Whether an entry at this level would be written.
	 *
	 * @since 1.0.0
	 *
	 * @param string $level The level.
	 *
	 * @return bool
	 */
	public function writes( string $level ): bool {
		return $this->enabled && ( self::LEVELS[ strtoupper( $level ) ] ?? self::LEVELS['INFO'] ) >= self::LEVELS[ $this->level ];
	}

	/**
	 * The file written to.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function get_file(): string {
		return $this->log_file;
	}

	/**
	 * The plugin's name, as the registry and the folder know it.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function get_name(): string {
		return $this->name;
	}

	/**
	 * Whether anything is written.
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	public function is_enabled(): bool {
		return $this->enabled;
	}

	/**
	 * Start or stop writing.
	 *
	 * @since 1.0.0
	 *
	 * @param bool $enabled Whether to write.
	 *
	 * @return static
	 */
	public function set_enabled( bool $enabled ): static {
		$this->enabled = $enabled;

		return $this;
	}

	/**
	 * The quietest level written.
	 *
	 * @since 1.0.0
	 *
	 * @return string `DEBUG`, `INFO`, `WARNING` or `ERROR`.
	 */
	public function get_level(): string {
		return $this->level;
	}

	/**
	 * A level, as one of the four.
	 *
	 * @param string $level The level, in any case.
	 *
	 * @return string `DEBUG` for anything it does not know.
	 */
	private static function level( string $level ): string {
		$level = strtoupper( trim( $level ) );

		return isset( self::LEVELS[ $level ] ) ? $level : 'DEBUG';
	}

	/**
	 * Whether the plugin, or WordPress, is debugging.
	 *
	 * @return bool
	 */
	private function debugging(): bool {
		$constant = strtoupper( str_replace( '-', '_', $this->name ) ) . '_DEBUG';

		if ( defined( $constant ) ) {
			return (bool) constant( $constant );
		}

		return defined( 'WP_DEBUG' ) && WP_DEBUG;
	}
}
