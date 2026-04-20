<?php
/**
 * Unit tests for FirstLoginNotice.
 *
 * Covers Task #287 (UX-REC-02 MUSS): Art. 13 DSGVO First-Login Datenschutzhinweis.
 *
 * @package WpDsgvoForm\Tests\Unit\Admin
 */

declare(strict_types=1);

namespace WpDsgvoForm\Tests\Unit\Admin;

use WpDsgvoForm\Admin\FirstLoginNotice;
use WpDsgvoForm\Auth\AccessControl;
use WpDsgvoForm\Audit\AuditLogger;
use WpDsgvoForm\Tests\TestCase;
use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Mockery;

/**
 * Tests for FirstLoginNotice: needs_acknowledgment(), register(), redirect,
 * handle_acknowledgment(), render, and filter integration.
 *
 * @security-relevant  Art. 13 DSGVO information obligations
 * @privacy-relevant   Art. 29, 32 DSGVO confidentiality obligation
 */
class FirstLoginNoticeTest extends TestCase {

	private AccessControl $access_control;
	private AuditLogger $audit_logger;

	/**
	 * Backup of $_GET and $_POST superglobals.
	 *
	 * @var array<string, mixed>
	 */
	private array $original_get = array();
	private array $original_post = array();

	/**
	 * @inheritDoc
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->original_get  = $_GET;
		$this->original_post = $_POST;

		$this->access_control = Mockery::mock( AccessControl::class );
		$this->audit_logger   = Mockery::mock( AuditLogger::class );

		// Common WP function stubs.
		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
	}

	/**
	 * @inheritDoc
	 */
	protected function tearDown(): void {
		$_GET  = $this->original_get;
		$_POST = $this->original_post;
		parent::tearDown();
	}

	/**
	 * Creates a FirstLoginNotice instance with mocked dependencies.
	 *
	 * @return FirstLoginNotice
	 */
	private function create_notice(): FirstLoginNotice {
		return new FirstLoginNotice( $this->access_control, $this->audit_logger );
	}

	/**
	 * Stubs needs_acknowledgment dependencies for a plugin-role user.
	 *
	 * @param int    $user_id       The user ID.
	 * @param string $role          The WP role slug.
	 * @param int    $ack_version   The stored acknowledgment version (0 = none).
	 * @param bool   $is_admin      Whether user has dsgvo_form_manage capability.
	 */
	private function stub_user_for_needs_ack(
		int $user_id,
		string $role = 'wp_dsgvo_form_reader',
		int $ack_version = 0,
		bool $is_admin = false
	): void {
		Functions\when( 'user_can' )->alias(
			function ( int $uid, string $cap ) use ( $user_id, $is_admin ): bool {
				if ( $uid === $user_id && $cap === 'dsgvo_form_manage' ) {
					return $is_admin;
				}
				return false;
			}
		);

		$user        = new \WP_User( $user_id );
		$user->roles = array( $role );

		Functions\when( 'get_userdata' )->alias(
			function ( int $uid ) use ( $user_id, $user ) {
				return $uid === $user_id ? $user : false;
			}
		);

		Functions\when( 'get_user_meta' )->alias(
			function ( int $uid, string $key, bool $single ) use ( $user_id, $ack_version ) {
				if ( $uid === $user_id && $key === FirstLoginNotice::META_KEY && $single ) {
					return $ack_version > 0 ? (string) $ack_version : '';
				}
				return '';
			}
		);
	}

	// ==================================================================
	// register() — Hook registration
	// ==================================================================

	/**
	 * @test
	 */
	public function test_register_adds_admin_menu_action(): void {
		Actions\expectAdded( 'admin_menu' )->once();

		$notice = $this->create_notice();
		$notice->register();
	}

	/**
	 * @test
	 */
	public function test_register_adds_current_screen_action_at_priority_5(): void {
		Actions\expectAdded( 'current_screen' )
			->once()
			->with( Mockery::type( 'array' ), 5 );

		$notice = $this->create_notice();
		$notice->register();
	}

	/**
	 * @test
	 */
	public function test_register_adds_admin_post_action_for_nonce_action(): void {
		Actions\expectAdded( 'admin_post_wpdsgvo_acknowledge_notice' )->once();

		$notice = $this->create_notice();
		$notice->register();
	}

	// ==================================================================
	// register_page() — Hidden submenu
	// ==================================================================

