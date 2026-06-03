<?php
/**
 * Minimal stub of \Elementor\Plugin so the prune code's class_exists() guard returns true
 * during tests without needing the real Elementor plugin installed. The prune logic only
 * checks existence - it does not call any method on this class.
 */
namespace Elementor;

if ( ! class_exists( __NAMESPACE__ . '\\Plugin', false ) ) {
    class Plugin {}
}
