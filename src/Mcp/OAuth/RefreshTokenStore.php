<?php

declare(strict_types=1);

namespace ApexToolbox\Mcp\OAuth;

/**
 * Refresh-token storage with a 30-day sliding window (each successful refresh rotates the token
 * and extends expiry). Tokens are stored hashed, never raw.
 *
 * Backed by the `apex_mcp_oauth_refresh_tokens` option.
 */
final class RefreshTokenStore
{
    public const OPTION = 'apex_mcp_oauth_refresh_tokens';

    /** Refresh-token lifetime in seconds (30 days). */
    public const TTL = 2_592_000;

    /**
     * Issue a new refresh token and return the RAW token.
     *
     * @param list<string> $scopes
     */
    public function issue(string $clientId, int $userId, array $scopes, int $now): string
    {
        $raw = bin2hex(random_bytes(32));

        $tokens = $this->prune($this->tokens(), $now);
        $tokens[ $this->hash($raw) ] = array(
            'client_id' => $clientId,
            'user_id' => $userId,
            'scopes' => array_values($scopes),
            'expires_at' => $now + self::TTL,
        );

        update_option(self::OPTION, $tokens, false);

        return $raw;
    }

    /**
     * Look up (without consuming) a raw refresh token.
     *
     * @return array<string,mixed>|null
     */
    public function find(string $rawToken, int $now): ?array
    {
        $tokens = $this->tokens();
        $hash = $this->hash($rawToken);

        if (! isset($tokens[ $hash ]) || ($tokens[ $hash ]['expires_at'] ?? 0) < $now) {
            return null;
        }

        return $tokens[ $hash ];
    }

    public function revoke(string $rawToken): void
    {
        $tokens = $this->tokens();
        unset($tokens[ $this->hash($rawToken) ]);
        update_option(self::OPTION, $tokens, false);
    }

    public function revokeAllForClient(string $clientId): void
    {
        $tokens = array_filter(
            $this->tokens(),
            static fn (array $t): bool => ($t['client_id'] ?? null) !== $clientId,
        );

        update_option(self::OPTION, $tokens, false);
    }

    private function hash(string $raw): string
    {
        return hash('sha256', $raw);
    }

    /** @return array<string, array<string,mixed>> */
    private function tokens(): array
    {
        $tokens = get_option(self::OPTION, array());

        return is_array($tokens)
            ? $tokens
            : array();
    }

    /**
     * @param array<string, array<string,mixed>> $tokens
     * @return array<string, array<string,mixed>>
     */
    private function prune(array $tokens, int $now): array
    {
        return array_filter($tokens, static fn (array $t): bool => ($t['expires_at'] ?? 0) >= $now);
    }
}
