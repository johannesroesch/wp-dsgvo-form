<?php
/**
 * Unit tests for FormListTable.
 *
 * Covers:
 * - #283 FLT-02: New "Rechtsgrundlage" column + column_legal_basis() method
 * - #284 FLT-01: Row-action "Einwilligungstexte" only for consent legal basis
 *
 * @package WpDsgvoForm\Tests\Unit\Admin
 */

declare(strict_types=1);

namespace WpDsgvoForm\Tests\Unit\Admin;

use WpDsgvoForm\Admin\FormListTable;
use WpDsgvoForm\Models\Form;
use WpDsgvoForm\Tests\TestCase;
use Brain\Monkey\Functions;

/**
 * Tests for FormListTable column rendering and row actions.
 */
class FormListTableTest extends TestCase {

	/**
	 * @inheritDoc
	 */
	protected function setUp(): void {
		parent::setUp();

		// Common WP function stubs.
		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'esc_js' )->returnArg();
		Functions\when( 'admin_url' )->alias(
			function ( string $path = '' ): string {
				return 'https://example.com/wp-admin/' . $path;
			}
		);
		Functions\when( 'wp_nonce_url' )->alias(
			function ( string $url, string $action = '' ): string {
				return $url . '&_wpnonce=test123';
			}
		);
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
	}

	/**
	 * @inheritDoc
	 */
	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		parent::tearDown();
	}

	// ------------------------------------------------------------------
	// Helpers
	// ------------------------------------------------------------------

	/**
	 * Creates a Form model instance with the given properties.
	 *
	 * @param array<string, mixed> $overrides Property overrides.
	 * @return Form
	 */
	private function make_form( array $overrides = [] ): Form {
		$form                = new Form();
		$form->id            = $overrides['id'] ?? 1;
		$form->title         = $overrides['title'] ?? 'Kontaktformular';
		$form->slug          = $overrides['slug'] ?? 'kontakt';
		$form->legal_basis   = $overrides['legal_basis'] ?? 'consent';
		$form->is_active     = $overrides['is_active'] ?? true;
		$form->retention_days = $overrides['retention_days'] ?? 90;
		$form->created_at    = $overrides['created_at'] ?? '2026-01-01 12:00:00';

		return $form;
	}

	/**
	 * Creates a testable subclass that exposes protected column methods
	 * and captures the actions array passed to row_actions().
	 *
	 * @return TestableFormListTable
	 */
	private function create_testable_table(): TestableFormListTable {
		return new TestableFormListTable();
	}

	// ==================================================================
	// #283 FLT-02 — Legal Basis column
	// ==================================================================

	/**
	 * @test
	 */
	public function test_get_columns_includes_legal_basis_key(): void {
		$table   = $this->create_testable_table();
		$columns = $table->get_columns();

		$this->assertArrayHasKey( 'legal_basis', $columns );
	}

	/**
	 * @test
	 */
	public function test_get_columns_legal_basis_label_is_rechtsgrundlage(): void {
		$table   = $this->create_testable_table();
		$columns = $table->get_columns();

		$this->assertSame( 'Rechtsgrundlage', $columns['legal_basis'] );
	}

	/**
	 * @test
	 */
	public function test_get_columns_legal_basis_is_between_title_and_slug(): void {
		$table   = $this->create_testable_table();
		$columns = $table->get_columns();
		$keys    = array_keys( $columns );

		$title_pos = array_search( 'title', $keys, true );
		$basis_pos = array_search( 'legal_basis', $keys, true );
		$slug_pos  = array_search( 'slug', $keys, true );

		$this->assertGreaterThan( $title_pos, $basis_pos, 'legal_basis must come after title' );
		$this->assertLessThan( $slug_pos, $basis_pos, 'legal_basis must come before slug' );
	}

	/**
	 * @test
	 */
	public function test_column_legal_basis_returns_consent_text_for_consent(): void {
		$table = $this->create_testable_table();
		$form  = $this->make_form( array( 'legal_basis' => 'consent' ) );

		$output = $table->public_column_legal_basis( $form );

		$this->assertStringContainsString( 'Einwilligung', $output );
		$this->assertStringContainsString( 'Art. 6 Abs. 1 lit. a', $output );
	}

	/**
	 * @test
	 */
	public function test_column_legal_basis_returns_contract_text_for_contract(): void {
		$table = $this->create_testable_table();
		$form  = $this->make_form( array( 'legal_basis' => 'contract' ) );

		$output = $table->public_column_legal_basis( $form );

		$this->assertStringContainsString( 'Vertrag', $output );
		$this->assertStringContainsString( 'Art. 6 Abs. 1 lit. b', $output );
	}

	/**
	 * @test
	 */
	public function test_column_legal_basis_defaults_to_consent_for_unknown_value(): void {
		$table = $this->create_testable_table();
		$form  = $this->make_form( array( 'legal_basis' => 'legitimate_interest' ) );

		$output = $table->public_column_legal_basis( $form );

		$this->assertStringContainsString( 'Einwilligung', $output );
		$this->assertStringContainsString( 'Art. 6 Abs. 1 lit. a', $output );
	}

	// ==================================================================
	// #284 FLT-01 — Consent row action in column_title
	// ==================================================================

	/**
	 * @test
	 */
	public function test_column_title_shows_consent_action_for_consent_form(): void {
		$table = $this->create_testable_table();
		$form  = $this->make_form( array( 'legal_basis' => 'consent' ) );

		$table->public_column_title( $form );

		$this->assertArrayHasKey(
			'consent',
			$table->last_row_actions,
			'Consent row action must be present for consent legal basis.'
		);
	}

	/**
	 * @test
	 */
	public function test_column_title_hides_consent_action_for_contract_form(): void {
		$table = $this->create_testable_table();
		$form  = $this->make_form( array( 'legal_basis' => 'contract' ) );

		$table->public_column_title( $form );

		$this->assertArrayNotHasKey(
			'consent',
			$table->last_row_actions,
			'Consent row action must NOT be present for contract legal basis.'
		);
	}

	/**
	 * @test
	 */
	public function test_column_title_consent_action_url_contains_consent_action_and_form_id(): void {
		$table = $this->create_testable_table();
		$form  = $this->make_form( array( 'id' => 42, 'legal_basis' => 'consent' ) );

		$table->public_column_title( $form );

		$consent_html = $table->last_row_actions['consent'];

		$this->assertStringContainsString( 'action=consent', $consent_html );
		$this->assertStringContainsString( 'form_id=42', $consent_html );
	}

	/**
	 * @test
	 */
	public function test_column_title_consent_action_label_is_einwilligungstexte(): void {
		$table = $this->create_testable_table();
		$form  = $this->make_form( array( 'legal_basis' => 'consent' ) );

		$table->public_column_title( $form );

		$consent_html = $table->last_row_actions['consent'];

		$this->assertStringContainsString( 'Einwilligungstexte', $consent_html );
	}

	/**
	 * @test
	 */
	public function test_column_title_consent_action_is_between_edit_and_delete(): void {
		$table = $this->create_testable_table();
		$form  = $this->make_form( array( 'legal_basis' => 'consent' ) );

		$table->public_column_title( $form );

		$keys       = array_keys( $table->last_row_actions );
		$edit_pos    = array_search( 'edit', $keys, true );
		$consent_pos = array_search( 'consent', $keys, true );
		$delete_pos  = array_search( 'delete', $keys, true );

		$this->assertGreaterThan( $edit_pos, $consent_pos, 'consent must come after edit' );
		$this->assertLessThan( $delete_pos, $consent_pos, 'consent must come before delete' );
	}

	/**
	 * @test
	 */
	public function test_column_title_always_has_edit_and_delete_actions(): void {
		$table = $this->create_testable_table();

		// Test with consent form.
		$consent_form = $this->make_form( array( 'legal_basis' => 'consent' ) );
		$table->public_column_title( $consent_form );

		$this->assertArrayHasKey( 'edit', $table->last_row_actions );
		$this->assertArrayHasKey( 'delete', $table->last_row_actions );

		// Test with contract form.
		$contract_form = $this->make_form( array( 'legal_basis' => 'contract' ) );
		$table->public_column_title( $contract_form );

		$this->assertArrayHasKey( 'edit', $table->last_row_actions );
		$this->assertArrayHasKey( 'delete', $table->last_row_actions );
	}

	/**
	 * @test
	 */
	public function test_column_title_contract_form_has_exactly_two_actions(): void {
		$table = $this->create_testable_table();
		$form  = $this->make_form( array( 'legal_basis' => 'contract' ) );

		$table->public_column_title( $form );

		$this->assertCount(
			2,
			$table->last_row_actions,
			'Contract form should have exactly 2 actions (edit + delete).'
		);
	}

	/**
	 * @test
	 */
	public function test_column_title_consent_form_has_exactly_three_actions(): void {
		$table = $this->create_testable_table();
		$form  = $this->make_form( array( 'legal_basis' => 'consent' ) );

		$table->public_column_title( $form );

		$this->assertCount(
			3,
			$table->last_row_actions,
			'Consent form should have exactly 3 actions (edit + consent + delete).'
		);
	}
}

