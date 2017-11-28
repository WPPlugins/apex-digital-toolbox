<?php
// Forbid accessing directly
if ( ! defined( 'ABSPATH' ) ) {
	header( 'HTTP/1.1 401 Unauthorized' );
	exit;
}
// Ensure there are no conflicts
if ( ! class_exists( 'toolboxHookWoocommerce' ) ) {
	class toolboxHookWoocommerce extends toolboxHookController {
		function __construct( $Toolbox ) {
			parent::__construct( $Toolbox );
			$this->setLabel( 'WooCommerce' );
			$this->addHook( APEX_TOOLBOX_HOOK_FILTER, 'woocommerce_product_tabs', 'disableWooCommerceReviews', Array(
				'label'       => 'Disable reviews on all products',
				'description' => 'Will disable reviews from being available on any product and hide the tab on the product page',
				'priority'    => 98,
				'args'        => 1
			) );
			$this->addHook( APEX_TOOLBOX_HOOK_FILTER, 'woocommerce_subcategory_count_html', 'disableCategoryProductCount', Array(
				'label'       => 'Disable product count',
				'description' => 'Removes the total products available for a given category',
			) );
			$this->addHook( APEX_TOOLBOX_HOOK_ACTION, 'init', 'disableProductCategoryList', Array(
				'label'       => 'Remove product category list',
				'description' => 'Removes the list of categories a product is in on the single product page',
			) );
		}

		/**
		 * Will disable reviews from being available on any product and hide the tab on the product page
		 *
		 * @param array $tabs Tabs available for output
		 *
		 * @author Nigel Wells
		 * @version 0.4.0.17.04.20
		 * @return array;
		 */
		public function disableWooCommerceReviews( $tabs ) {
			if(isset($tabs['reviews'])) unset( $tabs['reviews'] );

			return $tabs;
		}

		/**
		 * Removes the total products available for a given category
		 *
		 * @author Nigel Wells
		 * @version 0.4.0.17.04.20
		 * @return void;
		 */
		public function disableCategoryProductCount( ) {
			return;
		}

		/**
		 * Removes the list of categories a product is in on the single product page
		 *
		 * @author Nigel Wells
		 * @version 0.4.0.17.04.21
		 * @return void;
		 */
		public function disableProductCategoryList( ) {
			remove_action( 'woocommerce_single_product_summary', 'woocommerce_template_single_meta', 40 );
			return;
		}
	}

}