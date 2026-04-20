<?php
/**
 * Admin menu registration.
 *
 * Registers the DSGVO form admin menu and submenus,
 * and enqueues admin-specific scripts and styles.
 *
 * @package WpDsgvoForm
 */

declare(strict_types=1);

namespace WpDsgvoForm\Admin;

use WpDsgvoForm\Api\SubmissionDeleter;
use WpDsgvoForm\Audit\AuditLogger;
use WpDsgvoForm\Encryption\EncryptionService;
use WpDsgvoForm\Encryption\KeyManager;
use WpDsgvoForm\Upload\FileHandler;

defined( 'ABSPATH' ) || exit;

/**
 * Handles admin menu registration and asset enqueueing.
 */
class AdminMenu {

	/**
	 * Menu slug for the main admin page.
	 *
	 * @var string
	 */
	public const MENU_SLUG = 'dsgvo-form';

	/**
	 * Hook suffixes for the registered admin pages.
	 *
	 * @var string[]
	 */
	private array $page_hooks = array();

	/**
	 * Shared FormEditPage instance (load hook → render callback).
	 *
	 * @var FormEditPage|null
	 */
	private ?FormEditPage $form_edit_page = null;

	/**
	 * Register WordPress hooks.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		add_action( 'admin_menu', array( $this, 'register_menus' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Register the admin menu and submenu pages.
	 *
	 * @return void
	 */
	public function register_menus(): void {
		// Main menu: Formulare (form list is the landing page).
		$this->page_hooks['forms'] = add_menu_page(
			__( 'DSGVO Formulare', 'wp-dsgvo-form' ),
			__( 'DSGVO Formulare', 'wp-dsgvo-form' ),
			'dsgvo_form_manage',
			self::MENU_SLUG,
			array( $this, 'render_form_list_page' ),
			'dashicons-feedback',
			30
		);

		// Handle form save redirects before output (PRG pattern).
		add_action( 'load-' . $this->page_hooks['forms'], array( $this, 'handle_form_page_load' ) );

		// Submenu: Formulare (explicit, replaces auto-generated parent entry).
		add_submenu_page(
			self::MENU_SLUG,
			__( 'Formulare', 'wp-dsgvo-form' ),
			__( 'Formulare', 'wp-dsgvo-form' ),
			'dsgvo_form_manage',
			self::MENU_SLUG,
			array( $this, 'render_form_list_page' )
		);

		// Submenu: Einsendungen.
		$this->page_hooks['submissions'] = add_submenu_page(
			self::MENU_SLUG,
			__( 'Einsendungen', 'wp-dsgvo-form' ),
			__( 'Einsendungen', 'wp-dsgvo-form' ),
			'dsgvo_form_view_submissions',
			self::MENU_SLUG . '-submissions',
			array( $this, 'render_submission_list_page' )
		);

		// Handle export downloads before output (LEGAL-F02: Art. 20 DSGVO).
		add_action( 'load-' . $this->page_hooks['submissions'], array( $this, 'handle_submission_page_load' ) );

		// Submenu: Empfaenger.
		$this->page_hooks['recipients'] = add_submenu_page(
			self::MENU_SLUG,
			__( 'Empfaenger', 'wp-dsgvo-form' ),
			__( 'Empfaenger', 'wp-dsgvo-form' ),
			'dsgvo_form_manage',
			self::MENU_SLUG . '-recipients',
			array( $this, 'render_recipient_list_page' )
		);

		// Submenu: Einstellungen.
		$this->page_hooks['settings'] = add_submenu_page(
			self::MENU_SLUG,
			__( 'Einstellungen', 'wp-dsgvo-form' ),
			__( 'Einstellungen', 'wp-dsgvo-form' ),
			'dsgvo_form_manage',
			self::MENU_SLUG . '-settings',
			array( $this, 'render_settings_page' )
		);

		// Hidden submenu: Betroffenen-Suche (Art. 15/17 DSGVO) — no menu entry.
		add_submenu_page(
			null,
			__( 'Betroffenen-Suche', 'wp-dsgvo-form' ),
			'',
			'dsgvo_form_manage',
			DataSubjectSearchPage::PAGE_SLUG,
			array( $this, 'render_subject_search_page' )
		);
	}

