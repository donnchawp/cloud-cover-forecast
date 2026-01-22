<?php
/**
 * Uninstall script for Cloud Cover Forecast Plugin
 *
 * Fired when the plugin is uninstalled via WordPress admin.
 * This file handles cleanup of all plugin data from the database.
 *
 * @package CloudCoverForecast
 * @since 1.0.0
 */

// If uninstall not called from WordPress, exit
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * Clean up all plugin data from the database
 */
function cloud_cover_forecast_uninstall() {
	// Delete plugin options
	delete_option( 'cloud_cover_forecast_settings_v1' );
	delete_option( 'cloud_cover_forecast_cache_version' );

	// Clean up any orphaned transients (belt and suspenders approach)
	global $wpdb;

	$prefixes = array(
		'cloud_cover_forecast_cache_',
		'cloud_cover_forecast_geocoding_',
		'cloud_cover_forecast_rate_',
		'cloud_cover_forecast_rate_limit_',
	);

	foreach ( $prefixes as $prefix ) {
		// Delete transient options
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options}
				WHERE option_name LIKE %s
				OR option_name LIKE %s",
				$wpdb->esc_like( '_transient_' . $prefix ) . '%',
				$wpdb->esc_like( '_transient_timeout_' . $prefix ) . '%'
			)
		);
	}

	// Clean up any activation notices
	delete_transient( 'cloud_cover_forecast_activation_notice' );

	// For multisite, clean up site-specific options
	if ( is_multisite() ) {
		global $wpdb;

		$blog_ids = $wpdb->get_col( "SELECT blog_id FROM {$wpdb->blogs}" );

		foreach ( $blog_ids as $blog_id ) {
			switch_to_blog( $blog_id );

			// Delete options for this site
			delete_option( 'cloud_cover_forecast_settings_v1' );
			delete_option( 'cloud_cover_forecast_cache_version' );

			// Clean up orphaned transients for this site
			foreach ( $prefixes as $prefix ) {
				$wpdb->query(
					$wpdb->prepare(
						"DELETE FROM {$wpdb->options}
						WHERE option_name LIKE %s
						OR option_name LIKE %s",
						$wpdb->esc_like( '_transient_' . $prefix ) . '%',
						$wpdb->esc_like( '_transient_timeout_' . $prefix ) . '%'
					)
				);
			}

			restore_current_blog();
		}
	}
}

// Execute cleanup
cloud_cover_forecast_uninstall();