	/**
	 * @test
	 */
	public function test_register_page_creates_hidden_submenu_with_null_parent(): void {
		$captured_parent = 'NOT_CALLED';

		Functions\expect( 'add_submenu_page' )
			->once()
			->andReturnUsing(
				function () use ( &$captured_parent ): string {
					$args            = func_get_args();
					$captured_parent = $args[0];
					return 'submenu_hook';
				}
			);

		$notice = $this->create_notice();
		$notice->register_page();

		$this->assertNull( $captured_parent, 'Hidden page must have null parent slug.' );
	}

	/**
	 * @test
	 */
	public function test_register_page_uses_dsgvo_form_acknowledge_slug(): void {
		$captured_slug = '';

		Functions\expect( 'add_submenu_page' )
			->once()
			->andReturnUsing(
				function () use ( &$captured_slug ): string {
					$args           = func_get_args();
					$captured_slug = $args[4];
					return 'submenu_hook';
				}
			);

		$notice = $this->create_notice();
		$notice->register_page();

		$this->assertSame( 'dsgvo-form-acknowledge', $captured_slug );
	}

	// ==================================================================
	// needs_acknowledgment() — Static method
	// ==================================================================

	/**
	 * @test
	 */
	public function test_needs_acknowledgment_returns_false_for_zero_user_id(): void {
		$this->assertFalse( FirstLoginNotice::needs_acknowledgment( 0 ) );
	}

	/**
	 * @test
	 */
	public function test_needs_acknowledgment_returns_false_for_admin_with_manage_capability(): void {
		$this->stub_user_for_needs_ack( 1, 'administrator', 0, true );

		$this->assertFalse(
			FirstLoginNotice::needs_acknowledgment( 1 ),
			'Admins with dsgvo_form_manage must be exempt.'
		);
	}

	/**
	 * @test
	 */
	public function test_needs_acknowledgment_returns_false_for_non_plugin_role(): void {
		$this->stub_user_for_needs_ack( 5, 'subscriber', 0, false );

		$this->assertFalse(
			FirstLoginNotice::needs_acknowledgment( 5 ),
			'Non-plugin roles (subscriber) should not need acknowledgment.'
		);
	}

	/**
	 * @test
	 */
	public function test_needs_acknowledgment_returns_false_when_get_userdata_fails(): void {
		Functions\when( 'user_can' )->justReturn( false );
		Functions\when( 'get_userdata' )->justReturn( false );

		$this->assertFalse( FirstLoginNotice::needs_acknowledgment( 999 ) );
	}

	/**
	 * @test
	 */
	public function test_needs_acknowledgment_returns_true_for_reader_without_meta(): void {
		$this->stub_user_for_needs_ack( 10, 'wp_dsgvo_form_reader', 0, false );

		$this->assertTrue(
			FirstLoginNotice::needs_acknowledgment( 10 ),
			'Reader without acknowledgment meta must need acknowledgment.'
		);
	}

	/**
	 * @test
	 */
	public function test_needs_acknowledgment_returns_true_for_supervisor_without_meta(): void {
		$this->stub_user_for_needs_ack( 11, 'wp_dsgvo_form_supervisor', 0, false );

		$this->assertTrue(
			FirstLoginNotice::needs_acknowledgment( 11 ),
			'Supervisor without acknowledgment meta must need acknowledgment.'
		);
	}

	/**
	 * @test
	 */
	public function test_needs_acknowledgment_returns_false_after_current_version_acknowledged(): void {
		$this->stub_user_for_needs_ack( 10, 'wp_dsgvo_form_reader', 1, false );

		$this->assertFalse(
			FirstLoginNotice::needs_acknowledgment( 10 ),
			'Reader who acknowledged current version should not need re-acknowledgment.'
		);
	}

	/**
	 * @test
	 * @privacy-relevant Version bump forces re-acknowledgment
	 */
	public function test_needs_acknowledgment_returns_true_for_outdated_version(): void {
		// Ack version 0 < NOTICE_VERSION (1) → needs re-ack.
		$this->stub_user_for_needs_ack( 10, 'wp_dsgvo_form_reader', 0, false );

		$this->assertTrue(
			FirstLoginNotice::needs_acknowledgment( 10 ),
			'Outdated ack version must trigger re-acknowledgment.'
		);
	}

