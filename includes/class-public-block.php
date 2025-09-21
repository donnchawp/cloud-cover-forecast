<?php
/**
 * Public Block functionality for Cloud Cover Forecast Plugin
 *
 * @package CloudCoverForecast
 * @since 1.0.0
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Public Block class for Cloud Cover Forecast Plugin
 *
 * @since 1.0.0
 */
class Cloud_Cover_Forecast_Public_Block {

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
	 * Photography renderer instance
	 *
	 * @since 1.0.0
	 * @var Cloud_Cover_Forecast_Photography_Renderer
	 */
	private $photography_renderer;

	/**
	 * Rate limiting configuration
	 *
	 * @since 1.0.0
	 * @var array
	 */
	private $rate_limit_config = array(
		'window_minutes' => 5,
		'max_requests'   => 10,
		'ban_minutes'    => 15,
	);

	/**
	 * Constructor
	 *
	 * @since 1.0.0
	 * @param Cloud_Cover_Forecast_Plugin $plugin Plugin instance.
	 */
	public function __construct( $plugin ) {
		$this->plugin = $plugin;
		$this->api = $plugin->get_api();
		$this->photography_renderer = $plugin->get_photography_renderer();
	}

	/**
	 * Initialize the public block functionality
	 *
	 * @since 1.0.0
	 */
	public function init() {
		// Register the block
		add_action( 'init', array( $this, 'register_block' ) );

		// Enqueue assets
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );

		// AJAX handlers
		add_action( 'wp_ajax_cloud_cover_forecast_public_lookup', array( $this, 'handle_ajax_lookup' ) );
		add_action( 'wp_ajax_nopriv_cloud_cover_forecast_public_lookup', array( $this, 'handle_ajax_lookup' ) );

		// Register block render callback
		add_action( 'init', array( $this, 'register_render_callback' ) );
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

