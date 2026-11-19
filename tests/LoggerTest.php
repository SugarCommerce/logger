<?php
/**
 * Logger tests.
 *
 * @package SugarCommerce\Logger
 */

declare( strict_types=1 );

namespace SugarCommerce\Logger\Tests;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use SugarCommerce\Logger\Logger;
use WP_Error;

/**
 * Writing, levels, the file's name and place, rotation and redaction.
 */
final class LoggerTest extends TestCase {

	/**
	 * @return void
	 */
	protected function setUp(): void {
		lg_remove( $GLOBALS['lg_uploads'] );
	}

	/**
	 * A logger that writes.
	 *
	 * @param array $options More options.
	 *
	 * @return Logger
	 */
	private function logger( array $options = array() ): Logger {
		return new Logger( 'My-Shop', $options + array( 'enabled' => true ) );
	}

	/**
	 * Each level writes its own line.
	 *
	 * @return void
	 */
	public function test_levels(): void {
		$log = $this->logger();

		$log->error( 'One' );
		$log->warning( 'Two' );
		$log->info( 'Three', array( 'order_id' => 1001 ) );
		$log->debug( 'Four' );
		$log->log( 'Five', array(), 'notice' );

		$this->assertSame(
			array(
				'[2026-11-18T10:00:00+00:00] ERROR: One',
				'[2026-11-18T10:00:00+00:00] WARNING: Two',
				'[2026-11-18T10:00:00+00:00] INFO: Three {"order_id":1001}',
				'[2026-11-18T10:00:00+00:00] DEBUG: Four',
				'[2026-11-18T10:00:00+00:00] NOTICE: Five',
			),
			$log->get_entries()
		);
		$this->assertSame( array( '[2026-11-18T10:00:00+00:00] NOTICE: Five' ), $log->get_entries( 1 ) );
	}

	/**
	 * The file lives in the plugin's folder in uploads, named with a salted
	 * hash, beside guard files -- made on the first write, not before.
	 *
	 * @return void
	 */
	public function test_file_is_private(): void {
		$log    = $this->logger();
		$folder = $GLOBALS['lg_uploads'] . '/my-shop/';

		$this->assertSame( $folder . 'my-shop-' . wp_hash( 'my-shop' ) . '.log', $log->get_file() );
		$this->assertDirectoryDoesNotExist( $folder );

		$log->info( 'Hello' );

		$this->assertFileExists( $log->get_file() );
		$this->assertStringContainsString( 'Require all denied', (string) file_get_contents( $folder . '.htaccess' ) );
		$this->assertStringContainsString( 'Deny from all', (string) file_get_contents( $folder . '.htaccess' ) );
		$this->assertFileExists( $folder . 'index.php' );
	}

	/**
	 * A file name goes in the plugin's folder; a path is used as given.
	 *
	 * @return void
	 */
	public function test_custom_file(): void {
		$this->assertSame( $GLOBALS['lg_uploads'] . '/my-shop/debug.log', $this->logger( array( 'log_file' => 'debug.log' ) )->get_file() );

		$path = $GLOBALS['lg_uploads'] . '/elsewhere/x.log';
		$log  = $this->logger( array( 'log_file' => $path ) );
		$log->error( 'Here' );

		$this->assertStringContainsString( 'ERROR: Here', (string) file_get_contents( $path ) );
	}

	/**
	 * Off writes nothing, and touches nothing on disk.
	 *
	 * @return void
	 */
	public function test_disabled_writes_nothing(): void {
		$log = new Logger( 'quiet-plugin', array( 'enabled' => false ) );
		$log->error( 'Nope' );

		$this->assertFalse( $log->is_enabled() );
		$this->assertSame( '', $log->get_contents() );
		$this->assertDirectoryDoesNotExist( $GLOBALS['lg_uploads'] . '/quiet-plugin' );

		$log->set_enabled( true )->error( 'Now' );
		$this->assertCount( 1, $log->get_entries() );
	}

	/**
	 * Without an option, the plugin's own constant decides, then WP_DEBUG.
	 *
	 * @return void
	 */
	public function test_debug_constant(): void {
		define( 'CONSTANT_ON_DEBUG', true );
		define( 'CONSTANT_OFF_DEBUG', false );

		$this->assertTrue( ( new Logger( 'constant-on' ) )->is_enabled() );
		$this->assertFalse( ( new Logger( 'constant-off' ) )->is_enabled() );
		$this->assertSame( defined( 'WP_DEBUG' ) && WP_DEBUG, ( new Logger( 'no-constant' ) )->is_enabled() );
	}

