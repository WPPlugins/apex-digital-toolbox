<?php

// Forbid accessing directly
if (! defined('ABSPATH')) {
    header('HTTP/1.1 401 Unauthorized');
    exit;
}
// Ensure there are no conflicts
if (! class_exists('toolboxHookSiteWide')) {
    class toolboxHookSiteWide extends toolboxHookController
    {
        /**
         * Fired with the wp_mail() $atts when an email is blocked for having no recipient.
         * A site can log, count or alert on it. The block itself already wrote to the PHP error log.
         */
        public const BLOCKED_MAIL_ACTION = 'apex_toolbox_blocked_mail_without_recipient';

        /**
         * Late on purpose. pre_wp_mail passes the value the previous callback returned, and a
         * non-null value means another plugin already decided. Running late is what lets us see
         * that decision and leave it alone. FluentSMTP's wp_mail() replacement applies this filter
         * and returns before it logs, so a false here never becomes a failed-log row.
         */
        public const BLOCK_MAIL_PRIORITY = 999;

        private $_production = true;

        function __construct($Toolbox)
        {
            parent::__construct($Toolbox);

            $this->setLabel('Site Wide');
            $this->addHook(APEX_TOOLBOX_HOOK_ACTION, 'admin_init', 'addSettings', array(
                'label' => 'Production/Staging',
                'description' => 'Allows the ability to define the production URL for the installation. Once set the site can offer restrictions via IP or cookie to the staging site.',
                'force' => true,
            ));
            $this->addHook(APEX_TOOLBOX_HOOK_ACTION, 'init', 'isStagingAccessAuthorized', array(
                'label' => 'Website Restriction',
                'description' => 'If a site is in staging mode then a 401 will be returned unless the IP matches an authorized one or the cookie is in place.',
            ));
            $this->addHook(APEX_TOOLBOX_HOOK_FILTER, 'pre_wp_mail', 'blockMailWithoutRecipients', array(
                'label' => 'Block emails with no recipient',
                'description' => 'Stops an email when there is no address to send it to.',
                'priority' => self::BLOCK_MAIL_PRIORITY,
                'args' => 2,
                'force' => true,
            ));
        }

        /**
         * Add production url setting
         *
         * @param array $args Any arguments passed to the callback
         * @author Nigel Wells
         * @version 0.3.4.16.10.31
         * @return void;
         */
        public function addSettings($args = array())
        {
            // Setup the new field details
            $description = 'Used to identify if this installation matches the main production URL. Allows for other triggers when viewing staging site compared to production sites.<br />
					Current installation is on: <strong>' . ( $this->onProduction() ? 'PRODUCTION' : 'STAGING' ) . '</strong>.';
            // Show URL to allow cookie access for staging sites
            if (! $this->onProduction() && $this->Toolbox->isHookEnabled('site_wide', 'isStagingAccessAuthorized')) {
                $description .= '<br />Cookie access to the site: ' . $this->getStagingCookieURL();
            }
            $this->Toolbox->addSetting(array(
                'name' => 'production_url',
                'label' => 'Production URL',
                'type' => 'url',
                'value' => $this->Toolbox->getOption('production_url'),
                'description' => $description,
            ), $this->getLabel());
            if ($this->Toolbox->isHookEnabled('site_wide', 'isStagingAccessAuthorized')) {
                $allowed_staging_ip = $this->Toolbox->getOption('allowed_staging_ip');
                // Default to current IP if nothing has been set
                if (! $allowed_staging_ip && isset($_SERVER['REMOTE_ADDR'])) {
                    $allowed_staging_ip = $_SERVER['REMOTE_ADDR'];
                    $this->sendFirstTimeStagingEmailSetup($allowed_staging_ip);
                }
                $this->Toolbox->addSetting(array(
                    'name' => 'allowed_staging_ip',
                    'label' => 'Allowed Staging IPs',
                    'type' => 'text',
                    'value' => $allowed_staging_ip,
                    'description' => 'Enter the IP addresses (comma separated) that are allowed access to the staging site without needing the cookie authenticated.',
                ), $this->getLabel());
            }
        }

        /**
         * Is the site being viewed on the production server or not
         *
         * @author Nigel Wells
         * @version 0.2.1.16.10.07
         * @return bool
         */
        function onProduction()
        {
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
        public function isStagingAccessAuthorized($args = array())
        {
            $decision = $this->getStagingAccessDecision();
            if ($decision['kind'] === 'allow') {
                return;
            }
            if ($decision['kind'] === 'set_cookie_and_redirect') {
                setcookie('apex_toolbox_staging', $decision['cookie_value'], strtotime('+1 month'));
                header('Location: /');
                exit;
            }
            // 'deny'
            $this->logStagingAccessDenied($decision['remote_addr'] ?? null);
            header('HTTP/1.1 401 Unauthorized');
            echo $this->getStagingDeniedHtml($decision['remote_addr'] ?? null);
            exit;
        }

        /**
         * Write a line to the PHP error log for each unauthorized staging access attempt so
         * site admins can review who tried to reach the site and what they were after.
         */
        public function logStagingAccessDenied(?string $remote_addr): void
        {
            error_log($this->buildStagingAccessDeniedLogMessage($remote_addr));
        }

        /**
         * Pure formatter for the error_log line. Split out so tests can assert the exact format
         * without intercepting error_log itself.
         */
        public function buildStagingAccessDeniedLogMessage(?string $remote_addr): string
        {
            $ip = $remote_addr
                ? $remote_addr
                : 'unknown';
            $request = $_SERVER['REQUEST_URI'] ?? 'unknown';
            $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';

            return sprintf('[Apex Staging Lock] Denied access from IP %s to %s (UA: %s)', $ip, $request, $user_agent);
        }

        /**
         * Pure decision logic for staging access. Returns one of:
         *  - ['kind' => 'allow'] on production, or any authorize branch
         *  - ['kind' => 'set_cookie_and_redirect', 'cookie_value' => string] valid ?request=staging&code=... pair
         *  - ['kind' => 'deny', 'remote_addr' => string|null] unauthorized; remote_addr is for the rendered 401 page
         *
         * @return array
         */
        public function getStagingAccessDecision(): array
        {
            $this->checkIfProduction();
            if ($this->onProduction()) {
                return array('kind' => 'allow');
            }
            if (is_user_logged_in()) {
                return array('kind' => 'allow');
            }
            if (! empty($_SERVER['DDEV_PROJECT_TYPE']) && $_SERVER['DDEV_PROJECT_TYPE'] === 'wordpress') {
                return array('kind' => 'allow');
            }
            if (isset($GLOBALS['pagenow']) && $GLOBALS['pagenow'] === 'wp-login.php') {
                return array('kind' => 'allow');
            }
            if (isset($GLOBALS['pagenow']) && $GLOBALS['pagenow'] === 'wp-cron.php') {
                return array('kind' => 'allow');
            }
            if ($this->isAdmin()) {
                return array('kind' => 'allow');
            }
            // Allowed IP list from settings
            $allowed_setting = $this->Toolbox->getOption('allowed_staging_ip');
            if ($allowed_setting) {
                $allowed = array_map('trim', explode(',', $allowed_setting));
            } else {
                $allowed = array();
            }
            if (isset($_SERVER['REMOTE_ADDR'])) {
                foreach ($allowed as $ip) {
                    if ($ip !== '' && strpos($_SERVER['REMOTE_ADDR'], $ip) !== false) {
                        return array('kind' => 'allow');
                    }
                }
            }
            // Cookie check; elseif preserved from original - if cookie is set but wrong, the GET path doesn't run.
            if (isset($_COOKIE['apex_toolbox_staging'])) {
                if ($_COOKIE['apex_toolbox_staging'] == $this->getStagingCookie()) {
                    return array('kind' => 'allow');
                }
            } elseif (isset($_GET['request'], $_GET['code']) && $_GET['request'] === 'staging') {
                if ($_GET['code'] === md5(AUTH_KEY)) {
                    return array(
                        'kind' => 'set_cookie_and_redirect',
                        'cookie_value' => $_GET['code'],
                    );
                }
            }
            // Fall-through: if no IPs are configured at all, don't lock the site out
            if (! count($allowed)) {
                return array('kind' => 'allow');
            }

            return array(
                'kind' => 'deny',
                'remote_addr' => $_SERVER['REMOTE_ADDR'] ?? null,
            );
        }

        /**
         * Short-circuit wp_mail() when nobody would receive the message.
         *
         * A non-null $return is another plugin's decision and is passed through untouched. Returning
         * false is what stops the send: core and FluentSMTP both honour pre_wp_mail before the
         * message is handed to PHPMailer, which is before FluentSMTP writes a failed log row.
         *
         * @param mixed $return Null when nobody has short-circuited the send yet.
         * @param mixed $atts wp_mail() arguments: to, subject, message, headers, attachments.
         * @return mixed False when this hook blocks the send, otherwise $return unchanged.
         */
        public function blockMailWithoutRecipients($return, $atts = array())
        {
            if ($return !== null) {
                return $return;
            }

            if (! is_array($atts) || ! class_exists(\ApexToolbox\Mail\MailRecipients::class)) {
                return $return;
            }

            if (\ApexToolbox\Mail\MailRecipients::hasDeliverableRecipient($atts)) {
                return $return;
            }

            $this->logBlockedMailWithoutRecipient($atts);
            do_action(self::BLOCKED_MAIL_ACTION, $atts);

            return false;
        }

        /**
         * Write one line to the PHP error log for each email blocked because it had no recipient.
         *
         * The line carries the subject plus a hint of where the send came from: the calling
         * plugin or function, and the first 60 characters of the message. The rest of the body
         * stays out of the log.
         *
         * @param array<string, mixed> $atts
         */
        public function logBlockedMailWithoutRecipient(array $atts): void
        {
            $subject = $atts['subject'] ?? '';

            if (! is_string($subject)) {
                $subject = '';
            }

            error_log($this->buildBlockedMailLogMessage(
                $subject,
                $this->callerFromBacktrace(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 30)),
                $this->blockedMailExcerpt($atts),
            ));
        }

        /**
         * Pure formatter for the error_log line. Subject, source and excerpt are each one line.
         */
        public function buildBlockedMailLogMessage(string $subject, string $source = '', string $excerpt = ''): string
        {
            $subject = $this->oneLine($subject, 200);

            if ($subject === '') {
                $subject = '(no subject)';
            }

            $line = '[Apex Toolbox] Blocked outgoing email with no valid recipient. Subject: ' . $subject;
            $source = $this->oneLine($source, 120);

            if ($source !== '') {
                $line .= '. Source: ' . $source;
            }

            $excerpt = $this->oneLine($excerpt, 60);

            if ($excerpt !== '') {
                $line .= '. Message: ' . $excerpt;
            }

            return $line;
        }

        /**
         * The first caller that is not this hook and not WordPress's own mail plumbing.
         *
         * @param list<array<string, mixed>> $trace debug_backtrace() with arguments ignored.
         */
        public function callerFromBacktrace(array $trace): string
        {
            $fallback = '';

            foreach ($trace as $frame) {
                if (! is_array($frame)) {
                    continue;
                }

                $function = '';

                if (isset($frame['function']) && is_string($frame['function'])) {
                    $function = $frame['function'];
                }

                $class = '';

                if (isset($frame['class']) && is_string($frame['class'])) {
                    $class = $frame['class'];
                }

                $file = '';

                if (isset($frame['file']) && is_string($frame['file'])) {
                    $file = $frame['file'];
                }

                if ($class === self::class || $function === '' || strpos($function, '{closure}') !== false) {
                    continue;
                }

                $plumbing = array('apply_filters', 'apply_filters_ref_array', 'wp_mail', 'fluentMailSend');

                if (in_array($function, $plumbing, true)) {
                    continue;
                }

                $normalized = str_replace('\\', '/', $file);

                if (strpos($normalized, '/wp-includes/') !== false || strpos($normalized, '/wp-admin/') !== false) {
                    continue;
                }

                $callable = $function;

                if ($class !== '') {
                    $callable = $class . '::' . $function;
                }

                $where = '';

                if (preg_match('#/(?:plugins|themes)/([^/]+)/#', $normalized, $match) === 1) {
                    $where = $match[1];
                }

                $hint = $callable;

                if ($where !== '') {
                    $hint = $where . ' ' . $callable;
                }

                if ($where !== '') {
                    return $hint;
                }

                if ($fallback === '') {
                    $fallback = $hint;
                }
            }

            return $fallback;
        }

        /**
         * 401 page body for the deny branch.
         */
        protected function getStagingDeniedHtml(?string $remote_addr): string
        {
            $ip_display = $remote_addr !== null && $remote_addr !== ''
                ? esc_html($remote_addr)
                : 'unknown';
            $ip_line = '<p>Your IP is logged as: <b>' . $ip_display . '</b></p>';

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
        protected function isAdmin(): bool
        {
            return is_admin();
        }

        /**
         * Checks to see if this installation is running on the production URL or if it is on a staging site
         *
         * @author Nigel Wells
         * @version 0.02.1.16.10.07
         * @return void;
         */
        private function checkIfProduction()
        {
            if (
                ! $this->Toolbox->getOption('production_url')
                || ! $this->Toolbox->isHookEnabled('site_wide', 'addSettings')
            ) {
                $production = true;
            } else {
                // Check if in staging or production
                if (getDomain(get_site_url()) == getDomain($this->Toolbox->getOption('production_url'))) {
                    $production = true;
                } else {
                    $production = false;
                }
            }
            $this->_production = $production;
        }

        /**
         * Sends out an email to the currently logged in user notifying them of the staging code as well as adding their IP to the allowed list
         *
         * @param string $allowed_staging_ip IP to set as allowed to view the site without needing the cookie code
         * @author Nigel Wells
         * @version 0.3.4.16.10.31
         * @return void;
         */
        private function sendFirstTimeStagingEmailSetup($allowed_staging_ip)
        {
            // Save the IP as an option
            $this->Toolbox->setOption('allowed_staging_ip', $allowed_staging_ip);
            // Send email out with the cookie address for safe keeping
            $current_user = wp_get_current_user();
            $to = $current_user->user_email;
            $subject = getDomain(get_bloginfo('url')) . ': Cookie for staging access';
            $body = 'Here is the staging cookie URL if you ever need to gain access to the site and your IP is not valid: ' . $this->getStagingCookieUrl() . '. Keep this email especially if you\'re running the site off a dynamic IP.';

            wp_mail($to, $subject, $body);
        }

        /**
         * Get the full URL that includes the cookie code - used to access the site when in staging mode
         *
         * @author Nigel Wells
         * @version 0.3.4.16.10.31
         * @return string;
         */
        private function getStagingCookieUrl()
        {
            return get_site_url() . '?request=staging&code=' . $this->getStagingCookie();
        }

        /**
         * Get the staging cookie code to be used when in staging mode
         *
         * @author Nigel Wells
         * @version 0.3.4.16.10.31
         * @return string;
         */
        private function getStagingCookie()
        {
            return md5(AUTH_KEY);
        }

        /**
         * The first 60 characters of the message, tags and line breaks removed.
         *
         * @param array<string, mixed> $atts
         */
        private function blockedMailExcerpt(array $atts): string
        {
            $message = $atts['message'] ?? '';

            if (! is_string($message)) {
                return '';
            }

            if (function_exists('wp_strip_all_tags')) {
                $message = wp_strip_all_tags($message);
            }

            return $this->oneLine($message, 60);
        }

        /**
         * One line, trimmed, and cut at $length. A cut line ends with an ellipsis.
         */
        private function oneLine(string $value, int $length): string
        {
            $value = trim(str_replace(array("\r", "\n", "\t"), ' ', $value));
            $collapsed = preg_replace('/ {2,}/', ' ', $value);

            if (is_string($collapsed)) {
                $value = $collapsed;
            }

            if (strlen($value) <= $length) {
                return $value;
            }

            return substr($value, 0, $length) . '...';
        }
    }

}
