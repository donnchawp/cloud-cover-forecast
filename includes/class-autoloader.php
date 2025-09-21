<?php
/**
 * Autoloader for Cloud Cover Forecast Plugin
 *
 * @package CloudCoverForecast
 * @since 1.0.0
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Autoloader class for Cloud Cover Forecast Plugin
 *
 * @since 1.0.0
 */
class Cloud_Cover_Forecast_Autoloader {

	/**
	 * Plugin directory path
	 *
	 * @since 1.0.0
	 * @var string
	 */
	private $plugin_dir;

	/**
	 * Constructor
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		$this->plugin_dir = plugin_dir_path( dirname( __FILE__ ) );
		spl_autoload_register( array( $this, 'autoload' ) );
	}

	/**
	 * Autoload classes
	 *
	 * @since 1.0.0
	 * @param string $class_name Class name to load.
	 */
	public function autoload( $class_name ) {
		// Only load our plugin classes
		if ( strpos( $class_name, 'Cloud_Cover_Forecast_' ) !== 0 ) {
			return;
		}

		// Convert class name to file name
		$file_name = strtolower( str_replace( array( 'Cloud_Cover_Forecast_', '_' ), array( '', '-' ), $class_name ) );
		$file_path = $this->plugin_dir . 'includes/class-' . $file_name . '.php';

		if ( file_exists( $file_path ) ) {
			require_once $file_path;
		}
	}
}
