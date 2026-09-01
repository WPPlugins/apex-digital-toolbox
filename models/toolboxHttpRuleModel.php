<?php

// Forbid accessing directly
if (! defined('ABSPATH')) {
    header('HTTP/1.1 401 Unauthorized');
    exit;
}
require_once __DIR__ . '/toolboxHttpRequestShape.php';

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
        // Returned by normaliseRespond() for an unusable `respond` value, so normalise() can tell
        // "no instruction" (null) from "a broken instruction" and drop the rule rather than let it
        // become an allow.
        public const INVALID_RESPOND = 'invalid-respond';

        // Sentinel in a method set meaning "every verb".
        public const ALL_METHODS = '*';

        // Cap on the request body parsed for `require` matching - a base64 upload must not balloon
        // memory. An oversized body simply yields no parameters, so a rule requiring one fails closed.
        // Kept as an alias so existing callers keep working; the value lives on the shape class.
        public const MAX_BODY_PARSE_BYTES = toolboxHttpRequestShape::MAX_BODY_PARSE_BYTES;

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

                $respond = self::normaliseRespond($rule['respond'] ?? null);
                if ($respond === self::INVALID_RESPOND) {
                    // See normaliseRespond: dropping is the fail-closed reading, because keeping the
                    // rule without the instruction would turn it into a real outbound allow.
                    continue;
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
                    'respond' => $respond,
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
            return self::firstMatch($rules, $host, $method, $url, $body) !== null;
        }

        /**
         * The first rule this request satisfies, or null. Callers that only need a yes/no use
         * matches(); this exists because a rule can carry an instruction (`respond`) that the caller
         * has to read off the winning rule rather than infer.
         *
         * @param mixed $body
         * @return array<string,mixed>|null
         */
        public static function firstMatch(array $rules, string $host, string $method, string $url, $body = null): ?array
        {
            $host = strtolower(trim($host));
            $method = self::normaliseMethod($method);
            if ($host === '' || empty($rules)) {
                return null;
            }

            $path = toolboxHttpRequestShape::extractPath($url);
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
                        $params = toolboxHttpRequestShape::extractRequestParams($url, $body);
                    }
                    if (! toolboxHttpRequestShape::paramsSatisfyRequirements($params, $rule['require'])) {
                        continue;
                    }
                }
                if (! empty($rule['absent'])) {
                    // An absence claim is only as good as our read of the request. `require` fails
                    // closed on a body we could not parse (the parameter simply is not found), but
                    // `absent` would fail OPEN on exactly the same input - an unreadable body would
                    // read as "the parameter is not there". So a rule asserting absence only matches
                    // when the whole request was actually readable.
                    if (! toolboxHttpRequestShape::bodyWasFullyRead($body)) {
                        continue;
                    }
                    if ($params === null) {
                        $params = toolboxHttpRequestShape::extractRequestParams($url, $body);
                    }
                    if (! toolboxHttpRequestShape::paramsSatisfyAbsences($params, $rule['absent'])) {
                        continue;
                    }
                }

                return $rule;
            }

            return null;
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
         * Build the canned response a `respond` rule returns, in the shape the WordPress HTTP API
         * hands back. Returning this from pre_http_request short-circuits the request entirely, so
         * nothing is sent and the caller sees an ordinary successful response.
         *
         * @param array{code:int,body:string} $respond
         * @return array<string,mixed>
         */
        public static function syntheticResponse(array $respond): array
        {
            return array(
                'headers' => array(),
                'body' => $respond['body'],
                'response' => array(
                    'code' => $respond['code'],
                    'message' => get_status_header_desc($respond['code']),
                ),
                'cookies' => array(),
                'filename' => null,
            );
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
         * Normalise a `respond` instruction: answer this request locally with a canned response
         * instead of letting it out OR blocking it.
         *
         * This exists for one narrow case. A vendor client that treats a WP_Error as fatal turns a
         * blocked call into a dead end - Elementor's connect client calls wp_die() on any WP_Error
         * (`base-app.php:469`), so blocking its `disconnect` kills the request before the client has
         * cleared its own local state, and the licence screen becomes unusable on a clone. Allowing
         * the call is not the answer either: `disconnect` is signed with production's credentials
         * and asks the vendor to drop THAT connection.
         *
         * So the request is answered locally: nothing leaves the site, and the client proceeds as if
         * the server had acknowledged it. This is only defensible where the evidence shows the client
         * does not act on the response body - it is a fabricated vendor response, and every use of it
         * must say in `docs/firewall-rules.md` why the body is ignored.
         *
         * `true` gives an empty 200. An array may override `code` and `body`.
         *
         * @param mixed $raw
         * @return array{code:int,body:string}|null
         */
        private static function normaliseRespond($raw)
        {
            if ($raw === null || $raw === false) {
                return null;
            }
            // Anything that is not exactly `true` or an array is a mistake, and the safe reading of
            // a mistake here is NOT "no respond instruction" - that would leave the rule matching as
            // an ordinary allow and send the request out, which for these endpoints is the outcome
            // the rule exists to prevent. Signal invalid so normalise() drops the whole rule, the
            // same way an unsatisfiable `require` does.
            if ($raw !== true && ! is_array($raw)) {
                return self::INVALID_RESPOND;
            }
            $code = 200;
            $body = '{}';
            if (is_array($raw)) {
                if (isset($raw['code']) && is_numeric($raw['code'])) {
                    $code = intval($raw['code']);
                }
                if (isset($raw['body']) && is_scalar($raw['body'])) {
                    $body = (string) $raw['body'];
                }
            }

            return array('code' => $code, 'body' => $body);
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
    }
}
