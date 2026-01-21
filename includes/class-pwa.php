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
	 * PWA endpoint slug
	 *
	 * @since 1.0.0
	 * @var string
	 */
	const ENDPOINT = 'forecast-app';

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

		// Serve manifest and service worker.
		add_action( 'init', array( $this, 'serve_pwa_assets' ) );
	}

	/**
	 * Register rewrite rules for the PWA endpoint
	 *
	 * @since 1.0.0
	 */
	public function register_rewrite_rules() {
		add_rewrite_rule(
			'^' . self::ENDPOINT . '/?$',
			'index.php?ccf_pwa=1',
			'top'
		);

		// Flush rewrite rules if needed (only on activation).
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

		// Update icon paths to absolute URLs.
		if ( isset( $manifest_data['icons'] ) ) {
			foreach ( $manifest_data['icons'] as &$icon ) {
				$icon['src'] = CLOUD_COVER_FORECAST_PLUGIN_URL . 'pwa/' . $icon['src'];
			}
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

		header( 'Content-Type: application/javascript' );
		header( 'Cache-Control: no-cache' );
		header( 'Service-Worker-Allowed: /' );
		readfile( $sw_path );
		exit;
	}

	/**
	 * Render the PWA application
	 *
	 * @since 1.0.0
	 */
	private function render_pwa_app() {
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
