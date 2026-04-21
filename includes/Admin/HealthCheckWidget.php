<?php
/**
 * Encryption health-check dashboard widget.
 *
 * Displays a status overview of the encryption subsystem on the
 * WordPress admin dashboard. Checks KEK availability, OpenSSL cipher
 * support, and validates a round-trip encrypt/decrypt cycle.
 *
 * SEC-KANN-03: Proactive health monitoring for encryption configuration.
 *
 * @package WpDsgvoForm
 */

declare(strict_types=1);

namespace WpDsgvoForm\Admin;

defined( 'ABSPATH' ) || exit;

use WpDsgvoForm\Encryption\KeyManager;

/**
 * Registers and renders the encryption health-check dashboard widget.
 */
class HealthCheckWidget {

	private const WIDGET_ID = 'dsgvo_form_health_check';

	/**
	 * Registers the dashboard widget hook.
	 */
	public function register(): void {
		add_action( 'wp_dashboard_setup', [ $this, 'add_widget' ] );
	}

	/**
	 * Adds the widget to the WordPress dashboard.
	 */
	public function add_widget(): void {
		if ( ! current_user_can( 'dsgvo_form_manage' ) ) {
			return;
		}

		wp_add_dashboard_widget(
			self::WIDGET_ID,
			__( 'DSGVO Form — Verschluesselungs-Status', 'wp-dsgvo-form' ),
			[ $this, 'render' ]
		);
	}

	/**
	 * Renders the widget content.
	 */
	public function render(): void {
		$checks = $this->run_checks();

		echo '<table class="widefat striped" role="presentation">';
		echo '<tbody>';

		foreach ( $checks as $check ) {
			$icon  = $check['ok'] ? '&#9989;' : '&#10060;';
			$label = esc_html( $check['label'] );
			$desc  = esc_html( $check['description'] );

			printf(
				'<tr><td style="width:2em;text-align:center">%s</td><td><strong>%s</strong><br><small>%s</small></td></tr>',
				$icon,
				$label,
				$desc
			);
		}

		echo '</tbody>';
		echo '</table>';

		$all_ok = ! in_array( false, array_column( $checks, 'ok' ), true );

		if ( $all_ok ) {
			printf(
				'<p style="margin-top:0.75em;color:#00a32a"><strong>%s</strong></p>',
				esc_html__( 'Alle Pruefungen bestanden. Die Verschluesselung ist betriebsbereit.', 'wp-dsgvo-form' )
			);
		} else {
			printf(
				'<p style="margin-top:0.75em;color:#d63638"><strong>%s</strong></p>',
				esc_html__( 'Achtung: Mindestens eine Pruefung ist fehlgeschlagen. Einsendungen koennen nicht verschluesselt werden.', 'wp-dsgvo-form' )
			);
		}
	}

	/**
	 * Runs all health checks and returns results.
	 *
	 * @return array<int, array{label: string, ok: bool, description: string}>
	 */
	private function run_checks(): array {
		$key_manager = new KeyManager();
		$checks      = [];

		// Check 1: KEK constant defined.
		$kek_available = $key_manager->is_kek_available();
		$checks[]      = [
			'label'       => __( 'Master Key (KEK)', 'wp-dsgvo-form' ),
			'ok'          => $kek_available,
			'description' => $kek_available
				? sprintf(
					/* translators: %s: constant name */
					__( '%s ist in wp-config.php definiert.', 'wp-dsgvo-form' ),
					$key_manager->get_kek_constant_name()
				)
				: sprintf(
					/* translators: %s: constant name */
					__( '%s ist nicht definiert. Bitte in wp-config.php konfigurieren.', 'wp-dsgvo-form' ),
					$key_manager->get_kek_constant_name()
				),
		];

		// Check 2: KEK valid format (32 bytes base64).
		$kek_valid = false;
		if ( $kek_available ) {
			try {
				$key_manager->get_kek();
				$kek_valid = true;
			} catch ( \RuntimeException $e ) {
				// Invalid format.
			}
		}
		$checks[] = [
			'label'       => __( 'KEK-Format', 'wp-dsgvo-form' ),
			'ok'          => $kek_valid,
			'description' => $kek_valid
				? __( 'Gueltiger base64-codierter 256-Bit-Schluessel.', 'wp-dsgvo-form' )
				: __( 'Ungueltig oder fehlend. Erwartet: 44 Zeichen base64 (32 Byte).', 'wp-dsgvo-form' ),
		];

		// Check 3: OpenSSL cipher available.
		$cipher_ok = in_array( 'aes-256-gcm', openssl_get_cipher_methods(), true );
		$checks[]  = [
			'label'       => __( 'OpenSSL AES-256-GCM', 'wp-dsgvo-form' ),
			'ok'          => $cipher_ok,
			'description' => $cipher_ok
				? __( 'Cipher ist verfuegbar.', 'wp-dsgvo-form' )
				: __( 'Cipher nicht verfuegbar. OpenSSL-Installation pruefen.', 'wp-dsgvo-form' ),
		];

		// Check 4: Round-trip test.
		$roundtrip_ok = false;
		if ( $kek_valid && $cipher_ok ) {
			$roundtrip_ok = $this->test_roundtrip( $key_manager );
		}
		$checks[] = [
			'label'       => __( 'Roundtrip-Test', 'wp-dsgvo-form' ),
			'ok'          => $roundtrip_ok,
			'description' => $roundtrip_ok
				? __( 'Verschluesselung und Entschluesselung funktionieren korrekt.', 'wp-dsgvo-form' )
				: __( 'Roundtrip fehlgeschlagen. DEK-Verschluesselung pruefen.', 'wp-dsgvo-form' ),
		];

		return $checks;
	}

	/**
	 * Tests a full DEK encrypt/decrypt round-trip.
	 *
	 * @param KeyManager $key_manager Key manager instance.
	 * @return bool True if round-trip succeeds.
	 */
	private function test_roundtrip( KeyManager $key_manager ): bool {
		try {
			$dek        = $key_manager->generate_dek();
			$encrypted  = $key_manager->encrypt_dek( $dek );

			$decrypted = $key_manager->decrypt_dek(
				$encrypted['encrypted_dek'],
				$encrypted['dek_iv']
			);

			return hash_equals( $dek, $decrypted );
		} catch ( \Throwable $e ) {
			return false;
		}
	}
}
