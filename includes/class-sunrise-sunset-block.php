<?php
/**
 * Sunrise Sunset Block functionality for Cloud Cover Forecast Plugin
 *
 * @package CloudCoverForecast
 * @since 1.0.0
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sunrise Sunset Block class for Cloud Cover Forecast Plugin
 *
 * @since 1.0.0
 */
class Cloud_Cover_Forecast_Sunrise_Sunset_Block {

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
	 * Constructor
	 *
	 * @since 1.0.0
	 * @param Cloud_Cover_Forecast_Plugin $plugin Plugin instance.
	 */
	public function __construct( $plugin ) {
		$this->plugin = $plugin;
		$this->api = $plugin->get_api();
	}

	/**
	 * Initialize the sunrise sunset block functionality
	 *
	 * @since 1.0.0
	 */
	public function init() {
		add_action( 'init', array( $this, 'register_block' ) );

		// AJAX handlers for location lookup
		add_action( 'wp_ajax_sunrise_sunset_geocode', array( $this, 'handle_ajax_geocode' ) );
		add_action( 'wp_ajax_nopriv_sunrise_sunset_geocode', array( $this, 'handle_ajax_geocode' ) );
	}

	/**
	 * Register the Gutenberg block
	 *
	 * @since 1.0.0
	 */
	public function register_block() {
		// Check if Gutenberg is available
		if ( ! function_exists( 'register_block_type' ) ) {
			return;
		}

		// Register block editor script
		wp_register_script(
			'sunrise-sunset-block-editor',
			CLOUD_COVER_FORECAST_PLUGIN_URL . 'sunrise-sunset-block.js',
			array( 'wp-blocks', 'wp-element', 'wp-editor', 'wp-components', 'wp-i18n' ),
			CLOUD_COVER_FORECAST_VERSION,
			false
		);

		register_block_type(
			'cloud-cover-forecast/sunrise-sunset',
			array(
				'editor_script' => 'sunrise-sunset-block-editor',
				'render_callback' => array( $this, 'render_block' ),
				'attributes' => array(
					'location' => array(
						'type' => 'string',
						'default' => '',
					),
					'latitude' => array(
						'type' => 'number',
						'default' => 0,
					),
					'longitude' => array(
						'type' => 'number',
						'default' => 0,
					),
				),
			)
		);
	}

