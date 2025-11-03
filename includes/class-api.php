<?php
/**
 * API management for Cloud Cover Forecast Plugin
 *
 * @package CloudCoverForecast
 * @since 1.0.0
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * API class for Cloud Cover Forecast Plugin
 *
 * @since 1.0.0
 */
class Cloud_Cover_Forecast_API {

	/**
	 * Plugin instance
	 *
	 * @since 1.0.0
	 * @var Cloud_Cover_Forecast_Plugin
	 */
	private $plugin;

	/**
	 * Per-service rate limit configuration (max requests within window seconds).
	 *
	 * @var array<string,array<string,int>>
	 */
	private const SERVICE_RATE_LIMITS = array(
		'open_meteo_forecast'      => array( 'window' => HOUR_IN_SECONDS, 'max_requests' => 45 ),
		'open_meteo_geocoding'     => array( 'window' => HOUR_IN_SECONDS, 'max_requests' => 20 ),
		'met_no_forecast'          => array( 'window' => HOUR_IN_SECONDS, 'max_requests' => 15 ),
		'ipgeolocation_astronomy'  => array( 'window' => HOUR_IN_SECONDS, 'max_requests' => 60 ),
	);

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
	 * Validate coordinates
	 *
	 * @since 1.0.0
	 * @param float $lat Latitude.
	 * @param float $lon Longitude.
	 * @return bool True if coordinates are valid, false otherwise.
	 */
	private function validate_coordinates( $lat, $lon ) {
		$lat = filter_var( $lat, FILTER_VALIDATE_FLOAT );
		$lon = filter_var( $lon, FILTER_VALIDATE_FLOAT );
		return ( $lat !== false && $lon !== false &&
				$lat >= -90 && $lat <= 90 &&
				$lon >= -180 && $lon <= 180 );
	}

