<?php
/**
 * Registry
 *
 * @package   SugarCommerce\Logger
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Logger;

/**
 * Class Registry
 *
 * Every plugin's logger, by name, so anything in a plugin reaches the same
 * one.
 *
 * Static calls rather than global functions: a global function is the one
 * thing Strauss cannot prefix, so two plugins bundling different copies
 * would share whichever loaded first.
 *
 * @since 1.0.0
 */
final class Registry {

	/**
	 * The loggers, by name.
	 *
	 * @var array<string, Logger>
	 */
	private static array $loggers = array();

	/**
	 * Register a logger, or get the one already registered.
	 *
	 * The options count only the first time; the name is read as a key.
	 *
	 * @since 1.0.0
	 *
	 * @param string $name    The plugin's name.
	 * @param array  $options As Logger's constructor.
	 *
	 * @return Logger
	 */
	public static function register( string $name, array $options = array() ): Logger {
		$name = sanitize_key( $name );

		return self::$loggers[ $name ] ??= new Logger( $name, $options );
	}

	/**
	 * A registered logger.
	 *
	 * @since 1.0.0
	 *
	 * @param string $name The plugin's name.
	 *
	 * @return Logger|null
	 */
	public static function get( string $name ): ?Logger {
		return self::$loggers[ sanitize_key( $name ) ] ?? null;
	}

	/**
	 * Whether a logger is registered.
	 *
	 * @since 1.0.0
	 *
	 * @param string $name The plugin's name.
	 *
	 * @return bool
	 */
	public static function has( string $name ): bool {
		return isset( self::$loggers[ sanitize_key( $name ) ] );
	}

	/**
	 * Forget a logger. Its file stays.
	 *
	 * @since 1.0.0
	 *
	 * @param string $name The plugin's name.
	 *
	 * @return bool Whether there was one.
	 */
	public static function remove( string $name ): bool {
		$name  = sanitize_key( $name );
		$found = isset( self::$loggers[ $name ] );

		unset( self::$loggers[ $name ] );

		return $found;
	}

	/**
	 * Every logger, by name.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, Logger>
	 */
	public static function all(): array {
		return self::$loggers;
	}

	/**
	 * The names registered.
	 *
	 * @since 1.0.0
	 *
	 * @return string[]
	 */
	public static function names(): array {
		return array_keys( self::$loggers );
	}

	/**
	 * How many loggers there are.
	 *
	 * @since 1.0.0
	 *
	 * @return int
	 */
	public static function count(): int {
		return count( self::$loggers );
	}

	/**
	 * Forget every logger. Their files stay.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public static function reset(): void {
		self::$loggers = array();
	}
}
