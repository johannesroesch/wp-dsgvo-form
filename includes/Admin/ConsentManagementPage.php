<?php
/**
 * Consent text management page.
 *
 * Allows administrators to manage per-locale consent texts
 * for each form. Creates immutable consent versions for
 * DSGVO Art. 7 compliance (proof of exact consent wording).
 *
 * @package WpDsgvoForm
 * @privacy-relevant Art. 7 Abs. 1 DSGVO — Consent text versioning per locale
 */

declare(strict_types=1);

namespace WpDsgvoForm\Admin;

use WpDsgvoForm\Models\ConsentVersion;
use WpDsgvoForm\Models\Form;

defined( 'ABSPATH' ) || exit;

/**
 * Displays and handles the consent text management admin page.
 *
 * UX-Concept §2.3: Locale tabs, version history, fail-closed validation.
 * CONSENT-I18N-01 through 05: Per-locale consent versioning.
 */
class ConsentManagementPage {

	private const TEXT_DOMAIN = 'wp-dsgvo-form';

	/**
	 * Supported locales (CONSENT-I18N-01).
	 *
	 * @var array<string, string>
	 */
	private const SUPPORTED_LOCALES = [
		'de_DE' => 'Deutsch',
		'en_US' => 'English',
		'fr_FR' => 'Français',
		'es_ES' => 'Español',
		'it_IT' => 'Italiano',
		'sv_SE' => 'Svenska',
	];

	/**
	 * Maximum length for consent text excerpt in history table.
	 */
	private const EXCERPT_LENGTH = 120;

	/**
	 * Render the consent management page.
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! current_user_can( 'dsgvo_form_manage' ) ) {
			wp_die( esc_html__( 'Keine Berechtigung.', self::TEXT_DOMAIN ) );
		}

		$form_id = isset( $_GET['form_id'] ) ? absint( $_GET['form_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$form    = $form_id > 0 ? Form::find( $form_id ) : null;

		if ( $form === null ) {
			wp_die( esc_html__( 'Formular nicht gefunden.', self::TEXT_DOMAIN ) );
		}

		// Handle POST submission.
		if ( 'POST' === $_SERVER['REQUEST_METHOD'] && isset( $_POST['dsgvo_consent_action'] ) ) {
			$this->handle_save( $form );
			return;
		}

		// Active locale tab.
		$active_locale = $this->get_active_locale();

		// Load all versions for this form.
		$all_versions = ConsentVersion::find_all_by_form( $form_id );

		// Group versions by locale.
		$versions_by_locale = $this->group_by_locale( $all_versions );

		$this->render_page( $form, $active_locale, $versions_by_locale );
	}

	/**
	 * Handles the consent text save action.
	 *
	 * @param Form $form The form to save consent for.
	 * @return void
	 */
	private function handle_save( Form $form ): void {
		check_admin_referer( 'dsgvo_consent_save_' . $form->id );

		$locale      = sanitize_text_field( $_POST['consent_locale'] ?? '' );
		$text        = wp_kses_post( wp_unslash( $_POST['consent_text'] ?? '' ) );
		$privacy_url = esc_url_raw( wp_unslash( $_POST['privacy_policy_url'] ?? '' ) );

		// Validate locale.
		if ( ! array_key_exists( $locale, self::SUPPORTED_LOCALES ) ) {
			$this->redirect_with_notice( $form->id, $locale, 'error', __( 'Ungueltige Sprache.', self::TEXT_DOMAIN ) );
			return;
		}

		// Empty text = no save (Fail-Closed, DPO-FINDING-13).
		if ( trim( $text ) === '' ) {
			$this->redirect_with_notice(
				$form->id,
				$locale,
				'warning',
				__( 'Leerer Text wurde nicht gespeichert. Das Formular wird fuer diese Sprache nicht gerendert (Fail-Closed).', self::TEXT_DOMAIN )
			);
			return;
		}

		// Check if text differs from current version.
		$current = ConsentVersion::get_current_version( $form->id, $locale );
		if ( $current !== null && $current->consent_text === $text && $current->privacy_policy_url === $privacy_url ) {
			$this->redirect_with_notice(
				$form->id,
				$locale,
				'info',
				__( 'Text unveraendert — keine neue Version erstellt.', self::TEXT_DOMAIN )
			);
			return;
		}

		// Validate privacy URL (HTTPS only, if provided).
		if ( $privacy_url !== '' && strpos( $privacy_url, 'https://' ) !== 0 ) {
			$this->redirect_with_notice(
				$form->id,
				$locale,
				'error',
				__( 'Privacy-Policy-URL muss HTTPS verwenden.', self::TEXT_DOMAIN )
			);
			return;
		}

		// Create new immutable consent version.
		$version                    = new ConsentVersion();
		$version->form_id           = $form->id;
		$version->locale            = $locale;
		$version->version           = 0; // Trigger auto-increment in save().
		$version->consent_text      = $text;
		$version->privacy_policy_url = $privacy_url !== '' ? $privacy_url : null;

		try {
			$version->save();

			$this->redirect_with_notice(
				$form->id,
				$locale,
				'success',
				sprintf(
					/* translators: 1: version number, 2: locale label */
					__( 'Version %1$d fuer %2$s gespeichert.', self::TEXT_DOMAIN ),
					$version->version,
					self::SUPPORTED_LOCALES[ $locale ]
				)
			);
		} catch ( \RuntimeException $e ) {
			$this->redirect_with_notice(
				$form->id,
				$locale,
				'error',
				__( 'Fehler beim Speichern.', self::TEXT_DOMAIN ) . ' ' . esc_html( $e->getMessage() )
			);
		}
	}