	/**
	 * Fetch weather data from Open-Meteo API
	 *
	 * @since 1.0.0
	 * @param float $lat Latitude.
	 * @param float $lon Longitude.
	 * @param int   $hours Number of hours to fetch.
	 * @return array|WP_Error Weather data or error.
	 */
	public function fetch_weather_data( float $lat, float $lon, int $hours ) {
		// Validate coordinates
		if ( ! $this->validate_coordinates( $lat, $lon ) ) {
			return new WP_Error( 'cloud_cover_forecast_invalid_coordinates', __( 'Invalid coordinates. Must be between -90 and 90 for latitude, and -180 and 180 for longitude.', 'cloud-cover-forecast' ) );
		}

		$params = array(
			'latitude'  => $lat,
			'longitude' => $lon,
			'hourly'    => 'cloudcover,cloudcover_low,cloudcover_mid,cloudcover_high',
			'daily'     => 'sunrise,sunset',
			'timezone'  => 'auto',
		);
		$hours = max( 1, min( 168, $hours ) );
		$url = add_query_arg( $params, 'https://api.open-meteo.com/v1/forecast' );

		$cache_key = $this->plugin::TRANSIENT_PREFIX . 'open_meteo_' . md5( $url );
		$res       = get_transient( $cache_key );

		if ( false === $res ) {
			$rate_check = $this->can_make_request( 'open_meteo_forecast' );
			if ( is_wp_error( $rate_check ) ) {
				return $rate_check;
			}

			$res = wp_remote_get(
				$url,
				array(
					'timeout'    => 12,
					'user-agent' => 'Cloud Cover Forecast Plugin/' . CLOUD_COVER_FORECAST_VERSION,
					'sslverify'  => true,
				)
			);
			$this->increment_rate_counter( 'open_meteo_forecast' );

			if ( is_wp_error( $res ) ) {
				return new WP_Error( 'cloud_cover_forecast_network', __( 'Network error occurred while fetching weather data.', 'cloud-cover-forecast' ) );
			}

			$code = wp_remote_retrieve_response_code( $res );
			if ( 200 !== $code ) {
				return new WP_Error( 'cloud_cover_forecast_http', __( 'Weather service temporarily unavailable. Please try again later.', 'cloud-cover-forecast' ) );
			}

			$cache_ttl_minutes = $this->plugin->get_settings()['cache_ttl'] ?? 15;
			$cache_ttl_seconds = max( 1, intval( $cache_ttl_minutes ) ) * MINUTE_IN_SECONDS;
			set_transient( $cache_key, $res, $cache_ttl_seconds );
			$this->plugin->register_transient_key( $cache_key );
		}

		$body = wp_remote_retrieve_body( $res );
		$json = json_decode( $body, true );
		if ( ! $json || empty( $json['hourly']['time'] ) ) {
			return new WP_Error( 'cloud_cover_forecast_json', __( 'Malformed API response', 'cloud-cover-forecast' ) );
		}

		$times = $json['hourly']['time'];
		$tcc   = $json['hourly']['cloudcover'] ?? array();
		$lcc   = $json['hourly']['cloudcover_low'] ?? array();
		$mcc   = $json['hourly']['cloudcover_mid'] ?? array();
		$hcc   = $json['hourly']['cloudcover_high'] ?? array();

		// Extract daily sun data
		$daily_times = $json['daily']['time'] ?? array();
		$daily_sunrise = $json['daily']['sunrise'] ?? array();
		$daily_sunset = $json['daily']['sunset'] ?? array();

		// Extract timezone information
		$timezone = $json['timezone'] ?? 'UTC';
		$timezone_abbr = $json['timezone_abbreviation'] ?? 'UTC';

		$rows = array();
		$location_timezone = new DateTimeZone( $timezone );

		// Get current time in the location's timezone for proper comparison
		$now = time();

		// Calculate today's start in the location's timezone
		$location_now = new DateTime( 'now', $location_timezone );
		$today_start = ( clone $location_now )->setTime( 0, 0, 0 )->getTimestamp();

		// Determine relevant sunset/sunrise window for photography display
		$last_sunset = null;
		$next_sunset = null;
		foreach ( $daily_sunset as $sunset_time ) {
			$sunset_ts = $this->to_timestamp_in_timezone( $sunset_time, $location_timezone );
			if ( null === $sunset_ts ) {
				continue;
			}

			if ( $sunset_ts <= $now ) {
				$last_sunset = array(
					'time' => $sunset_time,
					'ts'   => $sunset_ts,
				);
				continue;
			}

			if ( null === $next_sunset ) {
				$next_sunset = array(
					'time' => $sunset_time,
					'ts'   => $sunset_ts,
				);
			}
		}

		$sunrise_after_last_sunset = null;
		if ( $last_sunset ) {
			foreach ( $daily_sunrise as $sunrise_time ) {
				$sunrise_ts = $this->to_timestamp_in_timezone( $sunrise_time, $location_timezone );
				if ( null === $sunrise_ts ) {
					continue;
				}

				if ( $sunrise_ts > $last_sunset['ts'] ) {
					$sunrise_after_last_sunset = array(
						'time' => $sunrise_time,
						'ts'   => $sunrise_ts,
					);
					break;
				}
			}
		}

		$sunrise_after_next_sunset = null;
		if ( $next_sunset ) {
			foreach ( $daily_sunrise as $sunrise_time ) {
				$sunrise_ts = $this->to_timestamp_in_timezone( $sunrise_time, $location_timezone );
				if ( null === $sunrise_ts ) {
					continue;
				}

				if ( $sunrise_ts > $next_sunset['ts'] ) {
					$sunrise_after_next_sunset = array(
						'time' => $sunrise_time,
						'ts'   => $sunrise_ts,
					);
					break;
				}
			}
		}

		$selected_sunset = null;
		$selected_sunrise = null;

		if ( $last_sunset && $sunrise_after_last_sunset && $now >= $last_sunset['ts'] && $now <= $sunrise_after_last_sunset['ts'] ) {
			$selected_sunset = $last_sunset;
			$selected_sunrise = $sunrise_after_last_sunset;
		} elseif ( $next_sunset ) {
			$selected_sunset = $next_sunset;
			if ( $sunrise_after_next_sunset ) {
				$selected_sunrise = $sunrise_after_next_sunset;
			} elseif ( $sunrise_after_last_sunset && $sunrise_after_last_sunset['ts'] > $next_sunset['ts'] ) {
				$selected_sunrise = $sunrise_after_last_sunset;
			}
		} elseif ( $sunrise_after_last_sunset ) {
			$selected_sunset = $last_sunset;
			$selected_sunrise = $sunrise_after_last_sunset;
		}

		$hours_limit = $hours;
		if ( $selected_sunrise && $selected_sunrise['ts'] > $today_start ) {
			$desired_end_ts = $selected_sunrise['ts'] + HOUR_IN_SECONDS;
			$hours_until_end = (int) ceil( ( $desired_end_ts - $today_start ) / HOUR_IN_SECONDS );
			if ( $hours_until_end > $hours_limit ) {
				$hours_limit = $hours_until_end;
			}
		}

		for ( $i = 0; $i < count( $times ); $i++ ) {
			$ts = $this->to_timestamp_in_timezone( $times[ $i ], $location_timezone );
			if ( null === $ts ) {
				continue;
			}


		// Include hours from today (after midnight) and all future hours
		// Use the location's timezone for proper filtering
		if ( $ts >= $today_start ) {
				$rows[] = array(
					'time'  => $times[ $i ],
					'ts'    => $ts,
					'total' => isset( $tcc[ $i ] ) ? intval( $tcc[ $i ] ) : null,
					'low'   => isset( $lcc[ $i ] ) ? intval( $lcc[ $i ] ) : null,
					'mid'   => isset( $mcc[ $i ] ) ? intval( $mcc[ $i ] ) : null,
					'high'  => isset( $hcc[ $i ] ) ? intval( $hcc[ $i ] ) : null,
				);
			}

			// Don't break early - collect all relevant hours first, then sort and limit
		}

		// Sort rows by timestamp to ensure chronological order (past hours first, then future)
		usort( $rows, function( $a, $b ) {
			return $a['ts'] <=> $b['ts'];
		});
		// Limit to requested number of hours (ensure we cover until selected sunrise)
		$rows = array_slice( $rows, 0, $hours_limit );

		$metno_merge_summary = array();
		$metno_source = array();
		$metno_threshold = 20;
		$metno_data = $this->fetch_met_no_complete( $lat, $lon );
		if ( ! is_wp_error( $metno_data ) && ! empty( $metno_data['hourly'] ) ) {
			$merge_result = $this->merge_cloud_cover_rows( $rows, $metno_data['hourly'], $metno_threshold );
			$rows = $merge_result['rows'];
			$metno_merge_summary = $merge_result['summary'];
			$metno_source = array(
				'url'        => $metno_data['source_url'],
				'updated_at' => $metno_data['updated_at'] ?? null,
			);
		} elseif ( is_wp_error( $metno_data ) ) {
			$metno_source = array(
				'url'        => $metno_data->get_error_data()['url'] ?? '',
				'error'      => $metno_data->get_error_message(),
			);
		}

		// Fetch moon data if photography mode is enabled
		$today_date = gmdate( 'Y-m-d' );
		$tomorrow_date = gmdate( 'Y-m-d', strtotime( '+1 day' ) );
		$moon_today = $this->fetch_moon_data( $lat, $lon, $today_date );
		$moon_tomorrow = $this->fetch_moon_data( $lat, $lon, $tomorrow_date );

		// Compute quick stats for the visible window
		$stats = array(
			'avg_total'        => $this->avg( array_column( $rows, 'total' ) ),
			'avg_low'          => $this->avg( array_column( $rows, 'low' ) ),
			'avg_mid'          => $this->avg( array_column( $rows, 'mid' ) ),
			'avg_high'         => $this->avg( array_column( $rows, 'high' ) ),
			'first_time'       => $rows ? $rows[0]['time'] : null,
			'last_time'        => $rows ? end( $rows )['time'] : null,
			'lat'              => $lat,
			'lon'              => $lon,
			'timezone'         => $timezone,
			'timezone_abbr'    => $timezone_abbr,
			'source_url'       => $url,
			'sources'          => array_filter( array(
				'open_meteo' => array(
					'url' => $url,
				),
				'met_no'     => $metno_source,
			) ),
			'provider_diff_summary' => array_merge(
				array(
					'rows_with_differences' => $metno_merge_summary['rows_with_differences'] ?? 0,
					'per_level'             => $metno_merge_summary['per_level'] ?? array(),
				),
				array(
					'threshold' => $metno_threshold,
				)
			),
			'daily_times'      => $daily_times,
			'daily_sunrise'    => $daily_sunrise,
			'daily_sunset'     => $daily_sunset,
			'moon_today'       => $moon_today,
			'moon_tomorrow'    => $moon_tomorrow,
			'selected_sunset'  => isset( $selected_sunset['time'] ) ? $selected_sunset['time'] : null,
			'selected_sunrise' => isset( $selected_sunrise['time'] ) ? $selected_sunrise['time'] : null,
			'selected_sunset_ts'  => isset( $selected_sunset['ts'] ) ? $selected_sunset['ts'] : null,
			'selected_sunrise_ts' => isset( $selected_sunrise['ts'] ) ? $selected_sunrise['ts'] : null,
			'used_coords'      => array( 'lat' => $lat, 'lon' => $lon ), // Debug info
		);

		return array( 'rows' => $rows, 'stats' => $stats );
	}

