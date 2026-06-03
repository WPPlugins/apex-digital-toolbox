<?php
/**
 * Tests for toolboxHookWordPressFront::getDomainRedirectTarget.
 *
 * The wrapper fixDomainRedirects() runs header() + die() so we test the pure decision
 * function it delegates to instead - that's where all the logic lives. We use a test
 * subclass to stub the global-state checks (is_admin / CLI / AJAX) so we can drive
 * each branch deterministically.
 */

require_once dirname( __DIR__ ) . '/hooks/hook_word_press_front.php';

if ( ! class_exists( 'StubbableHookWordPressFront' ) ) {
    class StubbableHookWordPressFront extends toolboxHookWordPressFront {
        public bool $stub_is_admin = false;
        public bool $stub_is_cli   = false;
        public bool $stub_is_ajax  = false;

        protected function isAdmin(): bool { return $this->stub_is_admin; }
        protected function isCLI(): bool   { return $this->stub_is_cli; }
        protected function isAJAX()        { return $this->stub_is_ajax; }
    }
}

class Test_Fix_Domain_Redirects extends WP_UnitTestCase {

    /** @var StubbableHookWordPressFront */
    private $hookController;

    public function set_up() {
        parent::set_up();
        $this->resetServer();
        update_option( 'home', 'http://example.test' );
        $toolbox              = new toolboxController();
        $this->hookController = new StubbableHookWordPressFront( $toolbox );
    }

    public function tear_down() {
        $this->resetServer();
        parent::tear_down();
    }

    private function resetServer(): void {
        unset( $_SERVER['HTTPS'], $_SERVER['HTTP_HOST'] );
        // REQUEST_URI is consulted by WP internals (e.g., cron filter) - restore a benign default
        // rather than unset to avoid noisy "Undefined array key" warnings during tear_down.
        $_SERVER['REQUEST_URI'] = '/';
    }

    // ===== Skip cases (always returns null) =====

    public function test_returns_null_in_admin_context() {
        $this->hookController->stub_is_admin = true;
        $_SERVER['HTTP_HOST']                = 'wrong-host.test';
        $_SERVER['REQUEST_URI']              = '/some-page/';

        $this->assertNull( $this->hookController->getDomainRedirectTarget() );
    }

    public function test_returns_null_in_cli_context() {
        $this->hookController->stub_is_cli = true;
        $_SERVER['HTTP_HOST']              = 'wrong-host.test';
        $_SERVER['REQUEST_URI']             = '/some-page/';

        $this->assertNull( $this->hookController->getDomainRedirectTarget() );
    }

    public function test_returns_null_in_ajax_context() {
        $this->hookController->stub_is_ajax = true;
        $_SERVER['HTTP_HOST']               = 'wrong-host.test';
        $_SERVER['REQUEST_URI']             = '/some-page/';

        $this->assertNull( $this->hookController->getDomainRedirectTarget() );
    }

    public function test_returns_null_when_home_option_is_empty() {
        // WP rejects update_option( 'home', '' ) - go via the option_home filter instead.
        // WP_UnitTestCase's _restore_hooks() reverts this in tear_down.
        add_filter( 'option_home', '__return_empty_string' );
        $_SERVER['HTTP_HOST']   = 'wrong-host.test';
        $_SERVER['REQUEST_URI'] = '/some-page/';

        $this->assertNull( $this->hookController->getDomainRedirectTarget() );
    }

    // ===== Same-host passthrough =====

    public function test_returns_null_when_already_on_correct_host_http() {
        $_SERVER['HTTP_HOST']   = 'example.test';
        $_SERVER['REQUEST_URI'] = '/some-page/';

        $this->assertNull( $this->hookController->getDomainRedirectTarget() );
    }

    public function test_returns_null_when_already_on_correct_host_https() {
        update_option( 'home', 'https://example.test' );
        $_SERVER['HTTPS']       = 'on';
        $_SERVER['HTTP_HOST']   = 'example.test';
        $_SERVER['REQUEST_URI'] = '/some-page/';

        $this->assertNull( $this->hookController->getDomainRedirectTarget() );
    }

    // ===== Redirect cases =====

    public function test_redirects_when_on_wrong_host_preserves_path() {
        $_SERVER['HTTP_HOST']   = 'wrong-host.test';
        $_SERVER['REQUEST_URI'] = '/about/team/';

        $this->assertSame(
            'http://example.test/about/team/',
            $this->hookController->getDomainRedirectTarget()
        );
    }

    public function test_redirects_when_on_wrong_host_preserves_query_string() {
        $_SERVER['HTTP_HOST']   = 'wrong-host.test';
        $_SERVER['REQUEST_URI'] = '/search/?q=hello&page=2';

        $this->assertSame(
            'http://example.test/search/?q=hello&page=2',
            $this->hookController->getDomainRedirectTarget()
        );
    }

    public function test_redirects_from_http_request_to_https_home() {
        update_option( 'home', 'https://example.test' );
        // Current request is bare HTTP on the right host - protocol-mismatch counts as wrong-domain
        unset( $_SERVER['HTTPS'] );
        $_SERVER['HTTP_HOST']   = 'example.test';
        $_SERVER['REQUEST_URI'] = '/secure-page/';

        $this->assertSame(
            'https://example.test/secure-page/',
            $this->hookController->getDomainRedirectTarget()
        );
    }

    public function test_redirects_from_https_request_to_http_home_when_home_is_http() {
        // Inverse - if the home is plain http but client is on https, current code treats as
        // wrong domain because the prefix won't match. Locks this in as a behavioral guarantee.
        $_SERVER['HTTPS']       = 'on';
        $_SERVER['HTTP_HOST']   = 'example.test';
        $_SERVER['REQUEST_URI'] = '/';

        $this->assertSame(
            'http://example.test/',
            $this->hookController->getDomainRedirectTarget()
        );
    }

    public function test_redirects_when_root_path_only() {
        $_SERVER['HTTP_HOST']   = 'wrong-host.test';
        $_SERVER['REQUEST_URI'] = '/';

        $this->assertSame(
            'http://example.test/',
            $this->hookController->getDomainRedirectTarget()
        );
    }

    public function test_handles_missing_request_uri_gracefully() {
        $_SERVER['HTTP_HOST'] = 'wrong-host.test';
        unset( $_SERVER['REQUEST_URI'] );

        $this->assertSame(
            'http://example.test',
            $this->hookController->getDomainRedirectTarget()
        );
    }
}
