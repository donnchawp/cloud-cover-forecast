<?php
/**
 * Photography calculations for Cloud Cover Forecast Plugin
 *
 * @package CloudCoverForecast
 * @since 1.0.0
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Photography class for Cloud Cover Forecast Plugin
 *
 * @since 1.0.0
 */
class Cloud_Cover_Forecast_Photography {

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
	 * Calculate astronomical twilight and photography optimal times
	 *
	 * @since 1.0.0
	 * @param string $sunrise_time Sunrise time in ISO format.
	 * @param string $sunset_time Sunset time in ISO format.
	 * @param string $timezone Timezone for calculations.
	 * @return array Calculated times for different photography events.
	 */
	public function calculate_photography_times( string $sunrise_time, string $sunset_time, string $timezone = 'UTC' ) {
		// Create DateTime objects in the specified timezone to ensure correct parsing
		$sunrise_dt = new DateTime( $sunrise_time, new DateTimeZone( $timezone ) );
		$sunset_dt = new DateTime( $sunset_time, new DateTimeZone( $timezone ) );

		$sunrise_ts = $sunrise_dt->getTimestamp();
		$sunset_ts = $sunset_dt->getTimestamp();

		// Calculate different twilight periods (approximate)
		$civil_twilight_end = $sunset_ts + ( 30 * 60 ); // ~30 min after sunset
		$nautical_twilight_end = $sunset_ts + ( 60 * 60 ); // ~60 min after sunset
		$astronomical_twilight_end = $sunset_ts + ( 90 * 60 ); // ~90 min after sunset

		// Calculate sunrise twilight periods (approximate)
		$civil_twilight_start = $sunrise_ts - ( 30 * 60 ); // ~30 min before sunrise
		$nautical_twilight_start = $sunrise_ts - ( 60 * 60 ); // ~60 min before sunrise
		$astronomical_twilight_start = $sunrise_ts - ( 90 * 60 ); // ~90 min before sunrise

		// Calculate Milky Way core rise time (seasonal approximation)
		$month = intval( gmdate( 'm', $sunset_ts ) );
		$milky_way_core_hour = $this->get_milky_way_core_rise_hour( $month );

		// Create core rise time in the same timezone
		$core_rise_date = gmdate( 'Y-m-d', $sunset_ts );
		$core_rise_time = $core_rise_date . ' ' . sprintf( '%02d:00:00', $milky_way_core_hour );
		$core_rise_dt = new DateTime( $core_rise_time, new DateTimeZone( $timezone ) );
		$core_rise_ts = $core_rise_dt->getTimestamp();

		// If core rise is before sunset, it's next day
		if ( $core_rise_ts < $sunset_ts ) {
			$core_rise_dt->add( new DateInterval( 'P1D' ) );
			$core_rise_ts = $core_rise_dt->getTimestamp();
		}

		$golden_hour_start = $sunset_ts - ( 60 * 60 ); // 1 hour before sunset
		$golden_hour_end = $sunrise_ts + ( 60 * 60 ); // 1 hour after sunrise
		$sunrise_golden_hour_start = $sunrise_ts - ( 60 * 60 ); // 1 hour before sunrise

		return array(
			'sunset'                    => $sunset_ts,
			'sunrise'                   => $sunrise_ts,
			'civil_twilight_end'        => $civil_twilight_end,
			'nautical_twilight_end'     => $nautical_twilight_end,
			'astronomical_twilight_end' => $astronomical_twilight_end,
			'civil_twilight_start'      => $civil_twilight_start,
			'nautical_twilight_start'   => $nautical_twilight_start,
			'astronomical_twilight_start' => $astronomical_twilight_start,
			'milky_way_core_rise'       => $core_rise_ts,
			'golden_hour_start'         => $golden_hour_start,
			'golden_hour_end'           => $golden_hour_end,
			'sunrise_golden_hour_start' => $sunrise_golden_hour_start,
			'blue_hour_start'           => $sunset_ts + ( 15 * 60 ), // 15 min after sunset
			'blue_hour_end'             => $sunset_ts + ( 45 * 60 ), // 45 min after sunset
			'sunrise_blue_hour_start'   => $sunrise_ts - ( 45 * 60 ), // 45 min before sunrise
			'sunrise_blue_hour_end'     => $sunrise_ts - ( 15 * 60 ), // 15 min before sunrise
		);
	}

