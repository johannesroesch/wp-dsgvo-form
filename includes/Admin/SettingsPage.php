<?php
/**
 * Plugin settings page.
 *
 * Manages general plugin settings including CAPTCHA configuration,
 * default retention period, and encryption key status.
 *
 * @package WpDsgvoForm
 */

declare(strict_types=1);

namespace WpDsgvoForm\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Displays the plugin settings admin page.
 */
class SettingsPage {

	/**
	 * The option group name for Settings API.
	 *
	 * @var string
	 */
	private const OPTION_GROUP = 'dsgvo_form_settings';

	/**
	 * Register settings via the WordPress Settings API.
	 *
	 * @return void
	 */
	public function register_settings(): void {
		register_setting(
			self::OPTION_GROUP,
			'wpdsgvo_captcha_secret',
			array(
				'type'              => 'string',
				'default'           => '',
				'sanitize_callback' => 'sanitize_text_field',
			)
		);

		register_setting(
			self::OPTION_GROUP,
			'wpdsgvo_default_retention_days',
			array(
				'type'              => 'integer',
				'default'           => 90,
				'sanitize_callback' => array( $this, 'sanitize_retention_days' ),
			)
		);

		register_setting(
			self::OPTION_GROUP,
			'wpdsgvo_controller_name',
			array(
				'type'              => 'string',
				'default'           => '',
				'sanitize_callback' => 'sanitize_text_field',
			)
		);

		register_setting(
			self::OPTION_GROUP,
			'wpdsgvo_controller_email',
			array(
				'type'              => 'string',
				'default'           => '',
				'sanitize_callback' => 'sanitize_email',
			)
		);

		add_settings_section(
			'dsgvo_form_captcha_section',
			__( 'CAPTCHA-Konfiguration', 'wp-dsgvo-form' ),
			array( $this, 'render_captcha_section' ),
			self::OPTION_GROUP
		);

		add_settings_field(
			'wpdsgvo_captcha_secret',
			__( 'Secret Key', 'wp-dsgvo-form' ),
			array( $this, 'render_captcha_secret_field' ),
			self::OPTION_GROUP,
			'dsgvo_form_captcha_section'
		);

		add_settings_section(
			'dsgvo_form_general_section',
			__( 'Allgemeine Einstellungen', 'wp-dsgvo-form' ),
			array( $this, 'render_general_section' ),
			self::OPTION_GROUP
		);

		add_settings_field(
			'wpdsgvo_default_retention_days',
			__( 'Standard-Aufbewahrungsfrist (Tage)', 'wp-dsgvo-form' ),
			array( $this, 'render_retention_field' ),
			self::OPTION_GROUP,
			'dsgvo_form_general_section'
		);

		add_settings_field(
			'wpdsgvo_controller_name',
			__( 'Verantwortlicher (Name)', 'wp-dsgvo-form' ),
			array( $this, 'render_controller_name_field' ),
			self::OPTION_GROUP,
			'dsgvo_form_general_section'
		);

		add_settings_field(
			'wpdsgvo_controller_email',
			__( 'Verantwortlicher (E-Mail)', 'wp-dsgvo-form' ),
			array( $this, 'render_controller_email_field' ),
			self::OPTION_GROUP,
			'dsgvo_form_general_section'
		);
	}

