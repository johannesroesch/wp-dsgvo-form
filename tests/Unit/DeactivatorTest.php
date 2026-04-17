<?php
/**
 * Unit tests for Deactivator class.
 *
 * @package WpDsgvoForm\Tests\Unit
 */

declare(strict_types=1);

namespace WpDsgvoForm\Tests\Unit;

use WpDsgvoForm\Deactivator;
use WpDsgvoForm\Tests\TestCase;
use Brain\Monkey\Functions;

/**
 * Tests for plugin deactivation logic.
 */
class DeactivatorTest extends TestCase {

	/**
	 * @test
	 */
	public function test_deactivate_unschedules_cron_when_scheduled(): void {
		$timestamp = 1713350400;

		Functions\expect( 'wp_next_scheduled' )
			->once()
			->with( 'dsgvo_form_cleanup' )
			->andReturn( $timestamp );

		Functions\expect( 'wp_unschedule_event' )
			->once()
			->with( $timestamp, 'dsgvo_form_cleanup' );

		$wpdb          = \Mockery::mock( 'wpdb' );
		$wpdb->options = 'wp_options';
		$wpdb->shouldReceive( 'prepare' )->andReturn( '' );
		$wpdb->shouldReceive( 'query' )->andReturn( 0 );
		$wpdb->shouldReceive( 'esc_like' )->andReturnUsing(
			function ( string $text ): string {
				return $text;
			}
		);
		$GLOBALS['wpdb'] = $wpdb;

		Deactivator::deactivate();
	}

	/**
	 * @test
	 */
	public function test_deactivate_skips_unschedule_when_no_cron(): void {
		Functions\expect( 'wp_next_scheduled' )
			->once()
			->with( 'dsgvo_form_cleanup' )
			->andReturn( false );

		Functions\expect( 'wp_unschedule_event' )->never();

		$wpdb          = \Mockery::mock( 'wpdb' );
		$wpdb->options = 'wp_options';
		$wpdb->shouldReceive( 'prepare' )->andReturn( '' );
		$wpdb->shouldReceive( 'query' )->andReturn( 0 );
		$wpdb->shouldReceive( 'esc_like' )->andReturnUsing(
			function ( string $text ): string {
				return $text;
			}
		);
		$GLOBALS['wpdb'] = $wpdb;

		Deactivator::deactivate();
	}

	/**
	 * @test
	 */
	public function test_deactivate_cleans_rate_limit_transients(): void {
		Functions\expect( 'wp_next_scheduled' )
			->once()
			->with( 'dsgvo_form_cleanup' )
			->andReturn( false );

		$wpdb          = \Mockery::mock( 'wpdb' );
		$wpdb->options = 'wp_options';

		$wpdb->shouldReceive( 'esc_like' )
			->andReturnUsing(
				function ( string $text ): string {
					return $text;
				}
			);

		$wpdb->shouldReceive( 'prepare' )
			->once()
			->andReturnUsing(
				function ( string $query, string ...$args ) {
					$this->assertStringContainsString( '_transient_dsgvo_rate_', $args[0] );
					$this->assertStringContainsString( '_transient_timeout_dsgvo_rate_', $args[1] );
					return $query;
				}
			);

		$wpdb->shouldReceive( 'query' )
			->once()
			->andReturn( 3 );

		$GLOBALS['wpdb'] = $wpdb;

		Deactivator::deactivate();
	}
}
