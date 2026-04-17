<?php
declare(strict_types=1);

namespace WpDsgvoForm\Tests\Unit\Notification;

use PHPUnit\Framework\Attributes\CoversClass;
use WpDsgvoForm\Notification\NotificationService;
use WpDsgvoForm\Tests\TestCase;
use Brain\Monkey\Functions;

/**
 * Unit tests for NotificationService.
 *
 * Tests that notification emails comply with SEC-MAIL-01 through SEC-MAIL-05:
 * - SEC-MAIL-01: Only wp_mail(), never mail()
 * - SEC-MAIL-02: Subject sanitized against header injection
 * - SEC-MAIL-03: No form data in email body (only login link)
 * - SEC-MAIL-04: Recipients validated against WP user system
 * - SEC-MAIL-05: HTML content type with UTF-8
 */
#[CoversClass(NotificationService::class)]
class NotificationServiceTest extends TestCase
{

	private NotificationService $service;

	protected function setUp(): void
	{
		parent::setUp();
		$this->service = new NotificationService();
	}

	// ─── SEC-MAIL-03: No plaintext form data in emails ──────────

	public function test_email_body_contains_no_form_data(): void
	{
		$captured_body = null;

		// Mock WordPress i18n — return string as-is.
		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'sanitize_email' )->returnArg();
		Functions\expect( 'admin_url' )
			->andReturn( 'https://example.com/wp-admin/admin.php?page=dsgvo-form-submissions' );

		// Mock get_userdata to return a valid user.
		$user              = new \stdClass();
		$user->user_email  = 'recipient@example.com';
		Functions\expect( 'get_userdata' )
			->with( 1 )
			->andReturn( $user );

		// Capture the email body sent via wp_mail.
		Functions\expect( 'wp_mail' )
			->once()
			->andReturnUsing( function ( $to, $subject, $body ) use ( &$captured_body ) {
				$captured_body = $body;
				return true;
			} );

		$this->service->notify_single( 1, 42, 'Kontaktformular' );

		// SEC-MAIL-03: Body must contain a login link, NOT form data.
		$this->assertStringContainsString( 'dsgvo-form-submissions', $captured_body );
		$this->assertStringContainsString( 'Kontaktformular', $captured_body );

		// Body must NOT contain submission data placeholders or decrypted content.
		$this->assertStringNotContainsString( 'submission_id=42', $captured_body );
	}

	public function test_email_body_contains_privacy_disclaimer(): void
	{
		$captured_body = null;

		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'sanitize_email' )->returnArg();
		Functions\expect( 'admin_url' )
			->andReturn( 'https://example.com/wp-admin/' );

		$user              = new \stdClass();
		$user->user_email  = 'test@example.com';
		Functions\expect( 'get_userdata' )->andReturn( $user );

		Functions\expect( 'wp_mail' )
			->once()
			->andReturnUsing( function ( $to, $subject, $body ) use ( &$captured_body ) {
				$captured_body = $body;
				return true;
			} );

		$this->service->notify_single( 1, 42, 'Test Form' );

		// SEC-MAIL-03: Email must include a privacy disclaimer explaining why no data is shown.
		$this->assertStringContainsString( 'Datenschutzgruenden', $captured_body );
		$this->assertStringContainsString( 'keine Formulardaten', $captured_body );
	}

	// ─── SEC-MAIL-02: Subject sanitized ─────────────────────────

	public function test_email_subject_strips_line_breaks_for_header_injection(): void
	{
		$captured_subject = null;

		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'sanitize_email' )->returnArg();
		Functions\expect( 'admin_url' )->andReturn( 'https://example.com/' );

		$user              = new \stdClass();
		$user->user_email  = 'test@example.com';
		Functions\expect( 'get_userdata' )->andReturn( $user );

		Functions\expect( 'wp_mail' )
			->once()
			->andReturnUsing( function ( $to, $subject, $body ) use ( &$captured_subject ) {
				$captured_subject = $subject;
				return true;
			} );

		// Title with injected line breaks (header injection attempt).
		$malicious_title = "Form\r\nBcc: attacker@evil.com";
		$this->service->notify_single( 1, 42, $malicious_title );

		// SEC-MAIL-02: Line breaks must be stripped from subject.
		// Header injection relies on \r\n to inject headers — removing them neutralizes the attack.
		$this->assertStringNotContainsString( "\r", $captured_subject );
		$this->assertStringNotContainsString( "\n", $captured_subject );
	}

	// ─── Recipient handling ─────────────────────────────────────

	public function test_notify_returns_zero_when_no_recipients(): void
	{
		global $wpdb;
		$wpdb         = \Mockery::mock( 'wpdb' );
		$wpdb->prefix = 'wp_';
		$wpdb->shouldReceive( 'prepare' )->once()->andReturn( 'SELECT ...' );
		$wpdb->shouldReceive( 'get_results' )->once()->andReturn( [] );

		$result = $this->service->notify( 1, 42, 'Test Form' );
		$this->assertSame( 0, $result );
	}

	public function test_notify_skips_user_without_email(): void
	{
		global $wpdb;
		$wpdb         = \Mockery::mock( 'wpdb' );
		$wpdb->prefix = 'wp_';

		$recipient           = new \stdClass();
		$recipient->user_id  = 99;

		$wpdb->shouldReceive( 'prepare' )->once()->andReturn( 'SELECT ...' );
		$wpdb->shouldReceive( 'get_results' )->once()->andReturn( [ $recipient ] );

		// User exists but has no email.
		$user              = new \stdClass();
		$user->user_email  = '';
		Functions\expect( 'get_userdata' )->with( 99 )->andReturn( $user );
		Functions\when( 'sanitize_email' )->justReturn( '' );

		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\expect( 'admin_url' )->andReturn( 'https://example.com/' );

		$result = $this->service->notify( 1, 42, 'Test' );
		$this->assertSame( 0, $result );
	}

	public function test_notify_single_returns_false_for_invalid_user(): void
	{
		Functions\expect( 'get_userdata' )->with( 999 )->andReturn( false );

		$result = $this->service->notify_single( 999, 42, 'Test' );
		$this->assertFalse( $result );
	}

	// ─── SEC-MAIL-05: HTML content type ─────────────────────────

	public function test_email_headers_contain_html_content_type(): void
	{
		$captured_headers = null;

		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'sanitize_email' )->returnArg();
		Functions\expect( 'admin_url' )->andReturn( 'https://example.com/' );

		$user              = new \stdClass();
		$user->user_email  = 'test@example.com';
		Functions\expect( 'get_userdata' )->andReturn( $user );

		Functions\expect( 'wp_mail' )
			->once()
			->andReturnUsing( function ( $to, $subject, $body, $headers ) use ( &$captured_headers ) {
				$captured_headers = $headers;
				return true;
			} );

		$this->service->notify_single( 1, 42, 'Test' );

		$this->assertIsArray( $captured_headers );
		$this->assertContains( 'Content-Type: text/html; charset=UTF-8', $captured_headers );
	}
}
