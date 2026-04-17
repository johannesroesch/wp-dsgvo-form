<?php
/**
 * Unit tests for SubmissionListView.
 *
 * @package WpDsgvoForm\Tests\Unit\Recipient
 */

declare(strict_types=1);

namespace WpDsgvoForm\Tests\Unit\Recipient;

use WpDsgvoForm\Auth\AccessControl;
use WpDsgvoForm\Audit\AuditLogger;
use WpDsgvoForm\Models\Form;
use WpDsgvoForm\Models\Submission;
use WpDsgvoForm\Recipient\SubmissionListView;
use WpDsgvoForm\Tests\TestCase;
use Brain\Monkey\Functions;
use Mockery;

/**
 * Tests for SubmissionListView: supervisor audit logging, accessible forms,
 * status labels, filter rendering, and empty state.
 */
class SubmissionListViewTest extends TestCase {

	private AccessControl $access_control;
	private SubmissionListView $view;
	private object $wpdb;

	protected function setUp(): void {
		parent::setUp();

		$this->access_control = Mockery::mock( AccessControl::class );
		$this->view           = new SubmissionListView( $this->access_control );

		$this->wpdb         = Mockery::mock( 'wpdb' );
		$this->wpdb->prefix = 'wp_';
		$this->wpdb->insert_id  = 0;
		$this->wpdb->last_error = '';
		$GLOBALS['wpdb']    = $this->wpdb;

		// Common WP function stubs.
		Functions\stubs(
			[
				'esc_html'    => function ( string $text ): string {
					return $text;
				},
				'esc_html__'  => function ( string $text, string $domain = '' ): string {
					return $text;
				},
				'esc_html_e'  => function ( string $text, string $domain = '' ): void {
					echo $text;
				},
				'esc_url'     => function ( string $url ): string {
					return $url;
				},
				'esc_attr'    => function ( string $text ): string {
					return $text;
				},
				'__'          => function ( string $text, string $domain = '' ): string {
					return $text;
				},
				'home_url'    => function ( string $path = '' ): string {
					return 'https://example.com' . $path;
				},
				'selected'    => function ( $selected, $current = true, bool $echo = true ): string {
					$result = $selected == $current ? ' selected="selected"' : '';
					if ( $echo ) {
						echo $result;
					}
					return $result;
				},
				'absint'      => function ( $value ): int {
					return abs( (int) $value );
				},
				'_n'          => function ( string $single, string $plural, int $count, string $domain = '' ): string {
					return $count === 1 ? $single : $plural;
				},
			]
		);

		Functions\when( 'sanitize_text_field' )->returnArg();
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		$_GET = [];
		parent::tearDown();
	}

	// ------------------------------------------------------------------
	// render — supervisor audit logging
	// ------------------------------------------------------------------

	public function test_render_logs_access_for_supervisor(): void {
		$this->access_control->shouldReceive( 'is_supervisor' )
			->with( 42 )->andReturn( true );
		$this->access_control->shouldReceive( 'is_admin' )
			->with( 42 )->andReturn( false );

		// AuditLogger::log expects wpdb insert.
		$this->wpdb->shouldReceive( 'insert' )->once()->andReturn( 1 );
		Functions\when( 'current_time' )->justReturn( '2026-01-01 00:00:00' );
		Functions\when( 'wp_unslash' )->returnArg();

		// Form::find_all for supervisor — returns empty.
		$this->wpdb->shouldReceive( 'prepare' )->andReturn( 'SQL' );
		$this->wpdb->shouldReceive( 'get_results' )->andReturn( [] );

		$_GET = [];

		ob_start();
		$this->view->render( 42 );
		$output = ob_get_clean();

		// Table should show "no submissions" state.
		$this->assertStringContainsString( 'Keine Einsendungen gefunden.', $output );
	}

	// ------------------------------------------------------------------
	// render — reader does not trigger audit
	// ------------------------------------------------------------------

