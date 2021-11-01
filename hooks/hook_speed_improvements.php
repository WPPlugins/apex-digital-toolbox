<?php
// Forbid accessing directly
if ( ! defined( 'ABSPATH' ) ) {
    header( 'HTTP/1.1 401 Unauthorized' );
    exit;
}
// Ensure there are no conflicts
if ( ! class_exists( 'toolboxHookSpeedImprovements' ) ) {
    class toolboxHookSpeedImprovements extends toolboxHookController {
        private $inlineJS = [];

        function __construct( $Toolbox ) {
            parent::__construct( $Toolbox );
            $this->setLabel( 'Speed Improvements' );
            $this->addHook( APEX_TOOLBOX_HOOK_FILTER, 'wp_enqueue_scripts', 'inlineJQuery', array(
                'label'       => 'Inline jQuery',
                'description' => 'Outputs jQuery directly in the page source code rather than having it as a downloadable asset',
            ) );
            $this->addHook( APEX_TOOLBOX_HOOK_FILTER, 'wp', 'setupInlineJavaScriptHooks', array(
                'label'       => 'Output Inline JavaScript',
                'description' => 'Outputs any inline JavaScript files',
            ) );
        }


        /**
         * Setup the site map settings or register the shortcode if needed
         *
         * @param array $args Any arguments passed to the callback
         */
        public function inlineJQuery( $args = [] ) {
            $this->inlineJS( 'jquery-core' );
        }

        private function inlineJS( $handle ) {
            $this->inlineJS[] = $handle;
        }

        public function setupInlineJavaScriptHooks( $args = [] ) {
            if ( is_admin() ) {
                return;
            }
            add_action( 'wp_enqueue_scripts', [ $this, 'outputInlineJavaScript' ], 10000000 );
            add_filter( 'script_loader_tag', [ $this, 'filterScriptOutput' ], 10, 2 );
        }

        public function filterScriptOutput( $html, $handle ) {
            if ( in_array( $handle, $this->inlineJS ) ) {
                return '';
            }

            return $html;
        }

        public function outputInlineJavaScript( $args = [] ) {
            global $wp_scripts;

            foreach ( $this->inlineJS as $handle ) {
                if ( empty( $wp_scripts->{'registered'}[ $handle ] ) ) {
                    continue;
                }
                $parse = wp_parse_url( $wp_scripts->{'registered'}[ $handle ]->{'src'} );
                if ( empty( $parse['path'] ) ) {
                    continue;
                }
                $fullPath = ABSPATH . $parse['path'];
                echo '<!-- Start output JavaScript handle [' . $handle . ' - ' . $fullPath . '] -->
<script type="text/javascript">
' . file_get_contents( $fullPath ) . '
</script>
<!-- End CSS Handle [' . $handle . '] -->
';
            }
        }
    }
}
