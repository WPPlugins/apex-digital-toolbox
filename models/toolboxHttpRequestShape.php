<?php

// Forbid accessing directly
if (! defined('ABSPATH')) {
    header('HTTP/1.1 401 Unauthorized');
    exit;
}
// Ensure there are no conflicts
if (! class_exists('toolboxHttpRequestShape')) {
    /**
     * Reading the shape of an outbound request: its path, the parameters it carries, and whether the
     * body could be read in full.
     *
     * Split out of toolboxHttpRuleModel, which reached the phpcs class-length cap. The seam is a real
     * one rather than a convenience: everything here answers "what IS this request", with no notion of
     * rules or verdicts, while the model decides whether a request satisfies a rule. The model is the
     * only production caller.
     *
     * Nothing here stores anything. Values are read to evaluate `require` and `absent` constraints and
     * are then discarded - the discovery log keeps parameter NAMES only, and that is enforced
     * separately in toolboxHookOutboundFirewall::redactRequestShape.
     */
    class toolboxHttpRequestShape
    {
        // Cap on the request body parsed for parameter matching - a base64 upload must not balloon
        // memory. An oversized body simply yields no parameters, so a rule requiring one fails closed.
        public const MAX_BODY_PARSE_BYTES = 65_536;

        /** The path component of a URL, defaulting to '/' when the URL carries none. */
        public static function extractPath(string $url): string
        {
            $path = wp_parse_url($url, PHP_URL_PATH);
            if (! is_string($path) || $path === '') {
                return '/';
            }

            return $path;
        }

        /**
         * Every parameter the request carries, as name => list of lowercased string values. Query
         * string and body are merged, since a vendor may send the same field either way. These values
         * exist only for the in-memory `require` comparison and are never persisted.
         *
         * @param mixed $body
         * @return array<string,list<string>>
         */
        public static function extractRequestParams(string $url, $body): array
        {
            $params = array();

            $query = wp_parse_url($url, PHP_URL_QUERY);
            if (is_string($query) && $query !== '') {
                $pairs = array();
                parse_str($query, $pairs);
                $params = self::mergeParams($params, $pairs);
            }

            if (is_array($body)) {
                return self::mergeParams($params, $body);
            }
            if (! is_string($body) || $body === '' || strlen($body) > self::MAX_BODY_PARSE_BYTES) {
                return $params;
            }

            $trimmed = ltrim($body);
            if ($trimmed !== '' && ( $trimmed[0] === '{' || $trimmed[0] === '[' )) {
                $decoded = json_decode($body, true);
            } else {
                $decoded = array();
                parse_str($body, $decoded);
            }

            return is_array($decoded)
                ? self::mergeParams($params, $decoded)
                : $params;
        }

        /**
         * Whether the request satisfies a rule's `require` map. Each named parameter must be present
         * and EVERY value it carries must be permitted - one unexpected value fails the whole rule, so
         * a request smuggling a second `action` cannot ride in on the back of the first.
         *
         * @param array<string,list<string>> $params
         * @param array<string,list<string>> $require
         */
        public static function paramsSatisfyRequirements(array $params, array $require): bool
        {
            foreach ($require as $key => $permitted) {
                if (empty($params[ $key ])) {
                    return false;
                }
                foreach ($params[ $key ] as $value) {
                    if (! in_array($value, $permitted, true)) {
                        return false;
                    }
                }
            }

            return true;
        }

        /**
         * Whether every named parameter is ABSENT from the request. The counterpart to
         * paramsSatisfyRequirements: `require` says "this parameter must be present and hold one of
         * these values", `absent` says "this parameter must not appear at all".
         *
         * It exists for vendors whose only safe endpoint is the one that carries no parameters. The
         * stock EDD Software Licensing updater probes the bare store host with a parameter-less GET
         * and refuses to check for updates if that probe fails - but on those hosts the licence
         * activate and deactivate calls are the SAME host, path and method, separated only by an
         * edd_action parameter. "edd_action is absent" is the only thing that distinguishes them.
         *
         * This is not a denylist and does not weaken the fail-closed property: a request carrying
         * the parameter never satisfies the assertion, whatever value it holds, so an action the
         * vendor adds next year is blocked by this rule exactly as today's are.
         *
         * @param array<string,list<string>> $params
         */
        public static function paramsSatisfyAbsences(array $params, array $absent): bool
        {
            foreach ($absent as $key) {
                if (! empty($params[ $key ])) {
                    return false;
                }
            }

            return true;
        }

        /**
         * Whether every named parameter is present and EVERY value it carries is this site's own URL.
         * The third constraint kind, for the one write that is safe only because it names the
         * calling site: a licence deactivation whose body says `url = home_url()` can release this
         * clone's registration and no other. `require` cannot express that (the permitted value is
         * not a constant), so this compares against the URLs the hook knows to be its own. Fails
         * closed on every edge: no own URLs known, parameter missing, a non-scalar value, or any one
         * value naming a different site - a repeated parameter cannot smuggle production's URL in
         * beside the clone's.
         *
         * @param array<string,list<string>> $params
         * @param list<string> $keys
         * @param list<string> $ownSiteUrls Already lowercased, no trailing slash.
         */
        public static function paramsNameOwnSite(array $params, array $keys, array $ownSiteUrls): bool
        {
            if (empty($ownSiteUrls)) {
                return false;
            }
            foreach ($keys as $key) {
                if (empty($params[ $key ])) {
                    return false;
                }
                foreach ($params[ $key ] as $value) {
                    if (! in_array(rtrim($value, '/'), $ownSiteUrls, true)) {
                        return false;
                    }
                }
            }

            return true;
        }

        /**
         * Whether the request body was read in full, so an absence claim about it can be trusted.
         *
         * extractRequestParams() is deliberately lossy: an oversized body is skipped and a malformed
         * JSON body decodes to nothing, in both cases yielding fewer parameters than the request
         * actually carries. For `require` that is safe - a parameter it cannot see is a requirement
         * it cannot satisfy. For `absent` the same loss inverts into a false "not present", so a
         * caller could hide a parameter behind an unparseable body. Rules asserting absence consult
         * this first and decline to match when the answer is no.
         *
         * @param mixed $body
         */
        public static function bodyWasFullyRead($body): bool
        {
            if ($body === null || $body === '' || is_array($body)) {
                return true;
            }
            if (! is_string($body)) {
                // An object or resource body: we have no reading of it at all.
                return false;
            }
            if (strlen($body) > self::MAX_BODY_PARSE_BYTES) {
                return false;
            }
            $trimmed = ltrim($body);
            if ($trimmed !== '' && ( $trimmed[0] === '{' || $trimmed[0] === '[' )) {
                return is_array(json_decode($body, true));
            }

            // Form-encoded: parse_str consumes any remaining string without failing.
            return true;
        }

        /**
         * Fold one parameter bag into the accumulator as name => list of lowercased string values. A
         * non-scalar value becomes a sentinel that can never equal a required value, so a rule
         * requiring that key fails closed rather than skipping the check.
         *
         * @param array<string,list<string>> $params
         * @param array $pairs
         * @return array<string,list<string>>
         */
        private static function mergeParams(array $params, array $pairs): array
        {
            foreach ($pairs as $key => $value) {
                $key = (string) $key;
                if (! isset($params[ $key ])) {
                    $params[ $key ] = array();
                }
                foreach (( is_array($value) ? $value : array($value) ) as $item) {
                    $params[ $key ][] = is_scalar($item)
                        ? strtolower(trim((string) $item))
                        : '(non-scalar)';
                }
            }

            return $params;
        }
    }
}
