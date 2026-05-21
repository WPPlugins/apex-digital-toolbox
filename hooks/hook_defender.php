<?php
// Forbid accessing directly
if ( ! defined( 'ABSPATH' ) ) {
    header( 'HTTP/1.1 401 Unauthorized' );
    exit;
}
// Ensure there are no conflicts
if ( ! class_exists( 'toolboxHookDefender' ) ) {
    class toolboxHookDefender extends toolboxHookController {
        function __construct( $Toolbox ) {
            parent::__construct( $Toolbox );
            $this->setLabel( 'WP Defender' );
            $this->addHook( APEX_TOOLBOX_HOOK_FILTER, 'wp_defender_audit_hooks', 'ignoreAuditHooksDuringImport', array(
                'label'       => 'Skip Audit Hooks During Imports',
                'description' => 'Return an empty audit-hooks list while an import is processing so WP Defender does not log every row of a WP All Import or custom (import_key) import run. Matches when the request is on the WP All Import page or carries an "import_key" parameter, with an action of "process" or "processing".',
                'args'        => 1,
            ) );
        }

        /**
         * Suppress WP Defender's audit hooks while an import is actively processing rows.
         * Detects WP All Import via its admin page slug, and any other importer via the
         * conventional `import_key` request parameter (so we don't have to hardcode site-specific keys).
         *
         * @param array|null $hooks
         * @return array
         */
        public function ignoreAuditHooksDuringImport( $hooks ) {
            $page       = isset( $_REQUEST['page'] ) ? (string) $_REQUEST['page'] : '';
            $action     = isset( $_REQUEST['action'] ) ? (string) $_REQUEST['action'] : '';
            $import_key = isset( $_REQUEST['import_key'] ) ? (string) $_REQUEST['import_key'] : '';

            $is_import_request = $page === 'pmxi-admin-import' || $import_key !== '';
            $is_processing     = in_array( $action, array( 'process', 'processing' ), true );

            if ( $is_import_request && $is_processing ) {
                return array();
            }

            return is_array( $hooks ) ? $hooks : array();
        }
    }
}