	/**
	 * Geocode a location name to coordinates using Open-Meteo Geocoding API
	 *
	 * @since 1.0.0
	 * @param string $location_name Location name to geocode.
	 * @return array|WP_Error Array with lat, lon, name, country or error.
	 */
	public function geocode_location( string $location_name ) {
		if ( empty( trim( $location_name ) ) ) {
			return new WP_Error( 'cloud_cover_forecast_empty_location', __( 'Location name cannot be empty.', 'cloud-cover-forecast' ) );
		}

		// Check cache first (15 minute cache)
		$cache_key = $this->plugin::GEOCODING_PREFIX . md5( strtolower( trim( $location_name ) ) );
		$cached    = get_transient( $cache_key );
		if ( false !== $cached ) {
			return $cached;
		}

		$params = array(
			'name'   => trim( $location_name ),
			'count'  => 5, // Get multiple results for selection
			'format' => 'json',
		);
		$url = add_query_arg( $params, 'https://geocoding-api.open-meteo.com/v1/search' );

		$rate_check = $this->can_make_request( 'open_meteo_geocoding' );
		if ( is_wp_error( $rate_check ) ) {
			return $rate_check;
		}

		$res = wp_remote_get(
			$url,
			array(
				'timeout'    => 10,
				'user-agent' => 'Cloud Cover Forecast Plugin/' . CLOUD_COVER_FORECAST_VERSION,
				'sslverify'  => true,
			)
		);
		$this->increment_rate_counter( 'open_meteo_geocoding' );
		if ( is_wp_error( $res ) ) {
			return new WP_Error( 'cloud_cover_forecast_geocoding_network', __( 'Network error occurred while searching for location.', 'cloud-cover-forecast' ) );
		}

		$code = wp_remote_retrieve_response_code( $res );
		if ( 200 !== $code ) {
			return new WP_Error( 'cloud_cover_forecast_geocoding_http', __( 'Location service temporarily unavailable. Please try again later.', 'cloud-cover-forecast' ) );
		}

		$body = wp_remote_retrieve_body( $res );
		$json = json_decode( $body, true );
		if ( ! $json || empty( $json['results'] ) ) {
			return new WP_Error( 'cloud_cover_forecast_geocoding_not_found', __( 'Location not found.', 'cloud-cover-forecast' ) );
		}

		// For backward compatibility, if only one result requested, return single result
		if ( 1 === count( $json['results'] ) ) {
			$result = $json['results'][0];
			$geocoded = array(
				'lat'      => $result['latitude'],
				'lon'      => $result['longitude'],
				'name'     => $result['name'],
				'country'  => $result['country'] ?? '',
				'admin1'   => $result['admin1'] ?? '',
				'admin2'   => $result['admin2'] ?? '',
				'timezone' => $result['timezone'] ?? '',
			);

			// Cache result for quicker lookups
			set_transient( $cache_key, $geocoded, 15 * MINUTE_IN_SECONDS );
			$this->plugin->register_transient_key( $cache_key );

			return $geocoded;
		}

		// Return multiple results for selection
		$results = array();
		foreach ( $json['results'] as $result ) {
			$results[] = array(
				'lat'      => $result['latitude'],
				'lon'      => $result['longitude'],
				'name'     => $result['name'],
				'country'  => $result['country'] ?? '',
				'admin1'   => $result['admin1'] ?? '',
				'admin2'   => $result['admin2'] ?? '',
				'timezone' => $result['timezone'] ?? '',
			);
		}

		set_transient( $cache_key, $results, 15 * MINUTE_IN_SECONDS );
		$this->plugin->register_transient_key( $cache_key );

		return $results;
	}