	/**
	 * Render the settings page.
	 *
	 * @return void
	 */
	public function render(): void {
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Einstellungen', 'wp-dsgvo-form' ); ?></h1>

			<form method="post" action="options.php">
				<?php
				settings_fields( self::OPTION_GROUP );
				do_settings_sections( self::OPTION_GROUP );
				submit_button();
				?>
			</form>

			<hr>

			<h2><?php esc_html_e( 'Systemstatus', 'wp-dsgvo-form' ); ?></h2>
			<table class="widefat striped">
				<tbody>
					<tr>
						<td><strong><?php esc_html_e( 'Verschluesselungs-Key', 'wp-dsgvo-form' ); ?></strong></td>
						<td>
							<?php
							if ( defined( 'DSGVO_FORM_ENCRYPTION_KEY' ) && '' !== DSGVO_FORM_ENCRYPTION_KEY ) {
								echo '<span class="dashicons dashicons-yes-alt" style="color:#46b450;"></span> ';
								esc_html_e( 'Konfiguriert', 'wp-dsgvo-form' );
							} else {
								echo '<span class="dashicons dashicons-warning" style="color:#dc3232;"></span> ';
								esc_html_e( 'Nicht konfiguriert — Verschluesselung nicht moeglich', 'wp-dsgvo-form' );
							}
							?>
						</td>
					</tr>
					<tr>
						<td><strong><?php esc_html_e( 'Plugin-Version', 'wp-dsgvo-form' ); ?></strong></td>
						<td><?php echo esc_html( WPDSGVO_VERSION ); ?></td>
					</tr>
					<tr>
						<td><strong><?php esc_html_e( 'PHP-Version', 'wp-dsgvo-form' ); ?></strong></td>
						<td><?php echo esc_html( PHP_VERSION ); ?></td>
					</tr>
					<tr>
						<td><strong><?php esc_html_e( 'OpenSSL', 'wp-dsgvo-form' ); ?></strong></td>
						<td>
							<?php
							if ( extension_loaded( 'openssl' ) ) {
								echo '<span class="dashicons dashicons-yes-alt" style="color:#46b450;"></span> ';
								echo esc_html( OPENSSL_VERSION_TEXT );
							} else {
								echo '<span class="dashicons dashicons-warning" style="color:#dc3232;"></span> ';
								esc_html_e( 'Nicht verfuegbar', 'wp-dsgvo-form' );
							}
							?>
						</td>
					</tr>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * Render the CAPTCHA section description.
	 *
	 * @return void
	 */
	public function render_captcha_section(): void {
		echo '<p>';
		printf(
			/* translators: %s: CAPTCHA service URL */
			esc_html__( 'CAPTCHA-Service: %s (konfiguriert als WPDSGVO_CAPTCHA_URL-Konstante in wp-dsgvo-form.php). Hier wird nur der Secret Key fuer die Server-zu-Server-Validierung hinterlegt.', 'wp-dsgvo-form' ),
			'<code>' . esc_html( WPDSGVO_CAPTCHA_URL ) . '</code>'
		);
		echo '</p>';
		echo '<p class="description">';
		esc_html_e( 'Hinweis: Bei der Server-zu-Server-Validierung (POST /api/validate) wird die IP-Adresse des Webservers an den CAPTCHA-Service uebermittelt. Sofern der CAPTCHA-Service von einem externen Anbieter betrieben wird, kann ein Auftragsverarbeitungsvertrag (AVV) nach Art. 28 DSGVO erforderlich sein. Bitte pruefen Sie dies mit Ihrem Datenschutzbeauftragten.', 'wp-dsgvo-form' );
		echo '</p>';
	}

	/**
	 * Render the CAPTCHA secret key field.
	 *
	 * @return void
	 */
	public function render_captcha_secret_field(): void {
		$value = get_option( 'wpdsgvo_captcha_secret', '' );
		?>
		<input type="password"
			name="wpdsgvo_captcha_secret"
			id="wpdsgvo_captcha_secret"
			value="<?php echo esc_attr( $value ); ?>"
			class="regular-text">
		<?php
	}

	/**
	 * Render the general section description.
	 *
	 * @return void
	 */
	public function render_general_section(): void {
		echo '<p>';
		esc_html_e( 'Allgemeine Plugin-Einstellungen fuer DSGVO-konforme Formularverarbeitung.', 'wp-dsgvo-form' );
		echo '</p>';
	}

	/**
	 * Render the retention days field.
	 *
	 * @return void
	 */
	public function render_retention_field(): void {
		$value = get_option( 'wpdsgvo_default_retention_days', 90 );
		?>
		<input type="number"
			name="wpdsgvo_default_retention_days"
			id="wpdsgvo_default_retention_days"
			value="<?php echo esc_attr( (string) $value ); ?>"
			min="1"
			max="3650"
			step="1"
			class="small-text">
		<p class="description">
			<?php esc_html_e( 'Standard-Aufbewahrungsfrist in Tagen (1–3650). Einsendungen werden nach Ablauf automatisch geloescht.', 'wp-dsgvo-form' ); ?>
		</p>
		<?php
	}

	/**
	 * Render the controller name field.
	 *
	 * @return void
	 */
	public function render_controller_name_field(): void {
		$value = get_option( 'wpdsgvo_controller_name', '' );
		if ( '' === $value ) {
			$value = get_option( 'blogname' );
		}
		?>
		<input type="text"
			name="wpdsgvo_controller_name"
			id="wpdsgvo_controller_name"
			value="<?php echo esc_attr( $value ); ?>"
			class="regular-text">
		<p class="description">
			<?php esc_html_e( 'Name des Verantwortlichen (Art. 13 Abs. 1 lit. a DSGVO). Standard: Website-Titel.', 'wp-dsgvo-form' ); ?>
		</p>
		<?php
	}

	/**
	 * Render the controller email field.
	 *
	 * @return void
	 */
	public function render_controller_email_field(): void {
		$value = get_option( 'wpdsgvo_controller_email', '' );
		if ( '' === $value ) {
			$value = get_option( 'admin_email' );
		}
		?>
		<input type="email"
			name="wpdsgvo_controller_email"
			id="wpdsgvo_controller_email"
			value="<?php echo esc_attr( $value ); ?>"
			class="regular-text">
		<p class="description">
			<?php esc_html_e( 'Kontakt-E-Mail des Verantwortlichen (Art. 13 Abs. 1 lit. a DSGVO). Standard: Administrator-E-Mail.', 'wp-dsgvo-form' ); ?>
		</p>
		<?php
	}

	/**
	 * Sanitize callback for the retention days. Enforces range 1–3650 (DPO-FINDING-18).
	 *
	 * @param mixed $value The submitted retention days value.
	 * @return int Clamped value between 1 and 3650.
	 *
	 * @privacy-relevant DPO-FINDING-01 — No unlimited storage allowed
	 */
	public function sanitize_retention_days( $value ): int {
		return max( 1, min( 3650, absint( $value ) ) );
	}
}
