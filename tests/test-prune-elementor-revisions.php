<?php
/**
 * Tests for the Elementor revision pruning feature in toolboxHookElementor.
 *
 * Covers:
 *  1. Safety floor (keep the N most recent regardless of age)
 *  2. Fresh window (keep every revision newer than PRUNE_FRESH_DAYS)
 *  3. Daily bucket (PRUNE_FRESH_DAYS - PRUNE_DAILY_DAYS: keep newest per local calendar day)
 *  4. Weekly bucket (>= PRUNE_DAILY_DAYS: keep newest per ISO week)
 *  5. Scope (non-Elementor posts are not touched)
 *  6. Self-unschedule when the hook is disabled
 *  7. Batch + cursor behavior (overflow reschedules + cursor advances; cursor resets after a complete pass)
 *  8. Schedule idempotency (scheduleElementorRevisionPrune does not double-schedule)
 */

require_once dirname( __DIR__ ) . '/hooks/hook_elementor.php';

if ( ! class_exists( 'Test_MemoryBudget_PruneHook' ) ) {
    /**
     * Test subclass that simulates "memory limit nearly reached" after the first parent has been processed.
     * Lets the memory-budget bail path be exercised deterministically without actually allocating memory.
     */
    class Test_MemoryBudget_PruneHook extends toolboxHookElementor {
        private int $calls = 0;
        protected function isMemoryBudgetExceeded(): bool {
            $this->calls++;
            return $this->calls >= 1;
        }
    }
}

if ( ! class_exists( 'Test_NoElementor_PruneHook' ) ) {
    /**
     * Test subclass simulating a site where Elementor is not installed. The test bootstrap stubs
     * \Elementor\Plugin into existence, so we override the wrapper to report it absent.
     */
    class Test_NoElementor_PruneHook extends toolboxHookElementor {
        protected function isElementorActive(): bool {
            return false;
        }
    }
}

if ( ! class_exists( 'Test_PruneKillSwitch_Toolbox' ) ) {
    /**
     * Toolbox subclass simulating a site that killed the prune via wp-config
     * (APEX_TOOLBOX_DISABLED_HOOKS). Injected through getDisabledHooks() rather than a process-global
     * constant. The prune is otherwise a forced default, so this is the supported way to turn it off.
     */
    class Test_PruneKillSwitch_Toolbox extends toolboxController {
        protected function getDisabledHooks() {
            return array( 'elementor' => array( 'scheduleElementorRevisionPrune' ) );
        }
    }
}

class Test_Prune_Elementor_Revisions extends WP_UnitTestCase {

    /** @var toolboxController */
    private $Toolbox;

    /** @var toolboxHookElementor */
    private $hookController;

    public function set_up() {
        parent::set_up();

        // Enable the prune scheduling hook so isHookEnabled('elementor', 'scheduleElementorRevisionPrune') is true
        // for the Toolbox instance built below. We delete first to defeat any stale option cache.
        delete_option( 'apex_toolbox_hooks' );
        update_option( 'apex_toolbox_hooks', array(
            'elementor' => array( 'scheduleElementorRevisionPrune' ),
        ) );

        delete_option( 'apex_toolbox_elementor_prune_cursor' );
        delete_option( 'apex_toolbox_elementor_prune_last_complete_at' );
        wp_clear_scheduled_hook( toolboxHookElementor::PRUNE_CRON_HOOK );

        $this->Toolbox        = new toolboxController();
        $this->hookController = $this->Toolbox->getObject( 'elementor' );
        $this->assertInstanceOf( toolboxHookElementor::class, $this->hookController, 'Elementor hook controller should be instantiated by the Toolbox' );
    }

    public function tear_down() {
        wp_clear_scheduled_hook( toolboxHookElementor::PRUNE_CRON_HOOK );
        parent::tear_down();
    }

    // ===== Test 1: safety floor =====

