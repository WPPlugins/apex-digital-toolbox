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
            $this->addHook( APEX_TOOLBOX_HOOK_FILTER, 'woocommerce_product_tabs', 'disableWooCommerceReviews', array(
                'label'       => 'Disable reviews on all products',
                'description' => 'Will disable reviews from being available on any product and hide the tab on the product page',
                'priority'    => 98,
                'args'        => 1
            ) );
            $this->addHook( APEX_TOOLBOX_HOOK_FILTER, 'woocommerce_subcategory_count_html', 'disableCategoryProductCount', array(
                'label'       => 'Disable product count',
                'description' => 'Removes the total products available for a given category',
                'priority'    => 98,
                'args'        => 1
            ) );
            $this->addHook( APEX_TOOLBOX_HOOK_ACTION, 'init', 'disableProductCategoryList', array(
                'label'       => 'Remove product category list',
                'description' => 'Removes the list of categories a product is in on the single product page',
            ) );
            $this->addHook( APEX_TOOLBOX_HOOK_ACTION, 'init', 'enhanceJupiterWooCommerce', array(
                'label'       => 'Enhance Jupiter WooCommerce Experience',
                'description' => 'Various tweaks to the category, cart, and checkout pages to improve the user experience',
            ) );
            $this->addHook( APEX_TOOLBOX_HOOK_ACTION, 'init', 'accountMenu', array(
                'label'       => 'Account Menu',
                'description' => 'Dynamically populate a menu with WooCommerce dashboard menu items',
            ) );
            $this->addHook( APEX_TOOLBOX_HOOK_FILTER, 'woocommerce_style_smallscreen_breakpoint', 'fixBreakpoint', array(
                'label'       => 'Fix Breakpoint',
                'description' => 'The tablet breakpoint in WooCommerce is 768px whereas all theme settings are 767px - turn this on to change WooCommerce to match the theme',
                'args'        => 1
            ) );
            $this->addHook( APEX_TOOLBOX_HOOK_ACTION, 'woocommerce_after_add_to_cart_form', 'enableStructuredData', array(
                'label'       => 'Enable Structured Data',
                'description' => 'When using Elementor the hook to output the schema markup isn\'t triggered - enable this hook to have it output'
            ) );
            $this->addHook( APEX_TOOLBOX_HOOK_FILTER, 'relevanssi_search_ok', 'enableProductFiltersRelevanssiCompatability', array(
                'label'       => 'Enable Product Filter Compatability with Relevanssi',
                'description' => 'Allow the Product Filters plugin to use Relevanssi when performing post queries - this allows filtering on the search page when searching for custom fields',
                'args'        => 2
            ) );
            $this->addHook( APEX_TOOLBOX_HOOK_ACTION, 'wp_head', 'disableProductFiltersScrollOnSafariBackNavigation', array(
                'label'       => "Improve Product Filters plugin back navigation",
                "description" => "The XForWooCommerce Product Filters plugin will scroll back to the top of the products when navigating back to a page with filters as on Safari, this hook disables that behaviour.",
                'priority'    => 0
            ) );
            $this->addHook( APEX_TOOLBOX_HOOK_ACTION, 'woocommerce_update_product', 'disableAdjustDownloadPermissionsSchedulingForNonDownloadableProducts', array(
                'label'       => 'Disable scheduling for non-downloadable products',
                'description' => 'Disable the scheduling of the `adjust_download_permissions` task when the product nor it\'s children are downloadable. This task is scheduled whenever any product is updated, regardless of whether or not it is downloadable. Not scheduling this for non-downloadable products avoids bloating the database when doing large product imports.',
                'args'        => 2
            ) );
        }

        /**
         * When using Elementor the hook to output the schema markup isn't triggered - enable this hook to have it output
         *
         * @return void
         */
        public function enableStructuredData() {
            $data = new WC_Structured_Data();
            $data->generate_product_data();
        }

        /**
         * Change the inclusion of WooCommerce small screen CSS to work from the expected breakpoint
         *
         * @param $breakpoint
         *
         * @return string
         */
        public function fixBreakpoint( $breakpoint ): string {
            return '767px';
        }

        /**
         * Will disable reviews from being available on any product and hide the tab on the product page
         *
         * @param array $tabs Tabs available for output
         *
         * @return array;
         * @version 0.4.0.17.04.20
         * @author Nigel Wells
         */
        public function disableWooCommerceReviews( $tabs ) {
            if ( isset( $tabs['reviews'] ) ) {
                unset( $tabs['reviews'] );
            }

            return $tabs;
        }

        public function accountMenu() {
            if ( ! function_exists( 'WC' ) ) {
                return;
            }

            add_filter( 'wp_get_nav_menu_items', function ( $items, $menu ) {
                if ( ! in_array( $menu->slug, [ 'my-account', 'login' ] ) ) {
                    return $items;
                }
                $parent = 0;
                $order  = 1;
                if ( $menu->slug == 'login' ) {
                    if ( is_user_logged_in() ) {
                        $items[] = $this->createNavMenuItem( '<span class="text">My Account</span>', '#', 1 );
                        $parent  = $items[ key( $items ) ]->ID;
                        $order ++;
                    } else {
                        $iconSettings = [
                            'value'   => 'fa-user',
                            'library' => 'fa-solid'
                        ];
                        // If not logged in then just return the one item
                        $title = '<span class="text">Login</span>';
                        if ( \ElementorPro\Plugin::elementor()->experiments->is_feature_active( 'e_font_icon_svg' ) ) {
                            $iconSettings['value'] = str_replace( 'fa-', 'fas-', $iconSettings['value'] );
                            // TODO: This is needed for the cart icon until Elementor fixes the bug wp_enqueue_style( 'elementor-icons' );
                            $icon = \Elementor\Icons_Manager::render_font_icon( $iconSettings );
                        } else {
                            $icon = '<i class="fas ' . $iconSettings['value'] . '"></i>';
                        }
                        if ( $icon ) {
                            $title .= '<span class="sub-arrow">' . $icon . '</span>';
                        }
                        $items[] = $this->createNavMenuItem( $title, wc_get_page_permalink( 'myaccount' ), 1 );

                        return $items;
                    }
                }
                $menuItems    = wc_get_account_menu_items();
                $myAccountUrl = wc_get_page_permalink( 'myaccount' );
                foreach ( $menuItems as $endpoint => $menuLabel ) {
                    $menuUrl = wc_get_endpoint_url( $endpoint, '', $myAccountUrl );
                    if ( $endpoint == 'customer-logout' ) {
                        $menuUrl .= '?customer-logout=true';
                    } elseif ( $endpoint == 'dashboard' ) {
                        $menuUrl = $myAccountUrl;
                    }
                    $items[] = $this->createNavMenuItem( $menuLabel, $menuUrl, $order, $parent );
                    $order ++;
                }

                return $items;
            }, 20, 2 );
        }

        private function createNavMenuItem( $title, $url, $order, $parent = 0 ): stdClass {
            $item                   = new stdClass();
            $item->ID               = 1000000 + $order + $parent;
            $item->db_id            = $item->ID;
            $item->title            = $title;
            $item->url              = $url;
            $item->menu_order       = $order;
            $item->menu_item_parent = $parent;
            $item->type             = '';
            $item->object           = '';
            $item->object_id        = '';
            $item->classes          = array();
            $item->target           = '';
            $item->attr_title       = '';
            $item->description      = '';
            $item->xfn              = '';
            $item->status           = '';

            return $item;
        }

        /**
         * Removes the total products available for a given category
         *
         * @return void;
         * @version 0.4.0.17.04.20
         * @author Nigel Wells
         */
        public function disableCategoryProductCount() {
        }

        /**
         * Removes the list of categories a product is in on the single product page
         *
         * @return void;
         * @version 0.4.0.17.04.21
         * @author Nigel Wells
         */
        public function disableProductCategoryList() {
            remove_action( 'woocommerce_single_product_summary', 'woocommerce_template_single_meta', 40 );

            return;
        }

        public function enhanceJupiterWooCommerce() {
            // Create settings
            $this->Toolbox->addSetting( array(
                'name'        => 'wc_shipping_text',
                'label'       => 'Shipping and Delivery text',
                'type'        => 'textarea',
                'value'       => $this->Toolbox->getOption( 'wc_shipping_text' ),
                'description' => 'HTML text to show above the table of shipping options available'
            ), $this->getLabel() );
            $this->Toolbox->addSetting( array(
                'name'        => 'wc_hide_shipping_table',
                'label'       => 'Shipping Table Visibility',
                'type'        => 'checkbox',
                'range'       => [ 1 => 'Yes' ],
                'value'       => $this->Toolbox->getOption( 'wc_hide_shipping_table' ),
                'description' => 'Hide the shipping table options'
            ), $this->getLabel() );

            if ( ! function_exists( 'WC' ) ) {
                return;
            }

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
                if ( ! function_exists( 'WC' ) || empty( WC()->cart ) ) {
                    return $classes;
                }
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
                if ( wc_coupons_enabled() ) {
                    ?>
                    <tr class="order-coupon">
                        <td colspan="2">
                            <form class="woocommerce-cart-form"
                                  action="<?php echo wc_get_cart_url(); ?>"
                                  method="post">
                                <?php printf( '<h4 class="mk-coupon-title"><a href="javascript:;" onclick="jQuery(\'#coupon_toggle\').slideToggle();">%s</a></h4>', __( 'Do you have a coupon code?', 'mk_framework' ) ); ?>
                                <div id="coupon_toggle" class="coupon" style="display: none;">
                                    <input type="text" name="coupon_code" class="input-text"
                                           id="coupon_code"
                                           value=""
                                           placeholder="<?php esc_attr_e( 'Coupon code', 'woocommerce' ); ?>" />
                                    <input type="submit" class="button"
                                           name="apply_coupon"
                                           value="<?php esc_attr_e( 'Apply coupon', 'woocommerce' ); ?>" />
                                    <?php do_action( 'woocommerce_cart_coupon' ); ?>
                                </div>
                            </form>
                        </td>
                    </tr>
                    <?php
                }
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
            $filter = false;
            if ( is_product_category() && $this->checkProductsDisplaying() ) {
                $filter = true;
            }

            return apply_filters( 'apex_toolbox_show_shop_filter', $filter );
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

        public function enableProductFiltersRelevanssiCompatability( $search_ok, $query ) {
            if ( $search_ok ) {
                return true;
            }

            if ( ! empty( $query->query['prdctfltr_active'] ) ) {
                // Use Relevanssi's count for found product count rather than the WP computed one which is usually incorrect.
                $query->query_vars['no_found_rows'] = true;

                return true;
            }

            return false;
        }

        /**
         * Safari, unlike other browsers, fires a "popstate" event when doing user navigation
         * that results in a page load. The XForWooCommerce Product Filters plugin listens for popstate events
         * to handle navigation of filter states without page loads and on this inital event, despite not
         * needing to navigate state, scrolls to the top anyway.
         *
         * The fix for this below is to swallow any popstate events that occur during the pageshow event.
         * We cannot however always swallow the first popstate event as on browsers which do not share Safaris
         * behaviour this event will be caused by a legitimate user interaction.
         */
        public function disableProductFiltersScrollOnSafariBackNavigation() {
            ?>
            <script>
                let withinPageShowEvent = false;

                window.addEventListener("pageshow", () => {
                    withinPageShowEvent = true;

                    // Push this to the back of the event loop queue to execute once the pageshow event ends
                    setTimeout(() => {
                        withinPageShowEvent = false;
                    }, 0);
                });

                window.addEventListener("popstate", (e) => {
                    if (withinPageShowEvent && e.state.filters !== undefined) {
                        e.stopImmediatePropagation();
                    }
                });
            </script>
            <?php
        }

        public function disableAdjustDownloadPermissionsSchedulingForNonDownloadableProducts( $productId, $product ) {
            // If the product is downloadable we do not want to unschedule the task
            if ( $product->is_downloadable() ) {
                return;
            }

            // If any of the products children are downloadable we do not want to unschedule the task
            foreach ( $product->get_children() as $child_id ) {
                $child = wc_get_product( $child_id );
                if ( $child && $child->is_downloadable() ) {
                    return;
                }
            }

            $actionId = as_unschedule_action(
                'adjust_download_permissions',
                array( $product->get_id() ),
            );

            if ( $actionId ) {
                ActionScheduler_Store::instance()->delete_action( $actionId );
            }
        }
    }
}