	/**
	 * Fetch moon data from IPGeolocation Astronomy API
	 *
	 * @since 1.0.0
	 * @param float  $lat Latitude.
	 * @param float  $lon Longitude.
	 * @param string $date Date in YYYY-MM-DD format.
	 * @return array|WP_Error Moon data or error.
	 */
	public function fetch_moon_data( float $lat, float $lon, string $date = '' ) {
		$settings = $this->plugin->get_settings();
		$api_key = $settings['astro_api_key'] ?? '';

		$empty_data = array(
			'moon_illumination' => null,
			'moon_phase_name'   => __( 'Unknown', 'cloud-cover-forecast' ),
			'moonrise'          => null,
			'moonset'           => null,
			'moon_azimuth'      => null,
			'moon_altitude'     => null,
		);

		// Return empty data if no API key provided
		if ( empty( $api_key ) ) {
			return $empty_data;
		}

		if ( empty( $date ) ) {
			$date = gmdate( 'Y-m-d' );
		}

		// Check cache first (24 hour cache for moon data)
		$cache_key = $this->plugin::GEOCODING_PREFIX . 'moon_' . md5( $lat . '|' . $lon . '|' . $date );
		$cached = get_transient( $cache_key );
		if ( false !== $cached ) {
			return $cached;
		}

		$rate_check = $this->can_make_request( 'ipgeolocation_astronomy' );
		if ( is_wp_error( $rate_check ) ) {
			return array_merge( $empty_data, array( 'rate_limited' => true ) );
		}

		$params = array(
			'apiKey' => $api_key,
			'lat'    => $lat,
			'long'   => $lon,
			'date'   => $date,
		);
		$url = add_query_arg( $params, 'https://api.ipgeolocation.io/astronomy' );

		$res = wp_remote_get(
			$url,
			array(
				'timeout'    => 10,
				'user-agent' => 'Cloud Cover Forecast Plugin/' . CLOUD_COVER_FORECAST_VERSION,
				'sslverify'  => true,
			)
		);
		$this->increment_rate_counter( 'ipgeolocation_astronomy' );
		if ( is_wp_error( $res ) ) {
			// Return empty data on network failure - graceful degradation
			return $empty_data;
		}

		$code = wp_remote_retrieve_response_code( $res );
		if ( 200 !== $code ) {
			// Return empty data on API failure - graceful degradation
			return $empty_data;
		}

		$body = wp_remote_retrieve_body( $res );
		$json = json_decode( $body, true );
		if ( ! $json ) {
			return $empty_data;
		}

		$moon_data = array(
			'moon_illumination' => isset( $json['moon_illumination'] ) ? intval( $json['moon_illumination'] ) : null,
			'moon_phase_name'   => $json['moon_phase_name'] ?? __( 'Unknown', 'cloud-cover-forecast' ),
			'moonrise'          => $json['moonrise'] ?? null,
			'moonset'           => $json['moonset'] ?? null,
			'moon_azimuth'      => isset( $json['moon_azimuth'] ) ? floatval( $json['moon_azimuth'] ) : null,
			'moon_altitude'     => isset( $json['moon_altitude'] ) ? floatval( $json['moon_altitude'] ) : null,
		);

		// Cache for 24 hours
		set_transient( $cache_key, $moon_data, 24 * HOUR_IN_SECONDS );
		$this->plugin->register_transient_key( $cache_key );

		return $moon_data;
	}