	public function test_render_does_not_log_for_reader(): void {
		$this->access_control->shouldReceive( 'is_supervisor' )
			->with( 10 )->andReturn( false );
		$this->access_control->shouldReceive( 'is_admin' )
			->with( 10 )->andReturn( false );

		// For reader, get_assigned_forms queries recipients table.
		$this->wpdb->shouldReceive( 'prepare' )->andReturn( 'SQL' );
		$this->wpdb->shouldReceive( 'get_col' )->andReturn( [] ); // no assigned forms

		// AuditLogger::log should NOT be called (no wpdb->insert for audit).
		$this->wpdb->shouldNotReceive( 'insert' );

		$_GET = [];

		ob_start();
		$this->view->render( 10 );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Keine Einsendungen gefunden.', $output );
	}

	// ------------------------------------------------------------------
	// render — empty submissions shows message
	// ------------------------------------------------------------------

	public function test_render_shows_empty_message_when_no_submissions(): void {
		$this->access_control->shouldReceive( 'is_supervisor' )
			->with( 1 )->andReturn( true );
		$this->access_control->shouldReceive( 'is_admin' )
			->with( 1 )->andReturn( false );

		$this->wpdb->shouldReceive( 'insert' )->andReturn( 1 ); // audit
		$this->wpdb->shouldReceive( 'prepare' )->andReturn( 'SQL' );
		$this->wpdb->shouldReceive( 'get_results' )->andReturn( [] ); // no forms
		Functions\when( 'current_time' )->justReturn( '2026-01-01 00:00:00' );
		Functions\when( 'wp_unslash' )->returnArg();

		$_GET = [];

		ob_start();
		$this->view->render( 1 );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Keine Einsendungen gefunden.', $output );
	}

	// ------------------------------------------------------------------
	// render — form filter access denied resets filter
	// ------------------------------------------------------------------

	public function test_render_resets_form_filter_when_access_denied(): void {
		$this->access_control->shouldReceive( 'is_supervisor' )
			->with( 10 )->andReturn( false );
		$this->access_control->shouldReceive( 'is_admin' )
			->with( 10 )->andReturn( false );
		$this->access_control->shouldReceive( 'can_view_form' )
			->with( 10, 99 )->andReturn( false );

		// Reader with no assigned forms.
		$this->wpdb->shouldReceive( 'prepare' )->andReturn( 'SQL' );
		$this->wpdb->shouldReceive( 'get_col' )->andReturn( [] );

		$_GET = [ 'form_id' => '99' ];

		ob_start();
		$this->view->render( 10 );
		$output = ob_get_clean();

		// Should not crash, should show empty message.
		$this->assertStringContainsString( 'Keine Einsendungen gefunden.', $output );
	}

	// ------------------------------------------------------------------
	// get_status_label (tested indirectly via render output)
	// ------------------------------------------------------------------

