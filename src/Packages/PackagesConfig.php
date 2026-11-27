<?php

declare(strict_types=1);

namespace ApexToolbox\Packages;

/**
 * Where the private package registry lives, and how this site proves who it is.
 *
 * Single source of truth for the host, the paths and the token. The host in particular is
 * referenced from three places that MUST agree or the feature silently half-works: the attribute
 * on the update filter (WordPress derives the filter name from each plugin's own `Update URI`
 * header), the curated firewall rule in toolboxHttpRuleDefaults, and the validation that rejects a
 * manifest pointing the upgrader somewhere else.
 *
 * The token is an opaque bearer credential issued by the registry per site. Precedence is
 * wp-config constant over option, matching APEX_TOOLBOX_HTTP_ALLOWLIST: the constant is how the
 * fleet is provisioned (written over wp-cli), the option is the manual fallback for a site that
 * cannot be reached that way. A clone inherits whichever is set, and that is intended - entitlement
 * is per client site, not per seat, so a development copy should see exactly the same packages.
 */
class PackagesConfig
{
    /** Exact host. Never a wildcard on the apex - ai.limelightdigital.co.nz is Geoff. */
    public const REGISTRY_HOST = 'cue.limelightdigital.co.nz';

    public const REGISTRY_BASE = 'https://' . self::REGISTRY_HOST;

    /** Pinned by the curated firewall rule; changing it needs that rule changed too. */
    public const MANIFEST_PATH = '/api/packages/manifest';

    /**
     * Path prefix for the Update URI a private plugin declares in its header.
     *
     * Namespaced rather than sitting at the root because the registry is a real
     * application with its own pages, and a package slug at the root would be
     * competing with them - a package called `settings` or `login` is not
     * far-fetched. WordPress never fetches this URL, but the plugin points
     * humans at it: with no homepage of its own, a package's "View version
     * details" link and the plugin-information modal both resolve here. So it
     * needs to be somewhere the registry can sensibly serve a page.
     *
     * Changing this later is expensive: the URI is baked into every shipped zip
     * and an installed plugin keeps declaring the old one until it updates. It
     * is cheap now only because nothing has shipped.
     */
    public const UPDATE_URI_PREFIX = '/packages';

    public const TOKEN_CONSTANT = 'APEX_TOOLBOX_PACKAGES_TOKEN';

    public const TOKEN_OPTION = 'apex_toolbox_packages_token';

    /** wp-config off-switch, mirroring APEX_TOOLBOX_DISABLED_HOOKS for the legacy layer. */
    public const DISABLE_CONSTANT = 'APEX_TOOLBOX_PACKAGES_DISABLED';

    /**
     * Point a DEVELOPMENT copy at a different registry, e.g.
     * define('APEX_TOOLBOX_PACKAGES_REGISTRY', 'http://host.docker.internal:3000');
     *
     * Honoured ONLY where the outbound firewall says this is a non-production
     * environment, so a live site can never be repointed however wp-config is
     * edited. Repointing the registry is an "install code from somewhere else"
     * lever; anyone who can write wp-config can already run PHP, but there is no
     * reason to hand them a tidier way to do it on a production site.
     *
     * It changes where the site FETCHES from. It does not change the Update URI
     * a plugin declares in its own header - that is the plugin's identity, it is
     * baked into every shipped zip, and core derives the update filter's name
     * from it. So a demo plugin still carries the production Update URI while
     * its manifest and package come from the local registry.
     */
    public const DEV_REGISTRY_CONSTANT = 'APEX_TOOLBOX_PACKAGES_REGISTRY';

    /**
     * Memoised per request - the answer cannot change mid-request.
     */
    private ?string $base = null;

    /**
     * The token in force. The constant wins so a provisioned site cannot be repointed from the
     * database alone.
     *
     * @param mixed $constantValue
     * @param mixed $optionValue
     */
    public static function resolveToken($constantValue, $optionValue): string
    {
        foreach (array($constantValue, $optionValue) as $candidate) {
            if (! is_scalar($candidate)) {
                continue;
            }
            $token = trim((string) $candidate);
            if ($token !== '') {
                return $token;
            }
        }

        return '';
    }

    /** The Update URI a private plugin's header must declare for this registry to serve it. */
    public static function updateUriFor(string $slug): string
    {
        return self::REGISTRY_BASE . self::UPDATE_URI_PREFIX . '/' . $slug;
    }

    /**
     * The slug an installed plugin's Update URI names, or '' when the header is not ours.
     *
     * The host check is not redundant with the filter name: this also runs over every installed
     * plugin's header when matching the manifest to what is on disk, and those headers are written
     * by third parties.
     */
    public static function slugFromUpdateUri(string $uri): string
    {
        $uri = trim($uri);
        if ($uri === '') {
            return '';
        }

        $parts = wp_parse_url($uri);
        if (! is_array($parts) || strtolower((string) ($parts['host'] ?? '')) !== self::REGISTRY_HOST) {
            return '';
        }

        $path = trim((string) ($parts['path'] ?? ''), '/');
        $prefix = trim(self::UPDATE_URI_PREFIX, '/');

        // The prefix is required, not optional. Accepting a bare slug at the root
        // as well would leave the registry's root namespace claimed, which is the
        // whole thing the prefix exists to avoid.
        if ($prefix !== '') {
            if (strpos($path, $prefix . '/') !== 0) {
                return '';
            }
            $path = substr($path, strlen($prefix) + 1);
        }

        // One segment after the prefix - '/packages/foo/bar' is not a slug this
        // registry issues.
        return preg_match('/^[a-z0-9][a-z0-9-]{0,63}$/i', $path) === 1
            ? $path
            : '';
    }

