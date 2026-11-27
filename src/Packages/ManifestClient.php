<?php

declare(strict_types=1);

namespace ApexToolbox\Packages;

/**
 * The one place a request leaves this site.
 *
 * Until this class the plugin made no outbound HTTP of its own at all, which is worth keeping in
 * mind: everything here is subject to the plugin's own outbound firewall on any non-production
 * copy, and reaches the network only because toolboxHttpRuleDefaults ships a curated rule for the
 * registry's manifest path.
 *
 * The token travels in an Authorization header and NEVER in the URL. The firewall's blocked-request
 * log stores request paths verbatim and only masks query VALUES, so a credential in a path segment
 * would be written in clear text into an option on every clone the first time a call was blocked.
 *
 * Failure is classified rather than merely reported, because the three kinds want different
 * behaviour upstream: a rejected token will not un-reject itself and deserves a long backoff, a
 * firewall block is an expected, explainable state on a development copy, and a transport error is
 * worth retrying soon.
 */
class ManifestClient
{
    public const STATE_OK = 'ok';

    /** 401/403 - the token is wrong, revoked, or the site is unknown. */
    public const STATE_REJECTED = 'rejected';

    /** Stopped by this plugin's own outbound firewall, not by the network. */
    public const STATE_BLOCKED = 'blocked';

    public const STATE_ERROR = 'error';

    private const TIMEOUT = 10;

    /**
     * Parse a 200 body. Pure, so the whole contract with the registry is testable without HTTP.
     *
     * A 200 is taken as COMPLETE: any package absent from it is treated upstream as revoked. That
     * is why a body we cannot parse is an error rather than an empty package set - degrading to
     * "entitled to nothing" would silently revoke the fleet.
     *
     * @param mixed $body
     * @return array{ok:bool,state:string,status:int,packages:array<string,array<string,mixed>>,message:string}
     */
    public static function parse(
        int $status,
        $body,
        string $registryBase = PackagesConfig::REGISTRY_BASE,
    ): array {
        $decoded = is_string($body)
            ? json_decode($body, true)
            : null;
        if (! is_array($decoded) || ! isset($decoded['packages']) || ! is_array($decoded['packages'])) {
            return self::failure(self::STATE_ERROR, $status, 'The registry response could not be read.');
        }

        $packages = array();
        foreach ($decoded['packages'] as $raw) {
            $record = PackageRecord::normalise($raw, $registryBase);
            if ($record !== null) {
                $packages[$record['slug']] = $record;
            }
        }

        return array(
            'ok' => true,
            'state' => self::STATE_OK,
            'status' => $status,
            'packages' => $packages,
            'message' => '',
        );
    }

    /** Pure classification of a failed attempt. */
    public static function stateForFailure(int $status, string $wpErrorCode): string
    {
        if ($wpErrorCode === 'apex_toolbox_outbound_blocked') {
            return self::STATE_BLOCKED;
        }

        return in_array($status, array(401, 403), true)
            ? self::STATE_REJECTED
            : self::STATE_ERROR;
    }

    /** @return array{ok:bool,state:string,status:int,packages:array<string,array<string,mixed>>,message:string} */
    private static function failure(string $state, int $status, string $message): array
    {
        return array(
            'ok' => false,
            'state' => $state,
            'status' => $status,
            'packages' => array(),
            'message' => $message,
        );
    }

    private static function messageForStatus(int $status): string
    {
        if (in_array($status, array(401, 403), true)) {
            return 'The registry rejected this site\'s token.';
        }

        return sprintf('The registry returned HTTP %d.', $status);
    }

    public function __construct(private readonly PackagesConfig $config)
    {
    }

    /**
     * Fetch and parse the manifest.
     *
     * @return array{ok:bool,state:string,status:int,packages:array<string,array<string,mixed>>,message:string}
     */
    public function fetch(string $token): array
    {
        $response = $this->request(
            $this->config->manifestUrl(),
            array(
                'timeout' => self::TIMEOUT,
                'redirection' => 2,
                'headers' => array(
                    'Authorization' => 'Bearer ' . $token,
                    'Accept' => 'application/json',
                    // Lets the registry tell a clone from production without the site reporting
                    // anything of its own - both carry the same token by design.
                    'X-Apex-Site-Url' => get_site_url(),
                ),
            ),
        );

        if ($response instanceof \WP_Error) {
            return self::failure(
                self::stateForFailure(0, $response->get_error_code()),
                0,
                $response->get_error_message(),
            );
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        if ($status !== 200) {
            return self::failure(
                self::stateForFailure($status, ''),
                $status,
                self::messageForStatus($status),
            );
        }

        return self::parse($status, wp_remote_retrieve_body($response), $this->config->registryBase());
    }

    /**
     * @param array<string, mixed> $args
     * @return array<string, mixed>|\WP_Error
     */
    protected function request(string $url, array $args)
    {
        return wp_remote_get($url, $args);
    }
}
