<?php

declare(strict_types=1);

namespace ApexToolbox\Packages;

/**
 * Caching, backoff and the fail-soft policy around the registry manifest.
 *
 * Three rules drive everything here:
 *
 * 1. The update filter fires ONCE PER INSTALLED PLUGIN, so a fetch per call would mean a burst of
 *    requests on every update check. The manifest is fetched at most once per request and then
 *    memoised, and at most once per freshness window across requests.
 * 2. An error must never look like a revocation. Only a SUCCESSFUL manifest that omits a package
 *    revokes it; a 401, a firewall block or a timeout leaves the last known good answer in place
 *    for a week. Degrading to "entitled to nothing" would strip updates from a whole fleet the
 *    moment the registry had a bad afternoon.
 * 3. An unreachable registry must not be re-tried on every admin page load. Failures back off, and
 *    a rejected token backs off hardest, because a rejected token will not un-reject itself.
 *
 * The cached manifest is tied to a fingerprint of the token that produced it, so a token rotated
 * onto the site invalidates the cache by itself with nothing to remember to clear.
 */
final class ManifestCache
{
    /** Last good manifest. autoload=false: read on demand, never on a front-end request. */
    public const CACHE_OPTION = 'apex_toolbox_packages_cache';

    /** Outcome of the most recent attempt, for the admin screen. autoload=false. */
    public const STATUS_OPTION = 'apex_toolbox_packages_status';

    public const CHECKED_TRANSIENT = 'apex_toolbox_packages_checked';

    public const BACKOFF_TRANSIENT = 'apex_toolbox_packages_backoff';

    /**
     * Floor between fetches. NOT a cache lifetime.
     *
     * WordPress decides when an update check is due - twelve hours normally, one
     * on the Plugins screen, one minute on the Updates screen, two on cron, and
     * whenever WP-CLI asks. The update filter only ever runs from inside that
     * check, so the filter firing IS core saying "I want current data". A second
     * long window here would put two independent throttles in series and let a
     * fresh core check be answered with a stale manifest - which is how an update
     * published this morning can still be invisible tonight.
     *
     * So this is only a floor, short enough to be irrelevant to core's cadence
     * and long enough that a bulk plugin update (which re-runs the check after
     * every item) costs one request rather than ten.
     */
    public const FRESH_TTL = 5 * MINUTE_IN_SECONDS;

    /** How long a stale manifest keeps being served while fetches fail. */
    public const STALE_GRACE = 7 * DAY_IN_SECONDS;

    /**
     * Per-request memo, so the update filter's one-call-per-plugin pattern costs one fetch.
     *
     * @var array<string, array<string, mixed>>|null
     */
    private ?array $memo = null;

    /**
     * Whether to attempt a fetch. Pure, so the whole policy is testable as a truth table.
     *
     * A token not yet tried forces a fetch through both throttles: nothing on record is
     * evidence about this credential. It must be "not tried" rather than "not the one the
     * cache came from" - a failed fetch leaves the cache alone, so the latter would never
     * become false and the backoff would never apply.
     */
    public static function shouldFetch(
        bool $force,
        bool $checkedFresh,
        bool $inBackoff,
        bool $tokenUntried,
    ): bool {
        if ($force || $tokenUntried) {
            return true;
        }

        return ! $checkedFresh && ! $inBackoff;
    }

    /**
     * How long to wait after a failed attempt. A rejected token jumps straight to the cap: nothing
     * the site can do will change the answer, and hammering a 401 is the worst thing to retry hard.
     */
    public static function backoffSeconds(int $failures, string $state): int
    {
        if ($state === ManifestClient::STATE_REJECTED) {
            return 6 * HOUR_IN_SECONDS;
        }

        $steps = self::backoffSteps();
        $index = max(0, min($failures - 1, count($steps) - 1));

        return $steps[$index];
    }

    /**
     * slug => version, for deciding whether core's cached answer is now wrong.
     *
     * Only the offered versions matter: a changed description or changelog does
     * not affect what core would record, so it is not worth making core redo a
     * full update check over.
     *
     * @param array<string, array<string, mixed>> $packages
     * @return array<string, string>
     */
    public static function offeredVersions(array $packages): array
    {
        $versions = array();
        foreach ($packages as $slug => $record) {
            $versions[(string) $slug] = (string) ($record['version'] ?? '');
        }
        ksort($versions);

        return $versions;
    }

    /** Whether a manifest fetched at $fetchedAt may still be served. */
    public static function isStaleUsable(int $fetchedAt, int $now): bool
    {
        return $fetchedAt > 0 && ($now - $fetchedAt) <= self::STALE_GRACE;
    }

    /**
     * Backoff ladder in seconds: 15m, 30m, 1h, 3h, 6h.
     *
     * @return list<int>
     */
    private static function backoffSteps(): array
    {
        return array(
            15 * MINUTE_IN_SECONDS,
            30 * MINUTE_IN_SECONDS,
            HOUR_IN_SECONDS,
            3 * HOUR_IN_SECONDS,
            6 * HOUR_IN_SECONDS,
        );
    }

    public function __construct(
        private readonly PackagesConfig $config,
        private readonly ManifestClient $client,
    ) {
    }

