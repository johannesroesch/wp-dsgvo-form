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

	private const TEXT_DOMAIN   = 'wp-dsgvo-form';
	private const ENDPOINT_BASE = 'dsgvo-empfaenger';
	private const QUERY_VAR     = 'dsgvo_recipient_page';
	private const ACTION_VAR    = 'dsgvo_recipient_action';
	private const ID_VAR        = 'dsgvo_submission_id';

	private AccessControl $access_control;

	public function __construct( AccessControl $access_control ) {
		$this->access_control = $access_control;
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

		// Auth check: must be logged in with a plugin role.
		if ( ! is_user_logged_in() ) {
			wp_safe_redirect( wp_login_url( home_url( '/' . self::ENDPOINT_BASE . '/' ) ) );
			exit;
		}

		$user_id = get_current_user_id();

		if ( ! $this->access_control->has_plugin_role( $user_id ) && ! $this->access_control->is_admin( $user_id ) ) {
			wp_die(
				esc_html__( 'Sie haben keinen Zugriff auf den Einsendungs-Bereich.', self::TEXT_DOMAIN ),
				esc_html__( 'Zugriff verweigert', self::TEXT_DOMAIN ),
				[ 'response' => 403 ]
			);
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
	 * Hides the admin bar for plugin role users (UX-Concept §2.1).
	 */
	public function maybe_hide_admin_bar(): void {
		if ( ! is_user_logged_in() ) {
			return;
		}

		$user_id = get_current_user_id();

		if ( $this->access_control->has_plugin_role( $user_id ) && ! $this->access_control->is_admin( $user_id ) ) {
			show_admin_bar( false );
		}
	}

	/**
	 * Renders the submission list view.
	 *
	 * @param int $user_id Current user ID.
	 */
	private function render_list_view( int $user_id ): void {
		$list_view = new SubmissionListView( $this->access_control );
		$this->render_page_template(
			__( 'Einsendungen', self::TEXT_DOMAIN ),
			function () use ( $list_view, $user_id ): void {
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
		$detail_view = new SubmissionDetailView( $this->access_control );
		$this->render_page_template(
			__( 'Einsendung anzeigen', self::TEXT_DOMAIN ),
			function () use ( $detail_view, $user_id, $submission_id ): void {
				$detail_view->render( $user_id, $submission_id );
			}
		);
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
	</style>
</head>
<body class="dsgvo-recipient-page">
	<div class="dsgvo-recipient">
		<div class="dsgvo-recipient__header">
			<span class="dsgvo-recipient__greeting">
				<?php
				printf(
					/* translators: %s: user display name */
					esc_html__( 'Hallo, %s', self::TEXT_DOMAIN ),
					esc_html( wp_get_current_user()->display_name )
				);
				?>
			</span>
			<a href="<?php echo esc_url( wp_logout_url( home_url() ) ); ?>" class="dsgvo-recipient__logout">
				<?php esc_html_e( 'Abmelden', self::TEXT_DOMAIN ); ?>
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
