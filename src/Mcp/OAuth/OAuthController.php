<?php

declare(strict_types=1);

namespace ApexToolbox\Mcp\OAuth;

use ApexToolbox\Lib\Action;
use ApexToolbox\Mcp\ManifestController;
use ApexToolbox\Support\Branding;
use ApexToolbox\Support\WordPressVersionGate;
use WP_REST_Request;
use WP_REST_Response;

/**
 * The OAuth 2.1 + PKCE endpoints: authorize (browser consent), token (code/refresh exchange),
 * and revoke. Lightweight custom implementation — JWT access tokens, option-backed code/refresh
 * stores — chosen over a heavyweight PSR-7 library to fit WordPress natively.
 *
 * Version gating is strict: the authorize endpoint returns 412 on WP < 7.0 even for a well-formed
 * request, so a site that updated the plugin but not WordPress can't half-complete a connection.
 */
final class OAuthController
{
    /**
     * Front-end path for the browser authorize endpoint.
     *
     * Deliberately NOT a REST route: WordPress REST cookie auth requires a nonce, which a browser
     * OAuth redirect can't supply, so a REST authorize endpoint sees the logged-in user as
     * anonymous and bounces them back to the login screen forever. A normal front-end request uses
     * ordinary cookie auth (no nonce), so the consent screen works after login.
     */
    public const AUTHORIZE_PATH = '/apex-mcp/authorize';

    /**
     * Verify an RFC 7636 S256 PKCE challenge.
     */
    public static function verifyPkce(string $verifier, string $challenge): bool
    {
        if ($verifier === '' || $challenge === '') {
            return false;
        }

        $computed = self::base64UrlEncode(hash('sha256', $verifier, true));

        return hash_equals($challenge, $computed);
    }

    public static function base64UrlEncode(string $binary): string
    {
        return rtrim(strtr(base64_encode($binary), '+/', '-_'), '=');
    }

    public function __construct(
        private readonly ClientRegistry $clients,
        private readonly AuthorizationCodeStore $codes,
        private readonly RefreshTokenStore $refreshTokens,
        private readonly RevokedTokens $revoked,
        private readonly JwtIssuer $jwt,
    ) {
    }

    #[Action('rest_api_init')]
    public function registerRoutes(): void
    {
        $ns = ManifestController::NAMESPACE;

        register_rest_route($ns, '/oauth/token', array(
            'methods' => 'POST',
            'callback' => array($this, 'token'),
            'permission_callback' => '__return_true',
        ));

        register_rest_route($ns, '/oauth/revoke', array(
            'methods' => 'POST',
            'callback' => array($this, 'revoke'),
            'permission_callback' => '__return_true',
        ));
    }
    // ----------------------------------------------------------------- PKCE (pure, tested)


    // ----------------------------------------------------------------- token exchange (testable)

    /**
     * Authorization-code grant: validate the code + PKCE verifier, then mint tokens.
     *
     * @param array<string,mixed> $params
     * @return array<string,mixed>|\WP_Error
     */
    public function exchangeAuthorizationCode(array $params, int $now): array|\WP_Error
    {
        $clientId = (string) ($params['client_id'] ?? '');
        $client = $this->clients->find($clientId);
        if ($client === null) {
            return $this->oauthError('invalid_client', 'Unknown client.');
        }

        $data = $this->codes->consume((string) ($params['code'] ?? ''), $now);
        if ($data === null) {
            return $this->oauthError('invalid_grant', 'Authorization code is invalid or expired.');
        }

        if ($data['client_id'] !== $clientId || $data['redirect_uri'] !== (string) ($params['redirect_uri'] ?? '')) {
            return $this->oauthError('invalid_grant', 'Client or redirect URI mismatch.');
        }

        if (! self::verifyPkce((string) ($params['code_verifier'] ?? ''), (string) $data['code_challenge'])) {
            return $this->oauthError('invalid_grant', 'PKCE verification failed.');
        }

        return $this->mintTokens($client, (int) $data['user_id'], (array) $data['scopes'], $now);
    }

    /**
     * Refresh-token grant: rotate the refresh token and mint a new access token.
     *
     * @param array<string,mixed> $params
     * @return array<string,mixed>|\WP_Error
     */
    public function exchangeRefreshToken(array $params, int $now): array|\WP_Error
    {
        $clientId = (string) ($params['client_id'] ?? '');
        $client = $this->clients->find($clientId);
        if ($client === null) {
            return $this->oauthError('invalid_client', 'Unknown client.');
        }

        $raw = (string) ($params['refresh_token'] ?? '');
        $record = $this->refreshTokens->find($raw, $now);
        if ($record === null || $record['client_id'] !== $clientId) {
            return $this->oauthError('invalid_grant', 'Refresh token is invalid or expired.');
        }

        // Rotate: the old refresh token is single-use.
        $this->refreshTokens->revoke($raw);

        return $this->mintTokens($client, (int) $record['user_id'], (array) $record['scopes'], $now);
    }

