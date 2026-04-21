<?php
/**
 * Recipient area page routing and template loading.
 *
 * @package WpDsgvoForm
 */

declare(strict_types=1);

namespace WpDsgvoForm\Recipient;

defined( 'ABSPATH' ) || exit;

use WpDsgvoForm\Auth\AccessControl;
use WpDsgvoForm\Models\Form;
use WpDsgvoForm\Models\Recipient;
use WpDsgvoForm\ServiceContainer;

/**
 * Registers the recipient-facing submissions viewer.
 *
 * Creates a virtual page at /dsgvo-empfaenger/ using WordPress rewrite rules.
 * Recipients (dsgvoform_reader / dsgvoform_supervisor) are redirected here
 * after login by LoginRedirect (Task #57).
 *
 * Routes:
 *   /dsgvo-empfaenger/                     → Submission list
 *   /dsgvo-empfaenger/view/{submission_id}  → Submission detail
 *
 * UX-Concept §2.1: No WP Dashboard access, no admin bar.
 */
class RecipientPage {

	private const ENDPOINT_BASE = 'dsgvo-empfaenger';
	private const QUERY_VAR     = 'dsgvo_recipient_page';
	private const ACTION_VAR    = 'dsgvo_recipient_action';
	private const ID_VAR        = 'dsgvo_submission_id';

	private AccessControl $access_control;
	private ServiceContainer $container;

	public function __construct( AccessControl $access_control, ServiceContainer $container ) {
		$this->access_control = $access_control;
		$this->container      = $container;
	}

	/**
	 * Registers all hooks for the recipient area.
	 */
	public function register(): void {
		add_action( 'init', [ $this, 'register_rewrite_rules' ] );
		add_filter( 'query_vars', [ $this, 'register_query_vars' ] );
		add_action( 'template_redirect', [ $this, 'handle_request' ] );

		// UX-Concept §2.1: Hide admin bar for plugin roles.
		add_action( 'init', [ $this, 'maybe_hide_admin_bar' ] );
	}

	/**
	 * Registers rewrite rules for the recipient area.
	 */
	public function register_rewrite_rules(): void {
		// /dsgvo-empfaenger/view/{id}
		add_rewrite_rule(
			'^' . self::ENDPOINT_BASE . '/view/([0-9]+)/?$',
			'index.php?' . self::QUERY_VAR . '=1&' . self::ACTION_VAR . '=view&' . self::ID_VAR . '=$matches[1]',
			'top'
		);

		// /dsgvo-empfaenger/
		add_rewrite_rule(
			'^' . self::ENDPOINT_BASE . '/?$',
			'index.php?' . self::QUERY_VAR . '=1&' . self::ACTION_VAR . '=list',
			'top'
		);
	}

	/**
	 * Registers custom query variables.
	 *
	 * @param string[] $vars Existing query vars.
	 * @return string[] Modified query vars.
	 */
	public function register_query_vars( array $vars ): array {
		$vars[] = self::QUERY_VAR;
		$vars[] = self::ACTION_VAR;
		$vars[] = self::ID_VAR;

		return $vars;
	}

