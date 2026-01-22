<?php
/**
 * Admin functionality for Cloud Cover Forecast Plugin
 *
 * @package CloudCoverForecast
 * @since 1.0.0
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin class for Cloud Cover Forecast Plugin
 *
 * @since 1.0.0
 */
class Cloud_Cover_Forecast_Admin {

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
	 * Initialize admin functionality
	 *
	 * @since 1.0.0
	 */
	public function init() {
		add_action( 'admin_menu', array( $this, 'add_settings_page' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'init', array( $this, 'register_gutenberg_block' ) );
		add_action( 'wp_ajax_ccf_geocode', array( $this, 'ajax_geocode_location' ) );
	}

	/**
	 * Add settings page to WordPress admin menu
	 *
	 * @since 1.0.0
	 */
	public function add_settings_page() {
		add_options_page(
			__( 'Cloud Cover Forecast', 'cloud-cover-forecast' ),
			__( 'Cloud Cover', 'cloud-cover-forecast' ),
			'manage_options',
			'cloud-cover-forecast-settings',
			array( $this, 'render_settings_page' )
		);
	}

	/**
	 * Register plugin settings
	 *
	 * @since 1.0.0
	 */
	public function register_settings() {
		register_setting(
			'cloud_cover_forecast_settings_group',
			$this->plugin::OPTION_KEY,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_settings' ),
				'default'           => $this->plugin::get_defaults(),
			)
		);

		add_settings_section(
			'cloud_cover_forecast_main',
			__( 'Defaults', 'cloud-cover-forecast' ),
			array( $this, 'settings_section_callback' ),
			'cloud-cover-forecast-settings'
		);

		// Register individual settings fields
		add_settings_field(
			'cloud_cover_forecast_lat',
			__( 'Latitude', 'cloud-cover-forecast' ),
			array( $this, 'render_lat_field' ),
			'cloud-cover-forecast-settings',
			'cloud_cover_forecast_main'
		);

		add_settings_field(
			'cloud_cover_forecast_lon',
			__( 'Longitude', 'cloud-cover-forecast' ),
			array( $this, 'render_lon_field' ),
			'cloud-cover-forecast-settings',
			'cloud_cover_forecast_main'
		);

		add_settings_field(
			'cloud_cover_forecast_hours',
			__( 'Hours Ahead', 'cloud-cover-forecast' ),
			array( $this, 'render_hours_field' ),
			'cloud-cover-forecast-settings',
			'cloud_cover_forecast_main'
		);

		add_settings_field(
			'cloud_cover_forecast_cache_ttl',
			__( 'Cache TTL (minutes)', 'cloud-cover-forecast' ),
			array( $this, 'render_cache_ttl_field' ),
			'cloud-cover-forecast-settings',
			'cloud_cover_forecast_main'
		);

		add_settings_field(
			'cloud_cover_forecast_location_search',
			__( 'Location Search', 'cloud-cover-forecast' ),
			array( $this, 'render_location_search_field' ),
			'cloud-cover-forecast-settings',
			'cloud_cover_forecast_main'
		);

		add_settings_field(
			'cloud_cover_forecast_astro_api_key',
			__( 'Astronomy API Key', 'cloud-cover-forecast' ),
			array( $this, 'render_astro_api_key_field' ),
			'cloud-cover-forecast-settings',
			'cloud_cover_forecast_main'
		);

		add_settings_field(
			'cloud_cover_forecast_clear_cache',
			__( 'Clear Cache', 'cloud-cover-forecast' ),
			array( $this, 'render_clear_cache_field' ),
			'cloud-cover-forecast-settings',
			'cloud_cover_forecast_main'
		);
	}

	/**
	 * Settings section callback
	 *
	 * @since 1.0.0
	 */
	public function settings_section_callback() {
		echo '<p>' . esc_html__( 'Set default location and behaviour for the [cloud_cover] shortcode.', 'cloud-cover-forecast' ) . '</p>';
	}