    public function token(WP_REST_Request $request): WP_REST_Response
    {
        $params = $request->get_params();
        $grantType = (string) ($params['grant_type'] ?? '');
        $now = time();

        $result = match ($grantType) {
            'authorization_code' => $this->exchangeAuthorizationCode($params, $now),
            'refresh_token' => $this->exchangeRefreshToken($params, $now),
            default => $this->oauthError('unsupported_grant_type', 'Unsupported grant_type.'),
        };

        if ($result instanceof \WP_Error) {
            return $this->errorResponse($result);
        }

        return new WP_REST_Response($result, 200);
    }

    public function revoke(WP_REST_Request $request): WP_REST_Response
    {
        $token = (string) $request->get_param('token');

        // A refresh token (opaque) is revoked from storage; an access JWT is added to the deny list.
        $this->refreshTokens->revoke($token);

        foreach ($this->clients->all() as $client) {
            $claims = isset($client['public_key'])
                ? $this->jwt->verify($token, $client)
                : null;
            if ($claims !== null && isset($claims['jti'], $claims['exp'])) {
                $this->revoked->revoke((string) $claims['jti'], (int) $claims['exp']);

                break;
            }
        }

        return new WP_REST_Response(array('revoked' => true), 200);
    }

    /**
     * Front-end entry point: intercept GET/POST to AUTHORIZE_PATH before the normal template loads.
     * Runs as an ordinary (cookie-authenticated) request, so the login/consent flow works.
     */
    #[Action('template_redirect')]
    public function maybeAuthorize(): void
    {
        $uri = isset($_SERVER['REQUEST_URI'])
            ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI']))
            : '';
        $path = (string) wp_parse_url($uri, PHP_URL_PATH);

        if (untrailingslashit($path) !== self::AUTHORIZE_PATH) {
            return;
        }

        $method = isset($_SERVER['REQUEST_METHOD'])
            ? strtoupper(sanitize_text_field(wp_unslash($_SERVER['REQUEST_METHOD'])))
            : 'GET';

        // OAuth params arrive as query/body params with no WP nonce by design (the redirect comes
        // from Geoff). Individual fields are sanitized in authorize(); the consent POST is
        // separately protected by its own nonce, verified there.
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.NonceVerification.Missing
        $params = (array) wp_unslash($_REQUEST);

        $this->authorize($params, $method);
    }

    /**
     * Authorize logic: version gate, client/redirect + PKCE validation, login gate, consent, code.
     * Emits its response (redirect / HTML / JSON error) directly and exits.
     *
     * @param array<string,mixed> $params
     */
    public function authorize(array $params, string $method): void
    {
        if (! WordPressVersionGate::isSupported()) {
            $this->sendError(
                412,
                'apex_mcp_precondition_failed',
                sprintf('WordPress %s+ is required for MCP/OAuth.', WordPressVersionGate::MINIMUM_WP_VERSION),
            );
        }

        $clientId = sanitize_text_field((string) ($params['client_id'] ?? ''));
        $redirectUri = esc_url_raw((string) ($params['redirect_uri'] ?? ''));
        $state = sanitize_text_field((string) ($params['state'] ?? ''));
        $challenge = sanitize_text_field((string) ($params['code_challenge'] ?? ''));
        $scope = sanitize_text_field((string) ($params['scope'] ?? ''));
        $client = $this->clients->find($clientId);

        if ($client === null || ! in_array($redirectUri, (array) ($client['redirect_uris'] ?? array()), true)) {
            $this->sendError(400, 'invalid_request', 'Unknown client or redirect URI.');
        }

        if (sanitize_text_field((string) ($params['code_challenge_method'] ?? '')) !== 'S256' || $challenge === '') {
            $this->sendError(400, 'invalid_request', 'PKCE S256 code_challenge is required.');
        }

        if (! is_user_logged_in()) {
            $this->redirect(wp_login_url($this->currentUrl()));
        }

        $scopes = Scopes::filter(array_filter(explode(' ', $scope)));

        if ($method === 'POST') {
            if (! wp_verify_nonce((string) ($params['_apex_nonce'] ?? ''), 'apex_mcp_consent')) {
                $this->sendError(400, 'invalid_request', 'Invalid consent nonce.');
            }

            if (sanitize_text_field((string) ($params['consent'] ?? '')) !== 'approve') {
                $this->redirectToCallback(add_query_arg(
                    array('error' => 'access_denied', 'state' => rawurlencode($state)),
                    $redirectUri,
                ));
            }

            $code = $this->codes->issue($clientId, get_current_user_id(), $redirectUri, $challenge, $scopes, time());
            $this->redirectToCallback(add_query_arg(
                array('code' => rawurlencode($code), 'state' => rawurlencode($state)),
                $redirectUri,
            ));
        }

        $this->renderConsent($client, $scopes);
    }

