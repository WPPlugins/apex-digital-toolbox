<?php
// Forbid accessing directly
if ( ! defined( 'ABSPATH' ) ) {
	header( 'HTTP/1.1 401 Unauthorized' );
	exit;
}
// Ensure there are no conflicts
if ( ! class_exists( 'toolboxHookSiteWide' ) ) {
	class toolboxHookSiteWide extends toolboxHookController {
		private $_production = true;

		function __construct( $Toolbox ) {
			parent::__construct( $Toolbox );
			$this->setLabel( 'Site Wide' );
			$this->addHook( APEX_TOOLBOX_HOOK_ACTION, 'admin_init', 'addSettings', Array(
				'label'       => 'Production/Staging',
				'description' => 'Allows the ability to define the production URL for the installation. Once set the site can offer restrictions via IP or cookie to the staging site.'
			) );
			$this->addHook( APEX_TOOLBOX_HOOK_ACTION, 'init', 'isStagingAccessAuthorized', Array(
				'label'       => 'Website Restriction',
				'description' => 'If a site is in staging mode then a 401 will be returned unless the IP matches an authorized one or the cookie is in place.'
			) );
		}

		/**
		 * Add production url setting
		 *
		 * @param array $args Any arguments passed to the callback
		 *
		 * @author Nigel Wells
		 * @version 0.3.4.16.10.31
		 * @return void;
		 */
		public function addSettings( $args = Array() ) {
			// Setup the new field details
			$description = 'Used to identify if this installation matches the main production URL. Allows for other triggers when viewing staging site compared to production sites.<br />
					Current installation is on: <strong>' . ( $this->onProduction() ? 'PRODUCTION' : 'STAGING' ) . '</strong>.';
			// Show URL to allow cookie access for staging sites
			if ( ! $this->onProduction() && $this->Toolbox->isHookEnabled( 'site_wide', 'isStagingAccessAuthorized' ) ) {
				$description .= '<br />Cookie access to the site: ' . $this->getStagingCookieURL();
			}
			$this->Toolbox->addSetting( Array(
				'name'        => 'production_url',
				'label'       => 'Production URL',
				'type'        => 'url',
				'value'       => $this->Toolbox->getOption( 'production_url' ),
				'description' => $description
			), $this->getLabel() );
			if ( $this->Toolbox->isHookEnabled( 'site_wide', 'isStagingAccessAuthorized' ) ) {
				$allowed_staging_ip = $this->Toolbox->getOption( 'allowed_staging_ip' );
				// Default to current IP if nothing has been set
				if ( ! $allowed_staging_ip && isset( $_SERVER['REMOTE_ADDR'] ) ) {
					$allowed_staging_ip = $_SERVER['REMOTE_ADDR'];
					$this->sendFirstTimeStagingEmailSetup( $allowed_staging_ip );
				}
				$this->Toolbox->addSetting( Array(
					'name'        => 'allowed_staging_ip',
					'label'       => 'Allowed Staging IPs',
					'type'        => 'text',
					'value'       => $allowed_staging_ip,
					'description' => 'Enter the IP addresses (comma separated) that are allowed access to the staging site without needing the cookie authenticated.'
				), $this->getLabel() );
			}
		}

		/**
		 * Checks to see if this installation is running on the production URL or if it is on a staging site
		 *
		 * @author Nigel Wells
		 * @version 0.02.1.16.10.07
		 * @return void;
		 */
		private function checkIfProduction() {
			if ( ! $this->Toolbox->getOption( 'production_url' ) || ! $this->Toolbox->isHookEnabled( 'site_wide', 'addSettings' ) ) {
				$production = true;
			} else {
				// Check if in staging or production
				if ( getDomain( get_site_url() ) == getDomain( $this->Toolbox->getOption( 'production_url' ) ) ) {
					$production = true;
				} else {
					$production = false;
				}
			}
			$this->_production = $production;
		}

		/**
		 * Is the site being viewed on the production server or not
		 *
		 * @author Nigel Wells
		 * @version 0.2.1.16.10.07
		 * @return boolean
		 */
		function onProduction() {
			$this->checkIfProduction();

			return $this->_production;
		}

		/**
		 * Checks to see if this access is permitted to the staging installation. Delegates the
		 * pure decision to getStagingAccessDecision() and translates the result into header/echo/exit.
		 *
		 * @author Nigel Wells
		 * @return void
		 */
		public function isStagingAccessAuthorized( $args = Array() ) {
			$decision = $this->getStagingAccessDecision();
			if ( $decision['kind'] === 'allow' ) {
				return;
			}
			if ( $decision['kind'] === 'set_cookie_and_redirect' ) {
				setcookie( 'apex_toolbox_staging', $decision['cookie_value'], strtotime( '+1 month' ) );
				header( 'Location: /' );
				exit;
			}
			// 'deny'
			$this->logStagingAccessDenied( $decision['remote_addr'] ?? null );
			header( 'HTTP/1.1 401 Unauthorized' );
			echo $this->getStagingDeniedHtml( $decision['remote_addr'] ?? null );
			exit;
		}

		/**
		 * Write a line to the PHP error log for each unauthorized staging access attempt so
		 * site admins can review who tried to reach the site and what they were after.
		 */
		public function logStagingAccessDenied( ?string $remote_addr ): void {
			error_log( $this->buildStagingAccessDeniedLogMessage( $remote_addr ) );
		}

		/**
		 * Pure formatter for the error_log line. Split out so tests can assert the exact format
		 * without intercepting error_log itself.
		 */
		public function buildStagingAccessDeniedLogMessage( ?string $remote_addr ): string {
			$ip         = $remote_addr ?: 'unknown';
			$request    = $_SERVER['REQUEST_URI'] ?? 'unknown';
			$user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
			return sprintf( '[Apex Staging Lock] Denied access from IP %s to %s (UA: %s)', $ip, $request, $user_agent );
		}

		/**
		 * Pure decision logic for staging access. Returns one of:
		 *  - ['kind' => 'allow']                                                       on production, or any authorize branch
		 *  - ['kind' => 'set_cookie_and_redirect', 'cookie_value' => string]           valid ?request=staging&code=... pair
		 *  - ['kind' => 'deny', 'remote_addr' => string|null]                          unauthorized; remote_addr is for the rendered 401 page
		 *
		 * @return array
		 */
		public function getStagingAccessDecision(): array {
			$this->checkIfProduction();
			if ( $this->onProduction() ) {
				return array( 'kind' => 'allow' );
			}
			if ( is_user_logged_in() ) {
				return array( 'kind' => 'allow' );
			}
			if ( ! empty( $_SERVER['DDEV_PROJECT_TYPE'] ) && $_SERVER['DDEV_PROJECT_TYPE'] === 'wordpress' ) {
				return array( 'kind' => 'allow' );
			}
			if ( isset( $GLOBALS['pagenow'] ) && $GLOBALS['pagenow'] === 'wp-login.php' ) {
				return array( 'kind' => 'allow' );
			}
			if ( isset( $GLOBALS['pagenow'] ) && $GLOBALS['pagenow'] === 'wp-cron.php' ) {
				return array( 'kind' => 'allow' );
			}
			if ( $this->isAdmin() ) {
				return array( 'kind' => 'allow' );
			}
			// Allowed IP list from settings
			$allowed_setting = $this->Toolbox->getOption( 'allowed_staging_ip' );
			if ( $allowed_setting ) {
				$allowed = array_map( 'trim', explode( ',', $allowed_setting ) );
			} else {
				$allowed = array();
			}
			if ( isset( $_SERVER['REMOTE_ADDR'] ) ) {
				foreach ( $allowed as $ip ) {
					if ( $ip !== '' && strpos( $_SERVER['REMOTE_ADDR'], $ip ) !== false ) {
						return array( 'kind' => 'allow' );
					}
				}
			}
			// Cookie check; elseif preserved from original - if cookie is set but wrong, the GET path doesn't run.
			if ( isset( $_COOKIE['apex_toolbox_staging'] ) ) {
				if ( $_COOKIE['apex_toolbox_staging'] == $this->getStagingCookie() ) {
					return array( 'kind' => 'allow' );
				}
			} elseif ( isset( $_GET['request'], $_GET['code'] ) && $_GET['request'] === 'staging' ) {
				if ( $_GET['code'] === md5( AUTH_KEY ) ) {
					return array(
						'kind'         => 'set_cookie_and_redirect',
						'cookie_value' => $_GET['code'],
					);
				}
			}
			// Fall-through: if no IPs are configured at all, don't lock the site out
			if ( ! count( $allowed ) ) {
				return array( 'kind' => 'allow' );
			}
			return array(
				'kind'        => 'deny',
				'remote_addr' => $_SERVER['REMOTE_ADDR'] ?? null,
			);
		}

		/**
		 * 401 page body for the deny branch.
		 */
		protected function getStagingDeniedHtml( ?string $remote_addr ): string {
			$ip_display = $remote_addr !== null && $remote_addr !== '' ? esc_html( $remote_addr ) : 'unknown';
			$ip_line    = '<p>Your IP is logged as: <b>' . $ip_display . '</b></p>';
			return '<html>
<head>
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<style>
body,h1,p {
	font-family: Verdana, Arial;
}
.face {
	font-size: 136px;
	font-family: "Times New Roman";
	font-weight: bold;
	margin: 0;
	padding: 0 0 10px;
}
</style>
</head>
<body>
<p class="face">:(</p>
<h1>You are unauthorized to view this website</h1>
<p>This site is in development mode and authorization is required to view it</p>
<p>If you feel this been a mistake please contact your website administrator for the process to get authorized</p>
' . $ip_line . '
</body>
</html>';
		}

		/**
		 * Wrapper around is_admin() so tests can substitute it via a subclass.
		 */
		protected function isAdmin(): bool {
			return is_admin();
		}

		/**
		 * Sends out an email to the currently logged in user notifying them of the staging code as well as adding their IP to the allowed list
		 *
		 * @param string $allowed_staging_ip IP to set as allowed to view the site without needing the cookie code
		 *
		 * @author Nigel Wells
		 * @version 0.3.4.16.10.31
		 * @return void;
		 */
		private function sendFirstTimeStagingEmailSetup( $allowed_staging_ip ) {
			// Save the IP as an option
			$this->Toolbox->setOption( 'allowed_staging_ip', $allowed_staging_ip );
			// Send email out with the cookie address for safe keeping
			$current_user = wp_get_current_user();
			$to           = $current_user->user_email;
			$subject      = getDomain( get_bloginfo( 'url' ) ) . ': Cookie for staging access';
			$body         = 'Here is the staging cookie URL if you ever need to gain access to the site and your IP is not valid: ' . $this->getStagingCookieUrl() . '. Keep this email especially if you\'re running the site off a dynamic IP.';

			wp_mail( $to, $subject, $body );
		}

		/**
		 * Get the full URL that includes the cookie code - used to access the site when in staging mode
		 *
		 * @author Nigel Wells
		 * @version 0.3.4.16.10.31
		 * @return string;
		 */
		private function getStagingCookieUrl() {
			return get_site_url() . '?request=staging&code=' . $this->getStagingCookie();
		}

		/**
		 * Get the staging cookie code to be used when in staging mode
		 *
		 * @author Nigel Wells
		 * @version 0.3.4.16.10.31
		 * @return string;
		 */
		private function getStagingCookie() {
			return md5( AUTH_KEY );
		}
	}

}