	/**
	 * Fetch Met.no locationforecast (complete) data and normalise hourly cloud values.
	 *
	 * @since 1.0.0
	 * @param float $lat Latitude.
	 * @param float $lon Longitude.
	 * @return array|WP_Error Normalised forecast data or error.
	 */
	private function fetch_met_no_complete( float $lat, float $lon ) {
		$endpoint = 'https://api.met.no/weatherapi/locationforecast/2.0/complete';
		$params   = array(
			'lat' => $lat,
			'lon' => $lon,
		);
		$url = add_query_arg( $params, $endpoint );

		$cache_key = $this->plugin::TRANSIENT_PREFIX . 'metno_' . md5( $url );
		$res       = get_transient( $cache_key );
		if ( false === $res ) {
			$rate_check = $this->can_make_request( 'met_no_forecast' );
			if ( is_wp_error( $rate_check ) ) {
				return $rate_check;
			}

			$res = wp_remote_get(
				$url,
				array(
					'timeout'   => 15,
					'user-agent' => $this->get_met_no_user_agent(),
					'headers'   => array(
						'Accept' => 'application/json',
					),
					'sslverify' => true,
				)
			);
			$this->increment_rate_counter( 'met_no_forecast' );
			if ( is_wp_error( $res ) ) {
				return new WP_Error( 'cloud_cover_forecast_metno_network', __( 'Network error occurred while fetching Met.no data.', 'cloud-cover-forecast' ), array( 'url' => $url ) );
			}
			$code = wp_remote_retrieve_response_code( $res );
			if ( 200 !== $code ) {
				return new WP_Error( 'cloud_cover_forecast_metno_http', __( 'Met.no service temporarily unavailable.', 'cloud-cover-forecast' ), array( 'url' => $url, 'status' => $code ) );
			}

			// Use same cache TTL as Open-Meteo to keep sources synchronized
			$cache_ttl_minutes = $this->plugin->get_settings()['cache_ttl'] ?? 15;
			$cache_ttl_seconds = max( 1, intval( $cache_ttl_minutes ) ) * MINUTE_IN_SECONDS;
			set_transient( $cache_key, $res, $cache_ttl_seconds );
			$this->plugin->register_transient_key( $cache_key );
		}

		$body = wp_remote_retrieve_body( $res );
		$json = json_decode( $body, true );
		if ( ! $json || empty( $json['properties']['timeseries'] ) ) {
			return new WP_Error( 'cloud_cover_forecast_metno_json', __( 'Malformed Met.no API response.', 'cloud-cover-forecast' ), array( 'url' => $url ) );
		}

		$timeseries = $json['properties']['timeseries'];
		$hourly     = array();
		foreach ( $timeseries as $entry ) {
			if ( empty( $entry['time'] ) ) {
				continue;
			}
			$timestamp = strtotime( $entry['time'] );
			if ( false === $timestamp ) {
				continue;
			}
			$details = $entry['data']['instant']['details'] ?? array();
			$key     = gmdate( 'Y-m-d H', $timestamp );
			$hourly[ $key ] = array(
				'ts'    => $timestamp,
				'total' => isset( $details['cloud_area_fraction'] ) ? intval( round( $details['cloud_area_fraction'] ) ) : null,
				'low'   => isset( $details['cloud_area_fraction_low'] ) ? intval( round( $details['cloud_area_fraction_low'] ) ) : null,
				'mid'   => isset( $details['cloud_area_fraction_medium'] ) ? intval( round( $details['cloud_area_fraction_medium'] ) ) : null,
				'high'  => isset( $details['cloud_area_fraction_high'] ) ? intval( round( $details['cloud_area_fraction_high'] ) ) : null,
			);
		}

		return array(
			'hourly'     => $hourly,
			'source_url' => $url,
			'updated_at' => $json['properties']['meta']['updated_at'] ?? null,
		);
	}