	/**
	 * Register Gutenberg block
	 *
	 * @since 1.0.0
	 */
	public function register_gutenberg_block() {
		// Check if Gutenberg is available
		if ( ! function_exists( 'register_block_type' ) ) {
			return;
		}

		// Register the block editor script
		wp_register_script(
			'cloud-cover-forecast-block',
			plugin_dir_url( dirname( __FILE__ ) ) . 'block.js',
			array( 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-editor', 'wp-components', 'wp-i18n' ),
			'1.0.0',
			true
		);

		// Register the block
		register_block_type( 'cloud-cover-forecast/block', array(
			'editor_script' => 'cloud-cover-forecast-block',
			'render_callback' => array( $this, 'render_gutenberg_block' ),
			'attributes' => array(
				'latitude' => array(
					'type' => 'string',
					'default' => $this->plugin->get_settings()['lat'],
				),
				'longitude' => array(
					'type' => 'string',
					'default' => $this->plugin->get_settings()['lon'],
				),
				'hours' => array(
					'type' => 'number',
					'default' => $this->plugin->get_settings()['hours'],
				),
				'label' => array(
					'type' => 'string',
					'default' => '',
				),
				'location' => array(
					'type' => 'string',
					'default' => '',
				),
			),
		) );
	}

	/**
	 * Render the Gutenberg block
	 *
	 * @since 1.0.0
	 * @param array $attributes Block attributes.
	 * @return string Block output.
	 */
	public function render_gutenberg_block( $attributes ) {
		// Get the shortcode instance and delegate to it
		$shortcode = $this->plugin->get_shortcode();
		return $shortcode->render_gutenberg_block( $attributes );
	}

	/**
	 * Render latitude field
	 *
	 * @since 1.0.0
	 */
	public function render_lat_field() {
		$opts = $this->plugin->get_settings();
		printf(
			'<input type="text" name="%1$s[lat]" value="%2$s" class="regular-text" />',
			esc_attr( $this->plugin::OPTION_KEY ),
			esc_attr( $opts['lat'] )
		);
	}

	/**
	 * Render longitude field
	 *
	 * @since 1.0.0
	 */
	public function render_lon_field() {
		$opts = $this->plugin->get_settings();
		printf(
			'<input type="text" name="%1$s[lon]" value="%2$s" class="regular-text" />',
			esc_attr( $this->plugin::OPTION_KEY ),
			esc_attr( $opts['lon'] )
		);
	}

	/**
	 * Render hours field
	 *
	 * @since 1.0.0
	 */
	public function render_hours_field() {
		$opts = $this->plugin->get_settings();
		printf(
			'<input type="number" name="%1$s[hours]" value="%2$s" min="1" step="1" class="regular-text" />',
			esc_attr( $this->plugin::OPTION_KEY ),
			esc_attr( $opts['hours'] )
		);
	}

	/**
	 * Render cache TTL field
	 *
	 * @since 1.0.0
	 */
	public function render_cache_ttl_field() {
		$opts = $this->plugin->get_settings();
		printf(
			'<input type="number" name="%1$s[cache_ttl]" value="%2$s" min="1" step="1" class="regular-text" />',
			esc_attr( $this->plugin::OPTION_KEY ),
			esc_attr( $opts['cache_ttl'] )
		);
	}

	/**
	 * Render location search field
	 *
	 * @since 1.0.0
	 */
	public function render_location_search_field() {
		$ajax_data = array(
			'ajaxUrl' => esc_url_raw( admin_url( 'admin-ajax.php' ) ),
			'nonce'   => wp_create_nonce( 'ccf_admin_nonce' ),
		);
		?>
		<div id="cloud-cover-forecast-location-search">
			<input type="text" id="clf-location-input" placeholder="<?php esc_attr_e( 'Enter location name (e.g., London, UK)', 'cloud-cover-forecast' ); ?>" class="regular-text" />
			<button type="button" id="clf-search-btn" class="button"><?php esc_html_e( 'Search Location', 'cloud-cover-forecast' ); ?></button>
			<div id="clf-search-results" style="margin-top: 10px;"></div>
		</div>
		<p class="description">
			<?php esc_html_e( 'Search for a location to automatically fill in the latitude and longitude fields above.', 'cloud-cover-forecast' ); ?>
		</p>
		<script type="text/javascript">
		(function($) {
			var ccfAdminAjax = <?php echo wp_json_encode( $ajax_data ); ?>;

			function clfEscapeHtml(value) {
				return $('<div/>').text(value || '').html();
			}

			$(document).ready(function() {
				$('#clf-search-btn').on('click', function() {
					var location = $('#clf-location-input').val();
					if (!location) {
						alert('<?php echo esc_js( __( 'Please enter a location name', 'cloud-cover-forecast' ) ); ?>');
						return;
					}

					var $resultsEl = $('#clf-search-results');
					$resultsEl.html('<em><?php echo esc_js( __( 'Searching...', 'cloud-cover-forecast' ) ); ?></em>');

					var ajaxUrl = (ccfAdminAjax && ccfAdminAjax.ajaxUrl) ? ccfAdminAjax.ajaxUrl : (typeof window.ajaxurl !== 'undefined' ? window.ajaxurl : '');
					if (!ajaxUrl) {
						$resultsEl.html('<div style="padding: 8px; background: #f8d7da; border: 1px solid #f5c6cb; border-radius: 4px;"><?php echo esc_js( __( 'Search failed. Please try again.', 'cloud-cover-forecast' ) ); ?></div>');
						return;
					}

					$.ajax({
						url: ajaxUrl,
						type: 'POST',
						dataType: 'json',
						data: {
							action: 'ccf_geocode',
							nonce: ccfAdminAjax.nonce,
							location: location
						}
					})
					.done(function(response) {
						if (!response || !response.success) {
							var message = (response && response.data && response.data.message) ? response.data.message : '<?php echo esc_js( __( 'Search failed. Please try again.', 'cloud-cover-forecast' ) ); ?>';
							$resultsEl.removeData('results').html('<div style="padding: 8px; background: #f8d7da; border: 1px solid #f5c6cb; border-radius: 4px;">' + clfEscapeHtml(message) + '</div>');
							return;
						}

						var results = (response.data && Array.isArray(response.data.results)) ? response.data.results : [];

						if (!results.length) {
							$resultsEl.removeData('results').html('<div style="padding: 8px; background: #f8d7da; border: 1px solid #f5c6cb; border-radius: 4px;"><?php echo esc_js( __( 'Location not found. Please try a different search term.', 'cloud-cover-forecast' ) ); ?></div>');
							return;
						}

						var htmlParts = [];

						if (results.length === 1) {
							var result = results[0];
							var nameParts = [];
							if (result.name) {
								nameParts.push(result.name);
							}
							if (result.country) {
								nameParts.push(result.country);
							}
							var displayName = nameParts.join(', ');

							var latValue = (result.latitude !== null && result.latitude !== undefined) ? result.latitude : '';
							var lonValue = (result.longitude !== null && result.longitude !== undefined) ? result.longitude : '';

							$('input[name="<?php echo esc_js( $this->plugin::OPTION_KEY ); ?>[lat]"]').val(latValue);
							$('input[name="<?php echo esc_js( $this->plugin::OPTION_KEY ); ?>[lon]"]').val(lonValue);

							htmlParts.push(
								'<div style="padding: 8px; background: #d4edda; border: 1px solid #c3e6cb; border-radius: 4px;">' +
								'<strong><?php echo esc_js( __( 'Found:', 'cloud-cover-forecast' ) ); ?></strong> ' + clfEscapeHtml(displayName) +
								' (' + latValue + ', ' + lonValue + ')' +
								'</div>'
							);

							$resultsEl.removeData('results');
						} else {
							htmlParts.push(
								'<div style="padding: 8px; background: #e7f3ff; border: 1px solid #b3d9ff; border-radius: 4px;">' +
								'<strong><?php echo esc_js( __( 'Multiple locations found. Please select one:', 'cloud-cover-forecast' ) ); ?></strong><br><br>'
							);

							results.forEach(function(result, index) {
								var locationParts = [];
								if (result.name) {
									locationParts.push(result.name);
								}
								if (result.admin1) {
									locationParts.push(result.admin1);
								}
								if (result.country) {
									locationParts.push(result.country);
								}
								var displayName = locationParts.join(', ');

								var latText = (result.latitude !== null && result.latitude !== undefined) ? result.latitude : '';
								var lonText = (result.longitude !== null && result.longitude !== undefined) ? result.longitude : '';

								htmlParts.push(
									'<label style="display: block; margin: 5px 0; cursor: pointer;">' +
									'<input type="radio" name="clf-location-choice" value="' + index + '" style="margin-right: 8px;">' +
									clfEscapeHtml(displayName) + ' <span style="color: #666;">(' + latText + ', ' + lonText + ')</span>' +
									'</label>'
								);
							});

							htmlParts.push(
								'<br><button type="button" id="clf-select-location" class="button button-primary" style="margin-top: 8px;">' +
								'<?php echo esc_js( __( 'Use Selected Location', 'cloud-cover-forecast' ) ); ?>' +
								'</button></div>'
							);

							$resultsEl.data('results', results);
						}

						$resultsEl.html(htmlParts.join(''));
					})
					.fail(function(jqXHR) {
						var message = '<?php echo esc_js( __( 'Search failed. Please try again.', 'cloud-cover-forecast' ) ); ?>';
						if (jqXHR && jqXHR.responseJSON && jqXHR.responseJSON.data && jqXHR.responseJSON.data.message) {
							message = jqXHR.responseJSON.data.message;
						}
						$resultsEl.removeData('results').html(
							'<div style="padding: 8px; background: #f8d7da; border: 1px solid #f5c6cb; border-radius: 4px;">' +
							clfEscapeHtml(message) +
							'</div>'
						);
					});
				});

				$(document).on('click', '#clf-select-location', function() {
					var selectedIndex = $('input[name="clf-location-choice"]:checked').val();
					if (selectedIndex !== undefined) {
						var idx = parseInt(selectedIndex, 10);
						var results = $('#clf-search-results').data('results');
						if (!Array.isArray(results) || !results[idx]) {
							alert('<?php echo esc_js( __( 'Please select a location from the list', 'cloud-cover-forecast' ) ); ?>');
							return;
						}

						var selected = results[idx];
						var latValue = (selected.latitude !== null && selected.latitude !== undefined) ? selected.latitude : '';
						var lonValue = (selected.longitude !== null && selected.longitude !== undefined) ? selected.longitude : '';

						$('input[name="<?php echo esc_js( $this->plugin::OPTION_KEY ); ?>[lat]"]').val(latValue);
						$('input[name="<?php echo esc_js( $this->plugin::OPTION_KEY ); ?>[lon]"]').val(lonValue);

						var locationParts = [];
						if (selected.name) {
							locationParts.push(selected.name);
						}
						if (selected.admin1) {
							locationParts.push(selected.admin1);
						}
						if (selected.country) {
							locationParts.push(selected.country);
						}
						var displayName = locationParts.join(', ');

						$('#clf-search-results')
							.removeData('results')
							.html(
								'<div style="padding: 8px; background: #d4edda; border: 1px solid #c3e6cb; border-radius: 4px;">' +
								'<strong><?php echo esc_js( __( 'Selected:', 'cloud-cover-forecast' ) ); ?></strong> ' + clfEscapeHtml(displayName) +
								' (' + latValue + ', ' + lonValue + ')' +
								'</div>'
							);
					} else {
						alert('<?php echo esc_js( __( 'Please select a location from the list', 'cloud-cover-forecast' ) ); ?>');
					}
				});

				$('#clf-location-input').on('keypress', function(e) {
					if (e.which === 13) {
						$('#clf-search-btn').click();
					}
				});
			});
		})(jQuery);
		</script>
		<?php
	}

	/**
	 * Render astronomy API key field
	 *
	 * @since 1.0.0
	 */
	public function render_astro_api_key_field() {
		$opts = $this->plugin->get_settings();
		printf(
			'<input type="text" name="%1$s[astro_api_key]" value="%2$s" class="regular-text" />',
			esc_attr( $this->plugin::OPTION_KEY ),
			esc_attr( $opts['astro_api_key'] )
		);
		echo '<p class="description">' .
			sprintf(
				/* translators: %s is the IPGeolocation.io signup URL */
				esc_html__( 'Optional: Get a free API key from %1$s for moon phases and rise/set times (1000 requests/day free). Leave empty to use basic sun-only analysis.', 'cloud-cover-forecast' ),
				'<a href="https://ipgeolocation.io/" target="_blank" rel="noopener">IPGeolocation.io</a>'
			) .
		'</p>';
	}

	/**
	 * Render clear cache field
	 *
	 * @since 1.0.0
	 */
	public function render_clear_cache_field() {
		// Check user capabilities
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// Handle cache clearing if requested
		if ( ! empty( $_POST['clear_cloud_cover_cache'] ) && check_admin_referer( 'cloud_cover_forecast_settings_group-options' ) ) {
			$this->clear_all_cache();
			echo '<div class="notice notice-success"><p>' . esc_html__( 'All cache cleared successfully!', 'cloud-cover-forecast' ) . '</p></div>';
		}

		echo '<button type="submit" name="clear_cloud_cover_cache" value="1" class="button button-secondary">' .
			esc_html__( 'Clear All Cache', 'cloud-cover-forecast' ) . '</button>';
		echo '<p class="description">' .
			esc_html__( 'Clear weather forecasts, geocoding results, and moon data cache. Use this if you see incorrect coordinates or outdated data.', 'cloud-cover-forecast' ) .
		'</p>';
	}

	/**
	 * Clear all plugin cache
	 *
	 * @since 1.0.0
	 */
	private function clear_all_cache() {
		// Check user capabilities
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$this->plugin->clear_cache();
	}

	/**
	 * Handle admin geocoding AJAX requests
	 *
	 * @since 1.0.0
	 */
	public function ajax_geocode_location() {
		check_ajax_referer( 'ccf_admin_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error(
				array( 'message' => __( 'You do not have permission to perform this action.', 'cloud-cover-forecast' ) ),
				403
			);
		}

		$location = isset( $_POST['location'] ) ? sanitize_text_field( wp_unslash( $_POST['location'] ) ) : '';
		if ( '' === $location ) {
			wp_send_json_error(
				array( 'message' => __( 'Please provide a location to search for.', 'cloud-cover-forecast' ) ),
				400
			);
		}

		$api = $this->plugin->get_api();
		$results = $api->get_normalized_geocode_results( $location );
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
					$status = 503;
					break;
			}