    public function test_keeps_three_most_recent_regardless_of_age() {
        $parent_id = $this->makeElementorPost();
        // 5 ancient revisions, all past every retention bucket. The safety floor's contract is
        // "the 3 newest survive no matter what" - it does not preclude bucket logic also keeping
        // the newest of any bucket containing the non-floor remainder, so we assert the floor's
        // contract directly rather than a fixed total count.
        $rev_ids = array();
        for ( $age_days = 60; $age_days <= 64; $age_days++ ) {
            $rev_ids[ $age_days ] = $this->makeRevisionAt( $parent_id, $age_days * DAY_IN_SECONDS );
        }
        $this->assertSame( 5, $this->countRevisions( $parent_id ) );

        $this->hookController->pruneElementorRevisions();

        $remaining_ids = wp_list_pluck( wp_get_post_revisions( $parent_id ), 'ID' );
        $this->assertContains( $rev_ids[60], $remaining_ids, 'Newest revision must survive (safety floor)' );
        $this->assertContains( $rev_ids[61], $remaining_ids, 'Second newest must survive (safety floor)' );
        $this->assertContains( $rev_ids[62], $remaining_ids, 'Third newest must survive (safety floor)' );
        $this->assertGreaterThanOrEqual( toolboxHookElementor::PRUNE_MIN_KEEP, count( $remaining_ids ) );
    }

    // ===== Test 2: fresh window =====

    public function test_keeps_all_revisions_within_fresh_window() {
        $parent_id = $this->makeElementorPost();
        // 10 revisions spaced 12 hours apart, all under 5 days old (well inside PRUNE_FRESH_DAYS=7)
        for ( $i = 0; $i < 10; $i++ ) {
            $this->makeRevisionAt( $parent_id, $i * 12 * HOUR_IN_SECONDS );
        }

        $this->hookController->pruneElementorRevisions();

        $this->assertSame( 10, $this->countRevisions( $parent_id ), 'All revisions inside PRUNE_FRESH_DAYS should be kept' );
    }

    // ===== Test 3: daily bucket =====

    public function test_keeps_newest_per_day_in_daily_bucket() {
        $parent_id = $this->makeElementorPost();
        // 3 fresh ones - these absorb the PRUNE_MIN_KEEP floor so the older clusters get bucket-pruned.
        // Cluster members share a single timestamp so the test is deterministic regardless of what time
        // of day it runs - spreading by hours can cross midnight UTC and put cluster members on
        // different calendar days, breaking the "single bucket" premise.
        $this->makeRevisionAt( $parent_id, 1 * HOUR_IN_SECONDS );
        $this->makeRevisionAt( $parent_id, 2 * HOUR_IN_SECONDS );
        $this->makeRevisionAt( $parent_id, 3 * HOUR_IN_SECONDS );
        // 3 revisions all at exactly 10 days ago - same calendar day
        $this->makeRevisionAt( $parent_id, 10 * DAY_IN_SECONDS );
        $this->makeRevisionAt( $parent_id, 10 * DAY_IN_SECONDS );
        $this->makeRevisionAt( $parent_id, 10 * DAY_IN_SECONDS );
        // 3 revisions all at exactly 15 days ago - different calendar day to the above
        $this->makeRevisionAt( $parent_id, 15 * DAY_IN_SECONDS );
        $this->makeRevisionAt( $parent_id, 15 * DAY_IN_SECONDS );
        $this->makeRevisionAt( $parent_id, 15 * DAY_IN_SECONDS );
        $this->assertSame( 9, $this->countRevisions( $parent_id ) );

        $this->hookController->pruneElementorRevisions();

        // Expected: 3 fresh + 1 newest from day-10 bucket + 1 newest from day-15 bucket = 5
        $this->assertSame( 5, $this->countRevisions( $parent_id ), '7-30 day revisions should collapse to one per calendar day' );
    }

    // ===== Test 4: weekly bucket =====

