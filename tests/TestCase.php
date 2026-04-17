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
	}

	/**
	 * Tear down Brain\Monkey after each test.
	 */
	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}
}