    /**
     * The packages this site is entitled to, keyed by slug.
     *
     * @return array<string, array<string, mixed>>
     */
    public function packages(bool $force = false): array
    {
        if ($this->memo !== null && ! $force) {
            return $this->memo;
        }

        if (! $this->config->isEnabled()) {
            $this->memo = array();

            return $this->memo;
        }

        $token = $this->config->token();
        $fingerprint = PackagesConfig::tokenFingerprint($token);
        $cached = $this->readCache();

        // "Not tried yet" rather than "not the credential the cache came from".
        // A failed fetch never updates the cache, so keying this off the CACHED
        // fingerprint would leave a rotated token looking new for ever, bypassing
        // the backoff on every read - a retry storm at exactly the moment the
        // registry is rejecting us. What may serve the cached packages is a
        // separate question, answered from the cached fingerprint below.
        $attempted = (string) ($this->readStatus()['attempted_fp'] ?? '');
        $tokenUntried = $attempted !== $fingerprint;

        $shouldFetch = self::shouldFetch(
            $force,
            (bool) get_site_transient(self::CHECKED_TRANSIENT),
            (bool) get_site_transient(self::BACKOFF_TRANSIENT),
            $tokenUntried,
        );

        if ($shouldFetch) {
            $cached = $this->fetchAndStore($token, $fingerprint, $cached);
        }

        // Whatever happened above, packages belonging to a different credential
        // are not this site's answer.
        if ((string) ($cached['token_fp'] ?? '') !== $fingerprint) {
            $cached = array();
        }

        $packages = is_array($cached['packages'] ?? null)
            ? $cached['packages']
            : array();
        if ($packages !== array() && ! self::isStaleUsable((int) ($cached['fetched_at'] ?? 0), time())) {
            // Past the grace period the cache stops being an answer. Nothing is deactivated; the
            // site simply stops offering updates until it can reach the registry again.
            $packages = array();
        }

        $this->memo = $packages;

        return $this->memo;
    }

    /**
     * The most recent attempt, for the admin screen.
     *
     * @return array<string, mixed>
     */
    public function status(): array
    {
        $status = $this->readStatus();
        $cached = $this->readCache();

        return array(
            'state' => (string) ($status['state'] ?? 'never'),
            'message' => (string) ($status['message'] ?? ''),
            'checked_at' => (int) ($status['checked_at'] ?? 0),
            'failures' => (int) ($status['failures'] ?? 0),
            'fetched_at' => (int) ($cached['fetched_at'] ?? 0),
            'has_token' => $this->config->token() !== '',
            'token_hint' => PackagesConfig::maskToken($this->config->token()),
            'token_from_constant' => $this->config->tokenIsFromConstant(),
        );
    }

    /** Clear both throttles so the next read fetches. Backs the "Refresh now" button. */
    public function flush(): void
    {
        delete_site_transient(self::CHECKED_TRANSIENT);
        delete_site_transient(self::BACKOFF_TRANSIENT);
        $this->memo = null;
    }

    /**
     * @param array<string, mixed> $cached
     * @return array<string, mixed> The cache to serve from after this attempt.
     */
    private function fetchAndStore(string $token, string $fingerprint, array $cached): array
    {
        $result = $this->client->fetch($token);
        $now = time();

        if ($result['ok']) {
            $changed = self::offeredVersions($result['packages']) !== self::offeredVersions(
                is_array($cached['packages'] ?? null) ? $cached['packages'] : array(),
            );

            $cached = array(
                'packages' => $result['packages'],
                'fetched_at' => $now,
                'token_fp' => $fingerprint,
            );
            update_option(self::CACHE_OPTION, $cached, false);

            // Core caches its own answer for twelve hours. If what the registry
            // offers has changed, that answer is now wrong and nothing else would
            // ever correct it - so drop it and let core rebuild. This is what puts
            // a new version on the Updates screen, the Plugins screen and in
            // `wp plugin list` rather than only on our own page.
            if ($changed) {
                delete_site_transient('update_plugins');
            }
            update_option(
                self::STATUS_OPTION,
                array(
                    'state' => ManifestClient::STATE_OK,
                    'message' => '',
                    'checked_at' => $now,
                    'failures' => 0,
                    'attempted_fp' => $fingerprint,
                ),
                false,
            );
            set_site_transient(self::CHECKED_TRANSIENT, 1, self::FRESH_TTL);
            delete_site_transient(self::BACKOFF_TRANSIENT);

            return $cached;
        }

        // Failure: the cache is deliberately left exactly as it was. The attempt
        // is recorded even so, so a rotated token counts as tried and backs off
        // like anything else rather than retrying on every read.
        $previous = $this->readStatus();
        $failures = (int) ($previous['failures'] ?? 0) + 1;
        update_option(
            self::STATUS_OPTION,
            array(
                'state' => $result['state'],
                'message' => $result['message'],
                'checked_at' => $now,
                'failures' => $failures,
                'attempted_fp' => $fingerprint,
            ),
            false,
        );
        set_site_transient(self::BACKOFF_TRANSIENT, 1, self::backoffSeconds($failures, $result['state']));

        return $cached;
    }

    /** @return array<string, mixed> */
    private function readStatus(): array
    {
        $status = get_option(self::STATUS_OPTION, array());

        return is_array($status)
            ? $status
            : array();
    }

    /** @return array<string, mixed> */
    private function readCache(): array
    {
        $cached = get_option(self::CACHE_OPTION, array());

        return is_array($cached)
            ? $cached
            : array();
    }
}
