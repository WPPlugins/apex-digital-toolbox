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
				'priority'    => 98,
				'args'        => 1
			) );
			$this->addHook( APEX_TOOLBOX_HOOK_ACTION, 'init', 'disableProductCategoryList', Array(
				'label'       => 'Remove product category list',
				'description' => 'Removes the list of categories a product is in on the single product page',
			) );
			$this->addHook( APEX_TOOLBOX_HOOK_ACTION, 'init', 'enhanceJupiterWooCommerce', Array(
				'label'       => 'Enhance Jupiter WooCommerce Experience',
				'description' => 'Various tweaks to the category, cart, and checkout pages to improve the user experience',
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
			if ( isset( $tabs['reviews'] ) ) {
				unset( $tabs['reviews'] );
			}

			return $tabs;
		}

		/**
		 * Removes the total products available for a given category
		 *
		 * @author Nigel Wells
		 * @version 0.4.0.17.04.20
		 * @return void;
		 */
		public function disableCategoryProductCount() {
		}

		/**
		 * Removes the list of categories a product is in on the single product page
		 *
		 * @author Nigel Wells
		 * @version 0.4.0.17.04.21
		 * @return void;
		 */
		public function disableProductCategoryList() {
			remove_action( 'woocommerce_single_product_summary', 'woocommerce_template_single_meta', 40 );

			return;
		}

		public function enhanceJupiterWooCommerce() {
			// Create settings
			$this->Toolbox->addSetting( Array(
				'name'        => 'wc_shipping_text',
				'label'       => 'Shipping and Delivery text',
				'type'        => 'textarea',
				'value'       => $this->Toolbox->getOption( 'wc_shipping_text' ),
				'description' => 'HTML text to show above the table of shipping options available'
			), $this->getLabel() );
			$this->Toolbox->addSetting( Array(
				'name'        => 'wc_hide_shipping_table',
				'label'       => 'Shipping Table Visibility',
				'type'        => 'checkbox',
				'range'       => [ 1 => 'Yes' ],
				'value'       => $this->Toolbox->getOption( 'wc_hide_shipping_table' ),
				'description' => 'Hide the shipping table options'
			), $this->getLabel() );
			// Setup various hooks
			add_action( 'wp_enqueue_scripts', function () {
				wp_enqueue_style( 'apex-woocommerce-styles', APEX_TOOLBOX_PLUGIN_URL . 'assets/css/apex-woocommerce-styles.min.css?' . filemtime( APEX_TOOLBOX_PLUGIN_PATH . 'assets/css/apex-woocommerce-styles.min.css' ) );
				wp_enqueue_script( 'apex-woocommerce-script', APEX_TOOLBOX_PLUGIN_URL . 'assets/js/apex-woocommerce-scripts.js?' . filemtime( APEX_TOOLBOX_PLUGIN_PATH . 'assets/js/apex-woocommerce-scripts.js' ) );
			} );

			add_action( 'woocommerce_before_main_content', function () {
				if ( $this->isShowingShopFilter() ) {
					echo '<div class="woocommerce-archive-category-container"><div class="woocommerce-archive-category-filters">';
					do_action( 'product_filters_plugin' );
					echo '</div><div class="woocommerce-archive-category-list">';
				}
			}, 50 );
			add_action( 'woocommerce_after_main_content', function () {
				if ( $this->isShowingShopFilter() ) {
					echo '</div></div>';
				}
			}, 10 );
			add_action( 'template_redirect', function () {
				remove_action( 'woocommerce_single_product_summary', 'woocommerce_template_single_meta', 12 );
				add_action( 'woocommerce_single_product_summary', 'woocommerce_template_single_meta', 6 );

				remove_action( 'woocommerce_single_product_summary', 'woocommerce_template_single_rating', 11 );
				add_action( 'woocommerce_single_product_summary', 'woocommerce_template_single_rating', 7 );
				if ( $this->hasShippingDetails() ) {
					add_action( 'woocommerce_single_product_summary', function () {
						echo '<a class="woocommerce-shipping-more-details" href="javascript:;" onclick="apex_viewShippingDelivery();">Shipping &amp; Delivery</a>';
					}, 31 );
				}
			} );
			if ( $this->hasShippingDetails() ) {
				add_filter( 'woocommerce_product_tabs', [ $this, 'showShippingDetails' ] );
			}

			add_filter( 'body_class', function ( $classes ) {
				if ( WC()->cart->get_cart_contents_count() ) {
					$classes[] = 'woocommerce-items-in-cart';
				} else {
					$classes[] = 'woocommerce-cart-empty';
				}

				return $classes;
			} );

			add_filter( 'woocommerce_cart_totals_order_total_html', function ( $html ) {
				$html = trim( str_replace( 'Tax estimated for New Zealand', 'GST', $html ) );
				if ( ! strpos( $html, 'includes_tax' ) && wc_tax_enabled() && WC()->cart->display_prices_including_tax() ) {
					$html .= '<small class="includes_tax">(includes $0.00 GST)<br />' . get_bloginfo( 'name' ) . ' is not registered for GST</small>';
				}

				return $html;
			} );
			add_action( 'woocommerce_review_order_before_submit', function () {
				echo '<div class="checkout-buttons-container">';
			}, 1 );
			add_action( 'woocommerce_review_order_after_submit', function () {
				echo '</div>';
			} );
			add_filter( 'woocommerce_thankyou_order_received_text', function ( $text ) {
				$text .= ' You will receive an email confirming your order.';

				return $text;
			} );
			add_action( 'woocommerce_cart_totals_after_order_total', function () {
				?>
                <tr class="order-coupon">
                    <td colspan="2">
						<?php printf( '<h4 class="mk-coupon-title"><a href="javascript:;" onclick="jQuery(\'#coupon_toggle\').slideToggle();">%s</a></h4>', __( 'Do you have a coupon code?', 'mk_framework' ) ); ?>
						<?php if ( wc_coupons_enabled() ) { ?>
                            <div id="coupon_toggle" class="coupon" style="display: none;">
                                <input type="text" name="coupon_code" class="input-text" id="coupon_code" value=""
                                       placeholder="<?php esc_attr_e( 'Coupon code', 'woocommerce' ); ?>"/> <input type="submit" class="button"
                                                                                                                   name="apply_coupon"
                                                                                                                   value="<?php esc_attr_e( 'Apply coupon', 'woocommerce' ); ?>"/>
								<?php do_action( 'woocommerce_cart_coupon' ); ?>
                            </div>
						<?php } ?>
                    </td>
                </tr>
				<?php
			} );
			add_filter( 'paginate_links', function ( $link ) {
				if ( $pos = strpos( $link, 'page/1/' ) ) {
					$link = substr( $link, 0, $pos );
				}

				return $link;
			} );
			add_filter( 'woocommerce_cart_shipping_method_full_label', function ( $label, $method ) {
				if ( $method->cost == 0 ) {
					$label = str_replace( '&#36;', '', $label );
					$label = str_replace( '0.00', 'Free', $label );
				}

				return $label;
			}, 10, 2 );
			add_filter( 'wpseo_breadcrumb_single_link', function ( $output, $link ) {
				global $post;
				if ( is_object( $post ) && $post->post_type == 'product' ) {
					if ( substr( $link['url'], - 6 ) == '/shop/' ) {
						$output = '';
					}
				}

				return $output;
			}, 10, 2 );
		}

		public function wooCommerceBeforeMainContent() {

		}

		private function isShowingShopFilter() {
			if ( is_product_category() && $this->checkProductsDisplaying() ) {
				return true;
			}

			return false;
		}

		private function checkProductsDisplaying() {
			global $wp_query;

			if ( ! is_product_category() ) {
				return false;
			}
			// Get the query object
			$object       = $wp_query->get_queried_object();
			$display_type = get_term_meta( $object->term_id, 'display_type', true );
			if ( $display_type == 'subcategories' ) {
				return false;
			}

			return true;
		}

		private function hasShippingDetails() {
			if ( $this->Toolbox->getOption( 'wc_shipping_text' ) ) {
				return true;
			} elseif ( intval( $this->Toolbox->getOption( 'wc_hide_shipping_table' ) ) ) {
				return false;
			}
			try {
				$shippingZone = new WC_Shipping_Zone( 1 );

				return true;
			} catch ( Exception $exception ) {
				return false;
			}
		}

		public function showShippingDetails( $tabs ) {
			$tabs['shipping_tab'] = array(
				'title'    => __( 'Shipping & Delivery', 'apex_toolbox' ),
				'priority' => 50,
				'callback' => function () {
					echo '<h2>Shipping &amp; Delivery</h2>';
					if ( $shippingText = $this->Toolbox->getOption( 'wc_shipping_text' ) ) {
						echo html_entity_decode( $shippingText );
					}
					$hideShipping = intval( $this->Toolbox->getOption( 'wc_hide_shipping_table' ) );
					if ( ! $hideShipping ) {
						try {
							$shippingZone = new WC_Shipping_Zone( 1 );
							echo '<table class="shop_attributes"><tbody>';
							foreach ( $shippingZone->get_shipping_methods() as $method ) {
								echo '<tr>
					<th>' . $method->title . '</th>
					<td>' . ( $method->cost > 0 ? wc_price( $method->cost ) : 'Free' ) . '</td>
				</tr>';
							}
							echo '</tbody></table>';
						} catch ( Exception $exception ) {
						}
					}
				}
			);

			return $tabs;
		}
	}

}