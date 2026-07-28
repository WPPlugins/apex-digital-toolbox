<?php

declare(strict_types=1);

namespace ApexToolbox\Mcp\OAuth;

use ApexToolbox\Vendor\Firebase\JWT\JWT;
use ApexToolbox\Vendor\Firebase\JWT\Key;

/**
 * Issues and verifies the RS256 JWT access tokens Geoff presents on MCP requests.
 *
 * Tokens are self-contained (the MCP request needs no DB lookup beyond the per-client signing
 * key and the revocation list), short-lived (1 hour), and carry the WP user id + granted scopes.
 */
final class JwtIssuer
{
    /** Access-token lifetime in seconds. */
    public const ACCESS_TOKEN_TTL = 3_600;

    private const ALGORITHM = 'RS256';

    /**
     * @param array{client_id:string,private_key:string} $client
     * @param list<string> $scopes
     * @param int $issuedAt Unix timestamp (injected for testability).
     * @return array{token:string,jti:string,expires_at:int}
     */
    public function issueAccessToken(
        array $client,
        int $userId,
        array $scopes,
        string $issuerUrl,
        int $issuedAt,
    ): array {
        $jti = wp_generate_uuid4();
        $expiresAt = $issuedAt + self::ACCESS_TOKEN_TTL;

        $payload = array(
            'iss' => $issuerUrl,
            'sub' => (string) $userId,
            'aud' => $client['client_id'],
            'iat' => $issuedAt,
            'exp' => $expiresAt,
            'jti' => $jti,
            'scopes' => array_values($scopes),
        );

        $token = JWT::encode($payload, $client['private_key'], self::ALGORITHM);

        return array(
            'token' => $token,
            'jti' => $jti,
            'expires_at' => $expiresAt,
        );
    }

    /**
     * Verify a token's signature + expiry against a client's public key.
     *
     * @param array{public_key:string} $client
     * @return array<string,mixed>|null Decoded claims, or null if invalid/expired/tampered.
     */
    public function verify(string $token, array $client): ?array
    {
        try {
            $decoded = JWT::decode($token, new Key($client['public_key'], self::ALGORITHM));

            return json_decode(json_encode($decoded), true);
        } catch (\Throwable $e) {
            return null;
        }
    }
}