	/**
	 * Below the level, nothing is written; a level of your own counts as
	 * info.
	 *
	 * @return void
	 */
	public function test_minimum_level(): void {
		$log = $this->logger( array( 'level' => 'warning' ) );

		$log->debug( 'No' );
		$log->info( 'No' );
		$log->log( 'No', array(), 'notice' );
		$log->warning( 'Yes' );
		$log->error( 'Yes' );

		$this->assertCount( 2, $log->get_entries() );
		$this->assertSame( 'WARNING', $log->get_level() );
		$this->assertFalse( $log->writes( 'info' ) );
		$this->assertTrue( $log->writes( 'ERROR' ) );
		$this->assertSame( 'DEBUG', $this->logger( array( 'level' => 'loud' ) )->get_level() );
	}

	/**
	 * An exception keeps its class, file, line and trace; a WP_Error its
	 * code and data.
	 *
	 * @return void
	 */
	public function test_exception_and_wp_error(): void {
		$log = $this->logger();

		$log->exception( new RuntimeException( 'Gateway down' ), array( 'order_id' => 7 ) );
		$log->wp_error( new WP_Error( 'http_failed', 'Timed out', array( 'status' => 504 ) ) );

		$entries = $log->get_entries();
		$this->assertStringContainsString( 'ERROR: [RuntimeException] Gateway down {"order_id":7,"file":"', $entries[0] );
		$this->assertStringContainsString( '"trace":"', $entries[0] );
		$this->assertStringEndsWith( 'ERROR: Timed out {"error_code":"http_failed","error_data":{"status":504}}', $entries[1] );
	}

	/**
	 * A new line in a message cannot forge an entry.
	 *
	 * @return void
	 */
	public function test_one_entry_one_line(): void {
		$log = $this->logger();

		$log->info( "Name: Bob\n[2026-11-18T10:00:00+00:00] ERROR: forged" );

		$this->assertSame( array( '[2026-11-18T10:00:00+00:00] INFO: Name: Bob\n[2026-11-18T10:00:00+00:00] ERROR: forged' ), $log->get_entries() );
	}

	/**
	 * Secrets in the context are left out, however deep; invalid UTF-8
	 * does not lose the entry.
	 *
	 * @return void
	 */
	public function test_redaction(): void {
		$log = $this->logger();

		$log->info(
			'Charge',
			array(
				'Password' => 'hunter2',
				'request'  => array(
					'headers' => array( 'Authorization' => 'Bearer x' ),
					'amount'  => 100,
				),
				'note'     => "bad \xC3",
			)
		);

		$entry = $log->get_entries()[0];
		$this->assertStringNotContainsString( 'hunter2', $entry );
		$this->assertStringNotContainsString( 'Bearer', $entry );
		$this->assertStringContainsString( '"Password":"[redacted]"', $entry );
		$this->assertStringContainsString( '"amount":100', $entry );
		$this->assertStringContainsString( '"note":"bad', $entry );

		$all = $this->logger( array( 'redact' => array() ) );
		$all->clear();
		$all->info( 'Kept', array( 'token' => 'abc' ) );
		$this->assertStringContainsString( '"token":"abc"', $all->get_entries()[0] );
	}

