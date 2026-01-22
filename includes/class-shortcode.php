<?php
/**
 * Shortcode handling for Cloud Cover Forecast Plugin
 *
 * @package CloudCoverForecast
 * @since 1.0.0
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shortcode class for Cloud Cover Forecast Plugin
 *
 * @since 1.0.0
 */
class Cloud_Cover_Forecast_Shortcode {

	/**
	 * Plugin instance
	 *
	 * @since 1.0.0
	 * @var Cloud_Cover_Forecast_Plugin
	 */
	private $plugin;

	/**
	 * API instance
	 *
	 * @since 1.0.0
	 * @var Cloud_Cover_Forecast_API
	 */
	private $api;

	/**
	 * Photography renderer instance (handles both calculations and rendering)
	 *
	 * @since 1.0.0
	 * @var Cloud_Cover_Forecast_Photography_Renderer
	 */
	private $photography_renderer;

	/**
	 * Constructor
	 *
	 * @since 1.0.0
	 * @param Cloud_Cover_Forecast_Plugin $plugin Plugin instance.
	 * @param Cloud_Cover_Forecast_API $api API instance.
	 * @param Cloud_Cover_Forecast_Photography_Renderer $photography_renderer Photography renderer instance.
	 */
	public function __construct( $plugin, $api, $photography_renderer ) {
		$this->plugin = $plugin;
		$this->api = $api;
		$this->photography_renderer = $photography_renderer;
	}

	/**
	 * Initialize shortcode
	 *
	 * @since 1.0.0
	 */
	public function init() {
		add_shortcode( 'cloud_cover', array( $this, 'shortcode_handler' ) );
	}

	/**
	 * Handle the cloud_cover shortcode
	 *
	 * @since 1.0.0
	 * @param array  $atts Shortcode attributes.
	 * @param string $content Shortcode content.
	 * @param string $tag Shortcode tag.
	 * @return string Shortcode output.
	 */
	public function shortcode_handler( $atts = array(), $content = null, $tag = '' ) {
		$opts = $this->plugin->get_settings();
		$atts = shortcode_atts(
			array(
				'lat'        => $opts['lat'],
				'lon'        => $opts['lon'],
				'hours'      => $opts['hours'],
				'label'      => '',
				'show_chart' => $opts['show_chart'],
				'location'   => '',
			),
			$atts,
			$tag
		);

		$lat        = $this->to_float( $atts['lat'] );
		$lon        = $this->to_float( $atts['lon'] );
		$hours      = max( 1, min( 168, intval( $atts['hours'] ) ) ); // Limit to 1-168 hours
		$label      = sanitize_text_field( $atts['label'] );
		$show_chart = intval( $atts['show_chart'] ) ? 1 : 0;
		$location   = sanitize_text_field( $atts['location'] );

		// Check if manual coordinates are provided (not defaults)
		$defaults = $this->plugin->get_settings();
		$has_manual_coords = ( $lat !== null && $lon !== null &&
			( $atts['lat'] !== $defaults['lat'] || $atts['lon'] !== $defaults['lon'] ) );

		// If location is provided AND no manual coordinates, geocode it to get coordinates
		if ( ! empty( $location ) && ! $has_manual_coords ) {
			$geocoded = $this->api->geocode_location( $location );
			if ( is_wp_error( $geocoded ) ) {
				return $this->error_box( __( 'Location not found. Please check the location name and try again.', 'cloud-cover-forecast' ) );
			}

			// Handle multiple results by taking the first one for shortcode usage
			if ( is_array( $geocoded ) && isset( $geocoded[0] ) ) {
				$geocoded = $geocoded[0];
			}

			$lat = floatval( $geocoded['lat'] );
			$lon = floatval( $geocoded['lon'] );
			// Use geocoded location name as label if no label provided
			if ( empty( $label ) ) {
				$label_parts = array_filter( array( $geocoded['name'], $geocoded['country'] ) );
				$label = implode( ', ', $label_parts );
			}
		} elseif ( ! empty( $location ) && $has_manual_coords ) {
			// If both location and manual coordinates provided, prioritize coordinates but use location for label
			if ( empty( $label ) ) {
				$label = $location;
			}
		}

		if ( null === $lat || null === $lon ) {
			return $this->error_box( __( 'Invalid latitude/longitude. Provide either lat/lon coordinates or a location name.', 'cloud-cover-forecast' ) );
		}

		$cache_key = $this->plugin->get_transient_key(
			$this->plugin::TRANSIENT_PREFIX,
			md5( implode( '|', array( $lat, $lon, $hours, wp_timezone_string() ) ) )
		);
		$data      = get_transient( $cache_key );

		if ( false === $data ) {
			$data = $this->api->fetch_weather_data( $lat, $lon, $hours );
			if ( is_wp_error( $data ) ) {
				return $this->error_box( $data->get_error_message() );
			}
			$cache_ttl_minutes = $this->plugin->get_settings()['cache_ttl'] ?? 15;
			set_transient( $cache_key, $data, max( 1, intval( $cache_ttl_minutes ) ) * MINUTE_IN_SECONDS );
		}

		if ( empty( $data['rows'] ) ) {
			return $this->error_box( __( 'No forecast data available.', 'cloud-cover-forecast' ) );
		}

		// Calculate photography data if we have sunrise/sunset data
		$shortcode_sunset = $data['stats']['selected_sunset'] ?? ( $data['stats']['daily_sunset'][0] ?? '' );
		$shortcode_sunrise = $data['stats']['selected_sunrise'] ?? ( $data['stats']['daily_sunrise'][0] ?? '' );
		if ( ! empty( $shortcode_sunset ) && ! empty( $shortcode_sunrise ) ) {
			$photo_times = $this->photography_renderer->calculate_photography_times(
				$shortcode_sunrise,
				$shortcode_sunset,
				$data['stats']['timezone']
			);
			$photo_ratings = $this->photography_renderer->rate_photography_conditions( $data['stats'] );

			$data['stats']['photo_times'] = $photo_times;
			$data['stats']['photo_ratings'] = $photo_ratings;
		}

		return $this->render_widget( $data, $label, $show_chart );
	}