	/**
	 * Enqueue admin scripts and styles on plugin pages only.
	 *
	 * @param string $hook_suffix The current admin page hook suffix.
	 * @return void
	 */
	public function enqueue_assets( string $hook_suffix ): void {
		if ( ! $this->is_plugin_page( $hook_suffix ) ) {
			return;
		}
	}

	/**
	 * Check whether the given hook suffix belongs to a plugin admin page.
	 *
	 * @param string $hook_suffix The current admin page hook suffix.
	 * @return bool
	 */
	private function is_plugin_page( string $hook_suffix ): bool {
		return str_contains( $hook_suffix, self::MENU_SLUG );
	}

	/**
	 * Handle form edit POST before output to allow redirects.
	 *
	 * Fires on load-{page} hook — before WordPress renders the admin shell.
	 *
	 * @return void
	 */
	public function handle_form_page_load(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- routing only, no state change
		if ( isset( $_GET['action'] ) && 'edit' === sanitize_key( wp_unslash( $_GET['action'] ) ) ) {
			$this->form_edit_page = new FormEditPage();
			$this->form_edit_page->maybe_save_and_redirect();
		}
	}

	/**
	 * Handle submission export downloads before output.
	 *
	 * Fires on load-{page} hook — before WordPress renders the admin shell.
	 * Required because file downloads must send HTTP headers before any output.
	 *
	 * @return void
	 */
	public function handle_submission_page_load(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- routing only, no state change
		if ( isset( $_GET['action'] ) && 'view' === sanitize_key( wp_unslash( $_GET['action'] ) ) && isset( $_GET['do'] ) && 'export' === sanitize_key( wp_unslash( $_GET['do'] ) ) ) {
			( new SubmissionViewPage() )->handle_export();
		}
	}

	/**
	 * Render the form list page.
	 *
	 * @return void
	 */
	public function render_form_list_page(): void {
		// Delegate to FormEditPage when editing a single form.
		if ( isset( $_GET['action'] ) && 'edit' === sanitize_key( wp_unslash( $_GET['action'] ) ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$page = $this->form_edit_page ?? new FormEditPage();
			$page->render();
			return;
		}

		// Delegate to ConsentManagementPage for consent text management.
		if ( isset( $_GET['action'] ) && 'consent' === sanitize_key( wp_unslash( $_GET['action'] ) ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			( new ConsentManagementPage() )->render();
			return;
		}

		( new FormListPage() )->render();
	}

	/**
	 * Render the submission list page.
	 *
	 * @return void
	 */
	public function render_submission_list_page(): void {
		// Delegate to detail view when viewing a single submission.
		if ( isset( $_GET['action'] ) && 'view' === sanitize_key( wp_unslash( $_GET['action'] ) ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			( new SubmissionViewPage() )->render();
			return;
		}

		$key_manager  = new KeyManager();
		$encryption   = new EncryptionService( $key_manager );
		$file_handler = new FileHandler( $encryption );
		$deleter      = new SubmissionDeleter( $file_handler );
		$audit_logger = new AuditLogger();

		( new SubmissionListPage( $deleter, $audit_logger ) )->render();
	}

	/**
	 * Render the recipient list page.
	 *
	 * @return void
	 */
	public function render_recipient_list_page(): void {
		( new RecipientListPage() )->render();
	}

	/**
	 * Render the settings page.
	 *
	 * @return void
	 */
	public function render_settings_page(): void {
		( new SettingsPage() )->render();
	}

	/**
	 * Render the data subject search page (Art. 15/17 DSGVO).
	 *
	 * @return void
	 */
	public function render_subject_search_page(): void {
		$key_manager  = new KeyManager();
		$encryption   = new EncryptionService( $key_manager );
		$audit_logger = new AuditLogger();

		( new DataSubjectSearchPage( $encryption, $audit_logger ) )->render();
	}
}
