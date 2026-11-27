<?php

declare(strict_types=1);

namespace ApexToolbox\Mail;

/**
 * `wp apex mail retry-failed` resends FluentSMTP emails that failed inside a look-back window.
 *
 * Written for an SMTP2GO outage: run it on a schedule until the provider accepts the mail again.
 * Rows with nobody to send to, and rows whose stored response is a permanent rejection, are
 * reported and left alone. The resend goes through FluentSMTP's own Logger::resendEmailFromLog()
 * in `check_realtime` mode, which keeps a failed retry marked failed and bumps retries and
 * updated_at, so --max-retries and --min-age hold across runs.
 *
 * This is not one of the commands the updater mu-plugin wakes. WakeUpdaters::commandWakes()
 * lists `apex updates` and `apex changelog` only, and every `wp apex` command skips the
 * mu-plugin rewrite.
 */
class RetryFailedCommand
{
    /**
     * Retries FluentSMTP emails that failed inside the look-back window.
     *
     * ## OPTIONS
     *
     * [--hours=<hours>]
     * : How far back to look, from when the failure was logged.
     * ---
     * default: 24
     * ---
     *
     * [--max-retries=<count>]
     * : Skip a row FluentSMTP has already retried this many times.
     * ---
     * default: 5
     * ---
     *
     * [--min-age=<minutes>]
     * : Skip a row whose last attempt is newer than this. Leaves a gap between runs.
     * ---
     * default: 30
     * ---
     *
     * [--limit=<count>]
     * : Maximum emails to retry, oldest first. A skipped row is reported and does not use up this number.
     * ---
     * default: 50
     * ---
     *
     * [--dry-run]
     * : List the rows that would be retried, and the rows that would be skipped, and send nothing.
     *
     * [--format=<format>]
     * : `table` for a person, `json` for a script.
     * ---
     * default: table
     * options:
     *   - table
     *   - json
     * ---
     *
     * ## EXAMPLES
     *
     *     wp apex mail retry-failed --dry-run
     *     wp apex mail retry-failed --hours=6 --min-age=15
     *     wp apex mail retry-failed --format=json
     *
     * @subcommand retry-failed
     * @param list<string> $args
     * @param array<string, mixed> $assocArgs
     */
    public function retryFailed(array $args, array $assocArgs): void
    {
        $options = FailedMail::options($assocArgs);

        if (isset($options['error'])) {
            \WP_CLI::error((string) $options['error']);
        }

        global $wpdb;

        $problem = $this->problem($wpdb);

        if ($problem !== null) {
            \WP_CLI::error(FailedMail::availabilityMessage($problem, FailedMail::logTable($wpdb)));
        }

        $rows = FailedMail::gather($wpdb, FailedMail::logTable($wpdb), $options, current_time('mysql'));
        $report = FailedMail::review($rows, (bool) $options['dry_run'], $options['dry_run'] ? null : $this->resend());

        $this->render($report, (string) $options['format']);
    }

    /** @return callable(int, string): mixed */
    private function resend(): callable
    {
        return static function (int $id, string $mode): mixed {
            $logger = new \FluentMail\App\Models\Logger();

            return $logger->resendEmailFromLog($id, $mode);
        };
    }

    private function problem(\wpdb $wpdb): ?string
    {
        $class = \FluentMail\App\Models\Logger::class;
        $exists = class_exists($class);
        $method = $exists && method_exists($class, 'resendEmailFromLog');

        return FailedMail::availabilityProblem(
            $exists,
            $method,
            $method && FailedMail::tableExists($wpdb, FailedMail::logTable($wpdb)),
        );
    }

    /** @param array<string, mixed> $report */
    private function render(array $report, string $format): void
    {
        if ($format === 'json') {
            \WP_CLI::line((string) wp_json_encode($report));

            return;
        }

        $rows = $report['rows'];

        if ($rows === array()) {
            \WP_CLI::log('No failed emails matched.');
        } else {
            \WP_CLI\Utils\format_items(
                'table',
                $rows,
                array('id', 'created_at', 'retries', 'subject', 'recipients', 'decision', 'reason'),
            );
        }

        $summary = sprintf(
            '%d attempted, %d sent, %d failed, %d skipped%s',
            $report['attempted'],
            $report['sent'],
            $report['failed'],
            $report['skipped'],
            $report['dry_run'] ? sprintf(' (%d would be retried)', $report['would_retry']) : '',
        );

        // A row that fails again is a result, not a broken command. A cron retrying through an
        // outage should keep running. The summary is a warning so the failure is still obvious.
        if ($report['failed'] > 0) {
            \WP_CLI::warning($summary);

            return;
        }

        \WP_CLI::success($summary);
    }
}