	/**
	 * A redacted key is matched as a word of the context key, so the
	 * secrets payment and OAuth code actually logs are left out -- and a
	 * value shaped like a Stripe secret is left out whatever its key.
	 *
	 * @return void
	 */
	public function test_redaction_matches_words_of_the_key(): void {
		$log  = $this->logger();
		$key  = 'sk_' . 'live_' . str_repeat( 'a1B2', 6 );
		$hook = 'whsec_' . str_repeat( 'Z9', 8 );

		$log->info(
			'Connect',
			array(
				'client_secret'     => 'cs-one',
				'access_token'      => 'at-two',
				'refreshToken'      => 'rt-three',
				'secret_key'        => 'sk-four',
				'stripe_secret_key' => 'sk-five',
				'X-Api-Key'         => 'ak-six',
				'stripe_key'        => $key,
				'settings'          => array( 'webhook' => $hook ),
				'note'              => 'used ' . $key . ' today',
				'passenger'         => 'Ada',
				'tokenizer'         => 'wordpiece',
				'stripe_customer'   => 'cus_123',
			)
		);

		$entry = $log->get_entries()[0];
		foreach ( array( 'cs-one', 'at-two', 'rt-three', 'sk-four', 'sk-five', 'ak-six', $key, $hook ) as $secret ) {
			$this->assertStringNotContainsString( $secret, $entry );
		}
		$this->assertStringContainsString( '"client_secret":"[redacted]"', $entry );
		$this->assertStringContainsString( '"note":"used [redacted] today"', $entry );
		$this->assertStringContainsString( '"passenger":"Ada"', $entry );
		$this->assertStringContainsString( '"tokenizer":"wordpiece"', $entry );
		$this->assertStringContainsString( '"stripe_customer":"cus_123"', $entry );

		// A list of one's own is matched the same way.
		$own = $this->logger( array( 'redact' => array( 'iban' ) ) );
		$own->clear();
		$own->info( 'Payout', array( 'customer_iban' => 'GB00', 'token' => 'kept' ) );
		$this->assertStringNotContainsString( 'GB00', $own->get_entries()[0] );
		$this->assertStringContainsString( '"token":"kept"', $own->get_entries()[0] );
	}

	/**
	 * Past the size limit the log moves to .1, replacing the older one;
	 * clear() removes both.
	 *
	 * @return void
	 */
	public function test_rotation_and_clear(): void {
		$log = $this->logger( array( 'max_size' => 100 ) );

		$log->info( str_repeat( 'a', 40 ) );
		$log->info( str_repeat( 'b', 40 ) );
		$this->assertFileExists( $log->get_file() . '.1' );
		$this->assertCount( 1, $log->get_entries() );

		$log->info( str_repeat( 'c', 40 ) );
		$this->assertStringContainsString( 'bbbb', (string) file_get_contents( $log->get_file() . '.1' ) );

		$this->assertTrue( $log->clear() );
		$this->assertFileDoesNotExist( $log->get_file() );
		$this->assertFileDoesNotExist( $log->get_file() . '.1' );
		$this->assertSame( array(), $log->get_entries() );
	}

	/**
	 * Zero never rotates.
	 *
	 * @return void
	 */
	public function test_no_rotation(): void {
		$log = $this->logger( array( 'max_size' => 0 ) );

		for ( $i = 0; $i < 5; $i++ ) {
			$log->info( str_repeat( 'x', 60 ) );
		}

		$this->assertFileDoesNotExist( $log->get_file() . '.1' );
		$this->assertCount( 5, $log->get_entries() );
	}

	/**
	 * prune() takes out what is older than the days given, from both files,
	 * keeps a line with no time, and removes a previous file left empty.
	 *
	 * @return void
	 */
	public function test_prune(): void {
		$log  = $this->logger( array( 'max_size' => 0 ) );
		$at   = static fn( int $days_ago ): string => gmdate( 'c', time() - $days_ago * DAY_IN_SECONDS - 60 );
		$file = $log->get_file();

		$log->info( 'Makes the folder' );

		file_put_contents( $file, "[{$at( 40 )}] INFO: Old\n[{$at( 30 )}] DEBUG: Thirty\nno time at all\n[{$at( 29 )}] INFO: Recent\n[{$at( 0 )}] ERROR: Today\n" );
		file_put_contents( $file . '.1', "[{$at( 90 )}] INFO: Very old\n[{$at( 31 )}] INFO: Also old\n" );

		$this->assertSame( 0, $log->prune( 0 ), 'Below one day, nothing goes.' );
		$this->assertSame( 4, $log->prune( 30 ) );
		$this->assertSame( "no time at all\n[{$at( 29 )}] INFO: Recent\n[{$at( 0 )}] ERROR: Today\n", (string) file_get_contents( $file ) );
		$this->assertFileDoesNotExist( $file . '.1' );
		$this->assertSame( 0, $log->prune( 30 ), 'Nothing more to take.' );

		$this->assertSame( 1, $log->prune( 1 ) );
		$this->assertSame( array( 'no time at all', "[{$at( 0 )}] ERROR: Today" ), $log->get_entries() );
	}
}
