<?php
/**
 * Plugin Name: Cloud Cover Forecast
 * Plugin URI: https://github.com/donnchawp/cloud-cover-forecast
 * Description: Display total, low, medium, and high cloud cover percentages for a location using the Open‑Meteo API. Provides a shortcode and an admin settings page.
 * Version: 1.0.1
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
define( 'CLOUD_COVER_FORECAST_VERSION', '1.0.1' );
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
	 * Cache version option key used for cache busting.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	const CACHE_VERSION_OPTION = 'cloud_cover_forecast_cache_version';

	/**
	 * Rate limit cache prefix.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	const RATE_LIMIT_PREFIX = 'cloud_cover_forecast_rate_limit_';

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
		require_once CLOUD_COVER_FORECAST_PLUGIN_DIR . 'includes/class-autoloader.php';
		new Cloud_Cover_Forecast_Autoloader();

		$this->assets = new Cloud_Cover_Forecast_Assets( $this );
		$this->assets->init();

		$this->api = new Cloud_Cover_Forecast_API( $this );

		$this->photography_renderer = new Cloud_Cover_Forecast_Photography_Renderer( $this );

		$this->public_block = new Cloud_Cover_Forecast_Public_Block( $this );
		$this->public_block->init();

		$this->sunrise_sunset_block = new Cloud_Cover_Forecast_Sunrise_Sunset_Block( $this );
		$this->sunrise_sunset_block->init();

		$this->shortcode = new Cloud_Cover_Forecast_Shortcode( $this, $this->api, $this->photography_renderer );
		$this->shortcode->init();

		$this->admin = new Cloud_Cover_Forecast_Admin( $this );
		$this->admin->init();

		$this->pwa = new Cloud_Cover_Forecast_PWA( $this );
		$this->pwa->init();

		register_activation_hook( __FILE__, array( $this, 'activate' ) );
		register_deactivation_hook( __FILE__, array( $this, 'deactivate' ) );
	}

	/**
	 * Build a versioned transient key for plugin cache entries.
	 *
	 * @since 1.0.0
	 * @param string $prefix Key prefix.
	 * @param string $suffix Key suffix.
	 * @return string
	 */
	public function get_transient_key( string $prefix, string $suffix ): string {
		$version = $this->get_cache_version();
		return $prefix . $version . '_' . $suffix;
	}

	/**
	 * Clear all plugin cache by bumping the cache version.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function clear_cache(): void {
		$this->bump_cache_version();
	}

	/**
	 * Get current cache version.
	 *
	 * @since 1.0.0
	 * @return int
	 */
	private function get_cache_version(): int {
		$version = intval( get_option( self::CACHE_VERSION_OPTION, 1 ) );
		if ( $version < 1 ) {
			$version = 1;
			update_option( self::CACHE_VERSION_OPTION, $version, false );
		}
		return $version;
	}

	/**
	 * Increment cache version to invalidate existing transients.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	private function bump_cache_version(): void {
		$version = $this->get_cache_version() + 1;
		update_option( self::CACHE_VERSION_OPTION, $version, false );
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