	/**
	 * Get approximate Milky Way core rise hour by month
	 *
	 * @since 1.0.0
	 * @param int $month Month (1-12).
	 * @return int Hour (0-23) when MW core becomes visible.
	 */
	public function get_milky_way_core_rise_hour( int $month ): int {
		// Approximate times for Milky Way core visibility (varies by latitude)
		$core_times = array(
			1  => 6,  // January - early morning
			2  => 5,  // February
			3  => 4,  // March
			4  => 3,  // April
			5  => 2,  // May
			6  => 1,  // June - best summer viewing
			7  => 23, // July
			8  => 22, // August
			9  => 21, // September
			10 => 20, // October
			11 => 7,  // November - poor visibility
			12 => 7,  // December
		);

		return $core_times[ $month ] ?? 4;
	}

	/**
	 * Rate photography conditions based on cloud cover and astronomical events
	 *
	 * @since 1.0.0
	 * @param array $stats Weather statistics including averages and astronomical data.
	 * @return array Photography ratings and analysis.
	 */
	public function rate_photography_conditions( array $stats ): array {
		$avg_total = $stats['avg_total'] ?? 100;
		$avg_high = $stats['avg_high'] ?? 0;
		$moon_today = $stats['moon_today'] ?? array();
		$moon_illumination = $moon_today['moon_illumination'] ?? 0;

		// Sunset photography rating (high clouds are good, total clouds bad)
		$sunset_rating = 5;
		if ( $avg_total > 80 ) {
			$sunset_rating = 1;
		} elseif ( $avg_total > 60 ) {
			$sunset_rating = 2;
		} elseif ( $avg_total > 40 ) {
			$sunset_rating = 3;
		} elseif ( $avg_total > 20 ) {
			$sunset_rating = 4;
		}

		// Boost rating if high clouds present (good for dramatic sunsets)
		if ( $avg_high > 20 && $avg_high < 60 && $avg_total < 50 ) {
			$sunset_rating = min( 5, $sunset_rating + 1 );
		}

		// Sunrise photography rating (similar to sunset - high clouds good for spectacular sunrise)
		$sunrise_rating = 5;
		if ( $avg_total > 80 ) {
			$sunrise_rating = 1;
		} elseif ( $avg_total > 60 ) {
			$sunrise_rating = 2;
		} elseif ( $avg_total > 40 ) {
			$sunrise_rating = 3;
		} elseif ( $avg_total > 20 ) {
			$sunrise_rating = 4;
		}

		// Boost rating if high clouds present (good for dramatic sunrise colors)
		if ( $avg_high > 20 && $avg_high < 60 && $avg_total < 50 ) {
			$sunrise_rating = min( 5, $sunrise_rating + 1 );
		}

		// Astrophotography rating (clear skies + dark moon best)
		$astro_rating = 5;
		if ( $avg_total > 70 ) {
			$astro_rating = 1;
		} elseif ( $avg_total > 50 ) {
			$astro_rating = 2;
		} elseif ( $avg_total > 30 ) {
			$astro_rating = 3;
		} elseif ( $avg_total > 15 ) {
			$astro_rating = 4;
		}

		// Moon interference penalty
		if ( $moon_illumination > 80 ) {
			$astro_rating = max( 1, $astro_rating - 2 );
		} elseif ( $moon_illumination > 50 ) {
			$astro_rating = max( 1, $astro_rating - 1 );
		}

		// Milky Way rating (considers moon position and timing)
		$mw_rating = $astro_rating;
		$moonset_time = $moon_today['moonset'] ?? '';
		$moonrise_time = $moon_today['moonrise'] ?? '';

		// If moon sets before core rise, boost rating
		if ( ! empty( $moonset_time ) ) {
			$moonset_ts = strtotime( $moonset_time );
			$core_rise_ts = strtotime( gmdate( 'Y-m-d' ) . ' 04:30:00' ); // Approximate

			if ( $moonset_ts < $core_rise_ts ) {
				$mw_rating = min( 5, $mw_rating + 1 );
			}
		}

		return array(
			'sunset_rating'  => $sunset_rating,
			'sunrise_rating' => $sunrise_rating,
			'astro_rating'   => $astro_rating,
			'milky_way_rating' => $mw_rating,
			'moon_interference' => $moon_illumination > 30 ? 'high' : ( $moon_illumination > 10 ? 'medium' : 'low' ),
			'optimal_astro_window' => $this->find_optimal_astro_window( $stats ),
		);
	}

