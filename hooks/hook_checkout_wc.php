<?php

// Forbid accessing directly
if (! defined('ABSPATH')) {
    header('HTTP/1.1 401 Unauthorized');
    exit;
}
// Ensure there are no conflicts
if (! class_exists('toolboxHookCheckoutWc')) {
    /**
     * Integrations for the "Checkout for WooCommerce" (CheckoutWC / CFW) plugin.
     *
     * Both hooks no-op unless CFW is active, so they are safe to enable on a site
     * that does not run CFW (the callbacks return early).
     */
    class toolboxHookCheckoutWc extends toolboxHookController
    {
        /** CFW's shipping method id for its bundled Local Pickup rate. */
        public const CFW_LOCAL_PICKUP_METHOD_ID = 'cfw_local_pickup';

        /** CFW's settings manager — used both to read settings and to detect CFW. */
        public const CFW_SETTINGS_CLASS = 'Objectiv\\Plugins\\Checkout\\Managers\\SettingsManager';

        public function __construct($Toolbox)
        {
            parent::__construct($Toolbox);

            $label = 'Checkout for WooCommerce';
            if (! $this->isCfwActive()) {
                $label .= ' (CHECKOUT FOR WOOCOMMERCE NOT INSTALLED)';
            }
            $this->setLabel($label);

            $this->addHook(
                APEX_TOOLBOX_HOOK_FILTER,
                'woocommerce_cart_shipping_method_full_label',
                'renameCfwLocalPickupLabelOnCart',
                array(
                    'label' => 'Rename CFW Local Pickup label on the cart',
                    'description' => 'Replaces CFW\'s "Local Pickup" shipping rate label on the cart with the '
                        . 'configured CFW pickup_option_label so it matches the wording shown on the CFW checkout.',
                    'args' => 2,
                ),
            );

            $this->addHook(
                APEX_TOOLBOX_HOOK_ACTION,
                'woocommerce_after_calculate_totals',
                'syncCfwDeliveryMethodFromShippingChoice',
                array(
                    'label' => 'Sync CFW delivery method from cart shipping choice',
                    'description' => 'Keeps CFW\'s cfw_delivery_method session value in sync with the cart\'s chosen '
                        . 'shipping method, so picking "Local Pickup" on the cart pre-selects the "Pick up" radio '
                        . 'on the CFW checkout.',
                ),
            );
        }

        /**
         * Filter: woocommerce_cart_shipping_method_full_label.
         *
         * Thin glue — reads the rate's method id then defers to the pure
         * buildCfwPickupLabel() decision so the logic stays unit-testable
         * without constructing a real WC_Shipping_Rate.
         *
         * @param string $label The rate label WooCommerce built.
         * @param mixed $method The WC_Shipping_Rate for this row.
         * @return string
         */
        public function renameCfwLocalPickupLabelOnCart($label, $method)
        {
            $methodId = is_object($method) && method_exists($method, 'get_method_id')
                ? $method->get_method_id()
                : '';

            return $this->buildCfwPickupLabel((string) $label, (string) $methodId);
        }

        /**
         * Pure decision for the cart pickup label.
         *
         * Returns the original label untouched unless this is the CFW local
         * pickup rate AND a non-empty pickup_option_label is configured.
         *
         * @param string $label
         * @param string $methodId
         * @return string
         */
        public function buildCfwPickupLabel(string $label, string $methodId): string
        {
            if ($methodId !== self::CFW_LOCAL_PICKUP_METHOD_ID) {
                return $label;
            }

            $pickupLabel = $this->getCfwPickupOptionLabel();
            if (! is_string($pickupLabel) || $pickupLabel === '') {
                return $label;
            }

            return (string) apply_filters('cfw_local_pickup_option_label', $pickupLabel);
        }

        /**
         * Action: woocommerce_after_calculate_totals.
         *
         * Mirrors the cart's chosen shipping method into CFW's
         * cfw_delivery_method session value. Side-effecting glue; the
         * pickup-vs-ship decision lives in resolveCfwDeliveryMethod().
         *
         * @return void
         */
        public function syncCfwDeliveryMethodFromShippingChoice(): void
        {
            if (! $this->isCfwActive()) {
                return;
            }

            $chosenMethods = $this->getChosenShippingMethods();
            if (! is_array($chosenMethods) || empty($chosenMethods)) {
                return;
            }

            $newValue = $this->resolveCfwDeliveryMethod($chosenMethods);

            // Avoid a needless session write (each write dirties the session).
            if ($this->getCfwDeliveryMethod() === $newValue) {
                return;
            }

            $this->setCfwDeliveryMethod($newValue);
        }

        /**
         * Pure decision: 'pickup' if any chosen method is a local-pickup rate,
         * otherwise 'ship'.
         *
         * @param array $chosenMethods
         * @return string 'pickup'|'ship'
         */
        public function resolveCfwDeliveryMethod(array $chosenMethods): string
        {
            foreach ($chosenMethods as $method) {
                if (! is_string($method)) {
                    continue;
                }

                if ($method === self::CFW_LOCAL_PICKUP_METHOD_ID || strpos($method, 'local_pickup') !== false) {
                    return 'pickup';
                }
            }

            return 'ship';
        }

        /**
         * Whether the Checkout for WooCommerce plugin is active.
         *
         * Protected so tests can stub it without loading CFW. CFW defines
         * CFW_VERSION on load; the SettingsManager class is the fallback.
         *
         * @return bool
         */
        protected function isCfwActive(): bool
        {
            return defined('CFW_VERSION') || class_exists(self::CFW_SETTINGS_CLASS);
        }

        /**
         * Read CFW's pickup_option_label setting, or null if unavailable.
         *
         * @return string|null
         */
        protected function getCfwPickupOptionLabel(): ?string
        {
            if (! is_callable(array(self::CFW_SETTINGS_CLASS, 'instance'))) {
                return null;
            }

            $manager = call_user_func(array(self::CFW_SETTINGS_CLASS, 'instance'));
            if (! is_object($manager) || ! method_exists($manager, 'get_setting')) {
                return null;
            }

            $value = $manager->get_setting('pickup_option_label');

            return is_string($value)
                ? $value
                : null;
        }

        /**
         * The cart's chosen shipping methods, or null if no WC session.
         *
         * @return array|null
         */
        protected function getChosenShippingMethods(): ?array
        {
            $session = $this->getWcSession();
            if ($session === null) {
                return null;
            }

            $chosen = $session->get('chosen_shipping_methods');

            return is_array($chosen)
                ? $chosen
                : null;
        }

        /**
         * Current cfw_delivery_method session value, or null.
         *
         * @return string|null
         */
        protected function getCfwDeliveryMethod(): ?string
        {
            $session = $this->getWcSession();
            if ($session === null) {
                return null;
            }

            $value = $session->get('cfw_delivery_method');

            return is_string($value)
                ? $value
                : null;
        }

        /**
         * Set the cfw_delivery_method session value.
         *
         * @param string $value
         * @return void
         */
        protected function setCfwDeliveryMethod(string $value): void
        {
            $session = $this->getWcSession();
            if ($session === null) {
                return;
            }

            $session->set('cfw_delivery_method', $value);
        }

        /**
         * The WooCommerce session object, or null if WC / the session is absent.
         *
         * @return object|null
         */
        protected function getWcSession()
        {
            if (! function_exists('WC')) {
                return null;
            }

            $wc = WC();
            if (! is_object($wc) || ! isset($wc->session) || $wc->session === null) {
                return null;
            }

            return $wc->session;
        }
    }
}
