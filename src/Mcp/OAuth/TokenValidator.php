<?php

declare(strict_types=1);

namespace ApexToolbox\Mcp\OAuth;

use WP_REST_Request;

/**
 * Validates the OAuth bearer token on incoming MCP requests.
 *
 * Wired into the MCP Adapter as the server's `transport_permission_callback`: the adapter calls
 * it with the WP_REST_Request and treats a truthy return as "authorised". On success we also set
 * the current WP user to the token's subject so abilities run with the right capabilities.
 */
final class TokenValidator
{
    public function __construct(
        private readonly ClientRegistry $clients,
        private readonly JwtIssuer $jwt,
        private readonly RevokedTokens $revoked,
    ) {
    }

    /**
     * MCP Adapter permission callback.
     *
     * @return bool|\WP_Error true if authorised, WP_Error (401) otherwise.
     */
    public function validateForMcp(WP_REST_Request $request): bool|\WP_Error
    {
        $result = $this->authenticate($request);

        if ($result instanceof \WP_Error) {
            return $result;
        }

        wp_set_current_user($result['user_id']);

        return true;
    }

    /**
     * Authenticate a request from its bearer token.
     *
     * @return array{user_id:int,client_id:string,scopes:list<string>,jti:string}|\WP_Error
     */
    public function authenticate(WP_REST_Request $request): array|\WP_Error
    {
        $token = $this->bearerToken($request);
        if ($token === null) {
            return $this->unauthorized('Missing or malformed Authorization header.');
        }

        $now = $this->now();

        // One client in practice (Geoff); verify against each registered client's public key.
        foreach ($this->clients->all() as $client) {
            if (! isset($client['public_key'])) {
                continue;
            }

            $claims = $this->jwt->verify($token, $client);
            if ($claims === null) {
                continue;
            }

            $jti = (string) ($claims['jti'] ?? '');
            if ($jti === '' || $this->revoked->isRevoked($jti, $now)) {
                return $this->unauthorized('Token has been revoked.');
            }

            return array(
                'user_id' => (int) ($claims['sub'] ?? 0),
                'client_id' => (string) ($claims['aud'] ?? $client['client_id']),
                'scopes' => array_values((array) ($claims['scopes'] ?? array())),
                'jti' => $jti,
            );
        }

        return $this->unauthorized('Invalid or expired access token.');
    }

    protected function now(): int
    {
        return time();
    }

    private function bearerToken(WP_REST_Request $request): ?string
    {
        $header = (string) $request->get_header('authorization');

        if (preg_match('/^Bearer\s+(.+)$/i', $header, $matches) === 1) {
            return trim($matches[1]);
        }

        return null;
    }

    private function unauthorized(string $message): \WP_Error
    {
        return new \WP_Error('apex_mcp_unauthorized', $message, array('status' => 401));
    }
}
