<?php
/**
 * Audit logging service.
 *
 * Logs all access to submissions for DSGVO accountability (Art. 5 Abs. 2).
 * Audit entries are tamper-resistant: no admin-facing delete method.
 *
 * @package WpDsgvoForm
 */

declare(strict_types=1);

namespace WpDsgvoForm\Audit;

defined('ABSPATH') || exit;

/**
 * Writes and reads audit log entries for submission access.
 *
 * Every view, export, or delete action on a submission is recorded.
 * Supervisor access is always logged; admin access is logged for
 * destructive actions (export, delete).
 *
 * SEC-AUDIT-03: No public delete method — the audit log is NOT
 * deletable by any admin through the plugin UI.
 * Cleanup is handled exclusively via scheduled cron jobs.
 *
 * Security requirements: SEC-AUDIT-01 through SEC-AUDIT-05.
 */
class AuditLogger
{

    /**
     * Allowed action types for audit entries.
     *
     * @var string[]
     */
    public const ALLOWED_ACTIONS = [
        'view',
        'export',
        'delete',
        'restrict',
    ];

    /**
     * Days after which IP addresses are anonymized (SEC-AUDIT-04).
     */
    private const IP_RETENTION_DAYS = 90;

    /**
     * Days after which audit entries are deleted (SEC-AUDIT-03).
     */
    private const ENTRY_RETENTION_DAYS = 365;

    /**
     * Logs an access event to the audit table.
     *
     * SEC-AUDIT-01: Records who accessed which submission and when.
     * SEC-AUDIT-02: Stores user_id, action, submission_id, form_id,
     * ip_address, details, and timestamp.
     *
     * @param int      $user_id       The WordPress user ID performing the action.
     * @param string   $action        The action type (view|export|delete).
     * @param int|null $submission_id The submission being accessed, if applicable.
     * @param int|null $form_id       The form ID, if applicable.
     * @param string|null $details    Optional additional details (JSON or text).
     * @return bool True if the log entry was inserted successfully.
     */
    public function log(
        int $user_id,
        string $action,
        ?int $submission_id = null,
        ?int $form_id = null,
        ?string $details = null
    ): bool {
        if (!in_array($action, self::ALLOWED_ACTIONS, true)) {
            return false;
        }

        global $wpdb;

        $table = $wpdb->prefix . 'dsgvo_audit_log';

        // SEC-AUDIT-02: Capture admin's IP address.
        $ip_address = $this->get_client_ip();

        // SEC-SQL-01: Prepared statement.
        $result = $wpdb->insert(
            $table,
            [
                'user_id'       => $user_id,
                'action'        => $action,
                'submission_id' => $submission_id,
                'form_id'       => $form_id,
                'ip_address'    => $ip_address,
                'details'       => $details,
                'created_at'    => current_time('mysql', true),
            ],
            [
                '%d',
                '%s',
                '%d',
                '%d',
                '%s',
                '%s',
                '%s',
            ]
        );

        return $result !== false;
    }

    /**
     * Retrieves audit log entries with optional filters.
     *
     * @param array $filters {
     *     Optional filters.
     *     @type int    $user_id       Filter by user ID.
     *     @type string $action        Filter by action type.
     *     @type int    $submission_id Filter by submission ID.
     *     @type int    $form_id       Filter by form ID.
     * }
     * @param int $limit  Maximum entries to return (default: 50).
     * @param int $offset Pagination offset (default: 0).
     * @return object[] Array of audit log entries.
     */
    public function get_logs(array $filters = [], int $limit = 50, int $offset = 0): array
    {
        global $wpdb;

        $table = $wpdb->prefix . 'dsgvo_audit_log';

        $where   = [];
        $values  = [];

        if (!empty($filters['user_id'])) {
            $where[]  = '`user_id` = %d';
            $values[] = (int) $filters['user_id'];
        }

        if (!empty($filters['action']) && in_array($filters['action'], self::ALLOWED_ACTIONS, true)) {
            $where[]  = '`action` = %s';
            $values[] = $filters['action'];
        }

        if (!empty($filters['submission_id'])) {
            $where[]  = '`submission_id` = %d';
            $values[] = (int) $filters['submission_id'];
        }

        if (!empty($filters['form_id'])) {
            $where[]  = '`form_id` = %d';
            $values[] = (int) $filters['form_id'];
        }

        $where_clause = '';
        if (!empty($where)) {
            $where_clause = 'WHERE ' . implode(' AND ', $where);
        }

        $limit  = max(1, min($limit, 200));
        $offset = max(0, $offset);

        // SEC-SQL-01: Prepared statement.
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Hardcoded table name; $where_clause built from validated %d/%s placeholders matching $values by construction.
        $sql = "SELECT * FROM `{$table}` {$where_clause} ORDER BY `created_at` DESC LIMIT %d OFFSET %d";

        $values[] = $limit;
        $values[] = $offset;

        $results = $wpdb->get_results(
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $sql passed to prepare(); dynamic WHERE built from validated placeholders matching $values by construction.
            $wpdb->prepare($sql, ...$values)
        );

        return is_array($results) ? $results : [];
    }

