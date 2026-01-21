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
	 * Set to 10 requests per 5 minutes to allow for paired geocoding + forecast lookups.
	 * Each location search typically requires 2 requests (geocode + forecast).
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
	 * Tracks whether frontend assets were enqueued.
	 *
	 * @since 1.0.0
	 * @var bool
	 */
	private $assets_enqueued = false;

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

		// AJAX handlers
		add_action( 'wp_ajax_cloud_cover_forecast_public_lookup', array( $this, 'handle_ajax_lookup' ) );
		add_action( 'wp_ajax_nopriv_cloud_cover_forecast_public_lookup', array( $this, 'handle_ajax_lookup' ) );
		add_action( 'wp_ajax_cloud_cover_forecast_public_geocode', array( $this, 'handle_ajax_geocode' ) );
		add_action( 'wp_ajax_nopriv_cloud_cover_forecast_public_geocode', array( $this, 'handle_ajax_geocode' ) );

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
					'showOtherForecastApps' => array(
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
	 * Enqueue assets for the public block
	 *
	 * @since 1.0.0
	 */
	public function enqueue_assets() {
		if ( $this->assets_enqueued ) {
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
					/* translators: {time}: number of seconds before a new request can be made. */
					'rateLimitText' => __( 'Too many requests. Please wait {time} seconds before trying again.', 'cloud-cover-forecast' ),
					'multipleLocationsText' => __( 'Multiple matches found. Please pick one:', 'cloud-cover-forecast' ),
					'useLocationButtonText' => __( 'Use this location', 'cloud-cover-forecast' ),
				),
			)
		);

		$this->assets_enqueued = true;
	}

	/**
	 * Render the public block
	 *
	 * @since 1.0.0
	 * @param array $attributes Block attributes.
	 * @return string Block HTML.
	 */
	public function render_public_block( $attributes ) {
		$this->enqueue_assets();

		$attributes = wp_parse_args(
			$attributes,
			array(
				'title' => __( 'Cloud Cover Forecast', 'cloud-cover-forecast' ),
				'placeholder' => __( 'Enter location (e.g., London, UK)', 'cloud-cover-forecast' ),
				'buttonText' => __( 'Get Forecast', 'cloud-cover-forecast' ),
				'showPhotographyMode' => true,
				'showOtherForecastApps' => true,
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

				<div class="location-search-results" style="display: none;" role="listbox" aria-live="polite"></div>

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
		$nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'cloud_cover_forecast_public_lookup' ) ) {
			wp_send_json_error( __( 'Security check failed.', 'cloud-cover-forecast' ) );
		}

		// Check rate limiting
		if ( $this->is_rate_limited() ) {
			wp_send_json_error( __( 'Rate limit exceeded. Please try again later.', 'cloud-cover-forecast' ) );
		}

		// Get and validate parameters with proper sanitization
		$lat = isset( $_POST['lat'] ) ? floatval( wp_unslash( $_POST['lat'] ) ) : 0;
		$lon = isset( $_POST['lon'] ) ? floatval( wp_unslash( $_POST['lon'] ) ) : 0;
		$location = isset( $_POST['location'] ) ? sanitize_text_field( wp_unslash( $_POST['location'] ) ) : '';
		$hours = isset( $_POST['hours'] ) ? intval( wp_unslash( $_POST['hours'] ) ) : 48;
		$show_photography = isset( $_POST['show_photography'] ) ? intval( wp_unslash( $_POST['show_photography'] ) ) : 1;
		$show_photography = (bool) $show_photography;
		$show_other_forecast_apps = isset( $_POST['show_other_forecast_apps'] ) ? intval( wp_unslash( $_POST['show_other_forecast_apps'] ) ) : 1;
		$show_other_forecast_apps = (bool) $show_other_forecast_apps;

		// Validate coordinates
		if ( $lat < -90 || $lat > 90 || $lon < -180 || $lon > 180 ) {
			wp_send_json_error( __( 'Invalid coordinates provided.', 'cloud-cover-forecast' ) );
		}

		// Validate hours
		if ( $hours < 1 || $hours > 168 ) {
			$hours = 48;
		}

		// Fetch weather data
		$weather_data = $this->api->fetch_weather_data( $lat, $lon, $hours );
		if ( is_wp_error( $weather_data ) ) {
			wp_send_json_error( $weather_data->get_error_message() );
		}

		// Add photography data if requested
		if ( $show_photography ) {
			// Use the selected (relevant) sunrise/sunset when available
			$sunset_time = $weather_data['stats']['selected_sunset'] ?? ( $weather_data['stats']['daily_sunset'][0] ?? '' );
			$sunrise_time = $weather_data['stats']['selected_sunrise'] ?? ( $weather_data['stats']['daily_sunrise'][0] ?? '' );

			// Calculate photography data if we have sunrise/sunset data
			if ( ! empty( $sunset_time ) && ! empty( $sunrise_time ) ) {
				$photo_times = $this->photography_renderer->calculate_photography_times(
					$sunrise_time,
					$sunset_time,
					$weather_data['stats']['timezone']
				);
				$photo_ratings = $this->photography_renderer->rate_photography_conditions( $weather_data['stats'] );

				$weather_data['stats']['photo_times'] = $photo_times;
				$weather_data['stats']['photo_ratings'] = $photo_ratings;
			}
		}

		// Render the forecast
		$html = $this->render_forecast_html(
			$weather_data,
			$location,
			$show_photography,
			array(
				'show_other_forecast_apps' => $show_other_forecast_apps,
			)
		);

		// Record the request for rate limiting
		$this->record_request();

		wp_send_json_success( array( 'html' => $html ) );
	}

	/**
	 * Handle public geocoding requests via AJAX.
	 *
	 * @since 1.0.0
	 */
	public function handle_ajax_geocode() {
		$nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'cloud_cover_forecast_public_lookup' ) ) {
			wp_send_json_error( array( 'message' => __( 'Security check failed.', 'cloud-cover-forecast' ) ), 403 );
		}

		// Check rate limiting
		if ( $this->is_rate_limited() ) {
			wp_send_json_error( array( 'message' => __( 'Rate limit exceeded. Please try again later.', 'cloud-cover-forecast' ) ), 429 );
		}

		$location = isset( $_POST['location'] ) ? sanitize_text_field( wp_unslash( $_POST['location'] ) ) : '';
		if ( '' === $location ) {
			wp_send_json_error( array( 'message' => __( 'Please provide a location to search for.', 'cloud-cover-forecast' ) ), 400 );
		}

		$results = $this->get_geocode_results( $location );
		if ( is_wp_error( $results ) ) {
			$status = 500;
			switch ( $results->get_error_code() ) {
				case 'cloud_cover_forecast_empty_location':
					$status = 400;
					break;
				case 'cloud_cover_forecast_geocoding_not_found':
					$status = 404;
					break;
				case 'cloud_cover_forecast_rate_limit':
					$status = 429;
					break;
				case 'cloud_cover_forecast_geocoding_network':
				case 'cloud_cover_forecast_geocoding_http':
				case 'cloud_cover_forecast_api_unavailable':
					$status = 503;
					break;
			}

			wp_send_json_error( array( 'message' => $results->get_error_message() ), $status );
		}

		// Record the request for rate limiting
		$this->record_request();

		wp_send_json_success(
			array(
				'results' => $results,
			)
		);
	}

	/**
	 * Retrieve sanitized geocoding results for a location.
	 *
	 * @since 1.0.0
	 * @param string $location Location name.
	 * @return array|WP_Error
	 */
	private function get_geocode_results( string $location ) {
		$api = $this->plugin->get_api();
		if ( ! $api ) {
			return new WP_Error( 'cloud_cover_forecast_api_unavailable', __( 'Geocoding service unavailable.', 'cloud-cover-forecast' ) );
		}

		$geocoded = $api->geocode_location( $location );
		if ( is_wp_error( $geocoded ) ) {
			return $geocoded;
		}

		if ( isset( $geocoded['lat'] ) && isset( $geocoded['lon'] ) ) {
			$geocoded = array( $geocoded );
		}

		$results = array();
		foreach ( (array) $geocoded as $item ) {
			$results[] = array(
				'name'      => isset( $item['name'] ) ? sanitize_text_field( $item['name'] ) : '',
				'admin1'    => isset( $item['admin1'] ) ? sanitize_text_field( $item['admin1'] ) : '',
				'admin2'    => isset( $item['admin2'] ) ? sanitize_text_field( $item['admin2'] ) : '',
				'country'   => isset( $item['country'] ) ? sanitize_text_field( $item['country'] ) : '',
				'timezone'  => isset( $item['timezone'] ) ? sanitize_text_field( $item['timezone'] ) : '',
				'latitude'  => isset( $item['lat'] ) ? floatval( $item['lat'] ) : null,
				'longitude' => isset( $item['lon'] ) ? floatval( $item['lon'] ) : null,
			);
		}

		return $results;
	}

	/**
	 * Render forecast HTML
	 *
	 * @since 1.0.0
	 * @param array  $data Weather data.
	 * @param string $location Location name.
	 * @param bool   $show_photography Whether to show photography mode.
	 * @param array  $options Rendering options.
	 * @return string HTML output.
	 */
	private function render_forecast_html( $data, $location, $show_photography, $options = array() ) {
		ob_start();

		if ( $show_photography ) {
			$this->photography_renderer->render_photography_widget( $data, $location, 1, $options );
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
					<?php /* translators: %d: number of forecast hours being displayed. */ ?>
					<strong>🌤️ <?php printf( esc_html__( 'Cloud Cover Forecast (next %dh)', 'cloud-cover-forecast' ), count( $rows ) ); ?></strong>
					<?php if ( $location ) : ?>
						<span class="cloud-cover-forecast-badge"><?php echo esc_html( $location ); ?></span>
					<?php endif; ?>
				</div>
				<div class="cloud-cover-forecast-meta">
					<?php /* translators: %s: timezone name such as "America/New_York". */ ?>
					<?php printf( esc_html__( 'Local Timezone: %s', 'cloud-cover-forecast' ), esc_html( $stats['timezone'] ) ); ?>
				</div>
			</div>

			<div class="cloud-cover-forecast-summary">
				<div class="cloud-cover-forecast-stats">
					<?php
					printf(
						/* translators: 1: average total cloud cover, 2: average low cloud, 3: average mid cloud, 4: average high cloud. */
						esc_html__( 'Avg — Total: %1$s%% · Low: %2$s%% · Mid: %3$s%% · High: %4$s%%', 'cloud-cover-forecast' ),
						esc_html( $stats['avg_total'] ),
						esc_html( $stats['avg_low'] ),
						esc_html( $stats['avg_mid'] ),
						esc_html( $stats['avg_high'] )
					);
					?>
				</div>

				<?php
				$provider_notice = $this->photography_renderer->render_provider_diff_notice( $stats );
				if ( $provider_notice ) {
					echo $provider_notice; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- already escaped within helper
				}
				?>
			</div>

			<table class="cloud-cover-forecast-table" role="table">
				<thead>
					<tr>
						<th class="cloud-cover-forecast-th"><?php esc_html_e( 'Time', 'cloud-cover-forecast' ); ?></th>
						<th class="cloud-cover-forecast-th"><?php esc_html_e( 'Low', 'cloud-cover-forecast' ); ?></th>
						<th class="cloud-cover-forecast-th"><?php esc_html_e( 'Mid', 'cloud-cover-forecast' ); ?></th>
						<th class="cloud-cover-forecast-th"><?php esc_html_e( 'High', 'cloud-cover-forecast' ); ?></th>
						<th class="cloud-cover-forecast-th"><?php esc_html_e( 'Total', 'cloud-cover-forecast' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php
				$allowed_markup = $this->photography_renderer->get_allowed_cloud_markup();
				foreach ( $rows as $r ) : ?>
					<tr>
						<td class="cloud-cover-forecast-td"><?php
							$hour_dt = new DateTime( '@' . $r['ts'] );
							$hour_dt->setTimezone( new DateTimeZone( $stats['timezone'] ) );
							echo esc_html( $hour_dt->format( 'M j, H:i' ) );
						?></td>
						<td class="cloud-cover-forecast-td"><?php echo wp_kses( $this->photography_renderer->format_cloud_cover_value( $r, 'low' ), $allowed_markup ); ?></td>
						<td class="cloud-cover-forecast-td"><?php echo wp_kses( $this->photography_renderer->format_cloud_cover_value( $r, 'mid' ), $allowed_markup ); ?></td>
						<td class="cloud-cover-forecast-td"><?php echo wp_kses( $this->photography_renderer->format_cloud_cover_value( $r, 'high' ), $allowed_markup ); ?></td>
						<td class="cloud-cover-forecast-td"><?php echo wp_kses( $this->photography_renderer->format_cloud_cover_value( $r, 'total' ), $allowed_markup ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>

			<div class="cloud-cover-forecast-footer">
				<div class="cloud-cover-forecast-meta">
					<?php
					printf(
						/* translators: 1: latitude, 2: longitude. */
						esc_html__( 'Location: %1$s, %2$s · Weather: Open‑Meteo + Met.no', 'cloud-cover-forecast' ),
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
			$this->plugin->unregister_transient_key( $transient_key );
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
		$this->plugin->register_transient_key( $transient_key );
	}

	/**
	 * Get client IP address with protection against IP spoofing
	 *
	 * By default, only trusts REMOTE_ADDR to prevent rate limit bypass attacks.
	 * Site admins can enable proxy header support via filters if behind a CDN/proxy.
	 *
	 * @since 1.0.0
	 * @return string Client IP address.
	 */
	private function get_client_ip() {
		// Get the direct connection IP (most secure, cannot be spoofed)
		$remote_addr = isset( $_SERVER['REMOTE_ADDR'] ) ?
			sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) :
			'0.0.0.0';

		/**
		 * Filter to enable trust of proxy headers.
		 *
		 * WARNING: Only enable this if your site is behind a known CDN/proxy.
		 * Enabling this without proper configuration allows rate limit bypass attacks.
		 *
		 * @since 1.0.0
		 * @param bool $trust_proxy_headers Whether to trust proxy headers. Default false.
		 */
		$trust_proxy_headers = apply_filters(
			'cloud_cover_forecast_trust_proxy_headers',
			false
		);

		/**
		 * Filter to define trusted proxy IP addresses.
		 *
		 * Only requests from these IPs will have their proxy headers trusted.
		 * Example: array( '192.168.1.1', '10.0.0.1' )
		 *
		 * @since 1.0.0
		 * @param array $trusted_proxies Array of trusted proxy IP addresses. Default empty.
		 */
		$trusted_proxies = apply_filters(
			'cloud_cover_forecast_trusted_proxies',
			array()
		);

		// Only trust proxy headers if explicitly enabled AND request is from trusted proxy
		if ( $trust_proxy_headers && ! empty( $trusted_proxies ) && in_array( $remote_addr, $trusted_proxies, true ) ) {
			// Check proxy headers in order of preference
			$proxy_headers = array(
				'HTTP_CF_CONNECTING_IP',  // Cloudflare
				'HTTP_X_FORWARDED_FOR',   // Standard proxy header
				'HTTP_X_REAL_IP',         // Nginx proxy
			);

			foreach ( $proxy_headers as $header ) {
				if ( ! empty( $_SERVER[ $header ] ) ) {
					$ip = sanitize_text_field( wp_unslash( $_SERVER[ $header ] ) );

					// X-Forwarded-For can contain multiple IPs (client, proxy1, proxy2)
					// Take the first one (original client)
					if ( strpos( $ip, ',' ) !== false ) {
						$ip = trim( explode( ',', $ip )[0] );
					}

					// Validate IP and ensure it's not a private/reserved range
					if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
						return $ip;
					}
				}
			}
		}

		// Default: Use REMOTE_ADDR (most reliable, not spoofable via HTTP headers)
		if ( filter_var( $remote_addr, FILTER_VALIDATE_IP ) ) {
			return $remote_addr;
		}

		return '0.0.0.0';
	}
}