	public function test_render_shows_correct_status_labels(): void {
		$this->access_control->shouldReceive( 'is_supervisor' )
			->with( 1 )->andReturn( true );
		$this->access_control->shouldReceive( 'is_admin' )
			->with( 1 )->andReturn( false );

		$this->wpdb->shouldReceive( 'insert' )->andReturn( 1 ); // audit
		Functions\when( 'current_time' )->justReturn( '2026-01-01 00:00:00' );
		Functions\when( 'wp_unslash' )->returnArg();

		// Form::find_all returns one form.
		$form_row = [
			'id'                   => '1',
			'title'                => 'Kontakt',
			'slug'                 => 'kontakt',
			'is_active'            => '1',
			'retention_days'       => '90',
			'legal_basis'          => 'consent',
			'consent_text'         => 'Ja',
			'consent_text_version' => '1',
			'consent_locale'       => 'de_DE',
			'recipient_email'      => null,
			'encrypted_dek'        => null,
			'dek_iv'               => null,
			'created_at'           => '2026-01-01 00:00:00',
			'updated_at'           => '2026-01-01 00:00:00',
		];
		$this->wpdb->shouldReceive( 'prepare' )->andReturn( 'SQL' );
		$this->wpdb->shouldReceive( 'get_results' )
			->andReturn(
				[ $form_row ], // Form::find_all
				[
					// Submission rows for form 1.
					[
						'id'                   => '100',
						'form_id'              => '1',
						'submitted_at'         => '2026-01-02 10:00:00',
						'is_read'              => '0',
						'expires_at'           => null,
						'consent_text_version' => null,
						'consent_timestamp'    => null,
						'email_lookup_hash'    => null,
						'consent_locale'       => null,
						'is_restricted'        => '0',
					],
					[
						'id'                   => '101',
						'form_id'              => '1',
						'submitted_at'         => '2026-01-01 10:00:00',
						'is_read'              => '1',
						'expires_at'           => null,
						'consent_text_version' => null,
						'consent_timestamp'    => null,
						'email_lookup_hash'    => null,
						'consent_locale'       => null,
						'is_restricted'        => '0',
					],
					[
						'id'                   => '102',
						'form_id'              => '1',
						'submitted_at'         => '2026-01-01 09:00:00',
						'is_read'              => '0',
						'expires_at'           => null,
						'consent_text_version' => null,
						'consent_timestamp'    => null,
						'email_lookup_hash'    => null,
						'consent_locale'       => null,
						'is_restricted'        => '1',
					],
				]
			);

		// Submission::count_by_form_ids — COUNT query for pagination.
		$this->wpdb->shouldReceive( 'get_var' )->andReturn( '3' );

		Functions\when( 'wp_date' )->justReturn( '01.01.2026 10:00' );
		Functions\when( 'get_option' )->justReturn( 'Y-m-d' );
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );

		$_GET = [];

		ob_start();
		$this->view->render( 1 );
		$output = ob_get_clean();

