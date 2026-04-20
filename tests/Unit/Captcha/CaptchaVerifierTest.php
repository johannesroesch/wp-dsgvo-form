<?php
/**
 * Unit tests for CaptchaVerifier class.
 *
 * @package WpDsgvoForm\Tests\Unit\Captcha
 */

declare(strict_types=1);

namespace WpDsgvoForm\Tests\Unit\Captcha;

use WpDsgvoForm\Captcha\CaptchaVerifier;
use WpDsgvoForm\Models\Form;
use WpDsgvoForm\Tests\TestCase;
use Brain\Monkey\Functions;

/**
 * Tests for CAPTCHA token verification and configuration.
 *
 * Covers SEC-CAP-01 through SEC-CAP-08.
 *
 * Updated for Task #278: Constructor no longer takes a $base_url parameter.
 * URL is read from WPDSGVO_CAPTCHA_URL constant.
 *
 * Note: is_wp_error() is a real function from tests/stubs/wordpress.php
 * (checks instanceof WP_Error). It cannot be mocked via Brain\Monkey.
 * Tests use real WP_Error instances instead.
 */
class CaptchaVerifierTest extends TestCase {

	/**
	 * Stub common WordPress functions.
	 *
	 * @param string[] $skip Function names to NOT stub.
	 */
	private function stub_captcha_functions( array $skip = array() ): void {
		if ( ! in_array( 'get_option', $skip, true ) ) {
			Functions\when( 'get_option' )->alias(
				function ( string $option, $default = false ) {
					if ( 'wpdsgvo_captcha_secret' === $option ) {
						return 'test-api-key';
					}
					return $default;
				}
			);
		}

		if ( ! in_array( 'wp_json_encode', $skip, true ) ) {
			Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		}
	}

	/**
	 * @test
	 * SEC-CAP-01: Empty token must be rejected immediately.
	 */
	public function test_verify_returns_false_for_empty_token(): void {
		$this->stub_captcha_functions();

		$verifier = new CaptchaVerifier();

		$this->assertFalse( $verifier->verify( '' ) );
	}

	/**
	 * @test
	 * SEC-CAP-01: Successful server-side verification via POST.
	 */
	public function test_verify_returns_true_on_successful_verification(): void {
		$this->stub_captcha_functions( array( 'get_option', 'wp_remote_post', 'wp_remote_retrieve_body' ) );

		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );

		Functions\expect( 'get_option' )
			->andReturnUsing(
				function ( string $option, $default = false ) {
					if ( 'wpdsgvo_captcha_secret' === $option ) {
						return 'test-secret-key';
					}
					return $default;
				}
			);

		Functions\expect( 'wp_remote_post' )
			->once()
			->with(
				WPDSGVO_CAPTCHA_URL . '/api/validate',
				\Mockery::on(
					function ( array $args ): bool {
						$body = json_decode( $args['body'], true );
						return $body['verification_token'] === 'valid-token'
							&& $args['headers']['Content-Type'] === 'application/json'
							&& $args['headers']['Authorization'] === 'Bearer test-secret-key'
							&& $args['timeout'] === 5
							&& $args['sslverify'] === true;
					}
				)
			)
			->andReturn( array( 'body' => '{"valid":true}' ) );

		Functions\expect( 'wp_remote_retrieve_body' )
			->once()
			->andReturn( '{"valid":true}' );

		$verifier = new CaptchaVerifier();

