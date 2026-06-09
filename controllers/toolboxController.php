<?php

// Forbid accessing directly
if (! defined('ABSPATH')) {
    header('HTTP/1.1 401 Unauthorized');
    exit;
}
// Ensure there are no conflicts
if (! class_exists('toolboxController')) {
    class toolboxController
    {
        private $_prefix = 'apex_toolbox_';
        private $_options = array();
        private $_path = '';
        private $_setting = array();
        private $_enabled = array();
        private $_objects = array();
        private $_forcedHooks = null;

        function __construct()
        {
            $file = dirname(__FILE__) . '/apex-digital-toolbox.php';
            $this->_path = plugin_dir_path($file) . '../';
            // Include any files
            $this->loadIncludeFiles();
            // Load options
            $this->loadOptions();
            // Register any WordPress hooks
            $this->registerHooks();
        }

        /**
         * Get a specific options value for the plugin
         *
         * @param string $option Option to get the value from the plugin
         * @author Nigel Wells
         * @return mixed;
         */
        public function getOption($option)
        {
            $value = ( $this->_options[$option] ?? null );
            // If nothing was found then it might not be a default one so check with WordPress
            if ($value === null) {
                $value = get_option($this->getPrefix() . $option);
                $this->_options[ $option ] = $value;
            }
            // TODO: Delete this in a future version - exists because we serialized values to save rather than letting WP do it
            // allowed_classes=false: these legacy values are only ever arrays/scalars, and refusing
            // object instantiation prevents PHP object injection if an option value is ever tampered with.
            if (is_serialized($value)) {
                $value = unserialize($value, array('allowed_classes' => false));
            }

            return $value;
        }

        /**
         * Update option in WordPress
         *
         * @param string $option Option to set
         * @param mixed $value Value to save in the database. Arrays will be automatically serialized
         * @author Nigel Wells
         * @return void;
         */
        public function setOption($option, $value)
        {
            update_option($this->getPrefix() . $option, $value);
        }

        public function includeFile($file)
        {
            require_once $this->_path . 'includes/' . $file;
        }

        /**
         * Check if a given controller and method is in the default list of hooks
         *
         * @param string $controller Name of the controller without the prefix and suffix
         * @param string $method Method in the controller to check
         * @author Nigel Wells
         * @version 0.1.1.16.10.07
         * @return boolean;
         */
        public function isHookDefault($controller, $method)
        {
            $defaultHooks = $this->getDefaultHooks();

            return isset($defaultHooks[ $controller ]) && in_array($method, $defaultHooks[ $controller ]);
        }

        /** @return string */
        public function getPrefix()
        {
            return $this->_prefix;
        }

        /**
         * Get all possible hook files in this installation
         *
         * @author Nigel Wells
         * @version 0.1.1.16.10.07
         * @return array;
         */
        public function getAvailableHookControllers()
        {
            $hooks = array();
            if ($dh = opendir($this->_path . 'hooks')) {
                while (( $file = readdir($dh) ) !== false) {
                    if (substr($file, 0, 5) == 'hook_' && substr($file, -4) == '.php') {
                        $this->loadHookFile($file);
                        $hookName = $this->getHookName($file);
                        $hooks[ $hookName ] = new $hookName($this);
                    }
                }
                closedir($dh);
                // Sort the sections alphabetically by their display label, keeping the class names as indexes
                uasort($hooks, static fn ($a, $b) => strcasecmp($a->getLabel(), $b->getLabel()));
            }

            return $hooks;
        }

        /**
         * Add a setting into the main plugins settings page
         *
         * @author Nigel Wells
         * @version 0.1.1.16.10.07
         * @return void;
         */
        public function addSetting($settingField, $sectionName)
        {
            $hookField = new toolboxFieldModel($settingField);
            // Temporarily set the setting in this object so we can reference once the callback is reached
            $this->_setting[ $sectionName ][] = $hookField;
            add_filter($this->getPrefix() . 'settings', function ($settings) {
                // Add the setting
                $settings[] = $this->_setting;
                // Reset the temporary setting
                $this->_setting = array();

                // Return the settings
                return $settings;
            });
        }

        /**
         * Check if a given controller and method has been enabled
         *
         * @param string $hook Name of the hook controller file
         * @param string $method Method in the controller to check
         * @author Nigel Wells
         * @version 0.2.1.16.10.07
         * @return boolean;
         */
        public function isHookEnabled($hook, $method)
        {
            return isset($this->_enabled[ $hook ]) && in_array($method, $this->_enabled[ $hook ]);
        }

        /**
         * Get the full map of hooks that were registered as enabled for this request.
         * Keyed by hook source, each value is the list of enabled method names.
         *
         * @author Nigel Wells
         * @return array;
         */
        public function getEnabledHooks()
        {
            return $this->_enabled;
        }

        /**
         * Get a controller that has been declared so we can use its functions somewhere else
         *
         * @param string $hookName The name of the controller to get
         * @author Nigel Wells
         * @version 0.3.1.16.10.10
         * @return mixed
         */
        function getObject($hookName)
        {
            $hookName = $this->getHookName($hookName);
            if (isset($this->_objects[ $hookName ])) {
                return $this->_objects[ $hookName ];
            } else {
                return false;
            }
        }

        /**
         * Wrapper for hook_word_press_admin to more easily add menu items when required
         *
         * @param string $name Menu label
         * @param array $callback Array containing the controller and callback function
         * @author Nigel Wells
         * @version 0.3.1.16.10.10
         * @return void;
         */
        function addPage($name, $callback)
        {
            $adminHook = $this->getObject('word_press_admin');
            $adminHook->addPage($name, $callback);
        }

        /**
         * Prepends the prefix to any shortcode request
         *
         * @author Nigel Wells
         * @version 0.3.8.16.12.21
         * @return string
         */
        function getShortCode($label)
        {
            return $this->getPrefix() . $label;
        }

        /**
         * Collect every hook declared with 'force' => true across all hook controllers.
         *
         * Reading the flag means instantiating every hook controller, which would defeat the
         * lazy-loading "light fingerprint" if done on every request - so the result is cached in an
         * option and only rebuilt when the hooks directory changes (a deploy). The cache stores a
         * signature of the hook files' names + mtimes; a mismatch triggers a rebuild. Memoised per
         * request as well, since getDefaultHooks()/isHookDefault() can be called many times.
         *
         * @author Nigel Wells
         * @return array;
         */
        protected function getForcedHooks()
        {
            if (is_array($this->_forcedHooks)) {
                return $this->_forcedHooks;
            }

            $signature = $this->getHooksSignature();
            $cache = get_option($this->getPrefix() . 'forced_hooks_cache');
            if (
                is_array($cache) && isset($cache['signature'], $cache['hooks'])
                && $cache['signature'] === $signature && is_array($cache['hooks'])
            ) {
                $this->_forcedHooks = $cache['hooks'];

                return $this->_forcedHooks;
            }

            // Cache miss (first run, or a hook file changed on deploy): rebuild by reading the flag
            // off every hook. This instantiates all hook controllers, so it only happens here.
            $forced = $this->scanForcedHooks();
            update_option($this->getPrefix() . 'forced_hooks_cache', array(
                'signature' => $signature,
                'hooks' => $forced,
            ));
            $this->_forcedHooks = $forced;

            return $forced;
        }

        /**
         * Read the per-site kill-switch from wp-config.php. A site can turn off any forced default
         * (or any saved per-site hook) by defining APEX_TOOLBOX_DISABLED_HOOKS in the same shape as
         * the hooks array, e.g.:
         *
         *     define( 'APEX_TOOLBOX_DISABLED_HOOKS', array(
         *         'elementor' => array( 'scheduleElementorRevisionPrune' ),
         *     ) );
         *
         * Kept as its own method so tests can inject a value via a subclass without defining a
         * process-global constant.
         *
         * @author Nigel Wells
         * @return array;
         */
        protected function getDisabledHooks()
        {
            if (defined('APEX_TOOLBOX_DISABLED_HOOKS') && is_array(APEX_TOOLBOX_DISABLED_HOOKS)) {
                return APEX_TOOLBOX_DISABLED_HOOKS;
            }

            return array();
        }

        /**
         * Load all the various options saved in WordPress for the plugin
         *
         * @author Nigel Wells
         * @version 0.0.1.16.10.04
         * @return void;
         */
        private function loadOptions()
        {
            // Loop through all options and store them in the class to make it easier to access when needed
            foreach ($this->getOptions() as $option) {
                $optionValue = get_option($this->getPrefix() . $option);
                $this->_options[ $option ] = $optionValue;
            }
        }

        /**
         * Get all defined options for the plugin
         *
         * @author Nigel Wells
         * @version 0.0.1.16.10.06
         * @return array;
         */
        private function getOptions()
        {
            // Define available options
            return array(
                'hooks',
            );
        }

        /**
         * Load required libraries for the plugin
         *
         * @author Nigel Wells
         * @version 0.3.9.17.03.21
         * @return void;
         */
        private function loadIncludeFiles()
        {
            include_once ABSPATH . 'wp-admin/includes/plugin.php';
            require_once $this->_path . 'controllers/toolboxHookController.php';
            require_once $this->_path . 'models/toolboxHookModel.php';
            require_once $this->_path . 'models/toolboxFieldModel.php';
            include_once $this->_path . 'includes/apex_toolbox_functions.php';
        }

        /**
         * Register hooks & filters with WordPress
         *
         * @author Nigel Wells
         * @version 0.3.1.16.10.10
         * @return void;
         */
        private function registerHooks()
        {
            // Get required hooks
            $hooks = $this->getOption('hooks');
            if (! is_array($hooks)) {
                $hooks = array();
            }
            $hooks = array_merge_recursive($this->getDefaultHooks(), $hooks);
            // Honour the per-site kill-switch (wp-config) for anything turned off there, regardless of
            // whether it arrived as a forced default or a saved per-site choice.
            $hooks = $this->removeDisabledHooks($hooks);
            // To help keep a light fingerprint we only need to load up required hooks
            foreach ($hooks as $hookSource => $hookArray) {
                // A hook can be both a forced default and a saved per-site choice; de-dupe so it
                // only registers (add_action/add_filter) once.
                $hookArray = array_unique($hookArray);
                $this->loadHookFile($hookSource);
                $hookName = $this->getHookName($hookSource);
                $hookObject = new $hookName($this);
                $this->_objects[ $hookName ] = $hookObject;
                foreach ($hookArray as $hookMethod) {
                    // Locate the hook with the setting we need
                    foreach ($hookObject->getHooks() as $hookModal) {
                        if ($hookModal->getMethod() == $hookMethod) {
                            $hookModal->createHook($hookObject);
                            // Register this hook as being enabled
                            $this->_enabled[ $hookSource ][] = $hookMethod;
                        }
                    }
                }
            }
        }

        /**
         * Get a list of default hooks to load - these are forced on for every site without anyone
         * needing to visit the Hooks page and save. They are merged into the active set on every
         * request by registerHooks(), so a new default takes effect on the next plugin update
         * automatically.
         *
         * Feature defaults are NOT listed here - a hook declares itself a default with
         * 'force' => true in its own addHook() call, and getForcedHooks() collects them. This keeps
         * the controller from needing an edit every time a hook is added. Only the structural hooks
         * (the plugin's own admin) are hard-coded, as a guaranteed baseline. A site can switch any
         * default (or any saved per-site hook) back off via the wp-config kill-switch; see
         * getDisabledHooks().
         *
         * @author Nigel Wells
         * @version 0.3.1.16.10.10
         * @return array;
         */
        private function getDefaultHooks()
        {
            $defaultHooks = array();

            // --- Structural: required for the plugin's own admin to work; hard-coded as a guaranteed
            //     baseline so the settings menu can never be switched off by accident ---
            $defaultHooks['word_press_admin'][] = 'createAdminMenu';
            $defaultHooks['word_press_admin'][] = 'enableSessions';

            // --- Feature defaults: declared with 'force' => true on the hook itself, discovered + cached ---
            $defaultHooks = array_merge_recursive($defaultHooks, $this->getForcedHooks());

            // Drop anything a site has killed via wp-config so isHookDefault() stays honest (the
            // killed hook won't render as a forced, read-only checkbox on the Hooks page).
            return $this->removeDisabledHooks($defaultHooks);
        }

        /**
         * Build the map of forced hooks by instantiating every hook controller and reading each
         * hook's 'force' flag. Expensive - only call on a cache miss (see getForcedHooks()).
         *
         * @author Nigel Wells
         * @return array;
         */
        private function scanForcedHooks()
        {
            $forced = array();
            foreach ($this->getAvailableHookControllers() as $controllerObject) {
                $source = $controllerObject->getName();
                foreach ($controllerObject->getHooks() as $hookModal) {
                    if ($hookModal->isForced()) {
                        $forced[ $source ][] = $hookModal->getMethod();
                    }
                }
            }

            return $forced;
        }

        /**
         * Signature of the hooks directory (filename + mtime of every hook file), used to decide when
         * the forced-hooks cache is stale. Changes whenever a hook file is added, removed, or edited -
         * i.e. exactly when the set of forced hooks could change.
         *
         * @author Nigel Wells
         * @return string;
         */
        private function getHooksSignature()
        {
            $parts = array();
            $dir = $this->_path . 'hooks';
            if ($dh = opendir($dir)) {
                while (( $file = readdir($dh) ) !== false) {
                    if (substr($file, 0, 5) == 'hook_' && substr($file, -4) == '.php') {
                        $parts[] = $file . ':' . filemtime($dir . '/' . $file);
                    }
                }
                closedir($dh);
            }
            sort($parts);

            return md5(implode('|', $parts));
        }

        /**
         * Strip every hook listed in the wp-config kill-switch out of a hooks map, so a disabled hook
         * is never registered no matter how it got into the list.
         *
         * @param array $hooks Map of hookSource => array of method names.
         * @author Nigel Wells
         * @return array;
         */
        private function removeDisabledHooks($hooks)
        {
            $disabled = $this->getDisabledHooks();
            if (empty($disabled)) {
                return $hooks;
            }
            foreach ($disabled as $hookSource => $methods) {
                if (! isset($hooks[ $hookSource ])) {
                    continue;
                }
                $hooks[ $hookSource ] = array_diff($hooks[ $hookSource ], (array) $methods);
                if (empty($hooks[ $hookSource ])) {
                    unset($hooks[ $hookSource ]);
                }
            }

            return $hooks;
        }

        /**
         * Include a specific hook file into memory
         *
         * @param string $file Name of the hook file to load
         * @author Nigel Wells
         * @version 0.1.1.16.10.07
         * @return void;
         */
        private function loadHookFile($file)
        {
            $filename = $this->getHookFilename($file);
            include_once $this->_path . 'hooks/' . $filename;
        }

        /**
         * Get the official name of a hook to be used to initiate the class object
         *
         * @param string $name Name of the hook to return
         * @author Nigel Wells
         * @version 0.1.1.16.10.07
         * @return string;
         */
        private function getHookName($name)
        {
            // Strip out parts of a file if it is sent through like that
            if (substr($name, 0, 5) == 'hook_') {
                $name = substr($name, 5);
            }
            if (substr($name, -4) == '.php') {
                $name = substr($name, 0, strlen($name) - 4);
            }

            // Return the full name of the hook object
            return 'toolboxHook' . str_replace(' ', '', ucwords(str_replace('_', ' ', $name)));
        }

        /**
         * Get the physical name of a hooks file
         *
         * @param string $name Name of the hook
         * @author Nigel Wells
         * @version 0.1.1.16.10.07
         * @return string;
         */
        private function getHookFilename($name)
        {
            // Only need to do this if passed through a basic name i.e. from a WordPress option
            if (strpos($name, '.php')) {
                return $name;
            } else {
                return 'hook_' . $name . '.php';
            }
        }
    }
}
