<?php
/**
 * Redaction: secret-named keys and secret-shaped values kept out of the log.
 *
 * @package     SugarCommerce\Logger
 * @copyright   Copyright (c) 2026, ArrayPress Limited
 * @license     GPL-2.0-or-later
 * @since       1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Logger\Traits\Logger;

/**
 * Redaction: secret-named keys and secret-shaped values kept out of the log.
 *
 * A key is matched word by word, so client_secret and refreshToken are
 * caught and tokenizer is not.
 *
 * @since 1.0.0
 */
trait Redaction {

	/**
	 * Context with the keys nobody should keep written as `[redacted]`, and
	 * any value that is plainly a secret key.
	 *
	 * @param array $context The context.
	 *
	 * @return array
	 */
	private function redacted( array $context ): array {
		if ( array() === $this->redact ) {
			return $context;
		}

		foreach ( $context as $key => $value ) {
			if ( $this->is_redacted( (string) $key ) ) {
				$context[ $key ] = '[redacted]';
			} elseif ( is_array( $value ) ) {
				$context[ $key ] = $this->redacted( $value );
			} elseif ( $value instanceof \stdClass ) {
				// A decoded API answer or a row: its properties are keys too.
				$context[ $key ] = (object) $this->redacted( (array) $value );
			} elseif ( is_string( $value ) ) {
				$context[ $key ] = (string) preg_replace( self::SECRET_VALUE, '[redacted]', $value );
			}
		}

		return $context;
	}

	/**
	 * Whether a context key names something redacted: one of the redacted
	 * keys appears among its words, in order.
	 *
	 * @param string $key A context key.
	 *
	 * @return bool
	 */
	private function is_redacted( string $key ): bool {
		$words = self::words( $key );
		$count = count( $words );

		foreach ( $this->redact_words as $redact ) {
			$length = count( $redact );

			for ( $i = 0; $i + $length <= $count; $i++ ) {
				if ( array_slice( $words, $i, $length ) === $redact ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * A key's words, lower case: split at anything not a letter or digit
	 * and where camelCase turns upper.
	 *
	 * @param string $key A key.
	 *
	 * @return string[]
	 */
	private static function words( string $key ): array {
		$key = (string) preg_replace( '/([a-z0-9])([A-Z])/', '$1_$2', $key );

		return array_values( array_filter( (array) preg_split( '/[^a-z0-9]+/', strtolower( $key ) ), 'strlen' ) );
	}
}
