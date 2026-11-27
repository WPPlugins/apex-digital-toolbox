<?php

declare(strict_types=1);

namespace ApexToolbox\Packages;

use ApexToolbox\Lib\Filter;

/**
 * Everything that exists purely so a development copy can talk to a local
 * registry instead of the real one, in one file that reads as such.
 *
 * Every hook here is a no-op unless PackagesConfig says this site is genuinely
 * overridden - which itself requires the outbound firewall to have decided this
 * is a development or staging copy. On production not one of them does anything,
 * whatever wp-config says.
 *
 * Three separate things stand between a WordPress site and http://localhost:3000,
 * and all three have to be answered or the failure looks like a bug in the
 * package client rather than a deliberate protection:
 *
 *   1. The plugin's own outbound firewall blocks any host with no rule.
 *   2. wp_http_validate_url() - used by download_url() via wp_safe_remote_get -
 *      rejects any port that is not 80, 443 or 8080.
 *   3. The same function rejects a host that resolves to a private or loopback
 *      address, which localhost and host.docker.internal both do.
 */
final class DevRegistryOverride
{
    public function __construct(private readonly PackagesConfig $config)
    {
    }

    /**
     * Let the firewall reach the development registry's manifest.
     *
     * Added as a curated rule rather than an allowlist entry so it is exactly as
     * narrow as the shipped production rule - GET, one path - and so it shows up
     * on the firewall settings screen with its reason, where an operator
     * wondering why a local host is reachable will find it.
     *
     * @param mixed $rules
     * @return mixed
     */
    #[Filter('apex_toolbox_http_default_rules')]
    public function allowDevRegistry($rules)
    {
        if (! is_array($rules) || ! $this->config->isOverridden()) {
            return $rules;
        }

        $rules[] = array(
            'host' => $this->config->registryHost(),
            'methods' => array('GET'),
            'paths' => array(PackagesConfig::MANIFEST_PATH),
            'note' => 'Development package registry, set by APEX_TOOLBOX_PACKAGES_REGISTRY in '
                . 'wp-config.php. Read-only, and only ever present on a development or staging '
                . 'copy - a production site ignores that setting entirely.',
        );

        return $rules;
    }

    /**
     * Allow the development registry's port through wp_http_validate_url().
     *
     * Core permits 80, 443 and 8080 only, so a registry on :3000 fails with "A
     * valid URL was not provided" - an error that says nothing about ports and
     * sends you looking in the wrong place. Only the configured port is added,
     * not a blanket relaxation.
     *
     * @param mixed $ports
     */
    #[Filter('http_allowed_safe_ports')]
    public function allowDevRegistryPort($ports)
    {
        if (! is_array($ports) || ! $this->config->isOverridden()) {
            return $ports;
        }

        $port = wp_parse_url($this->config->registryBase(), PHP_URL_PORT);
        if (is_int($port) && ! in_array($port, $ports, true)) {
            $ports[] = $port;
        }

        return $ports;
    }

    /**
     * Treat the development registry as an external host.
     *
     * wp_http_validate_url() refuses a host resolving to a loopback or private
     * address, which is every way of reaching a registry running on the
     * developer's own machine. Scoped to the configured host by name - this must
     * never become a general "allow private addresses", which is the SSRF guard
     * the check exists to provide.
     *
     * @param mixed $isExternal
     * @param mixed $host
     * @return mixed
     */
    #[Filter('http_request_host_is_external')]
    public function allowDevRegistryHost($isExternal, $host)
    {
        if ($isExternal === true || ! is_string($host) || ! $this->config->isOverridden()) {
            return $isExternal;
        }

        return strtolower($host) === $this->config->registryHost()
            ? true
            : $isExternal;
    }
}
