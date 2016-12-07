<?php
// Forbid accessing directly
if ( ! defined( 'ABSPATH' ) ) {
	header( 'HTTP/1.1 401 Unauthorized' );
	exit;
}
// Ensure there are no conflicts
if ( ! class_exists( 'toolboxHookWordPressFront' ) ) {
	class toolboxHookWordPressFront extends toolboxHookController {

		function __construct( $Toolbox ) {
			parent::__construct( $Toolbox );
			$this->setLabel( 'WordPress Frontend' );
			$this->addHook( APEX_TOOLBOX_HOOK_FILTER, 'body_class', 'deviceDetection', Array(
				'label'       => 'Device Detection',
				'description' => 'Add a class to the body tag of every page containing the OS and device specific tag if available i.e ios ipad. Very useful if you need to target specific devices with CSS.'
			) );
			$this->addHook( APEX_TOOLBOX_HOOK_ACTION, 'init', 'fixDomainRedirects', Array(
				'label'       => 'Fix Domain Redirects',
				'description' => 'Fix Domain Redirects when the website is being used on a domain that doesn\'t match the one entered in WordPress general settings. Note: This won\'t affect the WordPress administration area as you need to be able to change the URL if you are migrating sites or changing domains.'
			) );
		}

		/**
		 * Detect user device and OS based off their user agent string
		 * Script adapted from: http://www.schiffner.com/code-snippets/php-mobile-device-detection/
		 *
		 * @param array $classes Classes to be set in the opening body tag
		 *
		 * @author Nigel Wells
		 * @version 0.3.6.16.11.01
		 * @return array;
		 */
		public function deviceDetection( $classes = Array() ) {
			//initialize all known devices as false
			$iPod            = false;
			$iPhone          = false;
			$iPad            = false;
			$iOS             = false;
			$webOSPhone      = false;
			$webOSTablet     = false;
			$webOS           = false;
			$BlackBerry9down = false;
			$BlackBerry10    = false;
			$RimTablet       = false;
			$BlackBerry      = false;
			$NokiaSymbian    = false;
			$Symbian         = false;
			$Mac             = false;
			$AndroidTablet   = false;
			$AndroidPhone    = false;
			$Android         = false;
			$WindowsPhone    = false;
			$WindowsTablet   = false;
			$Windows         = false;
			$Tablet          = false;
			$Phone           = false;

			//Detect special conditions devices & types (tablet/phone form factor)
			if ( stripos( $_SERVER['HTTP_USER_AGENT'], "iPod" ) ) {
				$iPod  = true;
				$Phone = true;
				$iOS   = true;
			}
			if ( stripos( $_SERVER['HTTP_USER_AGENT'], "iPhone" ) ) {
				$iPhone = true;
				$Phone  = true;
				$iOS    = true;
			}
			if ( stripos( $_SERVER['HTTP_USER_AGENT'], "iPad" ) ) {
				$iPad   = true;
				$Tablet = true;
				$iOS    = true;
			}
			if ( stripos( $_SERVER['HTTP_USER_AGENT'], "webOS" ) ) {
				$webOS = true;
				if ( stripos( $_SERVER['HTTP_USER_AGENT'], "Pre" ) || stripos( $_SERVER['HTTP_USER_AGENT'], "Pixi" ) ) {
					$webOSPhone = true;
					$Phone      = true;
				}
				if ( stripos( $_SERVER['HTTP_USER_AGENT'], "TouchPad" ) ) {
					$webOSTablet = true;
					$Tablet      = true;
				}
			}
			if ( stripos( $_SERVER['HTTP_USER_AGENT'], "BlackBerry" ) ) {
				$BlackBerry      = true;
				$BlackBerry9down = true;
				$Phone           = true;
			}
			if ( stripos( $_SERVER['HTTP_USER_AGENT'], "BB10" ) ) {
				$BlackBerry   = true;
				$BlackBerry10 = true;
				$Phone        = true;
			}
			if ( stripos( $_SERVER['HTTP_USER_AGENT'], "RIM Tablet" ) ) {
				$BlackBerry = true;
				$RimTablet  = true;
				$Tablet     = true;
			}
			if ( stripos( $_SERVER['HTTP_USER_AGENT'], "SymbianOS" ) ) {
				$Symbian      = true;
				$NokiaSymbian = true;
				$Phone        = true;
			}
			if ( stripos( $_SERVER['HTTP_USER_AGENT'], "Android" ) ) {
				$Android = true;
				if ( stripos( $_SERVER['HTTP_USER_AGENT'], "mobile" ) ) {
					$AndroidPhone = true;
					$Phone        = true;
				} else {
					$AndroidTablet = true;
					$Tablet        = true;
				}
			}
			if ( stripos( $_SERVER['HTTP_USER_AGENT'], "Windows" ) ) {
				$Windows = true;
				if ( stripos( $_SERVER['HTTP_USER_AGENT'], "Touch" ) ) {
					$WindowsTablet = true;
					$Tablet        = true;
				}
				if ( stripos( $_SERVER['HTTP_USER_AGENT'], "Windows Phone" ) ) {
					$WindowsPhone = true;
					$Phone        = true;
				}
			}
			if ( stripos( $_SERVER['HTTP_USER_AGENT'], "Mac OS" ) ) {
				$Mac = true;
			}

			// Target form factors
			if ( $Phone ) {
				$classes[] = 'phone';
			} else if ( $Tablet ) {
				$classes[] = 'tablet';
			} else {
				$classes[] = 'desktop';
			}

			// Target operating systems
			if ( $iOS ) {
				$classes[] = 'ios';
			} else if ( $Android ) {
				$classes[] = 'android';
			} else if ( $Windows ) {
				$classes[] = 'windows';
			} else if ( $BlackBerry ) {
				$classes[] = 'blackberry';
			} else if ( $webOS ) {
				$classes[] = 'webos';
			} else if ( $Symbian ) {
				$classes[] = 'symbian';
			} else if ( $Mac ) {
				$classes[] = 'macos';
			} else {
			}

			//Target individual devices
			if ( $iPod || $iPhone ) {
				$classes[] = 'iphone';
			} else if ( $iPad ) {
				$classes[] = 'ipad';
			} else if ( $AndroidPhone ) {
				//we're an Android Phone -- do something here
			} else if ( $AndroidTablet ) {
				//we're an Android Tablet -- do something here
			} else if ( $WindowsPhone ) {
				//we're an Windows Phone -- do something here
			} else if ( $WindowsTablet ) {
				//we're an Windows Tablet -- do something here
			} else if ( $webOSPhone ) {
				//we're a webOS phone -- do something here
			} else if ( $webOSTablet ) {
				//we're a webOS tablet -- do something here
			} else if ( $BlackBerry9down ) {
				//we're an outdated BlackBerry phone -- do something here
			} else if ( $BlackBerry10 ) {
				//we're an new BlackBerry phone -- do something here
			} else if ( $RimTablet ) {
				//we're a RIM/BlackBerry Tablet -- do something here
			} else if ( $NokiaSymbian ) {
				//we're a Nokia Symbian device -- do something here
			} else {
				//we're not a known device.
			}

			// Return the updated classes list
			return $classes;
		}

		/**
		 * Fix Domain Redirects when the website is being used on a domain that does not match the one entered in WordPress general settings
		 * Note: This won't affect the WordPress administration area as you need to be able to change the URL if you are migrating sites or changing domains.
		 *
		 * @param array $args Any arguments passed to the callback
		 *
		 * @author Nigel Wells
		 * @version 0.3.6.16.11.02
		 * @return void;
		 */
		public function fixDomainRedirects( $args = Array() ) {
			// Only do this outside of the admin area
			if ( ! is_admin() ) {
				$siteURL = esc_url(get_option( 'home' ));
				if ( substr( esc_url($_SERVER['SCRIPT_URI']), 0, strlen( $siteURL ) ) != $siteURL ) {
					header( "HTTP/1.1 301 Moved Permanently" );
					header( 'Location: ' . $siteURL . $_SERVER['REQUEST_URI'] );
					die();
				}
			}
		}
	}
}