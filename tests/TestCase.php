<?php
/**
 * Base test case for unit tests using Brain\Monkey.
 *
 * All unit tests should extend this class to get automatic
 * setup/teardown of Brain\Monkey WordPress function mocking.
 *
 * @package WpDsgvoForm\Tests
 */

declare(strict_types=1);

namespace WpDsgvoForm\Tests;

use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase as PHPUnitTestCase;

/**
 * Abstract base test case with Brain\Monkey integration.
 */
abstract class TestCase extends PHPUnitTestCase {

	use MockeryPHPUnitIntegration;

	/**
	 * Set up Brain\Monkey before each test.
	 */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		// Auto-stub WordPress escaping functions for all Brain\Monkey tests.
		// Production code (Task #260) uses esc_html()/esc_attr() in exception
		// messages and output; these stubs return the input unchanged.
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
	}

	/**
	 * Tear down Brain\Monkey after each test.
	 */
	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}
}