    /**
     * A short, non-reversible fingerprint of the token, used to tie a cached manifest to the
     * credential that produced it. A rotation pushed by the registry therefore invalidates the
     * cache by itself, with nothing to remember to clear.
     */
    public static function tokenFingerprint(string $token): string
    {
        return $token === '' ? '' : substr(hash('sha256', $token), 0, 16);
    }

    /**
     * A registry base URL that is safe to use, or null when the value is unusable.
     *
     * Pure. Accepts http as well as https because the whole point is a local
     * dev server; the non-production gate on the caller is what makes that
     * acceptable. A path, query or fragment is rejected rather than trimmed - a
     * base with a path is more likely a mistake than an intention, and guessing
     * would silently point the site somewhere it was never told to look.
     *
     * @param mixed $raw
     */
    public static function normaliseBase($raw): ?string
    {
        if (! is_scalar($raw)) {
            return null;
        }
        $base = rtrim(trim((string) $raw), '/');
        if ($base === '') {
            return null;
        }

        $parts = wp_parse_url($base);
        if (! is_array($parts)) {
            return null;
        }
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));
        if (! in_array($scheme, array('http', 'https'), true) || $host === '') {
            return null;
        }
        if (isset($parts['path']) && trim((string) $parts['path'], '/') !== '') {
            return null;
        }
        if (isset($parts['query']) || isset($parts['fragment'])) {
            return null;
        }

        $port = isset($parts['port'])
            ? ':' . (int) $parts['port']
            : '';

        return $scheme . '://' . $host . $port;
    }

    /**
     * A recognisable hint for a saved token, without reprinting it.
     *
     * Enough to answer "is this the one I issued?" and no more. The screen needs
     * manage_options, whose holders can read the option anyway, so this is about
     * not putting a credential on screen needlessly rather than about secrecy.
     */
    public static function maskToken(string $token): string
    {
        $token = trim($token);
        if ($token === '') {
            return '';
        }

        // A short token gets no tail at all - four characters of an eight
        // character secret is half of it.
        return strlen($token) < 12
            ? str_repeat('•', 8)
            : str_repeat('•', 8) . substr($token, -4);
    }

    public function __construct(private readonly \toolboxController $toolbox)
    {
    }

    public function token(): string
    {
        return self::resolveToken($this->constantToken(), $this->optionToken());
    }

    /**
     * The registry this site talks to: production, unless a development copy has
     * been pointed elsewhere.
     */
    public function registryBase(): string
    {
        if ($this->base === null) {
            $this->base = $this->resolveBase();
        }

        return $this->base;
    }

    public function registryHost(): string
    {
        return (string) (wp_parse_url($this->registryBase(), PHP_URL_HOST) ?? self::REGISTRY_HOST);
    }

    /** Whether this site is pointed at a development registry rather than the real one. */
    public function isOverridden(): bool
    {
        return $this->registryBase() !== self::REGISTRY_BASE;
    }

    public function manifestUrl(): string
    {
        return $this->registryBase() . self::MANIFEST_PATH;
    }

    /**
     * Whether the feature does anything at all. With no token nothing is fetched, the update filter
     * answers false immediately, and the admin page shows how to get one - so the token is the real
     * gate, and the constant/filter exist only for parity with the rest of the plugin's off-switches.
     */
    public function isEnabled(): bool
    {
        if ($this->isDisabledByConstant()) {
            return false;
        }

        return (bool) apply_filters('apex_toolbox_packages_enabled', $this->token() !== '');
    }

    /** Whether the token comes from wp-config, in which case the admin form is read-only. */
    public function tokenIsFromConstant(): bool
    {
        $constant = $this->constantToken();

        return is_scalar($constant) && trim((string) $constant) !== '';
    }

    /**
     * Whether the outbound firewall considers this a development or staging copy.
     *
     * Deliberately asks the firewall rather than re-deriving it: that hook is the
     * single source of truth for "is this production?" across the plugin, and a
     * second implementation would drift. Fail-safe is PRODUCTION - if the hook is
     * missing (killed by APEX_TOOLBOX_DISABLED_HOOKS) we cannot tell, and the
     * safe answer to "may this site be repointed?" is no.
     */
    protected function isNonProduction(): bool
    {
        $firewall = $this->toolbox->getObject('outbound_firewall');
        if (! is_object($firewall) || ! method_exists($firewall, 'isNonProductionEnvironment')) {
            return false;
        }

        return (bool) ($firewall->isNonProductionEnvironment()['block'] ?? false);
    }

    /** @return mixed */
    protected function overrideBase()
    {
        return defined(self::DEV_REGISTRY_CONSTANT)
            ? constant(self::DEV_REGISTRY_CONSTANT)
            : null;
    }

    /** @return mixed */
    protected function constantToken()
    {
        return defined(self::TOKEN_CONSTANT)
            ? constant(self::TOKEN_CONSTANT)
            : null;
    }

    /** @return mixed */
    protected function optionToken()
    {
        return get_option(self::TOKEN_OPTION, '');
    }

    protected function isDisabledByConstant(): bool
    {
        return defined(self::DISABLE_CONSTANT) && (bool) constant(self::DISABLE_CONSTANT);
    }

    private function resolveBase(): string
    {
        $override = self::normaliseBase($this->overrideBase());
        if ($override === null || ! $this->isNonProduction()) {
            return self::REGISTRY_BASE;
        }

        return $override;
    }
}
