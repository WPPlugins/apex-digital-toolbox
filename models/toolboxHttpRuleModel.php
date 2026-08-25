<?php

// Forbid accessing directly
if (! defined('ABSPATH')) {
    header('HTTP/1.1 401 Unauthorized');
    exit;
}
// Ensure there are no conflicts
if (! class_exists('toolboxHttpRuleModel')) {
    /**
     * Matching engine for the outbound HTTP firewall.
     *
     * Two things live here:
     *
     *  1. The primitives every allowlist tier shares - HTTP verb normalisation and host-pattern
     *     matching. toolboxHookOutboundFirewall delegates to them, so there is one implementation.
     *
     *  2. The CURATED RULE tier matching: a rule pins a host to specific methods, path prefixes, and required
     *     parameter values. That extra precision is the whole point. A vendor licence server exposes,
     *     on the same host, a harmless update check AND a `deactivate` call that would release the
     *     PRODUCTION site's licence seat; several vendors multiplex both through one path, separated
     *     only by a parameter value. "Allow the host" cannot express the difference - a rule can.
     *
     * The shipped rules themselves are DATA and live in toolboxHttpRuleDefaults, not here, so that
     * adding a vendor never touches this matching logic.
     *
     * Invariants:
     *   - Deny by default WITHIN the host. Anything a rule does not match stays blocked and keeps
     *     appearing in the discovery log, so a new vendor endpoint surfaces instead of sneaking in.
     *   - `require` is an allowlist of values, never a denylist. Requiring action in {check} fails
     *     CLOSED when the vendor adds a new action next year; forbidding `deactivate` would fail open
     *     on exactly that case.
     *   - Parameter values are matched in memory and never stored. The discovery log keeps key names
     *     only (see toolboxHookOutboundFirewall::redactRequestShape) and this class must not change that.
     */
    class toolboxHttpRuleModel
    {
        // Sentinel in a method set meaning "every verb".
        public const ALL_METHODS = '*';

        // Cap on the request body parsed for `require` matching - a base64 upload must not balloon
        // memory. An oversized body simply yields no parameters, so a rule requiring one fails closed.
        public const MAX_BODY_PARSE_BYTES = 65_536;

        /**
         * The HTTP verbs the firewall recognises for per-method rules. A static function (not an array
         * class constant) per the phpcs note in CLAUDE.md.
         *
         * @return list<string>
         */
        public static function httpMethods(): array
        {
            return array('GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS');
        }

        /**
         * Normalise a request method to an uppercase known verb. Empty/unknown -> 'GET' (the WordPress
         * HTTP API default when a caller omits 'method').
         *
         * @param mixed $method
         */
        public static function normaliseMethod($method): string
        {
            $method = strtoupper(trim((string) $method));
            if ($method === '' || ! in_array($method, self::httpMethods(), true)) {
                return 'GET';
            }

            return $method;
        }

        /**
         * Clean a method set: uppercase, drop unknowns, de-dupe. The ALL_METHODS sentinel wins (and
         * collapses the set to just ALL_METHODS).
         *
         * Returns an EMPTY list when nothing survives - it deliberately does not fall back to
         * ALL_METHODS. Callers must decide what an empty result means, because the two inputs that
         * produce one are opposites: no method set was supplied at all (a legacy bare host - allow
         * everything) versus a set was supplied and every verb in it was unrecognised (a typo - the
         * entry must be dropped, never widened to all methods, or a one-character slip in wp-config
         * would grant exactly the writes it was written to withhold).
         *
         * @param array $methods
         * @return list<string>
         */
        public static function normaliseMethodList(array $methods): array
        {
            $clean = array();
            foreach ($methods as $method) {
                $method = strtoupper(trim((string) $method));
                if ($method === self::ALL_METHODS) {
                    return array(self::ALL_METHODS);
                }
                if (in_array($method, self::httpMethods(), true) && ! in_array($method, $clean, true)) {
                    $clean[] = $method;
                }
            }

            return $clean;
        }

        /**
         * Match a host against an allowlist pattern. Supports an exact host (api.example.com) and a
         * leading-wildcard domain (*.example.com matches any subdomain AND the apex example.com).
         */
        public static function hostMatchesPattern(string $host, string $pattern): bool
        {
            $host = strtolower(trim($host));
            $pattern = strtolower(trim($pattern));
            if ($host === '' || $pattern === '') {
                return false;
            }
            if (strpos($pattern, '*.') === 0) {
                // ".example.com"
                $suffix = substr($pattern, 1);
                // "example.com"
                $apex = substr($pattern, 2);
                if ($host === $apex) {
                    return true;
                }

                return strlen($host) > strlen($suffix)
                    && substr($host, -strlen($suffix)) === $suffix;
            }

            return $host === $pattern;
        }

        /**
         * Normalise a list of host patterns: strip scheme/path, lowercase, trim, drop blanks and any
         * non-scalar, de-dupe. Keys are ignored - callers that need per-method scope must read the key
         * before handing the host here.
         *
         * @param array $hosts
         * @return list<string>
         */
        public static function normaliseHostList(array $hosts): array
        {
            $clean = array();
            foreach ($hosts as $host) {
                if (! is_scalar($host)) {
                    continue;
                }
                $host = strtolower(trim((string) $host));
                if ($host === '') {
                    continue;
                }
                // Strip a scheme if someone pasted a URL.
                $schemePos = strpos($host, '://');
                if ($schemePos !== false) {
                    $host = substr($host, $schemePos + 3);
                }
                // Strip any path.
                $slashPos = strpos($host, '/');
                if ($slashPos !== false) {
                    $host = substr($host, 0, $slashPos);
                }
                $host = trim($host);
                if ($host !== '' && ! in_array($host, $clean, true)) {
                    $clean[] = $host;
                }
            }

            return $clean;
        }

        /**
         * Normalise rule input, dropping anything unusable. A rule with a host but no method set means
         * ALL_METHODS; a rule whose method set was supplied and is entirely unrecognised is DROPPED,
         * not widened - same reasoning as the allowlist tiers.
         *
         * @param mixed $raw
         * @return list<array{host:string,methods:list<string>,paths:list<string>,require:array<string,list<string>>,note:string}>
         */
        public static function normalise($raw): array
        {
            if (! is_array($raw)) {
                return array();
            }

            $rules = array();
            foreach ($raw as $rule) {
                if (! is_array($rule) || ! isset($rule['host']) || ! is_scalar($rule['host'])) {
                    continue;
                }
                $host = self::normaliseHostList(array((string) $rule['host']))[0] ?? '';
                if ($host === '') {
                    continue;
                }

                $rawMethods = (array) ( $rule['methods'] ?? array() );
                $methods = self::normaliseMethodList($rawMethods);
                if (empty($methods)) {
                    if (! empty($rawMethods)) {
                        continue;
                    }
                    $methods = array(self::ALL_METHODS);
                }

                $require = self::normaliseRequire($rule['require'] ?? array());
                if ($require === null) {
                    // A requirement with no usable permitted values could never be satisfied - drop the
                    // whole rule rather than silently ignore the constraint it was written to impose.
                    continue;
                }

                $rules[] = array(
                    'host' => $host,
                    'methods' => $methods,
                    'paths' => self::normalisePaths($rule['paths'] ?? array()),
                    'require' => $require,
                    'absent' => self::normaliseAbsent($rule['absent'] ?? array()),
                    'note' => isset($rule['note']) && is_scalar($rule['note'])
                        ? (string) $rule['note']
                        : '',
                );
            }

            return $rules;
        }

        /**
         * Whether a request is permitted by any rule in the set. Every constraint a rule declares must
         * hold; a rule declaring no paths and no parameters degrades to plain host + method matching.
         *
         * @param list<array<string,mixed>> $rules Already through normalise().
         * @param mixed $body The request body, read (never stored) for `require` matching.
         */
        public static function matches(array $rules, string $host, string $method, string $url, $body = null): bool
        {
            $host = strtolower(trim($host));
            $method = self::normaliseMethod($method);
            if ($host === '' || empty($rules)) {
                return false;
            }

            $path = self::extractPath($url);
            $params = null;

            foreach ($rules as $rule) {
                if (! self::hostMatchesPattern($host, $rule['host'])) {
                    continue;
                }
                $methodOk = in_array(self::ALL_METHODS, $rule['methods'], true)
                    || in_array($method, $rule['methods'], true);
                if (! $methodOk) {
                    continue;
                }
                if (! empty($rule['paths']) && ! self::pathMatchesAnyPrefix($path, $rule['paths'])) {
                    continue;
                }
                if (! empty($rule['require'])) {
                    if ($params === null) {
                        $params = self::extractRequestParams($url, $body);
                    }
                    if (! self::paramsSatisfyRequirements($params, $rule['require'])) {
                        continue;
                    }
                }
                if (! empty($rule['absent'])) {
                    // An absence claim is only as good as our read of the request. `require` fails
                    // closed on a body we could not parse (the parameter simply is not found), but
                    // `absent` would fail OPEN on exactly the same input - an unreadable body would
                    // read as "the parameter is not there". So a rule asserting absence only matches
                    // when the whole request was actually readable.
                    if (! self::bodyWasFullyRead($body)) {
                        continue;
                    }
                    if ($params === null) {
                        $params = self::extractRequestParams($url, $body);
                    }
                    if (! self::paramsSatisfyAbsences($params, $rule['absent'])) {
                        continue;
                    }
                }

                return true;
            }

            return false;
        }

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
         * Prefix match on SEGMENT BOUNDARIES: the prefix '/api/v2/info' matches '/api/v2/info' and
         * '/api/v2/info/x' but NOT '/api/v2/information-deactivate'. A naive strpos() === 0 would allow
         * the third, which is the very failure this tier exists to prevent.
         *
         * A '.' or '..' segment never matches: the rule pins a literal prefix, so a traversal segment
         * means the path the server resolves is not the one that was vetted. Fail closed.
         *
         * @param list<string> $prefixes
         */
        public static function pathMatchesAnyPrefix(string $path, array $prefixes): bool
        {
            $path = strtolower($path);
            if ($path === '') {
                $path = '/';
            }
            foreach (explode('/', $path) as $segment) {
                if ($segment === '.' || $segment === '..') {
                    return false;
                }
            }
            $normalised = rtrim($path, '/');
            if ($normalised === '') {
                $normalised = '/';
            }

            foreach ($prefixes as $prefix) {
                $prefix = rtrim(strtolower($prefix), '/');
                if ($prefix === '') {
                    // '/' as a prefix means "any path"; the rule's other constraints still apply.
                    return true;
                }
                if (strpos($prefix, '*') !== false) {
                    if (self::segmentsMatchPrefix($normalised, $prefix)) {
                        return true;
                    }

                    continue;
                }
                if ($normalised === $prefix || strpos($normalised, $prefix . '/') === 0) {
                    return true;
                }
            }

            return false;
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
         * Segment-wise prefix match where `*` stands for exactly one whole segment.
         *
         * Needed for vendors that put a credential in the path and append the ACTION after it, so
         * that no fixed prefix can pin the action: Admin Menu Editor Pro posts seat activation to
         * /licenses/<key>/license_site and seat REMOVAL to /licenses/<key>/unlicense_site - same
         * host, same method, and identical bodies. Without a wildcard segment the only rule that
         * reaches the first also reaches the second, and the second can release production's seat.
         *
         * `*` matches one non-empty segment and never spans a `/`, so a pattern cannot widen itself
         * by accident. Ordinary prefix semantics still apply after the pattern is consumed - a
         * pattern is allowed to match a longer path, exactly like a literal prefix does.
         *
         * @param string $path Already lowercased and trailing-slash trimmed by the caller.
         */
        private static function segmentsMatchPrefix(string $path, string $prefix): bool
        {
            $pathSegments = explode('/', ltrim($path, '/'));
            $prefixSegments = explode('/', ltrim($prefix, '/'));
            if (count($prefixSegments) > count($pathSegments)) {
                return false;
            }

            foreach ($prefixSegments as $index => $segment) {
                if ($segment === '*') {
                    // A wildcard stands for a real segment, never an empty one.
                    if ($pathSegments[ $index ] === '') {
                        return false;
                    }

                    continue;
                }
                if ($pathSegments[ $index ] !== $segment) {
                    return false;
                }
            }

            return true;
        }

        /**
         * Clean a rule's path prefix list: non-scalars and blanks dropped, a leading slash added, de-duped.
         *
         * @param mixed $raw
         * @return list<string>
         */
        private static function normalisePaths($raw): array
        {
            $paths = array();
            foreach ((array) $raw as $path) {
                if (! is_scalar($path)) {
                    continue;
                }
                $path = trim((string) $path);
                if ($path === '') {
                    continue;
                }
                if ($path[0] !== '/') {
                    $path = '/' . $path;
                }
                if (! in_array($path, $paths, true)) {
                    $paths[] = $path;
                }
            }

            return $paths;
        }

        /**
         * Normalise an `absent` list: parameter names that must not appear in the request. Names are
         * trimmed and de-duped; blanks and non-scalars are dropped.
         *
         * Unlike normaliseRequire() an empty result is not an error - it simply means the rule makes
         * no absence claim - so there is no null return to drop the rule on.
         *
         * @param mixed $raw
         * @return list<string>
         */
        private static function normaliseAbsent($raw): array
        {
            $absent = array();
            foreach ((array) $raw as $value) {
                if (! is_scalar($value)) {
                    continue;
                }
                $value = trim((string) $value);
                if ($value !== '' && ! in_array($value, $absent, true)) {
                    $absent[] = $value;
                }
            }

            return $absent;
        }

        /**
         * Clean a rule's `require` map. Returns NULL when a named parameter ends up with no usable
         * permitted values, which the caller treats as "drop the whole rule" - an unsatisfiable
         * constraint must never degrade into an absent one.
         *
         * @param mixed $raw
         * @return array<string,list<string>>|null
         */
        private static function normaliseRequire($raw): ?array
        {
            $require = array();
            foreach ((array) $raw as $key => $values) {
                $key = trim((string) $key);
                if ($key === '') {
                    continue;
                }
                $clean = array();
                foreach ((array) $values as $value) {
                    if (! is_scalar($value)) {
                        continue;
                    }
                    $value = strtolower(trim((string) $value));
                    if (! in_array($value, $clean, true)) {
                        $clean[] = $value;
                    }
                }
                if (empty($clean)) {
                    return null;
                }
                $require[ $key ] = $clean;
            }

            return $require;
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
