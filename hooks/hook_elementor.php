<?php
// Forbid accessing directly
if ( ! defined( 'ABSPATH' ) ) {
    header( 'HTTP/1.1 401 Unauthorized' );
    exit;
}
// Ensure there are no conflicts
if ( ! class_exists( 'toolboxHookElementor' ) ) {
    class toolboxHookElementor extends toolboxHookController {
      function __construct( $Toolbox ) {
        parent::__construct( $Toolbox );
        $this->setLabel( 'Elementor' );
        $this->addHook( APEX_TOOLBOX_HOOK_FILTER, 'init', 'registerWidgetListCategories', array(
            'label'       => 'List Categories - Widget',
            'description' => 'Show a list of taxonomy links based on their parent and split them in them into columns with dividers',
        ) );
        $this->addHook( APEX_TOOLBOX_HOOK_ACTION, 'upgrader_process_complete', 'fixElementorCssAfterUpdate', array(
            'label'       => 'Fix CSS Regeneration After Plugin Updates',
            'description' => 'Automatically regenerate Elementor CSS files after plugin updates to prevent broken styles. Only regenerates affected posts for better performance.',
            'priority'    => 20,
            'args'        => 2,
        ) );
        $this->addHook( APEX_TOOLBOX_HOOK_ACTION, 'elementor/css-file/before_enqueue', 'healCssFileBeforeEnqueue', array(
            'label'       => 'Heal Missing Elementor CSS Files (per-request)',
            'description' => 'When Elementor is about to enqueue a post-CSS file whose meta says "file" but the file is missing on disk, regenerate it just-in-time. Falls back to clearing meta (forcing inline regen on next request) if write keeps failing. Logs and counts every occurrence.',
            'priority'    => 5,
            'args'        => 1,
        ) );
        $this->addHook( APEX_TOOLBOX_HOOK_ACTION, 'admin_bar_menu', 'adminBarCssHealStats', array(
            'label'       => 'CSS Heal Stats in Admin Bar',
            'description' => 'Show a small counter in the admin bar with the running totals of successful and failed CSS heals.',
            'priority'    => 999,
            'args'        => 1,
        ) );
      }

      /**
       * Register the list categories widget with Elementor
       */
      public function registerWidgetListCategories( ) {
          $this->includeFile('WidgetListCategories.php');
      }

      /**
       * Include an Elementor specific file
       *
       * @param $file
       */
      private function includeFile( $file ) {
        $this->Toolbox->includeFile( 'elementor/' . $file );
      }

      /**
       * Immediately regenerate only affected Elementor CSS files after plugin update
       *
       * @param WP_Upgrader $upgrader_object
       * @param array $options
       */
      public function fixElementorCssAfterUpdate( $upgrader_object, $options ) {
          // Only run for plugin updates
          if ( $options['type'] !== 'plugin' ) {
              return;
          }

          // Register the scheduled action hook
          add_action( 'regenerate_all_elementor_css', array( $this, 'regenerateAffectedElementorCss' ) );

          // Run immediately if we can, otherwise schedule
          if ( ! wp_doing_cron() && ! defined( 'DOING_AUTOSAVE' ) ) {
              $this->regenerateAffectedElementorCss();
          } else {
              wp_schedule_single_event( time() + 30, 'regenerate_all_elementor_css' );
          }
      }

      /**
       * Regenerate CSS only for posts with missing or invalid CSS files
       */
      public function regenerateAffectedElementorCss() {
          if ( ! class_exists( '\Elementor\Plugin' ) ) {
              return;
          }

          global $wpdb;

          // Get only published posts built with Elementor (excluding revisions and drafts)
          $post_ids = $wpdb->get_col(
              "SELECT DISTINCT p.ID
               FROM {$wpdb->posts} p
               INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
               WHERE pm.meta_key = '_elementor_edit_mode'
               AND pm.meta_value = 'builder'
               AND p.post_status = 'publish'
               AND p.post_type NOT IN ('revision', 'auto-draft')"
          );

          if ( empty( $post_ids ) ) {
              return;
          }

          $affected_posts = array();
          $uploads_dir    = wp_upload_dir();
          $css_base_path  = $uploads_dir['basedir'] . '/elementor/css/';

          // Regenerate Kit CSS first (global colors/fonts) - always needed
          try {
              $kit = \Elementor\Plugin::$instance->kits_manager->get_active_kit();
              if ( $kit ) {
                  $kit_css = \Elementor\Core\Files\CSS\Post::create( $kit->get_id() );
                  $kit_css->update();
                  error_log( "Elementor CSS Fix: Regenerated Kit CSS (ID: {$kit->get_id()})" );
              }
          } catch ( \Exception $e ) {
              error_log( 'Elementor CSS Fix: Failed to regenerate Kit CSS: ' . $e->getMessage() );
          }

          // Check which posts actually need regeneration
          foreach ( $post_ids as $post_id ) {
              $needs_regeneration = false;

              // Check if CSS file exists
              $css_filename  = "post-{$post_id}.css";
              $css_file_path = $css_base_path . $css_filename;

              if ( ! file_exists( $css_file_path ) ) {
                  $needs_regeneration = true;
              } else {
                  // Check if meta is missing or invalid
                  $meta = get_post_meta( $post_id, \Elementor\Core\Files\CSS\Post::META_KEY, true );

                  if ( empty( $meta ) || empty( $meta['status'] ) || $meta['status'] === '' ) {
                      $needs_regeneration = true;
                  } else if ( $meta['status'] === 'file' ) {
                      // File should exist but verify it's not empty
                      if ( filesize( $css_file_path ) < 100 ) {
                          // Suspiciously small CSS file - likely incomplete
                          $needs_regeneration = true;
                      }
                  }
              }

              if ( $needs_regeneration ) {
                  $affected_posts[] = $post_id;
              }
          }

          // Only regenerate affected posts
          if ( empty( $affected_posts ) ) {
              error_log( 'Elementor CSS Fix: No affected posts found - all CSS files are valid' );
              return; // Nothing to do!
          }

          $regenerated = 0;
          $batch_limit = 500; // Process max 500 per execution

          foreach ( array_slice( $affected_posts, 0, $batch_limit ) as $post_id ) {
              try {
                  $post       = get_post( $post_id );
                  $post_title = $post ? $post->post_title : 'Unknown';
                  $post_type  = $post ? $post->post_type : 'unknown';

                  $css_file = \Elementor\Core\Files\CSS\Post::create( $post_id );
                  $css_file->update();
                  $regenerated++;

                  error_log( "Elementor CSS Fix: Regenerated CSS for '{$post_title}' (ID: {$post_id}, Type: {$post_type})" );
              } catch ( \Exception $e ) {
                  error_log( "Elementor CSS Fix: Failed to regenerate CSS for post {$post_id}: " . $e->getMessage() );
              }
          }

          // If there are more affected posts, schedule next batch
          if ( count( $affected_posts ) > $batch_limit ) {
              $remaining = count( $affected_posts ) - $batch_limit;
              wp_schedule_single_event( time() + 30, 'regenerate_all_elementor_css' );
              error_log( "Elementor CSS Fix: Regenerated {$regenerated} posts, {$remaining} remaining - scheduled next batch" );
          } else {
              error_log( "Elementor CSS Fix: Complete - regenerated {$regenerated} affected posts out of " . count( $post_ids ) . ' total published Elementor posts' );
          }
      }

      /**
       * Per-request self-heal for Elementor post-CSS files.
       *
       * Elementor's enqueue path (Elementor\Core\Files\Base::enqueue) trusts post-meta
       * status === 'file' without verifying the file is on disk, so a missing file +
       * lying meta ships a broken <link>. This regenerates the file just-in-time
       * before Elementor's own wp_enqueue_style call runs, and falls back to
       * clearing the meta so the next request rebuilds from scratch.
       *
       * @param mixed $css_file Expected: \Elementor\Core\Files\Base
       */
      public function healCssFileBeforeEnqueue( $css_file ) {
          // Kill switch
          if ( defined( 'APEX_CSS_HEAL_DISABLED' ) && APEX_CSS_HEAL_DISABLED ) {
              return;
          }

          // Only act on per-post CSS — kit/preview/global have different lifecycles
          if ( ! class_exists( '\Elementor\Core\Files\CSS\Post' ) ) {
              return;
          }
          if ( ! ( $css_file instanceof \Elementor\Core\Files\CSS\Post ) ) {
              return;
          }

          // Front-end page renders only — broken <link>s only ship there
          if ( is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
              return;
          }
          if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
              return;
          }

          // Re-entry guard in case another plugin chains enqueues during update()
          static $in_heal = false;
          if ( $in_heal ) {
              return;
          }

          $meta = $css_file->get_meta();
          if ( ! is_array( $meta ) || empty( $meta['status'] ) || $meta['status'] !== 'file' ) {
              return;
          }

          $path = $css_file->get_path();
          if ( ! $path ) {
              return;
          }

          // Disk is fine — nothing to heal
          if ( file_exists( $path ) && filesize( $path ) > 0 ) {
              return;
          }

          // Per-file lock so concurrent requests don't dog-pile regeneration
          $lock_key = 'apex_css_heal_lock_' . md5( $path );
          if ( get_transient( $lock_key ) ) {
              return;
          }
          set_transient( $lock_key, 1, 30 );

          $post_id = method_exists( $css_file, 'get_post_id' ) ? intval( $css_file->get_post_id() ) : 0;

          $in_heal = true;
          try {
              try {
                  $css_file->update();
              } catch ( \Throwable $e ) {
                  error_log( '[Apex CSS Heal] Exception during regen for ' . $path . ': ' . $e->getMessage() );
              }

              if ( file_exists( $path ) && filesize( $path ) > 0 ) {
                  error_log( '[Apex CSS Heal] Regenerated missing CSS: ' . $path );
                  update_option( 'apex_css_heal_total', intval( get_option( 'apex_css_heal_total', 0 ) ) + 1, false );
                  update_option( 'apex_css_heal_last_at', time(), false );
                  update_option( 'apex_css_heal_last_path', $path, false );
              } else {
                  error_log( '[Apex CSS Heal] FAILED regen for ' . $path . ' — switching to inline fallback' );
                  // Clear the meta so the next request treats this as a fresh post
                  // and rebuilds from scratch (Elementor will compile inline if it can't write).
                  if ( $post_id ) {
                      delete_post_meta( $post_id, '_elementor_css' );
                  }
                  update_option( 'apex_css_heal_failed_total', intval( get_option( 'apex_css_heal_failed_total', 0 ) ) + 1, false );
                  update_option( 'apex_css_heal_last_failure_at', time(), false );
                  update_option( 'apex_css_heal_last_failure_path', $path, false );

                  $this->maybeSendCssHealFailureAlert( $path, $post_id );
              }
          } finally {
              $in_heal = false;
              delete_transient( $lock_key );
          }
      }

      /**
       * Send a single failure-alert email per cooldown window.
       *
       * @param string $path    Path of the CSS file that failed to regenerate.
       * @param int    $post_id Post ID whose CSS failed.
       */
      private function maybeSendCssHealFailureAlert( $path, $post_id ) {
          if ( defined( 'APEX_CSS_HEAL_NO_EMAIL' ) && APEX_CSS_HEAL_NO_EMAIL ) {
              return;
          }
          if ( get_transient( 'apx_css_heal_alert_sent' ) ) {
              return;
          }
          $to = ( defined( 'APEX_CSS_HEAL_ALERT_EMAIL' ) && APEX_CSS_HEAL_ALERT_EMAIL )
              ? APEX_CSS_HEAL_ALERT_EMAIL
              : get_option( 'admin_email' );
          if ( ! $to ) {
              return;
          }
          $host     = isset( $_SERVER['HTTP_HOST'] ) ? $_SERVER['HTTP_HOST'] : parse_url( home_url(), PHP_URL_HOST );
          $subject  = '[Apex CSS Heal] Failed regeneration on ' . $host;
          $failures = intval( get_option( 'apex_css_heal_failed_total', 0 ) );
          $body     = "Elementor CSS regeneration failed on {$host}.\n\n"
                    . "File: {$path}\n"
                    . "Post ID: {$post_id}\n"
                    . "Total failures: {$failures}\n\n"
                    . 'Meta has been cleared so the next request will regenerate from scratch.';
          wp_mail( $to, $subject, $body );
          set_transient( 'apx_css_heal_alert_sent', 1, HOUR_IN_SECONDS );
      }

      /**
       * Surface CSS-heal counters in the admin bar for quick visibility.
       *
       * @param WP_Admin_Bar $wp_admin_bar
       */
      public function adminBarCssHealStats( $wp_admin_bar ) {
          if ( ! current_user_can( 'manage_options' ) ) {
              return;
          }
          $total    = intval( get_option( 'apex_css_heal_total', 0 ) );
          $failures = intval( get_option( 'apex_css_heal_failed_total', 0 ) );
          if ( $total === 0 && $failures === 0 ) {
              return;
          }
          $last_at   = intval( get_option( 'apex_css_heal_last_at', 0 ) );
          $last_path = get_option( 'apex_css_heal_last_path', '' );
          $title     = sprintf( 'CSS Heal: %d ok | %d fail', $total, $failures );
          $tooltip   = $last_at
              ? 'Last heal: ' . human_time_diff( $last_at, time() ) . ' ago — ' . $last_path
              : '';
          $wp_admin_bar->add_node( array(
              'id'    => 'apex_css_heal',
              'title' => esc_html( $title ),
              'meta'  => array( 'title' => $tooltip ),
          ) );
      }
    }
}
