<?php

// Forbid accessing directly
if (! defined('ABSPATH')) {
    header('HTTP/1.1 401 Unauthorized');
    exit;
}
// Ensure there are no conflicts
if (! class_exists('toolboxHttpDownloadWindow')) {
    /**
     * The update-package download window: the narrow, fail-closed exception that lets a clone
     * actually INSTALL an update it can see.
     *
     * The curated rules let a clone reach a vendor's update CHECK, so the update badge appears. The
     * package itself is a different host or a licence-key-bearing URL that no read-only rule covers,
     * so until now a clone would advertise an update it could not install - and the whole reason
     * these sites are cloned down is to apply and test updates before they go live.
     *
     * This is deliberately NOT "open the firewall during updates". Nothing is opened. WordPress's own
     * upgrader resolves a package URL and is about to fetch it; this permits THAT fetch and nothing
     * else. Every one of the following must hold, or the request is blocked exactly as before:
     *
     *   1. A window is open - WP_Upgrader::download_package() has just fired upgrader_pre_download.
     *   2. The request is a stream-to-file download (`stream` + `filename`), which is what
     *      download_url() issues and what an API or licence call never is.
     *   3. The URL is the armed package URL, or a redirect hop descending from it - which means
     *      streaming into the SAME tmpfile download_url() created and carrying a redirect budget
     *      WordPress has counted down. Being inside an upgrade is not on its own enough.
     *
     * So a licence POST made while an upgrade happens to be running is still blocked: it fails (2).
     * A request to the package URL outside an upgrade is still blocked: it fails (1).
     *
     * Redirect hops need explicit handling because WordPress sets CURLOPT_FOLLOWLOCATION to false and
     * re-enters wp_remote_request() per hop (WP_Http::handle_redirects), so pre_http_request fires
     * again with a DIFFERENT url - premium packages routinely redirect to a CDN. Hops are capped at
     * WordPress's own default redirect limit, and only after the armed URL itself has been seen.
     *
     * Trust boundary, stated plainly: the package URL is whatever the vendor's update check returned,
     * and for premium plugins it usually carries the licence key. Allowing the fetch means that key
     * leaves the clone. That is the same trust WordPress itself places in the response, it is bounded
     * to a zip fetch that writes to a temp file, and it is the price of a clone being able to install
     * an update at all - but it IS a change, and those URLs remain blocked outside this window.
     */
    class toolboxHttpDownloadWindow
    {
        // WordPress's own default 'redirection' limit, which is what a package fetch is allowed.
        public const MAX_REDIRECT_HOPS = 5;

        /**
         * A fresh window for a package URL, or null when there is nothing usable to arm. Only http(s)
         * is accepted - a package URL of another scheme is not something to hand a free pass.
         */
        public static function open($package): ?array
        {
            if (! is_scalar($package)) {
                return null;
            }
            $package = trim((string) $package);
            if ($package === '' || ! preg_match('!^https?://!i', $package)) {
                return null;
            }

            return array(
                'url' => $package,
                'started' => false,
                'hops' => 0,
                // Captured from the armed request when it is first seen, and required to match on
                // every hop after it - see evaluate().
                'filename' => null,
                'redirection' => null,
            );
        }

        /**
         * Whether these request args are a stream-to-file download. download_url() always sets both,
         * and passes the same args down every redirect hop; an API or licence call sets neither.
         *
         * @param mixed $args
         */
        public static function isStreamedDownload($args): bool
        {
            return is_array($args) && ! empty($args['stream']) && ! empty($args['filename']);
        }

        /**
         * Pure decision for one request against the window. Returns the verdict AND the window to
         * carry forward, so the caller holds the state and this stays testable without a request.
         *
         * @param mixed $window The current window (array) or null when none is open.
         * @param mixed $args The parsed request args.
         * @return array{allow:bool,window:mixed}
         */
        public static function evaluate($window, string $url, $args): array
        {
            if (! is_array($window) || ! isset($window['url'])) {
                return array('allow' => false, 'window' => $window);
            }
            // A licence call that happens to fire mid-upgrade is not a download and never rides in.
            if (! self::isStreamedDownload($args)) {
                return array('allow' => false, 'window' => $window);
            }

            $url = trim($url);
            if ($url !== '' && $url === trim((string) $window['url'])) {
                // The armed fetch itself. Remember the tmpfile it streams into and the redirect
                // budget it started with; every hop after this has to match both.
                $window['started'] = true;
                $window['filename'] = (string) $args['filename'];
                $window['redirection'] = self::redirectBudget($args);

                return array('allow' => true, 'window' => $window);
            }

            // Anything other than the armed URL is only ever a redirect hop, so it cannot come first.
            if (empty($window['started']) || intval($window['hops']) >= self::MAX_REDIRECT_HOPS) {
                return array('allow' => false, 'window' => $window);
            }

            // Being inside an upgrade is NOT on its own a licence to stream from anywhere. Without
            // the two checks below the hop allowance would admit any stream-to-file request made
            // while an upgrade happens to be running - which is a way to carry secrets out in a
            // query string. WP_Http::handle_redirects() re-enters wp_remote_request() with the SAME
            // $args and $args['redirection'] decremented by one, so a genuine hop keeps streaming
            // into the tmpfile download_url() created (a random wp_tempnam, not guessable) and
            // counts down. An unrelated download has its own tmpfile and its own full budget.
            if ((string) $args['filename'] !== (string) $window['filename']) {
                return array('allow' => false, 'window' => $window);
            }
            $budget = self::redirectBudget($args);
            if ($budget === null || $budget >= intval($window['redirection'])) {
                return array('allow' => false, 'window' => $window);
            }

            $window['redirection'] = $budget;
            $window['hops'] = intval($window['hops']) + 1;

            return array('allow' => true, 'window' => $window);
        }

        /**
         * The request's remaining redirect budget, or null when it carries none to read. WordPress
         * decrements this by exactly one per hop (WP_Http::handle_redirects), so it counts down
         * across a real redirect chain and stays at the default for an unrelated request.
         *
         * @param mixed $args
         */
        private static function redirectBudget($args): ?int
        {
            if (! is_array($args) || ! isset($args['redirection']) || ! is_numeric($args['redirection'])) {
                return null;
            }

            return intval($args['redirection']);
        }
    }
}