	/**
	 * Build a compliant User-Agent string for Met.no requests.
	 *
	 * @since 1.0.0
	 * @return string
	 */
	private function get_met_no_user_agent(): string {
		$site_name  = get_bloginfo( 'name' );
		$site_url   = home_url();
		$admin_email = get_bloginfo( 'admin_email' );
		return sprintf( 'CloudCoverForecastPlugin/1.0 (%1$s; %2$s; contact:%3$s)', $site_name, $site_url, $admin_email );
	}

	/**
	 * Check whether a remote request can be made without breaking service limits.
	 *
	 * @since 1.0.0
	 * @param string $service Service key.
	 * @return true|WP_Error
	 */
	private function can_make_request( string $service ) {
		$config = self::SERVICE_RATE_LIMITS[ $service ] ?? null;
		if ( ! $config ) {
			return true;
		}

		$key   = $this->plugin::TRANSIENT_PREFIX . 'rate_' . $service;
		$state = get_transient( $key );
		$now   = time();

		if ( ! is_array( $state ) || ! isset( $state['window_start'], $state['count'] ) ) {
			return true;
		}

		$window_elapsed = $now - (int) $state['window_start'];
		if ( $window_elapsed >= (int) $config['window'] ) {
			return true;
		}

		if ( (int) $state['count'] >= (int) $config['max_requests'] ) {
			$retry_after = max( 1, (int) $config['window'] - $window_elapsed );
			return new WP_Error(
				'cloud_cover_forecast_rate_limited',
				sprintf(
				/* translators: 1: external service name, 2: number of seconds to wait before retrying. */
					__( 'Rate limit reached for %1$s. Please wait %2$d seconds and try again.', 'cloud-cover-forecast' ),
					$this->get_service_label( $service ),
					$retry_after
				),
				array( 'retry_after' => $retry_after )
			);
		}

		return true;
	}