    public function test_keeps_newest_per_week_in_weekly_bucket() {
        $parent_id = $this->makeElementorPost();
        // 3 fresh ones (safety floor)
        $this->makeRevisionAt( $parent_id, 1 * HOUR_IN_SECONDS );
        $this->makeRevisionAt( $parent_id, 2 * HOUR_IN_SECONDS );
        $this->makeRevisionAt( $parent_id, 3 * HOUR_IN_SECONDS );
        // 3 revisions all at exactly 35 days ago - same ISO week.
        // Same-timestamp cluster avoids midnight-UTC edge cases on the calendar day where
        // an hour offset would push the revision into the adjacent ISO week.
        $this->makeRevisionAt( $parent_id, 35 * DAY_IN_SECONDS );
        $this->makeRevisionAt( $parent_id, 35 * DAY_IN_SECONDS );
        $this->makeRevisionAt( $parent_id, 35 * DAY_IN_SECONDS );
        // 3 revisions all at exactly 50 days ago - different ISO week (15-day gap = at least 2 ISO weeks away)
        $this->makeRevisionAt( $parent_id, 50 * DAY_IN_SECONDS );
        $this->makeRevisionAt( $parent_id, 50 * DAY_IN_SECONDS );
        $this->makeRevisionAt( $parent_id, 50 * DAY_IN_SECONDS );
        $this->assertSame( 9, $this->countRevisions( $parent_id ) );

        $this->hookController->pruneElementorRevisions();

        // Expected: 3 fresh + 1 per ISO week = 5
        $this->assertSame( 5, $this->countRevisions( $parent_id ), '>=30 day revisions should collapse to one per ISO week' );
    }

    // ===== Test 5: scope =====

    public function test_ignores_non_elementor_posts() {
        // Plain post - no _elementor_edit_mode meta
        $parent_id = self::factory()->post->create();
        for ( $age_days = 60; $age_days <= 70; $age_days++ ) {
            $this->makeRevisionAt( $parent_id, $age_days * DAY_IN_SECONDS );
        }
        $this->assertSame( 11, $this->countRevisions( $parent_id ) );

        $this->hookController->pruneElementorRevisions();

        $this->assertSame( 11, $this->countRevisions( $parent_id ), 'Non-Elementor posts should never be touched' );
    }

    // ===== Test 6: self-unschedule =====

    public function test_self_unschedules_when_hook_disabled() {
        // The prune is a forced default, so disable it the supported way: the wp-config kill-switch.
        $toolbox       = new Test_PruneKillSwitch_Toolbox();
        $hookElementor = new toolboxHookElementor( $toolbox );

        // Simulate a previously-scheduled cron event
        wp_schedule_event( time() + DAY_IN_SECONDS, 'daily', toolboxHookElementor::PRUNE_CRON_HOOK );
        $this->assertNotFalse(
            wp_next_scheduled( toolboxHookElementor::PRUNE_CRON_HOOK ),
            'Sanity: cron event should be scheduled before the prune runs'
        );

        $hookElementor->pruneElementorRevisions();

        $this->assertFalse(
            wp_next_scheduled( toolboxHookElementor::PRUNE_CRON_HOOK ),
            'Cron should self-unschedule when the hook is disabled'
        );
    }

    // ===== Test 7a: batch overflow =====

    public function test_reschedules_followup_when_batch_full() {
        // Default PRUNE_BATCH is 200 - create PRUNE_BATCH+1 minimal parents to trigger overflow.
        // Use direct $wpdb inserts to keep this test cheap.
        $created = $this->createBulkElementorParents( toolboxHookElementor::PRUNE_BATCH + 1 );
        $this->assertCount( toolboxHookElementor::PRUNE_BATCH + 1, $created );

        $this->hookController->pruneElementorRevisions();

        $cursor = (int) get_option( 'apex_toolbox_elementor_prune_cursor' );
        $this->assertGreaterThan( 0, $cursor, 'Cursor should advance to indicate a partial pass' );

        $next = wp_next_scheduled( toolboxHookElementor::PRUNE_CRON_HOOK );
        $this->assertNotFalse( $next, 'A follow-up single event should be scheduled when the batch is full' );

        $this->assertFalse(
            get_option( 'apex_toolbox_elementor_prune_last_complete_at', false ),
            'last_complete_at should not be set until the full pass completes'
        );
    }

    // ===== Test 7b: cursor reset on complete pass =====

