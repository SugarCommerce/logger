<?php
/**
 * The file: where it is, keeping it private, rotating, pruning, clearing and reading it back.
 *
 * @package     SugarCommerce\Logger
 * @copyright   Copyright (c) 2026, ArrayPress Limited
 * @license     GPL-2.0-or-later
 * @since       1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Logger\Traits\Logger;

/**
 * The file: where it is, keeping it private, rotating, pruning, clearing and reading it back.
 *
 * One generation is kept beside the log as .1; the folder is guarded for
 * Apache and the file name is a salted hash for everything else.
 *
 * @since 1.0.0
 */
trait Files {

	/**
	 * Empty the log, and its previous file.
	 *
	 * @since 1.0.0
	 *
	 * @return bool False when a file could not be removed.
	 */
	public function clear(): bool {
		$cleared = true;

		foreach ( array( $this->log_file, $this->log_file . '.1' ) as $file ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- the plugin's own log.
			if ( file_exists( $file ) && ! unlink( $file ) ) {
				$cleared = false;
			}
		}

		return $cleared;
	}

	/**
	 * Remove the entries older than a number of days, from the log and its
	 * previous file.
	 *
	 * For a log that holds what customers typed: kept for as long as it is
	 * useful, not for as long as nobody happens to clear it. Rotation keeps
	 * the size down; this keeps the age down. A previous file left with
	 * nothing is removed. A line that does not start with a time is kept.
	 *
	 * @since 1.0.0
	 *
	 * @param int $days Entries this many days old or more go. Below one,
	 *                  nothing is removed.
	 *
	 * @return int How many entries were removed.
	 */
	public function prune( int $days ): int {
		if ( $days < 1 ) {
			return 0;
		}

		$before  = time() - $days * DAY_IN_SECONDS;
		$removed = 0;

		foreach ( array( $this->log_file . '.1', $this->log_file ) as $file ) {
			$removed += self::prune_file( $file, $before );
		}

		return $removed;
	}

	/**
	 * Remove the entries written before a moment from one file, under a lock.
	 *
	 * @param string $file   The file.
	 * @param int    $before The moment, as a timestamp.
	 *
	 * @return int How many entries were removed.
	 */
	private static function prune_file( string $file, int $before ): int {
		if ( ! is_file( $file ) || ! is_writable( $file ) ) {
			return 0;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- read and rewritten under one lock, which WP_Filesystem cannot do.
		$handle = fopen( $file, 'c+' );

		if ( false === $handle ) {
			return 0;
		}

		$removed = 0;

		try {
			if ( ! flock( $handle, LOCK_EX ) ) {
				return 0;
			}

			$kept = '';

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- see above.
			while ( false !== ( $line = fgets( $handle ) ) ) { // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition -- line by line.
				if ( 1 === preg_match( '/^\[([^\]]+)\]/', $line, $time ) ) {
					$at = strtotime( $time[1] );

					if ( false !== $at && $at < $before ) {
						++$removed;
						continue;
					}
				}

				$kept .= $line;
			}

			if ( 0 === $removed ) {
				return 0;
			}

			ftruncate( $handle, 0 );
			rewind( $handle );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- see above.
			fwrite( $handle, $kept );
			fflush( $handle );
			flock( $handle, LOCK_UN );
		} finally {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- see above.
			fclose( $handle );
		}

		if ( '' === $kept && str_ends_with( $file, '.1' ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- the plugin's own log, emptied.
			unlink( $file );
		}

		return $removed;
	}

	/**
	 * What the log holds.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function get_contents(): string {
		if ( ! is_readable( $this->log_file ) ) {
			return '';
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local file.
		return (string) file_get_contents( $this->log_file );
	}

	/**
	 * The last entries, newest last.
	 *
	 * @since 1.0.0
	 *
	 * @param int $count How many.
	 *
	 * @return string[]
	 */
	public function get_entries( int $count = 100 ): array {
		$lines = preg_split( '/\R/', rtrim( $this->get_contents() ) );
		$lines = array_values( array_filter( (array) $lines, fn ( $line ) => '' !== $line ) );

		return $count > 0 ? array_slice( $lines, -$count ) : array();
	}

	/**
	 * The file to write to.
	 *
	 * @param string $custom A file name or a path, or empty for the default.
	 *
	 * @return string
	 */
	private function path( string $custom ): string {
		$custom = trim( $custom );

		if ( '' !== $custom && ( str_contains( $custom, '/' ) || str_contains( $custom, '\\' ) ) ) {
			return $custom;
		}

		$folder = trailingslashit( (string) ( wp_upload_dir( null, false )['basedir'] ?? '' ) ) . $this->name . '/';

		// A name alone goes in the plugin's folder. With no name the hash is
		// what keeps the log private: wp_hash() is salted per site.
		return $folder . ( '' !== $custom ? sanitize_file_name( $custom ) : $this->name . '-' . wp_hash( $this->name ) . '.log' );
	}

	/**
	 * Make the folder, and keep it from being listed or served where the
	 * server listens.
	 *
	 * @return bool Whether the folder is there to write to.
	 */
	private function prepare(): bool {
		if ( $this->ready ) {
			return true;
		}

		$dir = dirname( $this->log_file );

		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			return false;
		}

		$files = array(
			// Apache 2.4 and 2.2 both; nginx reads neither, which is why the
			// file name is a secret too.
			'.htaccess' => "<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n\tDeny from all\n</IfModule>\n",
			'index.php' => "<?php\n// Silence is golden.\n",
		);

		foreach ( $files as $file => $contents ) {
			$path = trailingslashit( $dir ) . $file;

			if ( ! file_exists( $path ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- a guard file beside the log.
				file_put_contents( $path, $contents );
			}
		}

		$this->ready = true;

		return true;
	}

	/**
	 * Rotate when the next entry would take the log over its size, keeping
	 * one previous file as `.1`.
	 *
	 * @param int $incoming Bytes about to be written.
	 *
	 * @return void
	 */
	private function maybe_rotate( int $incoming ): void {
		if ( 0 === $this->max_size || ! file_exists( $this->log_file ) ) {
			return;
		}

		clearstatcache( true, $this->log_file );
		$size = filesize( $this->log_file );

		if ( false === $size || $size + $incoming <= $this->max_size ) {
			return;
		}

		// rename() replaces the older .1, so only one generation is kept.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- the plugin's own log.
		rename( $this->log_file, $this->log_file . '.1' );
	}
}
