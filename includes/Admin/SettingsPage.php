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
			'wpdsgvo_captcha_provider',
			array(
				'type'              => 'string',
				'default'           => 'custom',
				'sanitize_callback' => array( $this, 'sanitize_captcha_provider' ),
			)
		);

		register_setting(
			self::OPTION_GROUP,
			'wpdsgvo_captcha_base_url',
			array(
				'type'              => 'string',
				'default'           => 'https://captcha.repaircafe-bruchsal.de',
				'sanitize_callback' => array( $this, 'sanitize_captcha_base_url' ),
			)
		);

		register_setting(
			self::OPTION_GROUP,
			'wpdsgvo_captcha_sitekey',
			array(
				'type'              => 'string',
				'default'           => '',
				'sanitize_callback' => 'sanitize_text_field',
			)
		);

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
			'wpdsgvo_captcha_sri_hash',
			array(
				'type'              => 'string',
				'default'           => '',
				'sanitize_callback' => array( $this, 'sanitize_sri_hash' ),
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

		add_settings_section(
			'dsgvo_form_captcha_section',
			__( 'CAPTCHA-Konfiguration', 'wp-dsgvo-form' ),
			array( $this, 'render_captcha_section' ),
			self::OPTION_GROUP
		);

		add_settings_field(
			'wpdsgvo_captcha_provider',
			__( 'CAPTCHA-Anbieter', 'wp-dsgvo-form' ),
			array( $this, 'render_captcha_provider_field' ),
			self::OPTION_GROUP,
			'dsgvo_form_captcha_section'
		);

		add_settings_field(
			'wpdsgvo_captcha_base_url',
			__( 'CAPTCHA-Server-URL', 'wp-dsgvo-form' ),
			array( $this, 'render_captcha_base_url_field' ),
			self::OPTION_GROUP,
			'dsgvo_form_captcha_section'
		);

		add_settings_field(
			'wpdsgvo_captcha_sitekey',
			__( 'Site Key', 'wp-dsgvo-form' ),
			array( $this, 'render_captcha_sitekey_field' ),
			self::OPTION_GROUP,
			'dsgvo_form_captcha_section'
		);

		add_settings_field(
			'wpdsgvo_captcha_secret',
			__( 'Secret Key', 'wp-dsgvo-form' ),
			array( $this, 'render_captcha_secret_field' ),
			self::OPTION_GROUP,
			'dsgvo_form_captcha_section'
		);

		add_settings_field(
			'wpdsgvo_captcha_sri_hash',
			__( 'SRI-Hash (Subresource Integrity)', 'wp-dsgvo-form' ),
			array( $this, 'render_captcha_sri_hash_field' ),
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
		esc_html_e( 'Konfigurieren Sie den CAPTCHA-Dienst zum Schutz vor Spam-Einsendungen.', 'wp-dsgvo-form' );
		echo '</p>';
	}

	/**
	 * Render the CAPTCHA provider select field.
	 *
	 * @return void
	 */
	public function render_captcha_provider_field(): void {
		$value = get_option( 'wpdsgvo_captcha_provider', 'custom' );
		?>
		<select name="wpdsgvo_captcha_provider" id="wpdsgvo_captcha_provider">
			<option value="custom" <?php selected( $value, 'custom' ); ?>>
				<?php esc_html_e( 'Custom CAPTCHA Service', 'wp-dsgvo-form' ); ?>
			</option>
			<option value="friendly-captcha" <?php selected( $value, 'friendly-captcha' ); ?>>
				<?php esc_html_e( 'Friendly Captcha (DSGVO-konform)', 'wp-dsgvo-form' ); ?>
			</option>
			<option value="hcaptcha" <?php selected( $value, 'hcaptcha' ); ?>>
				<?php esc_html_e( 'hCaptcha', 'wp-dsgvo-form' ); ?>
			</option>
		</select>
		<p class="description">
			<?php esc_html_e( 'Standard: Custom CAPTCHA Service (captcha.repaircafe-bruchsal.de).', 'wp-dsgvo-form' ); ?>
		</p>
		<?php
	}

	/**
	 * Render the CAPTCHA server URL field (used for script, widget, and verification).
	 *
	 * @return void
	 */
	public function render_captcha_base_url_field(): void {
		$value = get_option( 'wpdsgvo_captcha_base_url', 'https://captcha.repaircafe-bruchsal.de' );
		?>
		<input type="url"
			name="wpdsgvo_captcha_base_url"
			id="wpdsgvo_captcha_base_url"
			value="<?php echo esc_attr( $value ); ?>"
			class="regular-text"
			placeholder="https://captcha.repaircafe-bruchsal.de">
		<p class="description">
			<?php esc_html_e( 'Basis-URL des CAPTCHA-Service. Wird fuer Script (/captcha.js), Widget (server-url) und Verifikation (/api/verify) verwendet. Muss HTTPS verwenden.', 'wp-dsgvo-form' ); ?>
		</p>
		<?php
	}

	/**
	 * Render the CAPTCHA site key field.
	 *
	 * @return void
	 */
	public function render_captcha_sitekey_field(): void {
		$value = get_option( 'wpdsgvo_captcha_sitekey', '' );
		?>
		<input type="text"
			name="wpdsgvo_captcha_sitekey"
			id="wpdsgvo_captcha_sitekey"
			value="<?php echo esc_attr( $value ); ?>"
			class="regular-text">
		<?php
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
	 * Sanitize callback for CAPTCHA provider — whitelist validation.
	 *
	 * @param string $value The submitted provider value.
	 * @return string Validated provider, or 'custom' as fallback.
	 */
	public function sanitize_captcha_provider( string $value ): string {
		$allowed = [ 'custom', 'friendly-captcha', 'hcaptcha' ];

		return in_array( $value, $allowed, true ) ? $value : 'custom';
	}

	/**
	 * Sanitize callback for the CAPTCHA base URL. Enforces HTTPS, strips trailing slash.
	 *
	 * @param string $url The URL to sanitize.
	 * @return string Sanitized HTTPS URL without trailing slash, or previous value on failure.
	 */
	public function sanitize_captcha_base_url( string $url ): string {
		$url = sanitize_url( rtrim( $url, '/' ) );

		if ( '' !== $url && strpos( $url, 'https://' ) !== 0 ) {
			add_settings_error(
				'wpdsgvo_captcha_base_url',
				'not_https',
				__( 'CAPTCHA-Server-URL muss HTTPS verwenden.', 'wp-dsgvo-form' ),
				'error'
			);

			return get_option( 'wpdsgvo_captcha_base_url', 'https://captcha.repaircafe-bruchsal.de' );
		}

		return $url;
	}

	/**
	 * Render the CAPTCHA SRI hash field.
	 *
	 * @return void
	 */
	public function render_captcha_sri_hash_field(): void {
		$value = get_option( 'wpdsgvo_captcha_sri_hash', '' );
		?>
		<input type="text"
			name="wpdsgvo_captcha_sri_hash"
			id="wpdsgvo_captcha_sri_hash"
			value="<?php echo esc_attr( $value ); ?>"
			class="large-text"
			placeholder="sha384-...">
		<p class="description">
			<?php esc_html_e( 'SRI-Hash des CAPTCHA-Scripts (z.B. sha384-...). Schuetzt vor Manipulation des externen Scripts. Leer lassen wenn nicht verfuegbar.', 'wp-dsgvo-form' ); ?>
		</p>
		<?php
	}

	/**
	 * Sanitize callback for the SRI hash. Validates format (sha256/sha384/sha512-base64).
	 *
	 * @param string $hash The SRI hash to sanitize.
	 * @return string Validated SRI hash, or empty string on failure.
	 */
	public function sanitize_sri_hash( string $hash ): string {
		$hash = trim( $hash );

		if ( '' === $hash ) {
			return '';
		}

		if ( ! preg_match( '/^sha(256|384|512)-[A-Za-z0-9+\/=]+$/', $hash ) ) {
			add_settings_error(
				'wpdsgvo_captcha_sri_hash',
				'invalid_sri',
				__( 'Ungueltiges SRI-Hash-Format. Erwartet: sha256-..., sha384-... oder sha512-...', 'wp-dsgvo-form' ),
				'error'
			);

			return get_option( 'wpdsgvo_captcha_sri_hash', '' );
		}

		return $hash;
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
