<?php

declare(strict_types=1);

namespace ApexToolbox\Mail;

/**
 * Decides which FluentSMTP failed-log rows `wp apex mail retry-failed` should resend.
 *
 * The selection and the classification are pure so the suite can exercise them without
 * FluentSMTP installed. The command supplies the rows and a resend callable.
 *
 * `check_realtime` is FluentSMTP's own resend mode (Logger::resendEmailFromLog, verified on
 * 2.3.1 and 2.4.1). When wp_mail() returns false and the row was already failed, it writes
 * status back to failed, increments retries and sets updated_at. The cap and the spacing
 * below therefore work off FluentSMTP's own write. A throw before that write leaves the row
 * unchanged, so the next run picks it up again.
 */
final class FailedMail
{
    public const DECISION_RETRY = 'retry';

    public const DECISION_SKIP_NO_RECIPIENT = 'skip_no_recipient';

    public const DECISION_SKIP_PERMANENT = 'skip_permanent';

    /** Second argument to Logger::resendEmailFromLog(). Keeps a failed retry marked failed. */
    public const RESEND_MODE = 'check_realtime';

    public const DEFAULT_HOURS = 24;

    public const DEFAULT_MAX_RETRIES = 5;

    public const DEFAULT_MIN_AGE_MINUTES = 30;

    public const DEFAULT_LIMIT = 50;

    /**
     * How many matching log rows one run will read. Skipped rows do not count towards --limit,
     * so a block of them cannot hide a later email that can still be sent. The cap keeps one
     * run from walking an unbounded log.
     */
    public const MAX_ROWS_SCANNED = 2_000;

    public const LOG_TABLE_SUFFIX = 'fsmpt_email_logs';

    /**
     * FluentSMTP's log table. FLUENT_MAIL_DB_PREFIX is `fsmpt_` and the table is `email_logs`
     * (2.3.1 and 2.4.1). The constant is not used here so the check still runs when the plugin
     * is inactive and has not defined it.
     */
    public static function logTable(\wpdb $wpdb): string
    {
        return $wpdb->prefix . self::LOG_TABLE_SUFFIX;
    }

    /**
     * Why a retry cannot run, or null when FluentSMTP can resend.
     *
     * Checked in this order so a site without the plugin hears that, rather than a missing-table
     * error about a plugin it does not have.
     */
    public static function availabilityProblem(bool $loggerClass, bool $resendMethod, bool $tableExists): ?string
    {
        if (! $loggerClass) {
            return 'inactive';
        }

        if (! $resendMethod) {
            return 'no_resend_method';
        }

        if (! $tableExists) {
            return 'no_table';
        }

        return null;
    }

    public static function availabilityMessage(string $problem, string $table): string
    {
        switch ($problem) {
            case 'inactive':
                return 'FluentSMTP is not active, so there are no failed emails to retry.';
            case 'no_resend_method':
                return 'FluentSMTP is active but Logger::resendEmailFromLog() is missing. '
                    . 'This command needs FluentSMTP 2.3 or newer.';
            case 'no_table':
                return 'FluentSMTP is active but its email log table (' . $table . ') is missing, '
                    . 'so there is nothing to retry.';
            default:
                return 'FluentSMTP retry is unavailable.';
        }
    }