	// ==================================================================
	// maybe_redirect_to_notice() — Admin redirect guard
	// ==================================================================

	/**
	 * @test
	 */
	public function test_maybe_redirect_skips_when_no_acknowledgment_needed(): void {
		// Admin user — needs_acknowledgment returns false.
		Functions\when( 'get_current_user_id' )->justReturn( 1 );
		$this->stub_user_for_needs_ack( 1, 'administrator', 0, true );

		Functions\expect( 'wp_safe_redirect' )->never();

		$screen     = \WP_Screen::get( 'dashboard' );
		$notice     = $this->create_notice();
		$notice->maybe_redirect_to_notice( $screen );

		// No exception = no redirect happened.
		$this->assertTrue( true );
	}

	/**
	 * @test
	 */
	public function test_maybe_redirect_skips_when_already_on_acknowledge_page(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 10 );
		$this->stub_user_for_needs_ack( 10, 'wp_dsgvo_form_reader', 0, false );

		$_GET['page'] = 'dsgvo-form-acknowledge';

		Functions\expect( 'wp_safe_redirect' )->never();

		$screen = \WP_Screen::get( 'toplevel_page_dsgvo-form-acknowledge' );
		$notice = $this->create_notice();
		$notice->maybe_redirect_to_notice( $screen );

