<?php

declare(strict_types=1);

namespace ApexToolbox\Mcp\OAuth;

/**
 * Revocation list of issued JWT ids (jti) that must be rejected before their natural expiry.
 *
 * Entries carry the token's own expiry so the list self-prunes — a revoked jti only needs to be
 * remembered until the moment it would have expired anyway.
 *
 * Backed by the `apex_mcp_oauth_revoked_jat` option.
 */
final class RevokedTokens
{
    public const OPTION = 'apex_mcp_oauth_revoked_jat';

    public function revoke(string $jti, int $expiresAt): void
    {
        $entries = $this->prune($this->entries(), $expiresAt);
        $entries[ $jti ] = $expiresAt;

        update_option(self::OPTION, $entries, false);
    }

    public function isRevoked(string $jti, int $now): bool
    {
        $entries = $this->entries();

        return isset($entries[ $jti ]) && $entries[ $jti ] >= $now;
    }

    /** @return array<string,int> jti => expires_at */
    private function entries(): array
    {
        $entries = get_option(self::OPTION, array());

        return is_array($entries)
            ? $entries
            : array();
    }

    /**
     * @param array<string,int> $entries
     * @return array<string,int>
     */
    private function prune(array $entries, int $now): array
    {
        return array_filter($entries, static fn (int $expiresAt): bool => $expiresAt >= $now);
    }
}
