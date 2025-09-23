<?php
/**
 * Photography rendering for Cloud Cover Forecast Plugin
 *
 * @package CloudCoverForecast
 * @since 1.0.0
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Photography Renderer class for Cloud Cover Forecast Plugin
 *
 * @since 1.0.0
 */
class Cloud_Cover_Forecast_Photography_Renderer {

	/**
	 * Plugin instance
	 *
	 * @since 1.0.0
	 * @var Cloud_Cover_Forecast_Plugin
	 */
	private $plugin;

	/**
	 * Photography instance
	 *
	 * @since 1.0.0
	 * @var Cloud_Cover_Forecast_Photography
	 */
	private $photography;

	/**
	 * Constructor
	 *
	 * @since 1.0.0
	 * @param Cloud_Cover_Forecast_Plugin $plugin Plugin instance.
	 * @param Cloud_Cover_Forecast_Photography $photography Photography instance.
	 */
	public function __construct( $plugin, $photography ) {
		$this->plugin = $plugin;
		$this->photography = $photography;
	}

	/**
	 * Render photography-focused widget with astronomical data
	 *
	 * @since 1.0.0
	 * @param array  $data Weather data.
	 * @param string $label Optional label.
	 * @param int    $show_chart Whether to show chart.
	 */
	public function render_photography_widget( array $data, string $label, int $show_chart, array $options = array() ) {
		$rows  = $data['rows'];
		$stats = $data['stats'];
		$show_other_forecast_apps = ! isset( $options['show_other_forecast_apps'] ) ? true : (bool) $options['show_other_forecast_apps'];
		$photo_ratings = $stats['photo_ratings'] ?? array();
		$photo_times = $stats['photo_times'] ?? array();
		$moon_today = $stats['moon_today'] ?? array();
		$display_sunset = $stats['selected_sunset'] ?? ( $stats['daily_sunset'][0] ?? null );
		$display_sunrise = $stats['selected_sunrise'] ?? ( $stats['daily_sunrise'][0] ?? null );
		//error_log( 'Rendering photography widget: ' . print_r( $data, true ) );
		?>
		<div class="cloud-cover-forecast-wrap cloud-cover-forecast-card cloud-cover-forecast-photography" data-provider="open-meteo">

			<!-- Photography Summary Header -->
			<div class="cloud-cover-forecast-header">
				<div>
					<?php /* translators: %d: number of forecast hours being displayed. */ ?>
					<strong>🌤️ <?php printf( esc_html__( 'Cloud Cover & Astronomical Forecast (next %dh)', 'cloud-cover-forecast' ), count( $rows ) ); ?></strong>
					<?php if ( $label ) : ?>
						<span class="cloud-cover-forecast-badge"><?php echo esc_html( $label ); ?></span>
					<?php endif; ?>
				</div>
				<div class="cloud-cover-forecast-meta">
					<?php /* translators: %s: timezone name such as "America/Los_Angeles". */ ?>
					<?php printf( esc_html__( 'Local Timezone: %s', 'cloud-cover-forecast' ), esc_html( $stats['timezone'] ) ); ?>
				</div>
			</div>

			<!-- Summary Stats & Photography Ratings -->
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
				$provider_notice = $this->render_provider_diff_notice( $stats );
				if ( $provider_notice ) {
					echo $provider_notice; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- already escaped within helper
				}
				?>

				<?php if ( ! empty( $photo_ratings ) ) : ?>
				<div class="cloud-cover-forecast-ratings">
					🌄 <?php esc_html_e( 'Sunrise Photography:', 'cloud-cover-forecast' ); ?>
					<?php echo esc_html( str_repeat( '★', $photo_ratings['sunrise_rating'] ?? 3 ) . str_repeat( '☆', 5 - ( $photo_ratings['sunrise_rating'] ?? 3 ) ) ); ?><br />

					🌅 <?php esc_html_e( 'Sunset Photography:', 'cloud-cover-forecast' ); ?>
					<?php echo esc_html( str_repeat( '★', $photo_ratings['sunset_rating'] ) . str_repeat( '☆', 5 - $photo_ratings['sunset_rating'] ) ); ?><br />

					🌌 <?php esc_html_e( 'Astrophotography:', 'cloud-cover-forecast' ); ?>
					<?php echo esc_html( str_repeat( '★', $photo_ratings['astro_rating'] ) . str_repeat( '☆', 5 - $photo_ratings['astro_rating'] ) ); ?><br />

					🌌 <?php esc_html_e( 'Milky Way Photography:', 'cloud-cover-forecast' ); ?>
					<?php echo esc_html( str_repeat( '★', $photo_ratings['milky_way_rating'] ) . str_repeat( '☆', 5 - $photo_ratings['milky_way_rating'] ) ); ?>
				</div>
				<?php endif; ?>

				<?php if ( ! empty( $moon_today ) && isset( $moon_today['moon_illumination'] ) ) : ?>
				<div class="cloud-cover-forecast-moon">
					🌙 <?php esc_html_e( 'Moon:', 'cloud-cover-forecast' ); ?>
					<?php echo esc_html( $moon_today['moon_phase_name'] . ' ' . $moon_today['moon_illumination'] . '%' ); ?>
					<?php if ( ! empty( $moon_today['moonset'] ) ) : ?>
						<?php /* translators: %s: localized moonset time. */ ?>
						| <?php printf( esc_html__( 'Moonset: %s', 'cloud-cover-forecast' ), esc_html( date_i18n( 'H:i', strtotime( $moon_today['moonset'] ) ) ) ); ?>
					<?php endif; ?>
				</div>
				<?php endif; ?>
			</div>

			<!-- Photography Opportunities Section -->
				<?php
				$instructions_markup = $this->render_instructions_section( $stats, $show_other_forecast_apps );
				if ( $instructions_markup ) {
					echo $instructions_markup; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- content escaped within helper
				}
				?>

				<?php if ( ! empty( $photo_times ) && ! empty( $display_sunset ) && ! empty( $display_sunrise ) ) : ?>
			<div class="cloud-cover-forecast-opportunities">
				<h4>🌅 <?php esc_html_e( 'Photography Opportunities', 'cloud-cover-forecast' ); ?></h4>

				<div class="cloud-cover-forecast-opportunity">
					<span class="time-label"><?php esc_html_e( 'Sunrise:', 'cloud-cover-forecast' ); ?></span>
					<span class="time-value"><?php
						$sunrise_dt = new DateTime( $display_sunrise, new DateTimeZone( $stats['timezone'] ) );
						echo esc_html( $sunrise_dt->format( 'H:i' ) );
					?></span>
					<span class="rating-stars"><?php echo esc_html( str_repeat( '★', $photo_ratings['sunrise_rating'] ?? 3 ) ); ?></span>
				</div>

				<div class="cloud-cover-forecast-opportunity">
					<span class="time-label"><?php esc_html_e( 'Sunset:', 'cloud-cover-forecast' ); ?></span>
					<span class="time-value"><?php
						$sunset_dt = new DateTime( $display_sunset, new DateTimeZone( $stats['timezone'] ) );
						echo esc_html( $sunset_dt->format( 'H:i' ) );
					?></span>
					<span class="rating-stars"><?php echo esc_html( str_repeat( '★', $photo_ratings['sunset_rating'] ?? 3 ) ); ?></span>
				</div>

				<?php if ( ! empty( $photo_ratings['optimal_astro_window'] ) ) : ?>
				<div class="cloud-cover-forecast-opportunity">
					<span class="time-label">🌌 <?php esc_html_e( 'Optimal Astro Window:', 'cloud-cover-forecast' ); ?></span>
					<span class="time-value">
						<?php
						echo esc_html( $photo_ratings['optimal_astro_window']['start_time'] . ' - ' . $photo_ratings['optimal_astro_window']['end_time'] );
						printf( ' (%sh)', esc_html( $photo_ratings['optimal_astro_window']['duration'] ) );
						?>
					</span>
					<span class="quality-badge"><?php echo esc_html( strtoupper( $photo_ratings['optimal_astro_window']['quality'] ) ); ?></span>
				</div>
				<?php endif; ?>
			</div>
			<?php endif; ?>

			<!-- Hourly Forecast Table -->
			<table class="cloud-cover-forecast-table cloud-cover-forecast-photography-table" role="table">
				<thead>
					<tr>
						<th class="cloud-cover-forecast-th"><?php esc_html_e( 'Time', 'cloud-cover-forecast' ); ?></th>
						<th class="cloud-cover-forecast-th"><?php esc_html_e( 'Low', 'cloud-cover-forecast' ); ?></th>
						<th class="cloud-cover-forecast-th"><?php esc_html_e( 'Mid', 'cloud-cover-forecast' ); ?></th>
						<th class="cloud-cover-forecast-th"><?php esc_html_e( 'High', 'cloud-cover-forecast' ); ?></th>
						<th class="cloud-cover-forecast-th"><?php esc_html_e( 'Total', 'cloud-cover-forecast' ); ?></th>
						<th class="cloud-cover-forecast-th"><?php esc_html_e( 'Event & Photo Condition', 'cloud-cover-forecast' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php
				$timezone = new DateTimeZone( $stats['timezone'] );
				$current_timestamp = ( new DateTime( 'now', $timezone ) )->getTimestamp();
				foreach ( $rows as $r ) :
					?>
					<?php
					// Skip daylight hours except for golden hour periods
					if ( ! $this->should_show_hour_in_photography_mode( $r['ts'], $photo_times, $moon_today ) ) {
						continue;
					}
					//error_log( 'Rendering hour: ' . print_r( $r, true ) );

					$event_data = $this->get_hour_event_data( $r['ts'], $photo_times, $moon_today );
					$photo_condition = $this->get_hour_photo_condition( $r, $photo_times, $moon_today );
					$row_class = $this->get_hour_row_class( $r['ts'], $photo_times );
					$hour_start = intval( $r['ts'] );
					$hour_end = $hour_start + HOUR_IN_SECONDS;
					if ( $current_timestamp >= $hour_start && $current_timestamp < $hour_end ) {
						$time_state_class = 'current-hour-row';
					} elseif ( $current_timestamp >= $hour_end ) {
						$time_state_class = 'past-hour-row';
					} else {
						$time_state_class = 'future-hour-row';
					}
					$row_classes = implode( ' ', array_filter( array( $row_class, $time_state_class ) ) );
					?>
					<tr class="<?php echo esc_attr( $row_classes ); ?>">
						<td class="cloud-cover-forecast-td"><?php
							$hour_dt = new DateTime( '@' . $r['ts'] );
							$hour_dt->setTimezone( $timezone );
							echo esc_html( $hour_dt->format( 'H:i' ) );
							if ( 'current-hour-row' === $time_state_class ) {
								echo ' <span class="cloud-cover-current-hour-label">' . esc_html__( 'Now', 'cloud-cover-forecast' ) . '</span>';
							}
						?></td>
						<td class="cloud-cover-forecast-td"><?php echo wp_kses( $this->format_cloud_cover_value( $r, 'low' ), $this->get_allowed_cloud_markup() ); ?></td>
						<td class="cloud-cover-forecast-td"><?php echo wp_kses( $this->format_cloud_cover_value( $r, 'mid' ), $this->get_allowed_cloud_markup() ); ?></td>
						<td class="cloud-cover-forecast-td"><?php echo wp_kses( $this->format_cloud_cover_value( $r, 'high' ), $this->get_allowed_cloud_markup() ); ?></td>
						<td class="cloud-cover-forecast-td"><?php echo wp_kses( $this->format_cloud_cover_value( $r, 'total' ), $this->get_allowed_cloud_markup() ); ?></td>
						<td class="cloud-cover-forecast-td event-condition-cell"><?php
							if ( ! empty( $event_data ) ) {
								printf(
									'<span class="event-meta"><span class="event-icon" aria-hidden="true">%1$s</span><span class="event-label">%2$s</span></span>',
									esc_html( $event_data['icon'] ),
									esc_html( $event_data['description'] )
								);
							}

							if ( ! empty( $photo_condition ) ) {
								if ( ! empty( $event_data ) ) {
									echo '<br />';
								}
								printf(
									'<span class="condition-text">%s</span>',
									esc_html( $photo_condition )
								);
							}
					?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>

			<div class="cloud-cover-forecast-footer">
				<div class="cloud-cover-forecast-meta">
					<?php
					printf(
						/* translators: 1: latitude, 2: longitude. */
						esc_html__( 'Location: %1$s, %2$s · Weather: Open‑Meteo + Met.no · Astronomy: IPGeolocation', 'cloud-cover-forecast' ),
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
	 * Get event icon and description for a specific hour based on astronomical events
	 *
	 * @since 1.0.0
	 * @param int   $timestamp Current hour timestamp.
	 * @param array $photo_times Calculated photography times.
	 * @param array $moon_today Moon data for today.
	 * @return array Event data with icon and description, or empty array.
	 */
	private function get_hour_event_data( int $timestamp, array $photo_times, array $moon_today ): array {
		$hour_time = gmdate( 'H:i', $timestamp );

		// Check for sunrise - sunrise should appear in the hour slot that contains the sunrise time
		if ( ! empty( $photo_times['sunrise'] ) ) {
			$sunrise_ts = $photo_times['sunrise'];
			$sunrise_hour = intval( gmdate( 'H', $sunrise_ts ) );
			$current_hour = intval( gmdate( 'H', $timestamp ) );

			// If sunrise is in this hour slot, show sunrise icon and description
			if ( $sunrise_hour === $current_hour ) {
				return array(
					'icon' => '🌄',
					'description' => __( 'Sunrise', 'cloud-cover-forecast' )
				);
			}
		}

		// Check for sunset - sunset should appear in the hour slot that contains the sunset time
		if ( ! empty( $photo_times['sunset'] ) ) {
			$sunset_ts = $photo_times['sunset'];
			$sunset_hour = intval( gmdate( 'H', $sunset_ts ) );
			$current_hour = intval( gmdate( 'H', $timestamp ) );

			// If sunset is in this hour slot, show sunset icon and description
			if ( $sunset_hour === $current_hour ) {
				return array(
					'icon' => '🌅',
					'description' => __( 'Sunset', 'cloud-cover-forecast' )
				);
			}
		}

		// Check for astronomical twilight - show in the hour slot that contains the twilight end time
		if ( ! empty( $photo_times['astronomical_twilight_end'] ) ) {
			$twilight_ts = $photo_times['astronomical_twilight_end'];
			$twilight_hour = intval( gmdate( 'H', $twilight_ts ) );
			$current_hour = intval( gmdate( 'H', $timestamp ) );

			// If astronomical twilight end is in this hour slot, show astro icon and description
			if ( $twilight_hour === $current_hour ) {
				return array(
					'icon' => '🌌',
					'description' => __( 'Astro Dark', 'cloud-cover-forecast' )
				);
			}
		}

		// Check for moonrise - show in the hour slot that contains the moonrise time
		if ( ! empty( $moon_today['moonrise'] ) ) {
			$moonrise_ts = strtotime( $moon_today['moonrise'] );
			$moonrise_hour = intval( gmdate( 'H', $moonrise_ts ) );
			$current_hour = intval( gmdate( 'H', $timestamp ) );

			// If moonrise is in this hour slot, show moonrise icon and description
			if ( $moonrise_hour === $current_hour ) {
				return array(
					'icon' => '🌙',
					'description' => __( 'Moonrise', 'cloud-cover-forecast' )
				);
			}
		}

		// Check for moonset - show in the hour slot that contains the moonset time
		if ( ! empty( $moon_today['moonset'] ) ) {
			$moonset_ts = strtotime( $moon_today['moonset'] );
			$moonset_hour = intval( gmdate( 'H', $moonset_ts ) );
			$current_hour = intval( gmdate( 'H', $timestamp ) );

			// If moonset is in this hour slot, show moonset icon and description
			if ( $moonset_hour === $current_hour ) {
				return array(
					'icon' => '🌙',
					'description' => __( 'Moonset', 'cloud-cover-forecast' )
				);
			}
		}

		// Check for Milky Way core rise - show in the hour slot that contains the core rise time
		if ( ! empty( $photo_times['milky_way_core_rise'] ) ) {
			$core_rise_ts = $photo_times['milky_way_core_rise'];
			$core_rise_hour = intval( gmdate( 'H', $core_rise_ts ) );
			$current_hour = intval( gmdate( 'H', $timestamp ) );

			// If Milky Way core rise is in this hour slot, show Milky Way icon and description
			if ( $core_rise_hour === $current_hour ) {
				return array(
					'icon' => '🌌',
					'description' => __( 'Milky Way', 'cloud-cover-forecast' )
				);
			}
		}

		return array();
	}

	/**
	 * Get photography condition description for a specific hour
	 *
	 * @since 1.0.0
	 * @param array $row Hourly forecast row data.
	 * @param array $photo_times Calculated photography times.
	 * @param array $moon_today Moon data for today.
	 * @return string Photography condition description.
	 */
	private function get_hour_photo_condition( array $row, array $photo_times, array $moon_today ): string {
		$total_cloud = $row['total'] ?? 100;
		$high_cloud = $row['high'] ?? 0;
		$timestamp = $row['ts'] ?? 0;
		$hour_time = gmdate( 'H:i', $timestamp );

		// Check for moonset first (prioritize astronomical events)
		if ( ! empty( $moon_today['moonset'] ) ) {
			$moonset_ts = strtotime( $moon_today['moonset'] );
			$moonset_hour = intval( gmdate( 'H', $moonset_ts ) );
			$current_hour = intval( gmdate( 'H', $timestamp ) );

			if ( $moonset_hour === $current_hour ) {
				if ( $total_cloud < 20 ) {
					return '🌙 Moonset, clear skies';
				} elseif ( $total_cloud < 50 ) {
					return '🌙 Moonset, partly cloudy';
				} else {
					return '🌙 Moonset, mostly cloudy';
				}
			}
		}

		// Check for moonrise
		if ( ! empty( $moon_today['moonrise'] ) ) {
			$moonrise_ts = strtotime( $moon_today['moonrise'] );
			$moonrise_hour = intval( gmdate( 'H', $moonrise_ts ) );
			$current_hour = intval( gmdate( 'H', $timestamp ) );

			if ( $moonrise_hour === $current_hour ) {
				if ( $total_cloud < 20 ) {
					return '🌙 Moonrise, clear skies';
				} elseif ( $total_cloud < 50 ) {
					return '🌙 Moonrise, partly cloudy';
				} else {
					return '🌙 Moonrise, mostly cloudy';
				}
			}
		}

		// Check if it's during sunrise golden hour (check if current hour falls within the golden hour range)
		$is_sunrise_golden_hour = false;
		if ( ! empty( $photo_times['sunrise_golden_hour_start'] ) && ! empty( $photo_times['golden_hour_end'] ) ) {
			$current_hour = intval( gmdate( 'H', $timestamp ) );
			$golden_start_hour = intval( gmdate( 'H', $photo_times['sunrise_golden_hour_start'] ) );
			$golden_end_hour = intval( gmdate( 'H', $photo_times['golden_hour_end'] ) );
			$is_sunrise_golden_hour = ( $current_hour >= $golden_start_hour && $current_hour <= $golden_end_hour );
		}

		// Check if it's during sunset golden hour (1 hour before sunset)
		$is_sunset_golden_hour = false;
		if ( ! empty( $photo_times['golden_hour_start'] ) ) {
			$current_hour = intval( gmdate( 'H', $timestamp ) );
			$golden_start_hour = intval( gmdate( 'H', $photo_times['golden_hour_start'] ) );
			$is_sunset_golden_hour = ( $current_hour === $golden_start_hour );
		}

		// Check if it's the actual sunset hour
		$is_sunset_hour = false;
		if ( ! empty( $photo_times['sunset'] ) ) {
			$current_hour = intval( gmdate( 'H', $timestamp ) );
			$sunset_hour = intval( gmdate( 'H', $photo_times['sunset'] ) );
			$is_sunset_hour = ( $current_hour === $sunset_hour );
		}

		// Check if it's during sunrise blue hour (check if current hour falls within the blue hour range)
		$is_sunrise_blue_hour = false;
		if ( ! empty( $photo_times['sunrise_blue_hour_start'] ) && ! empty( $photo_times['sunrise_blue_hour_end'] ) ) {
			$current_hour = intval( gmdate( 'H', $timestamp ) );
			$blue_start_hour = intval( gmdate( 'H', $photo_times['sunrise_blue_hour_start'] ) );
			$blue_end_hour = intval( gmdate( 'H', $photo_times['sunrise_blue_hour_end'] ) );
			$is_sunrise_blue_hour = ( $current_hour >= $blue_start_hour && $current_hour <= $blue_end_hour );
		}

		// Check if it's during sunset blue hour (check if current hour falls within the blue hour range)
		$is_sunset_blue_hour = false;
		if ( ! empty( $photo_times['blue_hour_start'] ) && ! empty( $photo_times['blue_hour_end'] ) ) {
			$current_hour = intval( gmdate( 'H', $timestamp ) );
			$blue_start_hour = intval( gmdate( 'H', $photo_times['blue_hour_start'] ) );
			$blue_end_hour = intval( gmdate( 'H', $photo_times['blue_hour_end'] ) );
			$is_sunset_blue_hour = ( $current_hour >= $blue_start_hour && $current_hour <= $blue_end_hour );
		}

		// Check if it's during astronomical dark (after sunset twilight, before sunrise twilight)
		$is_astro_dark = false;
		if ( ! empty( $photo_times['astronomical_twilight_end'] ) && ! empty( $photo_times['astronomical_twilight_start'] ) ) {
			$current_hour = intval( gmdate( 'H', $timestamp ) );
			$astro_end_hour = intval( gmdate( 'H', $photo_times['astronomical_twilight_end'] ) );
			$astro_start_hour = intval( gmdate( 'H', $photo_times['astronomical_twilight_start'] ) );

			// Handle astronomical dark that spans midnight
			if ( $astro_end_hour > $astro_start_hour ) {
				// Normal case: astronomical dark ends in evening, starts in morning
				$is_astro_dark = ( $current_hour > $astro_end_hour || $current_hour < $astro_start_hour );
			} else {
				// Edge case: astronomical dark within same day (polar regions)
				$is_astro_dark = ( $current_hour > $astro_end_hour && $current_hour < $astro_start_hour );
			}
		}

		// Check moon visibility - determine if moon is up during this hour
		$moon_up = false;
		$moonrise_ts = null;
		$moonset_ts = null;

		if ( ! empty( $moon_today['moonrise'] ) ) {
			$moonrise_ts = strtotime( $moon_today['moonrise'] );
		}
		if ( ! empty( $moon_today['moonset'] ) ) {
			$moonset_ts = strtotime( $moon_today['moonset'] );
		}

		// Determine if moon is visible during this hour
		if ( $moonrise_ts && $moonset_ts ) {
			// Both moonrise and moonset are available
			if ( $moonset_ts > $moonrise_ts ) {
				// Normal case: moonrise before moonset (same day)
				$moon_up = ( $timestamp >= $moonrise_ts && $timestamp < $moonset_ts );
			} else {
				// Moonrise after moonset (moonrise is next day)
				$moon_up = ( $timestamp >= $moonrise_ts || $timestamp < $moonset_ts );
			}
		} elseif ( $moonrise_ts ) {
			// Only moonrise available - assume moon is up after moonrise
			$moon_up = ( $timestamp >= $moonrise_ts );
		} elseif ( $moonset_ts ) {
			// Only moonset available - assume moon is up before moonset
			$moon_up = ( $timestamp < $moonset_ts );
		}

		if ( $is_sunrise_blue_hour ) {
			if ( $high_cloud > 30 && $high_cloud < 70 && $total_cloud < 60 ) {
				return '🌄 Dramatic sunrise blue hour';
			} elseif ( $total_cloud < 30 ) {
				return '🌄 Clear sunrise blue hour';
			} else {
				return '🌄 Overcast sunrise blue hour';
			}
		}

		if ( $is_sunrise_golden_hour ) {
			if ( $total_cloud < 20 ) {
				return '🌄 Clear sunrise golden hour';
			} elseif ( $total_cloud < 40 && $high_cloud > 20 ) {
				return '🌄 Spectacular sunrise conditions';
			} else {
				return '🌄 Cloudy sunrise golden hour';
			}
		}

		if ( $is_sunset_golden_hour ) {
			if ( $total_cloud < 20 ) {
				return '🌅 Clear sunset golden hour';
			} elseif ( $total_cloud < 40 && $high_cloud > 20 ) {
				return '🌅 Great sunset conditions';
			} else {
				return '🌅 Cloudy golden hour';
			}
		}

		if ( $is_sunset_hour ) {
			if ( $total_cloud < 20 ) {
				return '🌅 Clear sunset';
			} elseif ( $total_cloud < 40 && $high_cloud > 20 ) {
				return '🌅 Spectacular sunset conditions';
			} else {
				return '🌅 Cloudy sunset';
			}
		}

		if ( $is_sunset_blue_hour ) {
			if ( $high_cloud > 30 && $high_cloud < 70 && $total_cloud < 60 ) {
				return '🌆 Dramatic sunset blue hour';
			} elseif ( $total_cloud < 30 ) {
				return '🌆 Clear sunset blue hour';
			} else {
				return '🌆 Overcast sunset blue hour';
			}
		}

		if ( $is_astro_dark ) {
			if ( $total_cloud < 10 && ! $moon_up ) {
				return '🌌 Excellent for astro';
			} elseif ( $total_cloud < 10 && $moon_up ) {
				return '🌌 Clear sky, moon visible (interference)';
			} elseif ( $total_cloud < 30 && ! $moon_up ) {
				return '🌌 Some clouds, decent for astro';
			} elseif ( $total_cloud < 30 && $moon_up ) {
				return '🌌 Some clouds, moon visible';
			} else {
				return '🌌 Too cloudy for astro';
			}
		}

		// Check if it's well after sunrise but before sunset (regular daylight hours)
		$is_midday = false;
		if ( ! empty( $photo_times['sunrise'] ) && ! empty( $photo_times['sunset'] ) ) {
			// Only consider it midday if it's at least 2 hours after sunrise and 2 hours before sunset
			$two_hours_after_sunrise = $photo_times['sunrise'] + ( 2 * 3600 );
			$two_hours_before_sunset = $photo_times['sunset'] - ( 2 * 3600 );
			$is_midday = ( $timestamp >= $two_hours_after_sunrise ) && ( $timestamp <= $two_hours_before_sunset );
		}

		// Midday hours - astrophotography not possible
		if ( $is_midday ) {
			if ( $total_cloud < 20 ) {
				return 'Clear skies';
			} elseif ( $total_cloud < 50 ) {
				return 'Partly cloudy';
			} else {
				return 'Mostly cloudy';
			}
		}

		// Nighttime conditions
		if ( $moon_up ) {
			// Moon is visible during nighttime
			if ( $total_cloud < 20 ) {
				return '🌙 Moon visible, clear skies';
			} elseif ( $total_cloud < 50 ) {
				return '🌙 Moon visible, partly cloudy';
			} else {
				return '🌙 Moon visible, mostly cloudy';
			}
		} else {
			// No moon visible during nighttime
			if ( $total_cloud < 20 ) {
				return 'Clear skies';
			} elseif ( $total_cloud < 50 ) {
				return 'Partly cloudy';
			} else {
				return 'Mostly cloudy';
			}
		}
	}

	/**
	 * Build the instructions section shown above the opportunities list.
	 *
	 * @since 1.0.0
	 * @param array $stats Forecast statistics.
	 * @param bool  $show_other_forecast_apps Whether to display the other forecast apps section.
	 * @return string HTML markup.
	 */
	private function render_instructions_section( array $stats, bool $show_other_forecast_apps ): string {
		$diff_summary = $stats['provider_diff_summary'] ?? array();
		$diff_hours = intval( $diff_summary['rows_with_differences'] ?? 0 );
		$variance_sentence = esc_html__( 'We merge hourly cloud cover from Open-Meteo and Met.no and highlight disagreements with Δ badges.', 'cloud-cover-forecast' );

		if ( $diff_hours > 0 ) {
			$hours_label = sprintf(
				/* translators: %s: number of hours. */
				_n( '%s hour', '%s hours', $diff_hours, 'cloud-cover-forecast' ),
				number_format_i18n( $diff_hours )
			);
			$hours_label = esc_html( $hours_label );
			$variance_sentence .= ' ' . sprintf(
				/* translators: %s: number of hours that show provider variance. */
				esc_html__( '%s in this forecast show a wider spread, so plan for a broader range of cloud cover.', 'cloud-cover-forecast' ),
				$hours_label
			);
		}

		$variance_sentence .= ' ' . esc_html__( 'Hover a badge to compare the providers and gauge how confident the prediction is.', 'cloud-cover-forecast' );

		ob_start();
		?>
		<div class="cloud-cover-forecast-instructions is-collapsed">
			<button type="button" class="instructions-toggle" aria-expanded="false">
				<span class="instructions-toggle-icon" aria-hidden="true">▸</span>
				<span class="instructions-toggle-label"><?php esc_html_e( 'How to read this forecast', 'cloud-cover-forecast' ); ?></span>
			</button>
			<div class="instructions-content" hidden>
				<p><?php echo esc_html( $variance_sentence ); ?></p>
				<?php if ( $show_other_forecast_apps ) : ?>
				<p><?php esc_html_e( 'Other options include:', 'cloud-cover-forecast' ); ?></p>
				<ul>
					<li>
						<?php
						printf(
							wp_kses(
								__( '<a href="%s" target="_blank" rel="noopener noreferrer">Clear Outside</a>', 'cloud-cover-forecast' ),
								array(
									'a' => array(
										'href' => array(),
										'target' => array(),
										'rel' => array(),
									),
								)
							),
							esc_url( 'https://clearoutside.com/' )
						);
						?>
					</li>
					<li>
						<?php
						printf(
							wp_kses(
								__( '<a href="%s" target="_blank" rel="noopener noreferrer">Astronomy Seeing</a>', 'cloud-cover-forecast' ),
								array(
									'a' => array(
										'href' => array(),
										'target' => array(),
										'rel' => array(),
									),
								)
							),
							esc_url( 'https://content.meteoblue.com/en/private-customers/website-help/outdoor-and-sports/astronomy-seeing' )
						);
						?>
					</li>
					<li><?php esc_html_e( 'Windy.com app (click on the red menu, choose the cloud overlays)', 'cloud-cover-forecast' ); ?></li>
				</ul>
				<?php endif; ?>
				<p><?php esc_html_e( 'Cloud layers influence golden hour in different ways:', 'cloud-cover-forecast' ); ?></p>
				<ul>
					<li><?php esc_html_e( 'Low cloud (0-3 km) hugs the horizon and can block the sun entirely, crushing colour.', 'cloud-cover-forecast' ); ?></li>
					<li><?php esc_html_e( 'Mid-level cloud (3-8 km) adds texture—moderate cover keeps the sky interesting if the horizon stays open.', 'cloud-cover-forecast' ); ?></li>
					<li><?php esc_html_e( 'High cloud (8 km+) catches post-sunset light; thin cirrus often lights up spectacularly after the sun dips below the horizon.', 'cloud-cover-forecast' ); ?></li>
				</ul>
				<p><?php esc_html_e( 'Crystal-clear skies still deliver a warm glow at the horizon, but with no cloud to reflect the colour the scene can feel flat and the light drops off quickly once the sun has set.', 'cloud-cover-forecast' ); ?></p>
				<p><?php esc_html_e( 'The sunrise and sunset star ratings above reward that balance—clear horizons with some mid and high cloud boost the score, while heavy low or total cloud quickly drags it down.', 'cloud-cover-forecast' ); ?></p>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Check if an hour should be displayed in photography mode
	 * Shows hours from 1 hour before sunset to midnight, and from midnight to sunrise
	 *
	 * @since 1.0.0
	 * @param int   $timestamp Current hour timestamp.
	 * @param array $photo_times Calculated photography times.
	 * @param array $moon_today Moon data for today.
	 * @return bool True if hour should be shown, false otherwise.
	 */
	private function should_show_hour_in_photography_mode( int $timestamp, array $photo_times, array $moon_today = array() ): bool {
		// Always show if no photo times available (fallback)
		if ( empty( $photo_times ) ) {
			return true;
		}

		$current_hour = intval( gmdate( 'H', $timestamp ) );

		// Show hours from 1 hour before sunset to midnight, and from midnight to sunrise
		if ( ! empty( $photo_times['sunset'] ) && ! empty( $photo_times['sunrise'] ) ) {
			$sunset_hour = intval( gmdate( 'H', $photo_times['sunset'] ) );
			$sunrise_hour = intval( gmdate( 'H', $photo_times['sunrise'] ) );

			// Calculate 1 hour before sunset
			$one_hour_before_sunset = ( $sunset_hour - 1 + 24 ) % 24;

			// Check if current hour is in the range: 1 hour before sunset to midnight (23:00)
			if ( $current_hour >= $one_hour_before_sunset && $current_hour <= 23 ) {
				return true;
			}

			// Check if current hour is in the range: midnight (00:00) to sunrise
			if ( $current_hour >= 0 && $current_hour <= $sunrise_hour ) {
				return true;
			}
		}

		// Hide all other daylight hours
		return false;
	}

	/**
	 * Get CSS row class for highlighting optimal photography times
	 *
	 * @since 1.0.0
	 * @param int   $timestamp Current hour timestamp.
	 * @param array $photo_times Calculated photography times.
	 * @return string CSS class name.
	 */
	private function get_hour_row_class( int $timestamp, array $photo_times ): string {
		// Check if it's during sunrise golden hour (check if current hour falls within the golden hour range)
		$is_sunrise_golden_hour = false;
		if ( ! empty( $photo_times['sunrise_golden_hour_start'] ) && ! empty( $photo_times['golden_hour_end'] ) ) {
			$current_hour = intval( gmdate( 'H', $timestamp ) );
			$golden_start_hour = intval( gmdate( 'H', $photo_times['sunrise_golden_hour_start'] ) );
			$golden_end_hour = intval( gmdate( 'H', $photo_times['golden_hour_end'] ) );
			$is_sunrise_golden_hour = ( $current_hour >= $golden_start_hour && $current_hour <= $golden_end_hour );
		}

		// Check if it's during sunset golden hour (check if current hour falls within the golden hour range)
		$is_sunset_golden_hour = false;
		if ( ! empty( $photo_times['golden_hour_start'] ) && ! empty( $photo_times['sunset'] ) ) {
			$current_hour = intval( gmdate( 'H', $timestamp ) );
			$sunset_hour = intval( gmdate( 'H', $photo_times['sunset'] ) );

			// Only show golden hour for the hour that contains the sunset time
			// (since golden hour is the hour before sunset, it will be in the same hour slot as sunset)
			$is_sunset_golden_hour = ( $current_hour === $sunset_hour );
		}

		// Check if it's during astronomical dark (between sunset and sunrise twilights)
		$is_astro_dark = false;
		if ( ! empty( $photo_times['astronomical_twilight_end'] ) && ! empty( $photo_times['astronomical_twilight_start'] ) ) {
			$current_hour = intval( gmdate( 'H', $timestamp ) );
			$astro_end_hour = intval( gmdate( 'H', $photo_times['astronomical_twilight_end'] ) );
			$astro_start_hour = intval( gmdate( 'H', $photo_times['astronomical_twilight_start'] ) );

			// Handle astronomical dark that spans midnight
			if ( $astro_end_hour > $astro_start_hour ) {
				// Normal case: astronomical dark ends in evening, starts in morning
				$is_astro_dark = ( $current_hour > $astro_end_hour || $current_hour < $astro_start_hour );
			} else {
				// Edge case: astronomical dark within same day (polar regions)
				$is_astro_dark = ( $current_hour > $astro_end_hour && $current_hour < $astro_start_hour );
			}
		}

		// Check if it's nighttime (after sunset and before sunrise)
		$is_nighttime = false;
		if ( ! empty( $photo_times['sunset'] ) && ! empty( $photo_times['sunrise'] ) ) {
			$current_hour = intval( gmdate( 'H', $timestamp ) );
			$sunset_hour = intval( gmdate( 'H', $photo_times['sunset'] ) );
			$sunrise_hour = intval( gmdate( 'H', $photo_times['sunrise'] ) );

			// Handle nighttime that spans midnight
			if ( $sunset_hour > $sunrise_hour ) {
				// Normal case: sunset in evening, sunrise in morning
				$is_nighttime = ( $current_hour > $sunset_hour || $current_hour < $sunrise_hour );
			} else {
				// Edge case: sunset and sunrise in same day (polar regions)
				$is_nighttime = ( $current_hour > $sunset_hour && $current_hour < $sunrise_hour );
			}
		}

		if ( $is_sunrise_golden_hour ) {
			return 'sunrise-golden-hour-row';
		}

		if ( $is_sunset_golden_hour ) {
			return 'sunset-golden-hour-row';
		}

		if ( $is_astro_dark ) {
			return 'astro-dark-row';
		}

		if ( $is_nighttime ) {
			return 'nighttime-row';
		}

		return '';
	}

	/**
	 * Render a user-friendly variance notice when providers disagree.
	 *
	 * @since 1.0.0
	 * @param array $stats Stats array from forecast data.
	 * @return string HTML markup or empty string.
	 */
	private function render_provider_diff_notice( array $stats ): string {
		$summary = $stats['provider_diff_summary'] ?? array();
		$rows_with_diff = intval( $summary['rows_with_differences'] ?? 0 );
		if ( $rows_with_diff <= 0 ) {
			return '';
		}

		$threshold = intval( $summary['threshold'] ?? 20 );
		$hours_text = sprintf(
			/* translators: %s: number of forecast hours with differences. */
			_n( '%s hour', '%s hours', $rows_with_diff, 'cloud-cover-forecast' ),
			number_format_i18n( $rows_with_diff )
		);
		$intro = sprintf(
			/* translators: 1: count of hours with variance, 2: variance threshold percentage. */
			esc_html__( 'Heads-up: the forecast models disagree for %1$s (difference above %2$s%%).', 'cloud-cover-forecast' ),
			esc_html( $hours_text ),
			esc_html( number_format_i18n( $threshold ) )
		);

		$per_level = $summary['per_level'] ?? array();
		$level_labels = array(
			'total' => esc_html__( 'overall cloud cover', 'cloud-cover-forecast' ),
			'low'   => esc_html__( 'low cloud', 'cloud-cover-forecast' ),
			'mid'   => esc_html__( 'mid-level cloud', 'cloud-cover-forecast' ),
			'high'  => esc_html__( 'high cloud', 'cloud-cover-forecast' ),
		);
		$highlights = array();
		foreach ( $level_labels as $key => $label ) {
			$count = intval( $per_level[ $key ] ?? 0 );
			if ( $count > 0 ) {
				$highlights[] = sprintf(
					/* translators: 1: cloud cover level label, 2: number of hours. */
					esc_html__( '%1$s (%2$s hrs)', 'cloud-cover-forecast' ),
					$label,
					number_format_i18n( $count )
				);
			}
		}

		$detail = '';
		if ( ! empty( $highlights ) ) {
			$detail = sprintf(
				/* translators: %s: comma-separated list of cloud cover levels. */
				esc_html__( 'Largest differences in %s.', 'cloud-cover-forecast' ),
				$this->natural_language_join( $highlights )
			);
		}

		return '<div class="cloud-cover-forecast-provider-note">' . trim( $intro . ' ' . $detail ) . '</div>';
	}

	/**
	 * Join phrases into a natural language list.
	 *
	 * @since 1.0.0
	 * @param array $items List of phrases.
	 * @return string
	 */
	private function natural_language_join( array $items ): string {
		$items = array_values( array_filter( $items ) );
		$count = count( $items );
		if ( 0 === $count ) {
			return '';
		}
		if ( 1 === $count ) {
			return $items[0];
		}
		$last = array_pop( $items );
		return implode( ', ', $items ) . ' ' . esc_html__( 'and', 'cloud-cover-forecast' ) . ' ' . $last;
	}

	/**
	 * Format a cloud cover value with variance metadata badge.
	 *
	 * @since 1.0.0
	 * @param array  $row   Hourly row data.
	 * @param string $level Cloud level key (total|low|mid|high).
	 * @return string Markup-safe HTML string.
	 */
		private function format_cloud_cover_value( array $row, string $level ): string {
			$value = $row[ $level ] ?? null;
			$value_text = ( null === $value || '' === $value )
				? esc_html__( '—', 'cloud-cover-forecast' )
				: esc_html( intval( $value ) . '%' );
			$value_markup = sprintf( '<span class="cloud-cover-value">%s</span>', $value_text );
			$badge_markup = '';

			if ( empty( $row['provider_diff'][ $level ] ) ) {
				return sprintf( '<span class="cloud-cover-value-wrap">%s</span>', $value_markup );
			}

			if ( 'total' === $level && ! empty( $row['provider_diff'] ) ) {
				$non_total_diffs = array_filter(
					$row['provider_diff'],
					function ( $diff, $diff_level ) {
						return 'total' !== $diff_level && ! empty( $diff );
					},
					ARRAY_FILTER_USE_BOTH
				);

				if ( 1 === count( $non_total_diffs ) ) {
					return sprintf( '<span class="cloud-cover-value-wrap">%s</span>', $value_markup );
				}
			}

			$diff         = $row['provider_diff'][ $level ];
			$diff_value   = intval( $diff['difference'] );
			$open_value   = intval( $diff['open_meteo'] );
			$metno_value  = intval( $diff['met_no'] );
			$tooltip_text = sprintf(
				/* translators: 1: Open-Meteo cloud cover percentage, 2: Met.no cloud cover percentage. */
				esc_html__( 'Open-Meteo: %1$s%% · Met.no: %2$s%%', 'cloud-cover-forecast' ),
				$open_value,
				$metno_value
			);

			/* translators: %s: difference percentage between forecast providers. */
			$delta_label = sprintf( __( 'Δ %s%%', 'cloud-cover-forecast' ), $diff_value );
			$badge_markup = sprintf(
				'<span class="cloud-cover-diff-badge" title="%1$s">%2$s</span>',
				esc_attr( $tooltip_text ),
				esc_html( $delta_label )
			);

			return sprintf( '<span class="cloud-cover-value-wrap">%s%s</span>', $value_markup, $badge_markup );
		}

	/**
	 * Allowed markup for cloud cover table cells.
	 *
	 * @since 1.0.0
	 * @return array
	 */
	private function get_allowed_cloud_markup(): array {
		return array(
			'span' => array(
				'class' => array(),
				'title' => array(),
			),
		);
	}
}