	/**
	 * Handle AJAX geocoding requests
	 *
	 * @since 1.0.0
	 */
	public function handle_ajax_geocode() {
		$nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'sunrise_sunset_geocode' ) ) {
			wp_send_json_error( array( 'message' => __( 'Security check failed.', 'cloud-cover-forecast' ) ), 403 );
		}

		$location = isset( $_POST['location'] ) ? sanitize_text_field( wp_unslash( $_POST['location'] ) ) : '';
		if ( '' === $location ) {
			wp_send_json_error( array( 'message' => __( 'Please provide a location to search for.', 'cloud-cover-forecast' ) ), 400 );
		}

		$results = $this->api->geocode_location( $location );
		if ( is_wp_error( $results ) ) {
			wp_send_json_error( array( 'message' => $results->get_error_message() ), 500 );
		}

		wp_send_json_success( array( 'results' => $results ) );
	}

	/**
	 * Render the block
	 *
	 * @since 1.0.0
	 * @param array $attributes Block attributes.
	 * @return string Block HTML.
	 */
	public function render_block( $attributes ) {
		// Get coordinates from attributes
		$lat = floatval( $attributes['latitude'] ?? 0 );
		$lon = floatval( $attributes['longitude'] ?? 0 );
		$location = $attributes['location'] ?? '';

		// Check if location is defined in block attributes (use loose comparison to avoid float/int type issues)
		$has_location = ( 0 != $lat || 0 != $lon );

		// If no location in attributes, check URL query parameters
		if ( ! $has_location ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading query params for display only
			$query_lat = isset( $_GET['sunrise_lat'] ) ? floatval( wp_unslash( $_GET['sunrise_lat'] ) ) : 0;
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading query params for display only
			$query_lon = isset( $_GET['sunrise_lon'] ) ? floatval( wp_unslash( $_GET['sunrise_lon'] ) ) : 0;
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading query params for display only
			$query_location = isset( $_GET['sunrise_location'] ) ? sanitize_text_field( wp_unslash( $_GET['sunrise_location'] ) ) : '';

			if ( 0 != $query_lat || 0 != $query_lon ) {
				$lat = $query_lat;
				$lon = $query_lon;
				$location = $query_location;
				$has_location = true;
			}
		}

		// If still no location, show search form
		if ( ! $has_location ) {
			return $this->render_search_form();
		}

		// Fetch 3-day forecast data
		$forecast_data = $this->fetch_3day_forecast( $lat, $lon );

		if ( is_wp_error( $forecast_data ) ) {
			return '<div class="sunrise-sunset-forecast-error">' . esc_html( $forecast_data->get_error_message() ) . '</div>';
		}

		// Render the forecast
		return $this->render_forecast( $forecast_data, $location );
	}

	/**
	 * Fetch 3-day sunrise/sunset forecast data
	 *
	 * @since 1.0.0
	 * @param float $lat Latitude.
	 * @param float $lon Longitude.
	 * @return array|WP_Error Forecast data or error.
	 */
	private function fetch_3day_forecast( float $lat, float $lon ) {
		// Fetch 72 hours of data using the main API method (includes dual-source data from Open-Meteo + Met.no)
		$weather_data = $this->api->fetch_weather_data( $lat, $lon, 72 );

		if ( is_wp_error( $weather_data ) ) {
			return $weather_data;
		}

		$stats = $weather_data['stats'];
		$rows = $weather_data['rows'];
		$timezone = $stats['timezone'] ?? 'UTC';
		$timezone_obj = new DateTimeZone( $timezone );

		// Build hourly lookup from merged dual-source data
		$hourly_lookup = array();
		foreach ( $rows as $row ) {
			$time_str = $row['time'];
			$hourly_lookup[ $time_str ] = array(
				'total' => $row['total'] ?? null,
				'low'   => $row['low'] ?? null,
				'mid'   => $row['mid'] ?? null,
				'high'  => $row['high'] ?? null,
			);
		}

		// Extract daily sunrise/sunset data
		$daily_sunrise = $stats['daily_sunrise'] ?? array();
		$daily_sunset = $stats['daily_sunset'] ?? array();
		$daily_times = $stats['daily_times'] ?? array();

		$daily_data = $this->process_daily_data_from_stats(
			$daily_times,
			$daily_sunrise,
			$daily_sunset,
			$hourly_lookup,
			$timezone_obj,
			$lat,
			$lon
		);

		return $daily_data;
	}

	/**
	 * Process daily sunrise/sunset data with cloud cover analysis
	 *
	 * @since 1.0.0
	 * @param array        $times Daily dates.
	 * @param array        $sunrises Daily sunrise times.
	 * @param array        $sunsets Daily sunset times.
	 * @param array        $hourly_lookup Hourly cloud data lookup (already processed).
	 * @param DateTimeZone $timezone Timezone object.
	 * @param float        $lat Latitude.
	 * @param float        $lon Longitude.
	 * @return array Processed daily forecast data.
	 */
	private function process_daily_data_from_stats( array $times, array $sunrises, array $sunsets, array $hourly_lookup, DateTimeZone $timezone, float $lat, float $lon ): array {
		$days = array();

		// Process each day
		for ( $i = 0; $i < min( 3, count( $times ) ); $i++ ) {
			$date = $times[ $i ];
			$sunrise = $sunrises[ $i ] ?? null;
			$sunset = $sunsets[ $i ] ?? null;

			if ( ! $sunrise || ! $sunset ) {
				continue;
			}

			$sunrise_dt = new DateTime( $sunrise, $timezone );
			$sunset_dt = new DateTime( $sunset, $timezone );

			// Calculate cloud cover around sunrise (±2 hours)
			$sunrise_clouds = $this->get_cloud_cover_window( $sunrise_dt, $hourly_lookup, $timezone );

			// Calculate cloud cover around sunset (±2 hours)
			$sunset_clouds = $this->get_cloud_cover_window( $sunset_dt, $hourly_lookup, $timezone );

			// Calculate daylight duration
			$daylight_seconds = $sunset_dt->getTimestamp() - $sunrise_dt->getTimestamp();
			$daylight_hours = floor( $daylight_seconds / 3600 );
			$daylight_minutes = floor( ( $daylight_seconds % 3600 ) / 60 );

			$days[] = array(
				'date'             => $date,
				'sunrise'          => $sunrise,
				'sunset'           => $sunset,
				'sunrise_dt'       => $sunrise_dt,
				'sunset_dt'        => $sunset_dt,
				'daylight_hours'   => $daylight_hours,
				'daylight_minutes' => $daylight_minutes,
				'sunrise_clouds'   => $sunrise_clouds,
				'sunset_clouds'    => $sunset_clouds,
			);
		}

		return array(
			'days'     => $days,
			'timezone' => $timezone->getName(),
			'lat'      => $lat,
			'lon'      => $lon,
		);
	}

	/**
	 * Get average cloud cover for a ±2 hour window
	 *
	 * @since 1.0.0
	 * @param DateTime     $center_time Center time for the window.
	 * @param array        $hourly_lookup Hourly cloud data lookup.
	 * @param DateTimeZone $timezone Timezone object.
	 * @return array Cloud cover averages.
	 */
	private function get_cloud_cover_window( DateTime $center_time, array $hourly_lookup, DateTimeZone $timezone ): array {
		$total_values = array();
		$low_values = array();
		$mid_values = array();
		$high_values = array();

		// Check ±2 hours (5 data points including center)
		for ( $offset = -2; $offset <= 2; $offset++ ) {
			$check_time = clone $center_time;
			$check_time->modify( $offset . ' hours' );
			$time_key = $check_time->format( 'Y-m-d\TH:00' );

			if ( isset( $hourly_lookup[ $time_key ] ) ) {
				$data = $hourly_lookup[ $time_key ];
				if ( null !== $data['total'] ) {
					$total_values[] = $data['total'];
				}
				if ( null !== $data['low'] ) {
					$low_values[] = $data['low'];
				}
				if ( null !== $data['mid'] ) {
					$mid_values[] = $data['mid'];
				}
				if ( null !== $data['high'] ) {
					$high_values[] = $data['high'];
				}
			}
		}

		return array(
			'avg_total' => ! empty( $total_values ) ? round( array_sum( $total_values ) / count( $total_values ) ) : 0,
			'avg_low'   => ! empty( $low_values ) ? round( array_sum( $low_values ) / count( $low_values ) ) : 0,
			'avg_mid'   => ! empty( $mid_values ) ? round( array_sum( $mid_values ) / count( $mid_values ) ) : 0,
			'avg_high'  => ! empty( $high_values ) ? round( array_sum( $high_values ) / count( $high_values ) ) : 0,
		);
	}

	/**
	 * Generate shooting condition summary
	 *
	 * @since 1.0.0
	 * @param array  $clouds Cloud cover data.
	 * @param string $event_type 'sunrise' or 'sunset'.
	 * @return string Detailed condition summary.
	 */
	private function get_shooting_condition_summary( array $clouds, string $event_type ): string {
		$avg_total = $clouds['avg_total'];
		$avg_high = $clouds['avg_high'];
		$avg_low = $clouds['avg_low'];

		$event_label = 'sunrise' === $event_type ? __( 'sunrise', 'cloud-cover-forecast' ) : __( 'sunset', 'cloud-cover-forecast' );

		// Clear conditions with high clouds - spectacular!
		if ( $avg_total < 40 && $avg_high > 20 && $avg_high < 70 ) {
			return sprintf(
				/* translators: %s: sunrise or sunset */
				__( 'Clear skies with high clouds - expect dramatic, colorful %s with excellent photo opportunities', 'cloud-cover-forecast' ),
				$event_label
			);
		}

		// Clear conditions
		if ( $avg_total < 20 ) {
			return sprintf(
				/* translators: %s: sunrise or sunset */
				__( 'Clear conditions - excellent for %s photography with crisp, defined light', 'cloud-cover-forecast' ),
				$event_label
			);
		}

		// Good conditions
		if ( $avg_total < 40 ) {
			return sprintf(
				/* translators: %s: sunrise or sunset */
				__( 'Mostly clear with some clouds - good conditions for %s photos with interesting cloud features', 'cloud-cover-forecast' ),
				$event_label
			);
		}

		// Moderate conditions
		if ( $avg_total < 60 ) {
			if ( $avg_low > 40 ) {
				return sprintf(
					/* translators: %s: sunrise or sunset */
					__( 'Partly cloudy with low cloud cover - challenging for %s but may offer moody, diffused light', 'cloud-cover-forecast' ),
					$event_label
				);
			}
			return sprintf(
				/* translators: %s: sunrise or sunset */
				__( 'Partly cloudy - fair conditions for %s with mixed lighting opportunities', 'cloud-cover-forecast' ),
				$event_label
			);
		}

		// Heavy cloud cover
		if ( $avg_total < 80 ) {
			return sprintf(
				/* translators: %s: sunrise or sunset */
				__( 'Mostly cloudy - difficult conditions for %s photography with limited direct light', 'cloud-cover-forecast' ),
				$event_label
			);
		}

		// Very cloudy
		return sprintf(
			/* translators: %s: sunrise or sunset */
			__( 'Overcast - poor conditions for %s photography with heavily diffused or blocked light', 'cloud-cover-forecast' ),
			$event_label
		);
	}

	/**
	 * Render the 3-day forecast HTML
	 *
	 * @since 1.0.0
	 * @param array  $data Forecast data.
	 * @param string $location Location name.
	 * @return string HTML output.
	 */
	private function render_forecast( array $data, string $location ): string {
		$days = $data['days'] ?? array();
		$timezone = $data['timezone'] ?? 'UTC';

		if ( empty( $days ) ) {
			return '<div class="sunrise-sunset-forecast-empty">' . esc_html__( 'No forecast data available.', 'cloud-cover-forecast' ) . '</div>';
		}

		ob_start();
		$this->output_inline_css();
		?>
		<div class="sunrise-sunset-forecast">
			<div class="sunrise-sunset-header">
				<h3><?php esc_html_e( '3-Day Sunrise & Sunset Forecast', 'cloud-cover-forecast' ); ?></h3>
				<?php if ( ! empty( $location ) ) : ?>
					<div class="sunrise-sunset-location">
						<?php echo esc_html( $location ); ?>
					</div>
				<?php endif; ?>
			</div>

			<?php foreach ( $days as $day ) : ?>
				<?php
				$day_name = $day['sunrise_dt']->format( 'l, M j' );
				$sunrise_time = $day['sunrise_dt']->format( 'H:i' );
				$sunset_time = $day['sunset_dt']->format( 'H:i' );
				$daylight = sprintf( '%dh %dm', $day['daylight_hours'], $day['daylight_minutes'] );

				$sunrise_summary = $this->get_shooting_condition_summary( $day['sunrise_clouds'], 'sunrise' );
				$sunset_summary = $this->get_shooting_condition_summary( $day['sunset_clouds'], 'sunset' );
				?>

				<div class="sunrise-sunset-day">
					<div class="day-header">
						<strong><?php echo esc_html( $day_name ); ?></strong>
					</div>

					<div class="day-content">
						<div class="event-block sunrise-block">
							<div class="event-time">
								<span class="event-icon">🌅</span>
								<span class="event-label"><?php esc_html_e( 'Sunrise:', 'cloud-cover-forecast' ); ?></span>
								<span class="time-value"><?php echo esc_html( $sunrise_time ); ?></span>
							</div>
							<div class="event-summary">
								<?php echo esc_html( $sunrise_summary ); ?>
							</div>
							<div class="cloud-details">
								<?php
								printf(
									/* translators: 1: total cloud %, 2: low cloud %, 3: mid cloud %, 4: high cloud % */
									esc_html__( 'Cloud cover: %1$d%% (Low: %2$d%%, Mid: %3$d%%, High: %4$d%%)', 'cloud-cover-forecast' ),
									intval( $day['sunrise_clouds']['avg_total'] ),
									intval( $day['sunrise_clouds']['avg_low'] ),
									intval( $day['sunrise_clouds']['avg_mid'] ),
									intval( $day['sunrise_clouds']['avg_high'] )
								);
								?>
							</div>
						</div>

						<div class="event-block sunset-block">
							<div class="event-time">
								<span class="event-icon">🌇</span>
								<span class="event-label"><?php esc_html_e( 'Sunset:', 'cloud-cover-forecast' ); ?></span>
								<span class="time-value"><?php echo esc_html( $sunset_time ); ?></span>
							</div>
							<div class="event-summary">
								<?php echo esc_html( $sunset_summary ); ?>
							</div>
							<div class="cloud-details">
								<?php
								printf(
									/* translators: 1: total cloud %, 2: low cloud %, 3: mid cloud %, 4: high cloud % */
									esc_html__( 'Cloud cover: %1$d%% (Low: %2$d%%, Mid: %3$d%%, High: %4$d%%)', 'cloud-cover-forecast' ),
									intval( $day['sunset_clouds']['avg_total'] ),
									intval( $day['sunset_clouds']['avg_low'] ),
									intval( $day['sunset_clouds']['avg_mid'] ),
									intval( $day['sunset_clouds']['avg_high'] )
								);
								?>
							</div>
						</div>

						<div class="daylight-info">
							<span class="daylight-icon">⏱️</span>
							<?php
							printf(
								/* translators: %s: daylight duration */
								esc_html__( 'Daylight: %s', 'cloud-cover-forecast' ),
								esc_html( $daylight )
							);
							?>
						</div>
					</div>
				</div>

			<?php endforeach; ?>

			<div class="sunrise-sunset-footer">
				<div class="forecast-meta">
					<?php
					printf(
						/* translators: 1: latitude, 2: longitude, 3: timezone */
						esc_html__( 'Location: %1$s, %2$s · Timezone: %3$s · Data: Open-Meteo + Met.no', 'cloud-cover-forecast' ),
						esc_html( number_format( $data['lat'], 4 ) ),
						esc_html( number_format( $data['lon'], 4 ) ),
						esc_html( $timezone )
					);
					?>
				</div>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Output inline CSS for the block
	 *
	 * @since 1.0.0
	 */
	private function output_inline_css() {
		static $css_output = false;

		if ( $css_output ) {
			return;
		}

		$css_output = true;
		?>
		<style>
		.sunrise-sunset-forecast {
			font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
			max-width: 800px;
			margin: 2rem auto;
			background: #fff;
			border: 1px solid #e0e0e0;
			border-radius: 8px;
			box-shadow: 0 2px 4px rgba(0,0,0,0.1);
			padding: 1.5rem;
		}

		.sunrise-sunset-header {
			text-align: center;
			margin-bottom: 2rem;
			padding-bottom: 1rem;
			border-bottom: 2px solid #f0f0f0;
		}

		.sunrise-sunset-header h3 {
			margin: 0 0 0.5rem 0;
			font-size: 1.5rem;
			color: #333;
		}

		.sunrise-sunset-location {
			font-size: 1rem;
			color: #666;
			font-weight: 500;
		}

		.sunrise-sunset-day {
			background: #f9f9f9;
			border: 1px solid #e8e8e8;
			border-radius: 6px;
			margin-bottom: 1.5rem;
			overflow: hidden;
		}

		.sunrise-sunset-day:last-of-type {
			margin-bottom: 0;
		}

		.day-header {
			background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
			color: #fff;
			padding: 0.75rem 1rem;
			font-size: 1.1rem;
		}

		.day-content {
			padding: 1.25rem;
		}

		.event-block {
			margin-bottom: 1.25rem;
			padding: 1rem;
			background: #fff;
			border-radius: 6px;
			border-left: 4px solid #ddd;
		}

		.sunrise-block {
			border-left-color: #ff9800;
		}

		.sunset-block {
			border-left-color: #f44336;
		}

		.event-time {
			display: flex;
			align-items: center;
			gap: 0.5rem;
			margin-bottom: 0.75rem;
			font-size: 1.1rem;
		}

		.event-icon {
			font-size: 1.5rem;
		}

		.event-label {
			font-weight: 600;
			color: #333;
		}

		.time-value {
			font-weight: 700;
			color: #667eea;
			font-size: 1.2rem;
		}

		.event-summary {
			padding: 0.75rem;
			background: #f0f4ff;
			border-radius: 4px;
			margin-bottom: 0.75rem;
			line-height: 1.5;
			color: #333;
			font-size: 0.95rem;
		}

		.cloud-details {
			font-size: 0.85rem;
			color: #666;
			padding-left: 2rem;
		}

		.daylight-info {
			display: flex;
			align-items: center;
			gap: 0.5rem;
			padding: 0.75rem 1rem;
			background: #fff3e0;
			border-radius: 4px;
			margin-top: 1rem;
			font-weight: 600;
			color: #e65100;
		}

		.daylight-icon {
			font-size: 1.2rem;
		}

		.sunrise-sunset-footer {
			margin-top: 2rem;
			padding-top: 1rem;
			border-top: 1px solid #e0e0e0;
		}

		.forecast-meta {
			font-size: 0.8rem;
			color: #999;
			text-align: center;
		}

		.sunrise-sunset-forecast-error,
		.sunrise-sunset-forecast-empty {
			padding: 2rem;
			text-align: center;
			color: #d32f2f;
			background: #ffebee;
			border: 1px solid #ffcdd2;
			border-radius: 4px;
		}

		@media (max-width: 640px) {
			.sunrise-sunset-forecast {
				padding: 1rem;
			}

			.sunrise-sunset-header h3 {
				font-size: 1.2rem;
			}

			.event-time {
				flex-wrap: wrap;
			}

			.time-value {
				font-size: 1.1rem;
			}
		}
		</style>
		<?php
	}

	/**
	 * Render search form for location lookup
	 *
	 * @since 1.0.0
	 * @return string Search form HTML.
	 */
	private function render_search_form() {
		$this->output_inline_css();

		return Cloud_Cover_Forecast_Location_Search_Form::render(
			array(
				'title'       => __( 'Find Sunrise & Sunset Times', 'cloud-cover-forecast' ),
				'description' => __( 'Enter a location to view 3-day sunrise and sunset forecast with shooting conditions.', 'cloud-cover-forecast' ),
				'mode'        => 'redirect',
				'url_params'  => array(
					'lat'      => 'sunrise_lat',
					'lon'      => 'sunrise_lon',
					'location' => 'sunrise_location',
				),
			)
		);
	}
}