    public function test_cursor_resets_after_complete_pass() {
        // A handful of parents - well under PRUNE_BATCH so one run completes the pass
        $this->createBulkElementorParents( 5 );

        $this->hookController->pruneElementorRevisions();

        $this->assertSame(
            0,
            (int) get_option( 'apex_toolbox_elementor_prune_cursor' ),
            'Cursor should reset to 0 after a complete pass'
        );
        $this->assertNotFalse(
            get_option( 'apex_toolbox_elementor_prune_last_complete_at' ),
            'last_complete_at should be set after a complete pass'
        );
    }

    // ===== Test 8: schedule idempotency =====

    public function test_schedule_is_idempotent() {
        wp_clear_scheduled_hook( toolboxHookElementor::PRUNE_CRON_HOOK );
        $this->assertFalse( wp_next_scheduled( toolboxHookElementor::PRUNE_CRON_HOOK ), 'Sanity: nothing scheduled before first call' );

        $this->hookController->scheduleElementorRevisionPrune();
        $first = wp_next_scheduled( toolboxHookElementor::PRUNE_CRON_HOOK );
        $this->assertNotFalse( $first );

        $this->hookController->scheduleElementorRevisionPrune();
        $second = wp_next_scheduled( toolboxHookElementor::PRUNE_CRON_HOOK );
        $this->assertSame( $first, $second, 'A second call should not change the scheduled timestamp' );

        $matches = 0;
        foreach ( _get_cron_array() as $events ) {
            if ( isset( $events[ toolboxHookElementor::PRUNE_CRON_HOOK ] ) ) {
                $matches += count( $events[ toolboxHookElementor::PRUNE_CRON_HOOK ] );
            }
        }
        $this->assertSame( 1, $matches, 'Exactly one cron event should exist after two schedule calls' );
    }

    // ===== Crash-recovery safety paths =====

    public function test_skips_parent_marked_in_progress_from_previous_run() {
        // Two Elementor-built posts, each with 6 ancient revisions (which would normally lead to 2 deletes each).
        $parent_a = $this->makeElementorPost();
        $parent_b = $this->makeElementorPost();
        for ( $i = 0; $i < 6; $i++ ) {
            $this->makeRevisionAt( $parent_a, 60 * DAY_IN_SECONDS + $i * HOUR_IN_SECONDS );
            $this->makeRevisionAt( $parent_b, 60 * DAY_IN_SECONDS + $i * HOUR_IN_SECONDS );
        }
        // Simulate the marker a previous-run-that-crashed-mid-parent would have left behind
        update_option( 'apex_toolbox_elementor_prune_in_progress_parent', $parent_a, false );

        $stats = $this->hookController->pruneElementorRevisions();

        $this->assertSame( 'completed', $stats['kind'] );
        $this->assertSame( 6, $this->countRevisions( $parent_a ), 'Parent A should be skipped untouched after the crash marker matched' );
        $this->assertLessThan( 6, $this->countRevisions( $parent_b ), 'Parent B should still be processed normally' );
        $this->assertSame( 0, intval( get_option( 'apex_toolbox_elementor_prune_in_progress_parent' ) ), 'In-progress marker should be cleared after the skip' );
    }

    public function test_in_progress_marker_is_set_then_cleared_around_each_parent() {
        $this->makeElementorPost();

        $this->assertFalse( get_option( 'apex_toolbox_elementor_prune_in_progress_parent', false ), 'Sanity: no marker before run' );

        $this->hookController->pruneElementorRevisions();

        // After a clean run, no marker should remain
        $this->assertSame( 0, intval( get_option( 'apex_toolbox_elementor_prune_in_progress_parent' ) ) );
    }

    public function test_complete_pass_clears_any_stale_in_progress_marker() {
        // Even if the marker is left over from an unrelated state, completing a full pass clears it.
        update_option( 'apex_toolbox_elementor_prune_in_progress_parent', 99999, false );
        $this->makeElementorPost();

        $stats = $this->hookController->pruneElementorRevisions();

        $this->assertSame( 'completed', $stats['kind'] );
        $this->assertSame( 0, intval( get_option( 'apex_toolbox_elementor_prune_in_progress_parent' ) ) );
    }

