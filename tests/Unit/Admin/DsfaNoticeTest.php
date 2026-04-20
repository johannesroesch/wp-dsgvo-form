<?php
/**
 * Unit tests for DsfaNotice class.
 *
 * LEGAL-F04: DSFA recommendation notice when form/submission
 * volume exceeds Art. 35 DSGVO thresholds.
 *
 * @package WpDsgvoForm\Tests\Unit\Admin
 */

declare(strict_types=1);

namespace WpDsgvoForm\Tests\Unit\Admin;

use WpDsgvoForm\Admin\DsfaNotice;
use WpDsgvoForm\Tests\TestCase;
use Brain\Monkey\Functions;
use Brain\Monkey\Actions;

/**
 * Tests for DsfaNotice admin notice and AJAX dismissal.
 */
class DsfaNoticeTest extends TestCase {

	private DsfaNotice $notice;

	protected function setUp(): void {
		parent::setUp();
		$this->notice = new DsfaNotice();
	}

	// ------------------------------------------------------------------
	// Helpers
	// ------------------------------------------------------------------

	/**
	 * Sets up a mock $wpdb that returns given counts.
	 *
	 * @param int $form_count       Form count to return.
	 * @param int $submission_count  Submission count to return.
	 */
	private function mock_wpdb_counts( int $form_count, int $submission_count ): void {
		$wpdb         = \Mockery::mock( 'wpdb' );
		$wpdb->prefix = 'wp_';

		// get_var is called first for forms, then (if needed) for submissions.
		$wpdb->shouldReceive( 'get_var' )
			->andReturn( (string) $form_count, (string) $submission_count );

		$GLOBALS['wpdb'] = $wpdb;
	}

	/**
	 * Sets up the standard stubs for a maybe_show_notice call.
	 *
	 * @param bool        $can_manage   Whether user has manage_options.
	 * @param bool|string $dismissed    Value of dismiss user meta (false = not dismissed).
	 * @param string      $screen_id    WP_Screen id.
	 */
	private function stub_notice_prerequisites(
		bool $can_manage = true,
		$dismissed = false,
		string $screen_id = 'toplevel_page_dsgvo-form'
	): void {
		Functions\when( 'current_user_can' )->alias(
			function ( string $cap ) use ( $can_manage ): bool {
				return $cap === 'manage_options' ? $can_manage : false;
			}
		);

		Functions\when( 'get_current_user_id' )->justReturn( 1 );

		Functions\when( 'get_user_meta' )->alias(
			function ( int $uid, string $key, bool $single ) use ( $dismissed ) {
				if ( $key === 'wpdsgvo_dsfa_notice_dismissed' && $single ) {
					return $dismissed;
				}
				return '';
			}
		);

		$screen     = new \WP_Screen();
		$screen->id = $screen_id;

		Functions\when( 'get_current_screen' )->justReturn( $screen );

		// Output helpers.
		Functions\when( 'esc_html_e' )->alias( function ( string $text ): void {
			echo $text;
		} );
		Functions\when( 'esc_js' )->returnArg();
		Functions\when( 'wp_create_nonce' )->justReturn( 'test-nonce-123' );
	}

	// ------------------------------------------------------------------
	// maybe_show_notice() — Capability
	// ------------------------------------------------------------------

	/**
	 * @test
	 * Users without manage_options see nothing.
	 */
	public function test_notice_hidden_without_manage_options(): void {
		$this->stub_notice_prerequisites( can_manage: false );

		ob_start();
		$this->notice->maybe_show_notice();
		$output = ob_get_clean();

		$this->assertEmpty( $output );
	}