		$this->assertTrue( $verifier->verify( 'valid-token' ) );
	}

	/**
	 * @test
	 * SEC-CAP-04: Fail-closed on WP_Error (network timeout).
	 */
	public function test_verify_returns_false_on_wp_error(): void {
		$this->stub_captcha_functions( array( 'wp_remote_post' ) );

		// Return a real WP_Error — is_wp_error() is a real function.
		Functions\expect( 'wp_remote_post' )
			->once()
			->andReturn( new \WP_Error( 'http_request_failed', 'Connection timed out' ) );

		$verifier = new CaptchaVerifier();

		$this->assertFalse( $verifier->verify( 'some-token' ) );
	}

	/**
	 * @test
	 * SEC-CAP-04: Fail-closed when server returns error JSON (no valid field).
	 */
	public function test_verify_returns_false_on_error_response(): void {
		$this->stub_captcha_functions( array( 'wp_remote_post', 'wp_remote_retrieve_body' ) );

		Functions\expect( 'wp_remote_post' )->once()->andReturn( array() );
		Functions\expect( 'wp_remote_retrieve_body' )->once()->andReturn( '{"error":"server_error"}' );

		$verifier = new CaptchaVerifier();

		$this->assertFalse( $verifier->verify( 'some-token' ) );
	}

	/**
	 * @test
	 * SEC-CAP-04: Fail-closed on invalid JSON response body.
	 */
	public function test_verify_returns_false_on_invalid_json_response(): void {
		$this->stub_captcha_functions( array( 'wp_remote_post', 'wp_remote_retrieve_body' ) );

		Functions\expect( 'wp_remote_post' )->once()->andReturn( array() );
		Functions\expect( 'wp_remote_retrieve_body' )->once()->andReturn( 'not valid json' );

		$verifier = new CaptchaVerifier();

		$this->assertFalse( $verifier->verify( 'some-token' ) );
	}

	/**
	 * @test
	 * SEC-CAP-04: Fail-closed when valid field is missing or false.
	 */
	public function test_verify_returns_false_when_valid_is_false(): void {
		$this->stub_captcha_functions( array( 'wp_remote_post', 'wp_remote_retrieve_body' ) );

		Functions\expect( 'wp_remote_post' )->once()->andReturn( array() );
		Functions\expect( 'wp_remote_retrieve_body' )->once()->andReturn( '{"valid":false}' );

		$verifier = new CaptchaVerifier();

		$this->assertFalse( $verifier->verify( 'invalid-token' ) );
	}

	/**
	 * @test
	 * SEC-CAP-04: Fail-closed when API key option is empty (not configured).
	 */
	public function test_verify_returns_false_when_api_key_not_configured(): void {
		$this->stub_captcha_functions( array( 'get_option' ) );

		Functions\expect( 'get_option' )
			->andReturnUsing(
				function ( string $option, $default = false ) {
					if ( 'wpdsgvo_captcha_secret' === $option ) {
						return '';
					}
					return $default;
				}
			);

		$verifier = new CaptchaVerifier();

		// Must return false without even calling wp_remote_post.
		$this->assertFalse( $verifier->verify( 'some-token' ) );
	}

	/**
	 * @test
	 * SEC-CAP-08: Only the token is sent, no user data.
	 */
	public function test_verify_sends_only_token_no_user_data(): void {
		$this->stub_captcha_functions( array( 'wp_remote_post' ) );

		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );

		$sent_body    = '';
		$sent_headers = array();

		Functions\expect( 'wp_remote_post' )
			->once()
			->andReturnUsing(
				function ( string $url, array $args ) use ( &$sent_body, &$sent_headers ) {
					$sent_body    = $args['body'];
					$sent_headers = $args['headers'] ?? array();
					return new \WP_Error( 'test', 'test' );
				}
			);

		$verifier = new CaptchaVerifier();
		$verifier->verify( 'my-token' );

		// Body is JSON string with only verification_token (SEC-CAP-08: no IP, no user-agent).
		$decoded = json_decode( $sent_body, true );
		$this->assertIsArray( $decoded );
		$this->assertArrayHasKey( 'verification_token', $decoded );
		$this->assertCount( 1, $decoded );
		$this->assertSame( 'my-token', $decoded['verification_token'] );

		// No user-identifying data in headers.
		$this->assertArrayNotHasKey( 'X-Forwarded-For', $sent_headers );
		$this->assertArrayNotHasKey( 'User-Agent', $sent_headers );
	}

	/**
	 * @test
	 * API contract: Endpoint is /api/validate (not /api/verify).
	 */
	public function test_verify_calls_validate_endpoint(): void {
		$this->stub_captcha_functions( array( 'wp_remote_post' ) );

		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );

		$captured_url = '';

		Functions\expect( 'wp_remote_post' )
			->once()
			->andReturnUsing(
				function ( string $url ) use ( &$captured_url ) {
					$captured_url = $url;
					return new \WP_Error( 'test', 'test' );
				}
			);

		$verifier = new CaptchaVerifier();
		$verifier->verify( 'test-token' );

		$this->assertSame( WPDSGVO_CAPTCHA_URL . '/api/validate', $captured_url );
	}

	/**
	 * @test
	 * API contract: Request body must be JSON (Content-Type: application/json).
	 */
	public function test_verify_sends_json_content_type_header(): void {
		$this->stub_captcha_functions( array( 'wp_remote_post' ) );

		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );

		$sent_content_type = '';

		Functions\expect( 'wp_remote_post' )
			->once()
			->andReturnUsing(
				function ( string $url, array $args ) use ( &$sent_content_type ) {
					$sent_content_type = $args['headers']['Content-Type'] ?? '';
					return new \WP_Error( 'test', 'test' );
				}
			);

		$verifier = new CaptchaVerifier();
		$verifier->verify( 'test-token' );

		$this->assertSame( 'application/json', $sent_content_type );
	}

	/**
	 * @test
	 * API contract: Authorization header with Bearer token from wpdsgvo_captcha_secret option.
	 */
	public function test_verify_sends_authorization_bearer_from_option(): void {
		$this->stub_captcha_functions( array( 'get_option', 'wp_remote_post' ) );

		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );

		$sent_auth = '';

		Functions\expect( 'get_option' )
			->andReturnUsing(
				function ( string $option, $default = false ) {
					if ( 'wpdsgvo_captcha_secret' === $option ) {
						return 'my-secret-api-key';
					}
					return $default;
				}
			);

		Functions\expect( 'wp_remote_post' )
			->once()
			->andReturnUsing(
				function ( string $url, array $args ) use ( &$sent_auth ) {
					$sent_auth = $args['headers']['Authorization'] ?? '';
					return new \WP_Error( 'test', 'test' );
				}
			);

		$verifier = new CaptchaVerifier();
		$verifier->verify( 'test-token' );

		$this->assertSame( 'Bearer my-secret-api-key', $sent_auth );
	}

	/**
	 * @test
	 * Fail-closed on missing API key (server returns error, no valid field).
	 */
	public function test_verify_returns_false_on_missing_api_key_response(): void {
		$this->stub_captcha_functions( array( 'wp_remote_post', 'wp_remote_retrieve_body' ) );

		Functions\expect( 'wp_remote_post' )->once()->andReturn( array() );
		Functions\expect( 'wp_remote_retrieve_body' )->once()->andReturn( '{"error":"missing_api_key"}' );

		$verifier = new CaptchaVerifier();

		$this->assertFalse( $verifier->verify( 'some-token' ) );
	}

	/**
	 * @test
	 * Fail-closed on invalid/expired token (server returns valid:false).
	 */
	public function test_verify_returns_false_on_invalid_token_response(): void {
		$this->stub_captcha_functions( array( 'wp_remote_post', 'wp_remote_retrieve_body' ) );

		Functions\expect( 'wp_remote_post' )->once()->andReturn( array() );
		Functions\expect( 'wp_remote_retrieve_body' )->once()->andReturn( '{"valid":false,"error":"invalid_token"}' );

		$verifier = new CaptchaVerifier();

		$this->assertFalse( $verifier->verify( 'expired-token' ) );
	}

	/**
	 * @test
	 * SEC-CAP-07: is_enabled_for_form returns true when mode is 'always' and form has CAPTCHA enabled.
	 */
	public function test_is_enabled_for_form_returns_true_when_mode_always(): void {
		$this->stub_captcha_functions( array( 'get_option', 'get_transient' ) );

		Functions\expect( 'get_option' )
			->andReturnUsing(
				function ( string $option, $default = false ) {
					if ( 'dsgvo_form_captcha_mode' === $option ) {
						return 'always';
					}
					return $default;
				}
			);

		$form                  = new Form();
		$form->id              = 1;
		$form->captcha_enabled = true;

		Functions\when( 'get_transient' )->alias(
			function ( string $key ) use ( $form ) {
				return ( $key === 'dsgvo_form_1' ) ? $form : false;
			}
		);

		$verifier = new CaptchaVerifier();

		$this->assertTrue( $verifier->is_enabled_for_form( 1 ) );
	}

	/**
	 * @test
	 * SEC-CAP-07: Per-form opt-out via captcha_enabled=false in Form model.
	 */
	public function test_is_enabled_for_form_returns_false_when_form_captcha_disabled(): void {
		$this->stub_captcha_functions( array( 'get_option', 'get_transient' ) );

		Functions\expect( 'get_option' )
			->andReturnUsing(
				function ( string $option, $default = false ) {
					if ( 'dsgvo_form_captcha_mode' === $option ) {
						return 'always';
					}
					return $default;
				}
			);

		$form                  = new Form();
		$form->id              = 42;
		$form->captcha_enabled = false;

		Functions\when( 'get_transient' )->alias(
			function ( string $key ) use ( $form ) {
				return ( $key === 'dsgvo_form_42' ) ? $form : false;
			}
		);

		$verifier = new CaptchaVerifier();

		$this->assertFalse( $verifier->is_enabled_for_form( 42 ) );
	}

	/**
	 * @test
	 * SEC-CAP-07: Unknown form ID returns true (secure default — fail-closed).
	 */
	public function test_is_enabled_for_form_returns_true_for_unknown_form(): void {
		$this->stub_captcha_functions( array( 'get_option', 'get_transient', 'set_transient' ) );

		Functions\expect( 'get_option' )
			->andReturnUsing(
				function ( string $option, $default = false ) {
					if ( 'dsgvo_form_captcha_mode' === $option ) {
						return 'always';
					}
					return $default;
				}
			);

		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );

		$wpdb         = \Mockery::mock( 'wpdb' );
		$wpdb->prefix = 'wp_';
		$wpdb->shouldReceive( 'prepare' )->andReturn( 'SQL' );
		$wpdb->shouldReceive( 'get_row' )->andReturn( null );
		$GLOBALS['wpdb'] = $wpdb;

		$verifier = new CaptchaVerifier();

		$this->assertTrue( $verifier->is_enabled_for_form( 999 ) );
	}

	/**
	 * @test
	 * SEC-CAP-07: Global kill-switch overrides per-form setting.
	 */
	public function test_is_enabled_for_form_global_off_overrides_form_enabled(): void {
		$this->stub_captcha_functions( array( 'get_option' ) );

		Functions\expect( 'get_option' )
			->andReturnUsing(
				function ( string $option, $default = false ) {
					if ( 'dsgvo_form_captcha_mode' === $option ) {
						return 'off';
					}
					return $default;
				}
			);

		$verifier = new CaptchaVerifier();

		// Even if form would have captcha_enabled=true, global 'off' wins.
		$this->assertFalse( $verifier->is_enabled_for_form( 1 ) );
	}

	/**
	 * @test
	 * Task #278: Constructor uses WPDSGVO_CAPTCHA_URL constant for base_url.
	 */
	public function test_constructor_uses_constant_for_base_url(): void {
		$this->stub_captcha_functions( array( 'wp_remote_post' ) );

		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );

		$captured_url = '';

		Functions\expect( 'wp_remote_post' )
			->once()
			->andReturnUsing(
				function ( string $url ) use ( &$captured_url ) {
					$captured_url = $url;
					return new \WP_Error( 'test', 'test' );
				}
			);

		$verifier = new CaptchaVerifier();
		$verifier->verify( 'test' );

		$this->assertStringStartsWith( WPDSGVO_CAPTCHA_URL, $captured_url );
		$this->assertSame( WPDSGVO_CAPTCHA_URL . '/api/validate', $captured_url );
	}

	/**
	 * @test
	 * get_script_url returns URL to captcha.js on the CAPTCHA server.
	 */
	public function test_get_script_url_returns_captcha_js_url(): void {
		$verifier = new CaptchaVerifier();

		$this->assertSame( WPDSGVO_CAPTCHA_URL . '/captcha.js', $verifier->get_script_url() );
	}
}
