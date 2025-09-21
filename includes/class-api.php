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
	 * Constructor
	 *
	 * @since 1.0.0
	 * @param Cloud_Cover_Forecast_Plugin $plugin Plugin instance.
	 */
	public function __construct( $plugin ) {
		$this->plugin = $plugin;
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
	public function fetch_open_meteo( float $lat, float $lon, int $hours ) {
		// Validate coordinates
		if ( $lat < -90 || $lat > 90 ) {
			return new WP_Error( 'cloud_cover_forecast_invalid_lat', __( 'Invalid latitude. Must be between -90 and 90.', 'cloud-cover-forecast' ) );
		}
		if ( $lon < -180 || $lon > 180 ) {
			return new WP_Error( 'cloud_cover_forecast_invalid_lon', __( 'Invalid longitude. Must be between -180 and 180.', 'cloud-cover-forecast' ) );
		}

		$params = array(
			'latitude'  => $lat,
			'longitude' => $lon,
			'hourly'    => 'cloudcover,cloudcover_low,cloudcover_mid,cloudcover_high',
			'daily'     => 'sunrise,sunset',
			'timezone'  => 'auto',
		);
		$url = add_query_arg( $params, 'https://api.open-meteo.com/v1/forecast' );

		$res = get_transient( "forecast_" . md5( $url ) );
		if ( ! $res ) {
		$res = wp_remote_get( $url, array(
			'timeout' => 12,
			'user-agent' => 'Cloud Cover Forecast Plugin/' . CLOUD_COVER_FORECAST_VERSION,
			'sslverify' => true
		) );
		if ( is_wp_error( $res ) ) {
			return new WP_Error( 'cloud_cover_forecast_network', __( 'Network error occurred while fetching weather data.', 'cloud-cover-forecast' ) );
		}
		$code = wp_remote_retrieve_response_code( $res );
		if ( 200 !== $code ) {
			return new WP_Error( 'cloud_cover_forecast_http', __( 'Weather service temporarily unavailable. Please try again later.', 'cloud-cover-forecast' ) );
		}
		set_transient( "forecast_" . md5( $url ), $res, 12 * HOUR_IN_SECONDS );
		} else {
			error_log( "found cached data");
		}

		$body = wp_remote_retrieve_body( $res );
		$json = json_decode( $body, true );
		if ( ! $json || empty( $json['hourly']['time'] ) ) {
			return new WP_Error( 'cloud_cover_forecast_json', __( 'Malformed API response', 'cloud-cover-forecast' ) );
		}
		error_log( "json: " . print_r( $json, true ) );

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

		// Set timezone context for consistent timestamp parsing
		$orig_timezone = date_default_timezone_get();
		date_default_timezone_set( $timezone );

		// Get current time in the location's timezone for proper comparison
		$now = time();

		// Calculate today's start in the location's timezone
		$location_now = new DateTime( 'now', new DateTimeZone( $timezone ) );
		$today_start = $location_now->setTime( 0, 0, 0 )->getTimestamp();

		// Debug: Add debug info about today_start calculation
		error_log( 'Today start calculated as: ' . date( 'Y-m-d H:i:s', $today_start ) . ' (timezone: ' . $timezone . ')' );

		// Determine relevant sunset/sunrise window for photography display
		$last_sunset = null;
		$next_sunset = null;
		foreach ( $daily_sunset as $sunset_time ) {
			$sunset_ts = strtotime( $sunset_time );
			if ( false === $sunset_ts ) {
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
				$sunrise_ts = strtotime( $sunrise_time );
				if ( false === $sunrise_ts ) {
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
				$sunrise_ts = strtotime( $sunrise_time );
				if ( false === $sunrise_ts ) {
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
			$ts = strtotime( $times[ $i ] );
			if ( false === $ts ) {
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

		// Reset timezone to original value
		date_default_timezone_set( $orig_timezone );

		// Debug: Show first few and last few timestamps before sorting
		error_log( 'Before sorting - Total rows: ' . count( $rows ) );
		if ( count( $rows ) > 0 ) {
			error_log( 'First row: ' . date( 'Y-m-d H:i', $rows[0]['ts'] ) . ' (ts: ' . $rows[0]['ts'] . ')' );
			error_log( 'Last row: ' . date( 'Y-m-d H:i', $rows[count($rows)-1]['ts'] ) . ' (ts: ' . $rows[count($rows)-1]['ts'] . ')' );
		}
		//error_log( "before sortingrows: " . print_r( $rows, true ) );

		// Sort rows by timestamp to ensure chronological order (past hours first, then future)
		usort( $rows, function( $a, $b ) {
			return $a['ts'] <=> $b['ts'];
		});
		//error_log( "after sorting rows: " . print_r( $rows, true ) );

		// Debug: Show first few and last few timestamps after sorting
		error_log( 'After sorting:' );
		if ( count( $rows ) > 0 ) {
			error_log( 'First row: ' . date( 'Y-m-d H:i', $rows[0]['ts'] ) . ' (ts: ' . $rows[0]['ts'] . ')' );
			error_log( 'Last row: ' . date( 'Y-m-d H:i', $rows[count($rows)-1]['ts'] ) . ' (ts: ' . $rows[count($rows)-1]['ts'] . ')' );
		}
		error_log( "hours: " . $hours );
		// Limit to requested number of hours (ensure we cover until selected sunrise)
		$rows = array_slice( $rows, 0, $hours_limit );

		// Debug: Show final result
		error_log( 'After limiting to ' . $hours . ' hours: ' . count( $rows ) . ' rows' );
		if ( count( $rows ) > 0 ) {
			error_log( 'Final first row: ' . date( 'Y-m-d H:i', $rows[0]['ts'] ) );
			error_log( 'Final last row: ' . date( 'Y-m-d H:i', $rows[count($rows)-1]['ts'] ) );
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

		// Check cache first (24 hour cache)
		$cache_key = $this->plugin::GEOCODING_PREFIX . md5( strtolower( trim( $location_name ) ) );
		$cached = get_transient( $cache_key );
		if ( $cached ) {
			return $cached;
		}

		$params = array(
			'name'   => trim( $location_name ),
			'count'  => 5, // Get multiple results for selection
			'format' => 'json',
		);
		$url = add_query_arg( $params, 'https://geocoding-api.open-meteo.com/v1/search' );

		$res = wp_remote_get( $url, array(
			'timeout' => 10,
			'user-agent' => 'Cloud Cover Forecast Plugin/' . CLOUD_COVER_FORECAST_VERSION,
			'sslverify' => true
		) );
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
				'lat'     => $result['latitude'],
				'lon'     => $result['longitude'],
				'name'    => $result['name'],
				'country' => $result['country'] ?? '',
				'admin1'  => $result['admin1'] ?? '',
			);

			// Cache for 24 hours
			set_transient( $cache_key, $geocoded, 24 * HOUR_IN_SECONDS );

			return $geocoded;
		}

		// Return multiple results for selection
		$results = array();
		foreach ( $json['results'] as $result ) {
			$results[] = array(
				'lat'     => $result['latitude'],
				'lon'     => $result['longitude'],
				'name'    => $result['name'],
				'country' => $result['country'] ?? '',
				'admin1'  => $result['admin1'] ?? '',
				'admin2'  => $result['admin2'] ?? '',
			);
		}

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

		// Return empty data if no API key provided
		if ( empty( $api_key ) ) {
			return array(
				'moon_illumination' => null,
				'moon_phase_name'   => 'Unknown',
				'moonrise'          => null,
				'moonset'           => null,
				'moon_azimuth'      => null,
				'moon_altitude'     => null,
			);
		}

		if ( empty( $date ) ) {
			$date = gmdate( 'Y-m-d' );
		}

		// Check cache first (24 hour cache for moon data)
		$cache_key = $this->plugin::GEOCODING_PREFIX . 'moon_' . md5( $lat . '|' . $lon . '|' . $date );
		$cached = get_transient( $cache_key );
		if ( $cached ) {
			return $cached;
		}

		$params = array(
			'apiKey' => $api_key,
			'lat'    => $lat,
			'long'   => $lon,
			'date'   => $date,
		);
		$url = add_query_arg( $params, 'https://api.ipgeolocation.io/astronomy' );

		$res = wp_remote_get( $url, array(
			'timeout' => 10,
			'user-agent' => 'Cloud Cover Forecast Plugin/' . CLOUD_COVER_FORECAST_VERSION,
			'sslverify' => true
		) );
		if ( is_wp_error( $res ) ) {
			// Return empty data on network failure - graceful degradation
			return array(
				'moon_illumination' => null,
				'moon_phase_name'   => 'Unknown',
				'moonrise'          => null,
				'moonset'           => null,
				'moon_azimuth'      => null,
				'moon_altitude'     => null,
			);
		}

		$code = wp_remote_retrieve_response_code( $res );
		if ( 200 !== $code ) {
			// Return empty data on API failure - graceful degradation
			return array(
				'moon_illumination' => null,
				'moon_phase_name'   => 'Unknown',
				'moonrise'          => null,
				'moonset'           => null,
				'moon_azimuth'      => null,
				'moon_altitude'     => null,
			);
		}

		$body = wp_remote_retrieve_body( $res );
		$json = json_decode( $body, true );
		if ( ! $json ) {
			return array(
				'moon_illumination' => null,
				'moon_phase_name'   => 'Unknown',
				'moonrise'          => null,
				'moonset'           => null,
				'moon_azimuth'      => null,
				'moon_altitude'     => null,
			);
		}

		$moon_data = array(
			'moon_illumination' => isset( $json['moon_illumination'] ) ? intval( $json['moon_illumination'] ) : null,
			'moon_phase_name'   => $json['moon_phase_name'] ?? 'Unknown',
			'moonrise'          => $json['moonrise'] ?? null,
			'moonset'           => $json['moonset'] ?? null,
			'moon_azimuth'      => isset( $json['moon_azimuth'] ) ? floatval( $json['moon_azimuth'] ) : null,
			'moon_altitude'     => isset( $json['moon_altitude'] ) ? floatval( $json['moon_altitude'] ) : null,
		);

		// Cache for 24 hours
		set_transient( $cache_key, $moon_data, 24 * HOUR_IN_SECONDS );

		return $moon_data;
	}

	/**
	 * Calculate average of array values, excluding null values
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
