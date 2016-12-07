<?php
// Forbid accessing directly
if ( ! defined( 'ABSPATH' ) ) {
	header( 'HTTP/1.1 401 Unauthorized' );
	exit;
}
// Ensure there are no conflicts
if ( ! class_exists( 'toolboxHookVisualComposer' ) ) {
	class toolboxHookVisualComposer extends toolboxHookController {
		function __construct( $Toolbox ) {
			parent::__construct( $Toolbox );
			$this->setLabel( 'Visual Composer' );
			// Only need to worry about adding hooks if visual composer is actually installed
			if(function_exists('vc_map')) {
				$this->addHook( APEX_TOOLBOX_HOOK_FILTER, 'vc_load_default_templates', 'removeDefaultTemplates', Array(
					'label'       => 'Remove Default Templates',
					'description' => 'Cleans up Visual Composer templates area so that it only contains new templates added via the theme'
				) );
			}
		}

		/**
		 * Remove default templates bundled with Visual Composer
		 *
		 * @param array $args Any arguments passed to the callback
		 *
		 * @author Nigel Wells
		 * @version 0.3.5.16.11.01
		 * @return array;
		 */
		public function removeDefaultTemplates( $args = Array() ) {
			return array();
		}

	}

}