    // ===== Memory budget =====

    public function test_memory_budget_check_bails_early_and_reschedules() {
        $hook = new Test_MemoryBudget_PruneHook( $this->Toolbox );
        $this->createBulkElementorParents( 5 );

        $stats = $hook->pruneElementorRevisions();

        $this->assertSame( 'batch', $stats['kind'], 'Memory bail should be reported as a partial batch' );
        $this->assertSame( 1, $stats['parents_processed'], 'Should bail after the first parent when memory is over budget' );
        $this->assertGreaterThan( 0, $stats['parents_pending'] );
        $this->assertNotFalse(
            wp_next_scheduled( toolboxHookElementor::PRUNE_CRON_HOOK ),
            'A follow-up batch event should be scheduled when bailing on memory pressure'
        );
    }

    // ===== Elementor not installed (forced on for every site, incl. non-Elementor sites) =====

    public function test_does_not_schedule_when_elementor_inactive() {
        $hook = new Test_NoElementor_PruneHook( $this->Toolbox );

        // Pre-schedule an event to prove it also clears a leftover one (e.g. Elementor was removed).
        wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', toolboxHookElementor::PRUNE_CRON_HOOK );
        $this->assertNotFalse( wp_next_scheduled( toolboxHookElementor::PRUNE_CRON_HOOK ), 'Sanity: event scheduled before run' );

        $hook->scheduleElementorRevisionPrune();

        $this->assertFalse(
            wp_next_scheduled( toolboxHookElementor::PRUNE_CRON_HOOK ),
            'Without Elementor the prune cron should not be scheduled (and any leftover event cleared)'
        );
    }

    public function test_prune_run_returns_no_elementor_when_inactive() {
        $hook = new Test_NoElementor_PruneHook( $this->Toolbox );

        $stats = $hook->pruneElementorRevisions();

        $this->assertSame( 'no_elementor', $stats['kind'], 'Prune run on a site without Elementor should bail with no_elementor' );
    }

    // ===== Helpers =====

    private function makeElementorPost(): int {
        $post_id = self::factory()->post->create();
        update_post_meta( $post_id, '_elementor_edit_mode', 'builder' );
        return $post_id;
    }

    /**
     * Insert a revision directly so we can control post_date_gmt precisely.
     */
    private function makeRevisionAt( int $parent_id, int $seconds_ago ): int {
        $timestamp = time() - $seconds_ago;
        $date      = gmdate( 'Y-m-d H:i:s', $timestamp );
        $rev_id    = wp_insert_post( array(
            'post_type'     => 'revision',
            'post_status'   => 'inherit',
            'post_parent'   => $parent_id,
            'post_title'    => 'rev-' . $seconds_ago . '-' . wp_generate_password( 4, false, false ),
            'post_content'  => 'rev content',
            'post_date'     => $date,
            'post_date_gmt' => $date,
            'post_name'     => $parent_id . '-revision-' . wp_generate_password( 8, false, false ),
            'post_author'   => 1,
        ), true );
        $this->assertNotWPError( $rev_id );
        return (int) $rev_id;
    }

    private function countRevisions( int $parent_id ): int {
        return count( wp_get_post_revisions( $parent_id, array( 'posts_per_page' => -1 ) ) );
    }

    // ===== Return value contract =====

    public function test_pruneElementorRevisions_returns_no_parents_when_no_elementor_posts() {
        $stats = $this->hookController->pruneElementorRevisions();

        $this->assertSame( 'no_parents', $stats['kind'] );
        $this->assertSame( 0, $stats['parents_processed'] );
        $this->assertSame( 0, $stats['revisions_deleted'] );
        $this->assertSame( 0, $stats['parents_pending'] );
    }

    public function test_pruneElementorRevisions_returns_completed_for_full_pass() {
        $this->makeElementorPost();

        $stats = $this->hookController->pruneElementorRevisions();

        $this->assertSame( 'completed', $stats['kind'] );
        $this->assertSame( 1, $stats['parents_processed'] );
        $this->assertSame( 0, $stats['parents_pending'] );
    }

