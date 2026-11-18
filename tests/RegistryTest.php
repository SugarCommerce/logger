<?php
/**
 * Registry tests.
 *
 * @package SugarCommerce\Logger
 */

declare( strict_types=1 );

namespace SugarCommerce\Logger\Tests;

use PHPUnit\Framework\TestCase;
use SugarCommerce\Logger\Registry;

/**
 * One logger per name.
 */
final class RegistryTest extends TestCase {

	/**
	 * @return void
	 */
	protected function setUp(): void {
		Registry::reset();
	}

	/**
	 * Registering twice gives the first, its options kept; names are keys.
	 *
	 * @return void
	 */
	public function test_register_once(): void {
		$first  = Registry::register( 'My Shop', array( 'enabled' => true ) );
		$second = Registry::register( 'myshop', array( 'enabled' => false ) );

		$this->assertSame( $first, $second );
		$this->assertTrue( $second->is_enabled() );
		$this->assertSame( 'myshop', $first->get_name() );
		$this->assertSame( $first, Registry::get( 'MYSHOP' ) );
		$this->assertTrue( Registry::has( 'myshop' ) );
	}

	/**
	 * Listing, counting and forgetting.
	 *
	 * @return void
	 */
	public function test_list_and_remove(): void {
		Registry::register( 'one', array( 'enabled' => false ) );
		Registry::register( 'two', array( 'enabled' => false ) );

		$this->assertSame( array( 'one', 'two' ), Registry::names() );
		$this->assertSame( 2, Registry::count() );
		$this->assertCount( 2, Registry::all() );

		$this->assertTrue( Registry::remove( 'one' ) );
		$this->assertFalse( Registry::remove( 'one' ) );
		$this->assertNull( Registry::get( 'one' ) );
		$this->assertFalse( Registry::has( 'one' ) );

		Registry::reset();
		$this->assertSame( 0, Registry::count() );
	}
}