    public static function tableExists(\wpdb $wpdb, string $table): bool
    {
        self::assertTableName($table);

        // `_` is a LIKE wildcard. The test prefix and `wp_` are full of them, so an unescaped
        // name matches unrelated tables and the comparison below can go the wrong way.
        // phpcs:disable WordPress.DB.DirectDatabaseQuery
        $found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table)));
        // phpcs:enable

        return $found === $table;
    }

    /**
     * Normalise the WP-CLI assoc args. On invalid input the returned array is `array('error' => ...)`.
     *
     * @param array<string, mixed> $assocArgs
     * @return array<string, mixed>
     */
    public static function options(array $assocArgs): array
    {
        $hours = self::boundedInt($assocArgs['hours'] ?? null, self::DEFAULT_HOURS, 1, 8_760, 'hours');
        $maxRetries = self::boundedInt(
            $assocArgs['max-retries'] ?? null,
            self::DEFAULT_MAX_RETRIES,
            0,
            100,
            'max-retries',
        );
        $minAge = self::boundedInt($assocArgs['min-age'] ?? null, self::DEFAULT_MIN_AGE_MINUTES, 0, 10_080, 'min-age');
        $limit = self::boundedInt($assocArgs['limit'] ?? null, self::DEFAULT_LIMIT, 1, 500, 'limit');

        foreach (array($hours, $maxRetries, $minAge, $limit) as $parsed) {
            if (is_string($parsed)) {
                return array('error' => $parsed);
            }
        }

        $format = (string) ($assocArgs['format'] ?? 'table');

        if (! in_array($format, array('table', 'json'), true)) {
            return array('error' => 'Unknown format "' . $format . '". Use table or json.');
        }

        return array(
            'hours' => $hours,
            'max_retries' => $maxRetries,
            'min_age' => $minAge,
            'limit' => $limit,
            'dry_run' => self::flag($assocArgs, 'dry-run'),
            'format' => $format,
        );
    }

    /**
     * Failed rows inside the window, under the retry cap, whose last attempt is old enough.
     *
     * `created_at` is the look-back (when the failure happened). `COALESCE(updated_at, created_at)`
     * is the last attempt, so a retry FluentSMTP just wrote is not picked again until min-age
     * has passed. $now must be current_time('mysql'): FluentSMTP stamps the row with that, and
     * the two clocks have to be the same one.
     *
     * @param array<string, mixed> $options From options().
     * @return list<array<string, mixed>>
     */
    public static function select(\wpdb $wpdb, string $table, array $options, string $now): array
    {
        self::assertTableName($table);

        $afterId = (int) ($options['after_id'] ?? 0);
        $afterSql = '';
        $args = array();

        if ($afterId > 0) {
            $afterSql = 'AND id > %d';
            $args[] = $afterId;
        }

        $args[] = (int) $options['max_retries'];
        $args[] = $now;
        $args[] = (int) $options['min_age'];
        $args[] = $now;
        $args[] = (int) $options['hours'];
        $args[] = (int) $options['limit'];

        $sql = "SELECT id, `to`, headers, subject, response, retries, created_at, updated_at
            FROM `{$table}`
            WHERE status = 'failed'
              {$afterSql}
              AND COALESCE(retries, 0) < %d
              AND COALESCE(updated_at, created_at) <= DATE_SUB(%s, INTERVAL %d MINUTE)
              AND created_at >= DATE_SUB(%s, INTERVAL %d HOUR)
            ORDER BY id ASC
            LIMIT %d";

        // The table name is an identifier, which prepare() cannot bind. assertTableName() has
        // already restricted it to letters, digits and underscores. $prepared is that query.
        // phpcs:disable WordPress.DB.PreparedSQL, WordPress.DB.DirectDatabaseQuery
        $prepared = $wpdb->prepare($sql, $args);
        $rows = $wpdb->get_results($prepared, ARRAY_A);
        // phpcs:enable

        return is_array($rows)
            ? $rows
            : array();
    }

    /**
     * Rows to report for one run: every skip we pass, then retryable rows up to the limit.
     *
     * `select()` is one page. A page filled with no-recipient or permanent failures would
     * otherwise be the whole run, and the next scheduled run would read that same page again.
     * This keeps reading, oldest id first, until it has `$options['limit']` emails that classify
     * as retry, or it has read MAX_ROWS_SCANNED, or the window runs out. The limit is how many
     * emails to retry, not how many skips to print.
     *
     * @param array<string, mixed> $options From options().
     * @return list<array<string, mixed>>
     */
    public static function gather(\wpdb $wpdb, string $table, array $options, string $now): array
    {
        $want = (int) $options['limit'];
        $collected = array();
        $retryable = 0;
        $scanned = 0;
        $afterId = 0;

        while ($retryable < $want && $scanned < self::MAX_ROWS_SCANNED) {
            $page = $options;
            $page['after_id'] = $afterId;
            $page['limit'] = min(100, self::MAX_ROWS_SCANNED - $scanned);
            $rows = self::select($wpdb, $table, $page, $now);

            if ($rows === array()) {
                break;
            }

            foreach ($rows as $row) {
                $id = (int) ($row['id'] ?? 0);

                if ($id <= $afterId) {
                    return $collected;
                }

                $afterId = $id;
                $scanned += 1;
                $collected[] = $row;

                if (self::classify($row)['decision'] === self::DECISION_RETRY) {
                    $retryable += 1;

                    if ($retryable >= $want) {
                        return $collected;
                    }
                }

                if ($scanned >= self::MAX_ROWS_SCANNED) {
                    return $collected;
                }
            }
        }

        return $collected;
    }

    /**
     * `retry`, `skip_no_recipient` or `skip_permanent`, plus a reason for a skip.
     *
     * No recipient wins over a permanent error. The empty-To rows that prompted this command
     * also carry SMTP2GO's 400 "json data" validation error, and the reason an operator needs
     * is that there was nobody to send it to.
     *
     * @param array<string, mixed> $row
     * @return array{decision: string, reason: string}
     */
    public static function classify(array $row): array
    {
        if (! MailRecipients::hasDeliverableRecipient($row)) {
            return array(
                'decision' => self::DECISION_SKIP_NO_RECIPIENT,
                'reason' => 'No valid recipient',
            );
        }

        if (self::isPermanentFailure($row['response'] ?? null)) {
            return array(
                'decision' => self::DECISION_SKIP_PERMANENT,
                'reason' => 'Permanent error: ' . self::failureDetail($row['response'] ?? null),
            );
        }

        return array(
            'decision' => self::DECISION_RETRY,
            'reason' => '',
        );
    }

    /**
     * A stored failure that will not succeed by trying again.
     *
     * FluentSMTP stores the provider error as a serialized array with `code` and `message`.
     * A non-numeric WP_Error code (a connection failure, for instance) is rewritten to 400
     * before it is stored, so the numeric code is never enough on its own. 400, 401, 403 and
     * 422 are permanent only when the message says so. An authentication failure is retried:
     * an expired SMTP2GO key gets replaced, and those emails should then go. The retry cap
     * still bounds them. A transient phrase wins over a permanent one. HTTP 429 and 5xx count
     * only when they are written as a status code, not as a bare number in the text.
     */
    public static function isPermanentFailure(mixed $response): bool
    {
        $text = self::flatten(MailRecipients::unwrap($response));

        if (trim($text) === '') {
            return false;
        }

        if (self::matches($text, self::transientPatterns())) {
            return false;
        }

        return self::matches($text, self::permanentPatterns());
    }

    /**
     * Classify every row and, unless this is a dry run, resend the ones that classify as retry.
     *
     * The callable is `function (int $id, string $mode): mixed`. Success is a returned row whose
     * status is `sent`, which is what resendEmailFromLog() gives back after a real send. Anything
     * else, including a throw, is a failed attempt. Dry runs never call it.
     *
     * @param list<array<string, mixed>> $rows
     * @return array<string, mixed>
     */
    public static function review(array $rows, bool $dryRun, ?callable $resend): array
    {
        $lines = array();
        $attempted = 0;
        $sent = 0;
        $failed = 0;
        $skipped = 0;
        $wouldRetry = 0;

        foreach ($rows as $row) {
            $classified = self::classify($row);
            $line = self::rowLine($row, $classified['decision'], $classified['reason']);

            if ($classified['decision'] !== self::DECISION_RETRY) {
                $skipped += 1;
                $lines[] = $line;

                continue;
            }

            $wouldRetry += 1;

            if ($dryRun || $resend === null) {
                $line['decision'] = 'would_retry';
                $line['reason'] = 'Dry run';
                $lines[] = $line;

                continue;
            }

            $attempted += 1;
            $outcome = self::attempt((int) ($row['id'] ?? 0), $resend);
            $line['decision'] = $outcome['decision'];
            $line['reason'] = self::clip($outcome['reason'], 160);

            if ($outcome['decision'] === 'sent') {
                $sent += 1;
            } else {
                $failed += 1;
            }

            $lines[] = $line;
        }

        return array(
            'dry_run' => $dryRun,
            'attempted' => $attempted,
            'sent' => $sent,
            'failed' => $failed,
            'skipped' => $skipped,
            'would_retry' => $wouldRetry,
            'rows' => $lines,
        );
    }

    public static function assertTableName(string $table): void
    {
        if (! preg_match('/^[A-Za-z0-9_]+$/', $table)) {
            throw new \InvalidArgumentException('Unsafe table name.');
        }
    }

    /** @return list<string> */
    private static function permanentPatterns(): array
    {
        return array(
            '/invalid (?:recipient|address|e-?mail|mailbox|rcpt)/i',
            '/mailbox (?:unavailable|not found)/i',
            '/user unknown/i',
            '/no such user/i',
            '/does not exist/i',
            '/recipient (?:address )?rejected/i',
            '/address rejected/i',
            '/\b5\.1\.\d\b/',
            '/\b550\b/',
            '/suppress(?:ed|ion)/i',
            '/json data/i',
            '/unprocessable/i',
            // Authentication failures are not here. An expired API key is fixed and then the
            // same rows should send. "access denied" stays: that is a relay or recipient block,
            // not a key that gets rotated.
            '/access denied/i',
            '/relay (?:access )?denied/i',
            '/(?:recipient|address).{0,40}blocked/i',
            '/blocked (?:recipient|address)/i',
        );
    }

    /** @return list<string> */
    private static function transientPatterns(): array
    {
        return array(
            '/timed?\s*out/i',
            '/timeout/i',
            '/could not connect/i',
            '/could not resolve/i',
            '/failed to connect/i',
            '/connection (?:refused|reset|closed|aborted)/i',
            '/temporarily/i',
            '/try again/i',
            '/greylist/i',
            '/\b(?:421|450|451|452)\b/',
            // 429 and 5xx only when they are a status code. "invalid recipient, ref 500" must
            // stay permanent: a bare 500 in the text is not an HTTP status.
            '/\b(?:https?(?:\/\d+(?:\.\d+)?)?|status|code|error|response)\b\s*[:#-]?\s*(?:429|500|502|503|504)\b/i',
            '/rate limit/i',
            '/too many (?:requests|connections)/i',
            '/network (?:is )?unreachable/i',
            '/curl error/i',
            '/service unavailable/i',
            '/bad gateway/i',
            '/gateway timeout/i',
        );
    }

    /** @param list<string> $patterns */
    private static function matches(string $text, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text)) {
                return true;
            }
        }

        return false;
    }

    private static function failureDetail(mixed $response): string
    {
        $message = self::responseMessage($response);

        return $message !== ''
            ? $message
            : 'rejected by the mail provider';
    }

    /**
     * The provider's own words, from a stored response. Empty when the row has none.
     * FluentSMTP's resend returns the row from find(), which is a fresh read of this column.
     */
    private static function responseMessage(mixed $response): string
    {
        $value = MailRecipients::unwrap($response);
        $message = '';

        if (is_array($value) && isset($value['message']) && is_scalar($value['message'])) {
            $message = (string) $value['message'];
        } elseif (is_string($value)) {
            $message = $value;
        } else {
            $message = self::flatten($value);
        }

        return self::clip($message, 120);
    }

    private static function flatten(mixed $value): string
    {
        if (is_string($value) || is_int($value) || is_float($value)) {
            return (string) $value;
        }

        if (! is_array($value)) {
            return '';
        }

        $parts = array();

        foreach ($value as $item) {
            $flat = self::flatten($item);

            if ($flat !== '') {
                $parts[] = $flat;
            }
        }

        return implode(' ', $parts);
    }

    /** @return array{decision: string, reason: string} */
    private static function attempt(int $id, callable $resend): array
    {
        try {
            $result = $resend($id, self::RESEND_MODE);
        } catch (\Throwable $exception) {
            return array(
                'decision' => 'failed',
                'reason' => $exception->getMessage(),
            );
        }

        $status = '';
        $response = null;

        if (is_array($result)) {
            $status = (string) ($result['status'] ?? '');
            $response = $result['response'] ?? null;
        } elseif (is_object($result)) {
            if (isset($result->status)) {
                $status = (string) $result->status;
            }

            if (isset($result->response)) {
                $response = $result->response;
            }
        }

        if ($status === 'sent') {
            return array(
                'decision' => 'sent',
                'reason' => 'Sent',
            );
        }

        $message = self::responseMessage($response);

        return array(
            'decision' => 'failed',
            'reason' => $message !== '' ? $message : 'Still failed',
        );
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function rowLine(array $row, string $decision, string $reason): array
    {
        $subject = $row['subject'] ?? '';

        if (! is_string($subject)) {
            $subject = '';
        }

        $created = $row['created_at'] ?? '';

        return array(
            'id' => (int) ($row['id'] ?? 0),
            'created_at' => is_string($created) ? $created : '',
            'retries' => (int) ($row['retries'] ?? 0),
            'subject' => self::clip($subject, 60),
            'recipients' => count(MailRecipients::deliverableAddresses($row)),
            'decision' => $decision,
            'reason' => self::clip($reason, 160),
        );
    }

    private static function clip(string $value, int $length): string
    {
        $value = trim(str_replace(array("\r", "\n"), ' ', $value));

        if (function_exists('mb_strlen') && function_exists('mb_substr')) {
            if (mb_strlen($value) <= $length) {
                return $value;
            }

            return mb_substr($value, 0, $length) . '...';
        }

        if (strlen($value) <= $length) {
            return $value;
        }

        return substr($value, 0, $length) . '...';
    }

    /** @return int|string The integer, or an error message when $raw is present and out of range. */
    private static function boundedInt(mixed $raw, int $default, int $min, int $max, string $name): int|string
    {
        if ($raw === null) {
            return $default;
        }

        $error = $name . ' must be a whole number between ' . $min . ' and ' . $max . '.';

        if (is_bool($raw) || is_array($raw) || ! preg_match('/^\d+$/', (string) $raw)) {
            return $error;
        }

        $value = (int) $raw;

        if ($value < $min || $value > $max) {
            return $error;
        }

        return $value;
    }

    /** @param array<string, mixed> $args */
    private static function flag(array $args, string $name): bool
    {
        if (! array_key_exists($name, $args)) {
            return false;
        }

        $value = $args[$name];

        return $value !== false && $value !== 'false' && $value !== '0';
    }
}