// ------------------------------------------------------------------
// Testable subclass — exposes protected methods + captures row_actions.
// ------------------------------------------------------------------

/**
 * Testable FormListTable subclass that provides public access
 * to protected column methods and captures the actions array.
 *
 * @internal Only for unit testing.
 */
class TestableFormListTable extends FormListTable {

	/**
	 * Actions array captured from the last column_title() call.
	 *
	 * @var array<string, string>
	 */
	public array $last_row_actions = array();

	/**
	 * Override row_actions to capture the actions array.
	 *
	 * @param array<string, string> $actions     Row actions.
	 * @param bool                  $always_visible Whether always visible.
	 * @return string Concatenated action HTML.
	 */
	protected function row_actions( $actions, $always_visible = false ): string {
		$this->last_row_actions = $actions;
		return implode( ' | ', array_values( $actions ) );
	}

	/**
	 * Public proxy for protected column_title().
	 *
	 * @param Form $item The form object.
	 * @return string Column HTML.
	 */
	public function public_column_title( $item ): string {
		return $this->column_title( $item );
	}

	/**
	 * Public proxy for protected column_legal_basis().
	 *
	 * @param Form $item The form object.
	 * @return string Column HTML.
	 */
	public function public_column_legal_basis( $item ): string {
		return $this->column_legal_basis( $item );
	}
}
