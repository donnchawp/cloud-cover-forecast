<?php
/**
 * Assets management for Cloud Cover Forecast Plugin
 *
 * @package CloudCoverForecast
 * @since 1.0.0
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Assets class for Cloud Cover Forecast Plugin
 *
 * @since 1.0.0
 */
class Cloud_Cover_Forecast_Assets {

	/**
	 * Plugin instance
	 *
	 * @since 1.0.0
	 * @var Cloud_Cover_Forecast_Plugin
	 */
	private $plugin;

	/**
	 * Constructor
	 *
	 * @since 1.0.0
	 * @param Cloud_Cover_Forecast_Plugin $plugin Plugin instance.
	 */
	public function __construct( $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * Initialize assets
	 *
	 * @since 1.0.0
	 */
	public function init() {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_frontend_styles' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_scripts' ) );
		add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue_block_editor_assets' ) );
	}

	/**
	 * Enqueue frontend styles
	 *
	 * @since 1.0.0
	 */
	public function enqueue_frontend_styles() {
		self::register_tokens();

		wp_enqueue_style(
			'cloud-cover-forecast-style',
			CLOUD_COVER_FORECAST_PLUGIN_URL . 'assets/css/forecast-block.css',
			array( 'cloud-cover-forecast-tokens' ),
			CLOUD_COVER_FORECAST_VERSION
		);
	}

	/**
	 * Register the shared design token stylesheet.
	 *
	 * Registered rather than enqueued directly so that stylesheets depending on
	 * it pull it in exactly once, whichever of them loads first.
	 *
	 * @since 1.0.0
	 */
	public static function register_tokens() {
		if ( wp_style_is( 'cloud-cover-forecast-tokens', 'registered' ) ) {
			return;
		}

		wp_register_style(
			'cloud-cover-forecast-tokens',
			CLOUD_COVER_FORECAST_PLUGIN_URL . 'assets/css/forecast-tokens.css',
			array(),
			CLOUD_COVER_FORECAST_VERSION
		);
	}

	/**
	 * Enqueue admin scripts
	 *
	 * @since 1.0.0
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_admin_scripts( $hook ) {
		// Only enqueue on our settings page
		if ( 'settings_page_cloud-cover-forecast-settings' !== $hook ) {
			return;
		}

		wp_enqueue_script( 'jquery' );
	}

	/**
	 * Enqueue block editor assets
	 *
	 * @since 1.0.0
	 */
	public function enqueue_block_editor_assets() {
		// Enqueue the existing block editor script
		wp_enqueue_script(
			'cloud-cover-forecast-block-editor',
			CLOUD_COVER_FORECAST_PLUGIN_URL . 'block.js',
			array( 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-editor', 'wp-components', 'wp-i18n' ),
			CLOUD_COVER_FORECAST_VERSION,
			true
		);

		// Enqueue the public block editor script
		wp_enqueue_script(
			'cloud-cover-forecast-public-block-editor',
			CLOUD_COVER_FORECAST_PLUGIN_URL . 'public-block.js',
			array( 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-editor', 'wp-components', 'wp-i18n', 'wp-data' ),
			CLOUD_COVER_FORECAST_VERSION,
			true
		);

		// Localize scripts
		wp_localize_script(
			'cloud-cover-forecast-block-editor',
			'cloudCoverForecast',
			array(
				'strings' => array(
					'cloudCoverForecast' => __( 'Cloud Cover Forecast', 'cloud-cover-forecast' ),
					'locationSearch' => __( 'Location Search', 'cloud-cover-forecast' ),
					'enterLocationName' => __( 'Enter location name (e.g., London, UK)', 'cloud-cover-forecast' ),
					'search' => __( 'Search', 'cloud-cover-forecast' ),
					'searching' => __( 'Searching...', 'cloud-cover-forecast' ),
					'multipleLocationsFound' => __( 'Multiple locations found. Please select one:', 'cloud-cover-forecast' ),
					'locationNotFound' => __( 'Location not found. Please try a different search term.', 'cloud-cover-forecast' ),
					'searchWillAutoFill' => __( 'Search will automatically fill coordinates below', 'cloud-cover-forecast' ),
					'locationNameOverride' => __( 'Location Name Override', 'cloud-cover-forecast' ),
					'overrideLocationName' => __( 'Override location name in shortcode (optional)', 'cloud-cover-forecast' ),
					'latitude' => __( 'Latitude', 'cloud-cover-forecast' ),
					'enterLatitude' => __( 'Enter the latitude coordinate (e.g., 51.8986)', 'cloud-cover-forecast' ),
					'longitude' => __( 'Longitude', 'cloud-cover-forecast' ),
					'enterLongitude' => __( 'Enter the longitude coordinate (e.g., -8.4756)', 'cloud-cover-forecast' ),
					'hoursAhead' => __( 'Hours Ahead', 'cloud-cover-forecast' ),
					'numberOfHours' => __( 'Number of hours to forecast (1-168)', 'cloud-cover-forecast' ),
					'labelOptional' => __( 'Label (Optional)', 'cloud-cover-forecast' ),
					'optionalLabel' => __( 'Optional label to display with the forecast', 'cloud-cover-forecast' ),
					'cloudCoverAstronomicalForecast' => __( 'Cloud Cover & Astronomical Forecast', 'cloud-cover-forecast' ),
					'location' => __( 'Location:', 'cloud-cover-forecast' ),
					'coordinates' => __( 'Coordinates:', 'cloud-cover-forecast' ),
					'hours' => __( 'Hours:', 'cloud-cover-forecast' ),
					'label' => __( 'Label:', 'cloud-cover-forecast' ),
					'photographyFeatures' => __( 'Photography Features:', 'cloud-cover-forecast' ),
					'sunsetPhotographyRatings' => __( 'Sunset photography ratings', 'cloud-cover-forecast' ),
					'astrophotographyAnalysis' => __( 'Astrophotography analysis', 'cloud-cover-forecast' ),
					'moonPhasesAndRiseSetTimes' => __( 'Moon phases and rise/set times', 'cloud-cover-forecast' ),
					'optimalShootingWindows' => __( 'Optimal shooting windows', 'cloud-cover-forecast' ),
					'previewActualForecast' => __( 'Preview: The actual forecast will be displayed on the frontend.', 'cloud-cover-forecast' ),
				),
			)
		);

		wp_localize_script(
			'cloud-cover-forecast-public-block-editor',
			'cloudCoverForecastPublic',
			array(
				'strings' => array(
					'publicCloudCoverLookup' => __( 'Public Cloud Cover Lookup', 'cloud-cover-forecast' ),
					'allowPublicVisitors' => __( 'Allow public visitors to search for cloud cover conditions at any location.', 'cloud-cover-forecast' ),
					'cloudCoverForecast' => __( 'Cloud Cover Forecast', 'cloud-cover-forecast' ),
					'thisBlockAllowsVisitors' => __( 'This block allows visitors to search for cloud cover forecasts at any location.', 'cloud-cover-forecast' ),
					'enterLocation' => __( 'Enter location (e.g., London, UK)', 'cloud-cover-forecast' ),
					'getForecast' => __( 'Get Forecast', 'cloud-cover-forecast' ),
					'previewActualSearch' => __( 'Preview: The actual search functionality will be available on the frontend.', 'cloud-cover-forecast' ),
				),
			)
		);
	}

}