    /** Internal (same-host) redirect — e.g. to the login screen. */
    protected function redirect(string $url): void
    {
        wp_safe_redirect($url);
        exit;
    }

    /**
     * Redirect back to the OAuth client's callback. The URL is an external host, so wp_safe_redirect
     * would normally block it and fall back to wp-admin — but it has ALREADY been validated against
     * the client's registered redirect_uris allow-list, so we permit its host for this redirect.
     */
    protected function redirectToCallback(string $url): void
    {
        $host = (string) wp_parse_url($url, PHP_URL_HOST);
        add_filter(
            'allowed_redirect_hosts',
            static function (array $hosts) use ($host): array {
                $hosts[] = $host;

                return $hosts;
            },
        );

        $this->redirect($url);
    }

    protected function sendHtml(string $html): void
    {
        // We intercept a path WordPress resolves as a 404, so force a 200 for the consent page.
        status_header(200);
        nocache_headers();
        header('Content-Type: text/html; charset=utf-8');
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- assembled from esc_* above
        echo $html;
        exit;
    }

    private function currentUrl(): string
    {
        return home_url(add_query_arg(array()));
    }

    private function sendError(int $status, string $code, string $message): void
    {
        status_header($status);
        nocache_headers();
        header('Content-Type: application/json; charset=utf-8');
        echo wp_json_encode(array('error' => $code, 'error_description' => $message));
        exit;
    }

    /**
     * @param array<string,mixed> $client
     * @param list<string> $scopes
     * @return array<string,mixed>
     */
    private function mintTokens(array $client, int $userId, array $scopes, int $now): array
    {
        $scopes = Scopes::filter($scopes);
        $access = $this->jwt->issueAccessToken($client, $userId, $scopes, $this->issuerUrl(), $now);
        $refresh = $this->refreshTokens->issue($client['client_id'], $userId, $scopes, $now);

        return array(
            'access_token' => $access['token'],
            'token_type' => 'Bearer',
            'expires_in' => JwtIssuer::ACCESS_TOKEN_TTL,
            'refresh_token' => $refresh,
            'scope' => implode(' ', $scopes),
        );
    }
    // ----------------------------------------------------------------- REST handlers


    // ----------------------------------------------------------------- helpers

