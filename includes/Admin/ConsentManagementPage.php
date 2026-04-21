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

use WpDsgvoForm\Models\ConsentTemplateHelper;
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
			wp_die( esc_html__( 'Keine Berechtigung.', 'wp-dsgvo-form' ) );
		}

		$form_id = isset( $_GET['form_id'] ) ? absint( $_GET['form_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$form    = $form_id > 0 ? Form::find( $form_id ) : null;

		if ( $form === null ) {
			wp_die( esc_html__( 'Formular nicht gefunden.', 'wp-dsgvo-form' ) );
		}

		// ARCH-v104-03: Hard-block — consent management only for forms with legal_basis = 'consent'.
		if ( $form->legal_basis !== 'consent' ) {
			$back_url = admin_url( 'admin.php?page=' . AdminMenu::MENU_SLUG );
			?>
			<div class="wrap">
				<h1><?php esc_html_e( 'Einwilligungstexte', 'wp-dsgvo-form' ); ?></h1>
				<a href="<?php echo esc_url( $back_url ); ?>" class="page-title-action">
					<?php esc_html_e( 'Zurueck zur Uebersicht', 'wp-dsgvo-form' ); ?>
				</a>
				<hr class="wp-header-end">
				<div class="notice notice-error">
					<p>
						<?php
						printf(
							/* translators: %s: form title */
							esc_html__( 'Das Formular "%s" verwendet nicht die Rechtsgrundlage "Einwilligung". Einwilligungstexte koennen nur fuer Formulare mit Rechtsgrundlage "Einwilligung" verwaltet werden.', 'wp-dsgvo-form' ),
							esc_html( $form->title )
						);
						?>
					</p>
				</div>
			</div>
			<?php
			return;
		}

		// Handle POST submission.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- isset() routing only; nonce verified immediately below
		if ( 'POST' === $_SERVER['REQUEST_METHOD'] && isset( $_POST['dsgvo_consent_action'] ) ) {
			check_admin_referer( 'dsgvo_consent_save_' . $form->id );
			$this->handle_save( $form );
			return;
		}

		// Active locale tab.
		$active_locale = $this->get_active_locale();

		// PERF-SOLL-01: Lightweight query for locale tab indicators.
		$locales_with_versions = ConsentVersion::get_locales_with_versions( $form_id );

		// PERF-SOLL-01: Only load versions for the active locale (paginated).
		$per_page      = 20;
		$current_page  = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$offset        = ( $current_page - 1 ) * $per_page;
		$locale_versions = ConsentVersion::find_by_form_and_locale_paginated( $form_id, $active_locale, $per_page, $offset );
		$total_versions  = ConsentVersion::count_by_form_and_locale( $form_id, $active_locale );

		$this->render_page( $form, $active_locale, $locales_with_versions, $locale_versions, $total_versions, $current_page, $per_page );
	}

	/**
	 * Handles the consent text save action.
	 *
	 * Sanitizes POST input, delegates to validation and persistence.
	 *
	 * @param Form $form The form to save consent for.
	 * @return void
	 */
	private function handle_save( Form $form ): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce verified in render() before dispatch
		$locale      = sanitize_text_field( wp_unslash( $_POST['consent_locale'] ?? '' ) );
		$text        = wp_kses_post( wp_unslash( $_POST['consent_text'] ?? '' ) );
		$privacy_url = esc_url_raw( wp_unslash( $_POST['privacy_policy_url'] ?? '' ) );
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$validated = $this->validate_consent_input( $form, $locale, $text, $privacy_url );

		if ( is_array( $validated ) ) {
			$this->persist_consent_version( $form, $validated );
		}
	}

	/**
	 * Validates consent input data.
	 *
	 * @param Form   $form        The form.
	 * @param string $locale      Sanitized locale.
	 * @param string $text        Sanitized consent text.
	 * @param string $privacy_url Sanitized privacy policy URL.
	 * @return array{locale: string, text: string, privacy_url: string}|null Validated data or null on validation failure (redirect already sent).
	 */
	private function validate_consent_input( Form $form, string $locale, string $text, string $privacy_url ): ?array {
		// Validate locale.
		if ( ! array_key_exists( $locale, ConsentVersion::SUPPORTED_LOCALES ) ) {
			$this->redirect_with_notice( $form->id, $locale, 'error', __( 'Ungueltige Sprache.', 'wp-dsgvo-form' ) );
			return null;
		}

		// Empty text = no save (Fail-Closed, DPO-FINDING-13).
		if ( trim( $text ) === '' ) {
			$this->redirect_with_notice(
				$form->id,
				$locale,
				'warning',
				__( 'Leerer Text wurde nicht gespeichert. Das Formular wird fuer diese Sprache nicht gerendert (Fail-Closed).', 'wp-dsgvo-form' )
			);
			return null;
		}

		// Check if text differs from current version.
		$current = ConsentVersion::get_current_version( $form->id, $locale );
		if ( $current !== null && $current->consent_text === $text && $current->privacy_policy_url === $privacy_url ) {
			$this->redirect_with_notice(
				$form->id,
				$locale,
				'info',
				__( 'Text unveraendert — keine neue Version erstellt.', 'wp-dsgvo-form' )
			);
			return null;
		}

		// Validate privacy URL (HTTPS only, if provided).
		if ( $privacy_url !== '' && strpos( $privacy_url, 'https://' ) !== 0 ) {
			$this->redirect_with_notice(
				$form->id,
				$locale,
				'error',
				__( 'Privacy-Policy-URL muss HTTPS verwenden.', 'wp-dsgvo-form' )
			);
			return null;
		}

		return [
			'locale'      => $locale,
			'text'        => $text,
			'privacy_url' => $privacy_url,
		];
	}

	/**
	 * Creates and saves an immutable consent version.
	 *
	 * @param Form                 $form      The form.
	 * @param array<string, mixed> $validated Validated input from validate_consent_input().
	 * @return void
	 */
	private function persist_consent_version( Form $form, array $validated ): void {
		$version                     = new ConsentVersion();
		$version->form_id            = $form->id;
		$version->locale             = $validated['locale'];
		$version->version            = 0; // Trigger auto-increment in save().
		$version->consent_text       = $validated['text'];
		$version->privacy_policy_url = $validated['privacy_url'] !== '' ? $validated['privacy_url'] : null;

		try {
			// PERF-SOLL-02: Pass Form object to avoid redundant DB query in validate().
			$version->save( $form );

			$this->redirect_with_notice(
				$form->id,
				$validated['locale'],
				'success',
				sprintf(
					/* translators: 1: version number, 2: locale label */
					__( 'Version %1$d fuer %2$s gespeichert.', 'wp-dsgvo-form' ),
					$version->version,
					ConsentVersion::SUPPORTED_LOCALES[ $validated['locale'] ]
				)
			);
		} catch ( \RuntimeException $e ) {
			$this->redirect_with_notice(
				$form->id,
				$validated['locale'],
				'error',
				__( 'Fehler beim Speichern.', 'wp-dsgvo-form' ) . ' ' . esc_html( $e->getMessage() )
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
		$locale = sanitize_text_field( wp_unslash( $_GET['locale'] ?? 'de_DE' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( ! array_key_exists( $locale, ConsentVersion::SUPPORTED_LOCALES ) ) {
			return 'de_DE';
		}

		return $locale;
	}

	/**
	 * Renders pagination links for the version history.
	 *
	 * PERF-SOLL-01: Standard WordPress pagination pattern.
	 *
	 * @param int    $form_id      Form ID.
	 * @param string $locale       Active locale.
	 * @param int    $current_page Current page number.
	 * @param int    $total_pages  Total number of pages.
	 * @return void
	 */
	private function render_pagination( int $form_id, string $locale, int $current_page, int $total_pages ): void {
		$base_url = admin_url(
			sprintf(
				'admin.php?page=%s&action=consent&form_id=%d&locale=%s',
				AdminMenu::MENU_SLUG,
				$form_id,
				rawurlencode( $locale )
			)
		);

		echo '<div class="tablenav"><div class="tablenav-pages">';
		echo '<span class="displaying-num">';
		printf(
			/* translators: %d: total number of pages */
			esc_html__( 'Seite %1$d von %2$d', 'wp-dsgvo-form' ),
			$current_page,
			$total_pages
		);
		echo '</span> ';

		if ( $current_page > 1 ) {
			printf(
				'<a class="prev-page button" href="%s">&lsaquo;</a> ',
				esc_url( add_query_arg( 'paged', $current_page - 1, $base_url ) )
			);
		}

		if ( $current_page < $total_pages ) {
			printf(
				'<a class="next-page button" href="%s">&rsaquo;</a>',
				esc_url( add_query_arg( 'paged', $current_page + 1, $base_url ) )
			);
		}

		echo '</div></div>';
	}

	/**
	 * Renders the full consent management page.
	 *
	 * @param Form     $form                   The form.
	 * @param string   $active_locale          Currently selected locale tab.
	 * @param string[] $locales_with_versions  Locales that have at least one version.
	 * @param ConsentVersion[] $locale_versions Paginated versions for active locale.
	 * @param int      $total_versions         Total version count for active locale.
	 * @param int      $current_page           Current pagination page.
	 * @param int      $per_page               Items per page.
	 * @return void
	 */
	private function render_page( Form $form, string $active_locale, array $locales_with_versions, array $locale_versions, int $total_versions, int $current_page, int $per_page ): void {
		$back_url = admin_url( 'admin.php?page=' . AdminMenu::MENU_SLUG );
		?>
		<div class="wrap">
			<h1>
				<?php
				printf(
					/* translators: %s: form title */
					esc_html__( 'Einwilligungstexte — %s', 'wp-dsgvo-form' ),
					esc_html( $form->title )
				);
				?>
			</h1>

			<a href="<?php echo esc_url( $back_url ); ?>" class="page-title-action">
				<?php esc_html_e( 'Zurueck zur Uebersicht', 'wp-dsgvo-form' ); ?>
			</a>

			<hr class="wp-header-end">

			<?php $this->render_notices(); ?>

			<?php $this->render_locale_tabs( $form->id, $active_locale, $locales_with_versions ); ?>

			<?php $this->render_locale_content( $form, $active_locale, $locale_versions, $total_versions, $current_page, $per_page ); ?>
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
	 * @param int      $form_id                Form ID.
	 * @param string   $active_locale          Currently active locale.
	 * @param string[] $locales_with_versions  Locales that have at least one version.
	 * @return void
	 */
	private function render_locale_tabs( int $form_id, string $active_locale, array $locales_with_versions ): void {
		?>
		<nav class="nav-tab-wrapper" style="margin-bottom:1.5rem;">
			<?php foreach ( ConsentVersion::SUPPORTED_LOCALES as $locale => $label ) : ?>
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
				$has_text  = in_array( $locale, $locales_with_versions, true );
				$css       = 'nav-tab' . ( $is_active ? ' nav-tab-active' : '' );
				?>
				<a href="<?php echo esc_url( $tab_url ); ?>" class="<?php echo esc_attr( $css ); ?>">
					<?php echo esc_html( $label ); ?>
					<?php if ( $has_text ) : ?>
						<span class="dashicons dashicons-yes" style="font-size:16px;width:16px;height:16px;vertical-align:middle;color:#46b450;" title="<?php esc_attr_e( 'Text vorhanden', 'wp-dsgvo-form' ); ?>"></span>
					<?php endif; ?>
				</a>
			<?php endforeach; ?>
		</nav>
		<?php
	}

	/**
	 * Renders the content for the active locale tab.
	 *
	 * @param Form             $form           The form.
	 * @param string           $locale         Active locale.
	 * @param ConsentVersion[] $versions       Paginated versions for this locale (newest first).
	 * @param int              $total_versions Total version count for pagination.
	 * @param int              $current_page   Current pagination page.
	 * @param int              $per_page       Items per page.
	 * @return void
	 */
	private function render_locale_content( Form $form, string $locale, array $versions, int $total_versions, int $current_page, int $per_page ): void {
		$current     = ! empty( $versions ) ? $versions[0] : null;
		$locale_label = ConsentVersion::SUPPORTED_LOCALES[ $locale ] ?? $locale;

		// Current version info.
		if ( $current !== null ) {
			?>
			<div class="notice notice-info inline" style="margin:0 0 1rem 0;">
				<p>
					<?php
					printf(
						/* translators: 1: version number, 2: valid_from date */
						esc_html__( 'Aktuelle Version: %1$d (gueltig seit %2$s)', 'wp-dsgvo-form' ),
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
						esc_html__( 'Kein Einwilligungstext fuer %s vorhanden. Das Formular wird fuer diese Sprache nicht gerendert (Fail-Closed).', 'wp-dsgvo-form' ),
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

			// PERF-SOLL-01: Pagination links.
			$total_pages = (int) ceil( $total_versions / $per_page );
			if ( $total_pages > 1 ) {
				$this->render_pagination( $form->id, $locale, $current_page, $total_pages );
			}
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
								<?php esc_html_e( 'Einwilligungstext', 'wp-dsgvo-form' ); ?>
							</label>
						</th>
						<td>
							<textarea
								id="consent_text"
								name="consent_text"
								rows="8"
								class="large-text"
								placeholder="<?php esc_attr_e( 'Einwilligungstext eingeben...', 'wp-dsgvo-form' ); ?>"
							><?php echo esc_textarea( $current !== null ? $current->consent_text : '' ); ?></textarea>
							<p class="description">
								<?php esc_html_e( 'HTML-Formatierung erlaubt (Links, Fettschrift). Jede Aenderung erstellt eine neue, unveraenderliche Version (Art. 7 DSGVO).', 'wp-dsgvo-form' ); ?>
							</p>
								<?php
								// UX-TMPL-01: "Vorlage laden" button — integrates ConsentTemplateHelper.
								$privacy_url_hint = $current !== null && $current->privacy_policy_url !== null ? $current->privacy_policy_url : '';
								$template_text    = ConsentTemplateHelper::get_resolved_template( $locale, $form, $privacy_url_hint );
								if ( $template_text !== '' ) :
								?>
									<button type="button"
										id="dsgvo-load-template"
										class="button"
										style="margin-top:0.5rem;">
										<?php esc_html_e( 'Vorlage laden', 'wp-dsgvo-form' ); ?>
									</button>
									<span class="description" style="margin-left:0.5rem;">
										<?php esc_html_e( 'ENTWURF — muss vom Legal Expert freigegeben werden.', 'wp-dsgvo-form' ); ?>
									</span>
									<script>
									(function() {
										var template = <?php echo wp_json_encode( $template_text ); ?>;
										var btn = document.getElementById('dsgvo-load-template');
										if (!btn) { return; }
										btn.addEventListener('click', function() {
											var textarea = document.getElementById('consent_text');
											if (!textarea) { return; }
											if (textarea.value.trim() !== '' && !confirm(<?php echo wp_json_encode( __( 'Vorhandenen Text ueberschreiben?', 'wp-dsgvo-form' ) ); ?>)) {
												return;
											}
											textarea.value = template;
										});
									})();
									</script>
								<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="privacy_policy_url">
								<?php esc_html_e( 'Datenschutzerklaerung-URL', 'wp-dsgvo-form' ); ?>
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
								<?php esc_html_e( 'Optional. Sprachspezifischer Link zur Datenschutzerklaerung. Muss HTTPS verwenden.', 'wp-dsgvo-form' ); ?>
							</p>
						</td>
					</tr>
				</tbody>
			</table>

			<?php
			submit_button(
				__( 'Neue Version speichern', 'wp-dsgvo-form' ),
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
		<h2><?php esc_html_e( 'Versions-Historie', 'wp-dsgvo-form' ); ?></h2>
		<p class="description" style="margin-bottom:0.5rem;">
			<?php esc_html_e( 'Fruehere Versionen sind unveraenderlich und dienen als Nachweis der exakten Einwilligungsformulierung (Art. 7 Abs. 1 DSGVO).', 'wp-dsgvo-form' ); ?>
		</p>

		<table class="widefat striped">
			<thead>
				<tr>
					<th style="width:5%;"><?php esc_html_e( 'Version', 'wp-dsgvo-form' ); ?></th>
					<th style="width:45%;"><?php esc_html_e( 'Einwilligungstext', 'wp-dsgvo-form' ); ?></th>
					<th style="width:20%;"><?php esc_html_e( 'Datenschutz-URL', 'wp-dsgvo-form' ); ?></th>
					<th style="width:15%;"><?php esc_html_e( 'Gueltig seit', 'wp-dsgvo-form' ); ?></th>
					<th style="width:15%;"><?php esc_html_e( 'Erstellt am', 'wp-dsgvo-form' ); ?></th>
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
