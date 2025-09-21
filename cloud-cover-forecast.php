<?php
/**
 * Plugin Name: Cloud Cover Forecast
 * Plugin URI: https://github.com/donnchawp/cloud-cover-forecast
 * Description: Display total, low, medium, and high cloud cover percentages for a location using the Open‑Meteo API. Provides a shortcode and an admin settings page.
 * Version: 1.0.0
 * Author: Donncha O Caoimh
 * Author URI: https://github.com/donnchawp
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: cloud-cover-forecast
 * Requires at least: 5.0
 * Tested up to: 6.4
 * Requires PHP: 7.4
 *
 * @package CloudCoverForecast
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Define plugin constants
define( 'CLOUD_COVER_FORECAST_VERSION', '1.0.0' );
define( 'CLOUD_COVER_FORECAST_PLUGIN_FILE', __FILE__ );
define( 'CLOUD_COVER_FORECAST_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'CLOUD_COVER_FORECAST_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

/**
 * Main Cloud Cover Forecast Plugin Class
 *
 * @since 1.0.0
 */
class Cloud_Cover_Forecast_Plugin {
	/**
	 * Plugin option key
	 *
	 * @since 1.0.0
	 * @var string
	 */
	const OPTION_KEY = 'cloud_cover_forecast_settings_v1';

	/**
	 * Transient cache prefix
	 *
	 * @since 1.0.0
	 * @var string
	 */
	const TRANSIENT_PREFIX = 'cloud_cover_forecast_cache_';

	/**
	 * Geocoding cache prefix
	 *
	 * @since 1.0.0
	 * @var string
	 */
	const GEOCODING_PREFIX = 'cloud_cover_forecast_geocoding_';

	/**
	 * Plugin instance
	 *
	 * @since 1.0.0
	 * @var Cloud_Cover_Forecast_Plugin
	 */
	private static $instance = null;

	/**
	 * Admin instance
	 *
	 * @since 1.0.0
	 * @var Cloud_Cover_Forecast_Admin
	 */
	private $admin;

	/**
	 * API instance
	 *
	 * @since 1.0.0
	 * @var Cloud_Cover_Forecast_API
	 */
	private $api;

	/**
	 * Shortcode instance
	 *
	 * @since 1.0.0
	 * @var Cloud_Cover_Forecast_Shortcode
	 */
	private $shortcode;

	/**
	 * Photography instance
	 *
	 * @since 1.0.0
	 * @var Cloud_Cover_Forecast_Photography
	 */
	private $photography;

	/**
	 * Photography renderer instance
	 *
	 * @since 1.0.0
	 * @var Cloud_Cover_Forecast_Photography_Renderer
	 */
	private $photography_renderer;

	/**
	 * Public block instance
	 *
	 * @since 1.0.0
	 * @var Cloud_Cover_Forecast_Public_Block
	 */
	private $public_block;

	/**
	 * Assets instance
	 *
	 * @since 1.0.0
	 * @var Cloud_Cover_Forecast_Assets
	 */
	private $assets;

	/**
	 * Get plugin instance
	 *
	 * @since 1.0.0
	 * @return Cloud_Cover_Forecast_Plugin Plugin instance.
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor
	 *
	 * @since 1.0.0
	 */
	private function __construct() {
		$this->init();
	}

	/**
	 * Initialize the plugin
	 *
	 * @since 1.0.0
	 */
	private function init() {
		// Load autoloader
		require_once CLOUD_COVER_FORECAST_PLUGIN_DIR . 'includes/class-autoloader.php';
		new Cloud_Cover_Forecast_Autoloader();

		// Initialize components
		$this->init_components();

		// Initialize hooks
		$this->init_hooks();
	}