    /**
     * @param array<string,mixed> $client
     * @param list<string> $scopes
     */
    private function renderConsent(array $client, array $scopes): void
    {
        $appName = esc_html((string) ($client['client_name'] ?? $client['client_id']));
        $siteName = esc_html((string) get_bloginfo('name'));
        $siteHost = esc_html((string) wp_parse_url((string) home_url(), PHP_URL_HOST));
        $userName = esc_html((string) wp_get_current_user()->display_name);
        $nonce = wp_nonce_field('apex_mcp_consent', '_apex_nonce', true, false);
        $logoSvg = Branding::toolboxSvg(22);

        $scopeRows = $this->renderScopeRows($scopes);

        $heading = esc_html__('Authorize access', 'apex_toolbox');
        /* translators: %s: requesting application name (e.g. Geoff). */
        $lead = sprintf(esc_html__('%s is requesting permission to manage this site.', 'apex_toolbox'), $appName);
        $signedIn = sprintf(
            /* translators: %s: current user display name. */
            esc_html__('Signed in as %s', 'apex_toolbox'),
            '<strong>' . $userName . '</strong>',
        );
        $allowLabel = esc_html__('This will allow it to:', 'apex_toolbox');
        $authorize = esc_html__('Authorize', 'apex_toolbox');
        $cancel = esc_html__('Cancel', 'apex_toolbox');
        $footnote = esc_html__('Only authorize applications you trust. Access can be revoked anytime.', 'apex_toolbox');
        $brand = esc_html__('Apex Toolbox · Limelight Digital', 'apex_toolbox');

        $html = <<<HTML
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>{$heading} — {$appName}</title>
<style>
:root{--ink:#0f172a;--muted:#64748b;--line:#e2e8f0;--bg:#eef2f6;--brand:#16a34a;--brand-d:#15803d;--amber:#b45309}
*{box-sizing:border-box}
body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:24px;
background:linear-gradient(160deg,#f8fafc 0%,#eef2f6 100%);color:var(--ink);
font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;line-height:1.5}
.card{width:100%;max-width:440px;background:#fff;border:1px solid var(--line);border-radius:16px;
box-shadow:0 10px 30px rgba(15,23,42,.08);overflow:hidden}
.head{padding:22px 28px;border-bottom:1px solid var(--line);display:flex;align-items:center;gap:12px}
.logo{width:38px;height:38px;border-radius:10px;background:var(--brand);color:#fff;display:flex;
align-items:center;justify-content:center;font-weight:700;font-size:17px;flex:0 0 auto}
.head .site{font-size:13px;color:var(--muted)}
.head .site b{display:block;color:var(--ink);font-size:15px;font-weight:600}
.body{padding:24px 28px}
h1{font-size:20px;margin:0 0 6px}
.lead{margin:0 0 4px;color:var(--ink)}
.who{margin:0 0 18px;font-size:13px;color:var(--muted)}
.allow{font-size:13px;font-weight:600;color:var(--muted);text-transform:uppercase;letter-spacing:.03em;margin:0 0 10px}
ul.scopes{list-style:none;margin:0 0 22px;padding:0;border:1px solid var(--line);border-radius:12px}
ul.scopes li{display:flex;align-items:flex-start;gap:10px;padding:11px 14px;font-size:14px}
ul.scopes li+li{border-top:1px solid var(--line)}
.ic{flex:0 0 auto;width:18px;height:18px;margin-top:1px}
.ic.read{color:var(--brand)}.ic.write{color:var(--amber)}
.txt{flex:1}
.badge{flex:0 0 auto;font-size:11px;font-weight:600;padding:2px 8px;border-radius:999px;
background:#fef3c7;color:var(--amber);align-self:center}
.actions{display:flex;gap:10px}
button{font:inherit;font-weight:600;font-size:15px;padding:11px 16px;border-radius:10px;
cursor:pointer;flex:1;border:1px solid transparent}
.btn-primary{background:var(--brand);color:#fff}
.btn-primary:hover{background:var(--brand-d)}
.btn-secondary{background:#fff;color:var(--ink);border-color:var(--line)}
.btn-secondary:hover{background:#f8fafc}
.note{margin:16px 0 0;font-size:12px;color:var(--muted)}
.foot{padding:14px 28px;border-top:1px solid var(--line);font-size:12px;color:var(--muted);text-align:center}
</style>
</head>
<body>
<main class="card">
<div class="head">
<div class="logo" role="img" aria-label="Apex Toolbox">{$logoSvg}</div>
<div class="site"><b>{$siteName}</b>{$siteHost}</div>
</div>
<div class="body">
<h1>{$heading}</h1>
<p class="lead">{$lead}</p>
<p class="who">{$signedIn}</p>
<p class="allow">{$allowLabel}</p>
<ul class="scopes">{$scopeRows}</ul>
<form method="post">
{$nonce}
<div class="actions">
<button class="btn-primary" type="submit" name="consent" value="approve">{$authorize}</button>
<button class="btn-secondary" type="submit" name="consent" value="deny">{$cancel}</button>
</div>
<p class="note">{$footnote}</p>
</form>
</div>
<div class="foot">{$brand}</div>
</main>
</body>
</html>
HTML;

        $this->sendHtml($html);
    }

    /**
     * Build the permission rows, distinguishing read scopes from write/admin scopes.
     *
     * @param list<string> $scopes
     */
    private function renderScopeRows(array $scopes): string
    {
        $descriptions = Scopes::all();
        $checkIcon = '<svg class="ic read" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">'
            . '<path d="M16.7 5.3a1 1 0 0 1 0 1.4l-7.5 7.5a1 1 0 0 1-1.4 0L3.3 9.7a1 1 0 1 1 1.4-1.4l3.3 3.3 '
            . '6.8-6.8a1 1 0 0 1 1.4 0z"/></svg>';
        $writeIcon = '<svg class="ic write" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">'
            . '<path d="M13.6 2.6a2 2 0 0 1 2.8 2.8l-8.4 8.4-3.5.7.7-3.5 8.4-8.4z"/></svg>';

        $rows = '';
        foreach ($scopes as $scope) {
            $isWrite = str_starts_with($scope, 'write') || str_starts_with($scope, 'admin');
            $desc = esc_html($descriptions[ $scope ] ?? $scope);
            $badge = $isWrite
                ? '<span class="badge">write</span>'
                : '';

            $rows .= '<li>' . ($isWrite ? $writeIcon : $checkIcon)
                . '<span class="txt">' . $desc . '</span>' . $badge . '</li>';
        }

        return $rows;
    }

    /** @return array{code:string,message:string,data:array<string,mixed>} */
    private function oauthError(string $code, string $message): \WP_Error
    {
        return new \WP_Error($code, $message, array('status' => 400));
    }

    private function errorResponse(\WP_Error $error): WP_REST_Response
    {
        $status = (int) ($error->get_error_data()['status'] ?? 400);

        return new WP_REST_Response(
            array('error' => $error->get_error_code(), 'error_description' => $error->get_error_message()),
            $status,
        );
    }

    private function issuerUrl(): string
    {
        return (string) home_url();
    }
    // The following emit output/redirects and exit; isolated here so the rest stays testable.
}
