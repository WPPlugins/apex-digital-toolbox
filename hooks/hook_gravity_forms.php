<?php
// Forbid accessing directly
if ( ! defined( 'ABSPATH' ) ) {
	header( 'HTTP/1.1 401 Unauthorized' );
	exit;
}
// Ensure there are no conflicts
if ( ! class_exists( 'toolboxHookGravityForms' ) ) {
	class toolboxHookGravityForms extends toolboxHookController {
		function __construct( $Toolbox ) {
			parent::__construct( $Toolbox );
			$this->setLabel( 'Gravity Forms' );
			$installed = false;
			if ( is_plugin_active( 'gravityforms/gravityforms.php' ) ) {
				$installed = true;
			}
			$this->addHook( APEX_TOOLBOX_HOOK_ACTION, 'init', 'setupHooks', Array(
				'label'       => 'Add Bootstrap classes & columns' . ( ! $installed ? ' (GRAVITY FORMS NOT INSTALLED)' : '' ),
				'description' => 'Add Bootstrap classes to input fields to provide a more constant styling experience as well as a column divider - requires extra CSS for columns'
			) );
		}

		/**
		 * Add various hooks to be used to expand Gravity Forms
		 *
		 * @param array $args Any arguments passed to the callback
		 *
		 * @author Nigel Wells
		 * @version 0.3.9.17.03.21
		 * @return void;
		 */
		public function setupHooks( $args = Array() ) {
			add_filter( 'gform_field_content', Array( $this, 'addBootstrapInputClasses' ), 10, 5 );
			add_filter( 'gform_submit_button', Array( $this, 'addBootstrapButtonClasses' ), 10, 2 );
			GF_Fields::register( new GF_Field_Column() );
			GF_Fields::register( new GF_Field_Submit() );
			add_action( 'gform_field_standard_settings', function ( $placement, $form_id ) {
				if ( $placement == 0 ) {
					echo '<li class="column_description field_setting">Column breaks should be placed between fields to split form into separate columns. You do not need to place any column breaks at the beginning or end of the form, only in the middle.</li>';
					echo '<li class="submit_description field_setting">Used to place the submit button anywhere in the form rather than just in the footer. Requires the footer icon being hidden via CSS.</li>';
				}
			}, 10, 2 );
			add_filter( 'gform_field_container', function ( $field_container, $field, $form, $css_class, $style, $field_content ) {
				if ( IS_ADMIN ) {
					return $field_container;
				}
				if ( $field['type'] == 'column' ) {
					$column_index = 2;
					foreach ( $form['fields'] as $form_field ) {
						if ( $form_field['id'] == $field['id'] ) {
							break;
						}
						if ( $form_field['type'] == 'column' ) {
							$column_index ++;
						}
					}

					return '</ul><ul class="' . GFCommon::get_ul_classes( $form ) . ' column column_' . $column_index . ' ' . $field->cssClass . '">';
				} elseif ( $field['type'] == 'submit' ) {
					$button_input = GFFormDisplay::get_form_button( $form['id'], "gform_submit_button_{$form['id']}", $form['button'], __( 'Submit', 'gravityforms' ), 'gform_button', __( 'Submit', 'gravityforms' ), 0 );
					$button_input = gf_apply_filters( array( 'gform_submit_button', $form['id'] ), $button_input, $form );
					$field_container = str_replace('</li>', $button_input . '</li>', $field_container);
				}
				return $field_container;
			}, 10, 6 );
		}

		/**
		 * Add Bootstrap classes to input fields to provide a more constant styling experience
		 *
		 * @param array $args Any arguments passed to the callback
		 *
		 * @author Nigel Wells
		 * @version 0.3.9.17.03.21
		 * @return string;
		 */
		public function addBootstrapInputClasses( $field_content, $field, $value, $entry_id, $form_id ) {
			// Currently only applies to most common field types, but could be expanded.
			switch ( $field->type ) {
				case 'hidden' :
				case 'list' :
				case 'multiselect' :
				case 'fileupload' :
				case 'date' :
				case 'html' :
					break;
				case 'name' :
				case 'address' :
					$field_content = str_replace( '<input ', '<input class=\'form-control\' ', $field_content );
					break;
				case 'textarea' :
					$field_content = str_replace( 'class=\'textarea', 'class=\'form-control textarea', $field_content );
					break;
				case 'checkbox' :
					$field_content = str_replace( 'li class=\'', 'li class=\'checkbox ', $field_content );
					$field_content = str_replace( '<input ', '<input style=\'margin-left:1px;\' ', $field_content );
					break;
				case 'radio' :
					$field_content = str_replace( 'li class=\'', 'li class=\'radio ', $field_content );
					$field_content = str_replace( '<input ', '<input style=\'margin-left:1px;\' ', $field_content );
					break;
				default :
					$field_content = str_replace( 'class=\'medium', 'class=\'form-control medium', $field_content );
					$field_content = str_replace( 'class=\'large', 'class=\'form-control large', $field_content );
					break;
			}

			return $field_content;
		}

		/**
		 * Add Bootstrap classes to button fields to provide a more constant styling experience
		 *
		 * @author Nigel Wells
		 * @version 0.3.9.17.03.21
		 * @return string;
		 */
		public function addBootstrapButtonClasses( $button_input, $form ) {
			$button_input = str_replace( 'class=\'gform_button', 'class=\'btn btn-primary gform_button', $button_input );

			return $button_input;
		}

	}
}

if ( ! class_exists( 'GF_Field_Column' ) && class_exists( 'GF_Field' ) ) {
	class GF_Field_Column extends GF_Field {

		public $type = 'column';

		public function get_form_editor_field_title() {
			return esc_attr__( 'Column Break', 'gravityforms' );
		}

		public function is_conditional_logic_supported() {
			return false;
		}

		function get_form_editor_field_settings() {
			return array(
				'column_description',
				'css_class_setting'
			);
		}

		public function get_field_input( $form, $value = '', $entry = null ) {
			return '';
		}

		public function get_field_content( $value, $force_frontend_label, $form ) {

			$is_entry_detail = $this->is_entry_detail();
			$is_form_editor  = $this->is_form_editor();
			$is_admin        = $is_entry_detail || $is_form_editor;

			if ( $is_admin ) {
				$admin_buttons = $this->get_admin_buttons();

				return $admin_buttons . '<label class=\'gfield_label\'>' . $this->get_form_editor_field_title() . '</label>{FIELD}<hr>';
			}

			return '';
		}

	}
}

if ( ! class_exists( 'GF_Field_Submit' ) && class_exists( 'GF_Field' ) ) {
	class GF_Field_Submit extends GF_Field {

		public $type = 'submit';

		public function get_form_editor_field_title() {
			return esc_attr__( 'Submit Button', 'gravityforms' );
		}

		public function is_conditional_logic_supported() {
			return false;
		}

		function get_form_editor_field_settings() {
			return array(
				'submit_description',
				'css_class_setting'
			);
		}

		public function get_field_input( $form, $value = '', $entry = null ) {
			return '';
		}

		public function get_field_content( $value, $force_frontend_label, $form ) {

			$is_entry_detail = $this->is_entry_detail();
			$is_form_editor  = $this->is_form_editor();
			$is_admin        = $is_entry_detail || $is_form_editor;

			if ( $is_admin ) {
				$admin_buttons = $this->get_admin_buttons();

				return $admin_buttons . '<label class=\'gfield_label\'>' . $this->get_form_editor_field_title() . '</label>{FIELD}<hr>';
			}

			return '';
		}

	}
}