	/**
	 * Redirects back to the consent page with an admin notice.
	 *
	 * @param int    $form_id Form ID.
	 * @param string $locale  Active locale tab.
	 * @param string $type    Notice type (success, error, warning, info).
	 * @param string $message Notice message.
	 * @return void
	 */
	private function redirect_with_notice( int $form_id, string $locale, string $type, string $message ): void {
		set_transient(
			'dsgvo_consent_notice_' . get_current_user_id(),
			[ 'type' => $type, 'message' => $message ],
			30
		);

		wp_safe_redirect(
			admin_url(
				sprintf(
					'admin.php?page=%s&action=consent&form_id=%d&locale=%s',
					AdminMenu::MENU_SLUG,
					$form_id,
					rawurlencode( $locale )
				)
			)
		);
		exit;
	}

	/**
	 * Returns the currently active locale from GET parameter.
	 *
	 * @return string Locale string (defaults to de_DE).
	 */
	private function get_active_locale(): string {
		$locale = sanitize_text_field( $_GET['locale'] ?? 'de_DE' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( ! array_key_exists( $locale, self::SUPPORTED_LOCALES ) ) {
			return 'de_DE';
		}

		return $locale;
	}

	/**
	 * Groups consent versions by locale.
	 *
	 * @param ConsentVersion[] $versions All versions for a form.
	 * @return array<string, ConsentVersion[]> Grouped by locale.
	 */
	private function group_by_locale( array $versions ): array {
		$grouped = [];

		foreach ( self::SUPPORTED_LOCALES as $locale => $label ) {
			$grouped[ $locale ] = [];
		}

		foreach ( $versions as $version ) {
			if ( isset( $grouped[ $version->locale ] ) ) {
				$grouped[ $version->locale ][] = $version;
			}
		}

		return $grouped;
	}

	/**
	 * Renders the full consent management page.
	 *
	 * @param Form   $form               The form.
	 * @param string $active_locale       Currently selected locale tab.
	 * @param array<string, ConsentVersion[]> $versions_by_locale Versions grouped by locale.
	 * @return void
	 */
	private function render_page( Form $form, string $active_locale, array $versions_by_locale ): void {
		$back_url = admin_url( 'admin.php?page=' . AdminMenu::MENU_SLUG );
		?>
		<div class="wrap">
			<h1>
				<?php
				printf(
					/* translators: %s: form title */
					esc_html__( 'Einwilligungstexte — %s', self::TEXT_DOMAIN ),
					esc_html( $form->title )
				);
				?>
			</h1>

			<a href="<?php echo esc_url( $back_url ); ?>" class="page-title-action">
				<?php esc_html_e( 'Zurueck zur Uebersicht', self::TEXT_DOMAIN ); ?>
			</a>

			<hr class="wp-header-end">

			<?php $this->render_notices(); ?>

			<?php if ( $form->legal_basis !== 'consent' ) : ?>
				<div class="notice notice-info">
					<p>
						<?php esc_html_e( 'Dieses Formular verwendet die Rechtsgrundlage "Vertrag" — Einwilligungstexte sind optional.', self::TEXT_DOMAIN ); ?>
					</p>
				</div>
			<?php endif; ?>

			<?php $this->render_locale_tabs( $form->id, $active_locale, $versions_by_locale ); ?>

			<?php $this->render_locale_content( $form, $active_locale, $versions_by_locale[ $active_locale ] ?? [] ); ?>
		</div>
		<?php
	}

	/**
	 * Renders admin notices from transient.
	 *
	 * @return void
	 */
	private function render_notices(): void {
		$notice = get_transient( 'dsgvo_consent_notice_' . get_current_user_id() );

		if ( ! is_array( $notice ) ) {
			return;
		}

		delete_transient( 'dsgvo_consent_notice_' . get_current_user_id() );

		$type    = sanitize_text_field( $notice['type'] ?? 'info' );
		$message = wp_kses_post( $notice['message'] ?? '' );

		$css_class = 'notice ';
		switch ( $type ) {
			case 'success':
				$css_class .= 'notice-success';
				break;
			case 'error':
				$css_class .= 'notice-error';
				break;
			case 'warning':
				$css_class .= 'notice-warning';
				break;
			default:
				$css_class .= 'notice-info';
				break;
		}

		?>
		<div class="<?php echo esc_attr( $css_class ); ?> is-dismissible">
			<p><?php echo $message; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- already escaped via wp_kses_post ?></p>
		</div>
		<?php
	}

	/**
	 * Renders the locale tab navigation.
	 *
	 * @param int    $form_id       Form ID.
	 * @param string $active_locale Currently active locale.
	 * @param array<string, ConsentVersion[]> $versions_by_locale Versions grouped by locale.
	 * @return void
	 */
	private function render_locale_tabs( int $form_id, string $active_locale, array $versions_by_locale ): void {
		?>
		<nav class="nav-tab-wrapper" style="margin-bottom:1.5rem;">
			<?php foreach ( self::SUPPORTED_LOCALES as $locale => $label ) : ?>
				<?php
				$tab_url   = admin_url(
					sprintf(
						'admin.php?page=%s&action=consent&form_id=%d&locale=%s',
						AdminMenu::MENU_SLUG,
						$form_id,
						rawurlencode( $locale )
					)
				);
				$is_active = $locale === $active_locale;
				$has_text  = ! empty( $versions_by_locale[ $locale ] );
				$css       = 'nav-tab' . ( $is_active ? ' nav-tab-active' : '' );
				?>
				<a href="<?php echo esc_url( $tab_url ); ?>" class="<?php echo esc_attr( $css ); ?>">
					<?php echo esc_html( $label ); ?>
					<?php if ( $has_text ) : ?>
						<span class="dashicons dashicons-yes" style="font-size:16px;width:16px;height:16px;vertical-align:middle;color:#46b450;" title="<?php esc_attr_e( 'Text vorhanden', self::TEXT_DOMAIN ); ?>"></span>
					<?php endif; ?>
				</a>
			<?php endforeach; ?>
		</nav>
		<?php
	}

	/**
	 * Renders the content for the active locale tab.
	 *
	 * @param Form             $form     The form.
	 * @param string           $locale   Active locale.
	 * @param ConsentVersion[] $versions Versions for this locale (newest first).
	 * @return void
	 */
	private function render_locale_content( Form $form, string $locale, array $versions ): void {
		$current     = ! empty( $versions ) ? $versions[0] : null;
		$locale_label = self::SUPPORTED_LOCALES[ $locale ] ?? $locale;

		// Current version info.
		if ( $current !== null ) {
			?>
			<div class="notice notice-info inline" style="margin:0 0 1rem 0;">
				<p>
					<?php
					printf(
						/* translators: 1: version number, 2: valid_from date */
						esc_html__( 'Aktuelle Version: %1$d (gueltig seit %2$s)', self::TEXT_DOMAIN ),
						(int) $current->version,
						esc_html(
							wp_date(
								get_option( 'date_format' ) . ' ' . get_option( 'time_format' ),
								strtotime( $current->valid_from )
							)
						)
					);
					?>
				</p>
			</div>
			<?php
		} else {
			?>
			<div class="notice notice-warning inline" style="margin:0 0 1rem 0;">
				<p>
					<?php
					printf(
						/* translators: %s: locale label */
						esc_html__( 'Kein Einwilligungstext fuer %s vorhanden. Das Formular wird fuer diese Sprache nicht gerendert (Fail-Closed).', self::TEXT_DOMAIN ),
						esc_html( $locale_label )
					);
					?>
				</p>
			</div>
			<?php
		}

		// Editor form.
		$this->render_editor_form( $form, $locale, $current );

		// Version history.
		if ( ! empty( $versions ) ) {
			$this->render_version_history( $versions );
		}
	}

	/**
	 * Renders the consent text editor form.
	 *
	 * @param Form                $form    The form.
	 * @param string              $locale  Active locale.
	 * @param ConsentVersion|null $current Current version (or null).
	 * @return void
	 */
	private function render_editor_form( Form $form, string $locale, ?ConsentVersion $current ): void {
		$action_url = admin_url(
			sprintf(
				'admin.php?page=%s&action=consent&form_id=%d&locale=%s',
				AdminMenu::MENU_SLUG,
				$form->id,
				rawurlencode( $locale )
			)
		);
		?>
		<form method="post" action="<?php echo esc_url( $action_url ); ?>">
			<?php wp_nonce_field( 'dsgvo_consent_save_' . $form->id ); ?>
			<input type="hidden" name="dsgvo_consent_action" value="save">
			<input type="hidden" name="consent_locale" value="<?php echo esc_attr( $locale ); ?>">

			<table class="form-table" role="presentation">
				<tbody>
					<tr>
						<th scope="row">
							<label for="consent_text">
								<?php esc_html_e( 'Einwilligungstext', self::TEXT_DOMAIN ); ?>
							</label>
						</th>
						<td>
							<textarea
								id="consent_text"
								name="consent_text"
								rows="8"
								class="large-text"
								placeholder="<?php esc_attr_e( 'Einwilligungstext eingeben...', self::TEXT_DOMAIN ); ?>"
							><?php echo esc_textarea( $current !== null ? $current->consent_text : '' ); ?></textarea>
							<p class="description">
								<?php esc_html_e( 'HTML-Formatierung erlaubt (Links, Fettschrift). Jede Aenderung erstellt eine neue, unveraenderliche Version (Art. 7 DSGVO).', self::TEXT_DOMAIN ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="privacy_policy_url">
								<?php esc_html_e( 'Datenschutzerklaerung-URL', self::TEXT_DOMAIN ); ?>
							</label>
						</th>
						<td>
							<input
								type="url"
								id="privacy_policy_url"
								name="privacy_policy_url"
								value="<?php echo esc_attr( $current !== null && $current->privacy_policy_url !== null ? $current->privacy_policy_url : '' ); ?>"
								class="regular-text"
								placeholder="https://example.com/datenschutz">
							<p class="description">
								<?php esc_html_e( 'Optional. Sprachspezifischer Link zur Datenschutzerklaerung. Muss HTTPS verwenden.', self::TEXT_DOMAIN ); ?>
							</p>
						</td>
					</tr>
				</tbody>
			</table>

			<?php
			submit_button(
				__( 'Neue Version speichern', self::TEXT_DOMAIN ),
				'primary',
				'submit',
				true
			);
			?>
		</form>
		<?php
	}

	/**
	 * Renders the version history table (read-only).
	 *
	 * Displays all historical consent versions for the active locale,
	 * newest first. Old versions are immutable (Art. 7 DSGVO proof).
	 *
	 * @param ConsentVersion[] $versions Versions for this locale (newest first).
	 * @return void
	 */
	private function render_version_history( array $versions ): void {
		?>
		<h2><?php esc_html_e( 'Versions-Historie', self::TEXT_DOMAIN ); ?></h2>
		<p class="description" style="margin-bottom:0.5rem;">
			<?php esc_html_e( 'Fruehere Versionen sind unveraenderlich und dienen als Nachweis der exakten Einwilligungsformulierung (Art. 7 Abs. 1 DSGVO).', self::TEXT_DOMAIN ); ?>
		</p>

		<table class="widefat striped">
			<thead>
				<tr>
					<th style="width:5%;"><?php esc_html_e( 'Version', self::TEXT_DOMAIN ); ?></th>
					<th style="width:45%;"><?php esc_html_e( 'Einwilligungstext', self::TEXT_DOMAIN ); ?></th>
					<th style="width:20%;"><?php esc_html_e( 'Datenschutz-URL', self::TEXT_DOMAIN ); ?></th>
					<th style="width:15%;"><?php esc_html_e( 'Gueltig seit', self::TEXT_DOMAIN ); ?></th>
					<th style="width:15%;"><?php esc_html_e( 'Erstellt am', self::TEXT_DOMAIN ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $versions as $version ) : ?>
					<tr>
						<td>
							<strong><?php echo esc_html( (string) $version->version ); ?></strong>
						</td>
						<td>
							<span title="<?php echo esc_attr( $version->consent_text ); ?>">
								<?php echo esc_html( $this->excerpt( $version->consent_text ) ); ?>
							</span>
						</td>
						<td>
							<?php if ( $version->privacy_policy_url !== null && $version->privacy_policy_url !== '' ) : ?>
								<a href="<?php echo esc_url( $version->privacy_policy_url ); ?>" target="_blank" rel="noopener noreferrer">
									<?php echo esc_html( $this->excerpt( $version->privacy_policy_url, 50 ) ); ?>
								</a>
							<?php else : ?>
								<span style="color:#999;">—</span>
							<?php endif; ?>
						</td>
						<td>
							<?php
							echo esc_html(
								wp_date(
									get_option( 'date_format' ) . ' ' . get_option( 'time_format' ),
									strtotime( $version->valid_from )
								)
							);
							?>
						</td>
						<td>
							<?php
							echo esc_html(
								wp_date(
									get_option( 'date_format' ) . ' ' . get_option( 'time_format' ),
									strtotime( $version->created_at )
								)
							);
							?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Returns a truncated excerpt of a string.
	 *
	 * @param string $text   The full text.
	 * @param int    $length Max length.
	 * @return string Truncated text with ellipsis if needed.
	 */
	private function excerpt( string $text, int $length = self::EXCERPT_LENGTH ): string {
		$stripped = wp_strip_all_tags( $text );

		if ( mb_strlen( $stripped ) <= $length ) {
			return $stripped;
		}

		return mb_substr( $stripped, 0, $length ) . '...';
	}
}
