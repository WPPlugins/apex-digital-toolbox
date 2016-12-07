<?php
// Forbid accessing directly
if ( ! defined( 'ABSPATH' ) ) {
	header( 'HTTP/1.1 401 Unauthorized' );
	exit;
}
// Ensure there are no conflicts
if ( ! class_exists( 'toolboxFieldModel' ) ) {
	class toolboxFieldModel {
		private $_prefix = 'apex_toolbox_';
		private $_name = '';
		private $_label = '';
		private $_description = '';
		private $_value = '';
		private $_type = '';

		public function __construct( $args ) {
			if ( isset( $args['name'] ) ) {
				$this->setName( $args['name'] );
			}
			if ( isset( $args['label'] ) ) {
				$this->setLabel( $args['label'] );
			}
			if ( isset( $args['description'] ) ) {
				$this->setDescription( $args['description'] );
			}
			if ( isset( $args['value'] ) ) {
				$this->setValue( $args['value'] );
			}
			if ( isset( $args['type'] ) ) {
				$this->setType( $args['type'] );
			}
		}

		/**
		 * @return string
		 */
		public function getName() {
			return $this->_name;
		}

		/**
		 * @param string $name
		 */
		public function setName( $name ) {
			$this->_name = $name;
		}

		/**
		 * @return string
		 */
		public function getLabel() {
			$label = $this->_label;
			if ( ! $label ) {
				$label = ucwords( str_replace( '_', ' ', $this->getName() ) );
			}

			return $label;
		}

		/**
		 * @param string $label
		 */
		public function setLabel( $label ) {
			$this->_label = $label;
		}

		/**
		 * @return string
		 */
		public function getDescription() {
			return $this->_description;
		}

		/**
		 * @param string $description
		 */
		public function setDescription( $description ) {
			$this->_description = $description;
		}

		/**
		 * @return string
		 */
		public function getValue() {
			return $this->_value;
		}

		/**
		 * @param string $value
		 */
		public function setValue( $value ) {
			// Validate the value a little
			switch ( $this->getType() ) {
				case 'url' :
					$value = esc_url( $value );
					break;
				default :
					$value = esc_attr( $value );
			}
			$this->_value = $value;
		}

		/**
		 * @return string
		 */
		public function getType() {
			// Tidy up the type
			$type = trim( strtolower( $this->_type ) );
			// Valid field types
			$valid = Array( 'input', 'url' );
			// Default invalid types to text fields
			if ( ! in_array( $type, $valid ) ) {
				$type = 'text';
			}

			return $type;
		}

		/**
		 * @param string $type
		 */
		public function setType( $type ) {
			$this->_type = $type;
		}

		/**
		 * Output field settings in admin area
		 *
		 * @author Nigel Wells
		 * @version 0.1.1.16.10.07
		 * @return string;
		 */
		public function outputField() {
			switch ( $this->getType() ) {
				case 'url' :
				case 'text' :
					$input = '<input type="' . $this->getType() . '" class="regular-text code" name="' . $this->getPrefix() . $this->getName() . '" value="' . $this->getValue() . '" />';
			}
			$html = $input;
			if ( $this->getDescription() ) {
				$html .= '<p class="description">' . $this->getDescription() . '</p>';
			}

			return $html;
		}

		/**
		 * @return string
		 */
		public function getPrefix() {
			return $this->_prefix;
		}
	}
}