	/**
	 * Intercepts requests to the recipient area and renders the appropriate view.
	 */
	public function handle_request(): void {
		if ( ! get_query_var( self::QUERY_VAR ) ) {
			return;
		}

		// Auth check: must be logged in with plugin access (capability or legacy role).
		if ( ! is_user_logged_in() ) {
			wp_safe_redirect( wp_login_url( home_url( '/' . self::ENDPOINT_BASE . '/' ) ) );
			exit;
		}

		$user_id = get_current_user_id();

		if ( ! $this->access_control->has_plugin_access( $user_id ) && ! $this->access_control->is_admin( $user_id ) ) {
			wp_die(
				esc_html__( 'Sie haben keinen Zugriff auf den Einsendungs-Bereich.', 'wp-dsgvo-form' ),
				esc_html__( 'Zugriff verweigert', 'wp-dsgvo-form' ),
				[ 'response' => 403 ]
			);
		}

		// UX-REC-02: First-Login privacy notice guard (Art. 13 DSGVO).
		if ( \WpDsgvoForm\Admin\FirstLoginNotice::needs_acknowledgment( $user_id ) ) {
			$notice = new \WpDsgvoForm\Admin\FirstLoginNotice(
				$this->access_control,
				$this->container->audit_logger()
			);
			$this->render_page_template(
				__( 'Datenschutzhinweis', 'wp-dsgvo-form' ),
				function () use ( $notice ): void {
					// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- render_notice_frontend returns pre-escaped HTML.
					echo $notice->render_notice_frontend();
				}
			);
			exit;
		}

		$action = sanitize_text_field( get_query_var( self::ACTION_VAR, 'list' ) );

		switch ( $action ) {
			case 'view':
				$submission_id = absint( get_query_var( self::ID_VAR, '0' ) );
				$this->render_detail_view( $user_id, $submission_id );
				break;

			case 'list':
			default:
				$this->render_list_view( $user_id );
				break;
		}

		exit;
	}

	/**
	 * Hides the admin bar for plugin-only users (UX-Concept §2.1).
	 *
	 * Editors/Authors with plugin capabilities keep the admin bar.
	 */
	public function maybe_hide_admin_bar(): void {
		if ( ! is_user_logged_in() ) {
			return;
		}

		$user_id = get_current_user_id();

		// Hide admin bar only for plugin-only users (no edit_posts).
		if ( $this->access_control->has_plugin_access( $user_id )
			&& ! $this->access_control->is_admin( $user_id )
			&& ! user_can( $user_id, 'edit_posts' )
		) {
			show_admin_bar( false );
		}
	}

	/**
	 * Renders the submission list view.
	 *
	 * @param int $user_id Current user ID.
	 */
	private function render_list_view( int $user_id ): void {
		$list_view = new SubmissionListView( $this->access_control, $this->container->audit_logger() );
		$this->render_page_template(
			__( 'Einsendungen', 'wp-dsgvo-form' ),
			function () use ( $list_view, $user_id ): void {
				$this->render_access_hint( $user_id );
				$list_view->render( $user_id );
			}
		);
	}

	/**
	 * Renders the submission detail view.
	 *
	 * @param int $user_id       Current user ID.
	 * @param int $submission_id Submission to display.
	 */
	private function render_detail_view( int $user_id, int $submission_id ): void {
		$detail_view = new SubmissionDetailView( $this->access_control, $this->container->encryption(), $this->container->audit_logger() );
		$this->render_page_template(
			__( 'Einsendung anzeigen', 'wp-dsgvo-form' ),
			function () use ( $detail_view, $user_id, $submission_id ): void {
				$detail_view->render( $user_id, $submission_id );
			}
		);
	}

	/**
	 * Renders an access level hint for the current user.
	 *
	 * UX recommendation: Shows the user their access level and assigned forms.
	 *
	 * @param int $user_id Current user ID.
	 */
	private function render_access_hint( int $user_id ): void {
		if ( $this->access_control->is_admin( $user_id ) ) {
			return;
		}

		if ( $this->access_control->is_supervisor( $user_id ) ) {
			echo '<div class="dsgvo-recipient__access-hint" style="padding:0.5rem 1rem;margin-bottom:1rem;background:#f0f6fc;border-left:4px solid #2271b1;font-size:0.9rem;">';
			echo esc_html__( 'Ihr Zugriff: Supervisor — alle Formulare', 'wp-dsgvo-form' );
			echo '</div>';
			return;
		}

		// Reader: show assigned form names.
		$form_ids = Recipient::get_form_ids_for_user( $user_id );

		if ( empty( $form_ids ) ) {
			return;
		}

		$form_names = [];
		foreach ( $form_ids as $form_id ) {
			$form = Form::find( $form_id );
			if ( $form !== null ) {
				$form_names[] = $form->title;
			}
		}

		if ( empty( $form_names ) ) {
			return;
		}

		echo '<div class="dsgvo-recipient__access-hint" style="padding:0.5rem 1rem;margin-bottom:1rem;background:#f0f6fc;border-left:4px solid #2271b1;font-size:0.9rem;">';
		printf(
			/* translators: %s: comma-separated list of form names */
			esc_html__( 'Ihr Zugriff: Leser fuer Formular %s', 'wp-dsgvo-form' ),
			esc_html( implode( ', ', $form_names ) )
		);
		echo '</div>';
	}