		register_block_type(
			'cloud-cover-forecast/public-lookup',
			array(
				'editor_script' => 'cloud-cover-forecast-public-block-editor',
				'render_callback' => array( $this, 'render_public_block' ),
				'attributes' => array(
					'title' => array(
						'type' => 'string',
						'default' => __( 'Cloud Cover Forecast', 'cloud-cover-forecast' ),
					),
					'placeholder' => array(
						'type' => 'string',
						'default' => __( 'Enter location (e.g., London, UK)', 'cloud-cover-forecast' ),
					),
					'buttonText' => array(
						'type' => 'string',
						'default' => __( 'Get Forecast', 'cloud-cover-forecast' ),
					),
					'showPhotographyMode' => array(
						'type' => 'boolean',
						'default' => true,
					),
					'maxHours' => array(
						'type' => 'number',
						'default' => 24,
					),
				),
			)
		);
	}

	/**
	 * Register the render callback for the block
	 *
	 * @since 1.0.0
	 */
	public function register_render_callback() {
		// This is handled in register_block() above
	}

	/**
	 * Enqueue assets for the public block
	 *
	 * @since 1.0.0
	 */
	public function enqueue_assets() {
		// Only enqueue on pages that have the block
		if ( ! $this->has_public_block() ) {
			return;
		}

		// Enqueue CSS
		wp_enqueue_style(
			'cloud-cover-forecast-public-block',
			CLOUD_COVER_FORECAST_PLUGIN_URL . 'assets/css/public-block.css',
			array(),
			CLOUD_COVER_FORECAST_VERSION
		);

		// Enqueue JavaScript
		wp_enqueue_script(
			'cloud-cover-forecast-public-block',
			CLOUD_COVER_FORECAST_PLUGIN_URL . 'assets/js/public-block.js',
			array( 'jquery' ),
			CLOUD_COVER_FORECAST_VERSION,
			true
		);

		// Localize script with AJAX data
		wp_localize_script(
			'cloud-cover-forecast-public-block',
			'cloudCoverForecastPublic',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce' => wp_create_nonce( 'cloud_cover_forecast_public_lookup' ),
				'strings' => array(
					'searchingText' => __( 'Searching...', 'cloud-cover-forecast' ),
					'locationNotFoundText' => __( 'Location not found. Please try a different search term.', 'cloud-cover-forecast' ),
					'geocodingErrorText' => __( 'Unable to find location. Please check your internet connection and try again.', 'cloud-cover-forecast' ),
					'forecastErrorText' => __( 'Unable to fetch forecast data. Please try again later.', 'cloud-cover-forecast' ),
					'rateLimitText' => __( 'Too many requests. Please wait {time} seconds before trying again.', 'cloud-cover-forecast' ),
				),
			)
		);
	}

	/**
	 * Check if the current page has the public block
	 *
	 * @since 1.0.0
	 * @return bool True if page has the block, false otherwise.
	 */
	private function has_public_block() {
		global $post;

		if ( ! $post ) {
			return false;
		}

		// Check if the post content contains the block
		return has_block( 'cloud-cover-forecast/public-lookup', $post );
	}

	/**
	 * Render the public block
	 *
	 * @since 1.0.0
	 * @param array $attributes Block attributes.
	 * @return string Block HTML.
	 */
	public function render_public_block( $attributes ) {
		$attributes = wp_parse_args(
			$attributes,
			array(
				'title' => __( 'Cloud Cover Forecast', 'cloud-cover-forecast' ),
				'placeholder' => __( 'Enter location (e.g., London, UK)', 'cloud-cover-forecast' ),
				'buttonText' => __( 'Get Forecast', 'cloud-cover-forecast' ),
				'showPhotographyMode' => true,
				'maxHours' => 24,
			)
		);

		// Generate unique ID for this block instance
		$block_id = 'cloud-cover-forecast-public-' . wp_generate_uuid4();

		ob_start();
		?>
		<div class="wp-block-cloud-cover-forecast-public-lookup">
			<div class="cloud-cover-forecast-public-lookup"
				 data-block-attributes="<?php echo esc_attr( wp_json_encode( $attributes ) ); ?>"
				 id="<?php echo esc_attr( $block_id ); ?>">

				<div class="search-form">
					<input type="text"
						   class="location-search-input"
						   placeholder="<?php echo esc_attr( $attributes['placeholder'] ); ?>"
						   aria-label="<?php esc_attr_e( 'Location search', 'cloud-cover-forecast' ); ?>">
					<button type="button"
							class="location-search-button">
						<?php echo esc_html( $attributes['buttonText'] ); ?>
					</button>
				</div>

				<div class="loading-spinner" style="display: none;"></div>
				<div class="error-message" style="display: none;"></div>
				<div class="rate-limit-message" style="display: none;"></div>
				<div class="forecast-results" style="display: none;"></div>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Handle AJAX lookup request
	 *
	 * @since 1.0.0
	 */
	public function handle_ajax_lookup() {
		// Verify nonce first
		if ( ! wp_verify_nonce( $_POST['nonce'] ?? '', 'cloud_cover_forecast_public_lookup' ) ) {
			wp_send_json_error( __( 'Security check failed.', 'cloud-cover-forecast' ) );
		}

		// Check rate limiting
		if ( $this->is_rate_limited() ) {
			wp_send_json_error( __( 'Rate limit exceeded. Please try again later.', 'cloud-cover-forecast' ) );
		}

		// Get and validate parameters with proper sanitization
		$lat = isset( $_POST['lat'] ) ? floatval( $_POST['lat'] ) : 0;
		$lon = isset( $_POST['lon'] ) ? floatval( $_POST['lon'] ) : 0;
		$location = isset( $_POST['location'] ) ? sanitize_text_field( $_POST['location'] ) : '';
		$hours = isset( $_POST['hours'] ) ? intval( $_POST['hours'] ) : 48;
		$show_photography = isset( $_POST['show_photography'] ) ? intval( $_POST['show_photography'] ) : 1;

		// Validate coordinates
		if ( $lat < -90 || $lat > 90 || $lon < -180 || $lon > 180 ) {
			wp_send_json_error( __( 'Invalid coordinates provided.', 'cloud-cover-forecast' ) );
		}

		// Validate hours
		if ( $hours < 1 || $hours > 168 ) {
			$hours = 48;
		}

		// Fetch weather data
		$weather_data = $this->api->fetch_open_meteo( $lat, $lon, $hours );
		if ( is_wp_error( $weather_data ) ) {
			wp_send_json_error( __( 'Unable to fetch weather data. Please try again later.', 'cloud-cover-forecast' ) );
		}
		error_log( 'Weather data: ' . print_r( $weather_data, true ) );

		// Add photography data if requested
		if ( $show_photography ) {
			$photography = $this->plugin->get_photography();

			// Use the selected (relevant) sunrise/sunset when available
			$sunset_time = $weather_data['stats']['selected_sunset'] ?? ( $weather_data['stats']['daily_sunset'][0] ?? '' );
			$sunrise_time = $weather_data['stats']['selected_sunrise'] ?? ( $weather_data['stats']['daily_sunrise'][0] ?? '' );

			// Calculate photography data if we have sunrise/sunset data
			if ( ! empty( $sunset_time ) && ! empty( $sunrise_time ) ) {
				$photo_times = $photography->calculate_photography_times(
					$sunrise_time,
					$sunset_time,
					$weather_data['stats']['timezone']
				);
				$photo_ratings = $photography->rate_photography_conditions( $weather_data['stats'] );

				$weather_data['stats']['photo_times'] = $photo_times;
				$weather_data['stats']['photo_ratings'] = $photo_ratings;
			}
		}

		// Render the forecast
		$html = $this->render_forecast_html( $weather_data, $location, $show_photography );

		// Record the request for rate limiting
		$this->record_request();

		wp_send_json_success( array( 'html' => $html ) );
	}

	/**
	 * Render forecast HTML
	 *
	 * @since 1.0.0
	 * @param array  $data Weather data.
	 * @param string $location Location name.
	 * @param bool   $show_photography Whether to show photography mode.
	 * @return string HTML output.
	 */
	private function render_forecast_html( $data, $location, $show_photography ) {
		ob_start();
		error_log( 'Rendering forecast HTML: ' . print_r( $data, true ) );

		if ( $show_photography ) {
			$this->photography_renderer->render_photography_widget( $data, $location, 1 );
		} else {
			$this->render_basic_forecast( $data, $location );
		}

		return ob_get_clean();
	}

	/**
	 * Render basic forecast (non-photography mode)
	 *
	 * @since 1.0.0
	 * @param array  $data Weather data.
	 * @param string $location Location name.
	 */
	private function render_basic_forecast( $data, $location ) {
		$rows = $data['rows'];
		$stats = $data['stats'];
		?>
		<div class="cloud-cover-forecast-wrap cloud-cover-forecast-card">
			<div class="cloud-cover-forecast-header">
				<div>
					<strong>🌤️ <?php printf( esc_html__( 'Cloud Cover Forecast (next %dh)', 'cloud-cover-forecast' ), count( $rows ) ); ?></strong>
					<?php if ( $location ) : ?>
						<span class="cloud-cover-forecast-badge"><?php echo esc_html( $location ); ?></span>
					<?php endif; ?>
				</div>
				<div class="cloud-cover-forecast-meta">
					<?php printf( esc_html__( 'Local Timezone: %s', 'cloud-cover-forecast' ), esc_html( $stats['timezone'] ) ); ?>
				</div>
			</div>

			<div class="cloud-cover-forecast-summary">
				<div class="cloud-cover-forecast-stats">
					<?php
					printf(
						esc_html__( 'Avg — Total: %1$s%% · Low: %2$s%% · Mid: %3$s%% · High: %4$s%%', 'cloud-cover-forecast' ),
						esc_html( $stats['avg_total'] ),
						esc_html( $stats['avg_low'] ),
						esc_html( $stats['avg_mid'] ),
						esc_html( $stats['avg_high'] )
					);
					?>
				</div>
			</div>

			<table class="cloud-cover-forecast-table" role="table">
				<thead>
					<tr>
						<th class="cloud-cover-forecast-th"><?php esc_html_e( 'Time', 'cloud-cover-forecast' ); ?></th>
						<th class="cloud-cover-forecast-th"><?php esc_html_e( 'Total', 'cloud-cover-forecast' ); ?></th>
						<th class="cloud-cover-forecast-th"><?php esc_html_e( 'Low', 'cloud-cover-forecast' ); ?></th>
						<th class="cloud-cover-forecast-th"><?php esc_html_e( 'Mid', 'cloud-cover-forecast' ); ?></th>
						<th class="cloud-cover-forecast-th"><?php esc_html_e( 'High', 'cloud-cover-forecast' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ( $rows as $r ) : ?>
					<tr>
						<td class="cloud-cover-forecast-td"><?php
							$hour_dt = new DateTime( '@' . $r['ts'] );
							$hour_dt->setTimezone( new DateTimeZone( $stats['timezone'] ) );
							echo esc_html( $hour_dt->format( 'M j, H:i' ) );
						?></td>
						<td class="cloud-cover-forecast-td"><?php echo esc_html( $r['total'] . '%' ); ?></td>
						<td class="cloud-cover-forecast-td"><?php echo esc_html( $r['low'] . '%' ); ?></td>
						<td class="cloud-cover-forecast-td"><?php echo esc_html( $r['mid'] . '%' ); ?></td>
						<td class="cloud-cover-forecast-td"><?php echo esc_html( $r['high'] . '%' ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>

			<div class="cloud-cover-forecast-footer">
				<div class="cloud-cover-forecast-meta">
					<?php
					printf(
						esc_html__( 'Location: %1$s, %2$s · Weather: Open‑Meteo', 'cloud-cover-forecast' ),
						esc_html( number_format( $stats['lat'], 4 ) ),
						esc_html( number_format( $stats['lon'], 4 ) )
					);
					?>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Check if the current IP is rate limited
	 *
	 * @since 1.0.0
	 * @return bool True if rate limited, false otherwise.
	 */
	private function is_rate_limited() {
		$ip = $this->get_client_ip();
		$transient_key = 'cloud_cover_forecast_rate_limit_' . md5( $ip );

		$rate_data = get_transient( $transient_key );
		if ( ! $rate_data ) {
			return false;
		}

		$now = time();
		$window_start = $rate_data['window_start'];
		$request_count = $rate_data['count'];

		// Reset window if expired
		if ( $now - $window_start > ( $this->rate_limit_config['window_minutes'] * 60 ) ) {
			delete_transient( $transient_key );
			return false;
		}

		// Check if rate limit exceeded
		return $request_count >= $this->rate_limit_config['max_requests'];
	}

	/**
	 * Record a request for rate limiting
	 *
	 * @since 1.0.0
	 */
	private function record_request() {
		$ip = $this->get_client_ip();
		$transient_key = 'cloud_cover_forecast_rate_limit_' . md5( $ip );

		$rate_data = get_transient( $transient_key );
		$now = time();

		if ( ! $rate_data ) {
			$rate_data = array(
				'window_start' => $now,
				'count' => 1,
			);
		} else {
			// Reset window if expired
			if ( $now - $rate_data['window_start'] > ( $this->rate_limit_config['window_minutes'] * 60 ) ) {
				$rate_data = array(
					'window_start' => $now,
					'count' => 1,
				);
			} else {
				$rate_data['count']++;
			}
		}

		// Store for the window duration
		set_transient( $transient_key, $rate_data, $this->rate_limit_config['window_minutes'] * 60 );
	}

	/**
	 * Get client IP address
	 *
	 * @since 1.0.0
	 * @return string Client IP address.
	 */
	private function get_client_ip() {
		$ip_keys = array( 'HTTP_CF_CONNECTING_IP', 'HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_FORWARDED', 'HTTP_X_CLUSTER_CLIENT_IP', 'HTTP_FORWARDED_FOR', 'HTTP_FORWARDED', 'REMOTE_ADDR' );

		foreach ( $ip_keys as $key ) {
			if ( array_key_exists( $key, $_SERVER ) === true ) {
				$ip = $_SERVER[ $key ];
				if ( strpos( $ip, ',' ) !== false ) {
					$ip = explode( ',', $ip )[0];
				}
				$ip = trim( $ip );
				// Validate IP address
				if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
					return $ip;
				}
			}
		}

		// Fallback to REMOTE_ADDR with validation
		$fallback_ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
		if ( filter_var( $fallback_ip, FILTER_VALIDATE_IP ) ) {
			return $fallback_ip;
		}

		return '0.0.0.0';
	}
}
