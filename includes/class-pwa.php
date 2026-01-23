<?php
/**
 * PWA handler for Cloud Cover Forecast Plugin
 *
 * @package CloudCoverForecast
 * @since 1.0.0
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * PWA class for Cloud Cover Forecast Plugin
 *
 * Handles PWA registration, routing, and AJAX endpoints for the forecast app.
 *
 * @since 1.0.0
 */
class Cloud_Cover_Forecast_PWA {

	/**
	 * Plugin instance
	 *
	 * @since 1.0.0
	 * @var Cloud_Cover_Forecast_Plugin
	 */
	private $plugin;

	/**
	 * Default PWA endpoint slug (used as fallback)
	 *
	 * @since 1.0.0
	 * @var string
	 */
	const DEFAULT_ENDPOINT = 'forecast-app';

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
	 * Get the PWA endpoint slug from settings
	 *
	 * @since 1.0.0
	 * @return string PWA endpoint slug.
	 */
	public function get_endpoint() {
		$settings = $this->plugin->get_settings();
		$path = isset( $settings['pwa_path'] ) ? $settings['pwa_path'] : self::DEFAULT_ENDPOINT;
		// Sanitize: only allow alphanumeric, hyphens, and underscores
		$path = preg_replace( '/[^a-zA-Z0-9_-]/', '', $path );
		return ! empty( $path ) ? $path : self::DEFAULT_ENDPOINT;
	}

	/**
	 * Check if noindex is enabled for the PWA
	 *
	 * @since 1.0.0
	 * @return bool True if noindex is enabled.
	 */
	public function is_noindex_enabled() {
		$settings = $this->plugin->get_settings();
		return ! empty( $settings['pwa_noindex'] );
	}

	/**
	 * Initialize PWA functionality
	 *
	 * @since 1.0.0
	 */
	public function init() {
		// Register rewrite rules.
		add_action( 'init', array( $this, 'register_rewrite_rules' ) );
		add_filter( 'query_vars', array( $this, 'register_query_vars' ) );
		add_action( 'template_redirect', array( $this, 'handle_template_redirect' ) );

		// Register AJAX endpoints.
		add_action( 'wp_ajax_ccf_pwa_forecast', array( $this, 'ajax_extended_forecast' ) );
		add_action( 'wp_ajax_nopriv_ccf_pwa_forecast', array( $this, 'ajax_extended_forecast' ) );
		add_action( 'wp_ajax_ccf_pwa_geocode', array( $this, 'ajax_geocode' ) );
		add_action( 'wp_ajax_nopriv_ccf_pwa_geocode', array( $this, 'ajax_geocode' ) );
		add_action( 'wp_ajax_ccf_pwa_reverse_geocode', array( $this, 'ajax_reverse_geocode' ) );
		add_action( 'wp_ajax_nopriv_ccf_pwa_reverse_geocode', array( $this, 'ajax_reverse_geocode' ) );

		// Serve manifest and service worker.
		add_action( 'init', array( $this, 'serve_pwa_assets' ) );
	}

	/**
	 * Register rewrite rules for the PWA endpoint
	 *
	 * @since 1.0.0
	 */
	public function register_rewrite_rules() {
		$endpoint = $this->get_endpoint();
		add_rewrite_rule(
			'^' . preg_quote( $endpoint, '/' ) . '/?$',
			'index.php?ccf_pwa=1',
			'top'
		);

		// Flush rewrite rules if needed (only on activation or path change).
		if ( get_option( 'ccf_pwa_flush_rewrite' ) ) {
			flush_rewrite_rules();
			delete_option( 'ccf_pwa_flush_rewrite' );
		}
	}

	/**
	 * Register custom query vars
	 *
	 * @since 1.0.0
	 * @param array $vars Existing query vars.
	 * @return array Modified query vars.
	 */
	public function register_query_vars( $vars ) {
		$vars[] = 'ccf_pwa';
		return $vars;
	}

	/**
	 * Handle template redirect for PWA endpoint
	 *
	 * @since 1.0.0
	 */
	public function handle_template_redirect() {
		if ( ! get_query_var( 'ccf_pwa' ) ) {
			return;
		}

		// Load PWA template.
		$this->render_pwa_app();
		exit;
	}

