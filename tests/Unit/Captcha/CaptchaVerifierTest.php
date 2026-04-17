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
		$defaults = array(
			'get_option' => null,
		);

		foreach ( $defaults as $func => $return ) {
			if ( ! in_array( $func, $skip, true ) ) {
				Functions\when( $func )->justReturn( null );
			}
		}
	}

	/**
	 * @test
	 * SEC-CAP-01: Empty token must be rejected immediately.
	 */
	public function test_verify_returns_false_for_empty_token(): void {
		$this->stub_captcha_functions();

		$verifier = new CaptchaVerifier( 'https://captcha.example.com/api/verify' );

		$this->assertFalse( $verifier->verify( '' ) );
	}

	/**
	 * @test
	 * SEC-CAP-01: Successful server-side verification via POST.
	 */
	public function test_verify_returns_true_on_successful_verification(): void {
		$this->stub_captcha_functions( array( 'wp_remote_post', 'wp_remote_retrieve_response_code', 'wp_remote_retrieve_body' ) );

		Functions\expect( 'wp_remote_post' )
			->once()
			->with(
				'https://captcha.example.com/api/verify',
				\Mockery::on(
					function ( array $args ): bool {
						return $args['body']['token'] === 'valid-token'
							&& $args['timeout'] === 5
							&& $args['sslverify'] === true;
					}
				)
			)
			->andReturn( array( 'response' => array( 'code' => 200 ), 'body' => '{"valid":true}' ) );

		Functions\expect( 'wp_remote_retrieve_response_code' )
			->once()
			->andReturn( 200 );

		Functions\expect( 'wp_remote_retrieve_body' )
			->once()
			->andReturn( '{"valid":true}' );

		$verifier = new CaptchaVerifier( 'https://captcha.example.com/api/verify' );

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

		$verifier = new CaptchaVerifier( 'https://captcha.example.com/api/verify' );

		$this->assertFalse( $verifier->verify( 'some-token' ) );
	}

	/**
	 * @test
	 * SEC-CAP-04: Fail-closed on non-2xx status code.
	 */
	public function test_verify_returns_false_on_non_200_status(): void {
		$this->stub_captcha_functions( array( 'wp_remote_post', 'wp_remote_retrieve_response_code' ) );

		Functions\expect( 'wp_remote_post' )->once()->andReturn( array() );
		Functions\expect( 'wp_remote_retrieve_response_code' )->once()->andReturn( 500 );

		$verifier = new CaptchaVerifier( 'https://captcha.example.com/api/verify' );

		$this->assertFalse( $verifier->verify( 'some-token' ) );
	}

	/**
	 * @test
	 * SEC-CAP-04: Fail-closed on invalid JSON response body.
	 */
	public function test_verify_returns_false_on_invalid_json_response(): void {
		$this->stub_captcha_functions( array( 'wp_remote_post', 'wp_remote_retrieve_response_code', 'wp_remote_retrieve_body' ) );

		Functions\expect( 'wp_remote_post' )->once()->andReturn( array() );
		Functions\expect( 'wp_remote_retrieve_response_code' )->once()->andReturn( 200 );
		Functions\expect( 'wp_remote_retrieve_body' )->once()->andReturn( 'not valid json' );

		$verifier = new CaptchaVerifier( 'https://captcha.example.com/api/verify' );

		$this->assertFalse( $verifier->verify( 'some-token' ) );
	}

	/**
	 * @test
	 * SEC-CAP-04: Fail-closed when valid field is missing or false.
	 */
	public function test_verify_returns_false_when_valid_is_false(): void {
		$this->stub_captcha_functions( array( 'wp_remote_post', 'wp_remote_retrieve_response_code', 'wp_remote_retrieve_body' ) );

		Functions\expect( 'wp_remote_post' )->once()->andReturn( array() );
		Functions\expect( 'wp_remote_retrieve_response_code' )->once()->andReturn( 200 );
		Functions\expect( 'wp_remote_retrieve_body' )->once()->andReturn( '{"valid":false}' );

		$verifier = new CaptchaVerifier( 'https://captcha.example.com/api/verify' );

		$this->assertFalse( $verifier->verify( 'invalid-token' ) );
	}

	/**
	 * @test
	 * SEC-CAP-06: HTTP URLs must be rejected and replaced with default HTTPS URL.
	 */
	public function test_constructor_enforces_https_on_verify_url(): void {
		$this->stub_captcha_functions( array( 'wp_remote_post' ) );

		$captured_url = '';

		Functions\expect( 'wp_remote_post' )
			->once()
			->andReturnUsing(
				function ( string $url ) use ( &$captured_url ) {
					$captured_url = $url;
					return new \WP_Error( 'test', 'test' );
				}
			);

		// Pass an HTTP URL (insecure).
		$verifier = new CaptchaVerifier( 'http://evil.example.com/verify' );
		$verifier->verify( 'test-token' );

		// Must fall back to the default HTTPS URL.
		$this->assertStringStartsWith( 'https://', $captured_url );
		$this->assertSame( 'https://captcha.repaircafe-bruchsal.de/api/verify', $captured_url );
	}

	/**
	 * @test
	 * SEC-CAP-08: Only the token is sent, no user data.
	 */
	public function test_verify_sends_only_token_no_user_data(): void {
		$this->stub_captcha_functions( array( 'wp_remote_post' ) );

		$sent_body = array();

		Functions\expect( 'wp_remote_post' )
			->once()
			->andReturnUsing(
				function ( string $url, array $args ) use ( &$sent_body ) {
					$sent_body = $args['body'];
					return new \WP_Error( 'test', 'test' );
				}
			);

		$verifier = new CaptchaVerifier( 'https://captcha.example.com/api/verify' );
		$verifier->verify( 'my-token' );

		// Only token key should be present (SEC-CAP-08: no IP, no user-agent).
		$this->assertArrayHasKey( 'token', $sent_body );
		$this->assertCount( 1, $sent_body );
		$this->assertSame( 'my-token', $sent_body['token'] );
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

		$verifier = new CaptchaVerifier( 'https://captcha.example.com/api/verify' );

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

		$verifier = new CaptchaVerifier( 'https://captcha.example.com/api/verify' );

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

		$verifier = new CaptchaVerifier( 'https://captcha.example.com/api/verify' );

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

		$verifier = new CaptchaVerifier( 'https://captcha.example.com/api/verify' );

		// Even if form would have captcha_enabled=true, global 'off' wins.
		$this->assertFalse( $verifier->is_enabled_for_form( 1 ) );
	}

	/**
	 * @test
	 * Constructor loads URL from get_option when not provided.
	 */
	public function test_constructor_loads_verify_url_from_option(): void {
		$this->stub_captcha_functions( array( 'get_option', 'wp_remote_post' ) );

		$captured_url = '';

		Functions\expect( 'get_option' )
			->with( 'wpdsgvo_captcha_verify_url', \Mockery::type( 'string' ) )
			->andReturn( 'https://custom-captcha.example.com/verify' );

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

		$this->assertSame( 'https://custom-captcha.example.com/verify', $captured_url );
	}
}