	/**
	 * @test
	 * Admin with manage_options sees notice when thresholds exceeded.
	 */
	public function test_notice_shown_for_admin_with_manage_options(): void {
		$this->stub_notice_prerequisites();
		$this->mock_wpdb_counts( 6, 0 ); // >5 forms

		ob_start();
		$this->notice->maybe_show_notice();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'notice-warning', $output );
		$this->assertStringContainsString( 'Art. 35 DSGVO', $output );
	}

	// ------------------------------------------------------------------
	// maybe_show_notice() — Dismissal
	// ------------------------------------------------------------------

	/**
	 * @test
	 * Already-dismissed notice is not shown.
	 */
	public function test_notice_hidden_when_already_dismissed(): void {
		$this->stub_notice_prerequisites( dismissed: '1' );

		ob_start();
		$this->notice->maybe_show_notice();
		$output = ob_get_clean();

		$this->assertEmpty( $output );
	}

	// ------------------------------------------------------------------
	// maybe_show_notice() — Screen filtering
	// ------------------------------------------------------------------

	/**
	 * @test
	 * Notice only on plugin pages (screen id contains 'dsgvo-form').
	 */
	public function test_notice_hidden_on_non_plugin_screen(): void {
		$this->stub_notice_prerequisites( screen_id: 'dashboard' );

		ob_start();
		$this->notice->maybe_show_notice();
		$output = ob_get_clean();

		$this->assertEmpty( $output );
	}

	/**
	 * @test
	 * Notice shown on submission page (contains 'dsgvo-form').
	 */
	public function test_notice_shown_on_submission_screen(): void {
		$this->stub_notice_prerequisites( screen_id: 'admin_page_dsgvo-form-submissions' );
		$this->mock_wpdb_counts( 0, 1001 ); // >1000 submissions

		ob_start();
		$this->notice->maybe_show_notice();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'wpdsgvo-dsfa-notice', $output );
	}

	/**
	 * @test
	 * Null screen (e.g. AJAX context) hides notice.
	 */
	public function test_notice_hidden_when_screen_is_null(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'get_current_user_id' )->justReturn( 1 );
		Functions\when( 'get_user_meta' )->justReturn( false );
		Functions\when( 'get_current_screen' )->justReturn( null );

		ob_start();
		$this->notice->maybe_show_notice();
		$output = ob_get_clean();

		$this->assertEmpty( $output );
	}

	// ------------------------------------------------------------------
	// maybe_show_notice() — Thresholds
	// ------------------------------------------------------------------

	/**
	 * @test
	 * Form count > 5 triggers notice.
	 */
	public function test_notice_shown_when_form_count_exceeds_threshold(): void {
		$this->stub_notice_prerequisites();
		$this->mock_wpdb_counts( 6, 0 );

		ob_start();
		$this->notice->maybe_show_notice();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'DSFA-Empfehlung', $output );
	}

	/**
	 * @test
	 * Form count exactly 5 does NOT trigger (threshold is >5).
	 */
	public function test_notice_hidden_when_form_count_at_threshold(): void {
		$this->stub_notice_prerequisites();
		$this->mock_wpdb_counts( 5, 500 );

		ob_start();
		$this->notice->maybe_show_notice();
		$output = ob_get_clean();

		$this->assertEmpty( $output );
	}

	/**
	 * @test
	 * Submission count > 1000 triggers notice (even with few forms).
	 */
	public function test_notice_shown_when_submission_count_exceeds_threshold(): void {
		$this->stub_notice_prerequisites();
		$this->mock_wpdb_counts( 3, 1001 );

		ob_start();
		$this->notice->maybe_show_notice();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'DSFA-Empfehlung', $output );
	}

	/**
	 * @test
	 * Submission count exactly 1000 does NOT trigger (threshold is >1000).
	 */
	public function test_notice_hidden_when_submission_count_at_threshold(): void {
		$this->stub_notice_prerequisites();
		$this->mock_wpdb_counts( 3, 1000 );

		ob_start();
		$this->notice->maybe_show_notice();
		$output = ob_get_clean();

		$this->assertEmpty( $output );
	}

	/**
	 * @test
	 * Both below thresholds — no notice.
	 */
	public function test_notice_hidden_when_both_below_threshold(): void {
		$this->stub_notice_prerequisites();
		$this->mock_wpdb_counts( 2, 100 );

		ob_start();
		$this->notice->maybe_show_notice();
		$output = ob_get_clean();

		$this->assertEmpty( $output );
	}

	/**
	 * @test
	 * Both above thresholds — notice still shown (only need one).
	 */
	public function test_notice_shown_when_both_exceed_thresholds(): void {
		$this->stub_notice_prerequisites();
		$this->mock_wpdb_counts( 10, 5000 );

		ob_start();
		$this->notice->maybe_show_notice();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'DSFA-Empfehlung', $output );
	}

	// ------------------------------------------------------------------
	// maybe_show_notice() — Output format
	// ------------------------------------------------------------------

	/**
	 * @test
	 * Notice has correct CSS classes.
	 */
	public function test_notice_output_has_correct_classes(): void {
		$this->stub_notice_prerequisites();
		$this->mock_wpdb_counts( 6, 0 );

		ob_start();
		$this->notice->maybe_show_notice();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'notice notice-warning is-dismissible', $output );
		$this->assertStringContainsString( 'id="wpdsgvo-dsfa-notice"', $output );
	}

	/**
	 * @test
	 * Notice contains dismiss AJAX script with nonce.
	 */
	public function test_notice_output_contains_dismiss_script(): void {
		$this->stub_notice_prerequisites();
		$this->mock_wpdb_counts( 6, 0 );

		ob_start();
		$this->notice->maybe_show_notice();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'wpdsgvo_dismiss_dsfa_notice', $output );
		$this->assertStringContainsString( 'test-nonce-123', $output );
	}

	/**
	 * @test
	 * Notice mentions Datenschutzbeauftragten.
	 */
	public function test_notice_mentions_dpo(): void {
		$this->stub_notice_prerequisites();
		$this->mock_wpdb_counts( 6, 0 );

		ob_start();
		$this->notice->maybe_show_notice();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Datenschutzbeauftragten', $output );
	}

	// ------------------------------------------------------------------
	// handle_dismiss() — AJAX
	// ------------------------------------------------------------------

	/**
	 * @test
	 * Successful dismiss: nonce valid + manage_options → update_user_meta.
	 */
	public function test_handle_dismiss_saves_user_meta(): void {
		Functions\expect( 'check_ajax_referer' )
			->once()
			->with( 'wpdsgvo_dismiss_dsfa', '_wpnonce' );

		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'get_current_user_id' )->justReturn( 42 );

		$meta_saved = false;
		Functions\expect( 'update_user_meta' )
			->once()
			->with( 42, 'wpdsgvo_dsfa_notice_dismissed', 1 )
			->andReturnUsing( function () use ( &$meta_saved ): bool {
				$meta_saved = true;
				return true;
			} );

		$wp_died = false;
		Functions\when( 'wp_die' )->alias( function () use ( &$wp_died ): void {
			$wp_died = true;
			throw new \RuntimeException( 'wp_die' );
		} );

		try {
			$this->notice->handle_dismiss();
		} catch ( \RuntimeException $e ) {
			// Expected — wp_die throws.
		}

		$this->assertTrue( $meta_saved );
		$this->assertTrue( $wp_died );
	}

	/**
	 * @test
	 * Dismiss without manage_options returns 403.
	 */
	public function test_handle_dismiss_rejects_without_capability(): void {
		Functions\expect( 'check_ajax_referer' )->once();

		Functions\when( 'current_user_can' )->justReturn( false );

		$response_code = null;
		Functions\when( 'wp_die' )->alias(
			function ( string $msg = '', string $title = '', $args = [] ) use ( &$response_code ): void {
				if ( is_array( $args ) && isset( $args['response'] ) ) {
					$response_code = $args['response'];
				}
				throw new \RuntimeException( 'wp_die' );
			}
		);

		try {
			$this->notice->handle_dismiss();
		} catch ( \RuntimeException $e ) {
			// Expected.
		}

		$this->assertSame( 403, $response_code );
	}

	/**
	 * @test
	 * Dismiss calls check_ajax_referer with correct action.
	 */
	public function test_handle_dismiss_verifies_nonce(): void {
		Functions\expect( 'check_ajax_referer' )
			->once()
			->with( 'wpdsgvo_dismiss_dsfa', '_wpnonce' );

		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'get_current_user_id' )->justReturn( 1 );
		Functions\when( 'update_user_meta' )->justReturn( true );

		Functions\when( 'wp_die' )->alias( function (): void {
			throw new \RuntimeException( 'wp_die' );
		} );

		try {
			$this->notice->handle_dismiss();
		} catch ( \RuntimeException $e ) {
			// Expected.
		}

		// Assertion via Mockery expectation on check_ajax_referer.
		$this->assertTrue( true );
	}

	// ------------------------------------------------------------------
	// register()
	// ------------------------------------------------------------------

	/**
	 * @test
	 * register() adds both hooks.
	 */
	public function test_register_adds_admin_notices_and_ajax_hooks(): void {
		Actions\expectAdded( 'admin_notices' )
			->once()
			->with( [ $this->notice, 'maybe_show_notice' ] );

		Actions\expectAdded( 'wp_ajax_wpdsgvo_dismiss_dsfa_notice' )
			->once()
			->with( [ $this->notice, 'handle_dismiss' ] );

		$this->notice->register();
	}
}
