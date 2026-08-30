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
	 * Per-service rate limit configuration.
	 *
	 * Each service maps to a list of windows, all of which must have headroom
	 * before a request is allowed. Multiple windows let us respect providers
	 * that publish both burst (per-minute) and volume (per-day) limits.
	 *
	 * Budgets are deliberately set below each provider's published ceiling so
	 * that two Open-Meteo services sharing one quota cannot exhaust it:
	 *
	 * - Open-Meteo free tier: 600/min, 5,000/hour, 10,000/day (shared across
	 *   the forecast and geocoding endpoints). Allocated 420/min, 2,800/hour,
	 *   9,000/day in total.
	 * - Met.no: 20 requests/second per application. Allocated 200/min.
	 * - Nominatim: absolute maximum of 1 request per second.
	 * - IPGeolocation free tier: 1,000 requests/day.
	 *
	 * @since 1.0.0
	 * @var array<string,array<int,array<string,int>>>
	 */
	private const SERVICE_RATE_LIMITS = array(
		'open_meteo_forecast'     => array(
			array( 'window' => MINUTE_IN_SECONDS, 'max_requests' => 300 ),
			array( 'window' => HOUR_IN_SECONDS,   'max_requests' => 2000 ),
			array( 'window' => DAY_IN_SECONDS,    'max_requests' => 7000 ),
		),
		'open_meteo_geocoding'    => array(
			array( 'window' => MINUTE_IN_SECONDS, 'max_requests' => 120 ),
			array( 'window' => HOUR_IN_SECONDS,   'max_requests' => 800 ),
			array( 'window' => DAY_IN_SECONDS,    'max_requests' => 2000 ),
		),
		'met_no_forecast'         => array(
			array( 'window' => MINUTE_IN_SECONDS, 'max_requests' => 200 ),
			array( 'window' => HOUR_IN_SECONDS,   'max_requests' => 3000 ),
		),
		'ipgeolocation_astronomy' => array(
			array( 'window' => HOUR_IN_SECONDS, 'max_requests' => 100 ),
			array( 'window' => DAY_IN_SECONDS,  'max_requests' => 900 ),
		),
		'nominatim_reverse'       => array(
			array( 'window' => 1, 'max_requests' => 1 ),
		),
	);

	/**
	 * Cron hook fired to refresh a stale cache entry out of band.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	const REFRESH_HOOK = 'cloud_cover_forecast_refresh_cache';

	/**
	 * How long a cached response stays usable after it goes stale.
	 *
	 * Stale entries are served immediately while a refresh happens in the
	 * background, and are the fallback when a provider is unreachable. Keeping
	 * a long grace period means an outage degrades to slightly old data rather
	 * than an error page.
	 *
	 * @since 1.0.0
	 * @var int
	 */
	private const STALE_GRACE = 12 * HOUR_IN_SECONDS;

	/**
	 * Constructor
	 *
	 * @since 1.0.0
	 * @param Cloud_Cover_Forecast_Plugin $plugin Plugin instance.
	 */
	public function __construct( $plugin ) {
		$this->plugin = $plugin;

		add_action( self::REFRESH_HOOK, array( $this, 'refresh_cached_response' ), 10, 3 );
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

		$cache_key = $this->plugin->get_transient_key(
			$this->plugin::TRANSIENT_PREFIX,
			'open_meteo_' . md5( $url )
		);
		$res = $this->get_cached_remote( 'open_meteo_forecast', $url, $cache_key );
		if ( is_wp_error( $res ) ) {
			return $res;
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
	 * Fetch extended weather data from Open-Meteo API for PWA
	 *
	 * Returns 7 days (168 hours) of comprehensive weather data including
	 * temperature, humidity, precipitation, wind, visibility, and more.
	 *
	 * @since 1.0.0
	 * @param float $lat  Latitude.
	 * @param float $lon  Longitude.
	 * @param int   $days Number of days to fetch (default 7).
	 * @return array|WP_Error Extended weather data or error.
	 */
	public function fetch_extended_forecast( float $lat, float $lon, int $days = 7 ) {
		// Validate coordinates.
		if ( ! $this->validate_coordinates( $lat, $lon ) ) {
			return new WP_Error(
				'cloud_cover_forecast_invalid_coordinates',
				__( 'Invalid coordinates. Must be between -90 and 90 for latitude, and -180 and 180 for longitude.', 'cloud-cover-forecast' )
			);
		}

		$days = max( 1, min( 7, $days ) );

		$params = array(
			'latitude'       => $lat,
			'longitude'      => $lon,
			'hourly'         => implode( ',', array(
				'temperature_2m',
				'apparent_temperature',
				'dew_point_2m',
				'relative_humidity_2m',
				'precipitation',
				'precipitation_probability',
				'rain',
				'weather_code',
				'cloud_cover',
				'cloud_cover_low',
				'cloud_cover_mid',
				'cloud_cover_high',
				'visibility',
				'wind_speed_10m',
				'wind_direction_10m',
				'is_day',
			) ),
			'daily'          => implode( ',', array(
				'sunrise',
				'sunset',
			) ),
			'timezone'       => 'auto',
			'forecast_days'  => $days,
		);

		$url = add_query_arg( $params, 'https://api.open-meteo.com/v1/forecast' );

		$cache_key = $this->plugin->get_transient_key(
			$this->plugin::TRANSIENT_PREFIX,
			'extended_' . md5( $url )
		);
		$res = $this->get_cached_remote( 'open_meteo_forecast', $url, $cache_key );
		if ( is_wp_error( $res ) ) {
			return $res;
		}

		$body = wp_remote_retrieve_body( $res );
		$json = json_decode( $body, true );
		if ( ! $json || empty( $json['hourly']['time'] ) ) {
			return new WP_Error(
				'cloud_cover_forecast_json',
				__( 'Malformed API response', 'cloud-cover-forecast' )
			);
		}

		$timezone      = $json['timezone'] ?? 'UTC';
		$timezone_abbr = $json['timezone_abbreviation'] ?? 'UTC';
		$hourly        = $json['hourly'];
		$daily         = $json['daily'] ?? array();

		// Build hourly data array.
		$hourly_data = array();
		$count       = count( $hourly['time'] );

		for ( $i = 0; $i < $count; $i++ ) {
			$hourly_data[] = array(
				'time'              => $hourly['time'][ $i ],
				'temperature'       => $hourly['temperature_2m'][ $i ] ?? null,
				'feels_like'        => $hourly['apparent_temperature'][ $i ] ?? null,
				'dew_point'         => $hourly['dew_point_2m'][ $i ] ?? null,
				'humidity'          => $hourly['relative_humidity_2m'][ $i ] ?? null,
				'precipitation'     => $hourly['precipitation'][ $i ] ?? null,
				'rain_chance'       => $hourly['precipitation_probability'][ $i ] ?? null,
				'rain_amount'       => $hourly['precipitation'][ $i ] ?? null,
				'weather_code'      => $hourly['weather_code'][ $i ] ?? null,
				'cloud_total'       => $hourly['cloud_cover'][ $i ] ?? null,
				'cloud_low'         => $hourly['cloud_cover_low'][ $i ] ?? null,
				'cloud_mid'         => $hourly['cloud_cover_mid'][ $i ] ?? null,
				'cloud_high'        => $hourly['cloud_cover_high'][ $i ] ?? null,
				'visibility'        => $hourly['visibility'][ $i ] ?? null,
				'wind_speed'        => $hourly['wind_speed_10m'][ $i ] ?? null,
				'wind_direction'    => $hourly['wind_direction_10m'][ $i ] ?? null,
				'is_day'            => $hourly['is_day'][ $i ] ?? null,
			);
		}

		// Calculate frost indicator for each hour.
		foreach ( $hourly_data as &$hour ) {
			$hour['frost'] = ( null !== $hour['temperature'] && $hour['temperature'] <= 0 );
		}
		unset( $hour );

		// Build daily data array.
		$daily_data = array();
		if ( ! empty( $daily['time'] ) ) {
			$day_count = count( $daily['time'] );
			for ( $i = 0; $i < $day_count; $i++ ) {
				$daily_data[] = array(
					'date'    => $daily['time'][ $i ],
					'sunrise' => $daily['sunrise'][ $i ] ?? null,
					'sunset'  => $daily['sunset'][ $i ] ?? null,
				);
			}
		}

		// Calculate twilight times for each day.
		foreach ( $daily_data as &$day ) {
			$day['twilight'] = $this->calculate_twilight_times( $lat, $lon, $day['date'], $timezone );
		}
		unset( $day );

		// Fetch moon data for the forecast period.
		$moon_data = array();
		foreach ( $daily_data as $day ) {
			$moon = $this->fetch_moon_data( $lat, $lon, $day['date'] );
			if ( ! is_wp_error( $moon ) ) {
				$moon_data[ $day['date'] ] = $moon;
			}
		}

		return array(
			'location'      => array(
				'lat'      => $lat,
				'lon'      => $lon,
				'timezone' => $timezone,
				'timezone_abbr' => $timezone_abbr,
			),
			'hourly'        => $hourly_data,
			'daily'         => $daily_data,
			'moon'          => $moon_data,
			'generated_at'  => gmdate( 'c' ),
		);
	}

	/**
	 * Calculate twilight times for a given date and location.
	 *
	 * @since 1.0.0
	 * @param float  $lat      Latitude.
	 * @param float  $lon      Longitude.
	 * @param string $date     Date in YYYY-MM-DD format.
	 * @param string $timezone Timezone identifier.
	 * @return array Twilight times array.
	 */
	private function calculate_twilight_times( float $lat, float $lon, string $date, string $timezone ): array {
		try {
			$tz         = new DateTimeZone( $timezone );
			$local_noon = new DateTime( $date . ' 12:00:00', $tz );
		} catch ( Exception $e ) {
			return array();
		}

		// Noon at the location, not on the server. strtotime() would build
		// noon in the server's timezone, which lands on the wrong day for
		// locations far enough from it.
		$timestamp = $local_noon->getTimestamp();

		$format_time = function( $ts ) use ( $tz ) {
			if ( null === $ts ) {
				return null;
			}
			$dt = new DateTime( '@' . $ts );
			$dt->setTimezone( $tz );
			return $dt->format( 'H:i' );
		};

		// Every boundary comes from one solver so they all share a single day
		// anchoring. date_sun_info() anchors to the UTC day instead, which
		// answers for the previous date once a location reaches UTC+13.
		$astronomical = $this->solar_event_times( $lat, $lon, $timestamp, -18.0 );
		$nautical     = $this->solar_event_times( $lat, $lon, $timestamp, -12.0 );
		$civil        = $this->solar_event_times( $lat, $lon, $timestamp, -6.0 );
		$horizon      = $this->solar_event_times( $lat, $lon, $timestamp, -0.833 );
		$blue_edge    = $this->solar_event_times( $lat, $lon, $timestamp, -4.0 );
		$golden_edge  = $this->solar_event_times( $lat, $lon, $timestamp, 6.0 );

		return array(
			'astronomical_dawn' => $format_time( $astronomical['rise'] ),
			'nautical_dawn'     => $format_time( $nautical['rise'] ),
			'civil_dawn'        => $format_time( $civil['rise'] ),
			'sunrise'           => $format_time( $horizon['rise'] ),
			'sunset'            => $format_time( $horizon['set'] ),
			'civil_dusk'        => $format_time( $civil['set'] ),
			'nautical_dusk'     => $format_time( $nautical['set'] ),
			'astronomical_dusk' => $format_time( $astronomical['set'] ),

			// Photographic light phases, in order through the day.
			'blue_hour_dawn_start'   => $format_time( $civil['rise'] ),
			'blue_hour_dawn_end'     => $format_time( $blue_edge['rise'] ),
			'golden_hour_dawn_start' => $format_time( $blue_edge['rise'] ),
			'golden_hour_dawn_end'   => $format_time( $golden_edge['rise'] ),
			'golden_hour_dusk_start' => $format_time( $golden_edge['set'] ),
			'golden_hour_dusk_end'   => $format_time( $blue_edge['set'] ),
			'blue_hour_dusk_start'   => $format_time( $blue_edge['set'] ),
			'blue_hour_dusk_end'     => $format_time( $civil['set'] ),
		);
	}

	/**
	 * Times the sun crosses a given elevation on a date, rising and setting.
	 *
	 * date_sun_info() only reports the fixed elevations behind sunrise/sunset
	 * and the three twilights, so the golden hour boundaries are solved here
	 * with the standard low-precision solar position formulae. Good to about
	 * a minute, which is finer than hourly forecast data can justify anyway.
	 *
	 * @since 1.1.0
	 * @param float $lat       Latitude.
	 * @param float $lon       Longitude.
	 * @param int   $noon_ts   Timestamp of local noon on the date in question.
	 * @param float $elevation Target solar elevation, in degrees.
	 * @return array Rise and set timestamps, each null if the sun never reaches the elevation.
	 */
	private function solar_event_times( float $lat, float $lon, int $noon_ts, float $elevation ): array {
		$none = array(
			'rise' => null,
			'set'  => null,
		);

		// Days since J2000.0.
		$n = ( ( $noon_ts / 86400 ) + 2440587.5 ) - 2451545.0;

		// Solar mean longitude and mean anomaly, in degrees.
		$mean_longitude = fmod( 280.460 + ( 0.9856474 * $n ), 360.0 );
		$mean_anomaly   = fmod( 357.528 + ( 0.9856003 * $n ), 360.0 );

		// Ecliptic longitude, correcting the mean position for orbital eccentricity.
		$ecliptic_longitude = $mean_longitude
			+ ( 1.915 * sin( deg2rad( $mean_anomaly ) ) )
			+ ( 0.020 * sin( deg2rad( 2.0 * $mean_anomaly ) ) );

		$obliquity = 23.439 - ( 0.0000004 * $n );

		$declination = rad2deg( asin(
			sin( deg2rad( $obliquity ) ) * sin( deg2rad( $ecliptic_longitude ) )
		) );

		$right_ascension = rad2deg( atan2(
			cos( deg2rad( $obliquity ) ) * sin( deg2rad( $ecliptic_longitude ) ),
			cos( deg2rad( $ecliptic_longitude ) )
		) );

		// Equation of time, in minutes: how far true solar noon drifts from mean.
		$equation_of_time = 4.0 * $this->normalize_degrees_signed( $mean_longitude - $right_ascension );

		// Hour angle between solar noon and the target elevation.
		$latitude_rad    = deg2rad( $lat );
		$declination_rad = deg2rad( $declination );
		$divisor         = cos( $latitude_rad ) * cos( $declination_rad );

		// Exactly at a pole the divisor vanishes and no crossing is defined.
		if ( 0.0 === $divisor ) {
			return $none;
		}

		$cos_hour_angle = ( sin( deg2rad( $elevation ) )
				- ( sin( $latitude_rad ) * sin( $declination_rad ) ) ) / $divisor;

		// Outside [-1, 1] the sun stays above this elevation all day, or never
		// reaches it. Both are ordinary at high latitude: an Irish June has no
		// astronomical twilight at all.
		if ( $cos_hour_angle > 1.0 || $cos_hour_angle < -1.0 ) {
			return $none;
		}

		$hour_angle = rad2deg( acos( $cos_hour_angle ) );

		// Solar noon as a timestamp, anchored to the UTC day holding local noon.
		$utc_day_start = intdiv( $noon_ts, 86400 ) * 86400;
		$solar_noon_ts = $utc_day_start
			+ (int) round( ( 12.0 - ( $lon / 15.0 ) - ( $equation_of_time / 60.0 ) ) * 3600.0 );

		// The UTC day and the local day diverge at large offsets, so snap to
		// whichever solar noon actually falls on the requested local date.
		while ( $solar_noon_ts - $noon_ts > 43200 ) {
			$solar_noon_ts -= 86400;
		}
		while ( $noon_ts - $solar_noon_ts > 43200 ) {
			$solar_noon_ts += 86400;
		}

		$offset = (int) round( ( $hour_angle / 15.0 ) * 3600.0 );

		return array(
			'rise' => $solar_noon_ts - $offset,
			'set'  => $solar_noon_ts + $offset,
		);
	}

	/**
	 * Wrap an angle into the range -180 to 180 degrees.
	 *
	 * @since 1.1.0
	 * @param float $degrees Angle in degrees.
	 * @return float Equivalent angle within -180..180.
	 */
	private function normalize_degrees_signed( float $degrees ): float {
		$wrapped = fmod( $degrees + 180.0, 360.0 );
		if ( $wrapped < 0.0 ) {
			$wrapped += 360.0;
		}
		return $wrapped - 180.0;
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
		$cache_key = $this->plugin->get_transient_key(
			$this->plugin::GEOCODING_PREFIX,
			md5( strtolower( trim( $location_name ) ) )
		);
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

		return $results;
	}

	/**
	 * Reverse geocode coordinates to a location name using Nominatim (OpenStreetMap).
	 *
	 * @since 1.0.0
	 * @param float $lat Latitude.
	 * @param float $lon Longitude.
	 * @return array|WP_Error Array with name, admin1, country, timezone or error.
	 */
	public function reverse_geocode( float $lat, float $lon ) {
		// Round coordinates to 4 decimal places for caching
		$lat = round( $lat, 4 );
		$lon = round( $lon, 4 );

		// Check cache first
		$cache_key = $this->plugin->get_transient_key(
			$this->plugin::GEOCODING_PREFIX,
			'reverse_' . md5( "{$lat},{$lon}" )
		);
		$cached    = get_transient( $cache_key );
		if ( false !== $cached ) {
			return $cached;
		}

		$rate_check = $this->can_make_request( 'nominatim_reverse' );
		if ( is_wp_error( $rate_check ) ) {
			return $rate_check;
		}

		$url = add_query_arg(
			array(
				'lat'            => $lat,
				'lon'            => $lon,
				'format'         => 'json',
				'addressdetails' => 1,
				'zoom'           => 10, // City level
			),
			'https://nominatim.openstreetmap.org/reverse'
		);

		$this->increment_rate_counter( 'nominatim_reverse' );
		$res = wp_remote_get(
			$url,
			array(
				'timeout'    => 10,
				'user-agent' => 'Cloud Cover Forecast WordPress Plugin/' . CLOUD_COVER_FORECAST_VERSION . ' (contact via WordPress plugin support)',
				'sslverify'  => true,
			)
		);

		if ( is_wp_error( $res ) ) {
			return new WP_Error( 'cloud_cover_forecast_reverse_geocode_network', __( 'Network error during reverse geocoding.', 'cloud-cover-forecast' ) );
		}

		$code = wp_remote_retrieve_response_code( $res );
		if ( 200 !== $code ) {
			return new WP_Error( 'cloud_cover_forecast_reverse_geocode_http', __( 'Reverse geocoding service unavailable.', 'cloud-cover-forecast' ) );
		}

		$body = wp_remote_retrieve_body( $res );
		$json = json_decode( $body, true );

		if ( ! $json || isset( $json['error'] ) ) {
			return new WP_Error( 'cloud_cover_forecast_reverse_geocode_not_found', __( 'Location not found.', 'cloud-cover-forecast' ) );
		}

		$address = $json['address'] ?? array();

		// Build location name - prefer city/town/village, fall back to others
		$name = $address['city']
			?? $address['town']
			?? $address['village']
			?? $address['municipality']
			?? $address['suburb']
			?? $address['locality']
			?? $json['name']
			?? '';

		// Get state/region (admin1)
		$admin1 = $address['state']
			?? $address['province']
			?? $address['region']
			?? $address['county']
			?? '';

		$country = $address['country'] ?? '';

		// Get timezone from Open-Meteo (Nominatim doesn't provide it)
		$timezone = $this->get_timezone_for_coordinates( $lat, $lon );

		$result = array(
			'lat'      => $lat,
			'lon'      => $lon,
			'name'     => $name,
			'admin1'   => $admin1,
			'country'  => $country,
			'timezone' => $timezone,
		);

		// Cache for 24 hours (reverse geocoding results rarely change)
		set_transient( $cache_key, $result, DAY_IN_SECONDS );

		return $result;
	}

	/**
	 * Get timezone for coordinates from Open-Meteo.
	 *
	 * @since 1.0.0
	 * @param float $lat Latitude.
	 * @param float $lon Longitude.
	 * @return string Timezone identifier or empty string.
	 */
	private function get_timezone_for_coordinates( float $lat, float $lon ): string {
		// Use a minimal Open-Meteo request to get timezone
		$url = add_query_arg(
			array(
				'latitude'  => $lat,
				'longitude' => $lon,
				'timezone'  => 'auto',
				'forecast_days' => 1,
			),
			'https://api.open-meteo.com/v1/forecast'
		);

		$res = wp_remote_get(
			$url,
			array(
				'timeout'    => 5,
				'user-agent' => 'Cloud Cover Forecast Plugin/' . CLOUD_COVER_FORECAST_VERSION,
				'sslverify'  => true,
			)
		);

		if ( is_wp_error( $res ) ) {
			return '';
		}

		$body = wp_remote_retrieve_body( $res );
		$json = json_decode( $body, true );

		return $json['timezone'] ?? '';
	}

	/**
	 * Geocode a location and return normalized results for AJAX responses.
	 *
	 * @since 1.0.0
	 * @param string $location Location name to geocode.
	 * @return array|WP_Error Normalized array of results or error.
	 */
	public function get_normalized_geocode_results( string $location ) {
		$geocoded = $this->geocode_location( $location );
		if ( is_wp_error( $geocoded ) ) {
			return $geocoded;
		}

		// Normalize single result to array format
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
		$cache_key = $this->plugin->get_transient_key(
			$this->plugin::GEOCODING_PREFIX,
			'moon_' . md5( $lat . '|' . $lon . '|' . $date )
		);
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

		$cache_key = $this->plugin->get_transient_key(
			$this->plugin::TRANSIENT_PREFIX,
			'metno_' . md5( $url )
		);
		$res = $this->get_cached_remote( 'met_no_forecast', $url, $cache_key );
		if ( is_wp_error( $res ) ) {
			return $res;
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
		$windows = self::SERVICE_RATE_LIMITS[ $service ] ?? null;
		if ( ! $windows ) {
			return true;
		}

		$now = time();

		foreach ( $windows as $config ) {
			$state = get_transient( $this->get_rate_limit_key( $service, (int) $config['window'] ) );

			if ( ! is_array( $state ) || ! isset( $state['window_start'], $state['count'] ) ) {
				continue;
			}

			$window_elapsed = $now - (int) $state['window_start'];
			if ( $window_elapsed >= (int) $config['window'] ) {
				continue;
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
		}

		return true;
	}

	/**
	 * Build the transient key holding a service's counter for one window.
	 *
	 * Deliberately not versioned via get_transient_key(): clearing the plugin
	 * cache must not reset rate counters, or a cache flush would let the site
	 * exceed a provider's published limits.
	 *
	 * @since 1.0.0
	 * @param string $service Service key.
	 * @param int    $window  Window duration in seconds.
	 * @return string Transient key.
	 */
	private function get_rate_limit_key( string $service, int $window ): string {
		return $this->plugin::TRANSIENT_PREFIX . 'rate_' . $service . '_' . $window;
	}

	/**
	 * Default wp_remote_get() arguments for a service.
	 *
	 * Timeouts are kept short because these requests can sit on the page render
	 * path; a slow provider should degrade to stale data, not stall the page.
	 *
	 * @since 1.0.0
	 * @param string $service Service key.
	 * @return array Request arguments.
	 */
	private function get_request_args( string $service ): array {
		$args = array(
			'timeout'    => 5,
			'user-agent' => 'Cloud Cover Forecast Plugin/' . CLOUD_COVER_FORECAST_VERSION,
			'sslverify'  => true,
			'headers'    => array(),
		);

		if ( 'met_no_forecast' === $service ) {
			$args['user-agent']       = $this->get_met_no_user_agent();
			$args['headers']['Accept'] = 'application/json';
		}

		return $args;
	}

	/**
	 * Fetch a URL through the stale-while-revalidate cache.
	 *
	 * Fresh entries are returned directly. Stale entries are returned
	 * immediately and refreshed by a background cron event, so only the very
	 * first request for a location ever waits on the network.
	 *
	 * @since 1.0.0
	 * @param string $service   Service key for rate limiting.
	 * @param string $url       Request URL.
	 * @param string $cache_key Transient key.
	 * @return array|WP_Error Raw HTTP response array, or error.
	 */
	private function get_cached_remote( string $service, string $url, string $cache_key ) {
		$cached = get_transient( $cache_key );

		if ( is_array( $cached ) && isset( $cached['response'], $cached['fresh_until'] ) ) {
			if ( time() < (int) $cached['fresh_until'] ) {
				return $cached['response'];
			}

			$this->schedule_refresh( $service, $url, $cache_key );
			return $cached['response'];
		}

		return $this->fetch_and_cache( $service, $url, $cache_key );
	}

	/**
	 * Queue a background refresh for a stale cache entry.
	 *
	 * A short lock stops concurrent requests queueing the same refresh many
	 * times over while the first one is still pending.
	 *
	 * @since 1.0.0
	 * @param string $service   Service key.
	 * @param string $url       Request URL.
	 * @param string $cache_key Transient key.
	 */
	private function schedule_refresh( string $service, string $url, string $cache_key ): void {
		$lock_key = $this->plugin::TRANSIENT_PREFIX . 'refresh_lock_' . md5( $cache_key );
		if ( false !== get_transient( $lock_key ) ) {
			return;
		}
		set_transient( $lock_key, 1, 5 * MINUTE_IN_SECONDS );

		wp_schedule_single_event( time(), self::REFRESH_HOOK, array( $service, $url, $cache_key ) );
	}

	/**
	 * Cron callback: refresh one cache entry.
	 *
	 * @since 1.0.0
	 * @param string $service   Service key.
	 * @param string $url       Request URL.
	 * @param string $cache_key Transient key.
	 */
	public function refresh_cached_response( $service, $url, $cache_key ): void {
		$this->fetch_and_cache( (string) $service, (string) $url, (string) $cache_key );
		delete_transient( $this->plugin::TRANSIENT_PREFIX . 'refresh_lock_' . md5( (string) $cache_key ) );
	}

	/**
	 * Perform the request and store the result.
	 *
	 * Falls back to whatever is already cached whenever the request cannot be
	 * made or does not succeed, so transient provider problems never surface
	 * as an error when usable data exists.
	 *
	 * @since 1.0.0
	 * @param string $service   Service key.
	 * @param string $url       Request URL.
	 * @param string $cache_key Transient key.
	 * @return array|WP_Error Raw HTTP response array, or error.
	 */
	private function fetch_and_cache( string $service, string $url, string $cache_key ) {
		$existing = get_transient( $cache_key );

		// Require 'fresh_until' as well as 'response': a raw wp_remote_get()
		// array left over from an older plugin version also has a 'response'
		// key, but it holds the status code rather than a cached payload.
		$is_wrapped = is_array( $existing ) && isset( $existing['response'], $existing['fresh_until'] );
		$fallback   = $is_wrapped ? $existing['response'] : null;

		$rate_check = $this->can_make_request( $service );
		if ( is_wp_error( $rate_check ) ) {
			return null !== $fallback ? $fallback : $rate_check;
		}

		$args = $this->get_request_args( $service );

		// Conditional request: providers answer 304 when nothing has changed,
		// which Met.no's terms of service ask API consumers to support. The
		// header must echo the previous Last-Modified exactly.
		if ( $is_wrapped && ! empty( $existing['last_modified'] ) ) {
			$args['headers']['If-Modified-Since'] = $existing['last_modified'];
		}

		$res = wp_remote_get( $url, $args );
		$this->increment_rate_counter( $service );

		if ( is_wp_error( $res ) ) {
			if ( null !== $fallback ) {
				return $fallback;
			}
			return new WP_Error(
				'cloud_cover_forecast_network',
				__( 'Network error occurred while fetching weather data.', 'cloud-cover-forecast' ),
				array( 'url' => $url )
			);
		}

		$code = wp_remote_retrieve_response_code( $res );

		if ( 304 === $code && null !== $fallback ) {
			$this->store_response( $cache_key, $fallback, (string) ( $existing['last_modified'] ?? '' ) );
			return $fallback;
		}

		if ( 200 !== $code ) {
			if ( null !== $fallback ) {
				return $fallback;
			}
			return new WP_Error(
				'cloud_cover_forecast_http',
				__( 'Weather service temporarily unavailable. Please try again later.', 'cloud-cover-forecast' ),
				array( 'url' => $url, 'status' => $code )
			);
		}

		$this->store_response( $cache_key, $res, (string) wp_remote_retrieve_header( $res, 'last-modified' ) );

		return $res;
	}

	/**
	 * Store a response with its freshness deadline.
	 *
	 * The transient itself outlives the freshness window by STALE_GRACE so the
	 * entry remains available to serve stale and to revalidate against.
	 *
	 * @since 1.0.0
	 * @param string $cache_key     Transient key.
	 * @param array  $response      Raw HTTP response array.
	 * @param string $last_modified Last-Modified header value, if any.
	 */
	private function store_response( string $cache_key, array $response, string $last_modified ): void {
		$cache_ttl_minutes = $this->plugin->get_settings()['cache_ttl'] ?? 15;
		$fresh_seconds     = max( 1, intval( $cache_ttl_minutes ) ) * MINUTE_IN_SECONDS;

		set_transient(
			$cache_key,
			array(
				'response'      => $response,
				'fresh_until'   => time() + $fresh_seconds,
				'last_modified' => $last_modified,
			),
			$fresh_seconds + self::STALE_GRACE
		);
	}

	/**
	 * Increment rate counter for a service within its window.
	 *
	 * @since 1.0.0
	 * @param string $service Service key.
	 */
	private function increment_rate_counter( string $service ): void {
		$windows = self::SERVICE_RATE_LIMITS[ $service ] ?? null;
		if ( ! $windows ) {
			return;
		}

		$now = time();

		foreach ( $windows as $config ) {
			$window = (int) $config['window'];
			$key    = $this->get_rate_limit_key( $service, $window );
			$state  = get_transient( $key );

			if ( ! is_array( $state ) || ! isset( $state['window_start'], $state['count'] ) || ( $now - (int) $state['window_start'] ) >= $window ) {
				$state = array(
					'window_start' => $now,
					'count'        => 1,
				);
			} else {
				$state['count'] = (int) $state['count'] + 1;
			}

			// Expire when the window closes, not a full window from now.
			$expires_in = max( 1, $window - ( $now - (int) $state['window_start'] ) );
			set_transient( $key, $state, $expires_in );
		}
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
			case 'nominatim_reverse':
				return __( 'Nominatim reverse geocoding', 'cloud-cover-forecast' );
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
