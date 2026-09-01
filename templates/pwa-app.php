<?php
/**
 * PWA App Template
 *
 * Full-page template for the Cloud Cover Forecast PWA.
 * No WordPress theme chrome - standalone dark-themed app.
 *
 * @package CloudCoverForecast
 * @since 1.0.0
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// $pwa is passed from render_pwa_app() in class-pwa.php
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
	<meta name="theme-color" content="#f5f5f5" media="(prefers-color-scheme: light)">
	<meta name="theme-color" content="#0f172a" media="(prefers-color-scheme: dark)">
	<meta name="apple-mobile-web-app-capable" content="yes">
	<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
	<meta name="apple-mobile-web-app-title" content="<?php echo esc_attr__( 'Cloud Cover', 'cloud-cover-forecast' ); ?>">
	<meta name="description" content="<?php echo esc_attr__( 'Detailed weather forecasting for photographers and astronomers', 'cloud-cover-forecast' ); ?>">
	<?php if ( $pwa->is_noindex_enabled() ) : ?>
	<meta name="robots" content="noindex, nofollow">
	<?php endif; ?>

	<title><?php echo esc_html__( 'Cloud Cover Forecast', 'cloud-cover-forecast' ); ?></title>

	<!-- PWA Manifest -->
	<link rel="manifest" href="<?php echo esc_url( home_url( '/ccf-manifest.json' ) ); ?>">

	<!-- Apple Touch Icons -->
	<link rel="apple-touch-icon" href="<?php echo esc_url( CLOUD_COVER_FORECAST_PLUGIN_URL . 'pwa/icons/icon-192.svg' ); ?>">

	<!-- Favicon -->
	<link rel="icon" type="image/svg+xml" href="<?php echo esc_url( CLOUD_COVER_FORECAST_PLUGIN_URL . 'pwa/icons/icon-192.svg' ); ?>">

	<!-- Stylesheets -->
	<link rel="stylesheet" href="<?php echo esc_url( CLOUD_COVER_FORECAST_PLUGIN_URL . 'assets/css/forecast-app.css?v=' . CLOUD_COVER_FORECAST_VERSION ); ?>">

	<style>
		/* Critical CSS for initial load */
		* { box-sizing: border-box; margin: 0; padding: 0; }
		html, body { height: 100%; overflow: hidden; }
		body {
			font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, sans-serif;
			background: #f5f5f5;
			color: #111827;
		}
		/* Follow the system when the app is set to "auto"... */
		@media (prefers-color-scheme: dark) {
			body { background: #0f172a; color: #f8fafc; }
			.app-loading-spinner { border-color: #334155; border-top-color: #4ade80; }
		}
		/* ...and obey an explicit choice regardless of the system.
		   applyTheme() puts these on <html>. This block is inlined AFTER the
		   stylesheet link, so it wins over `body { color: var(--text-primary) }`
		   in forecast-app.css on equal specificity. Without the class rules,
		   choosing dark on a light system left dark text on a dark background
		   for every element that inherits its colour. Keep these values in step
		   with the tokens in forecast-app.css; tests/theme.test.php checks. */
		.dark-mode body { background: #0f172a; color: #f8fafc; }
		.dark-mode .app-loading-spinner { border-color: #334155; border-top-color: #4ade80; }
		.light-mode body { background: #f5f5f5; color: #111827; }
		.light-mode .app-loading-spinner { border-color: #e5e7eb; border-top-color: #16a34a; }
		#app { display: flex; flex-direction: column; height: 100%; }
		.app-loading {
			display: flex;
			flex-direction: column;
			align-items: center;
			justify-content: center;
			height: 100%;
			gap: 16px;
		}
		.app-loading-spinner {
			width: 48px;
			height: 48px;
			border: 4px solid #e5e7eb;
			border-top-color: #16a34a;
			border-radius: 50%;
			animation: spin 1s linear infinite;
		}
		@keyframes spin { to { transform: rotate(360deg); } }
	</style>
</head>
<body>
	<div id="app">
		<!-- Initial loading state -->
		<div class="app-loading" id="app-loading">
			<div class="app-loading-spinner"></div>
			<p><?php echo esc_html__( 'Loading forecast app...', 'cloud-cover-forecast' ); ?></p>
		</div>
	</div>

	<!-- App Configuration -->
		<script>
			window.CCF_CONFIG = {
				ajaxUrl: <?php echo wp_json_encode( $pwa->get_ajax_url() ); ?>,
				pluginUrl: <?php echo wp_json_encode( CLOUD_COVER_FORECAST_PLUGIN_URL ); ?>,
				strings: {
				appTitle: <?php echo wp_json_encode( __( 'Cloud Cover Forecast', 'cloud-cover-forecast' ) ); ?>,
				home: <?php echo wp_json_encode( __( 'Home', 'cloud-cover-forecast' ) ); ?>,
				current: <?php echo wp_json_encode( __( 'Current', 'cloud-cover-forecast' ) ); ?>,
				locations: <?php echo wp_json_encode( __( 'Locations', 'cloud-cover-forecast' ) ); ?>,
				// View tabs and location switching
				hours: <?php echo wp_json_encode( __( 'Hours', 'cloud-cover-forecast' ) ); ?>,
				outlook: <?php echo wp_json_encode( __( 'Outlook', 'cloud-cover-forecast' ) ); ?>,
				day: <?php echo wp_json_encode( __( 'Day', 'cloud-cover-forecast' ) ); ?>,
				changeLocation: <?php echo wp_json_encode( __( 'Change location', 'cloud-cover-forecast' ) ); ?>,
				useMyLocation: <?php echo wp_json_encode( __( 'Use my location', 'cloud-cover-forecast' ) ); ?>,
				// Score bands
				scoreExcellent: <?php echo wp_json_encode( __( 'Excellent', 'cloud-cover-forecast' ) ); ?>,
				scoreGood: <?php echo wp_json_encode( __( 'Good', 'cloud-cover-forecast' ) ); ?>,
				scoreFair: <?php echo wp_json_encode( __( 'Fair', 'cloud-cover-forecast' ) ); ?>,
				scorePoor: <?php echo wp_json_encode( __( 'Poor', 'cloud-cover-forecast' ) ); ?>,
				past: <?php echo wp_json_encode( __( 'already passed', 'cloud-cover-forecast' ) ); ?>,
				// Light phases
				firstLight: <?php echo wp_json_encode( __( 'First Light', 'cloud-cover-forecast' ) ); ?>,
				blueHour: <?php echo wp_json_encode( __( 'Blue Hour', 'cloud-cover-forecast' ) ); ?>,
				goldenHour: <?php echo wp_json_encode( __( 'Golden Hour', 'cloud-cover-forecast' ) ); ?>,
				daytime: <?php echo wp_json_encode( __( 'Daytime', 'cloud-cover-forecast' ) ); ?>,
				lastLight: <?php echo wp_json_encode( __( 'Last Light', 'cloud-cover-forecast' ) ); ?>,
				loading: <?php echo wp_json_encode( __( 'Loading...', 'cloud-cover-forecast' ) ); ?>,
				error: <?php echo wp_json_encode( __( 'Error', 'cloud-cover-forecast' ) ); ?>,
				retry: <?php echo wp_json_encode( __( 'Retry', 'cloud-cover-forecast' ) ); ?>,
				offline: <?php echo wp_json_encode( __( 'You are offline', 'cloud-cover-forecast' ) ); ?>,
				searchLocation: <?php echo wp_json_encode( __( 'Search location...', 'cloud-cover-forecast' ) ); ?>,
				addLocation: <?php echo wp_json_encode( __( 'Add Location', 'cloud-cover-forecast' ) ); ?>,
				setAsHome: <?php echo wp_json_encode( __( 'Set as Home', 'cloud-cover-forecast' ) ); ?>,
				delete: <?php echo wp_json_encode( __( 'Delete', 'cloud-cover-forecast' ) ); ?>,
				noLocations: <?php echo wp_json_encode( __( 'No saved locations', 'cloud-cover-forecast' ) ); ?>,
				addFirstLocation: <?php echo wp_json_encode( __( 'Add your first location to get started', 'cloud-cover-forecast' ) ); ?>,
				gettingLocation: <?php echo wp_json_encode( __( 'Getting your location...', 'cloud-cover-forecast' ) ); ?>,
				locationDenied: <?php echo wp_json_encode( __( 'Location access denied', 'cloud-cover-forecast' ) ); ?>,
				enableLocation: <?php echo wp_json_encode( __( 'Enable location access to use this feature', 'cloud-cover-forecast' ) ); ?>,
				noHomeLocation: <?php echo wp_json_encode( __( 'No home location set', 'cloud-cover-forecast' ) ); ?>,
				goToLocations: <?php echo wp_json_encode( __( 'Go to Locations', 'cloud-cover-forecast' ) ); ?>,
				// Weather data labels
				clouds: <?php echo wp_json_encode( __( 'Clouds', 'cloud-cover-forecast' ) ); ?>,
				total: <?php echo wp_json_encode( __( 'Total', 'cloud-cover-forecast' ) ); ?>,
				low: <?php echo wp_json_encode( __( 'Low', 'cloud-cover-forecast' ) ); ?>,
				mid: <?php echo wp_json_encode( __( 'Mid', 'cloud-cover-forecast' ) ); ?>,
				high: <?php echo wp_json_encode( __( 'High', 'cloud-cover-forecast' ) ); ?>,
				sun: <?php echo wp_json_encode( __( 'Sun', 'cloud-cover-forecast' ) ); ?>,
				moon: <?php echo wp_json_encode( __( 'Moon', 'cloud-cover-forecast' ) ); ?>,
				rain: <?php echo wp_json_encode( __( 'Rain', 'cloud-cover-forecast' ) ); ?>,
				chance: <?php echo wp_json_encode( __( 'Chance', 'cloud-cover-forecast' ) ); ?>,
				amount: <?php echo wp_json_encode( __( 'Amount', 'cloud-cover-forecast' ) ); ?>,
				wind: <?php echo wp_json_encode( __( 'Wind', 'cloud-cover-forecast' ) ); ?>,
				visibility: <?php echo wp_json_encode( __( 'Visibility', 'cloud-cover-forecast' ) ); ?>,
				fog: <?php echo wp_json_encode( __( 'Fog', 'cloud-cover-forecast' ) ); ?>,
				temp: <?php echo wp_json_encode( __( 'Temp', 'cloud-cover-forecast' ) ); ?>,
				actual: <?php echo wp_json_encode( __( 'Actual', 'cloud-cover-forecast' ) ); ?>,
				feelsLike: <?php echo wp_json_encode( __( 'Feels Like', 'cloud-cover-forecast' ) ); ?>,
				dewPoint: <?php echo wp_json_encode( __( 'Dew Point', 'cloud-cover-forecast' ) ); ?>,
				humidity: <?php echo wp_json_encode( __( 'Humidity', 'cloud-cover-forecast' ) ); ?>,
				frost: <?php echo wp_json_encode( __( 'Frost', 'cloud-cover-forecast' ) ); ?>,
				sunrise: <?php echo wp_json_encode( __( 'Sunrise', 'cloud-cover-forecast' ) ); ?>,
				sunset: <?php echo wp_json_encode( __( 'Sunset', 'cloud-cover-forecast' ) ); ?>,
				moonrise: <?php echo wp_json_encode( __( 'Moonrise', 'cloud-cover-forecast' ) ); ?>,
				moonset: <?php echo wp_json_encode( __( 'Moonset', 'cloud-cover-forecast' ) ); ?>,
				phase: <?php echo wp_json_encode( __( 'Phase', 'cloud-cover-forecast' ) ); ?>,
				illumination: <?php echo wp_json_encode( __( 'Illumination', 'cloud-cover-forecast' ) ); ?>,
				// Time labels
				now: <?php echo wp_json_encode( __( 'Now', 'cloud-cover-forecast' ) ); ?>,
				today: <?php echo wp_json_encode( __( 'Today', 'cloud-cover-forecast' ) ); ?>,
				tomorrow: <?php echo wp_json_encode( __( 'Tomorrow', 'cloud-cover-forecast' ) ); ?>,
				// Install labels
				installApp: <?php echo wp_json_encode( __( 'Install App', 'cloud-cover-forecast' ) ); ?>,
				installTitle: <?php echo wp_json_encode( __( 'Install App', 'cloud-cover-forecast' ) ); ?>,
				installDescription: <?php echo wp_json_encode( __( 'Add this app to your home screen for quick access.', 'cloud-cover-forecast' ) ); ?>,
				installStep1Safari: <?php echo wp_json_encode( __( 'Tap the Share button', 'cloud-cover-forecast' ) ); ?>,
				installStep2Safari: <?php echo wp_json_encode( __( 'Scroll down and tap "Add to Home Screen"', 'cloud-cover-forecast' ) ); ?>,
				installStep3Safari: <?php echo wp_json_encode( __( 'Tap "Add" in the top right', 'cloud-cover-forecast' ) ); ?>,
				installStep1ChromeIOS: <?php echo wp_json_encode( __( 'Tap the Share button', 'cloud-cover-forecast' ) ); ?>,
				installStep2ChromeIOS: <?php echo wp_json_encode( __( 'Tap "Add to Home Screen"', 'cloud-cover-forecast' ) ); ?>,
				installStep3ChromeIOS: <?php echo wp_json_encode( __( 'Tap "Add" to confirm', 'cloud-cover-forecast' ) ); ?>,
				installStep1FirefoxIOS: <?php echo wp_json_encode( __( 'Tap the menu button', 'cloud-cover-forecast' ) ); ?>,
				installStep2FirefoxIOS: <?php echo wp_json_encode( __( 'Tap "Share"', 'cloud-cover-forecast' ) ); ?>,
				installStep3FirefoxIOS: <?php echo wp_json_encode( __( 'Tap "Add to Home Screen"', 'cloud-cover-forecast' ) ); ?>,
				installStepGenericIOS: <?php echo wp_json_encode( __( 'Open this page in Safari, then tap Share and "Add to Home Screen"', 'cloud-cover-forecast' ) ); ?>,
				installStep1Firefox: <?php echo wp_json_encode( __( 'Tap the menu button', 'cloud-cover-forecast' ) ); ?>,
				installStep2Firefox: <?php echo wp_json_encode( __( 'Tap "Install"', 'cloud-cover-forecast' ) ); ?>,
				installStep1Generic: <?php echo wp_json_encode( __( 'Tap the browser menu', 'cloud-cover-forecast' ) ); ?>,
				installStep2Generic: <?php echo wp_json_encode( __( 'Look for "Install app" or "Add to Home Screen"', 'cloud-cover-forecast' ) ); ?>,
				// Share labels
				share: <?php echo wp_json_encode( __( 'Share', 'cloud-cover-forecast' ) ); ?>,
				shareLocationTitle: <?php echo wp_json_encode( __( 'Cloud Cover Forecast', 'cloud-cover-forecast' ) ); ?>,
				linkCopiedToClipboard: <?php echo wp_json_encode( __( 'Link copied to clipboard', 'cloud-cover-forecast' ) ); ?>,
				copyLink: <?php echo wp_json_encode( __( 'Copy link:', 'cloud-cover-forecast' ) ); ?>,
				// Font size label
				fontSize: <?php echo wp_json_encode( __( 'Font size', 'cloud-cover-forecast' ) ); ?>,
				// Photo score label
				photoScore: <?php echo wp_json_encode( __( 'Photo', 'cloud-cover-forecast' ) ); ?>,
				// Dual-source confidence
				cloudBySource: <?php echo wp_json_encode( __( 'Cloud by source', 'cloud-cover-forecast' ) ); ?>,
				sourceOpenMeteo: <?php echo wp_json_encode( __( 'Open-Meteo', 'cloud-cover-forecast' ) ); ?>,
				sourceMetNo: <?php echo wp_json_encode( __( 'Met.no', 'cloud-cover-forecast' ) ); ?>,
				bandsDiffer: <?php echo wp_json_encode( __( 'The two sources divide the sky at different altitudes, so only the high row is compared in the score.', 'cloud-cover-forecast' ) ); ?>,
				secondSourceUnavailable: <?php echo wp_json_encode( __( 'Second forecast source unavailable', 'cloud-cover-forecast' ) ); ?>,
				oneSource: <?php echo wp_json_encode( __( 'one source', 'cloud-cover-forecast' ) ); ?>,
				twoSources: <?php echo wp_json_encode( __( 'two sources', 'cloud-cover-forecast' ) ); ?>,
				twoSourcesHorizonClosed: <?php echo wp_json_encode( __( 'two sources, horizon closed', 'cloud-cover-forecast' ) ); ?>,
				horizonClosed: <?php echo wp_json_encode( __( 'Low cloud closed the horizon here, so the high row could not change the score. The two numbers match because the comparison had nothing to act on, not because the sources agree.', 'cloud-cover-forecast' ) ); ?>,
				/* translators: 1: low score, 2: high score. */
				scoreRange: <?php echo wp_json_encode( __( '%1$s to %2$s percent', 'cloud-cover-forecast' ) ); ?>,
				// Jump button labels
				jumpToNow: <?php echo wp_json_encode( __( 'Jump to now', 'cloud-cover-forecast' ) ); ?>,
				previousDay: <?php echo wp_json_encode( __( 'Previous day', 'cloud-cover-forecast' ) ); ?>,
				nextDay: <?php echo wp_json_encode( __( 'Next day', 'cloud-cover-forecast' ) ); ?>,
				// Generic actions
				close: <?php echo wp_json_encode( __( 'Close', 'cloud-cover-forecast' ) ); ?>,
				cancel: <?php echo wp_json_encode( __( 'Cancel', 'cloud-cover-forecast' ) ); ?>,
				save: <?php echo wp_json_encode( __( 'Save', 'cloud-cover-forecast' ) ); ?>,
				edit: <?php echo wp_json_encode( __( 'Edit', 'cloud-cover-forecast' ) ); ?>,
				saved: <?php echo wp_json_encode( __( 'Saved', 'cloud-cover-forecast' ) ); ?>,
				skipped: <?php echo wp_json_encode( __( 'skipped', 'cloud-cover-forecast' ) ); ?>,
				optional: <?php echo wp_json_encode( __( 'optional', 'cloud-cover-forecast' ) ); ?>,
				// Edit location form
				editLocation: <?php echo wp_json_encode( __( 'Edit Location', 'cloud-cover-forecast' ) ); ?>,
				name: <?php echo wp_json_encode( __( 'Name', 'cloud-cover-forecast' ) ); ?>,
				region: <?php echo wp_json_encode( __( 'Region', 'cloud-cover-forecast' ) ); ?>,
				regionPlaceholder: <?php echo wp_json_encode( __( 'State, province, or region', 'cloud-cover-forecast' ) ); ?>,
				notes: <?php echo wp_json_encode( __( 'Notes', 'cloud-cover-forecast' ) ); ?>,
				notesPlaceholder: <?php echo wp_json_encode( __( 'Add notes about this location...', 'cloud-cover-forecast' ) ); ?>,
				saveLocation: <?php echo wp_json_encode( __( 'Save Location', 'cloud-cover-forecast' ) ); ?>,
				locationSaved: <?php echo wp_json_encode( __( 'Location saved', 'cloud-cover-forecast' ) ); ?>,
				// Import / export
				export: <?php echo wp_json_encode( __( 'Export', 'cloud-cover-forecast' ) ); ?>,
				exportLocations: <?php echo wp_json_encode( __( 'Export', 'cloud-cover-forecast' ) ); ?>,
				import: <?php echo wp_json_encode( __( 'Import', 'cloud-cover-forecast' ) ); ?>,
				importLocations: <?php echo wp_json_encode( __( 'Import', 'cloud-cover-forecast' ) ); ?>,
				importedLocations: <?php echo wp_json_encode( __( 'Imported', 'cloud-cover-forecast' ) ); ?>,
				noLocationsToExport: <?php echo wp_json_encode( __( 'No locations to export', 'cloud-cover-forecast' ) ); ?>,
				noNewLocations: <?php echo wp_json_encode( __( 'No new locations to import', 'cloud-cover-forecast' ) ); ?>,
				exportCopiedToClipboard: <?php echo wp_json_encode( __( 'Locations copied to clipboard. Paste into a text file to save.', 'cloud-cover-forecast' ) ); ?>,
				exportFailed: <?php echo wp_json_encode( __( 'Export failed. Would you like to see the data to copy manually?', 'cloud-cover-forecast' ) ); ?>,
				importError: <?php echo wp_json_encode( __( 'Failed to import locations. Please check the file format.', 'cloud-cover-forecast' ) ); ?>,
			}
		};
	</script>

	<!-- Scoring and Light Phases (must load before the app) -->
	<script src="<?php echo esc_url( CLOUD_COVER_FORECAST_PLUGIN_URL . 'assets/js/forecast-scoring.js?v=' . CLOUD_COVER_FORECAST_VERSION ); ?>"></script>

	<!-- Storage Layer -->
	<script src="<?php echo esc_url( CLOUD_COVER_FORECAST_PLUGIN_URL . 'assets/js/forecast-storage.js?v=' . CLOUD_COVER_FORECAST_VERSION ); ?>"></script>

	<!-- Main Application -->
	<script src="<?php echo esc_url( CLOUD_COVER_FORECAST_PLUGIN_URL . 'assets/js/forecast-app.js?v=' . CLOUD_COVER_FORECAST_VERSION ); ?>"></script>

	<!-- Service Worker Registration -->
	<script>
		if ('serviceWorker' in navigator) {
			window.addEventListener('load', function() {
				navigator.serviceWorker.register('<?php echo esc_url( home_url( '/ccf-sw.js' ) ); ?>', { scope: '<?php echo esc_js( $pwa->get_sw_scope() ); ?>' })
					.then(function(registration) {
						console.log('ServiceWorker registered:', registration.scope);
					})
					.catch(function(error) {
						console.log('ServiceWorker registration failed:', error);
					});
			});
		}
	</script>
</body>
</html>