		$this->assertTrue( true );
	}

	/**
	 * @test
	 */
	public function test_maybe_redirect_redirects_to_acknowledge_page(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 10 );
		$this->stub_user_for_needs_ack( 10, 'wp_dsgvo_form_reader', 0, false );

		Functions\when( 'admin_url' )->alias(
			function ( string $path = '' ): string {
				return 'https://example.com/wp-admin/' . $path;
			}
		);

		$_GET['page'] = 'dsgvo-form-submissions';

		$redirected_to = '';

		Functions\expect( 'wp_safe_redirect' )
			->once()
			->andReturnUsing(
				function ( string $url ) use ( &$redirected_to ): void {
					$redirected_to = $url;
					throw new \RuntimeException( 'redirect_exit' );
				}
			);

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'redirect_exit' );

		$screen = \WP_Screen::get( 'dsgvo-form-submissions' );
		$notice = $this->create_notice();
		$notice->maybe_redirect_to_notice( $screen );

		// Not reached due to exception, but for clarity:
		$this->assertStringContainsString( 'dsgvo-form-acknowledge', $redirected_to );
	}

	// ==================================================================
	// handle_acknowledgment() — POST handler
	// ==================================================================

	/**
	 * @test
	 * @security-relevant Nonce verification before any processing
	 */
	public function test_handle_acknowledgment_rejects_invalid_nonce(): void {
		$_POST['_wpdsgvo_nonce'] = 'invalid';

		Functions\expect( 'wp_verify_nonce' )->once()->andReturn( false );

		$wp_die_message = '';

		Functions\expect( 'wp_die' )
			->once()
			->andReturnUsing(
				function () use ( &$wp_die_message ): never {
					$args           = func_get_args();
					$wp_die_message = $args[0];
					throw new \RuntimeException( 'wp_die: nonce' );
				}
			);

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'wp_die: nonce' );

		$notice = $this->create_notice();
		$notice->handle_acknowledgment();
	}

	/**
	 * @test
	 */
	public function test_handle_acknowledgment_rejects_missing_nonce(): void {
		unset( $_POST['_wpdsgvo_nonce'] );

		Functions\expect( 'wp_die' )
			->once()
			->andReturnUsing(
				function (): never {
					throw new \RuntimeException( 'wp_die: no nonce' );
				}
			);

		$this->expectException( \RuntimeException::class );

		$notice = $this->create_notice();
		$notice->handle_acknowledgment();
	}

	/**
	 * @test
	 */
	public function test_handle_acknowledgment_rejects_zero_user_id(): void {
		$_POST['_wpdsgvo_nonce'] = 'valid';

		Functions\expect( 'wp_verify_nonce' )->once()->andReturn( 1 );
		Functions\when( 'get_current_user_id' )->justReturn( 0 );

		$wp_die_response = 0;

		Functions\expect( 'wp_die' )
			->once()
			->andReturnUsing(
				function () use ( &$wp_die_response ): never {
					$args             = func_get_args();
					$wp_die_response = $args[2]['response'] ?? 0;
					throw new \RuntimeException( 'wp_die: no user' );
				}
			);

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'wp_die: no user' );

		$notice = $this->create_notice();
		$notice->handle_acknowledgment();
	}

	/**
	 * @test
	 */
	public function test_handle_acknowledgment_rejects_missing_checkbox(): void {
		$_POST['_wpdsgvo_nonce'] = 'valid';

		Functions\expect( 'wp_verify_nonce' )->once()->andReturn( 1 );
		Functions\when( 'get_current_user_id' )->justReturn( 10 );

		// No $_POST['acknowledge'] set.

		$wp_die_response = 0;

		Functions\expect( 'wp_die' )
			->once()
			->andReturnUsing(
				function () use ( &$wp_die_response ): never {
					$args             = func_get_args();
					$wp_die_response = $args[2]['response'] ?? 0;
					throw new \RuntimeException( 'wp_die: no checkbox' );
				}
			);

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'wp_die: no checkbox' );

		$notice = $this->create_notice();
		$notice->handle_acknowledgment();
	}

	/**
	 * @test
	 */
	public function test_handle_acknowledgment_saves_user_meta_with_notice_version(): void {
		$_POST['_wpdsgvo_nonce'] = 'valid';
		$_POST['acknowledge']    = '1';

		Functions\expect( 'wp_verify_nonce' )->once()->andReturn( 1 );
		Functions\when( 'get_current_user_id' )->justReturn( 10 );

		$saved_meta_key   = '';
		$saved_meta_value = '';

		Functions\expect( 'update_user_meta' )
			->once()
			->andReturnUsing(
				function ( int $user_id, string $key, $value ) use ( &$saved_meta_key, &$saved_meta_value ): bool {
					$saved_meta_key   = $key;
					$saved_meta_value = $value;
					return true;
				}
			);

		$this->audit_logger->shouldReceive( 'log' )->once();

		Functions\when( 'sanitize_url' )->returnArg();
		Functions\when( 'admin_url' )->alias(
			function ( string $path = '' ): string {
				return 'https://example.com/wp-admin/' . $path;
			}
		);

		Functions\expect( 'wp_safe_redirect' )
			->once()
			->andReturnUsing(
				function (): never {
					throw new \RuntimeException( 'redirect_exit' );
				}
			);

		try {
			$notice = $this->create_notice();
			$notice->handle_acknowledgment();
		} catch ( \RuntimeException $e ) {
			// Expected — exit after redirect.
		}

		$this->assertSame( 'wpdsgvo_privacy_notice_ack', $saved_meta_key );
		$this->assertSame( 1, $saved_meta_value, 'Must save NOTICE_VERSION (1) to user meta.' );
	}

	/**
	 * @test
	 * @security-relevant SEC-AUDIT-01: Acknowledgment must be logged
	 */
	public function test_handle_acknowledgment_logs_audit_event(): void {
		$_POST['_wpdsgvo_nonce'] = 'valid';
		$_POST['acknowledge']    = '1';

		Functions\expect( 'wp_verify_nonce' )->once()->andReturn( 1 );
		Functions\when( 'get_current_user_id' )->justReturn( 10 );
		Functions\when( 'update_user_meta' )->justReturn( true );
		Functions\when( 'sanitize_url' )->returnArg();
		Functions\when( 'admin_url' )->alias(
			function ( string $path = '' ): string {
				return 'https://example.com/wp-admin/' . $path;
			}
		);

		$this->audit_logger->shouldReceive( 'log' )
			->once()
			->with( 10, 'privacy_notice_acknowledged', null, null, 'Version 1' );

		Functions\expect( 'wp_safe_redirect' )
			->once()
			->andReturnUsing(
				function (): never {
					throw new \RuntimeException( 'redirect_exit' );
				}
			);

		$this->expectException( \RuntimeException::class );

		$notice = $this->create_notice();
		$notice->handle_acknowledgment();
	}

	/**
	 * @test
	 */
	public function test_handle_acknowledgment_redirects_to_default_submissions_page(): void {
		$_POST['_wpdsgvo_nonce'] = 'valid';
		$_POST['acknowledge']    = '1';
		// No redirect_to in POST.

		Functions\expect( 'wp_verify_nonce' )->once()->andReturn( 1 );
		Functions\when( 'get_current_user_id' )->justReturn( 10 );
		Functions\when( 'update_user_meta' )->justReturn( true );

		$this->audit_logger->shouldReceive( 'log' )->once();

		Functions\when( 'admin_url' )->alias(
			function ( string $path = '' ): string {
				return 'https://example.com/wp-admin/' . $path;
			}
		);

		$redirected_to = '';

		Functions\expect( 'wp_safe_redirect' )
			->once()
			->andReturnUsing(
				function ( string $url ) use ( &$redirected_to ): never {
					$redirected_to = $url;
					throw new \RuntimeException( 'redirect_exit' );
				}
			);

		try {
			$notice = $this->create_notice();
			$notice->handle_acknowledgment();
		} catch ( \RuntimeException $e ) {
			// Expected.
		}

		$this->assertStringContainsString( 'dsgvo-form-submissions', $redirected_to );
	}

	/**
	 * @test
	 */
	public function test_handle_acknowledgment_redirects_to_custom_redirect_to(): void {
		$_POST['_wpdsgvo_nonce'] = 'valid';
		$_POST['acknowledge']    = '1';
		$_POST['redirect_to']   = 'https://example.com/dsgvo-empfaenger/';

		Functions\expect( 'wp_verify_nonce' )->once()->andReturn( 1 );
		Functions\when( 'get_current_user_id' )->justReturn( 10 );
		Functions\when( 'update_user_meta' )->justReturn( true );
		Functions\when( 'sanitize_url' )->returnArg();

		$this->audit_logger->shouldReceive( 'log' )->once();

		$redirected_to = '';

		Functions\expect( 'wp_safe_redirect' )
			->once()
			->andReturnUsing(
				function ( string $url ) use ( &$redirected_to ): never {
					$redirected_to = $url;
					throw new \RuntimeException( 'redirect_exit' );
				}
			);

		try {
			$notice = $this->create_notice();
			$notice->handle_acknowledgment();
		} catch ( \RuntimeException $e ) {
			// Expected.
		}

		$this->assertSame( 'https://example.com/dsgvo-empfaenger/', $redirected_to );
	}

	// ==================================================================
	// render_acknowledge_page() — Admin HTML output
	// ==================================================================

	/**
	 * @test
	 */
	public function test_render_acknowledge_page_contains_heading_and_form(): void {
		Functions\when( 'esc_html_e' )->alias(
			function ( string $text, string $domain = '' ): void {
				echo $text;
			}
		);
		Functions\when( 'wp_kses_post' )->returnArg();
		Functions\when( 'admin_url' )->alias(
			function ( string $path = '' ): string {
				return 'https://example.com/wp-admin/' . $path;
			}
		);
		Functions\when( 'wp_nonce_field' )->justReturn( null );
		Functions\when( 'submit_button' )->alias(
			function ( string $text = 'Save' ): void {
				echo '<input type="submit" value="' . $text . '">';
			}
		);
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'get_option' )->justReturn( '' );

		$notice = $this->create_notice();

		ob_start();
		$notice->render_acknowledge_page();
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( 'Datenschutzhinweis', $output );
		$this->assertStringContainsString( 'type="checkbox"', $output );
		$this->assertStringContainsString( 'name="acknowledge"', $output );
		$this->assertStringContainsString( 'wpdsgvo_acknowledge_notice', $output );
		$this->assertStringContainsString( 'type="submit"', $output );
	}

	/**
	 * @test
	 */
	public function test_render_acknowledge_page_contains_art13_information(): void {
		Functions\when( 'esc_html_e' )->alias(
			function ( string $text, string $domain = '' ): void {
				echo $text;
			}
		);
		Functions\when( 'wp_kses_post' )->returnArg();
		Functions\when( 'admin_url' )->alias(
			function ( string $path = '' ): string {
				return 'https://example.com/wp-admin/' . $path;
			}
		);
		Functions\when( 'wp_nonce_field' )->justReturn( null );
		Functions\when( 'submit_button' )->alias(
			function ( string $text = 'Save' ): void {
				echo '<input type="submit" value="' . $text . '">';
			}
		);
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'get_option' )->justReturn( '' );

		$notice = $this->create_notice();

		ob_start();
		$notice->render_acknowledge_page();
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( 'Art. 13 DSGVO', $output );
		$this->assertStringContainsString( 'Vertraulichkeitspflicht', $output );
	}

	// ==================================================================
	// render_notice_frontend() — Frontend HTML string
	// ==================================================================

	/**
	 * @test
	 */
	public function test_render_notice_frontend_contains_form_and_checkbox(): void {
		Functions\when( 'wp_kses_post' )->returnArg();
		Functions\when( 'admin_url' )->alias(
			function ( string $path = '' ): string {
				return 'https://example.com/wp-admin/' . $path;
			}
		);
		Functions\when( 'wp_nonce_field' )->alias(
			function ( string $action, string $name, bool $referer = true, bool $echo = true ): string {
				$field = '<input type="hidden" name="' . $name . '" value="nonce123">';
				if ( $echo ) {
					echo $field;
				}
				return $field;
			}
		);
		Functions\when( 'apply_filters' )->returnArg( 2 );

		// RecipientPage::get_base_url() is static — needs stubbing.
		// It calls get_permalink() internally, so we mock it.
		Functions\when( 'get_option' )->justReturn( '' );
		Functions\when( 'home_url' )->alias(
			function ( string $path = '' ): string {
				return 'https://example.com' . $path;
			}
		);

		$notice = $this->create_notice();
		$output = $notice->render_notice_frontend();

		$this->assertStringContainsString( 'dsgvo-recipient__notice', $output );
		$this->assertStringContainsString( 'Datenschutzhinweis', $output );
		$this->assertStringContainsString( 'type="checkbox"', $output );
		$this->assertStringContainsString( 'name="acknowledge"', $output );
		$this->assertStringContainsString( 'wpdsgvo_acknowledge_notice', $output );
		$this->assertStringContainsString( 'Bestaetigen', $output );
	}

	/**
	 * @test
	 */
	public function test_render_notice_frontend_contains_confidentiality_info(): void {
		Functions\when( 'wp_kses_post' )->returnArg();
		Functions\when( 'admin_url' )->alias(
			function ( string $path = '' ): string {
				return 'https://example.com/wp-admin/' . $path;
			}
		);
		Functions\when( 'wp_nonce_field' )->alias(
			function ( string $action, string $name, bool $referer = true, bool $echo = true ): string {
				return '<input type="hidden" name="' . $name . '" value="nonce123">';
			}
		);
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'get_option' )->justReturn( '' );
		Functions\when( 'home_url' )->alias(
			function ( string $path = '' ): string {
				return 'https://example.com' . $path;
			}
		);

		$notice = $this->create_notice();
		$output = $notice->render_notice_frontend();

		$this->assertStringContainsString( 'Art. 13 DSGVO', $output );
		$this->assertStringContainsString( 'Vertraulichkeitspflicht', $output );
		$this->assertStringContainsString( 'Audit-Log', $output );
	}

	// ==================================================================
	// Filter: wpdsgvo_first_login_notice_text
	// ==================================================================

	/**
	 * @test
	 */
	public function test_notice_text_applies_wpdsgvo_first_login_notice_text_filter(): void {
		$filter_called = false;

		Functions\when( 'wp_kses_post' )->returnArg();
		Functions\when( 'admin_url' )->alias(
			function ( string $path = '' ): string {
				return 'https://example.com/wp-admin/' . $path;
			}
		);
		Functions\when( 'wp_nonce_field' )->alias(
			function ( string $action, string $name, bool $referer = true, bool $echo = true ): string {
				return '<input type="hidden" name="' . $name . '" value="nonce123">';
			}
		);
		Functions\when( 'get_option' )->justReturn( '' );
		Functions\when( 'home_url' )->alias(
			function ( string $path = '' ): string {
				return 'https://example.com' . $path;
			}
		);

		Functions\when( 'apply_filters' )->alias(
			function ( string $tag, $value ) use ( &$filter_called ) {
				if ( $tag === 'wpdsgvo_first_login_notice_text' ) {
					$filter_called = true;
				}
				return $value;
			}
		);

		$notice = $this->create_notice();
		$notice->render_notice_frontend();

		$this->assertTrue( $filter_called, 'wpdsgvo_first_login_notice_text filter must be applied.' );
	}

	// ==================================================================
	// META_KEY constant — public API
	// ==================================================================

	/**
	 * @test
	 */
	public function test_meta_key_constant_is_wpdsgvo_privacy_notice_ack(): void {
		$this->assertSame( 'wpdsgvo_privacy_notice_ack', FirstLoginNotice::META_KEY );
	}
}
