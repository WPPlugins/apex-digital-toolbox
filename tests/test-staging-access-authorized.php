<?php
/**
 * Tests for toolboxHookSiteWide::getStagingAccessDecision (the pure decision function
 * the public isStagingAccessAuthorized() wrapper translates into header/echo/exit).
 *
 * Strategy: drive each authorize/deny path by manipulating WP state and superglobals,
 * then assert the returned decision array. isAdmin() is stubbed via a test subclass so
 * we can simulate admin-area requests (WP_UnitTestCase defaults to non-admin).
 */

require_once dirname( __DIR__ ) . '/hooks/hook_site_wide.php';

if ( ! class_exists( 'StubbableHookSiteWide' ) ) {
    class StubbableHookSiteWide extends toolboxHookSiteWide {
        public bool $stub_is_admin = false;
        protected function isAdmin(): bool { return $this->stub_is_admin; }
    }
}

class Test_Staging_Access_Authorized extends WP_UnitTestCase {

    /** @var StubbableHookSiteWide */
    private $hookController;

    public function set_up() {
        parent::set_up();
        $this->resetGlobals();
        $this->configure();
    }

    public function tear_down() {
        $this->resetGlobals();
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    private function resetGlobals(): void {
        unset(
            $_SERVER['DDEV_PROJECT_TYPE'],
            $_SERVER['REMOTE_ADDR'],
            $_COOKIE['apex_toolbox_staging']
        );
        $_GET                = array();
        $GLOBALS['pagenow'] = '';
    }

    /**
     * Build a fresh Toolbox and hookController with the given option overrides.
     * Re-instantiate any time you need to change Toolbox-cached options mid-test.
     */
    private function configure( array $overrides = array() ): void {
        $defaults = array(
            'hooks'              => array( 'site_wide' => array( 'addSettings', 'isStagingAccessAuthorized' ) ),
            'production_url'     => 'https://production-only.test',
            'allowed_staging_ip' => '',
        );
        $config = array_merge( $defaults, $overrides );
        // toolboxController prepends 'apex_toolbox_' when reading via getOption(), so writes need
        // the same prefix - except for 'hooks' which is the bare option key the loader looks at.
        update_option( 'apex_toolbox_hooks', $config['hooks'] );
        update_option( 'apex_toolbox_production_url', $config['production_url'] );
        update_option( 'apex_toolbox_allowed_staging_ip', $config['allowed_staging_ip'] );

        $toolbox              = new toolboxController();
        $this->hookController = new StubbableHookSiteWide( $toolbox );
    }

    // ===== Production short-circuit =====

    public function test_allows_when_on_production() {
        // Configure with production_url matching the site_url so onProduction() is true
        $this->configure( array( 'production_url' => get_site_url() ) );

        $this->assertSame( array( 'kind' => 'allow' ), $this->hookController->getStagingAccessDecision() );
    }

    public function test_allows_when_addSettings_hook_disabled() {
        // checkIfProduction returns true when addSettings is disabled (so production_url isn't trusted)
        $this->configure( array(
            'hooks' => array( 'site_wide' => array( 'isStagingAccessAuthorized' ) ),
        ) );

        $this->assertSame( array( 'kind' => 'allow' ), $this->hookController->getStagingAccessDecision() );
    }

    public function test_allows_when_production_url_option_is_empty() {
        // checkIfProduction also treats empty production_url as production
        $this->configure( array( 'production_url' => '' ) );

        $this->assertSame( array( 'kind' => 'allow' ), $this->hookController->getStagingAccessDecision() );
    }

    // ===== Authorize branches (staging environment) =====

    public function test_allows_logged_in_user_on_staging() {
        $admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
        wp_set_current_user( $admin_id );

        $this->assertSame( array( 'kind' => 'allow' ), $this->hookController->getStagingAccessDecision() );
    }

    public function test_allows_when_ddev_project_type_is_wordpress() {
        $_SERVER['DDEV_PROJECT_TYPE'] = 'wordpress';

        $this->assertSame( array( 'kind' => 'allow' ), $this->hookController->getStagingAccessDecision() );
    }

    public function test_does_not_allow_unknown_ddev_project_type() {
        $_SERVER['DDEV_PROJECT_TYPE'] = 'drupal';

        // Should fall through DDEV check and end up in the no-allowed-IPs fallback
        $this->assertSame( array( 'kind' => 'allow' ), $this->hookController->getStagingAccessDecision(),
            'No allowed IPs configured - should fall through to allow rather than lock the site out' );

        // With allowed IPs configured and no match, this becomes a deny
        $this->configure( array( 'allowed_staging_ip' => '99.99.99.99' ) );
        $_SERVER['DDEV_PROJECT_TYPE'] = 'drupal';
        $_SERVER['REMOTE_ADDR']       = '1.2.3.4';
        $decision                     = $this->hookController->getStagingAccessDecision();
        $this->assertSame( 'deny', $decision['kind'] );
    }

    public function test_allows_login_page() {
        $GLOBALS['pagenow'] = 'wp-login.php';

        $this->assertSame( array( 'kind' => 'allow' ), $this->hookController->getStagingAccessDecision() );
    }

    public function test_allows_cron_request() {
        $GLOBALS['pagenow'] = 'wp-cron.php';

        $this->assertSame( array( 'kind' => 'allow' ), $this->hookController->getStagingAccessDecision() );
    }

    public function test_allows_admin_area_request() {
        $this->hookController->stub_is_admin = true;

        $this->assertSame( array( 'kind' => 'allow' ), $this->hookController->getStagingAccessDecision() );
    }

    // ===== Allowed IP list =====

    public function test_allows_when_remote_addr_matches_single_allowed_ip() {
        $this->configure( array( 'allowed_staging_ip' => '203.0.113.7' ) );
        $_SERVER['REMOTE_ADDR'] = '203.0.113.7';

        $this->assertSame( array( 'kind' => 'allow' ), $this->hookController->getStagingAccessDecision() );
    }

    public function test_allows_when_remote_addr_matches_one_of_multiple_allowed_ips() {
        $this->configure( array( 'allowed_staging_ip' => '198.51.100.1, 203.0.113.7 ,  192.0.2.55' ) );
        $_SERVER['REMOTE_ADDR'] = '203.0.113.7';

        $this->assertSame( array( 'kind' => 'allow' ), $this->hookController->getStagingAccessDecision() );
    }

    public function test_denies_when_remote_addr_does_not_match_allowed_ips() {
        $this->configure( array( 'allowed_staging_ip' => '203.0.113.7' ) );
        $_SERVER['REMOTE_ADDR'] = '1.2.3.4';

        $this->assertSame(
            array( 'kind' => 'deny', 'remote_addr' => '1.2.3.4' ),
            $this->hookController->getStagingAccessDecision()
        );
    }

    public function test_falls_through_to_allow_when_no_allowed_ips_configured() {
        // No allowed_staging_ip + nothing else authorizes - safety fallback to avoid lockout
        $_SERVER['REMOTE_ADDR'] = '1.2.3.4';

        $this->assertSame( array( 'kind' => 'allow' ), $this->hookController->getStagingAccessDecision() );
    }

    // ===== Cookie authorize =====

    public function test_allows_when_staging_cookie_matches() {
        $_COOKIE['apex_toolbox_staging'] = md5( AUTH_KEY );

        $this->assertSame( array( 'kind' => 'allow' ), $this->hookController->getStagingAccessDecision() );
    }

    public function test_does_not_allow_when_staging_cookie_value_is_wrong() {
        $this->configure( array( 'allowed_staging_ip' => '203.0.113.7' ) );
        $_COOKIE['apex_toolbox_staging'] = 'wrong-value';
        $_SERVER['REMOTE_ADDR']          = '1.2.3.4';

        $this->assertSame( 'deny', $this->hookController->getStagingAccessDecision()['kind'] );
    }

    // ===== GET-param cookie-set path =====

    public function test_sets_cookie_and_redirects_when_query_params_valid() {
        $code           = md5( AUTH_KEY );
        $_GET['request'] = 'staging';
        $_GET['code']    = $code;

        $this->assertSame(
            array( 'kind' => 'set_cookie_and_redirect', 'cookie_value' => $code ),
            $this->hookController->getStagingAccessDecision()
        );
    }

    public function test_does_not_set_cookie_when_query_code_is_wrong() {
        $this->configure( array( 'allowed_staging_ip' => '203.0.113.7' ) );
        $_GET['request']        = 'staging';
        $_GET['code']           = 'incorrect';
        $_SERVER['REMOTE_ADDR'] = '1.2.3.4';

        $this->assertSame( 'deny', $this->hookController->getStagingAccessDecision()['kind'] );
    }

    public function test_cookie_set_with_wrong_value_skips_get_param_path() {
        // Preserves the original elseif behavior: if a cookie is present (even wrong), the GET
        // param cookie-setup path is NOT attempted. This locks that quirk in as intentional.
        $this->configure( array( 'allowed_staging_ip' => '203.0.113.7' ) );
        $_COOKIE['apex_toolbox_staging'] = 'something-stale';
        $_GET['request']                 = 'staging';
        $_GET['code']                    = md5( AUTH_KEY );
        $_SERVER['REMOTE_ADDR']          = '1.2.3.4';

        $decision = $this->hookController->getStagingAccessDecision();

        $this->assertSame( 'deny', $decision['kind'], 'Stale cookie should block the GET-param fallback' );
    }

    // ===== Deny details =====

    public function test_deny_includes_remote_addr_when_set() {
        $this->configure( array( 'allowed_staging_ip' => '203.0.113.7' ) );
        $_SERVER['REMOTE_ADDR'] = '1.2.3.4';

        $this->assertSame(
            array( 'kind' => 'deny', 'remote_addr' => '1.2.3.4' ),
            $this->hookController->getStagingAccessDecision()
        );
    }

    public function test_deny_remote_addr_is_null_when_unset() {
        $this->configure( array( 'allowed_staging_ip' => '203.0.113.7' ) );
        unset( $_SERVER['REMOTE_ADDR'] );

        $this->assertSame(
            array( 'kind' => 'deny', 'remote_addr' => null ),
            $this->hookController->getStagingAccessDecision()
        );
    }

    // ===== Denied page HTML =====

    public function test_denied_html_includes_identified_ip() {
        $html = $this->invokeProtected( 'getStagingDeniedHtml', '203.0.113.42' );

        $this->assertStringContainsString( ':(', $html, 'Sad face marker should be present' );
        $this->assertStringContainsString( 'Your IP is logged as:', $html );
        $this->assertStringContainsString( '<b>203.0.113.42</b>', $html );
    }

    public function test_denied_html_shows_unknown_ip_when_remote_addr_missing() {
        $html = $this->invokeProtected( 'getStagingDeniedHtml', null );

        $this->assertStringContainsString( 'Your IP is logged as:', $html );
        $this->assertStringContainsString( '<b>unknown</b>', $html );
    }

    public function test_denied_html_escapes_ip_for_xss_safety() {
        // REMOTE_ADDR is server-side but defense-in-depth: anything reaching the page must be escaped.
        $html = $this->invokeProtected( 'getStagingDeniedHtml', '<script>alert(1)</script>' );

        $this->assertStringNotContainsString( '<script>', $html );
        $this->assertStringContainsString( '&lt;script&gt;', $html );
    }

    // ===== Logging =====

    public function test_build_log_message_includes_ip_request_and_user_agent() {
        $_SERVER['REQUEST_URI']     = '/admin/secret/';
        $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (TestRunner)';

        $message = $this->hookController->buildStagingAccessDeniedLogMessage( '203.0.113.42' );

        $this->assertStringContainsString( '[Apex Staging Lock]', $message );
        $this->assertStringContainsString( '203.0.113.42', $message );
        $this->assertStringContainsString( '/admin/secret/', $message );
        $this->assertStringContainsString( 'Mozilla/5.0 (TestRunner)', $message );
    }

    public function test_build_log_message_uses_unknown_fallbacks_when_request_data_missing() {
        unset( $_SERVER['REQUEST_URI'], $_SERVER['HTTP_USER_AGENT'] );

        $message = $this->hookController->buildStagingAccessDeniedLogMessage( null );

        $this->assertStringContainsString( 'IP unknown', $message );
        $this->assertStringContainsString( 'to unknown', $message );
        $this->assertStringContainsString( 'UA: unknown', $message );
    }

    public function test_logStagingAccessDenied_writes_to_php_error_log() {
        $log_file     = tempnam( sys_get_temp_dir(), 'apex-staging-log-' );
        $original_log = ini_get( 'error_log' );
        ini_set( 'error_log', $log_file );

        try {
            $_SERVER['REQUEST_URI']     = '/private/';
            $_SERVER['HTTP_USER_AGENT'] = 'PHPUnit/9';
            $this->hookController->logStagingAccessDenied( '198.51.100.7' );

            $contents = file_get_contents( $log_file );
            $this->assertStringContainsString( '[Apex Staging Lock]', $contents );
            $this->assertStringContainsString( '198.51.100.7', $contents );
            $this->assertStringContainsString( '/private/', $contents );
            $this->assertStringContainsString( 'PHPUnit/9', $contents );
        } finally {
            ini_set( 'error_log', $original_log );
            @unlink( $log_file );
        }
    }

    /**
     * Helper to call a protected method on the hook controller without bouncing off the wrapper that exits.
     */
    private function invokeProtected( string $method, ...$args ) {
        // PHP 8.1+ - non-private members are reflectable without setAccessible().
        return ( new ReflectionMethod( $this->hookController, $method ) )->invoke( $this->hookController, ...$args );
    }
}
