<?php

declare(strict_types=1);

namespace ApexToolbox\Mcp\OAuth;

/**
 * Persistence for OAuth clients (in practice just "geoff", but the store allows N).
 *
 * Each client carries its own RS256 signing key pair, generated once on first activation and
 * stored as PEM strings. Revoking a client wipes its keys, instantly invalidating every token
 * it ever signed (they can no longer be verified) until it re-authorises.
 *
 * Backed by the `apex_mcp_oauth_clients` option (per the MCP storage-layout contract).
 */
final class ClientRegistry
{
    public const OPTION = 'apex_mcp_oauth_clients';

    /** @return array{public_key:string,private_key:string} */
    public static function generateKeyPair(): array
    {
        $resource = openssl_pkey_new(array('private_key_bits' => 2_048, 'private_key_type' => OPENSSL_KEYTYPE_RSA));

        if ($resource === false) {
            throw new \RuntimeException('Unable to generate an RSA key pair for the OAuth client.');
        }

        openssl_pkey_export($resource, $privateKey);
        $details = openssl_pkey_get_details($resource);

        if (! is_array($details) || ! isset($details['key'])) {
            throw new \RuntimeException('Unable to export the public key for the OAuth client.');
        }

        return array(
            'private_key' => (string) $privateKey,
            'public_key' => (string) $details['key'],
        );
    }

    /** @return array<int, array<string,mixed>> */
    public function all(): array
    {
        $clients = get_option(self::OPTION, array());

        return is_array($clients)
            ? array_values($clients)
            : array();
    }

    /** @return array<string,mixed>|null */
    public function find(string $clientId): ?array
    {
        foreach ($this->all() as $client) {
            if (($client['client_id'] ?? null) === $clientId) {
                return $client;
            }
        }

        return null;
    }

    /**
     * Ensure the client exists and matches the configured redirect URIs / scopes.
     *
     * Creates the client (with a fresh key pair) if missing. If it already exists, the signing
     * key pair is PRESERVED (so previously-issued tokens stay valid) while the config-driven
     * fields — redirect_uris, scopes, client_name — are synced. This lets a site change its
     * allowed redirect URIs (e.g. via the APEX_TOOLBOX_MCP_REDIRECT_URIS constant) without
     * having to revoke and re-pair the client.
     *
     * @param list<string> $redirectUris
     * @param list<string> $scopes
     * @return array<string,mixed> the created/updated client record
     */
    public function ensureClient(
        string $clientId,
        string $clientName,
        array $redirectUris,
        array $scopes,
        int $createdAt,
    ): array {
        $clients = $this->all();

        foreach ($clients as $index => $client) {
            if (($client['client_id'] ?? null) !== $clientId) {
                continue;
            }

            // Existing client: keep the key pair, sync the config-driven fields.
            $clients[ $index ]['client_name'] = $clientName;
            $clients[ $index ]['redirect_uris'] = array_values($redirectUris);
            $clients[ $index ]['scopes'] = Scopes::filter($scopes);
            update_option(self::OPTION, $clients, false);

            return $clients[ $index ];
        }

        $keys = self::generateKeyPair();

        $client = array(
            'client_id' => $clientId,
            'client_name' => $clientName,
            'redirect_uris' => array_values($redirectUris),
            'scopes' => Scopes::filter($scopes),
            'public_key' => $keys['public_key'],
            'private_key' => $keys['private_key'],
            'created_at' => $createdAt,
        );

        $clients[] = $client;
        update_option(self::OPTION, $clients, false);

        return $client;
    }

    public function revoke(string $clientId): void
    {
        $remaining = array_values(array_filter(
            $this->all(),
            static fn (array $client): bool => ($client['client_id'] ?? null) !== $clientId,
        ));

        update_option(self::OPTION, $remaining, false);
    }
}