	/**
	 * Find optimal astrophotography window based on moon and weather conditions
	 *
	 * @since 1.0.0
	 * @param array $stats Weather and astronomical statistics.
	 * @return array Optimal shooting window information.
	 */
	public function find_optimal_astro_window( array $stats ): array {
		$moon_today = $stats['moon_today'] ?? array();
		$moonset_time = $moon_today['moonset'] ?? '';
		$avg_total_cloud = $stats['avg_total'] ?? 100;
		$astronomical_dark = 23 * 3600 + 42 * 60; // 23:42 approximation

		$window_start = $astronomical_dark;
		$window_end = 6 * 3600; // 06:00 before dawn
		$quality = 'good';

		// Adjust for moonset
		if ( ! empty( $moonset_time ) ) {
			$moonset_ts = strtotime( $moonset_time );
			$moonset_seconds = intval( gmdate( 'H', $moonset_ts ) ) * 3600 + intval( gmdate( 'i', $moonset_ts ) ) * 60;

			// If moonset is after astronomical dark (same day) or before window end (next day)
			if ( ( $moonset_seconds > $astronomical_dark ) || ( $moonset_seconds < $window_end ) ) {
				$window_start = $moonset_seconds;
			}
		}

		// Determine quality based on cloud cover conditions
		if ( $avg_total_cloud < 10 ) {
			$quality = 'excellent';
		} elseif ( $avg_total_cloud < 25 ) {
			$quality = 'good';
		} elseif ( $avg_total_cloud < 50 ) {
			$quality = 'fair';
		} else {
			$quality = 'poor';
		}

		// Boost quality if moon sets during the window (less light pollution)
		if ( ! empty( $moonset_time ) ) {
			$moonset_ts = strtotime( $moonset_time );
			$moonset_seconds = intval( gmdate( 'H', $moonset_ts ) ) * 3600 + intval( gmdate( 'i', $moonset_ts ) ) * 60;

			if ( ( $moonset_seconds > $astronomical_dark ) || ( $moonset_seconds < $window_end ) ) {
				if ( $quality === 'fair' ) {
					$quality = 'good';
				} elseif ( $quality === 'good' ) {
					$quality = 'excellent';
				}
			}
		}

		$duration_hours = ( $window_end - $window_start ) / 3600;

		// Handle cross-midnight duration (when end time is next day)
		if ( $duration_hours < 0 ) {
			$duration_hours = ( 24 * 3600 + $window_end - $window_start ) / 3600;
		}

		// Format times properly (these are seconds since midnight, not Unix timestamps)
		$start_hours = intval( $window_start / 3600 );
		$start_minutes = intval( ( $window_start % 3600 ) / 60 );
		$end_hours = intval( $window_end / 3600 );
		$end_minutes = intval( ( $window_end % 3600 ) / 60 );

		return array(
			'start_time' => sprintf( '%02d:%02d', $start_hours, $start_minutes ),
			'end_time'   => sprintf( '%02d:%02d', $end_hours, $end_minutes ),
			'duration'   => round( $duration_hours, 1 ),
			'quality'    => $quality,
		);
	}
}
