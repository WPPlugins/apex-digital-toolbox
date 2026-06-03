<?php
/**
 * Tests for the default-hooks mechanism in toolboxController.
 *
 * Feature defaults are declared on the hook itself with 'force' => true in its addHook() call;
 * getForcedHooks() discovers them and getDefaultHooks() merges them into the active set on every
 * request, so a forced-on hook runs without anyone saving the Hooks page. A site can switch any
 * default (or any saved per-site hook) back off via the wp-config kill-switch
 * APEX_TOOLBOX_DISABLED_HOOKS. We inject that kill-switch through a subclass that overrides
 * getDisabledHooks() rather than defining a process-global constant (which would leak into every
 * other test).
 *
 * Covers:
 *  1. A 'force' => true hook (scheduleElementorRevisionPrune) is enabled WITHOUT saving the Hooks page.
 *  2. isHookDefault() reports it as a default (so the Hooks admin checkbox renders ticked + disabled).
 *  3. A hook WITHOUT 'force' is not a default and is not enabled when nothing is saved.
 *  4. The structural defaults remain enabled.
 *  5. A hook that is both a default AND present in the saved per-site option registers exactly once.
 *  6. The wp-config kill-switch turns a default off: not enabled, not reported as a default.
 *  7. The kill-switch also strips a hook that was saved via the per-site Hooks page.
 */

if ( ! class_exists( 'Test_DefaultHooks_KillSwitch_Toolbox' ) ) {
    /**
     * Toolbox subclass simulating APEX_TOOLBOX_DISABLED_HOOKS in wp-config killing the prune.
     * The value must be returned during construction (registerHooks() runs in the constructor), so
     * it's hard-coded in the override rather than a settable property.
     */
    class Test_DefaultHooks_KillSwitch_Toolbox extends toolboxController {
        protected function getDisabledHooks() {
            return array( 'elementor' => array( 'scheduleElementorRevisionPrune' ) );
        }
    }
}

class Test_Default_Hooks extends WP_UnitTestCase {

    public function set_up() {
        parent::set_up();
        delete_option( 'apex_toolbox_hooks' );
    }

    public function tear_down() {
        wp_clear_scheduled_hook( toolboxHookElementor::PRUNE_CRON_HOOK );
        parent::tear_down();
    }

    // ===== Test 1: feature default is on with no saved option =====
    public function test_feature_default_is_enabled_without_saving() {
        // No apex_toolbox_hooks option exists (deleted in set_up()).
        $Toolbox = new toolboxController();

        $this->assertTrue(
            $Toolbox->isHookEnabled( 'elementor', 'scheduleElementorRevisionPrune' ),
            'A hard-coded feature default should be forced on even when the Hooks page was never saved'
        );
    }

    // ===== Test 2: reported as a default (drives the read-only checkbox in the UI) =====
    public function test_feature_default_reports_as_default() {
        $Toolbox = new toolboxController();

        $this->assertTrue(
            $Toolbox->isHookDefault( 'elementor', 'scheduleElementorRevisionPrune' ),
            'A feature default should report as a default so the admin checkbox renders ticked + disabled'
        );
    }

    // ===== Test 3: a hook without 'force' is not a default =====
    public function test_non_forced_hook_is_not_a_default() {
        // registerWidgetListCategories is a normal (opt-in) Elementor hook with no 'force' flag.
        $Toolbox = new toolboxController();

        $this->assertFalse(
            $Toolbox->isHookDefault( 'elementor', 'registerWidgetListCategories' ),
            'A hook without force => true must not be treated as a default'
        );
        $this->assertFalse(
            $Toolbox->isHookEnabled( 'elementor', 'registerWidgetListCategories' ),
            'A non-forced hook must stay off until a site opts in via the Hooks page'
        );
    }

    // ===== Test 4: structural defaults still enabled =====
    public function test_structural_defaults_still_enabled() {
        $Toolbox = new toolboxController();

        $this->assertTrue( $Toolbox->isHookEnabled( 'word_press_admin', 'createAdminMenu' ) );
        $this->assertTrue( $Toolbox->isHookEnabled( 'word_press_admin', 'enableSessions' ) );
    }

    // ===== Test 5: a default that is also saved per-site registers exactly once =====
    public function test_default_plus_saved_copy_registers_once() {
        // Simulate a site whose saved option also lists the hook; array_merge_recursive would
        // otherwise list it twice.
        update_option( 'apex_toolbox_hooks', array(
            'elementor' => array( 'scheduleElementorRevisionPrune' ),
        ) );

        $Toolbox = new toolboxController();
        $enabled = $Toolbox->getEnabledHooks();

        $this->assertArrayHasKey( 'elementor', $enabled );
        $occurrences = array_filter(
            $enabled['elementor'],
            static function ( $method ) {
                return $method === 'scheduleElementorRevisionPrune';
            }
        );
        $this->assertCount(
            1,
            $occurrences,
            'A hook that is both a forced default and a saved per-site choice must register exactly once'
        );
    }

    // ===== Test 6: kill-switch turns a default off =====
    public function test_kill_switch_disables_a_default() {
        $Toolbox = new Test_DefaultHooks_KillSwitch_Toolbox();

        $this->assertFalse(
            $Toolbox->isHookEnabled( 'elementor', 'scheduleElementorRevisionPrune' ),
            'A killed hook must not be registered as enabled'
        );
        $this->assertFalse(
            $Toolbox->isHookDefault( 'elementor', 'scheduleElementorRevisionPrune' ),
            'A killed hook must not report as a forced default (so its admin checkbox is not locked on)'
        );
    }

    // ===== Test 7: kill-switch also strips a per-site saved hook =====
    public function test_kill_switch_strips_saved_hook() {
        update_option( 'apex_toolbox_hooks', array(
            'elementor' => array( 'scheduleElementorRevisionPrune' ),
        ) );

        $Toolbox = new Test_DefaultHooks_KillSwitch_Toolbox();

        $this->assertFalse(
            $Toolbox->isHookEnabled( 'elementor', 'scheduleElementorRevisionPrune' ),
            'The kill-switch must override even a hook the site saved via the Hooks page'
        );
    }
}
