<?php
/**
 * Shared Location Search Form for Cloud Cover Forecast Plugin
 *
 * @package CloudCoverForecast
 * @since 1.0.0
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Location Search Form class
 *
 * @since 1.0.0
 */
class Cloud_Cover_Forecast_Location_Search_Form {

	/**
	 * Render location search form
	 *
	 * @since 1.0.0
	 * @param array $args Form configuration arguments.
	 * @return string Form HTML.
	 */
	public static function render( $args = array() ) {
		$defaults = array(
			'title'       => __( 'Find Your Location', 'cloud-cover-forecast' ),
			'description' => __( 'Enter a location to view the cloud cover forecast.', 'cloud-cover-forecast' ),
			'mode'        => 'redirect', // 'redirect' or 'ajax'
			'url_params'  => array(
				'lat'      => 'sunrise_lat',
				'lon'      => 'sunrise_lon',
				'location' => 'sunrise_location',
			),
		);

		$args = wp_parse_args( $args, $defaults );
		$form_id = 'location-search-' . wp_generate_uuid4();

		ob_start();
		self::output_css();
		?>
		<div class="ccf-location-search-form" id="<?php echo esc_attr( $form_id ); ?>" data-mode="<?php echo esc_attr( $args['mode'] ); ?>">
			<h3><?php echo esc_html( $args['title'] ); ?></h3>
			<p class="form-description">
				<?php echo esc_html( $args['description'] ); ?>
			</p>

			<div class="search-input-wrapper">
				<input
					type="text"
					class="location-input"
					placeholder="<?php esc_attr_e( 'Enter location (e.g., London, UK)', 'cloud-cover-forecast' ); ?>"
					aria-label="<?php esc_attr_e( 'Location search', 'cloud-cover-forecast' ); ?>"
				/>
				<button type="button" class="search-button">
					<?php esc_html_e( 'Search', 'cloud-cover-forecast' ); ?>
				</button>
			</div>

			<div class="search-results" style="display: none;"></div>
			<div class="search-error" style="display: none;"></div>
			<div class="search-loading" style="display: none;">
				<?php esc_html_e( 'Searching...', 'cloud-cover-forecast' ); ?>
			</div>
		</div>

		<script>
		(function() {
			var formId = <?php echo wp_json_encode( $form_id ); ?>;
			var form = document.getElementById(formId);
			if (!form) return;

			var mode = form.getAttribute('data-mode');
			var urlParams = <?php echo wp_json_encode( $args['url_params'] ); ?>;

			var input = form.querySelector('.location-input');
			var button = form.querySelector('.search-button');
			var resultsDiv = form.querySelector('.search-results');
			var errorDiv = form.querySelector('.search-error');
			var loadingDiv = form.querySelector('.search-loading');

			function showLoading() {
				resultsDiv.style.display = 'none';
				errorDiv.style.display = 'none';
				loadingDiv.style.display = 'block';
				button.disabled = true;
			}

			function hideLoading() {
				loadingDiv.style.display = 'none';
				button.disabled = false;
			}

			function showError(message) {
				hideLoading();
				errorDiv.className = 'ccf-search-error';
				errorDiv.textContent = message;
				errorDiv.style.display = 'block';
				resultsDiv.style.display = 'none';
			}

			function showResults(results) {
				hideLoading();
				errorDiv.style.display = 'none';
				resultsDiv.className = 'ccf-search-results';
				resultsDiv.innerHTML = '';

				if (results.length === 1) {
					// Auto-select single result
					handleLocationSelect(results[0]);
					return;
				}

				var heading = document.createElement('p');
				heading.style.marginTop = '0';
				heading.style.fontWeight = '600';
				heading.textContent = <?php echo wp_json_encode( __( 'Multiple locations found. Please select one:', 'cloud-cover-forecast' ) ); ?>;
				resultsDiv.appendChild(heading);

				results.forEach(function(result) {
					var item = document.createElement('div');
					item.className = 'result-item';

					var nameParts = [result.name];
					if (result.admin1) nameParts.push(result.admin1);
					if (result.country) nameParts.push(result.country);

					var nameDiv = document.createElement('div');
					nameDiv.className = 'result-name';
					nameDiv.textContent = nameParts.join(', ');

					var coordsDiv = document.createElement('div');
					coordsDiv.className = 'result-coords';
					coordsDiv.textContent = result.latitude.toFixed(4) + ', ' + result.longitude.toFixed(4);

					item.appendChild(nameDiv);
					item.appendChild(coordsDiv);

					item.addEventListener('click', function() {
						handleLocationSelect(result);
					});

					resultsDiv.appendChild(item);
				});

				resultsDiv.style.display = 'block';
			}

			function handleLocationSelect(result) {
				var nameParts = [result.name];
				if (result.admin1) nameParts.push(result.admin1);
				if (result.country) nameParts.push(result.country);
				var locationName = nameParts.join(', ');

				if (mode === 'redirect') {
					// Redirect mode: add query params and reload
					var url = new URL(window.location.href);
					url.searchParams.set(urlParams.lat, result.latitude);
					url.searchParams.set(urlParams.lon, result.longitude);
					url.searchParams.set(urlParams.location, locationName);
					window.location.href = url.toString();
				} else if (mode === 'ajax') {
					// AJAX mode: trigger custom event for parent to handle
					var event = new CustomEvent('ccfLocationSelected', {
						detail: {
							latitude: result.latitude,
							longitude: result.longitude,
							location: locationName
						}
					});
					form.dispatchEvent(event);
				}
			}

			function searchLocation() {
				var query = input.value.trim();
				if (!query) {
					showError(<?php echo wp_json_encode( __( 'Please enter a location.', 'cloud-cover-forecast' ) ); ?>);
					return;
				}

				showLoading();

				fetch('https://geocoding-api.open-meteo.com/v1/search?name=' + encodeURIComponent(query) + '&count=5&format=json')
					.then(function(response) {
						return response.json();
					})
					.then(function(data) {
						if (data.results && data.results.length > 0) {
							var results = data.results.map(function(r) {
								return {
									name: r.name,
									admin1: r.admin1 || '',
									country: r.country || '',
									latitude: r.latitude,
									longitude: r.longitude
								};
							});
							showResults(results);
						} else {
							showError(<?php echo wp_json_encode( __( 'Location not found. Please try a different search term.', 'cloud-cover-forecast' ) ); ?>);
						}
					})
					.catch(function(error) {
						showError(<?php echo wp_json_encode( __( 'Error searching for location. Please try again.', 'cloud-cover-forecast' ) ); ?>);
					});
			}

			button.addEventListener('click', searchLocation);
			input.addEventListener('keypress', function(e) {
				if (e.key === 'Enter') {
					e.preventDefault();
					searchLocation();
				}
			});
		})();
		</script>
		<?php
		return ob_get_clean();
	}