	/**
	 * Increment rate counter for a service within its window.
	 *
	 * @since 1.0.0
	 * @param string $service Service key.
	 */
	private function increment_rate_counter( string $service ): void {
		$config = self::SERVICE_RATE_LIMITS[ $service ] ?? null;
		if ( ! $config ) {
			return;
		}

		$key   = $this->plugin::TRANSIENT_PREFIX . 'rate_' . $service;
		$state = get_transient( $key );
		$now   = time();

		if ( ! is_array( $state ) || ! isset( $state['window_start'], $state['count'] ) || ( $now - (int) $state['window_start'] ) >= (int) $config['window'] ) {
			$state = array(
				'window_start' => $now,
				'count'        => 1,
			);
		} else {
			$state['count'] = (int) $state['count'] + 1;
		}

		set_transient( $key, $state, (int) $config['window'] );
		$this->plugin->register_transient_key( $key );
	}

	/**
	 * Human readable label for a service key.
	 *
	 * @since 1.0.0
	 * @param string $service Service key.
	 * @return string
	 */
	private function get_service_label( string $service ): string {
		switch ( $service ) {
			case 'open_meteo_forecast':
				return __( 'Open-Meteo forecast', 'cloud-cover-forecast' );
			case 'open_meteo_geocoding':
				return __( 'Open-Meteo geocoding', 'cloud-cover-forecast' );
			case 'met_no_forecast':
				return __( 'Met.no forecast', 'cloud-cover-forecast' );
			case 'ipgeolocation_astronomy':
				return __( 'IPGeolocation astronomy', 'cloud-cover-forecast' );
			default:
				return __( 'external service', 'cloud-cover-forecast' );
		}
	}

