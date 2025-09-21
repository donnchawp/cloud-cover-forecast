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
		$css = $this->get_css();
		wp_register_style( 'cloud-cover-forecast-style', false );
		wp_enqueue_style( 'cloud-cover-forecast-style' );
		wp_add_inline_style( 'cloud-cover-forecast-style', $css );
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
			array( 'wp-blocks', 'wp-element', 'wp-editor', 'wp-components', 'wp-i18n' ),
			CLOUD_COVER_FORECAST_VERSION,
			true
		);

		// Enqueue the public block editor script
		wp_enqueue_script(
			'cloud-cover-forecast-public-block-editor',
			CLOUD_COVER_FORECAST_PLUGIN_URL . 'public-block.js',
			array( 'wp-blocks', 'wp-element', 'wp-components', 'wp-i18n', 'wp-data' ),
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

	/**
	 * Get CSS styles
	 *
	 * @since 1.0.0
	 * @return string CSS styles.
	 */
	private function get_css() {
		return ".cloud-cover-forecast-wrap{font-family:system-ui,Segoe UI,Roboto,Helvetica,Arial,sans-serif}.cloud-cover-forecast-card{border:1px solid #e5e7eb;border-radius:14px;padding:14px;margin:10px 0;box-shadow:0 1px 2px rgba(0,0,0,.04)}.cloud-cover-forecast-grid{display:grid;gap:8px}.cloud-cover-forecast-grid.cols-5{grid-template-columns:110px repeat(4,1fr)}.cloud-cover-forecast-row{display:contents}.cloud-cover-forecast-h{font-weight:600}.cloud-cover-forecast-badge{display:inline-block;padding:2px 8px;border-radius:9999px;background:#f3f4f6}.cloud-cover-forecast-meta{color:#6b7280;font-size:12px}.cloud-cover-forecast-table{width:100%;border-collapse:separate;border-spacing:0}.cloud-cover-forecast-th,.cloud-cover-forecast-td{padding:8px 10px;text-align:right;border-bottom:1px solid #f1f5f9}.cloud-cover-forecast-th:first-child,.cloud-cover-forecast-td:first-child{text-align:left}.cloud-cover-forecast-foot{display:flex;justify-content:space-between;align-items:center;margin-top:8px}.cloud-cover-forecast-chart{width:100%;height:220px}.cloud-cover-forecast-err{color:#b91c1c;background:#fee2e2;border:1px solid #fecaca;border-radius:10px;padding:12px}.cloud-cover-forecast-skel{background:linear-gradient(90deg,#f3f4f6,#e5e7eb,#f3f4f6);background-size:200% 100%;animation:cloud-cover-forecast-shimmer 1.4s infinite}.cloud-cover-forecast-skel.h16{height:16px;border-radius:8px}.cloud-cover-forecast-skel.h24{height:24px;border-radius:8px}.cloud-cover-forecast-skel.mt8{margin-top:8px}@keyframes cloud-cover-forecast-shimmer{0%{background-position:200% 0}100%{background-position:-200% 0}}.cloud-cover-forecast-photography{background:linear-gradient(135deg,#f8fafc 0%,#f1f5f9 100%)}.cloud-cover-forecast-header{margin-bottom:15px;padding-bottom:10px;border-bottom:1px solid #e5e7eb}.cloud-cover-forecast-summary{margin-bottom:15px;padding:12px;background:#f8fafc;border-radius:8px;border:1px solid #e5e7eb}.cloud-cover-forecast-ratings{margin-top:8px;color:#374151}.cloud-cover-forecast-moon{margin-top:6px;color:#6366f1;font-weight:500}.cloud-cover-forecast-opportunities{margin-bottom:15px;padding:12px;background:#fef7ff;border-radius:8px;border:1px solid #e879f9}.cloud-cover-forecast-opportunities h4{margin:0 0 10px;color:#7c3aed}.cloud-cover-forecast-opportunity{display:flex;align-items:center;gap:12px;margin:6px 0}.cloud-cover-forecast-opportunity .time-label{font-weight:500;color:#374151;min-width:120px}.cloud-cover-forecast-opportunity .time-value{color:#059669;font-weight:600}.cloud-cover-forecast-opportunity .rating-stars{color:#f59e0b}.cloud-cover-forecast-opportunity .quality-badge{background:#10b981;color:#fff;padding:2px 8px;border-radius:12px;font-size:10px;font-weight:600}.cloud-cover-forecast-photography-table .event-cell{font-size:13px;text-align:center;width:120px;white-space:nowrap}.cloud-cover-forecast-photography-table .condition-cell{font-size:12px;max-width:180px}.cloud-cover-forecast-photography-table .sunrise-golden-hour-row{background:#fef3c7}.cloud-cover-forecast-photography-table .sunset-golden-hour-row{background:#fef3c7}.cloud-cover-forecast-photography-table .golden-hour-row{background:#fef3c7}.cloud-cover-forecast-photography-table .astro-dark-row{background:#ddd6fe}.cloud-cover-forecast-photography-table .nighttime-row{background:#e0e7ff}.cloud-cover-forecast-photography-table .past-hour-row{opacity:.65}.cloud-cover-forecast-photography-table .past-hour-row td{color:#6b7280}.cloud-cover-forecast-photography-table .current-hour-row td{background:#dbeafe;color:#1f2937;font-weight:600}.cloud-cover-forecast-photography-table .current-hour-row td:first-child{border-left:4px solid #2563eb}.cloud-cover-current-hour-label{display:inline-flex;align-items:center;margin-left:6px;padding:2px 8px;border-radius:9999px;background:#2563eb;color:#fff;font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.02em}.cloud-cover-forecast-photography-table th,.cloud-cover-forecast-photography-table td{padding:6px 8px;font-size:13px}.cloud-cover-forecast-photography-table th:first-child,.cloud-cover-forecast-photography-table td:first-child{text-align:left}.cloud-cover-forecast-footer{margin-top:15px;padding-top:10px;border-top:1px solid #e5e7eb}";
	}
}