    public function test_pruneElementorRevisions_returns_batch_when_more_parents_than_limit() {
        $this->createBulkElementorParents( toolboxHookElementor::PRUNE_BATCH + 1 );

        $stats = $this->hookController->pruneElementorRevisions();

        $this->assertSame( 'batch', $stats['kind'] );
        $this->assertSame( toolboxHookElementor::PRUNE_BATCH, $stats['parents_processed'] );
        $this->assertGreaterThan( 0, $stats['parents_pending'] );
    }

    public function test_pruneElementorRevisions_returns_disabled_when_hook_off() {
        $isolated_toolbox = new Test_PruneKillSwitch_Toolbox();
        $isolated_hook    = new toolboxHookElementor( $isolated_toolbox );

        $stats = $isolated_hook->pruneElementorRevisions();

        $this->assertSame( 'disabled', $stats['kind'] );
    }

    public function test_pruneElementorRevisions_reports_deleted_count() {
        $parent_id = $this->makeElementorPost();
        // 6 ancient revisions all on the same day; safety floor keeps 3, weekly bucket keeps 1 more, 2 deleted
        for ( $i = 0; $i < 6; $i++ ) {
            $this->makeRevisionAt( $parent_id, 60 * DAY_IN_SECONDS + $i * HOUR_IN_SECONDS );
        }

        $stats = $this->hookController->pruneElementorRevisions();

        $this->assertSame( 'completed', $stats['kind'] );
        $this->assertSame( 2, $stats['revisions_deleted'] );
    }

    // ===== getBucketSummary =====

    public function test_getBucketSummary_returns_zeros_when_no_elementor_posts() {
        $summary = $this->hookController->getBucketSummary();

        $this->assertSame( 0, $summary['fresh'] );
        $this->assertSame( 0, $summary['daily'] );
        $this->assertSame( 0, $summary['weekly'] );
        $this->assertSame( 0, $summary['total'] );
        $this->assertSame( 0, $summary['parents'] );
    }

    public function test_getBucketSummary_counts_parents_even_when_they_have_no_revisions() {
        $this->makeElementorPost();
        $this->makeElementorPost();
        $this->makeElementorPost();

        $summary = $this->hookController->getBucketSummary();

        $this->assertSame( 3, $summary['parents'] );
        $this->assertSame( 0, $summary['total'], 'No revisions yet so the revision-bucket counts should be zero' );
    }

    public function test_getBucketSummary_buckets_revisions_by_age() {
        $parent_id = $this->makeElementorPost();
        // 4 fresh (< 7 days)
        $this->makeRevisionAt( $parent_id, 1 * DAY_IN_SECONDS );
        $this->makeRevisionAt( $parent_id, 2 * DAY_IN_SECONDS );
        $this->makeRevisionAt( $parent_id, 3 * DAY_IN_SECONDS );
        $this->makeRevisionAt( $parent_id, 6 * DAY_IN_SECONDS );
        // 2 in the daily bucket (7-30 days)
        $this->makeRevisionAt( $parent_id, 10 * DAY_IN_SECONDS );
        $this->makeRevisionAt( $parent_id, 25 * DAY_IN_SECONDS );
        // 3 in the weekly bucket (>= 30 days)
        $this->makeRevisionAt( $parent_id, 40 * DAY_IN_SECONDS );
        $this->makeRevisionAt( $parent_id, 60 * DAY_IN_SECONDS );
        $this->makeRevisionAt( $parent_id, 90 * DAY_IN_SECONDS );

        $summary = $this->hookController->getBucketSummary();

        $this->assertSame( 4, $summary['fresh'] );
        $this->assertSame( 2, $summary['daily'] );
        $this->assertSame( 3, $summary['weekly'] );
        $this->assertSame( 9, $summary['total'] );
        $this->assertSame( 1, $summary['parents'] );
    }