	/**
	 * Merge cloud cover data from Open-Meteo and Met.no using worst-case values.
	 *
	 * @since 1.0.0
	 * @param array $rows            Existing Open-Meteo rows.
	 * @param array $metno_hourly    Normalised Met.no rows keyed by UTC hour.
	 * @param int   $threshold       Difference threshold (percentage points) for highlighting.
	 * @return array{'rows':array,'summary':array}
	 */
	private function merge_cloud_cover_rows( array $rows, array $metno_hourly, int $threshold ): array {
		$levels   = array( 'total', 'low', 'mid', 'high' );
		$summary  = array(
			'rows_with_differences' => 0,
			'per_level'             => array_fill_keys( $levels, 0 ),
		);

		foreach ( $rows as &$row ) {
			$hour_key = gmdate( 'Y-m-d H', $row['ts'] );
			if ( ! isset( $metno_hourly[ $hour_key ] ) ) {
				continue;
			}

			$met_values  = $metno_hourly[ $hour_key ];
			$open_values = array(
				'total' => $row['total'],
				'low'   => $row['low'],
				'mid'   => $row['mid'],
				'high'  => $row['high'],
			);

			$row['source_values'] = array(
				'open_meteo' => $open_values,
				'met_no'     => array(
					'total' => $met_values['total'],
					'low'   => $met_values['low'],
					'mid'   => $met_values['mid'],
					'high'  => $met_values['high'],
				),
			);

			$row_has_diff = false;

			foreach ( $levels as $level ) {
				$open_val = $open_values[ $level ];
				$met_val  = $met_values[ $level ];

				if ( null === $met_val && null === $open_val ) {
					continue;
				}

				if ( null === $open_val ) {
					$row[ $level ] = $met_val;
					continue;
				}

				if ( null === $met_val ) {
					// Keep Open-Meteo value when Met.no lacks data.
					continue;
				}

				$difference = abs( $open_val - $met_val );
				if ( $difference > $threshold ) {
					$row_has_diff = true;
					$summary['per_level'][ $level ]++;
					$row['provider_diff'][ $level ] = array(
						'difference'  => $difference,
						'open_meteo'  => $open_val,
						'met_no'      => $met_val,
						'selected'    => ( $met_val >= $open_val ) ? 'met_no' : 'open_meteo',
					);
				}

				$row[ $level ] = max( $open_val, $met_val );
			}

			if ( $row_has_diff ) {
				$summary['rows_with_differences']++;
			}
		}
		unset( $row );

		return array(
			'rows'    => $rows,
			'summary' => $summary,
		);
	}

	/**
	 * Convert an API time string into a timestamp using the provided timezone context.
	 *
	 * @since 1.0.0
	 * @param string       $time_string ISO8601 time string from the API.
	 * @param DateTimeZone $timezone    Timezone to interpret the string in when no offset is present.
	 * @return int|null Unix timestamp or null on failure.
	 */
	private function to_timestamp_in_timezone( string $time_string, DateTimeZone $timezone ): ?int {
		try {
			$date_time = new DateTime( $time_string, $timezone );
			return $date_time->getTimestamp();
		} catch ( Exception $e ) {
			return null;
		}
	}

	/**
	 * Calculate average of array values, excluding null values.
	 *
	 * @since 1.0.0
	 * @param array $arr Array of values.
	 * @return int|null Average value or null if no valid values.
	 */
	private function avg( $arr ) {
		$arr = array_filter( $arr, function( $v ) { return null !== $v; } );
		if ( ! $arr ) {
			return null;
		}
		return round( array_sum( $arr ) / count( $arr ) );
	}
}