	/**
	 * Serve PWA manifest and service worker files
	 *
	 * @since 1.0.0
	 */
	public function serve_pwa_assets() {
		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';

		// Serve manifest.json.
		if ( strpos( $request_uri, '/ccf-manifest.json' ) !== false ) {
			$this->serve_manifest();
			exit;
		}

		// Serve service-worker.js.
		if ( strpos( $request_uri, '/ccf-sw.js' ) !== false ) {
			$this->serve_service_worker();
			exit;
		}
	}

	/**
	 * Serve the web app manifest
	 *
	 * @since 1.0.0
	 */
	private function serve_manifest() {
		$manifest_path = CLOUD_COVER_FORECAST_PLUGIN_DIR . 'pwa/manifest.json';
		if ( ! file_exists( $manifest_path ) ) {
			status_header( 404 );
			exit;
		}

		$manifest = file_get_contents( $manifest_path );
		$manifest_data = json_decode( $manifest, true );

		// Update start_url and scope to use the configured endpoint.
		$endpoint = $this->get_endpoint();
		$manifest_data['start_url'] = '/' . $endpoint . '/';
		$manifest_data['scope'] = '/' . $endpoint . '/';

		// Update icon paths to absolute URLs.
		if ( isset( $manifest_data['icons'] ) ) {
			foreach ( $manifest_data['icons'] as &$icon ) {
				$icon['src'] = CLOUD_COVER_FORECAST_PLUGIN_URL . 'pwa/' . $icon['src'];
			}
		}

		// Send X-Robots-Tag header if noindex is enabled.
		if ( $this->is_noindex_enabled() ) {
			header( 'X-Robots-Tag: noindex, nofollow', true );
		}

		header( 'Content-Type: application/manifest+json' );
		header( 'Cache-Control: public, max-age=86400' );
		echo wp_json_encode( $manifest_data );
		exit;
	}

	/**
	 * Serve the service worker script
	 *
	 * @since 1.0.0
	 */
	private function serve_service_worker() {
		$sw_path = CLOUD_COVER_FORECAST_PLUGIN_DIR . 'pwa/service-worker.js';
		if ( ! file_exists( $sw_path ) ) {
			status_header( 404 );
			exit;
		}

		// Read and modify the service worker to use the configured endpoint.
		$sw_content = file_get_contents( $sw_path );
		$endpoint = $this->get_endpoint();

		// Replace hardcoded paths with the configured endpoint.
		$sw_content = str_replace( '/forecast-app/', '/' . $endpoint . '/', $sw_content );
		$sw_content = str_replace( '/forecast-app', '/' . $endpoint, $sw_content );

		header( 'Content-Type: application/javascript' );
		header( 'Cache-Control: no-cache' );
		header( 'Service-Worker-Allowed: /' );
		echo $sw_content;
		exit;
	}

	/**
	 * Render the PWA application
	 *
	 * @since 1.0.0
	 */
	private function render_pwa_app() {
		// Send X-Robots-Tag header if noindex is enabled.
		if ( $this->is_noindex_enabled() ) {
			header( 'X-Robots-Tag: noindex, nofollow', true );
		}

		// Make $pwa available to the template.
		$pwa = $this;

		// Load template.
		$template_path = CLOUD_COVER_FORECAST_PLUGIN_DIR . 'templates/pwa-app.php';
		if ( file_exists( $template_path ) ) {
			include $template_path;
		} else {
			wp_die( esc_html__( 'PWA template not found.', 'cloud-cover-forecast' ) );
		}
	}