		// Should show all three status types.
		$this->assertStringContainsString( 'Neu', $output );
		$this->assertStringContainsString( 'Gelesen', $output );
		$this->assertStringContainsString( 'Gesperrt (Art. 18)', $output );
	}

	// ------------------------------------------------------------------
	// render — Reader IDOR: sees only assigned forms (SEC-AUTH-DSGVO-03)
	// ------------------------------------------------------------------

	/**
	 * @security-relevant SEC-AUTH-DSGVO-03 — Reader only sees submissions for assigned forms
	 */
	public function test_reader_sees_only_submissions_from_assigned_forms(): void {
		$this->access_control->shouldReceive( 'is_supervisor' )
			->with( 20 )->andReturn( false );
		$this->access_control->shouldReceive( 'is_admin' )
			->with( 20 )->andReturn( false );

		// Reader is assigned to form 2 only.
		$this->wpdb->shouldReceive( 'prepare' )->andReturn( 'SQL' );
		$this->wpdb->shouldReceive( 'get_col' )->andReturn( [ '2' ] );

		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );

		// Form::find(2).
		$this->wpdb->shouldReceive( 'get_row' )->andReturn( [
			'id' => '2', 'title' => 'Bewerbung', 'slug' => 'bewerbung',
			'is_active' => '1', 'retention_days' => '90', 'legal_basis' => 'consent',
			'consent_text' => 'Ja', 'consent_text_version' => '1',
			'consent_locale' => 'de_DE', 'recipient_email' => null,
			'encrypted_dek' => null, 'dek_iv' => null,
			'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
		] );

		// Submissions for form 2 (aggregated view).
		$this->wpdb->shouldReceive( 'get_results' )->andReturn( [
			[
				'id' => '10', 'form_id' => '2', 'submitted_at' => '2026-04-17 10:00:00',
				'is_read' => '0', 'expires_at' => null, 'consent_text_version' => null,
				'consent_timestamp' => null, 'email_lookup_hash' => null,
				'consent_locale' => null, 'is_restricted' => '0',
			],
		] );

		// Submission::count_by_form_ids — COUNT query for pagination.
		$this->wpdb->shouldReceive( 'get_var' )->andReturn( '1' );

		Functions\when( 'wp_date' )->justReturn( '17.04.2026 10:00' );
		Functions\when( 'get_option' )->justReturn( 'Y-m-d' );

		$_GET = [];

		ob_start();
		$this->view->render( 20 );
		$output = ob_get_clean();

		// Form 2's title should appear.
		$this->assertStringContainsString( 'Bewerbung', $output );
		// Form 1 should NOT appear (not assigned).
		$this->assertStringNotContainsString( 'Kontakt', $output );
	}

	// ------------------------------------------------------------------
	// render — Supervisor sees all forms
	// ------------------------------------------------------------------

	public function test_supervisor_sees_all_forms(): void {
		$this->access_control->shouldReceive( 'is_supervisor' )
			->with( 42 )->andReturn( true );
		$this->access_control->shouldReceive( 'is_admin' )
			->with( 42 )->andReturn( false );

		$this->wpdb->shouldReceive( 'insert' )->andReturn( 1 ); // audit
		Functions\when( 'current_time' )->justReturn( '2026-01-01 00:00:00' );
		Functions\when( 'wp_unslash' )->returnArg();

		// Form::find_all returns 2 forms.
		$form1 = [
			'id' => '1', 'title' => 'Kontakt', 'slug' => 'kontakt',
			'is_active' => '1', 'retention_days' => '90', 'legal_basis' => 'consent',
			'consent_text' => 'Ja', 'consent_text_version' => '1',
			'consent_locale' => 'de_DE', 'recipient_email' => null,
			'encrypted_dek' => null, 'dek_iv' => null,
			'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
		];
		$form2 = [
			'id' => '2', 'title' => 'Bewerbung', 'slug' => 'bewerbung',
			'is_active' => '1', 'retention_days' => '90', 'legal_basis' => 'contract',
			'consent_text' => '', 'consent_text_version' => '1',
			'consent_locale' => null, 'recipient_email' => null,
			'encrypted_dek' => null, 'dek_iv' => null,
			'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
		];

		$this->wpdb->shouldReceive( 'prepare' )->andReturn( 'SQL' );
		$this->wpdb->shouldReceive( 'get_results' )->andReturn(
			[ $form1, $form2 ], // Form::find_all
			// Submission::find_by_form_ids — combined query for all forms.
			[
				[
					'id' => '10', 'form_id' => '1', 'submitted_at' => '2026-04-17 10:00:00',
					'is_read' => '0', 'expires_at' => null, 'consent_text_version' => null,
					'consent_timestamp' => null, 'email_lookup_hash' => null,
					'consent_locale' => null, 'is_restricted' => '0',
				],
				[
					'id' => '11', 'form_id' => '2', 'submitted_at' => '2026-04-17 09:00:00',
					'is_read' => '1', 'expires_at' => null, 'consent_text_version' => null,
					'consent_timestamp' => null, 'email_lookup_hash' => null,
					'consent_locale' => null, 'is_restricted' => '0',
				],
			]
		);

		// Submission::count_by_form_ids — COUNT query for pagination.
		$this->wpdb->shouldReceive( 'get_var' )->andReturn( '2' );

		Functions\when( 'wp_date' )->justReturn( '17.04.2026 10:00' );
		Functions\when( 'get_option' )->justReturn( 'Y-m-d' );
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );

		$_GET = [];

		ob_start();
		$this->view->render( 42 );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Kontakt', $output );
		$this->assertStringContainsString( 'Bewerbung', $output );
	}

	// ------------------------------------------------------------------
	// render — status filter "new" returns unread
	// ------------------------------------------------------------------

	public function test_status_filter_new_shows_unread_only(): void {
		$this->access_control->shouldReceive( 'is_supervisor' )
			->with( 1 )->andReturn( true );
		$this->access_control->shouldReceive( 'is_admin' )
			->with( 1 )->andReturn( false );
		$this->access_control->shouldReceive( 'can_view_form' )
			->with( 1, 1 )->andReturn( true );

		$this->wpdb->shouldReceive( 'insert' )->andReturn( 1 );
		Functions\when( 'current_time' )->justReturn( '2026-01-01 00:00:00' );
		Functions\when( 'wp_unslash' )->returnArg();

		$form_row = [
			'id' => '1', 'title' => 'Kontakt', 'slug' => 'kontakt',
			'is_active' => '1', 'retention_days' => '90', 'legal_basis' => 'consent',
			'consent_text' => 'Ja', 'consent_text_version' => '1',
			'consent_locale' => 'de_DE', 'recipient_email' => null,
			'encrypted_dek' => null, 'dek_iv' => null,
			'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
		];

		$this->wpdb->shouldReceive( 'prepare' )->andReturn( 'SQL' );
		$this->wpdb->shouldReceive( 'get_results' )->andReturn(
			[ $form_row ],
			// Only unread submissions returned by DB.
			[
				[
					'id' => '50', 'form_id' => '1', 'submitted_at' => '2026-04-17 10:00:00',
					'is_read' => '0', 'expires_at' => null, 'consent_text_version' => null,
					'consent_timestamp' => null, 'email_lookup_hash' => null,
					'consent_locale' => null, 'is_restricted' => '0',
				],
			]
		);
		$this->wpdb->shouldReceive( 'get_var' )->andReturn( '1' );

		Functions\when( 'wp_date' )->justReturn( '17.04.2026 10:00' );
		Functions\when( 'get_option' )->justReturn( 'Y-m-d' );
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );

		$_GET = [ 'form_id' => '1', 'status' => 'new' ];

		ob_start();
		$this->view->render( 1 );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Neu', $output );
		$this->assertStringContainsString( 'Anzeigen', $output );
	}

	// ------------------------------------------------------------------
	// render — unread row has bold style
	// ------------------------------------------------------------------

	public function test_unread_submission_row_has_bold_font_weight(): void {
		$this->access_control->shouldReceive( 'is_supervisor' )
			->with( 1 )->andReturn( true );
		$this->access_control->shouldReceive( 'is_admin' )
			->with( 1 )->andReturn( false );
		$this->access_control->shouldReceive( 'can_view_form' )
			->with( 1, 1 )->andReturn( true );

		$this->wpdb->shouldReceive( 'insert' )->andReturn( 1 );
		Functions\when( 'current_time' )->justReturn( '2026-01-01 00:00:00' );
		Functions\when( 'wp_unslash' )->returnArg();

		$form_row = [
			'id' => '1', 'title' => 'Kontakt', 'slug' => 'kontakt',
			'is_active' => '1', 'retention_days' => '90', 'legal_basis' => 'consent',
			'consent_text' => 'Ja', 'consent_text_version' => '1',
			'consent_locale' => 'de_DE', 'recipient_email' => null,
			'encrypted_dek' => null, 'dek_iv' => null,
			'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
		];

		$this->wpdb->shouldReceive( 'prepare' )->andReturn( 'SQL' );
		$this->wpdb->shouldReceive( 'get_results' )->andReturn(
			[ $form_row ],
			[
				[
					'id' => '60', 'form_id' => '1', 'submitted_at' => '2026-04-17 10:00:00',
					'is_read' => '0', 'expires_at' => null, 'consent_text_version' => null,
					'consent_timestamp' => null, 'email_lookup_hash' => null,
					'consent_locale' => null, 'is_restricted' => '0',
				],
			]
		);
		$this->wpdb->shouldReceive( 'get_var' )->andReturn( '1' );

		Functions\when( 'wp_date' )->justReturn( '17.04.2026 10:00' );
		Functions\when( 'get_option' )->justReturn( 'Y-m-d' );
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );

		$_GET = [ 'form_id' => '1' ];

		ob_start();
		$this->view->render( 1 );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'font-weight:600', $output );
	}

	// ------------------------------------------------------------------
	// render — view links point to correct detail URL
	// ------------------------------------------------------------------

	public function test_table_rows_have_correct_view_link(): void {
		$this->access_control->shouldReceive( 'is_supervisor' )
			->with( 1 )->andReturn( true );
		$this->access_control->shouldReceive( 'is_admin' )
			->with( 1 )->andReturn( false );
		$this->access_control->shouldReceive( 'can_view_form' )
			->with( 1, 1 )->andReturn( true );

		$this->wpdb->shouldReceive( 'insert' )->andReturn( 1 );
		Functions\when( 'current_time' )->justReturn( '2026-01-01 00:00:00' );
		Functions\when( 'wp_unslash' )->returnArg();

		$form_row = [
			'id' => '1', 'title' => 'Kontakt', 'slug' => 'kontakt',
			'is_active' => '1', 'retention_days' => '90', 'legal_basis' => 'consent',
			'consent_text' => 'Ja', 'consent_text_version' => '1',
			'consent_locale' => 'de_DE', 'recipient_email' => null,
			'encrypted_dek' => null, 'dek_iv' => null,
			'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
		];

		$this->wpdb->shouldReceive( 'prepare' )->andReturn( 'SQL' );
		$this->wpdb->shouldReceive( 'get_results' )->andReturn(
			[ $form_row ],
			[
				[
					'id' => '42', 'form_id' => '1', 'submitted_at' => '2026-04-17 10:00:00',
					'is_read' => '1', 'expires_at' => null, 'consent_text_version' => null,
					'consent_timestamp' => null, 'email_lookup_hash' => null,
					'consent_locale' => null, 'is_restricted' => '0',
				],
			]
		);
		$this->wpdb->shouldReceive( 'get_var' )->andReturn( '1' );

		Functions\when( 'wp_date' )->justReturn( '17.04.2026 10:00' );
		Functions\when( 'get_option' )->justReturn( 'Y-m-d' );
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );

		$_GET = [ 'form_id' => '1' ];

		ob_start();
		$this->view->render( 1 );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'dsgvo-empfaenger/view/42/', $output );
		$this->assertStringContainsString( 'Anzeigen', $output );
	}

	// ------------------------------------------------------------------
	// render — filter reset link shows when filters active
	// ------------------------------------------------------------------

	public function test_filter_reset_link_shown_when_filters_active(): void {
		$this->access_control->shouldReceive( 'is_supervisor' )
			->with( 1 )->andReturn( true );
		$this->access_control->shouldReceive( 'is_admin' )
			->with( 1 )->andReturn( false );
		$this->access_control->shouldReceive( 'can_view_form' )
			->with( 1, 1 )->andReturn( true );

		$this->wpdb->shouldReceive( 'insert' )->andReturn( 1 );
		Functions\when( 'current_time' )->justReturn( '2026-01-01 00:00:00' );
		Functions\when( 'wp_unslash' )->returnArg();

		$form_row = [
			'id' => '1', 'title' => 'Kontakt', 'slug' => 'kontakt',
			'is_active' => '1', 'retention_days' => '90', 'legal_basis' => 'consent',
			'consent_text' => 'Ja', 'consent_text_version' => '1',
			'consent_locale' => 'de_DE', 'recipient_email' => null,
			'encrypted_dek' => null, 'dek_iv' => null,
			'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
		];

		$this->wpdb->shouldReceive( 'prepare' )->andReturn( 'SQL' );
		$this->wpdb->shouldReceive( 'get_results' )->andReturn( [ $form_row ], [] );
		$this->wpdb->shouldReceive( 'get_var' )->andReturn( '0' );

		Functions\when( 'wp_date' )->justReturn( '17.04.2026 10:00' );
		Functions\when( 'get_option' )->justReturn( 'Y-m-d' );
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );

		$_GET = [ 'form_id' => '1', 'status' => 'new' ];

		ob_start();
		$this->view->render( 1 );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Filter zuruecksetzen', $output );
	}

	public function test_filter_reset_link_hidden_when_no_filters(): void {
		$this->access_control->shouldReceive( 'is_supervisor' )
			->with( 1 )->andReturn( true );
		$this->access_control->shouldReceive( 'is_admin' )
			->with( 1 )->andReturn( false );

		$this->wpdb->shouldReceive( 'insert' )->andReturn( 1 );
		Functions\when( 'current_time' )->justReturn( '2026-01-01 00:00:00' );
		Functions\when( 'wp_unslash' )->returnArg();

		$this->wpdb->shouldReceive( 'prepare' )->andReturn( 'SQL' );
		$this->wpdb->shouldReceive( 'get_results' )->andReturn( [] );

		$_GET = [];

		ob_start();
		$this->view->render( 1 );
		$output = ob_get_clean();

		$this->assertStringNotContainsString( 'Filter zuruecksetzen', $output );
	}

	// ------------------------------------------------------------------
	// render — pagination info shows total count
	// ------------------------------------------------------------------

	public function test_render_shows_total_count_in_pagination(): void {
		$this->access_control->shouldReceive( 'is_supervisor' )
			->with( 1 )->andReturn( true );
		$this->access_control->shouldReceive( 'is_admin' )
			->with( 1 )->andReturn( false );

		$this->wpdb->shouldReceive( 'insert' )->andReturn( 1 ); // audit
		Functions\when( 'current_time' )->justReturn( '2026-01-01 00:00:00' );
		Functions\when( 'wp_unslash' )->returnArg();

		// One form, two submissions (less than PER_PAGE).
		$form_row = [
			'id'                   => '1',
			'title'                => 'Kontakt',
			'slug'                 => 'kontakt',
			'is_active'            => '1',
			'retention_days'       => '90',
			'legal_basis'          => 'consent',
			'consent_text'         => 'Ja',
			'consent_text_version' => '1',
			'consent_locale'       => 'de_DE',
			'recipient_email'      => null,
			'encrypted_dek'        => null,
			'dek_iv'               => null,
			'created_at'           => '2026-01-01 00:00:00',
			'updated_at'           => '2026-01-01 00:00:00',
		];

		$this->wpdb->shouldReceive( 'prepare' )->andReturn( 'SQL' );
		$this->wpdb->shouldReceive( 'get_results' )
			->andReturn(
				[ $form_row ],
				[
					[
						'id'                   => '100',
						'form_id'              => '1',
						'submitted_at'         => '2026-01-02 10:00:00',
						'is_read'              => '0',
						'expires_at'           => null,
						'consent_text_version' => null,
						'consent_timestamp'    => null,
						'email_lookup_hash'    => null,
						'consent_locale'       => null,
						'is_restricted'        => '0',
					],
					[
						'id'                   => '101',
						'form_id'              => '1',
						'submitted_at'         => '2026-01-01 10:00:00',
						'is_read'              => '1',
						'expires_at'           => null,
						'consent_text_version' => null,
						'consent_timestamp'    => null,
						'email_lookup_hash'    => null,
						'consent_locale'       => null,
						'is_restricted'        => '0',
					],
				]
			);

		// Submission::count_by_form_ids — COUNT query for pagination.
		$this->wpdb->shouldReceive( 'get_var' )->andReturn( '2' );

		Functions\when( 'wp_date' )->justReturn( '01.01.2026 10:00' );
		Functions\when( 'get_option' )->justReturn( 'Y-m-d' );
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );

		$_GET = [];

		ob_start();
		$this->view->render( 1 );
		$output = ob_get_clean();

		// With 2 submissions < PER_PAGE, shows _n() count (not page/total).
		$this->assertStringContainsString( 'Einsendungen', $output );
	}

	// ------------------------------------------------------------------
	// Task #112: PER_PAGE constant is sane (no PHP_INT_MAX)
	// ------------------------------------------------------------------

	/**
	 * @test
	 * @performance-relevant Task #112 — Pagination uses sane limit, not PHP_INT_MAX
	 */
	public function test_per_page_constant_is_sane_limit(): void {
		// Verify via reflection that PER_PAGE is a reasonable number.
		$reflection = new \ReflectionClass( SubmissionListView::class );
		$constant   = $reflection->getConstant( 'PER_PAGE' );

		$this->assertIsInt( $constant );
		$this->assertGreaterThan( 0, $constant );
		$this->assertLessThanOrEqual( 100, $constant, 'PER_PAGE must not exceed 100 to prevent memory issues.' );
	}
}
