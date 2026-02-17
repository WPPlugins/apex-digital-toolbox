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
    }
}
