<?php
/**
 * Tests for toolboxHookDefender::ignoreAuditHooksDuringImport.
 *
 * The filter returns an empty hooks array (suppressing WP Defender audit logging) when:
 *  - $_REQUEST['page'] === 'pmxi-admin-import' (WP All Import), OR
 *  - $_REQUEST['import_key'] is a non-empty string (custom importer convention),
 * AND $_REQUEST['action'] is 'process' or 'processing'. Otherwise the original hooks
 * are returned unchanged (with a defensive coercion to [] when input is non-array).
 */

require_once dirname( __DIR__ ) . '/hooks/hook_defender.php';

class Test_Defender_Ignore_Audit_Hooks extends WP_UnitTestCase {

    /** @var toolboxHookDefender */
    private $hookController;

    public function set_up() {
        parent::set_up();
        $this->resetRequestSuperglobals();
        $toolbox              = new toolboxController();
        $this->hookController = new toolboxHookDefender( $toolbox );
    }

    public function tear_down() {
        $this->resetRequestSuperglobals();
        parent::tear_down();
    }

    private function resetRequestSuperglobals(): void {
        $_REQUEST = array();
        $_GET     = array();
        $_POST    = array();
    }

    // ===== Suppression cases =====

    public function test_returns_empty_when_wp_all_import_with_processing_action() {
        $_REQUEST['page']   = 'pmxi-admin-import';
        $_REQUEST['action'] = 'processing';

        $result = $this->hookController->ignoreAuditHooksDuringImport( array( 'login_failed', 'user_register' ) );

        $this->assertSame( array(), $result );
    }

    public function test_returns_empty_when_wp_all_import_with_process_action() {
        $_REQUEST['page']   = 'pmxi-admin-import';
        $_REQUEST['action'] = 'process';

        $result = $this->hookController->ignoreAuditHooksDuringImport( array( 'login_failed' ) );

        $this->assertSame( array(), $result );
    }

    public function test_returns_empty_when_custom_importer_key_with_processing_action() {
        $_REQUEST['import_key'] = 'qontro-ftp-importer';
        $_REQUEST['action']     = 'processing';

        $result = $this->hookController->ignoreAuditHooksDuringImport( array( 'login_failed' ) );

        $this->assertSame( array(), $result );
    }

    public function test_returns_empty_when_custom_importer_key_with_process_action() {
        $_REQUEST['import_key'] = 'another-importer';
        $_REQUEST['action']     = 'process';

        $result = $this->hookController->ignoreAuditHooksDuringImport( array( 'login_failed' ) );

        $this->assertSame( array(), $result );
    }

    // ===== Passthrough cases =====

    public function test_passthrough_when_no_request_params() {
        $original = array( 'login_failed', 'user_register', 'plugin_activated' );

        $result = $this->hookController->ignoreAuditHooksDuringImport( $original );

        $this->assertSame( $original, $result );
    }

    public function test_passthrough_when_import_page_but_action_is_not_processing() {
        $_REQUEST['page']   = 'pmxi-admin-import';
        $_REQUEST['action'] = 'edit';
        $original           = array( 'login_failed' );

        $result = $this->hookController->ignoreAuditHooksDuringImport( $original );

        $this->assertSame( $original, $result );
    }

    public function test_passthrough_when_import_page_but_no_action() {
        $_REQUEST['page'] = 'pmxi-admin-import';
        $original         = array( 'login_failed' );

        $result = $this->hookController->ignoreAuditHooksDuringImport( $original );

        $this->assertSame( $original, $result );
    }

    public function test_passthrough_when_processing_action_but_no_import_indicator() {
        // Action looks like a long-running import but nothing identifies the request as one
        $_REQUEST['action'] = 'processing';
        $original           = array( 'login_failed' );

        $result = $this->hookController->ignoreAuditHooksDuringImport( $original );

        $this->assertSame( $original, $result );
    }

    public function test_passthrough_when_import_key_is_empty_string() {
        // Defends against `?import_key=` query strings or unset values producing the empty string -
        // those should not be treated as a real importer
        $_REQUEST['import_key'] = '';
        $_REQUEST['action']     = 'processing';
        $original               = array( 'login_failed' );

        $result = $this->hookController->ignoreAuditHooksDuringImport( $original );

        $this->assertSame( $original, $result );
    }

    public function test_passthrough_for_unrelated_page() {
        $_REQUEST['page']   = 'options-general.php';
        $_REQUEST['action'] = 'processing';
        $original           = array( 'login_failed' );

        $result = $this->hookController->ignoreAuditHooksDuringImport( $original );

        $this->assertSame( $original, $result );
    }

    // ===== Defensive input handling =====

    public function test_returns_empty_array_when_hooks_input_is_null_and_no_match() {
        $result = $this->hookController->ignoreAuditHooksDuringImport( null );

        $this->assertSame( array(), $result );
    }

    public function test_returns_empty_array_when_hooks_input_is_null_and_suppression_applies() {
        $_REQUEST['page']   = 'pmxi-admin-import';
        $_REQUEST['action'] = 'processing';

        $result = $this->hookController->ignoreAuditHooksDuringImport( null );

        $this->assertSame( array(), $result );
    }
}