    public function test_getBucketSummary_ignores_revisions_of_non_elementor_posts() {
        $non_elementor_id = self::factory()->post->create();
        for ( $i = 0; $i < 5; $i++ ) {
            $this->makeRevisionAt( $non_elementor_id, $i * DAY_IN_SECONDS );
        }

        $summary = $this->hookController->getBucketSummary();

        $this->assertSame( 0, $summary['total'] );
        $this->assertSame( 0, $summary['parents'] );
    }

    // ===== buildManualPruneNotice =====

    public function test_buildManualPruneNotice_formats_completed_with_counts() {
        $notice = $this->hookController->buildManualPruneNotice( array(
            'kind'              => 'completed',
            'parents_processed' => 12,
            'revisions_deleted' => 47,
            'parents_pending'   => 0,
        ) );

        $this->assertSame( 1, $notice['type'], 'Completed pass is a success notice' );
        $this->assertStringContainsString( '12 parent posts', $notice['text'] );
        $this->assertStringContainsString( '47 revisions', $notice['text'] );
    }

    public function test_buildManualPruneNotice_formats_batch_with_pending_count() {
        $notice = $this->hookController->buildManualPruneNotice( array(
            'kind'              => 'batch',
            'parents_processed' => 200,
            'revisions_deleted' => 800,
            'parents_pending'   => 150,
        ) );

        $this->assertSame( 1, $notice['type'] );
        $this->assertStringContainsString( '200 parent posts', $notice['text'] );
        $this->assertStringContainsString( '800 revisions', $notice['text'] );
        $this->assertStringContainsString( '150 parents still pending', $notice['text'] );
    }

    public function test_buildManualPruneNotice_warns_when_disabled() {
        $notice = $this->hookController->buildManualPruneNotice( array( 'kind' => 'disabled' ) );

        $this->assertSame( 2, $notice['type'] );
        $this->assertStringContainsString( 'disabled', $notice['text'] );
    }

    public function test_buildManualPruneNotice_warns_when_elementor_inactive() {
        $notice = $this->hookController->buildManualPruneNotice( array( 'kind' => 'no_elementor' ) );

        $this->assertSame( 2, $notice['type'] );
        $this->assertStringContainsString( 'Elementor is not active', $notice['text'] );
    }

    public function test_buildManualPruneNotice_success_when_no_parents() {
        $notice = $this->hookController->buildManualPruneNotice( array( 'kind' => 'no_parents' ) );

        $this->assertSame( 1, $notice['type'] );
        $this->assertStringContainsString( 'No Elementor-built posts', $notice['text'] );
    }

    public function test_buildManualPruneNotice_warns_on_unknown_kind() {
        $notice = $this->hookController->buildManualPruneNotice( array( 'kind' => 'mystery' ) );

        $this->assertSame( 2, $notice['type'] );
    }

    /**
     * Fast bulk insert of Elementor-built parent posts via $wpdb to keep large-batch tests cheap.
     * @return int[]
     */
    private function createBulkElementorParents( int $count ): array {
        global $wpdb;
        $ids     = array();
        $now     = current_time( 'mysql' );
        $now_gmt = current_time( 'mysql', 1 );
        for ( $i = 0; $i < $count; $i++ ) {
            $wpdb->insert( $wpdb->posts, array(
                'post_author'           => 1,
                'post_date'             => $now,
                'post_date_gmt'         => $now_gmt,
                'post_modified'         => $now,
                'post_modified_gmt'     => $now_gmt,
                'post_title'            => 'bulk-' . $i,
                'post_content'          => '',
                'post_excerpt'          => '',
                'post_status'           => 'publish',
                'post_type'             => 'page',
                'post_name'             => 'bulk-' . $i . '-' . uniqid(),
                'comment_status'        => 'closed',
                'ping_status'           => 'closed',
                'to_ping'               => '',
                'pinged'                => '',
                'post_content_filtered' => '',
                'guid'                  => '',
            ) );
            $post_id = (int) $wpdb->insert_id;
            $wpdb->insert( $wpdb->postmeta, array(
                'post_id'    => $post_id,
                'meta_key'   => '_elementor_edit_mode',
                'meta_value' => 'builder',
            ) );
            $ids[] = $post_id;
        }
        return $ids;
    }
}