			wp_send_json_error(
				array( 'message' => $results->get_error_message() ),
				$status
			);
		}

		wp_send_json_success(
			array(
				'results' => $results,
			)
		);
	}

	/**
	 * Sanitize plugin settings
	 *
	 * @since 1.0.0
	 * @param array $input Raw input data.
	 * @return array Sanitized settings.
	 */
	public function sanitize_settings( $input ) {
		// Check user capabilities
		if ( ! current_user_can( 'manage_options' ) ) {
			return $this->plugin::get_defaults();
		}

		$out = $this->plugin::get_defaults();

		// Validate and sanitize latitude
		if ( isset( $input['lat'] ) ) {
			$lat = floatval( $input['lat'] );
			if ( $lat >= -90 && $lat <= 90 ) {
				$out['lat'] = $lat;
			}
		}

		// Validate and sanitize longitude
		if ( isset( $input['lon'] ) ) {
			$lon = floatval( $input['lon'] );
			if ( $lon >= -180 && $lon <= 180 ) {
				$out['lon'] = $lon;
			}
		}

		// Validate and sanitize hours
		if ( isset( $input['hours'] ) ) {
			$hours = intval( $input['hours'] );
			if ( $hours >= 1 && $hours <= 168 ) {
				$out['hours'] = $hours;
			}
		}

		// Validate and sanitize cache TTL
		if ( isset( $input['cache_ttl'] ) ) {
			$cache_ttl = intval( $input['cache_ttl'] );
			if ( $cache_ttl >= 1 && $cache_ttl <= 1440 ) { // Max 24 hours
				$out['cache_ttl'] = $cache_ttl;
			}
		}

		$out['show_chart']      = ! empty( $input['show_chart'] ) ? 1 : 0;
		$out['astro_api_key']   = sanitize_text_field( $input['astro_api_key'] ?? $out['astro_api_key'] );
		$out['provider']        = 'open-meteo';

		return $out;
	}

	/**
	 * Render the settings page
	 *
	 * @since 1.0.0
	 */
	public function render_settings_page() {
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'Cloud Cover Forecast', 'cloud-cover-forecast' ); ?></h1>
			<form method="post" action="options.php">
				<?php
				settings_fields( 'cloud_cover_forecast_settings_group' );
				do_settings_sections( 'cloud-cover-forecast-settings' );
				submit_button();
				?>
			</form>
			<p class="description">
				<?php
				printf(
					/* translators: %1$s is the Open-Meteo website URL */
					esc_html__( 'Data source: %1$s hourly variables: cloudcover, cloudcover_low, cloudcover_mid, cloudcover_high. No API key required.', 'cloud-cover-forecast' ),
					'<a href="https://open-meteo.com/" target="_blank" rel="noopener">Open‑Meteo</a>'
				);
				?>
			</p>
		</div>
		<?php
	}
}