	/**
	 * Renders the minimal HTML page template for the recipient area.
	 *
	 * Uses a clean template without WP admin chrome.
	 *
	 * @param string   $title          Page title.
	 * @param callable $content_callback Callback that renders the page body.
	 */
	private function render_page_template( string $title, callable $content_callback ): void {
		// Load theme header/footer for consistent styling.
		// Falls back to minimal HTML if theme doesn't support it.
		status_header( 200 );
		nocache_headers();

		?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title><?php echo esc_html( $title . ' — ' . get_bloginfo( 'name' ) ); ?></title>
	<?php wp_head(); ?>
	<style>
		.dsgvo-recipient {
			max-width: 1100px;
			margin: 2rem auto;
			padding: 0 1rem;
			font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
		}
		.dsgvo-recipient__header {
			display: flex;
			justify-content: space-between;
			align-items: center;
			margin-bottom: 1.5rem;
			padding-bottom: 1rem;
			border-bottom: 1px solid #ddd;
		}
		.dsgvo-recipient__greeting {
			font-size: 1.25rem;
			font-weight: 600;
		}
		.dsgvo-recipient__logout {
			color: #d63638;
			text-decoration: none;
		}
		.dsgvo-recipient__logout:hover {
			text-decoration: underline;
		}
		/* WCAG 2.1 AA: Skip-link visible on focus (UX-A11Y-01). */
		.skip-link.screen-reader-text {
			clip: rect(1px, 1px, 1px, 1px);
			position: absolute;
			height: 1px;
			width: 1px;
			overflow: hidden;
			word-wrap: normal;
		}
		.skip-link.screen-reader-text:focus {
			clip: auto;
			display: block;
			position: absolute;
			top: 5px;
			left: 5px;
			z-index: 100000;
			width: auto;
			height: auto;
			padding: 15px 23px 14px;
			background: #f1f1f1;
			color: #21759b;
			font-size: 14px;
			font-weight: 600;
			line-height: normal;
			text-decoration: none;
			box-shadow: 0 0 2px 2px rgba(0, 0, 0, 0.6);
		}
	</style>
</head>
<body class="dsgvo-recipient-page">
	<a class="skip-link screen-reader-text" href="#main-content">
		<?php esc_html_e( 'Zum Inhalt springen', 'wp-dsgvo-form' ); ?>
	</a>
	<div class="dsgvo-recipient" id="main-content">
		<div class="dsgvo-recipient__header">
			<span class="dsgvo-recipient__greeting">
				<?php
				printf(
					/* translators: %s: user display name */
					esc_html__( 'Hallo, %s', 'wp-dsgvo-form' ),
					esc_html( wp_get_current_user()->display_name )
				);
				?>
			</span>
			<a href="<?php echo esc_url( wp_logout_url( home_url() ) ); ?>" class="dsgvo-recipient__logout">
				<?php esc_html_e( 'Abmelden', 'wp-dsgvo-form' ); ?>
			</a>
		</div>

		<?php $content_callback(); ?>
	</div>
	<?php wp_footer(); ?>
</body>
</html>
		<?php
	}

	/**
	 * Returns the base URL of the recipient area.
	 *
	 * @return string URL to /dsgvo-empfaenger/.
	 */
	public static function get_base_url(): string {
		return home_url( '/' . self::ENDPOINT_BASE . '/' );
	}

	/**
	 * Returns the URL for viewing a specific submission.
	 *
	 * @param int $submission_id The submission ID.
	 * @return string URL to the detail view.
	 */
	public static function get_view_url( int $submission_id ): string {
		return home_url( '/' . self::ENDPOINT_BASE . '/view/' . $submission_id . '/' );
	}
}
