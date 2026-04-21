<?php
/**
 * Central plugin class.
 *
 * Singleton that handles bootstrapping, hook registration,
 * and acts as a lightweight service container.
 *
 * @package WpDsgvoForm
 */

declare(strict_types=1);

namespace WpDsgvoForm;

defined( 'ABSPATH' ) || exit;

/**
 * Main plugin class.
 */
final class Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 */
	private static ?self $instance = null;

	/**
	 * Get or create the singleton instance.
	 *
	 * @return self
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Private constructor to enforce singleton.
	 */
	private function __construct() {}

	/**
	 * Bootstrap the plugin.
	 *
	 * @return void
	 */
	public function init(): void {
		$this->check_master_key();
		$this->register_hooks();
		$this->register_cli_commands();

		// Run schema upgrades on version change (DPO-FINDING-13).
		add_action( 'admin_init', [ Activator::class, 'maybe_upgrade' ] );
	}

	/**
	 * Show admin notice when the encryption master key is missing.
	 *
	 * @return void
	 */
	private function check_master_key(): void {
		if ( ! defined( 'DSGVO_FORM_ENCRYPTION_KEY' ) || '' === DSGVO_FORM_ENCRYPTION_KEY ) {
			add_action( 'admin_notices', array( $this, 'render_missing_key_notice' ) );
		}
	}

	/**
	 * Render the admin notice for missing master key.
	 *
	 * @return void
	 */
	public function render_missing_key_notice(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="notice notice-error">
			<p>
				<strong><?php esc_html_e( 'WP DSGVO Form:', 'wp-dsgvo-form' ); ?></strong>
				<?php esc_html_e( 'Bitte definieren Sie DSGVO_FORM_ENCRYPTION_KEY in Ihrer wp-config.php:', 'wp-dsgvo-form' ); ?>
			</p>
			<pre>define( 'DSGVO_FORM_ENCRYPTION_KEY', '<?php echo esc_html( base64_encode( random_bytes( 32 ) ) ); ?>' );</pre>
		</div>
		<?php
	}

	/**
	 * Register all WordPress hooks.
	 *
	 * @return void
	 */
	private function register_hooks(): void {
		// KANN-ARCH-01: Shared service container for lazy-initialized services.
		$container = new ServiceContainer();

		if ( is_admin() ) {
			$this->register_admin_hooks( $container );
		}

		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
		add_action( 'init', array( $this, 'register_block' ) );
		add_action( 'dsgvo_form_cleanup', array( $this, 'cleanup_expired_submissions' ) );

		// Shared AccessControl instance for LoginRedirect and RecipientPage.
		$access_control = new Auth\AccessControl();

		// SEC-AUTH-06 through SEC-AUTH-12: Login redirect, admin isolation, cookie timeout.
		// Runs OUTSIDE is_admin() — login_redirect and auth_cookie_expiration
		// fire from wp-login.php which is not an admin context.
		$login_redirect = new Auth\LoginRedirect( $access_control );
		$login_redirect->register_hooks();

		// UX-REC-02: First-Login privacy notice (Art. 13 DSGVO).
		$first_login_notice = new Admin\FirstLoginNotice( $access_control, $container->audit_logger() );
		$first_login_notice->register();

		// Recipient area: /dsgvo-empfaenger/ (Task #33).
		$recipient_page = new Recipient\RecipientPage( $access_control, $container );
		$recipient_page->register();

		// Admin Bar Notification (outside is_admin() — admin bar also on frontend).
		$notification = new Admin\AdminBarNotification( $access_control );
		$notification->register_hooks();

		// LEGAL-F01: WordPress Privacy Data Exporter/Eraser (Art. 15 + 17 DSGVO).
		// Runs OUTSIDE is_admin() — privacy filters fire during WP-CLI and REST contexts too.
		$file_handler    = new Upload\FileHandler( $container->encryption() );
		$deleter         = new Api\SubmissionDeleter( $file_handler );
		$privacy_handler = new Privacy\PrivacyHandler( $container->encryption(), $deleter, $container->audit_logger() );
		$privacy_handler->register();
	}

	/**
	 * Register admin-specific hooks.
	 *
	 * @return void
	 */
	private function register_admin_hooks( ServiceContainer $container ): void {
		$admin_menu = new Admin\AdminMenu( $container );
		$admin_menu->register_hooks();

		$settings_page = new Admin\SettingsPage();
		add_action( 'admin_init', array( $settings_page, 'register_settings' ) );

		// LEGAL-F02: Suggested privacy policy content (Art. 13 DSGVO).
		$privacy_policy = new Privacy\PrivacyPolicy();
		$privacy_policy->register();

		// LEGAL-F04: DSFA notice when thresholds exceeded (Art. 35 DSGVO).
		$dsfa_notice = new Admin\DsfaNotice();
		$dsfa_notice->register();

		// SEC-KANN-02: Content-Security-Policy headers on plugin admin pages.
		$csp_headers = new Security\CspHeaders();
		$csp_headers->register();

		// SEC-KANN-03: Encryption health-check dashboard widget.
		$health_check = new Admin\HealthCheckWidget();
		$health_check->register();

		// Batch 7d: Dismissible migration notice for capability system change.
		add_action( 'wp_ajax_wpdsgvo_dismiss_cap_migration_notice', [ Admin\RecipientListPage::class, 'handle_dismiss_migration_notice' ] );
	}

	/**
	 * Register REST API routes.
	 *
	 * @return void
	 */
	public function register_rest_routes(): void {
		$key_manager  = new Encryption\KeyManager();
		$encryption   = new Encryption\EncryptionService( $key_manager );
		$captcha      = new Captcha\CaptchaVerifier();
		$validator    = new Validation\FieldValidator();
		$notification = new Notification\NotificationService();
		$file_handler = new Upload\FileHandler( $encryption );

		$submit = new Api\SubmitEndpoint(
			$encryption,
			$captcha,
			$validator,
			$notification,
			$file_handler
		);
		$submit->register();

		$deleter         = new Api\SubmissionDeleter( $file_handler );
		$access_control  = new Auth\AccessControl();
		$audit_logger    = new Audit\AuditLogger();
		$delete_endpoint = new Api\SubmissionDeleteEndpoint( $deleter, $access_control, $audit_logger );
		$delete_endpoint->register();
	}

	/**
	 * Register the Gutenberg block.
	 *
	 * @return void
	 */
	public function register_block(): void {
		$block = new Block\FormBlock();
		$block->register();
	}

	/**
	 * Delete expired submissions (DSGVO auto-cleanup).
	 *
	 * @return void
	 */
	public function cleanup_expired_submissions(): void {
		$key_manager  = new Encryption\KeyManager();
		$encryption   = new Encryption\EncryptionService( $key_manager );
		$file_handler = new Upload\FileHandler( $encryption );
		$deleter      = new Api\SubmissionDeleter( $file_handler );

		$deleter->delete_expired();

		// SEC-AUDIT-04: Anonymize IP addresses older than 90 days.
		// SEC-AUDIT-03: Delete audit entries older than 1 year.
		$audit_logger = new Audit\AuditLogger();
		$audit_logger->cleanup_ip_addresses();
		$audit_logger->cleanup_old_entries();
	}

	/**
	 * Register WP-CLI commands (SEC-SOLL-02: KEK rotation).
	 *
	 * @return void
	 */
	private function register_cli_commands(): void {
		if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
			return;
		}

		\WP_CLI::add_command( 'dsgvo-form rotate-kek', Cli\KekRotateCommand::class );
	}

}
