<?php
/**
 * Base test case for all unit tests.
 *
 * Sets up and tears down Brain Monkey so that WordPress function stubs are
 * available inside every test and cleaned up afterwards.
 *
 * @package Kntnt\Photo_Drop
 * @since   0.1.0
 */

declare( strict_types = 1 );

namespace Tests\Unit;

use Brain\Monkey;
use PHPUnit\Framework\TestCase as BaseTestCase;

/**
 * PHPUnit base class extended with Brain Monkey lifecycle hooks.
 *
 * All unit tests in tests/Unit/ use this as their base via the Pest.php
 * uses() binding. Brain Monkey::setUp() replaces WordPress functions with
 * Mockery stubs; Brain Monkey::tearDown() verifies expectations and cleans up.
 *
 * @since 0.1.0
 */
class TestCase extends BaseTestCase {

	/**
	 * Initialises Brain Monkey before each test.
	 *
	 * @since 0.1.0
	 */
	protected function setUp(): void {

		parent::setUp();
		Monkey\setUp();
	}

	/**
	 * Tears down Brain Monkey and Mockery after each test.
	 *
	 * @since 0.1.0
	 */
	protected function tearDown(): void {

		Monkey\tearDown();
		parent::tearDown();
	}
}
