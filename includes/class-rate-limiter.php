<?php
/**
 * Rate Limiter
 *
 * Per-IP rate limiting using WordPress transients.
 *
 * @package Cloud_Cover_Forecast
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shared rate limiter for public-facing AJAX endpoints.
 *
 * @since 1.0.0
 */
class Cloud_Cover_Forecast_Rate_Limiter {

	/**
	 * Plugin instance for transient key generation.
	 *
	 * @since 1.0.0
	 * @var Cloud_Cover_Forecast_Plugin
	 */
	private $plugin;

	/**
	 * Transient key prefix to namespace this limiter's data.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	private $prefix;

	/**
	 * Maximum requests allowed per window.
	 *
	 * @since 1.0.0
	 * @var int
	 */
	private $max_requests;

	/**
	 * Window duration in seconds.
	 *
	 * @since 1.0.0
	 * @var int
	 */
	private $window_seconds;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 * @param Cloud_Cover_Forecast_Plugin $plugin         Plugin instance.
	 * @param string                      $prefix         Short identifier for this limiter (e.g. 'pwa', 'public').
	 * @param int                         $max_requests   Maximum requests per window.
	 * @param int                         $window_seconds Window duration in seconds.
	 */
	public function __construct( $plugin, $prefix, $max_requests = 30, $window_seconds = 60 ) {
		$this->plugin         = $plugin;
		$this->prefix         = $prefix;
		$this->max_requests   = $max_requests;
		$this->window_seconds = $window_seconds;
	}

	/**
	 * Check whether the current request is allowed, and count it atomically.
	 *
	 * @since 1.0.0
	 * @return bool True if allowed, false if rate limited.
	 */
	public function is_allowed() {
		$ip            = $this->get_client_ip();
		$transient_key = $this->plugin->get_transient_key(
			$this->plugin::RATE_LIMIT_PREFIX,
			$this->prefix . '_' . md5( $ip )
		);

		$now       = time();
		$rate_data = get_transient( $transient_key );

		if ( ! is_array( $rate_data ) || ! isset( $rate_data['window_start'], $rate_data['count'] ) ) {
			$rate_data = array(
				'window_start' => $now,
				'count'        => 0,
			);
		}

		// Reset window if expired.
		if ( $now - intval( $rate_data['window_start'] ) >= $this->window_seconds ) {
			$rate_data = array(
				'window_start' => $now,
				'count'        => 0,
			);
		}

		if ( intval( $rate_data['count'] ) >= $this->max_requests ) {
			return false;
		}

		$rate_data['count']++;
		$expires_in = max( 1, $this->window_seconds - ( $now - intval( $rate_data['window_start'] ) ) );
		set_transient( $transient_key, $rate_data, $expires_in );

		return true;
	}

	/**
	 * Get client IP address for rate limiting.
	 *
	 * Only trusts Cloudflare's CF-Connecting-IP (which Cloudflare controls
	 * and strips from client requests) and REMOTE_ADDR. User-spoofable
	 * headers like X-Forwarded-For are intentionally excluded.
	 *
	 * @since 1.0.0
	 * @return string Client IP address.
	 */
	private function get_client_ip() {
		// CF-Connecting-IP is set by Cloudflare and cannot be spoofed by the client.
		if ( ! empty( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ) {
			$ip = sanitize_text_field( wp_unslash( $_SERVER['HTTP_CF_CONNECTING_IP'] ) );
			if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
				return $ip;
			}
		}

		// Fall back to direct connection IP.
		if ( ! empty( $_SERVER['REMOTE_ADDR'] ) ) {
			$ip = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
			if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
				return $ip;
			}
		}

		return '0.0.0.0';
	}
}