	/**
	 * AJAX handler for extended forecast
	 *
	 * @since 1.0.0
	 */
	public function ajax_extended_forecast() {
		// Verify nonce if provided.
		$nonce = isset( $_REQUEST['nonce'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['nonce'] ) ) : '';
		if ( ! empty( $nonce ) && ! wp_verify_nonce( $nonce, 'ccf_pwa_nonce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid security token.', 'cloud-cover-forecast' ) ) );
		}

		$lat = isset( $_REQUEST['lat'] ) ? floatval( $_REQUEST['lat'] ) : null;
		$lon = isset( $_REQUEST['lon'] ) ? floatval( $_REQUEST['lon'] ) : null;

		if ( null === $lat || null === $lon ) {
			wp_send_json_error( array( 'message' => __( 'Latitude and longitude are required.', 'cloud-cover-forecast' ) ) );
		}

		$api = $this->plugin->get_api();
		$result = $api->fetch_extended_forecast( $lat, $lon );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		// Add location name if provided.
		$location_name = isset( $_REQUEST['name'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['name'] ) ) : '';
		if ( ! empty( $location_name ) ) {
			$result['location']['name'] = $location_name;
		}

		wp_send_json_success( $result );
	}

	/**
	 * AJAX handler for geocoding
	 *
	 * @since 1.0.0
	 */
	public function ajax_geocode() {
		// Verify nonce if provided.
		$nonce = isset( $_REQUEST['nonce'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['nonce'] ) ) : '';
		if ( ! empty( $nonce ) && ! wp_verify_nonce( $nonce, 'ccf_pwa_nonce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid security token.', 'cloud-cover-forecast' ) ) );
		}

		$query = isset( $_REQUEST['query'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['query'] ) ) : '';

		if ( empty( $query ) ) {
			wp_send_json_error( array( 'message' => __( 'Search query is required.', 'cloud-cover-forecast' ) ) );
		}

		$api = $this->plugin->get_api();
		$result = $api->geocode_location( $query );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success( $result );
	}

	/**
	 * AJAX handler for reverse geocoding (coordinates to location name).
	 *
	 * @since 1.0.0
	 */
	public function ajax_reverse_geocode() {
		// Verify nonce if provided.
		$nonce = isset( $_REQUEST['nonce'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['nonce'] ) ) : '';
		if ( ! empty( $nonce ) && ! wp_verify_nonce( $nonce, 'ccf_pwa_nonce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid security token.', 'cloud-cover-forecast' ) ) );
		}

		$lat = isset( $_REQUEST['lat'] ) ? floatval( $_REQUEST['lat'] ) : null;
		$lon = isset( $_REQUEST['lon'] ) ? floatval( $_REQUEST['lon'] ) : null;

		if ( null === $lat || null === $lon ) {
			wp_send_json_error( array( 'message' => __( 'Latitude and longitude are required.', 'cloud-cover-forecast' ) ) );
		}

		// Validate coordinate ranges
		if ( $lat < -90 || $lat > 90 || $lon < -180 || $lon > 180 ) {
			wp_send_json_error( array( 'message' => __( 'Invalid coordinates.', 'cloud-cover-forecast' ) ) );
		}

		$api = $this->plugin->get_api();
		$result = $api->reverse_geocode( $lat, $lon );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success( $result );
	}

	/**
	 * Get the PWA nonce for AJAX requests
	 *
	 * @since 1.0.0
	 * @return string Nonce value.
	 */
	public function get_nonce() {
		return wp_create_nonce( 'ccf_pwa_nonce' );
	}

	/**
	 * Get the AJAX URL
	 *
	 * @since 1.0.0
	 * @return string AJAX URL.
	 */
	public function get_ajax_url() {
		return admin_url( 'admin-ajax.php' );
	}

	/**
	 * Get the full PWA URL
	 *
	 * @since 1.0.0
	 * @return string Full PWA URL.
	 */
	public function get_pwa_url() {
		return home_url( '/' . $this->get_endpoint() . '/' );
	}

	/**
	 * Get the service worker scope
	 *
	 * @since 1.0.0
	 * @return string Service worker scope.
	 */
	public function get_sw_scope() {
		return '/' . $this->get_endpoint() . '/';
	}

	/**
	 * Flush rewrite rules on plugin activation
	 *
	 * @since 1.0.0
	 */
	public static function activate() {
		update_option( 'ccf_pwa_flush_rewrite', true );
	}

	/**
	 * Clean up on plugin deactivation
	 *
	 * @since 1.0.0
	 */
	public static function deactivate() {
		flush_rewrite_rules();
	}
}