	/**
	 * Convert value to float, handling comma decimal separators
	 *
	 * @since 1.0.0
	 * @param mixed $val Value to convert.
	 * @return float|null Converted float value or null if invalid.
	 */
	private function to_float( $val ) {
		if ( is_numeric( $val ) ) {
			return floatval( $val );
		}
		$val = str_replace( array( ',' ), array( '.' ), (string) $val );
		return is_numeric( $val ) ? floatval( $val ) : null;
	}

	/**
	 * Generate error message HTML
	 *
	 * @since 1.0.0
	 * @param string $msg Error message.
	 * @return string Error HTML.
	 */
	private function error_box( $msg ): string {
		return '<div class="cloud-cover-forecast-wrap"><div class="cloud-cover-forecast-err">' . esc_html( $msg ) . '</div></div>';
	}

	/**
	 * Render the weather widget HTML
	 *
	 * @since 1.0.0
	 * @param array  $data Weather data.
	 * @param string $label Optional label.
	 * @param int    $show_chart Whether to show chart.
	 * @return string Widget HTML.
	 */
	private function render_widget( array $data, string $label, int $show_chart ): string {
		ob_start();
		// Always use photography mode
		$this->photography_renderer->render_photography_widget( $data, $label, $show_chart );

		return ob_get_clean();
	}

	/**
	 * Render the Gutenberg block
	 *
	 * @since 1.0.0
	 * @param array $attributes Block attributes.
	 * @return string Block output.
	 */
	public function render_gutenberg_block( $attributes ) {
		$atts = array(
			'lat'        => $attributes['latitude'] ?? $this->plugin->get_settings()['lat'],
			'lon'        => $attributes['longitude'] ?? $this->plugin->get_settings()['lon'],
			'hours'      => $attributes['hours'] ?? $this->plugin->get_settings()['hours'],
			'show_chart' => 0, // Always disable chart
			'label'      => $attributes['label'] ?? '',
			'location'   => $attributes['location'] ?? '',
		);

		// Always use photography mode for blocks
		return $this->shortcode_handler( $atts );
	}
}