	/**
	 * Output shared CSS for location search form
	 *
	 * @since 1.0.0
	 */
	private static function output_css() {
		static $css_output = false;

		if ( $css_output ) {
			return;
		}

		$css_output = true;
		?>
		<style>
		.ccf-location-search-form {
			max-width: 600px;
			margin: 2rem auto;
			padding: 2rem;
			background: #fff;
			border: 1px solid #e0e0e0;
			border-radius: 8px;
			box-shadow: 0 2px 4px rgba(0,0,0,0.1);
			font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
		}

		.ccf-location-search-form h3 {
			margin: 0 0 1.5rem 0;
			font-size: 1.5rem;
			color: #333;
			text-align: center;
		}

		.ccf-location-search-form .form-description {
			text-align: center;
			color: #666;
			margin-bottom: 1.5rem;
		}

		.ccf-location-search-form .search-input-wrapper {
			display: flex;
			gap: 0.5rem;
			margin-bottom: 1rem;
		}

		.ccf-location-search-form input[type="text"] {
			flex: 1;
			padding: 0.75rem;
			border: 2px solid #ddd;
			border-radius: 4px;
			font-size: 1rem;
		}

		.ccf-location-search-form input[type="text"]:focus {
			outline: none;
			border-color: #667eea;
		}

		.ccf-location-search-form button {
			padding: 0.75rem 1.5rem;
			background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
			color: #fff;
			border: none;
			border-radius: 4px;
			font-size: 1rem;
			font-weight: 600;
			cursor: pointer;
			transition: opacity 0.2s;
		}

		.ccf-location-search-form button:hover {
			opacity: 0.9;
		}

		.ccf-location-search-form button:disabled {
			opacity: 0.5;
			cursor: not-allowed;
		}

		.ccf-search-results {
			margin-top: 1rem;
			padding: 1rem;
			background: #f9f9f9;
			border: 1px solid #e0e0e0;
			border-radius: 4px;
		}

		.ccf-search-results .result-item {
			padding: 0.75rem;
			margin-bottom: 0.5rem;
			background: #fff;
			border: 1px solid #ddd;
			border-radius: 4px;
			cursor: pointer;
			transition: background 0.2s;
		}

		.ccf-search-results .result-item:hover {
			background: #f0f4ff;
			border-color: #667eea;
		}

		.ccf-search-results .result-item:last-child {
			margin-bottom: 0;
		}

		.ccf-search-results .result-name {
			font-weight: 600;
			color: #333;
			margin-bottom: 0.25rem;
		}

		.ccf-search-results .result-coords {
			font-size: 0.85rem;
			color: #666;
		}

		.ccf-search-error {
			margin-top: 1rem;
			padding: 1rem;
			background: #ffebee;
			border: 1px solid #ffcdd2;
			border-radius: 4px;
			color: #d32f2f;
		}

		.ccf-location-search-form .search-loading {
			text-align: center;
			padding: 1rem;
			color: #666;
		}

		@media (max-width: 640px) {
			.ccf-location-search-form {
				padding: 1.5rem;
			}

			.ccf-location-search-form h3 {
				font-size: 1.2rem;
			}

			.ccf-location-search-form .search-input-wrapper {
				flex-direction: column;
			}

			.ccf-location-search-form button {
				width: 100%;
			}
		}
		</style>
		<?php
	}
}
