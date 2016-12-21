<?php
// Forbid accessing directly
if ( ! defined( 'ABSPATH' ) ) {
	header( 'HTTP/1.1 401 Unauthorized' );
	exit;
}
// Ensure there are no conflicts
if ( ! class_exists( 'toolboxHookWordPressAdmin' ) ) {
	class toolboxHookWordPressAdmin extends toolboxHookController {
		public $_menu = Array();

		function __construct( $Toolbox ) {
			parent::__construct( $Toolbox );
			$this->setLabel( 'WordPress Administration' );
			$this->addHook( APEX_TOOLBOX_HOOK_ACTION, 'admin_menu', 'createAdminMenu', Array(
				'label'       => 'WordPress Menu',
				'description' => 'Adds the Apex Toolbox menus into the main WordPress menu.'
			) );
			$this->addHook( APEX_TOOLBOX_HOOK_ACTION, 'init', 'enableSessions', Array(
				'label'       => 'Sessions',
				'description' => 'Allows sessions to be used within the WordPress dashboard - they will be cleaned up on logout. This is used to help display notices via form submissions or other such functionality.',
				'priority'    => 1
			) );
			$this->addHook( APEX_TOOLBOX_HOOK_ACTION, 'admin_menu', 'findAndReplace', Array(
				'label'       => 'Find & Replace',
				'description' => 'Search the database for a text string and replace it with something new. Useful for domain name changes. Also checks & updates any visual composer output.'
			) );
		}

		/**
		 * Create WordPress administration menu
		 *
		 * @param array $args Any arguments passed to the callback
		 *
		 * @author Nigel Wells
		 * @version 0.3.1.16.10.10
		 * @return void;
		 */
		function createAdminMenu( $args = Array() ) {
			$this->addPage(
				'Hooks',
				Array( $this, 'outputHooksPage' )
			);
			$this->addPage(
				'Settings',
				Array( $this, 'outputSettingsPage' )
			);
			// Register the submission callback
			add_action( 'admin_action_hooksPageSubmission', Array( $this, 'hooksPageSubmission' ) );
			add_action( 'admin_action_settingsPageSubmission', Array( $this, 'settingsPageSubmission' ) );
		}

		/**
		 * Outputs the hooks page HTML
		 *
		 * @author Nigel Wells
		 * @version 0.3.5.16.11.01
		 * @return void;
		 */
		function outputHooksPage() {
			$savedHooks = $this->Toolbox->getOption( 'hooks' );
			echo '<div class="wrap">
				<h1>Apex Toolbox Hooks</h1>
				' . $this->displayNotices() . '
				<p>Below are the hooks currently available for activation. Once turned on some will provide additional functionality under <a href="' . admin_url( 'admin.php' ) . '?page=apex_toolbox_settings">settings</a> or other menu items.</p>
				<form method="post" action="' . admin_url( 'admin.php' ) . '?action=hooksPageSubmission">
				<input type="hidden" name="page" value="' . $_GET['page'] . '" />';
			foreach ( $this->Toolbox->getAvailableHookControllers() as $controllerObject ) {
				$availableHooks = $controllerObject->getHooks();
				if(count($availableHooks)) {
					echo '<h2>' . $controllerObject->getLabel() . '</h2>';
					foreach ( $availableHooks as $index => $hookModal ) {
						$checked  = false;
						$readOnly = false;
						if ( isset( $savedHooks[ $controllerObject->getName() ] ) && in_array( $hookModal->getMethod(), $savedHooks[ $controllerObject->getName() ] ) ) {
							$checked = true;
						}
						if ( $this->Toolbox->isHookDefault( $controllerObject->getName(), $hookModal->getMethod() ) ) {
							$checked  = true;
							$readOnly = true;
						}
						echo '<label><input type="checkbox" name="hooks[' . $controllerObject->getName() . '][]" value="' . $hookModal->getMethod() . '"' . ( $checked ? ' checked="checked"' : '' ) . ( $readOnly ? ' disabled="disabled"' : '' ) . '> ' . $hookModal->getLabel() . '</label>';
						if ( $hookModal->getDescription() ) {
							echo '<p class="description">' . $hookModal->getDescription() . '</p>';
						}
						echo '<br />';
					}
				}
			}
			echo submit_button( 'Update Hooks' ) . '
			</form>
			</div>';
		}

		/**
		 * Handles the submission of the hooks page
		 *
		 * @author Nigel Wells
		 * @version 0.3.1.16.10.10
		 * @return void;
		 */
		function hooksPageSubmission() {
			$hooks = ( isset( $_POST['hooks'] ) ? $_POST['hooks'] : Array() );
			$this->Toolbox->setOption( 'hooks', $hooks );
			// Note what happened and reload the page
			$this->addNotice( 'Hooks have been successfully updated' );
			$this->redirect();
		}

		/**
		 * Outputs the settings page HTML
		 *
		 * @author Nigel Wells
		 * @version 0.3.1.16.10.10
		 * @return void;
		 */
		function outputSettingsPage() {
			echo '<div class="wrap">
				<h1>Apex Toolbox Settings</h1>
				' . $this->displayNotices() . '
				<p>Settings available from the <a href="' . admin_url( 'admin.php' ) . '?page=apex_toolbox_hooks">hooks</a> that have been activated.</p>
				<form method="post" action="' . admin_url( 'admin.php' ) . '?action=settingsPageSubmission">
				<input type="hidden" name="page" value="' . $_GET['page'] . '" />';
			$settings = apply_filters( $this->Toolbox->getPrefix() . 'settings', Array() );
			if ( count( $settings ) ) {
				$oldSection = '';
				foreach ( $settings as $sections ) {
					foreach ( $sections as $sectionName => $fields ) {
						foreach ( $fields as $field ) {
							if ( $sectionName != $oldSection ) {
								if ( $oldSection ) {
									echo '</table>';
								}
								echo '<h2>' . $sectionName . '</h2>
					<table class="form-table">';
							}
							echo '<tr>
						<th scope="row">
							<label for="' . $field->getPrefix() . $field->getName() . '">' . __( $field->getLabel(), $field->getPrefix() ) . '</label>
						</th>
						<td>
							' . $field->outputField() . '
						</td>
					</tr>';
							$oldSection = $sectionName;
						}
					}
				}
				echo '</table>';
			}
			echo submit_button( 'Update Settings' ) . '
			</form>
			</div>';
		}

		/**
		 * Handles the submission of the hooks page
		 *
		 * @author Nigel Wells
		 * @version 0.3.1.16.10.10
		 * @return void;
		 */
		function settingsPageSubmission() {
			$settings = apply_filters( $this->Toolbox->getPrefix() . 'settings', Array() );
			if ( count( $settings ) ) {
				foreach ( $settings as $sections ) {
					foreach ( $sections as $sectionName => $fields ) {
						foreach ( $fields as $field ) {
							$settingValue = ( isset( $_POST[ $field->getPrefix() . $field->getName() ] ) ? $_POST[ $field->getPrefix() . $field->getName() ] : '' );
							$field->setValue( $settingValue );
							$this->Toolbox->setOption( $field->getName(), $field->getValue() );
						}
					}
				}
			}
			// Direct to success page
			$this->addNotice( 'Settings have been successfully updated' );
			$this->redirect();
		}

		/**
		 * Create a menu item for the plugin
		 *
		 * @param string $name Menu label
		 * @param array $callback Array containing the controller and callback function
		 *
		 * @author Nigel Wells
		 * @version 0.3.1.16.10.10
		 * @return void;
		 */
		public function addPage( $name, $callback ) {
			$slug                       = str_replace( '-', '_', sanitize_title( $name ) );
			$securityPermissionRequired = 'manage_options';
			// Keep track of what we've done
			$this->_menu[] = Array( 'name' => $name, 'slug' => $slug );
			// Create main menu item if needed
			if ( count( $this->_menu ) == 1 ) {
				add_menu_page(
					'Apex Toolbox',
					'Apex Toolbox',
					$securityPermissionRequired,
					$this->Toolbox->getPrefix() . $this->_menu[0]['slug'],
					$callback,
					'dashicons-admin-tools'
				);
			}
			// Create the sub page
			add_submenu_page(
				$this->Toolbox->getPrefix() . $this->_menu[0]['slug'],
				'Apex Toolbox | ' . $name,
				$name,
				$securityPermissionRequired,
				$this->Toolbox->getPrefix() . $slug,
				$callback
			);
		}

		/**
		 * Enable sessions to be used on the site
		 *
		 * @author Nigel Wells
		 * @version 0.3.1.16.10.10
		 * @return void;
		 */
		public function enableSessions( $args = Array() ) {
			if ( is_admin() ) {
				if ( ! session_id() ) {
					session_start();
				}
				add_action( 'wp_logout', Array( $this, 'sessionEnd' ) );
				add_action( 'wp_login', Array( $this, 'sessionEnd' ) );
			}
		}

		/**
		 * Destroy the session when logging out
		 *
		 * @author Nigel Wells
		 * @version 0.3.1.16.10.10
		 * @return void;
		 */
		public function sessionEnd() {
			session_destroy();
		}

		/**
		 * Add production url setting
		 *
		 * @param array $args Any arguments passed to the callback
		 *
		 * @author Nigel Wells
		 * @version 0.3.1.16.10.10
		 * @return void;
		 */
		public function findAndReplace( $args = Array() ) {
			$this->Toolbox->addPage(
				'Find & Replace',
				Array( $this, 'findAndReplaceOutput' )
			);
			// Register the submission callback
			add_action( 'admin_action_findAndReplace', Array( $this, 'findAndReplaceSubmission' ) );
		}

		/**
		 * Outputs the settings page HTML
		 *
		 * @author Nigel Wells
		 * @version 0.3.1.16.10.21
		 * @return void;
		 */
		function findAndReplaceOutput() {
			$search_text  = ( isset( $_GET[ $this->Toolbox->getPrefix() . 'search_text' ] ) ? $_GET[ $this->Toolbox->getPrefix() . 'search_text' ] : '' );
			$replace_text = ( isset( $_GET[ $this->Toolbox->getPrefix() . 'replace_text' ] ) ? $_GET[ $this->Toolbox->getPrefix() . 'replace_text' ] : '' );
			echo '<div class="wrap">
				<h1>Apex Toolbox Find &amp; Replace</h1>
				' . $this->displayNotices() . '
				<p>Search the wp_posts table in the database for a text string and replace it with something new. Useful for domain name changes.' . ( function_exists( 'vc_map' ) ? ' Also checks &amp; updates any visual composer output.' : '' ) . '</p>
				<form method="post" action="' . admin_url( 'admin.php' ) . '?action=findAndReplace">
				<input type="hidden" name="page" value="' . $_GET['page'] . '" />
				<table class="form-table">
				<tr>
					<th scope="row">
						<label for="' . $this->Toolbox->getPrefix() . 'search_text">' . __( 'Search Text', $this->Toolbox->getPrefix() ) . '</label>
					</th>
					<td>
						<input class="regular-text" type="text" required="required" id="' . $this->Toolbox->getPrefix() . 'search_text" name="' . $this->Toolbox->getPrefix() . 'search_text" value="' . $search_text . '" />
						<p class="description">Search through the database for this text</p>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="' . $this->Toolbox->getPrefix() . 'replace_text">' . __( 'Replace Text', $this->Toolbox->getPrefix() ) . '</label>
					</th>
					<td>
						<input class="regular-text" type="text" required="required" id="' . $this->Toolbox->getPrefix() . 'replace_text" name="' . $this->Toolbox->getPrefix() . 'replace_text" value="' . $replace_text . '" />
						<p class="description">Replace any text found above with this text</p>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="' . $this->Toolbox->getPrefix() . 'is_url">' . __( 'Replacing a URL', $this->Toolbox->getPrefix() ) . '</label>
					</th>
					<td>
						<input type="checkbox" id="' . $this->Toolbox->getPrefix() . 'is_url" name="' . $this->Toolbox->getPrefix() . 'is_url" value="1"' . ( isset( $_GET[ $this->Toolbox->getPrefix() . 'is_url' ] ) && intval( $_GET[ $this->Toolbox->getPrefix() . 'is_url' ] ) ? ' checked="checked"' : '' ) . ' />
						<p class="description">This will run a replace on the options table as well. We don\'t want to accidently break anything so the search string will be verified first.</p>
						' . ( function_exists( 'vc_map' ) ? '<p>Visual composer also stores URLs in an encoded format so we need to know if the above string has to be encoded likewise</p>' : '' ) . '
					</td>
				</tr>';
			// Show available post types to update
			echo '<tr>
					<th scope="row">
						<label for="' . $this->Toolbox->getPrefix() . 'post_types">' . __( 'Post Types', $this->Toolbox->getPrefix() ) . '</label>
					</th>
					<td>';
			// Get list of checked types
			if ( isset( $_GET[ $this->Toolbox->getPrefix() . 'post_types' ] ) ) {
				$checked = $_GET[ $this->Toolbox->getPrefix() . 'post_types' ];
			} else {
				$checked = Array( 'post', 'page', 'nav_menu_item' );
			}
			foreach ( get_post_types() as $post_type ) {
				echo '<label><input type="checkbox" name="' . $this->Toolbox->getPrefix() . 'post_types[]" value="' . $post_type . '"' . ( in_array( $post_type, $checked ) ? ' checked="checked"' : '' ) . ' /> ' . $post_type . '</label><br />';
			}
			echo '</td>
				</tr>
				</table>';
			echo submit_button( 'Find Matches' );
			// Show results from query if available
			if ( $search_text && $replace_text && isset( $_SESSION[ $this->Toolbox->getPrefix() . 'find_replace_queries' ] ) && count( $_SESSION[ $this->Toolbox->getPrefix() . 'find_replace_queries' ] ) ) {
				global $wpdb;
				$html = '';
				foreach ( $_SESSION[ $this->Toolbox->getPrefix() . 'find_replace_queries' ] as $query ) {
					$results = $wpdb->get_results( $query['select'] );
					if ( count( $results ) ) {
						foreach ( $results as $row ) {
							$textResults = $this->formatSearchResult( $row->{$query['column']}, $search_text );
							$html .= '<tr>
							<td>' . $query['table'] . '</td>
							<td>' . $query['column'] . '</td>
							<td>' . $row->{$query['id']} . '</li>
							<td>' . ( $query['table'] == 'posts' ? get_post_type( $row->{$query['id']} ) : '' ) . '</li>
							<td>' . implode( '<br />', $textResults ) . '</td>
							<td>';
							// Show various action buttons
							if ( $query['table'] == 'posts' ) {
								$html .= '<a href="post.php?post=' . $row->{$query['id']} . '&amp;action=edit" target="_blank">Edit</a>';
							}
							$html .= '</td>
						</tr>';
						}
					}
				}
				// Display results if found
				if ( $html ) {
					echo '<table class="widefat">
					<thead>
						<tr>
							<th>Table</th>
							<th>Column</th>
							<th>ID</th>
							<th>Type</th>
							<th>Search Match</th>
							<th>Action</th>
						</tr>
					</thead>
					<tbody>' . $html . '</tbody>
					</table>';
					echo submit_button( 'Replace `' . $search_text . '` with `' . $replace_text . '` in matches found above', 'primary', 'submit_replace' );
				} else {
					echo '<p>Nothing found....</p>';
				}
			}
			echo '</form>
			</div>';
		}

		/**
		 * Handles the submission of the hooks page
		 *
		 * @author Nigel Wells
		 * @version 0.3.1.16.12.20
		 * @return void;
		 */
		function findAndReplaceSubmission() {
			global $wpdb, $table_prefix;
			if ( ! $table_prefix ) {
				$table_prefix = '';
			}
			// Get the search strings
			$search_text  = trim( $_POST[ $this->Toolbox->getPrefix() . 'search_text' ] );
			$replace_text = trim( $_POST[ $this->Toolbox->getPrefix() . 'replace_text' ] );
			$is_url       = ( isset( $_POST[ $this->Toolbox->getPrefix() . 'is_url' ] ) ? intval( $_POST[ $this->Toolbox->getPrefix() . 'is_url' ] ) : 0 );
			$post_types   = $_POST[ $this->Toolbox->getPrefix() . 'post_types' ];
			if ( ! is_array( $post_types ) ) {
				$post_types = Array();
			}
			if ( $search_text && $replace_text && count( $post_types ) ) {
				// Format the strings to make sure they are valid URLs if that's what is required
				if ( $is_url ) {
					$search_text  = esc_url( $search_text );
					$replace_text = esc_url( $replace_text );
				}
				// Tables and columns to update
				$tables = Array(
					'posts'   => Array(
						'columns' => Array(
							'post_title'   => '(`post_type` = "' . implode( '" OR `post_type` = "', $post_types ) . '")',
							'post_content' => '(`post_type` = "' . implode( '" OR `post_type` = "', $post_types ) . '")',
							'post_excerpt' => '(`post_type` = "' . implode( '" OR `post_type` = "', $post_types ) . '")',
						),
						'id'      => 'ID',
					),
					'options' => Array(
						'columns' => Array(
							'option_value' => '`option_name` LIKE "widget_%%"',
						),
						'id'      => 'option_id',
					)
				);
				// If updating a URL then allow updating of any option
				if ( $is_url ) {
					$tables['options']['columns']['option_value'] = '';
					$tables['postmeta']['columns']['meta_value'] = '';
					$tables['postmeta']['id'] = 'meta_id';
				}
				// Make sure the post types are legitimate
				$validPostTypes = get_post_types();
				foreach ( $post_types as $index => $post_type ) {
					if ( ! in_array( $post_type, $validPostTypes ) ) {
						unset( $post_types[ $index ] );
					}
				}
				// Build the queries
				$queries = Array();
				foreach ( $tables as $table => $tableData ) {
					foreach ( $tableData['columns'] as $column => $columnWhere ) {
						// Generic WordPress update
						$sqlUpdate = $wpdb->prepare( 'UPDATE `' . $table_prefix . $table . '` SET `' . $column . '` = REPLACE(`' . $column . '`, "%s", "%s")' . ( $columnWhere ? ' WHERE ' . $columnWhere : '' ), Array(
							$search_text,
							$replace_text
						) );
						$sqlSelect = $wpdb->prepare( 'SELECT `' . $tableData['id'] . '`, `' . $column . '` FROM `' . $table_prefix . $table . '` WHERE `' . $column . '` LIKE "%%%s%%"' . ( $columnWhere ? ' AND ' . $columnWhere : '' ), Array(
							$search_text,
						) );
						$queries[] = Array(
							'select' => $sqlSelect,
							'update' => $sqlUpdate,
							'table'  => $table,
							'column' => $column,
							'id'     => $tableData['id'],
						);
						// Encoded version for visual composer
						if ( $is_url && function_exists( 'vc_map' ) && $table == 'posts' ) {
							$sqlUpdate = $wpdb->prepare( 'UPDATE `' . $table_prefix . $table . '` SET `' . $column . '` = REPLACE(`' . $column . '`, "%s", "%s")' . ( $columnWhere ? ' WHERE ' . $columnWhere : '' ), Array(
								rawurlencode( $search_text ),
								rawurlencode( $replace_text )
							) );
							$sqlSelect = $wpdb->prepare( 'SELECT `ID`, ' . $column . '` FROM `' . $table_prefix . $table . '` WHERE `' . $column . '` LIKE "%%%s%%"' . ( $columnWhere ? ' AND ' . $columnWhere : '' ), Array(
								rawurlencode( $search_text ),
								rawurlencode( $replace_text )
							) );
							$queries[] = Array(
								'select' => $sqlSelect,
								'update' => $sqlUpdate,
							);
						}
					}
				}
				if ( isset( $_POST['submit'] ) ) {
					$_SESSION[ $this->Toolbox->getPrefix() . 'find_replace_queries' ] = $queries;
					$this->addNotice( 'Dry run completed with results displayed below' );
				} elseif ( isset( $_POST['submit_replace'] ) ) {
					$updated = 0;
					foreach ( $queries as $query ) {
						$rowsAffected = 0;
						// Some options are serialized so we need to update this slightly differently
						if ( $query['table'] == 'options' ) {
							$results = $wpdb->get_results( $query['select'] );
							if ( count( $results ) ) {
								foreach ( $results as $row ) {
									$option_id   = $row->{$query['id']};
									$sql         = $wpdb->prepare( 'SELECT option_name FROM `' . $table_prefix . $query['table'] . '` WHERE `' . $query['id'] . '` = "%s"', Array(
										$option_id
									) );
									$option_name = $wpdb->get_var( $sql );
									if ( $option_name ) {
										$option_value = get_option( $option_name );
										if ( is_array( $option_value ) ) {
											$_SESSION[ $this->Toolbox->getPrefix() . 'find_replace_recursive' ] = 0;
											array_walk_recursive( $option_value, Array( $this, 'replaceArrayValues' ), Array(
												'search_text'  => $search_text,
												'replace_text' => $replace_text
											) );
											$rowsAffected = $_SESSION[ $this->Toolbox->getPrefix() . 'find_replace_recursive' ];
											unset( $_SESSION[ $this->Toolbox->getPrefix() . 'find_replace_recursive' ] );
											// Update the option
											update_option( $option_name, $option_value );
										}
									}
								}
							}
						} else {
							$rowsAffected = $wpdb->query( $query['update'] );
						}
						$updated += $rowsAffected;
					}
					$this->addNotice( 'Database updated with <strong>' . $updated . ' row' . ( $updated != 1 ? 's' : '' ) . '</strong> affected' );
					// Reset the queries
					$_SESSION[ $this->Toolbox->getPrefix() . 'find_replace_queries' ] = Array();
				}
			} else {
				$this->addNotice( 'Search &amp; replace strings need to both be provided as well as at least one post type checked' );
			}
			$this->redirect( $_POST['page'] . '&' . http_build_query( Array(
					$this->Toolbox->getPrefix() . 'search_text'  => $search_text,
					$this->Toolbox->getPrefix() . 'replace_text' => $replace_text,
					$this->Toolbox->getPrefix() . 'is_url'       => $is_url,
					$this->Toolbox->getPrefix() . 'post_types'   => $post_types,
				) )
			);
		}

		/**
		 * Loop through any text found and extract the search string to make it look better in the search results
		 *
		 * @param string $text Text containing the search term to highlight
		 * @param string $searchText Search term to locate in the text
		 *
		 * @author Nigel Wells
		 * @version 0.3.1.16.10.28
		 * @return array;
		 */
		private function formatSearchResult( $text, $searchText ) {
			// Varables
			$position = 50;
			$results  = Array();

			// Find all instances in the text
			$foundPositions = Array();
			$pos            = 0;
			while ( $pos !== false ) {
				$pos = strpos( strtolower( $text ), strtolower( $searchText ), $pos );
				if ( $pos !== false ) {
					$foundPositions[] = $pos;
					$pos ++;
				}
			}
			// Loop through all instances found
			foreach ( $foundPositions as $pos ) {
				$formattedText = $text;
				$posStart      = $pos - $position;
				$posFinish     = $pos + strlen( $searchText ) + $position;
				$appendDots    = true;
				if ( $posStart < 0 ) {
					$posFinish = $posFinish - $posStart;
					$posStart  = 0;
					if ( $posFinish > strlen( $formattedText ) ) {
						$posFinish  = strlen( $formattedText );
						$appendDots = false;
					}
				} elseif ( $posFinish > strlen( $formattedText ) ) {
					$posStart   = $posStart - ( $posFinish - strlen( $formattedText ) );
					$posFinish  = strlen( $formattedText );
					$appendDots = false;
				}
				$formattedText = substr( $formattedText, $posStart, ( $posFinish - $posStart ) );
				if ( $posStart > 0 ) {
					$formattedText = '...' . $formattedText;
				}
				if ( $appendDots ) {
					$formattedText .= '...';
				}
				$formattedText = htmlentities( $formattedText );

				// Make the text found bold
				$pos = strpos( strtolower( $formattedText ), strtolower( $searchText ) );
				while ( is_int( $pos ) ) {
					$strOld        = substr( $formattedText, $pos, strlen( $searchText ) );
					$strNew        = '<code><b>' . $strOld . '</b></code>';
					$formattedText = substr( $formattedText, 0, $pos ) . $strNew . substr( $formattedText, ( $pos + strlen( $searchText ) ), strlen( $formattedText ) - ( $pos + strlen( $searchText ) ) );
					$pos           = $pos + strlen( $strNew );
					$pos           = strpos( strtolower( $formattedText ), strtolower( $searchText ), $pos );
				}
				$results[] = $formattedText;
			}

			return $results;
		}

		/**
		 * Walker for replacing values in an array for updating option values
		 *
		 * @param string $item Value of the array
		 * @param string $key Key in the array
		 * @param array $args Array containing the search & replace search terms
		 *
		 * @author Nigel Wells
		 * @version 0.3.1.16.10.28
		 * @return void;
		 */
		private function replaceArrayValues( &$item, $key, $args ) {
			if ( strpos( $item, $args['search_text'] ) !== false ) {
				$item = str_replace( $args['search_text'], $args['replace_text'], $item );
				$_SESSION[ $this->Toolbox->getPrefix() . 'find_replace_recursive' ] ++;
			}
		}

	}
}