    /**
     * Counts audit log entries matching the given filters.
     *
     * @param array $filters Same filters as get_logs().
     * @return int Total count of matching entries.
     */
    public function count_logs(array $filters = []): int
    {
        global $wpdb;

        $table = $wpdb->prefix . 'dsgvo_audit_log';

        $where  = [];
        $values = [];

        if (!empty($filters['user_id'])) {
            $where[]  = '`user_id` = %d';
            $values[] = (int) $filters['user_id'];
        }

        if (!empty($filters['action']) && in_array($filters['action'], self::ALLOWED_ACTIONS, true)) {
            $where[]  = '`action` = %s';
            $values[] = $filters['action'];
        }

        if (!empty($filters['submission_id'])) {
            $where[]  = '`submission_id` = %d';
            $values[] = (int) $filters['submission_id'];
        }

        if (!empty($filters['form_id'])) {
            $where[]  = '`form_id` = %d';
            $values[] = (int) $filters['form_id'];
        }

        $where_clause = '';
        if (!empty($where)) {
            $where_clause = 'WHERE ' . implode(' AND ', $where);
        }

        // SEC-SQL-01: Always use prepared statement, even without filters.
        if (empty($values)) {
            $count = $wpdb->get_var(
                $wpdb->prepare("SELECT COUNT(*) FROM `{$table}` WHERE 1 = %d", 1)
            );
        } else {
            $count = $wpdb->get_var(
                // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $sql passed to prepare(); dynamic WHERE built from validated placeholders matching $values by construction.
                $wpdb->prepare(
                    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Hardcoded table name; $where_clause built from validated %d/%s placeholders matching $values by construction.
                    "SELECT COUNT(*) FROM `{$table}` {$where_clause}",
                    ...$values
                )
            );
        }

        return (int) $count;
    }

    /**
     * Anonymizes IP addresses older than 90 days (SEC-AUDIT-04).
     *
     * DSGVO: IP address is personal data. After 90 days, set to NULL.
     * The rest of the audit entry (user_id, action, timestamp) remains
     * until the 1-year retention period expires.
     *
     * Intended to be called by a WP-Cron job.
     *
     * @return int Number of rows updated.
     */
    public function cleanup_ip_addresses(): int
    {
        global $wpdb;

        $table = $wpdb->prefix . 'dsgvo_audit_log';

        // SEC-SQL-01: Prepared statement.
        $wpdb->query(
            $wpdb->prepare(
                "UPDATE `{$table}` SET `ip_address` = NULL WHERE `ip_address` IS NOT NULL AND `created_at` < DATE_SUB(%s, INTERVAL %d DAY)",
                current_time('mysql', true),
                self::IP_RETENTION_DAYS
            )
        );

        return (int) $wpdb->rows_affected;
    }

    /**
     * Deletes audit entries older than 1 year (SEC-AUDIT-03).
     *
     * Retention period: 1 year. After that, entries are automatically
     * purged. This is the ONLY deletion path — no admin UI method exists.
     *
     * Intended to be called by a WP-Cron job.
     *
     * @return int Number of rows deleted.
     */
    public function cleanup_old_entries(): int
    {
        global $wpdb;

        $table = $wpdb->prefix . 'dsgvo_audit_log';

        // SEC-SQL-01: Prepared statement.
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM `{$table}` WHERE `created_at` < DATE_SUB(%s, INTERVAL %d DAY)",
                current_time('mysql', true),
                self::ENTRY_RETENTION_DAYS
            )
        );

        return (int) $wpdb->rows_affected;
    }

    /**
     * Returns the client IP address, sanitized.
     *
     * Uses REMOTE_ADDR only — does NOT trust X-Forwarded-For
     * or similar headers (SEC-AUDIT-02: admin's actual IP).
     *
     * @return string|null The IP address, or null if unavailable.
     */
    private function get_client_ip(): ?string
    {
        if (empty($_SERVER['REMOTE_ADDR'])) {
            return null;
        }

        $ip = sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR']));

        // Validate as IP address (IPv4 or IPv6).
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return null;
        }

        return $ip;
    }
}
