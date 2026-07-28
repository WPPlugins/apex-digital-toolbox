<?php

declare(strict_types=1);

namespace ApexToolbox\Mcp\OAuth;

/**
 * Short-lived (10 minute) authorisation codes for the OAuth code-with-PKCE flow.
 *
 * Codes are stored hashed (never raw), single-use (consumed on exchange), and carry the PKCE
 * code_challenge so the token endpoint can verify the caller holds the matching verifier.
 *
 * Backed by the `apex_mcp_oauth_auth_codes` option.
 */
final class AuthorizationCodeStore
{
    public const OPTION = 'apex_mcp_oauth_auth_codes';

    /** Authorisation-code lifetime in seconds. */
    public const TTL = 600;

    /**
     * Issue a code for an approved authorisation and return the RAW code (shown once, to the client).
     *
     * @param list<string> $scopes
     */
    public function issue(
        string $clientId,
        int $userId,
        string $redirectUri,
        string $codeChallenge,
        array $scopes,
        int $now,
    ): string {
        $raw = bin2hex(random_bytes(32));

        $codes = $this->prune($this->codes(), $now);
        $codes[ $this->hash($raw) ] = array(
            'client_id' => $clientId,
            'user_id' => $userId,
            'redirect_uri' => $redirectUri,
            'code_challenge' => $codeChallenge,
            'scopes' => array_values($scopes),
            'expires_at' => $now + self::TTL,
        );

        update_option(self::OPTION, $codes, false);

        return $raw;
    }

    /**
     * Consume a raw code: returns its stored data and deletes it (one-time use), or null if
     * unknown/expired.
     *
     * @return array<string,mixed>|null
     */
    public function consume(string $rawCode, int $now): ?array
    {
        $codes = $this->codes();
        $hash = $this->hash($rawCode);

        if (! isset($codes[ $hash ])) {
            return null;
        }

        $data = $codes[ $hash ];
        unset($codes[ $hash ]);
        update_option(self::OPTION, $this->prune($codes, $now), false);

        if (($data['expires_at'] ?? 0) < $now) {
            return null;
        }

        return $data;
    }

    private function hash(string $raw): string
    {
        return hash('sha256', $raw);
    }

    /** @return array<string, array<string,mixed>> */
    private function codes(): array
    {
        $codes = get_option(self::OPTION, array());

        return is_array($codes)
            ? $codes
            : array();
    }

    /**
     * @param array<string, array<string,mixed>> $codes
     * @return array<string, array<string,mixed>>
     */
    private function prune(array $codes, int $now): array
    {
        return array_filter($codes, static fn (array $c): bool => ($c['expires_at'] ?? 0) >= $now);
    }
}