	/**
	 * Initialize plugin components
	 *
	 * @since 1.0.0
	 */
	private function init_components() {
		// Initialize assets
		$this->assets = new Cloud_Cover_Forecast_Assets( $this );
		$this->assets->init();

		// Initialize API
		$this->api = new Cloud_Cover_Forecast_API( $this );

		// Initialize photography
		$this->photography = new Cloud_Cover_Forecast_Photography( $this );

		// Initialize photography renderer
		$this->photography_renderer = new Cloud_Cover_Forecast_Photography_Renderer( $this, $this->photography );

		// Initialize public block
		$this->public_block = new Cloud_Cover_Forecast_Public_Block( $this );
		$this->public_block->init();

		// Initialize shortcode
		$this->shortcode = new Cloud_Cover_Forecast_Shortcode( $this, $this->api, $this->photography, $this->photography_renderer );
		$this->shortcode->init();

		// Initialize admin
		$this->admin = new Cloud_Cover_Forecast_Admin( $this );
		$this->admin->init();
	}

	/**
	 * Initialize WordPress hooks
	 *
	 * @since 1.0.0
	 */
	private function init_hooks() {
		// Plugin activation/deactivation hooks
		register_activation_hook( __FILE__, array( $this, 'activate' ) );
		register_deactivation_hook( __FILE__, array( $this, 'deactivate' ) );
	}

	/**
	 * Plugin activation
	 *
	 * @since 1.0.0
	 */
	public function activate() {
		// Set default options if they don't exist
		if ( ! get_option( self::OPTION_KEY ) ) {
			update_option( self::OPTION_KEY, self::get_defaults() );
		}
	}

	/**
	 * Plugin deactivation
	 *
	 * @since 1.0.0
	 */
	public function deactivate() {
		// Clean up if needed
	}

	/**
	 * Get default plugin settings
	 *
	 * @since 1.0.0
	 * @return array Default settings array
	 */
	public static function get_defaults(): array {
		return array(
			'lat'             => '51.8986', // Cork default
			'lon'             => '-8.4756',
			'hours'           => 48,
			'cache_ttl'       => 15, // minutes
			'show_chart'      => 1,
			'provider'        => 'open-meteo',
			'astro_api_key'   => '', // IPGeolocation API key for moon data
		);
	}

	/**
	 * Get plugin settings with defaults
	 *
	 * @since 1.0.0
	 * @return array Settings array
	 */
	public function get_settings(): array {
		$saved = get_option( self::OPTION_KEY, array() );
		return wp_parse_args( $saved, self::get_defaults() );
	}

	/**
	 * Get admin instance
	 *
	 * @since 1.0.0
	 * @return Cloud_Cover_Forecast_Admin Admin instance.
	 */
	public function get_admin() {
		return $this->admin;
	}

	/**
	 * Get API instance
	 *
	 * @since 1.0.0
	 * @return Cloud_Cover_Forecast_API API instance.
	 */
	public function get_api() {
		return $this->api;
	}

	/**
	 * Get shortcode instance
	 *
	 * @since 1.0.0
	 * @return Cloud_Cover_Forecast_Shortcode Shortcode instance.
	 */
	public function get_shortcode() {
		return $this->shortcode;
	}

	/**
	 * Get photography instance
	 *
	 * @since 1.0.0
	 * @return Cloud_Cover_Forecast_Photography Photography instance.
	 */
	public function get_photography() {
		return $this->photography;
	}

	/**
	 * Get photography renderer instance
	 *
	 * @since 1.0.0
	 * @return Cloud_Cover_Forecast_Photography_Renderer Photography renderer instance.
	 */
	public function get_photography_renderer() {
		return $this->photography_renderer;
	}

	/**
	 * Get public block instance
	 *
	 * @since 1.0.0
	 * @return Cloud_Cover_Forecast_Public_Block Public block instance.
	 */
	public function get_public_block() {
		return $this->public_block;
	}

	/**
	 * Get assets instance
	 *
	 * @since 1.0.0
	 * @return Cloud_Cover_Forecast_Assets Assets instance.
	 */
	public function get_assets() {
		return $this->assets;
	}
}

// Initialize the plugin
Cloud_Cover_Forecast_Plugin::get_instance();
