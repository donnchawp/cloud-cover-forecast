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
	 * Option key used to track plugin transients.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	const TRANSIENT_INDEX_OPTION = 'cloud_cover_forecast_transient_index';

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
	 * Photography renderer instance (handles both calculations and rendering)
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
	 * Sunrise Sunset block instance
	 *
	 * @since 1.0.0
	 * @var Cloud_Cover_Forecast_Sunrise_Sunset_Block
	 */
	private $sunrise_sunset_block;

	/**
	 * Assets instance
	 *
	 * @since 1.0.0
	 * @var Cloud_Cover_Forecast_Assets
	 */
	private $assets;

	/**
	 * PWA instance
	 *
	 * @since 1.0.0
	 * @var Cloud_Cover_Forecast_PWA
	 */
	private $pwa;

	/**
	 * Constructor
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
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

		// Initialize photography renderer (handles both calculations and rendering)
		$this->photography_renderer = new Cloud_Cover_Forecast_Photography_Renderer( $this );

		// Initialize public block
		$this->public_block = new Cloud_Cover_Forecast_Public_Block( $this );
		$this->public_block->init();

		// Initialize sunrise sunset block
		$this->sunrise_sunset_block = new Cloud_Cover_Forecast_Sunrise_Sunset_Block( $this );
		$this->sunrise_sunset_block->init();

		// Initialize shortcode
		$this->shortcode = new Cloud_Cover_Forecast_Shortcode( $this, $this->api, $this->photography_renderer );
		$this->shortcode->init();

		// Initialize admin
		$this->admin = new Cloud_Cover_Forecast_Admin( $this );
		$this->admin->init();

		// Initialize PWA
		$this->pwa = new Cloud_Cover_Forecast_PWA( $this );
		$this->pwa->init();
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
	 * Store a transient key for later cleanup.
	 *
	 * @since 1.0.0
	 * @param string $key Transient key to track.
	 * @return void
	 */
	public function register_transient_key( string $key ): void {
		$keys = $this->get_tracked_transient_keys();
		if ( in_array( $key, $keys, true ) ) {
			return;
		}

		$keys[] = $key;
		update_option( self::TRANSIENT_INDEX_OPTION, $keys, false );
	}

	/**
	 * Remove a transient key from the tracking list.
	 *
	 * @since 1.0.0
	 * @param string $key Transient key to remove.
	 * @return void
	 */
	public function unregister_transient_key( string $key ): void {
		$keys = $this->get_tracked_transient_keys();
		$position = array_search( $key, $keys, true );

		if ( false === $position ) {
			return;
		}

		unset( $keys[ $position ] );
		$keys = array_values( $keys );

		if ( empty( $keys ) ) {
			delete_option( self::TRANSIENT_INDEX_OPTION );
			return;
		}

		update_option( self::TRANSIENT_INDEX_OPTION, $keys, false );
	}

	/**
	 * Get all tracked transient keys.
	 *
	 * @since 1.0.0
	 * @return string[]
	 */
	public function get_tracked_transient_keys(): array {
		$keys = get_option( self::TRANSIENT_INDEX_OPTION, array() );

		if ( ! is_array( $keys ) ) {
			return array();
		}

		return array_values( array_map( 'strval', array_unique( $keys ) ) );
	}

	/**
	 * Clear all tracked transients and reset the index.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function clear_tracked_transients(): void {
		$keys = $this->get_tracked_transient_keys();
		if ( empty( $keys ) ) {
			return;
		}

		foreach ( $keys as $key ) {
			delete_transient( $key );
		}

		delete_option( self::TRANSIENT_INDEX_OPTION );
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

		// Trigger PWA rewrite rules flush.
		Cloud_Cover_Forecast_PWA::activate();
	}

	/**
	 * Plugin deactivation
	 *
	 * @since 1.0.0
	 */
	public function deactivate() {
		// Clean up PWA rewrite rules.
		Cloud_Cover_Forecast_PWA::deactivate();
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
	 * Get photography renderer instance (handles both calculations and rendering)
	 *
	 * @since 1.0.0
	 * @return Cloud_Cover_Forecast_Photography_Renderer Photography renderer instance.
	 */
	public function get_photography_renderer() {
		return $this->photography_renderer;
	}

	/**
	 * Get PWA instance
	 *
	 * @since 1.0.0
	 * @return Cloud_Cover_Forecast_PWA PWA instance.
	 */
	public function get_pwa() {
		return $this->pwa;
	}
}

// Initialize the plugin
new Cloud_Cover_Forecast_Plugin();
