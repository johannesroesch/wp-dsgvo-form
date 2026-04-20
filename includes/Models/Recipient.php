<?php
declare(strict_types=1);

namespace WpDsgvoForm\Models;

defined('ABSPATH') || exit;

/**
 * Recipient model — CRUD for the dsgvo_form_recipients table.
 *
 * Manages form-to-user assignments for submission access and email notifications.
 * Recipients are WordPress users with the dsgvo_form_recipient role.
 *
 * @privacy-relevant Art. 5 Abs. 1 lit. c DSGVO — Datenminimierung (Zugriff nur auf zugewiesene Formulare)
 */
class Recipient {

	public int $id                     = 0;
	public int $form_id                = 0;
	public int $user_id                = 0;
	public bool $notify_email          = true;
	public string $role_justification  = '';
	public string $created_at          = '';

	/**
	 * Returns the full table name with WordPress prefix.
	 */
	public static function get_table_name(): string {
		global $wpdb;
		return $wpdb->prefix . 'dsgvo_form_recipients';
	}

	/**
	 * Finds a recipient assignment by ID.
	 */
	public static function find( int $id ): ?self {
		global $wpdb;
		$table = self::get_table_name();

		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM `{$table}` WHERE id = %d", $id ),
			ARRAY_A
		);

		if ( $row === null ) {
			return null;
		}

		return self::from_row( $row );
	}

	/**
	 * Returns all recipients assigned to a form.
	 *
	 * @return self[]
	 */
	public static function find_by_form_id( int $form_id ): array {
		global $wpdb;
		$table = self::get_table_name();

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM `{$table}` WHERE form_id = %d ORDER BY created_at ASC",
				$form_id
			),
			ARRAY_A
		);

		return array_map( [ self::class, 'from_row' ], $rows ?: [] );
	}

	/**
	 * Returns all recipient assignments for a given WordPress user.
	 *
	 * Used to determine which forms a recipient can access in the dashboard.
	 *
	 * @return self[]
	 */
	public static function find_by_user_id( int $user_id ): array {
		global $wpdb;
		$table = self::get_table_name();

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM `{$table}` WHERE user_id = %d ORDER BY created_at ASC",
				$user_id
			),
			ARRAY_A
		);

		return array_map( [ self::class, 'from_row' ], $rows ?: [] );
	}

	/**
	 * Returns all recipients for a form who have email notifications enabled.
	 *
	 * @return self[]
	 */
	public static function find_notifiable_by_form_id( int $form_id ): array {
		global $wpdb;
		$table = self::get_table_name();

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM `{$table}` WHERE form_id = %d AND notify_email = %d",
				$form_id,
				1
			),
			ARRAY_A
		);

		return array_map( [ self::class, 'from_row' ], $rows ?: [] );
	}

	/**
	 * Returns the form IDs a user is assigned to as recipient.
	 *
	 * @return int[]
	 */
	public static function get_form_ids_for_user( int $user_id ): array {
		global $wpdb;
		$table = self::get_table_name();

		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT form_id FROM `{$table}` WHERE user_id = %d",
				$user_id
			)
		);

		return array_map( 'intval', $ids ?: [] );
	}

	/**
	 * Checks whether a user is already assigned as recipient for a form.
	 */
	public static function exists( int $form_id, int $user_id ): bool {
		global $wpdb;
		$table = self::get_table_name();

		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM `{$table}` WHERE form_id = %d AND user_id = %d",
				$form_id,
				$user_id
			)
		);

		return $count > 0;
	}

	/**
	 * Saves the recipient assignment (insert or update).
	 *
	 * @return int The recipient ID.
	 * @throws \RuntimeException On validation failure or duplicate assignment.
	 *
	 * @privacy-relevant SEC-AUTH-DSGVO-01 — Supervisor-Zuweisung erfordert Zweckdokumentation
	 */
	public function save(): int {
		$this->validate();

		global $wpdb;
		$table = self::get_table_name();
		$data  = $this->to_db_array();

		if ( $this->id === 0 ) {
			if ( self::exists( $this->form_id, $this->user_id ) ) {
				throw new \RuntimeException(
					'User is already assigned as recipient for this form.'
				);
			}

			$wpdb->insert( $table, $data, self::get_formats( $data ) );

			if ( $wpdb->insert_id === 0 ) {
				throw new \RuntimeException( 'Failed to insert recipient: ' . esc_html( $wpdb->last_error ) );
			}

			$this->id = (int) $wpdb->insert_id;
		} else {
			$wpdb->update(
				$table,
				$data,
				[ 'id' => $this->id ],
				self::get_formats( $data ),
				[ '%d' ]
			);
		}

		return $this->id;
	}

	/**
	 * Deletes a recipient assignment by ID.
	 */
	public static function delete( int $id ): bool {
		global $wpdb;
		$table  = self::get_table_name();
		$result = $wpdb->delete( $table, [ 'id' => $id ], [ '%d' ] );

		return $result !== false;
	}

	/**
	 * Removes a specific user from a specific form.
	 */
	public static function delete_by_form_and_user( int $form_id, int $user_id ): bool {
		global $wpdb;
		$table = self::get_table_name();

		$result = $wpdb->delete(
			$table,
			[
				'form_id' => $form_id,
				'user_id' => $user_id,
			],
			[ '%d', '%d' ]
		);

		return $result !== false;
	}

	/**
	 * Validates recipient data before save.
	 *
	 * @throws \RuntimeException On validation failure.
	 */
	private function validate(): void {
		if ( $this->form_id < 1 ) {
			throw new \RuntimeException( 'Recipient must belong to a form (form_id required).' );
		}

		if ( $this->user_id < 1 ) {
			throw new \RuntimeException( 'Recipient must reference a WordPress user (user_id required).' );
		}
	}

	/**
	 * Creates a Recipient instance from a database row.
	 */
	private static function from_row( array $row ): self {
		$recipient                     = new self();
		$recipient->id                 = (int) ( $row['id'] ?? 0 );
		$recipient->form_id            = (int) ( $row['form_id'] ?? 0 );
		$recipient->user_id            = (int) ( $row['user_id'] ?? 0 );
		$recipient->notify_email       = (bool) ( $row['notify_email'] ?? true );
		$recipient->role_justification = (string) ( $row['role_justification'] ?? '' );
		$recipient->created_at         = (string) ( $row['created_at'] ?? '' );

		return $recipient;
	}

	/**
	 * Converts recipient properties to an associative array for DB operations.
	 */
	private function to_db_array(): array {
		$data = [
			'form_id'      => $this->form_id,
			'user_id'      => $this->user_id,
			'notify_email' => (int) $this->notify_email,
		];

		if ( $this->role_justification !== '' ) {
			$data['role_justification'] = $this->role_justification;
		}

		return $data;
	}

	/**
	 * Returns wpdb format specifiers matching the data array.
	 *
	 * @param array<string, mixed> $data Column => value pairs.
	 * @return string[]
	 */
	private static function get_formats( array $data ): array {
		$formats = [];
		foreach ( $data as $value ) {
			$formats[] = is_int( $value ) ? '%d' : '%s';
		}
		return $formats;
	}
}
