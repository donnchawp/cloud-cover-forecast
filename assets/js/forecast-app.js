/**
 * Cloud Cover Forecast - Main PWA Application
 *
 * A Progressive Web App for detailed weather forecasting
 * for photographers and astronomers.
 *
 * @package CloudCoverForecast
 * @since 1.0.0
 */

(function (global) {
  'use strict';

  const { CCF_CONFIG, ForecastStorage, ForecastScoring } = global;
  const { ajaxUrl, strings } = CCF_CONFIG;

  // A page cached before forecast-scoring.js existed will not have loaded it.
  // Say so, rather than throwing on the destructure below and leaving the
  // loading spinner up for ever.
  if (!ForecastScoring) {
    const container = document.getElementById('app');
    if (container) {
      container.innerHTML = '<div class="empty-state">'
        + '<h2>' + (strings.error || 'Error') + '</h2>'
        + '<p>' + (strings.retry || 'Retry') + '</p>'
        + '<button class="btn btn-primary" onclick="location.reload(true)">'
        + (strings.retry || 'Retry') + '</button></div>';
    }
    return;
  }

  // Scoring, light-phase classification and time parsing live in
  // forecast-scoring.js, which must load before this file.
  const {
    parseTimeToTimestamp,
    parseHourTimestamp,
    nowInTimezone,
    findHourIndex,
    getSunlightClass,
    calculatePhotoScore,
    calculateWindowScore,
    getScoreClass,
    getScoreLabel,
    sunriseSunsetScore,
  } = ForecastScoring;

  // ============================================================
  // APP STATE
  // ============================================================

  const state = {
    // Which view of the selected location is showing.
    activeView: 'hours',
    // The location every view renders. Set from the home location at launch,
    // from a shared URL, from the picker, or from GPS.
    selectedLocation: null,
    // Day the Day view is showing, as an index into forecast.daily.
    selectedDayIndex: 0,
    showLocationPicker: false,
    // The designated home. Loaded at launch; not the same thing as whatever
    // location happens to be selected.
    homeLocation: null,
    savedLocations: [],
    forecastData: {},
    isLoading: false,
    error: null,
    isOnline: navigator.onLine,
    searchResults: [],
    isSearching: false,
    theme: localStorage.getItem('ccf-theme') || 'auto',
    fontSize: localStorage.getItem('ccf-font-size') || 'medium',
    // PWA Install state
    deferredInstallPrompt: null,
    showInstallInstructions: false,
    // Edit location state
    editingLocation: null,
  };

  // The three view tabs, in bar order.
  const VIEWS = ['hours', 'outlook', 'day'];

  /**
   * Key a location into state.forecastData.
   *
   * Saved locations key on their id; anything unsaved (GPS, a shared URL)
   * keys on rounded coordinates, so the same place resolves to the same
   * cache entry however it was chosen.
   *
   * @param {Object} location - Location object.
   * @returns {string|number|null} Cache key.
   */
  function forecastKey(location) {
    if (!location) return null;
    if (location.id) return location.id;
    return location.lat.toFixed(4) + ',' + location.lon.toFixed(4);
  }

  /**
   * The forecast for the selected location, if it has been fetched.
   * @returns {Object|null} Forecast data.
   */
  function selectedForecast() {
    const key = forecastKey(state.selectedLocation);
    return key === null ? null : (state.forecastData[key] || null);
  }

  /**
   * Display name for a location.
   * @param {Object} location - Location object.
   * @returns {string} Name.
   */
  function locationDisplayName(location) {
    if (!location) return '';
    if (location.admin1) return location.name + ', ' + location.admin1;
    return location.name || location.lat.toFixed(2) + ', ' + location.lon.toFixed(2);
  }

  // Debug mode - enable with ?debug=1 in URL
  const DEBUG_MODE = new URLSearchParams(window.location.search).has('debug');
  const debugLog = [];

  function addDebug(message) {
    const timestamp = new Date().toLocaleTimeString();
    debugLog.push(`[${timestamp}] ${message}`);
    if (debugLog.length > 20) debugLog.shift(); // Keep last 20 messages
    updateDebugPanel();
  }

  function updateDebugPanel() {
    if (!DEBUG_MODE) return;
    let panel = document.getElementById('ccf-debug-panel');
    if (!panel) {
      panel = document.createElement('div');
      panel.id = 'ccf-debug-panel';
      panel.style.cssText = 'position:fixed;bottom:0;left:0;right:0;max-height:40vh;overflow-y:auto;background:rgba(0,0,0,0.9);color:#0f0;font-family:monospace;font-size:11px;padding:8px;z-index:99999;white-space:pre-wrap;';
      document.body.appendChild(panel);
    }
    panel.textContent = debugLog.join('\n');
    panel.scrollTop = panel.scrollHeight;
  }

  // ============================================================
  // PWA INSTALL DETECTION
  // ============================================================

  /**
   * Detect if app is running in standalone/installed mode.
   * @returns {boolean} True if installed.
   */
  function isAppInstalled() {
    const standaloneMedia = window.matchMedia('(display-mode: standalone)').matches;
    const iosStandalone = window.navigator.standalone === true;
    const fullscreenMedia = window.matchMedia('(display-mode: fullscreen)').matches;
    const minimalUiMedia = window.matchMedia('(display-mode: minimal-ui)').matches;

    const isInstalled = standaloneMedia || iosStandalone || fullscreenMedia || minimalUiMedia;

    addDebug(`isAppInstalled: standalone=${standaloneMedia} iosStandalone=${iosStandalone} fullscreen=${fullscreenMedia} minimalUi=${minimalUiMedia} => ${isInstalled}`);

    return isInstalled;
  }

  /**
   * Detect if running on iOS.
   * @returns {boolean} True if iOS.
   */
  function isIOS() {
    return /iPad|iPhone|iPod/.test(navigator.userAgent) && !window.MSStream;
  }

  /**
   * Detect if running on Android.
   * @returns {boolean} True if Android.
   */
  function isAndroid() {
    return /Android/.test(navigator.userAgent);
  }

  /**
   * Detect if browser supports native install prompt (Chrome/Edge/Samsung).
   * @returns {boolean} True if supported.
   */
  function supportsNativeInstall() {
    return 'BeforeInstallPromptEvent' in window || state.deferredInstallPrompt !== null;
  }

  /**
   * Check if browser supports native install but needs manual instructions.
   * @returns {boolean} True if browser needs manual instructions.
   */
  function needsManualInstallInstructions() {
    const browser = getBrowserType();
    // iOS always needs manual instructions (no beforeinstallprompt support)
    if (isIOS()) return true;
    // Firefox on Android needs manual instructions
    if (browser === 'firefox') return true;
    // Other browsers (Chrome, Edge, Samsung) support native install
    return false;
  }

  /**
   * Check if we should show the install button.
   * @returns {boolean} True if install button should be shown.
   */
  function shouldShowInstallButton() {
    const installed = isAppInstalled();
    const hasPrompt = !!state.deferredInstallPrompt;
    const needsManual = needsManualInstallInstructions();
    const ios = isIOS();
    const android = isAndroid();
    const isMobile = ios || android;

    let shouldShow = false;
    let reason = '';

    if (installed) {
      reason = 'App is installed';
      shouldShow = false;
    } else if (hasPrompt) {
      reason = 'Native install prompt available';
      shouldShow = true;
    } else if (needsManual && isMobile) {
      reason = 'Mobile browser needs manual instructions';
      shouldShow = true;
    } else if (isMobile) {
      // Show on mobile even without deferred prompt - user might have dismissed before
      // They can still install via browser menu, and we can show instructions
      reason = 'Mobile browser (fallback - show instructions)';
      shouldShow = true;
    } else {
      reason = 'Desktop browser without native prompt';
      shouldShow = false;
    }

    addDebug(`shouldShowInstallButton: installed=${installed} hasPrompt=${hasPrompt} iOS=${ios} Android=${android} => ${shouldShow} (${reason})`);

    return shouldShow;
  }

  /**
   * Get the browser name for install instructions.
   * @returns {string} Browser identifier.
   */
  function getBrowserType() {
    const ua = navigator.userAgent;
    // Order matters - check specific browsers before generic ones
    if (/SamsungBrowser/.test(ua)) return 'samsung';
    if (/CriOS/.test(ua)) return 'chrome-ios';
    if (/Edg/.test(ua)) return 'edge';
    if (/Firefox/.test(ua)) return 'firefox';
    if (/Chrome/.test(ua)) return 'chrome';
    if (/Safari/.test(ua)) return 'safari';
    return 'other';
  }

  // Listen for the beforeinstallprompt event (Chrome/Edge/Samsung)
  window.addEventListener('beforeinstallprompt', (e) => {
    e.preventDefault();
    state.deferredInstallPrompt = e;
    renderApp();
  });

  // Listen for successful install
  window.addEventListener('appinstalled', () => {
    state.deferredInstallPrompt = null;
    state.showInstallInstructions = false;
    renderApp();
  });

  // ============================================================
  // UTILITY FUNCTIONS
  // ============================================================

  /**
   * Make an AJAX request.
   * @param {string} action - AJAX action name.
   * @param {Object} data - Request data.
   * @returns {Promise<Object>} Response data.
   */
  async function ajax(action, data = {}) {
    const params = new URLSearchParams({
      action,
      ...data,
    });

    const response = await fetch(ajaxUrl, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: params,
    });

    if (!response.ok) {
      throw new Error(`HTTP ${response.status}`);
    }

    const json = await response.json();
    if (!json.success) {
      throw new Error(json.data?.message || 'Request failed');
    }

    return json.data;
  }

  /**
   * Escape HTML entities.
   * @param {string} str - String to escape.
   * @returns {string} Escaped string.
   */
  function escapeHtml(str) {
    if (str == null) return '';
    const div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
  }

  /**
   * Format a date/time string.
   * @param {string} isoString - ISO date string.
   * @param {string} format - Format type ('time', 'date', 'datetime', 'day').
   * @param {string} timezone - Optional timezone identifier (e.g., 'America/Los_Angeles').
   * @returns {string} Formatted string.
   */
  function formatDateTime(isoString, format = 'time', timezone = undefined) {
    const date = new Date(isoString);
    const tzOption = timezone ? { timeZone: timezone } : {};

    switch (format) {
      case 'time':
        return date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', hour12: false, ...tzOption });
      case 'date':
        return date.toLocaleDateString([], { weekday: 'short', day: 'numeric', month: 'short', ...tzOption });
      case 'datetime':
        return date.toLocaleString([], {
          weekday: 'short',
          day: 'numeric',
          month: 'short',
          hour: '2-digit',
          minute: '2-digit',
          hour12: false,
          ...tzOption,
        });
      case 'day':
        return date.toLocaleDateString([], { weekday: 'short', ...tzOption });
      case 'hour':
        return date.toLocaleTimeString([], { hour: '2-digit', hour12: false, ...tzOption });
      default:
        return isoString;
    }
  }

  /**
   * Get wind direction arrow and label.
   * @param {number} degrees - Wind direction in degrees.
   * @returns {Object} Arrow and label.
   */
  function getWindDirection(degrees) {
    if (degrees == null) return { arrow: '', label: '' };
    const directions = ['N', 'NE', 'E', 'SE', 'S', 'SW', 'W', 'NW'];
    const arrows = ['\u2193', '\u2199', '\u2190', '\u2196', '\u2191', '\u2197', '\u2192', '\u2198'];
    const index = Math.round(degrees / 45) % 8;
    return { arrow: arrows[index], label: directions[index] };
  }

  /**
   * Get color class for a value based on thresholds.
   * @param {number} value - Value to check.
   * @param {Array} thresholds - Array of [max, class] pairs.
   * @returns {string} CSS class.
   */
  function getColorClass(value, thresholds) {
    if (value == null) return '';
    for (const [max, cls] of thresholds) {
      if (value <= max) return cls;
    }
    return thresholds[thresholds.length - 1][1];
  }

  /**
   * Debounce a function.
   * @param {Function} fn - Function to debounce.
   * @param {number} delay - Delay in ms.
   * @returns {Function} Debounced function.
   */
  function debounce(fn, delay) {
    let timeout;
    return function (...args) {
      clearTimeout(timeout);
      timeout = setTimeout(() => fn.apply(this, args), delay);
    };
  }

  /**
   * Get Google Maps URL for coordinates.
   * @param {number} lat - Latitude.
   * @param {number} lon - Longitude.
   * @returns {string} Google Maps URL.
   */
  function getGoogleMapsUrl(lat, lon) {
    return `https://www.google.com/maps?q=${lat},${lon}`;
  }

  // ============================================================
  // THEME MANAGEMENT
  // ============================================================

  /**
   * Apply the current theme to the document.
   */
  function applyTheme() {
    const html = document.documentElement;
    html.classList.remove('light-mode', 'dark-mode');

    if (state.theme === 'light') {
      html.classList.add('light-mode');
    } else if (state.theme === 'dark') {
      html.classList.add('dark-mode');
    }
    // 'auto' uses prefers-color-scheme media query (no class needed)

    applyThemeColor();
  }

  // Must match --bg-primary in forecast-app.css and the critical CSS in
  // pwa-app.php. tests/theme.test.php checks those two against each other.
  const THEME_COLOR = { light: '#f5f5f5', dark: '#0f172a' };

  /**
   * Keep the browser's theme-color in step with an explicit theme choice.
   *
   * The two meta tags are qualified by prefers-color-scheme, so on their own
   * they follow the system and ignore the toggle — choosing dark on a light
   * phone left the status bar light. Pointing both at the chosen colour makes
   * whichever one matches give the right answer.
   */
  function applyThemeColor() {
    const light = document.querySelector('meta[name="theme-color"][media*="light"]');
    const dark = document.querySelector('meta[name="theme-color"][media*="dark"]');
    if (!light || !dark) return;

    if (state.theme === 'dark' || state.theme === 'light') {
      light.setAttribute('content', THEME_COLOR[state.theme]);
      dark.setAttribute('content', THEME_COLOR[state.theme]);
      return;
    }

    light.setAttribute('content', THEME_COLOR.light);
    dark.setAttribute('content', THEME_COLOR.dark);
  }

  /**
   * Toggle between themes: auto -> light -> dark -> auto.
   */
  function toggleTheme() {
    // Save scroll position before re-render
    const gridData = document.getElementById('grid-data');
    const scrollLeft = gridData ? gridData.scrollLeft : 0;

    const themes = ['auto', 'light', 'dark'];
    const currentIndex = themes.indexOf(state.theme);
    state.theme = themes[(currentIndex + 1) % themes.length];
    localStorage.setItem('ccf-theme', state.theme);
    applyTheme();
    renderApp();

    // Restore scroll position after re-render
    requestAnimationFrame(() => {
      const newGridData = document.getElementById('grid-data');
      if (newGridData && scrollLeft > 0) {
        newGridData.scrollLeft = scrollLeft;
      }
    });
  }

  /**
   * Get the icon for the current theme.
   * @returns {string} Theme icon.
   */
  function getThemeIcon() {
    switch (state.theme) {
      case 'light': return '&#9728;'; // Sun
      case 'dark': return '&#9790;'; // Moon
      default: return '&#9788;'; // Sun with rays (auto)
    }
  }

  // ============================================================
  // FONT SIZE SETTINGS
  // ============================================================

  /**
   * Apply the current font size to the document.
   */
  function applyFontSize() {
    const html = document.documentElement;
    html.classList.remove('font-small', 'font-medium', 'font-large', 'font-xlarge', 'font-xxlarge');
    html.classList.add(`font-${state.fontSize}`);
  }

  /**
   * Toggle between font sizes: small -> medium -> large -> xlarge -> xxlarge -> small.
   */
  function toggleFontSize() {
    // Save scroll position before re-render
    const gridData = document.getElementById('grid-data');
    const scrollLeft = gridData ? gridData.scrollLeft : 0;

    const sizes = ['small', 'medium', 'large', 'xlarge', 'xxlarge'];
    let currentIndex = sizes.indexOf(state.fontSize);
    if (currentIndex === -1) currentIndex = 1; // Default to medium if not found
    state.fontSize = sizes[(currentIndex + 1) % sizes.length];
    localStorage.setItem('ccf-font-size', state.fontSize);
    applyFontSize();
    renderApp();

    // Restore scroll position after re-render
    requestAnimationFrame(() => {
      const newGridData = document.getElementById('grid-data');
      if (newGridData && scrollLeft > 0) {
        newGridData.scrollLeft = scrollLeft;
      }
    });
  }

  /**
   * Get the label for the current font size.
   * @returns {string} Font size label.
   */
  function getFontSizeLabel() {
    switch (state.fontSize) {
      case 'small': return 'A';
      case 'large': return 'A';
      default: return 'A'; // medium
    }
  }

  /**
   * Get the CSS class for font size icon styling.
   * @returns {string} CSS class.
   */
  function getFontSizeClass() {
    switch (state.fontSize) {
      case 'small': return 'font-size-icon-small';
      case 'large': return 'font-size-icon-large';
      case 'xlarge': return 'font-size-icon-xlarge';
      case 'xxlarge': return 'font-size-icon-xxlarge';
      default: return 'font-size-icon-medium';
    }
  }

  // ============================================================
  // COLOR SCHEMES
  // ============================================================

  const COLOR_THRESHOLDS = {
    cloud: [[25, 'excellent'], [50, 'good'], [75, 'fair'], [100, 'poor']],
    rain: [[10, 'excellent'], [30, 'good'], [60, 'fair'], [100, 'poor']],
    humidity: [[70, 'excellent'], [80, 'good'], [90, 'fair'], [100, 'poor']],
    wind: [[15, 'excellent'], [30, 'good'], [50, 'fair'], [200, 'poor']],
    visibility: [[1000, 'poor'], [5000, 'fair'], [10000, 'good'], [Infinity, 'excellent']],
  };

  /**
   * Get cloud description text.
   * @param {number} cloudTotal - Total cloud coverage percentage.
   * @returns {string} Description.
   */
  function getCloudDescription(cloudTotal) {
    if (cloudTotal <= 10) return 'Clear';
    if (cloudTotal <= 25) return 'Mostly clear';
    if (cloudTotal <= 50) return 'Partly cloudy';
    if (cloudTotal <= 75) return 'Mostly cloudy';
    return 'Overcast';
  }

  // ============================================================
  // API FUNCTIONS
  // ============================================================

  /**
   * Fetch extended forecast for a location.
   * @param {Object} location - Location with lat, lon, name.
   * @returns {Promise<Object>} Forecast data.
   */
  async function fetchForecast(location) {
    // Check cache first.
    if (location.id) {
      const cached = await ForecastStorage.getCachedForecast(location.id);
      if (cached) {
        return cached;
      }
    }

    const data = await ajax('ccf_pwa_forecast', {
      lat: location.lat,
      lon: location.lon,
      name: location.name || '',
    });

    // Cache the result.
    if (location.id) {
      await ForecastStorage.cacheForecast(location.id, data);
    }

    return data;
  }

  /**
   * Search for locations.
   * @param {string} query - Search query.
   * @returns {Promise<Array>} Array of location results.
   */
  async function searchLocations(query) {
    const data = await ajax('ccf_pwa_geocode', { query });
    // Normalize to array.
    return Array.isArray(data) ? data : [data];
  }

  // ============================================================
  // GEOLOCATION
  // ============================================================

  /**
   * Get current GPS position.
   * @returns {Promise<Object>} Position with lat, lon.
   */
  function getCurrentPosition() {
    return new Promise((resolve, reject) => {
      if (!navigator.geolocation) {
        reject(new Error('Geolocation not supported'));
        return;
      }

      navigator.geolocation.getCurrentPosition(
        (position) => {
          resolve({
            lat: position.coords.latitude,
            lon: position.coords.longitude,
          });
        },
        (error) => {
          reject(error);
        },
        { enableHighAccuracy: true, timeout: 10000, maximumAge: 60000 }
      );
    });
  }

  /**
   * Reverse geocode coordinates to location object.
   * @param {number} lat - Latitude.
   * @param {number} lon - Longitude.
   * @returns {Promise<Object>} Location object with name, admin1, country, timezone.
   */
  async function reverseGeocode(lat, lon) {
    try {
      const result = await ajax('ccf_pwa_reverse_geocode', { lat, lon });
      if (result && result.name) {
        return result;
      }
    } catch (e) {
      // Fallback to coordinates.
    }
    return {
      lat,
      lon,
      name: `${lat.toFixed(4)}, ${lon.toFixed(4)}`,
    };
  }

  /**
   * Check if a location is already saved (by coordinates proximity).
   * @param {number} lat - Latitude.
   * @param {number} lon - Longitude.
   * @returns {boolean} True if location is already saved.
   */
  function isLocationSaved(lat, lon) {
    const threshold = 0.01; // ~1km tolerance
    return state.savedLocations.some(
      (loc) => Math.abs(loc.lat - lat) < threshold && Math.abs(loc.lon - lon) < threshold
    );
  }

  // ============================================================
  // UI RENDERING
  // ============================================================

  const app = document.getElementById('app');

  /**
   * Render the main app structure.
   */
  function renderApp() {
    app.innerHTML = `
      <header class="app-header">
        <div class="app-header-content">
          <h1 class="app-title">
            <button class="location-switch" data-action="open-location-picker" aria-label="${escapeHtml(locationDisplayName(state.selectedLocation) || strings.appTitle)}. ${escapeHtml(strings.changeLocation || 'Change location')}" aria-haspopup="dialog">
              <span class="location-switch-name">${escapeHtml(locationDisplayName(state.selectedLocation) || strings.appTitle)}</span>
              <span class="location-switch-chevron" aria-hidden="true">&#9662;</span>
            </button>
          </h1>
          <div class="app-status">
            ${!state.isOnline ? `<span class="offline-badge">${escapeHtml(strings.offline)}</span>` : ''}
            ${shouldShowInstallButton() ? `<button class="install-btn" data-action="install" title="${escapeHtml(strings.installApp || 'Install App')}" aria-label="${escapeHtml(strings.installApp || 'Install App')}">&#8681;</button>` : ''}
            <button class="font-size-toggle ${getFontSizeClass()}" data-action="toggle-font-size" title="${escapeHtml(strings.fontSize || 'Font size')}" aria-label="${escapeHtml(strings.fontSize || 'Font size')}">${getFontSizeLabel()}</button>
            <button class="theme-toggle" data-action="toggle-theme" title="Toggle theme" aria-label="Toggle theme">${getThemeIcon()}</button>
          </div>
        </div>
      </header>
      <main class="app-content" id="app-content">
        ${renderViewContent()}
      </main>
      <nav class="app-tabs" aria-label="${escapeHtml(strings.appTitle)}">
        ${VIEWS.map((view) => `
          <button class="tab-btn ${state.activeView === view ? 'active' : ''}" data-tab="${view}"${state.activeView === view ? ' aria-current="page"' : ''}>
            ${escapeHtml(strings[view] || view)}
          </button>
        `).join('')}
      </nav>
      ${state.showLocationPicker ? renderLocationPicker() : ''}
      ${state.showInstallInstructions ? renderInstallInstructions() : ''}
      ${state.editingLocation ? renderEditModal() : ''}
    `;

    attachEventListeners();
  }

  /**
   * Render install instructions modal for Safari/Firefox.
   * @returns {string} HTML string.
   */
  /**
   * iOS Share glyph, drawn inline.
   *
   * Previously this was the character U+F048, which sits in the Unicode
   * Private Use Area and only renders inside Apple's own icon fonts; everywhere
   * else it showed as a blank box.
   */
  const SHARE_ICON = '<svg class="install-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M12 3v13"/><path d="m8 7 4-4 4 4"/><path d="M5 12v7a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2v-7"/></svg>';

  function renderInstallInstructions() {
    const browser = getBrowserType();
    const isIOSDevice = isIOS();

    let instructions = '';

    if (isIOSDevice) {
      if (browser === 'safari') {
        instructions = `
          <ol class="install-steps">
            <li>${escapeHtml(strings.installStep1Safari || 'Tap the Share button')} ${SHARE_ICON}</li>
            <li>${escapeHtml(strings.installStep2Safari || 'Scroll down and tap "Add to Home Screen"')}</li>
            <li>${escapeHtml(strings.installStep3Safari || 'Tap "Add" in the top right')}</li>
          </ol>
        `;
      } else if (browser === 'chrome-ios') {
        instructions = `
          <ol class="install-steps">
            <li>${escapeHtml(strings.installStep1ChromeIOS || 'Tap the Share button')} ${SHARE_ICON}</li>
            <li>${escapeHtml(strings.installStep2ChromeIOS || 'Tap "Add to Home Screen"')}</li>
            <li>${escapeHtml(strings.installStep3ChromeIOS || 'Tap "Add" to confirm')}</li>
          </ol>
        `;
      } else if (browser === 'firefox') {
        instructions = `
          <ol class="install-steps">
            <li>${escapeHtml(strings.installStep1FirefoxIOS || 'Tap the menu button')} <span class="install-icon" aria-hidden="true">&#8943;</span></li>
            <li>${escapeHtml(strings.installStep2FirefoxIOS || 'Tap "Share"')}</li>
            <li>${escapeHtml(strings.installStep3FirefoxIOS || 'Tap "Add to Home Screen"')}</li>
          </ol>
        `;
      } else {
        instructions = `
          <ol class="install-steps">
            <li>${escapeHtml(strings.installStepGenericIOS || 'Open this page in Safari, then tap Share and "Add to Home Screen"')}</li>
          </ol>
        `;
      }
    } else {
      // Android Firefox or other browsers
      if (browser === 'firefox') {
        instructions = `
          <ol class="install-steps">
            <li>${escapeHtml(strings.installStep1Firefox || 'Tap the menu button')} <span class="install-icon" aria-hidden="true">&#8942;</span></li>
            <li>${escapeHtml(strings.installStep2Firefox || 'Tap "Install"')}</li>
          </ol>
        `;
      } else {
        instructions = `
          <ol class="install-steps">
            <li>${escapeHtml(strings.installStep1Generic || 'Tap the browser menu')} <span class="install-icon" aria-hidden="true">&#8942;</span></li>
            <li>${escapeHtml(strings.installStep2Generic || 'Look for "Install app" or "Add to Home Screen"')}</li>
          </ol>
        `;
      }
    }

    return `
      <div class="install-modal-overlay" data-action="close-install">
        <div class="install-modal" role="dialog" aria-modal="true" aria-labelledby="install-modal-title">
          <button class="install-modal-close" data-action="close-install" aria-label="${escapeHtml(strings.close || 'Close')}">&times;</button>
          <h2 id="install-modal-title">${escapeHtml(strings.installTitle || 'Install App')}</h2>
          <p class="install-description">${escapeHtml(strings.installDescription || 'Install this app on your device for quick access.')}</p>
          ${instructions}
        </div>
      </div>
    `;
  }

  /**
   * Render edit location modal.
   * @returns {string} HTML string.
   */
  function renderEditModal() {
    const location = state.editingLocation;
    if (!location) return '';

    const name = location.name || '';
    const admin1 = location.admin1 || '';
    const notes = location.notes || '';

    return `
      <div class="edit-modal-overlay" data-action="cancel-edit">
        <div class="edit-modal" role="dialog" aria-modal="true" aria-labelledby="edit-modal-title">
          <button class="edit-modal-close" data-action="cancel-edit" aria-label="${escapeHtml(strings.close || 'Close')}">&times;</button>
          <h2 id="edit-modal-title">${escapeHtml(strings.editLocation || 'Edit Location')}</h2>
          <form class="edit-form" id="edit-location-form">
            <div class="form-group">
              <label for="edit-name">${escapeHtml(strings.name || 'Name')}</label>
              <input type="text" id="edit-name" class="form-input" value="${escapeHtml(name)}" required>
            </div>
            <div class="form-group">
              <label for="edit-admin1">${escapeHtml(strings.region || 'Region')} <span class="optional">(${escapeHtml(strings.optional || 'optional')})</span></label>
              <input type="text" id="edit-admin1" class="form-input" value="${escapeHtml(admin1)}" placeholder="${escapeHtml(strings.regionPlaceholder || 'State, province, or region')}">
            </div>
            <div class="form-group">
              <label for="edit-notes">${escapeHtml(strings.notes || 'Notes')} <span class="optional">(${escapeHtml(strings.optional || 'optional')})</span></label>
              <textarea id="edit-notes" class="form-textarea" rows="3" placeholder="${escapeHtml(strings.notesPlaceholder || 'Add notes about this location...')}">${escapeHtml(notes)}</textarea>
            </div>
            <div class="form-actions">
              <button type="button" class="btn" data-action="cancel-edit">${escapeHtml(strings.cancel || 'Cancel')}</button>
              <button type="submit" class="btn btn-primary" data-action="save-location-edit">${escapeHtml(strings.save || 'Save')}</button>
            </div>
          </form>
        </div>
      </div>
    `;
  }

  /**
   * Handle install button click.
   */
  async function handleInstallClick() {
    // If we have a deferred prompt (Chrome/Edge), use it
    if (state.deferredInstallPrompt) {
      try {
        state.deferredInstallPrompt.prompt();
        const result = await state.deferredInstallPrompt.userChoice;
        if (result.outcome === 'accepted') {
          state.deferredInstallPrompt = null;
        }
      } catch (e) {
        console.error('Install prompt error:', e);
      }
      return;
    }

    // Otherwise, show manual instructions
    state.showInstallInstructions = true;
    renderApp();
  }

  /**
   * Close install instructions modal.
   */
  function closeInstallInstructions() {
    state.showInstallInstructions = false;
    renderApp();
  }

  /**
   * Render the active view's content.
   * @returns {string} HTML string.
   */
  function renderViewContent() {
    if (state.isLoading) {
      return renderLoading();
    }

    if (state.error) {
      return renderError(state.error);
    }

    if (!state.selectedLocation) {
      return `
        <div class="empty-state">
          <div class="empty-icon">&#127968;</div>
          <h2>${escapeHtml(strings.noHomeLocation)}</h2>
          <p>${escapeHtml(strings.addFirstLocation)}</p>
          <button class="btn btn-primary" data-action="open-location-picker">
            ${escapeHtml(strings.changeLocation || 'Change location')}
          </button>
        </div>
      `;
    }

    const forecast = selectedForecast();
    if (!forecast) {
      return renderLoading();
    }

    switch (state.activeView) {
      case 'outlook':
        return renderOutlookView(forecast);
      case 'day':
        return renderDayView(forecast);
      default:
        return renderForecastView(state.selectedLocation, forecast);
    }
  }

  /**
   * Render the location picker, shown over the active view.
   * @returns {string} HTML string.
   */
  function renderLocationPicker() {
    return `
      <div class="location-picker-overlay" data-action="close-location-picker">
        <div class="location-picker" role="dialog" aria-modal="true" aria-label="${escapeHtml(strings.changeLocation || 'Change location')}">
          <div class="location-picker-header">
            <h2>${escapeHtml(strings.changeLocation || 'Change location')}</h2>
            <button class="btn btn-icon" data-action="close-location-picker" title="${escapeHtml(strings.close || 'Close')}" aria-label="${escapeHtml(strings.close || 'Close')}">&#10005;</button>
          </div>
          <button class="btn btn-use-location" data-action="use-my-location">
            <span aria-hidden="true">&#9678;</span> ${escapeHtml(strings.useMyLocation || 'Use my location')}
          </button>
      <div class="locations-panel">
        <div class="search-box">
          <input
            type="search"
            class="search-input"
            id="location-search"
            placeholder="${escapeHtml(strings.searchLocation)}"
            aria-label="${escapeHtml(strings.searchLocation)}"
            autocomplete="off"
          >
          <button class="search-btn" id="search-btn" ${state.isSearching ? 'disabled' : ''}>
            ${state.isSearching ? escapeHtml(strings.loading) : 'Search'}
          </button>
        </div>
        <div class="search-results-region" role="region" aria-live="polite" aria-label="${escapeHtml(strings.searchLocation)}">
          ${state.searchResults.length > 0 ? renderSearchResults() : ''}
        </div>
        <div class="saved-locations">
          <div class="locations-header">
            <h2>${escapeHtml(strings.locations)}</h2>
            <div class="locations-actions">
              <button class="btn btn-sm" data-action="export-locations" title="${escapeHtml(strings.exportLocations || 'Export')}" aria-label="${escapeHtml(strings.exportLocations || 'Export')}">
                &#8599; ${escapeHtml(strings.export || 'Export')}
              </button>
              <button class="btn btn-sm" data-action="import-locations" title="${escapeHtml(strings.importLocations || 'Import')}" aria-label="${escapeHtml(strings.importLocations || 'Import')}">
                &#8601; ${escapeHtml(strings.import || 'Import')}
              </button>
              <input type="file" id="import-file" accept=".json" style="display: none;">
            </div>
          </div>
          ${state.savedLocations.length === 0 ? `
            <div class="empty-state small">
              <p>${escapeHtml(strings.noLocations)}</p>
              <p class="hint">${escapeHtml(strings.addFirstLocation)}</p>
            </div>
          ` : `
            <ul class="location-list">
              ${state.savedLocations.map(renderLocationItem).join('')}
            </ul>
          `}
        </div>
      </div>
        </div>
      </div>
    `;
  }

  /**
   * Translated label for a score band.
   * @param {number} score - Score from 0-100.
   * @returns {string} Label.
   */
  function scoreBandLabel(score) {
    const band = getScoreLabel(score);
    const labels = {
      excellent: strings.scoreExcellent || 'Excellent',
      good: strings.scoreGood || 'Good',
      fair: strings.scoreFair || 'Fair',
      poor: strings.scorePoor || 'Poor',
    };
    return labels[band] || band;
  }

  /**
   * Time of a sunrise or sunset on a given day, as HH:MM.
   * @param {Object} day - Daily data.
   * @param {string} event - 'sunrise' or 'sunset'.
   * @param {string} timezone - Timezone identifier.
   * @returns {string} Time, or '' when unavailable.
   */
  function eventTime(day, event, timezone) {
    const twilight = day.twilight || {};
    if (twilight[event]) return twilight[event];
    return day[event] ? formatDateTime(day[event], 'time', timezone) : '';
  }

  /**
   * Short label for a day: Today, Tomorrow, or the weekday.
   * @param {string} dateStr - Date string (YYYY-MM-DD).
   * @param {number} index - Index into forecast.daily.
   * @returns {string} Label.
   */
  function dayLabel(dateStr, index) {
    if (0 === index) return strings.today || 'Today';
    if (1 === index) return strings.tomorrow || 'Tomorrow';
    // Noon avoids the date shifting a day under the browser's timezone.
    return new Date(`${dateStr}T12:00:00`).toLocaleDateString(undefined, { weekday: 'short' });
  }

  /**
   * Render a score as a ring. The value is drawn as text inside it so it is
   * readable rather than purely graphical.
   *
   * @param {number} score - Score from 0-100.
   * @returns {string} SVG markup.
   */
  function renderScoreRing(score) {
    // r chosen so the circumference is 100 and stroke-dasharray takes the
    // score directly.
    return `
      <svg class="score-ring" viewBox="0 0 36 36" aria-hidden="true" focusable="false">
        <circle class="score-ring-track" cx="18" cy="18" r="15.915" fill="none" stroke-width="3"></circle>
        <circle class="score-ring-value" cx="18" cy="18" r="15.915" fill="none" stroke-width="3"
          stroke-dasharray="${score} 100" stroke-linecap="round" transform="rotate(-90 18 18)"></circle>
        <text class="score-ring-text" x="18" y="18" text-anchor="middle" dominant-baseline="central">${score}%</text>
      </svg>
    `;
  }

  /**
   * Render one sunrise or sunset card in the outlook.
   *
   * @param {Object} forecast - Forecast data.
   * @param {Object} day - Daily data.
   * @param {number} dayIndex - Index into forecast.daily.
   * @param {string} event - 'sunrise' or 'sunset'.
   * @returns {string} HTML string.
   */
  function renderOutlookCard(forecast, day, dayIndex, event) {
    const timezone = forecast.location?.timezone;
    const time = eventTime(day, event, timezone);

    if (!time) {
      // No event at all: normal inside the polar circles.
      return '<div class="outlook-card is-empty" aria-hidden="true">&mdash;</div>';
    }

    const eventTs = parseTimeToTimestamp(day.date, time, timezone);
    const isPast = eventTs !== null && eventTs < Date.now();
    const eventName = 'sunrise' === event ? (strings.sunrise || 'Sunrise') : (strings.sunset || 'Sunset');

    if (isPast) {
      return `
        <div class="outlook-card is-past">
          <span class="outlook-card-icon" aria-hidden="true">&#128339;</span>
          <span class="outlook-card-time">${escapeHtml(time)}</span>
          <span class="visually-hidden">${escapeHtml(eventName)} ${escapeHtml(time)}, ${escapeHtml(strings.past || 'already passed')}</span>
        </div>
      `;
    }

    const score = sunriseSunsetScore(forecast.hourly, day, event);
    if (score === null) {
      return `
        <div class="outlook-card is-empty">
          <span class="outlook-card-time">${escapeHtml(time)}</span>
        </div>
      `;
    }

    const label = scoreBandLabel(score);
    const aria = `${eventName} ${dayLabel(day.date, dayIndex)} ${time}, ${label}, ${score}%`;

    return `
      <button class="outlook-card ${getScoreClass(score)}" data-action="open-day"
        data-day="${dayIndex}" data-event="${event}" aria-label="${escapeHtml(aria)}">
        <span class="outlook-card-band">${escapeHtml(label)}</span>
        ${renderScoreRing(score)}
        <span class="outlook-card-time">${escapeHtml(time)}</span>
      </button>
    `;
  }

  /**
   * Render the multi-day outlook view.
   * @param {Object} forecast - Forecast data.
   * @returns {string} HTML string.
   */
  function renderOutlookView(forecast) {
    const days = forecast.daily || [];
    if (!days.length) {
      return renderError(strings.error);
    }

    return `
      <div class="outlook-view">
        <div class="outlook-legend" aria-hidden="true">
          <span class="outlook-legend-day"></span>
          <span class="outlook-legend-icon">&#127749;</span>
          <span class="outlook-legend-icon">&#127751;</span>
        </div>
        <ul class="outlook-list">
          ${days.map((day, index) => `
            <li class="outlook-row">
              <span class="outlook-day">${escapeHtml(dayLabel(day.date, index))}</span>
              ${renderOutlookCard(forecast, day, index, 'sunrise')}
              ${renderOutlookCard(forecast, day, index, 'sunset')}
            </li>
          `).join('')}
        </ul>
      </div>
    `;
  }

  /**
   * Add a number of minutes to an HH:MM string, wrapping at midnight.
   * @param {string} timeStr - Time string (HH:MM).
   * @param {number} minutes - Minutes to add; may be negative.
   * @returns {string|null} Time string, or null on bad input.
   */
  function shiftTime(timeStr, minutes) {
    if (!timeStr) return null;
    const [h, m] = timeStr.split(':').map(Number);
    if (isNaN(h) || isNaN(m)) return null;
    const total = (((h * 60 + m + minutes) % 1440) + 1440) % 1440;
    return String(Math.floor(total / 60)).padStart(2, '0') + ':' + String(total % 60).padStart(2, '0');
  }

  /**
   * Twilight times for a day, filling in golden and blue hour from the old
   * fixed offsets when the computed fields are absent.
   *
   * Forecasts outlive freshness by twelve hours, so an entry cached before
   * the solar-elevation fields existed can still be served. These fallbacks
   * match what getSunlightClass() approximates, so the app stays consistent
   * with itself for that window rather than dropping the phases entirely.
   *
   * @param {Object} day - Daily data.
   * @returns {Object} Twilight times.
   */
  function phaseTimes(day) {
    const twilight = Object.assign({}, day.twilight || {});
    const sunrise = twilight.sunrise;
    const sunset = twilight.sunset;

    if (sunrise) {
      if (!twilight.blue_hour_dawn_start) twilight.blue_hour_dawn_start = twilight.civil_dawn || shiftTime(sunrise, -60);
      if (!twilight.blue_hour_dawn_end) twilight.blue_hour_dawn_end = shiftTime(sunrise, -15);
      if (!twilight.golden_hour_dawn_start) twilight.golden_hour_dawn_start = shiftTime(sunrise, -15);
      if (!twilight.golden_hour_dawn_end) twilight.golden_hour_dawn_end = shiftTime(sunrise, 60);
    }
    if (sunset) {
      if (!twilight.golden_hour_dusk_start) twilight.golden_hour_dusk_start = shiftTime(sunset, -60);
      if (!twilight.golden_hour_dusk_end) twilight.golden_hour_dusk_end = shiftTime(sunset, 15);
      if (!twilight.blue_hour_dusk_start) twilight.blue_hour_dusk_start = shiftTime(sunset, 15);
      if (!twilight.blue_hour_dusk_end) twilight.blue_hour_dusk_end = twilight.civil_dusk || shiftTime(sunset, 45);
    }

    return twilight;
  }

  /**
   * Human relative time for an event, or '' if it has passed.
   * @param {number|null} timestamp - Event timestamp.
   * @returns {string} Relative phrase.
   */
  function relativeTime(timestamp) {
    if (timestamp === null) return '';
    const diffMinutes = Math.round((timestamp - Date.now()) / 60000);
    if (diffMinutes <= 0) return '';

    const relative = new Intl.RelativeTimeFormat(undefined, { numeric: 'auto' });
    if (diffMinutes < 60) return relative.format(diffMinutes, 'minute');
    if (diffMinutes < 1440) return relative.format(Math.round(diffMinutes / 60), 'hour');
    return relative.format(Math.round(diffMinutes / 1440), 'day');
  }

  /**
   * Render one hero card for a sunrise or sunset.
   * @param {Object} forecast - Forecast data.
   * @param {Object} day - Daily data.
   * @param {string} event - 'sunrise' or 'sunset'.
   * @returns {string} HTML string.
   */
  function renderDayHero(forecast, day, event) {
    const timezone = forecast.location?.timezone;
    const time = eventTime(day, event, timezone);
    if (!time) return '';

    const name = 'sunrise' === event ? (strings.sunrise || 'Sunrise') : (strings.sunset || 'Sunset');
    const score = sunriseSunsetScore(forecast.hourly, day, event);
    const relative = relativeTime(parseTimeToTimestamp(day.date, time, timezone));

    if (score === null) {
      return `
        <div class="day-hero">
          <h2 class="day-hero-title">${escapeHtml(name)}</h2>
          <p class="day-hero-time">${escapeHtml(time)}</p>
          ${relative ? `<p class="day-hero-relative">${escapeHtml(relative)}</p>` : ''}
        </div>
      `;
    }

    return `
      <div class="day-hero ${getScoreClass(score)}">
        <h2 class="day-hero-title">${escapeHtml(name)}</h2>
        <p class="day-hero-band">${escapeHtml(scoreBandLabel(score))}</p>
        <div class="day-hero-meter" role="img" aria-label="${escapeHtml(scoreBandLabel(score))}, ${score}%">
          <div class="day-hero-meter-fill" style="width: ${score}%"><span>${score}%</span></div>
        </div>
        <p class="day-hero-time">${escapeHtml(time)}</p>
        ${relative ? `<p class="day-hero-relative">${escapeHtml(relative)}</p>` : ''}
      </div>
    `;
  }

  /**
   * Render the ordered light-phase list for a day.
   *
   * Rendered in chronological order rather than sorted on the time string,
   * because dusk phases can fall after local midnight — an Irish June has
   * nautical dusk at 00:02. Those rows are marked so the next-day time does
   * not read as a mistake.
   *
   * @param {Object} day - Daily data.
   * @returns {string} HTML string.
   */
  function renderPhaseList(day) {
    const t = phaseTimes(day);
    const phases = [
      { label: strings.firstLight || 'First Light', start: t.astronomical_dawn },
      { label: strings.blueHour || 'Blue Hour', start: t.blue_hour_dawn_start, end: t.blue_hour_dawn_end },
      { label: strings.goldenHour || 'Golden Hour', start: t.golden_hour_dawn_start, end: t.golden_hour_dawn_end },
      { label: strings.sunrise || 'Sunrise', start: t.sunrise },
      { label: strings.daytime || 'Daytime', start: t.golden_hour_dawn_end },
      { label: strings.goldenHour || 'Golden Hour', start: t.golden_hour_dusk_start, end: t.golden_hour_dusk_end },
      { label: strings.sunset || 'Sunset', start: t.sunset },
      { label: strings.blueHour || 'Blue Hour', start: t.blue_hour_dusk_start, end: t.blue_hour_dusk_end },
      { label: strings.lastLight || 'Last Light', start: t.astronomical_dusk },
    ].filter((phase) => phase.start);

    if (!phases.length) {
      return '';
    }

    // Once a phase reads earlier than the one before it, the day has rolled
    // past midnight and everything after it belongs to the next date.
    let previous = null;
    let wrapped = false;

    return `
      <ul class="phase-list">
        ${phases.map((phase) => {
          if (previous !== null && phase.start < previous) {
            wrapped = true;
          }
          previous = phase.start;
          const time = phase.end ? `${phase.start} \u2013 ${phase.end}` : phase.start;
          return `
            <li class="phase-row">
              <span class="phase-label">${escapeHtml(phase.label)}</span>
              <span class="phase-time">
                ${escapeHtml(time)}${wrapped ? `<span class="phase-next-day" title="${escapeHtml(strings.nextDay || 'next day')}">+1</span>` : ''}
              </span>
            </li>
          `;
        }).join('')}
      </ul>
    `;
  }

  /**
   * Render the single-day view.
   * @param {Object} forecast - Forecast data.
   * @returns {string} HTML string.
   */
  function renderDayView(forecast) {
    const days = forecast.daily || [];
    if (!days.length) {
      return renderError(strings.error);
    }

    const index = Math.max(0, Math.min(state.selectedDayIndex, days.length - 1));
    const day = days[index];

    return `
      <div class="day-view">
        <div class="day-pager">
          <button class="btn btn-icon" data-action="day-prev" ${0 === index ? 'disabled' : ''}
            aria-label="${escapeHtml(strings.previousDay || 'Previous day')}">&#8249;</button>
          <span class="day-pager-label">${escapeHtml(dayLabel(day.date, index))}</span>
          <button class="btn btn-icon" data-action="day-next" ${index === days.length - 1 ? 'disabled' : ''}
            aria-label="${escapeHtml(strings.nextDay || 'Next day')}">&#8250;</button>
        </div>
        ${renderDayHero(forecast, day, 'sunrise')}
        ${renderDayHero(forecast, day, 'sunset')}
        ${renderPhaseList(day)}
      </div>
    `;
  }

  /**
   * Render search results.
   * @returns {string} HTML string.
   */
  function renderSearchResults() {
    return `
      <div class="search-results">
        ${state.searchResults.map((result, index) => `
          <button class="search-result" data-action="add-location" data-index="${index}">
            <span class="result-name">${escapeHtml(result.name)}</span>
            <span class="result-detail">${escapeHtml([result.admin1, result.country].filter(Boolean).join(', '))}</span>
          </button>
        `).join('')}
      </div>
    `;
  }

  /**
   * Render a location list item.
   * @param {Object} location - Location object.
   * @returns {string} HTML string.
   */
  function renderLocationItem(location) {
    const displayName = location.admin1
      ? `${location.name}, ${location.admin1}`
      : location.name;
    const mapsUrl = getGoogleMapsUrl(location.lat, location.lon);

    return `
      <li class="location-item ${location.isHome ? 'is-home' : ''}" data-id="${location.id}">
        <button class="location-info" data-action="view-location" data-id="${location.id}">
          <span class="location-name">
            ${location.isHome ? '<span class="home-badge" aria-hidden="true">&#127968;</span>' : ''}
            ${escapeHtml(displayName)}
          </span>
          ${location.notes ? `<span class="location-notes">${escapeHtml(location.notes)}</span>` : ''}
          <span class="location-coords">${location.lat.toFixed(2)}, ${location.lon.toFixed(2)}</span>
        </button>
        <div class="location-actions">
          <button class="btn btn-icon" data-action="edit-location" data-id="${location.id}" title="${escapeHtml(strings.edit || 'Edit')}" aria-label="${escapeHtml(strings.edit || 'Edit')}">
            &#9998;
          </button>
          <a href="${mapsUrl}" target="_blank" rel="noopener" class="btn btn-icon" title="View on Google Maps" aria-label="View on Google Maps">
            &#128205;
          </a>
          <button class="btn btn-icon" data-action="share-location" data-id="${location.id}" title="${escapeHtml(strings.share || 'Share')}" aria-label="${escapeHtml(strings.share || 'Share')}">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><line x1="8.59" y1="13.51" x2="15.42" y2="17.49"/><line x1="15.41" y1="6.51" x2="8.59" y2="10.49"/></svg>
          </button>
          ${!location.isHome ? `
            <button class="btn btn-icon" data-action="set-home" data-id="${location.id}" title="${escapeHtml(strings.setAsHome)}" aria-label="${escapeHtml(strings.setAsHome)}">
              &#127968;
            </button>
          ` : ''}
          <button class="btn btn-icon btn-danger" data-action="delete-location" data-id="${location.id}" title="${escapeHtml(strings.delete)}" aria-label="${escapeHtml(strings.delete)}">
            &#128465;
          </button>
        </div>
      </li>
    `;
  }

  /**
   * Render jump buttons for quick navigation.
   * @returns {string} HTML string.
   */
  function renderJumpButtons() {
    return `
      <div class="jump-buttons">
        <span class="current-day-display" id="current-day-display"></span>
        <div class="jump-buttons-nav">
          <button class="jump-btn" data-action="jump-to" data-target="prev-day" title="${escapeHtml(strings.previousDay || 'Previous day')}" aria-label="${escapeHtml(strings.previousDay || 'Previous day')}">
            <span class="jump-btn-icon" aria-hidden="true">&#9664;</span>
          </button>
          <button class="jump-btn" data-action="jump-to" data-target="now" title="${escapeHtml(strings.jumpToNow || 'Jump to now')}" aria-label="${escapeHtml(strings.jumpToNow || 'Jump to now')}">
            <span class="jump-btn-icon" aria-hidden="true">&#9201;</span>
            <span>${escapeHtml(strings.now || 'Now')}</span>
          </button>
          <button class="jump-btn" data-action="jump-to" data-target="next-day" title="${escapeHtml(strings.nextDay || 'Next day')}" aria-label="${escapeHtml(strings.nextDay || 'Next day')}">
            <span class="jump-btn-icon" aria-hidden="true">&#9654;</span>
          </button>
        </div>
      </div>
    `;
  }

  /**
   * Render the forecast view.
   * @param {Object} location - Location object.
   * @param {Object} forecast - Forecast data.
   * @param {string} source - Source identifier ('home', 'current', 'shared').
   * @returns {string} HTML string.
   */
  function renderForecastView(location, forecast) {
    const displayName = location.admin1
      ? `${location.name}, ${location.admin1}`
      : location.name || `${location.lat.toFixed(2)}, ${location.lon.toFixed(2)}`;
    const mapsUrl = getGoogleMapsUrl(location.lat, location.lon);
    const canSave = !location.id && !isLocationSaved(location.lat, location.lon);
    const alreadySaved = !location.id && isLocationSaved(location.lat, location.lon);

    // Get today's sunrise/sunset times
    const today = forecast.daily?.[0];
    const twilight = today?.twilight || {};
    const timezone = forecast.location?.timezone;
    const sunrise = twilight.sunrise || (today?.sunrise ? formatDateTime(today.sunrise, 'time', timezone) : '');
    const sunset = twilight.sunset || (today?.sunset ? formatDateTime(today.sunset, 'time', timezone) : '');

    return `
      <div class="forecast-view">
        <div class="forecast-header">
          <div class="forecast-header-main">
            <h2 class="forecast-location">
              ${escapeHtml(displayName)}
              <a href="${mapsUrl}" target="_blank" rel="noopener" class="maps-link" title="View on Google Maps" aria-label="View on Google Maps">&#128205;</a>
            </h2>
            ${sunrise && sunset ? `
              <div class="forecast-sun-times">
                <span class="sun-time sunrise">&#9728;&#xFE0E; ${escapeHtml(sunrise)}</span>
                <span class="sun-time sunset">&#9790; ${escapeHtml(sunset)}</span>
              </div>
            ` : ''}
          </div>
          <div class="forecast-header-actions">
            ${forecast.location?.timezone_abbr ? `
              <span class="forecast-timezone">${escapeHtml(forecast.location.timezone_abbr)}</span>
            ` : ''}
            <button class="btn btn-icon" data-action="share-location" title="${escapeHtml(strings.share || 'Share')}" aria-label="${escapeHtml(strings.share || 'Share')}">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><line x1="8.59" y1="13.51" x2="15.42" y2="17.49"/><line x1="15.41" y1="6.51" x2="8.59" y2="10.49"/></svg>
            </button>
            ${canSave ? `
              <button class="btn btn-save-location" data-action="save-current-location" title="${escapeHtml(strings.saveLocation || 'Save Location')}" aria-label="${escapeHtml(strings.saveLocation || 'Save Location')}">
                &#128190; ${escapeHtml(strings.saveLocation || 'Save')}
              </button>
            ` : ''}
            ${alreadySaved ? `
              <span class="saved-badge" title="${escapeHtml(strings.locationSaved || 'Location saved')}">&#10003; ${escapeHtml(strings.saved || 'Saved')}</span>
            ` : ''}
          </div>
        </div>
        ${renderJumpButtons()}
        ${renderForecastGrid(forecast)}
      </div>
    `;
  }

  /**
   * Render solar information panel.
   * @param {Object} forecast - Forecast data.
   * @returns {string} HTML string.
   */
  function renderSolarInfo(forecast) {
    const today = forecast.daily?.[0];
    if (!today) return '';

    const twilight = today.twilight || {};
    const sunrise = twilight.sunrise || formatDateTime(today.sunrise, 'time');
    const sunset = twilight.sunset || formatDateTime(today.sunset, 'time');

    return `
      <div class="info-panel-compact solar-panel-compact">
        <span class="solar-times">${escapeHtml(sunrise)} - ${escapeHtml(sunset)}</span>
      </div>
    `;
  }

  /**
   * Render lunar information panel.
   * @param {Object} forecast - Forecast data.
   * @returns {string} HTML string.
   */
  function renderLunarInfo(forecast) {
    const todayDate = forecast.daily?.[0]?.date;
    const moon = todayDate ? forecast.moon?.[todayDate] : null;
    if (!moon || moon.moon_illumination == null) return '';

    return `
      <div class="info-panel lunar-panel">
        <h3>${escapeHtml(strings.moon)}</h3>
        <div class="info-grid">
          <div class="info-item">
            <span class="info-icon">${getMoonPhaseEmoji(moon.moon_illumination)}</span>
            <span class="info-label">${escapeHtml(strings.phase)}</span>
            <span class="info-value">${escapeHtml(moon.moon_phase_name)}</span>
          </div>
          <div class="info-item">
            <span class="info-icon">&#128161;</span>
            <span class="info-label">${escapeHtml(strings.illumination)}</span>
            <span class="info-value">${moon.moon_illumination}%</span>
          </div>
          ${moon.moonrise ? `
            <div class="info-item">
              <span class="info-icon">&#8593;</span>
              <span class="info-label">${escapeHtml(strings.moonrise)}</span>
              <span class="info-value">${escapeHtml(moon.moonrise)}</span>
            </div>
          ` : ''}
          ${moon.moonset ? `
            <div class="info-item">
              <span class="info-icon">&#8595;</span>
              <span class="info-label">${escapeHtml(strings.moonset)}</span>
              <span class="info-value">${escapeHtml(moon.moonset)}</span>
            </div>
          ` : ''}
        </div>
      </div>
    `;
  }

  /**
   * Get moon phase emoji based on illumination.
   * @param {number} illumination - Moon illumination percentage.
   * @returns {string} Emoji.
   */
  function getMoonPhaseEmoji(illumination) {
    if (illumination < 5) return '&#127761;'; // New moon
    if (illumination < 35) return '&#127762;'; // Waxing crescent
    if (illumination < 65) return '&#127763;'; // First quarter
    if (illumination < 95) return '&#127764;'; // Waxing gibbous
    return '&#127765;'; // Full moon
  }

  /**
   * Render the forecast grid.
   * @param {Object} forecast - Forecast data.
   * @returns {string} HTML string.
   */
  function renderForecastGrid(forecast) {
    const hourly = forecast.hourly || [];
    if (hourly.length === 0) return '';

    const timezone = forecast.location?.timezone;

    // Match the current hour by comparing strings against the API's own local
    // stamps. Building a Date from toLocaleString() and reading it back with
    // toISOString() re-applies the viewer's offset, which put this an hour out
    // for anyone not on UTC.
    const now = nowInTimezone(timezone);
    const currentHourIndex = findHourIndex(hourly, now.date, now.hour + ':00');

    return `
      <div class="forecast-grid-container" id="forecast-grid">
        <div class="forecast-grid">
          ${renderGridHeader()}
          ${renderGridBody(hourly, forecast, currentHourIndex, timezone)}
        </div>
      </div>
    `;
  }

  /**
   * Render the grid header (row labels).
   * @returns {string} HTML string.
   */
  function renderGridHeader() {
    return `
      <div class="grid-row-labels">
        <div class="grid-label header-label">Time</div>
        <div class="grid-label photo-score-label">${escapeHtml(strings.photoScore || 'Photo')}</div>
        <div class="grid-label section-header">${escapeHtml(strings.clouds)}</div>
        <div class="grid-label">${escapeHtml(strings.total)}</div>
        <div class="grid-label">${escapeHtml(strings.low)}</div>
        <div class="grid-label">${escapeHtml(strings.mid)}</div>
        <div class="grid-label">${escapeHtml(strings.high)}</div>
        <div class="grid-label section-header">${escapeHtml(strings.sun)}</div>
        <div class="grid-label section-header">${escapeHtml(strings.moon)}</div>
        <div class="grid-label section-header">${escapeHtml(strings.rain)}</div>
        <div class="grid-label">${escapeHtml(strings.chance)}</div>
        <div class="grid-label">${escapeHtml(strings.amount)}</div>
        <div class="grid-label section-header">${escapeHtml(strings.wind)}</div>
        <div class="grid-label">${escapeHtml(strings.visibility)}</div>
        <div class="grid-label section-header">${escapeHtml(strings.temp)}</div>
        <div class="grid-label">${escapeHtml(strings.actual)}</div>
        <div class="grid-label">${escapeHtml(strings.feelsLike)}</div>
        <div class="grid-label">${escapeHtml(strings.dewPoint)}</div>
        <div class="grid-label">${escapeHtml(strings.humidity)}</div>
        <div class="grid-label">${escapeHtml(strings.frost)}</div>
      </div>
    `;
  }

  /**
   * Render the grid body (data columns).
   * @param {Array} hourly - Hourly data array.
   * @param {Object} forecast - Full forecast object.
   * @param {string} todayStr - Today's date string.
   * @param {number} currentHourIndex - Index of current hour.
   * @returns {string} HTML string.
   */
  function renderGridBody(hourly, forecast, currentHourIndex, timezone) {
    let lastDate = '';

    // Build a map of daily data by date for quick lookup
    const dailyByDate = {};
    if (forecast.daily) {
      forecast.daily.forEach(day => {
        dailyByDate[day.date] = day;
      });
    }

    return `
      <div class="grid-data" id="grid-data">
        ${hourly.map((hour, index) => {
          // hour.time already carries the location's own date; no conversion.
          const dateStr = hour.time.split('T')[0];
          const isNewDay = dateStr !== lastDate;
          lastDate = dateStr;
          const isCurrent = index === currentHourIndex;
          const isPast = currentHourIndex >= 0 && index < currentHourIndex;
          const dayMoon = forecast.moon?.[dateStr];
          const dayData = dailyByDate[dateStr];

          return renderHourColumn(hour, index, isNewDay, isCurrent, isPast, dayMoon, dayData, timezone, dateStr);
        }).join('')}
      </div>
    `;
  }

  /**
   * Render a single hour column.
   * @param {Object} hour - Hour data.
   * @param {number} index - Column index.
   * @param {boolean} isNewDay - Whether this is the first hour of a new day.
   * @param {boolean} isCurrent - Whether this is the current hour.
   * @param {boolean} isPast - Whether this hour is in the past.
   * @param {Object} moon - Moon data for this day.
   * @param {Object} dayData - Daily data for this day (sunrise, sunset, twilight).
   * @param {string} timezone - Timezone identifier.
   * @param {string} dateStr - Date string (YYYY-MM-DD) for this hour.
   * @returns {string} HTML string.
   */
  function renderHourColumn(hour, index, isNewDay, isCurrent, isPast, moon, dayData, timezone, dateStr) {
    const hourDate = new Date(parseHourTimestamp(hour.time, timezone));
    const timeStr = formatDateTime(hour.time, 'hour', timezone);
    const dayLabel = isNewDay ? formatDateTime(hour.time, 'day', timezone) : '';
    const wind = getWindDirection(hour.wind_direction);
    const visKm = hour.visibility != null ? (hour.visibility / 1000).toFixed(1) : '-';

    const sunlightClass = getSunlightClass(hour, hourDate, dayData, timezone);
    const moonVisible = isMoonVisible(hourDate, moon, timezone);
    const moonIllumination = moon ? moon.moon_illumination : 0;

    // Calculate photography score
    const photoScore = calculatePhotoScore(hour, sunlightClass);
    const scoreClass = getScoreClass(photoScore);

    return `
      <div class="grid-column ${isCurrent ? 'current-hour' : ''} ${isPast ? 'past-hour' : ''} ${isNewDay ? 'day-boundary' : ''}" data-index="${index}" data-date="${dateStr}">
        <div class="grid-cell time-cell ${isNewDay ? 'new-day' : ''}">
          ${dayLabel ? `<span class="day-label">${escapeHtml(dayLabel)}</span>` : ''}
          <span class="hour-label">${escapeHtml(timeStr)}</span>
          ${isCurrent ? `<span class="now-badge">${escapeHtml(strings.now)}</span>` : ''}
        </div>
        <div class="grid-cell photo-score-cell ${scoreClass}">
          ${photoScore}
          <div class="score-bar"><div class="score-fill" style="width: ${photoScore}%"></div></div>
        </div>
        <div class="grid-cell section-spacer"></div>
        <div class="grid-cell cloud-cell ${getColorClass(hour.cloud_total, COLOR_THRESHOLDS.cloud)}">${formatValue(hour.cloud_total, '%')}</div>
        <div class="grid-cell cloud-cell ${getColorClass(hour.cloud_low, COLOR_THRESHOLDS.cloud)}">${formatValue(hour.cloud_low, '%')}</div>
        <div class="grid-cell cloud-cell ${getColorClass(hour.cloud_mid, COLOR_THRESHOLDS.cloud)}">${formatValue(hour.cloud_mid, '%')}</div>
        <div class="grid-cell cloud-cell ${getColorClass(hour.cloud_high, COLOR_THRESHOLDS.cloud)}">${formatValue(hour.cloud_high, '%')}</div>
        <div class="grid-cell sunlight-cell ${sunlightClass}"></div>
        <div class="grid-cell moon-cell ${moonVisible ? 'moon-visible' : 'moon-hidden'}" style="--moon-illumination: ${moonIllumination / 100}"></div>
        <div class="grid-cell section-spacer"></div>
        <div class="grid-cell rain-cell ${getColorClass(hour.rain_chance, COLOR_THRESHOLDS.rain)}">${formatValue(hour.rain_chance, '%')}</div>
        <div class="grid-cell">${formatValue(hour.rain_amount, 'mm', 1)}</div>
        <div class="grid-cell wind-cell ${getColorClass(hour.wind_speed, COLOR_THRESHOLDS.wind)}">
          <span class="wind-arrow">${wind.arrow}</span>
          <span class="wind-speed">${formatValue(hour.wind_speed, '', 0)}</span>
        </div>
        <div class="grid-cell vis-cell ${getColorClass(hour.visibility, COLOR_THRESHOLDS.visibility)}">${visKm}</div>
        <div class="grid-cell section-spacer"></div>
        <div class="grid-cell temp-cell">${formatValue(hour.temperature, '\u00B0', 0)}</div>
        <div class="grid-cell">${formatValue(hour.feels_like, '\u00B0', 0)}</div>
        <div class="grid-cell">${formatValue(hour.dew_point, '\u00B0', 0)}</div>
        <div class="grid-cell humidity-cell ${getColorClass(hour.humidity, COLOR_THRESHOLDS.humidity)}">${formatValue(hour.humidity, '%')}</div>
        <div class="grid-cell frost-cell">${hour.frost ? '&#10052;' : ''}</div>
      </div>
    `;
  }

  /**
   * Determine if the moon is visible during a given hour.
   * @param {Date} hourDate - Date object for this hour.
   * @param {Object} moon - Moon data for this day.
   * @returns {boolean} True if moon is visible.
   */
  function isMoonVisible(hourDate, moon, timezone) {
    if (!moon) return false;

    const hourTs = hourDate.getTime();
    const dateStr = timezone
      ? hourDate.toLocaleDateString('en-CA', { timeZone: timezone })
      : hourDate.toISOString().split('T')[0];
    const moonriseTs = parseTimeToTimestamp(dateStr, moon.moonrise, timezone);
    const moonsetTs = parseTimeToTimestamp(dateStr, moon.moonset, timezone);

    // Both times available
    if (moonriseTs && moonsetTs) {
      // Normal case: moonrise before moonset
      if (moonsetTs > moonriseTs) {
        return hourTs >= moonriseTs && hourTs < moonsetTs;
      }
      // Inverted case: moon was up at start of day, sets, then rises again
      return hourTs >= moonriseTs || hourTs < moonsetTs;
    }

    // Only one time available
    if (moonriseTs) return hourTs >= moonriseTs;
    if (moonsetTs) return hourTs < moonsetTs;

    return false;
  }

  /**
   * Format a value with optional suffix.
   * @param {number} value - Value to format.
   * @param {string} suffix - Suffix to append.
   * @param {number} decimals - Decimal places.
   * @returns {string} Formatted value.
   */
  function formatValue(value, suffix = '', decimals = 0) {
    if (value == null) return '-';
    const formatted = decimals > 0 ? value.toFixed(decimals) : Math.round(value);
    return formatted + suffix;
  }

  /**
   * Render loading state.
   * @returns {string} HTML string.
   */
  function renderLoading() {
    return `
      <div class="loading-state" role="status" aria-live="polite">
        <div class="loading-spinner" aria-hidden="true"></div>
        <p>${escapeHtml(strings.loading)}</p>
      </div>
    `;
  }

  /**
   * Render error state.
   * @param {string} message - Error message.
   * @returns {string} HTML string.
   */
  function renderError(message) {
    return `
      <div class="error-state" role="alert">
        <div class="error-icon" aria-hidden="true">&#9888;</div>
        <h2>${escapeHtml(strings.error)}</h2>
        <p>${escapeHtml(message)}</p>
        <button class="btn btn-primary" data-action="retry">
          ${escapeHtml(strings.retry)}
        </button>
      </div>
    `;
  }

  // ============================================================
  // EVENT HANDLING
  // ============================================================

  /**
   * Attach event listeners.
   */
  function attachEventListeners() {
    // Tab navigation.
    app.querySelectorAll('.tab-btn').forEach((btn) => {
      btn.addEventListener('click', () => switchView(btn.dataset.tab));
    });

    // Action buttons.
    app.addEventListener('click', handleActionClick);

    // Search input and button.
    const searchInput = app.querySelector('#location-search');
    const searchBtn = app.querySelector('#search-btn');
    if (searchInput) {
      searchInput.addEventListener('keydown', handleSearchKeydown);
    }
    if (searchBtn) {
      searchBtn.addEventListener('click', handleSearchClick);
    }

    // Import file input.
    const importInput = app.querySelector('#import-file');
    if (importInput) {
      importInput.addEventListener('change', handleImportFile);
    }

    // Edit form submission.
    const editForm = app.querySelector('#edit-location-form');
    if (editForm) {
      editForm.addEventListener('submit', (e) => {
        e.preventDefault();
        saveLocationEdit();
      });
    }
  }

  /**
   * Handle action button clicks.
   * @param {Event} event - Click event.
   */
  async function handleActionClick(event) {
    const btn = event.target.closest('[data-action]');
    if (!btn) return;

    const action = btn.dataset.action;
    const id = btn.dataset.id ? parseInt(btn.dataset.id, 10) : null;
    const index = btn.dataset.index ? parseInt(btn.dataset.index, 10) : null;

    switch (action) {
      case 'toggle-theme':
        toggleTheme();
        break;

      case 'toggle-font-size':
        toggleFontSize();
        break;

      case 'install':
        handleInstallClick();
        break;

      case 'close-install':
        // Only close if clicking X button or directly on overlay (not modal content)
        if (btn.classList.contains('install-modal-close') || event.target.classList.contains('install-modal-overlay')) {
          closeInstallInstructions();
        }
        break;

      case 'open-location-picker':
        state.showLocationPicker = true;
        state.searchResults = [];
        renderApp();
        break;

      case 'close-location-picker':
        // Ignore clicks inside the dialog; only the overlay and the close
        // button dismiss it.
        if (btn.classList.contains('location-picker-overlay') && event.target !== btn) {
          break;
        }
        state.showLocationPicker = false;
        renderApp();
        break;

      case 'use-my-location':
        await useCurrentLocation();
        break;

      case 'open-day':
        state.selectedDayIndex = parseInt(btn.dataset.day, 10) || 0;
        state.activeView = 'day';
        renderApp();
        break;

      case 'day-prev':
        if (state.selectedDayIndex > 0) {
          state.selectedDayIndex -= 1;
          renderApp();
        }
        break;

      case 'day-next': {
        const dayCount = (selectedForecast()?.daily || []).length;
        if (state.selectedDayIndex < dayCount - 1) {
          state.selectedDayIndex += 1;
          renderApp();
        }
        break;
      }

      case 'retry':
      case 'retry-location':
        state.error = null;
        if (state.selectedLocation) {
          delete state.forecastData[forecastKey(state.selectedLocation)];
          await selectLocation(state.selectedLocation);
        } else {
          renderApp();
        }
        break;

      case 'view-location':
        await viewLocation(id);
        break;

      case 'add-location':
        if (index != null && state.searchResults[index]) {
          await addLocation(state.searchResults[index]);
        }
        break;

      case 'save-current-location':
        if (state.selectedLocation && !isLocationSaved(state.selectedLocation.lat, state.selectedLocation.lon)) {
          await addLocation(state.selectedLocation);
        }
        break;

      case 'set-home':
        await setHomeLocation(id);
        break;

      case 'delete-location':
        await deleteLocation(id);
        break;

      case 'edit-location':
        openEditModal(id);
        break;

      case 'save-location-edit':
        event.preventDefault();
        await saveLocationEdit();
        break;

      case 'cancel-edit':
        // Only close if clicking X button, Cancel button, or directly on overlay (not modal content)
        if (btn.classList.contains('edit-modal-close') ||
            btn.classList.contains('btn') ||
            event.target.classList.contains('edit-modal-overlay')) {
          closeEditModal();
        }
        break;

      case 'export-locations':
        exportLocations();
        break;

      case 'import-locations':
        triggerImport();
        break;

      case 'share-location':
        // Share can come from location list (with id) or forecast view (with source)
        if (id) {
          const location = state.savedLocations.find((loc) => loc.id === id);
          if (location) {
            await shareLocation(location, btn);
          }
        } else if (state.selectedLocation) {
          await shareLocation(state.selectedLocation, btn);
        }
        break;

      case 'jump-to':
        const target = btn.dataset.target;
        if (target) {
          jumpToTarget(target);
        }
        break;
    }
  }

  /**
   * Jump to a target position in the grid.
   * @param {string} target - Target ('now', 'prev-day', 'next-day').
   */
  /**
   * Scroll behaviour honouring the user's motion preference.
   * CSS scroll-behavior does not apply to programmatic scrollTo() calls.
   * @returns {'auto'|'smooth'} Behaviour to pass to scrollTo().
   */
  function scrollBehavior() {
    return window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth';
  }

  function jumpToTarget(target) {
    const gridData = document.getElementById('grid-data');
    if (!gridData) return;

    if (target === 'now') {
      // Scroll to current hour
      const currentCol = gridData.querySelector('.current-hour');
      if (currentCol) {
        const scrollLeft = currentCol.offsetLeft - gridData.offsetWidth / 4;
        gridData.scrollTo({ left: Math.max(0, scrollLeft), behavior: scrollBehavior() });
        // Update day display after scroll settles
        setTimeout(updateCurrentDayDisplay, 100);
      }
      return;
    }

    if (target === 'prev-day' || target === 'next-day') {
      // Find current visible date based on scroll position
      const currentScroll = gridData.scrollLeft;
      let currentDateIndex = 0;
      const dayBoundaries = gridData.querySelectorAll('.grid-column.day-boundary');

      // Find which day boundary we're currently at or past
      for (let i = 0; i < dayBoundaries.length; i++) {
        if (dayBoundaries[i].offsetLeft <= currentScroll + 50) {
          currentDateIndex = i;
        } else {
          break;
        }
      }

      // Calculate target day index
      let targetIndex = target === 'prev-day' ? currentDateIndex - 1 : currentDateIndex + 1;
      targetIndex = Math.max(0, Math.min(targetIndex, dayBoundaries.length - 1));

      if (dayBoundaries[targetIndex]) {
        gridData.scrollTo({ left: dayBoundaries[targetIndex].offsetLeft, behavior: scrollBehavior() });
      }
    }
  }

  /**
   * Update the current day display based on scroll position.
   */
  function updateCurrentDayDisplay() {
    const gridData = document.getElementById('grid-data');
    const display = document.getElementById('current-day-display');
    if (!gridData || !display) return;

    // Find the first visible column based on scroll position
    const currentScroll = gridData.scrollLeft;
    const columns = gridData.querySelectorAll('.grid-column');
    let visibleDate = null;

    for (const col of columns) {
      // Find the first column that's at or past the scroll position
      if (col.offsetLeft >= currentScroll - 20) {
        visibleDate = col.dataset.date;
        break;
      }
      // Keep track of the last column we passed
      visibleDate = col.dataset.date;
    }

    if (visibleDate) {
      // Parse the date string (YYYY-MM-DD format)
      const [year, month, day] = visibleDate.split('-').map(Number);
      const date = new Date(year, month - 1, day);

      // Get day of week (first 3 letters) and date of month
      const dayOfWeek = date.toLocaleDateString('en-US', { weekday: 'short' });
      const dateOfMonth = date.getDate();

      display.textContent = `${dayOfWeek} ${dateOfMonth}`;
    }
  }

  /**
   * Handle search button click.
   */
  async function handleSearchClick() {
    const searchInput = app.querySelector('#location-search');
    const query = searchInput ? searchInput.value.trim() : '';

    if (query.length < 2) {
      state.searchResults = [];
      state.isSearching = false;
      renderApp();
      return;
    }

    state.isSearching = true;
    renderApp();

    try {
      state.searchResults = await searchLocations(query);
    } catch (e) {
      state.searchResults = [];
      console.error('Search error:', e);
    }

    state.isSearching = false;
    renderApp();

    // Restore focus to search input.
    const newSearchInput = app.querySelector('#location-search');
    if (newSearchInput) {
      newSearchInput.value = query;
      newSearchInput.focus();
    }
  }

  /**
   * Handle search input keydown.
   * @param {Event} event - Keydown event.
   */
  function handleSearchKeydown(event) {
    if (event.key === 'Enter') {
      event.preventDefault();
      handleSearchClick();
    } else if (event.key === 'Escape') {
      state.searchResults = [];
      renderApp();
    }
  }

  // ============================================================
  // TAB ACTIONS
  // ============================================================

  /**
   * Switch the active view.
   * @param {string} view - One of VIEWS.
   */
  function switchView(view) {
    if (state.activeView === view || !VIEWS.includes(view)) return;

    state.activeView = view;
    renderApp();

    if ('hours' === view) {
      scrollToCurrentHour();
    }
  }

  /**
   * Select a location and show it in the active view.
   *
   * The single path by which a location becomes the one on screen, whether it
   * came from the picker, a shared URL, GPS or the home location at launch.
   *
   * @param {Object} location - Location object.
   */
  async function selectLocation(location) {
    if (!location) return;

    state.selectedLocation = location;
    state.selectedDayIndex = 0;
    state.showLocationPicker = false;
    state.searchResults = [];
    state.error = null;

    const key = forecastKey(location);

    if (state.forecastData[key]) {
      renderApp();
      scrollToCurrentHour();
      return;
    }

    state.isLoading = true;
    renderApp();

    try {
      state.forecastData[key] = await fetchForecast(location);
    } catch (e) {
      state.error = e.message;
    }

    state.isLoading = false;
    renderApp();
    scrollToCurrentHour();
  }

  /**
   * Locate the device and select the place it reports.
   */
  async function useCurrentLocation() {
    state.showLocationPicker = false;
    state.error = null;
    state.isLoading = true;
    renderApp();

    try {
      const position = await getCurrentPosition();
      const location = await reverseGeocode(position.lat, position.lon);
      state.isLoading = false;
      await selectLocation(location);
    } catch (e) {
      state.error = e.message;
      state.isLoading = false;
      renderApp();
    }
  }

  /**
   * Select a saved location.
   *
   * Note this does NOT touch state.homeLocation. It used to, which is why
   * "Home" showed whichever location was last tapped.
   *
   * @param {number} id - Location ID.
   */
  async function viewLocation(id) {
    const location = state.savedLocations.find((loc) => loc.id === id);
    if (!location) return;
    await selectLocation(location);
  }

  /**
   * Add a new location.
   * @param {Object} locationData - Location data from search.
   */
  async function addLocation(locationData) {
    try {
      const id = await ForecastStorage.saveLocation({
        lat: locationData.lat,
        lon: locationData.lon,
        name: locationData.name,
        country: locationData.country,
        admin1: locationData.admin1,
        timezone: locationData.timezone,
      });

      await loadSavedLocations();

      // Clear search.
      state.searchResults = [];
      const searchInput = app.querySelector('#location-search');
      if (searchInput) {
        searchInput.value = '';
      }

      renderApp();
    } catch (e) {
      console.error('Error adding location:', e);
    }
  }

  /**
   * Set a location as home.
   * @param {number} id - Location ID.
   */
  async function setHomeLocation(id) {
    try {
      await ForecastStorage.setHomeLocation(id);
      await loadSavedLocations();
      renderApp();
    } catch (e) {
      console.error('Error setting home location:', e);
    }
  }

  /**
   * Delete a location.
   * @param {number} id - Location ID.
   */
  async function deleteLocation(id) {
    try {
      await ForecastStorage.deleteLocation(id);
      delete state.forecastData[id];
      await loadSavedLocations();

      // Deleting the location on screen leaves nothing selected, so fall
      // back to home, then to any saved location.
      if (state.selectedLocation && state.selectedLocation.id === id) {
        const fallback = state.homeLocation || state.savedLocations[0] || null;
        state.selectedLocation = null;
        if (fallback) {
          await selectLocation(fallback);
          return;
        }
      }

      renderApp();
    } catch (e) {
      console.error('Error deleting location:', e);
    }
  }

  /**
   * Open the edit modal for a location.
   * @param {number} id - Location ID.
   */
  function openEditModal(id) {
    const location = state.savedLocations.find((loc) => loc.id === id);
    if (!location) return;

    state.editingLocation = { ...location };
    renderApp();
  }

  /**
   * Close the edit modal without saving.
   */
  function closeEditModal() {
    state.editingLocation = null;
    renderApp();
  }

  /**
   * Save the edited location.
   */
  async function saveLocationEdit() {
    if (!state.editingLocation) return;

    const nameInput = document.getElementById('edit-name');
    const admin1Input = document.getElementById('edit-admin1');
    const notesInput = document.getElementById('edit-notes');

    if (!nameInput) return;

    const name = nameInput.value.trim();
    const admin1 = admin1Input ? admin1Input.value.trim() : '';
    const notes = notesInput ? notesInput.value.trim() : '';

    if (!name) return;

    try {
      await ForecastStorage.updateLocation(state.editingLocation.id, { name, admin1, notes });
      await loadSavedLocations();
      state.editingLocation = null;
      renderApp();
    } catch (e) {
      console.error('Error saving location:', e);
    }
  }

  /**
   * Export locations to JSON file.
   */
  async function exportLocations() {
    addDebug(`Export: Starting, ${state.savedLocations.length} locations`);

    if (state.savedLocations.length === 0) {
      addDebug('Export: No locations to export');
      alert(strings.noLocationsToExport || 'No locations to export');
      return;
    }

    const exportData = {
      version: 1,
      exported: new Date().toISOString(),
      locations: state.savedLocations.map((loc) => ({
        lat: loc.lat,
        lon: loc.lon,
        name: loc.name,
        admin1: loc.admin1,
        country: loc.country,
        timezone: loc.timezone,
        notes: loc.notes,
        isHome: loc.isHome,
      })),
    };

    const json = JSON.stringify(exportData, null, 2);
    const blob = new Blob([json], { type: 'application/json' });
    const filename = `cloud-cover-locations-${new Date().toISOString().split('T')[0]}.json`;

    // Try Web Share API first (for mobile devices)
    addDebug(`Export: Web Share API available: share=${!!navigator.share} canShare=${!!navigator.canShare}`);
    if (navigator.share && navigator.canShare) {
      const file = new File([blob], filename, { type: 'application/json' });
      const shareData = { files: [file] };
      const canShareFiles = navigator.canShare(shareData);
      addDebug(`Export: canShare(files)=${canShareFiles}`);

      try {
        if (canShareFiles) {
          addDebug('Export: Calling navigator.share()...');
          await navigator.share(shareData);
          addDebug('Export: Share completed successfully');
          return; // User completed or cancelled share - either way, we're done
        }
      } catch (e) {
        addDebug(`Export: Share error: ${e.name} - ${e.message}`);
        if (e.name === 'AbortError') {
          addDebug('Export: User cancelled share');
          return; // User cancelled, no need to fallback
        }
        // Fall through to other methods
      }
    }

    // On mobile browsers, anchor-click downloads are unreliable - prefer clipboard
    const isMobile = isIOS() || isAndroid();
    const browser = getBrowserType();
    addDebug(`Export: isMobile=${isMobile} browser=${browser}`);

    // On mobile, try clipboard first (most reliable)
    if (isMobile) {
      addDebug('Export: Mobile detected, trying clipboard first...');
      try {
        await navigator.clipboard.writeText(json);
        addDebug('Export: Clipboard write succeeded!');
        alert(strings.exportCopiedToClipboard || 'Locations copied to clipboard. Paste into a text file to save.');
        return;
      } catch (e) {
        addDebug(`Export: Clipboard failed: ${e.message}`);
        // Continue to download fallback
      }
    }

    // Try download approach (works reliably on desktop, less so on mobile)
    addDebug('Export: Trying download approach...');
    try {
      const url = URL.createObjectURL(blob);
      const a = document.createElement('a');
      a.href = url;
      a.download = filename;
      document.body.appendChild(a);
      a.click();
      document.body.removeChild(a);

      // Give the download a moment to start before revoking
      setTimeout(() => URL.revokeObjectURL(url), 1000);
      addDebug('Export: Download link clicked');

      // On desktop, assume it worked
      if (!isMobile) {
        return;
      }
    } catch (e) {
      addDebug(`Export: Download failed: ${e.message}`);
    }

    // All methods failed - show the data in a prompt so user can manually copy
    addDebug('Export: All methods failed, showing manual copy option');
    const copyManually = confirm(
      (strings.exportFailed || 'Export failed. Would you like to see the data to copy manually?')
    );
    if (copyManually) {
      // Use a textarea in a modal-like alert for better UX
      const textArea = document.createElement('textarea');
      textArea.value = json;
      textArea.style.cssText = 'position:fixed;top:10%;left:5%;width:90%;height:80%;z-index:10000;font-family:monospace;font-size:12px;';
      const closeBtn = document.createElement('button');
      closeBtn.textContent = strings.close || 'Close';
      closeBtn.style.cssText = 'position:fixed;top:5%;right:5%;z-index:10001;padding:10px 20px;';
      closeBtn.onclick = () => {
        document.body.removeChild(textArea);
        document.body.removeChild(closeBtn);
      };
      document.body.appendChild(textArea);
      document.body.appendChild(closeBtn);
      textArea.select();
    }
  }

  /**
   * Generate a shareable URL for a location.
   * @param {Object} location - Location object with lat, lon, name, admin1, country.
   * @returns {string} Shareable URL.
   */
  function getShareableUrl(location) {
    const baseUrl = window.location.origin + window.location.pathname;
    const params = new URLSearchParams();
    params.set('lat', location.lat.toFixed(4));
    params.set('lon', location.lon.toFixed(4));
    if (location.name) {
      params.set('loc', location.name);
    }
    if (location.admin1) {
      params.set('region', location.admin1);
    }
    if (location.country) {
      params.set('country', location.country);
    }
    return `${baseUrl}?${params.toString()}`;
  }

  /**
   * Show a toast message near an element.
   * @param {string} message - Message to display.
   * @param {HTMLElement} anchorElement - Element to position the toast near.
   * @param {number} duration - Duration in ms before auto-hide (default 5000).
   */
  function showToast(message, anchorElement, duration = 1000) {
    // Remove any existing toast
    const existingToast = document.querySelector('.ccf-toast');
    if (existingToast) {
      existingToast.remove();
    }

    const toast = document.createElement('div');
    toast.className = 'ccf-toast';
    toast.textContent = message;

    // Position near the anchor element
    if (anchorElement) {
      const rect = anchorElement.getBoundingClientRect();
      toast.style.position = 'fixed';
      toast.style.top = `${rect.bottom + 8}px`;
      toast.style.left = `${rect.left + rect.width / 2}px`;
      toast.style.transform = 'translateX(-50%)';
    }

    document.body.appendChild(toast);

    // Trigger animation
    requestAnimationFrame(() => {
      toast.classList.add('ccf-toast-visible');
    });

    // Auto-hide after duration
    setTimeout(() => {
      toast.classList.remove('ccf-toast-visible');
      setTimeout(() => toast.remove(), 300);
    }, duration);
  }

  /**
   * Share a location using the Web Share API or fallback to clipboard.
   * @param {Object} location - Location object with lat, lon, name, admin1, country.
   * @param {HTMLElement} buttonElement - The share button element for toast positioning.
   */
  async function shareLocation(location, buttonElement) {
    if (!location) {
      addDebug('Share: No location provided');
      return;
    }

    const displayName = location.admin1
      ? `${location.name}, ${location.admin1}`
      : location.name || `${location.lat.toFixed(4)}, ${location.lon.toFixed(4)}`;

    const shareUrl = getShareableUrl(location);
    const shareTitle = strings.shareLocationTitle || 'Cloud Cover Forecast';
    const shareText = displayName;

    addDebug(`Share: Starting share for "${displayName}"`);
    addDebug(`Share: URL = ${shareUrl}`);

    // Try Web Share API first
    if (navigator.share) {
      try {
        addDebug('Share: Using Web Share API');
        await navigator.share({
          title: shareTitle,
          text: shareText,
          url: shareUrl,
        });
        addDebug('Share: Completed successfully');
        return;
      } catch (e) {
        if (e.name === 'AbortError') {
          addDebug('Share: User cancelled');
          return;
        }
        addDebug(`Share: Web Share failed: ${e.message}`);
        // Fall through to clipboard
      }
    }

    // Fallback to clipboard
    try {
      addDebug('Share: Falling back to clipboard');
      await navigator.clipboard.writeText(shareUrl);
      showToast(strings.linkCopiedToClipboard || 'Link copied to clipboard', buttonElement);
      addDebug('Share: Copied to clipboard');
    } catch (e) {
      addDebug(`Share: Clipboard failed: ${e.message}`);
      // Last resort: show in prompt
      prompt(strings.copyLink || 'Copy link:', shareUrl);
    }
  }

  /**
   * Trigger the file input for importing locations.
   */
  function triggerImport() {
    const fileInput = document.getElementById('import-file');
    if (fileInput) {
      fileInput.click();
    }
  }

  /**
   * Handle file selection for import.
   * @param {Event} event - Change event.
   */
  async function handleImportFile(event) {
    const file = event.target.files?.[0];
    if (!file) return;

    try {
      const text = await file.text();
      const data = JSON.parse(text);

      // Validate structure
      if (!data.locations || !Array.isArray(data.locations)) {
        throw new Error('Invalid file format');
      }

      let imported = 0;
      let skipped = 0;

      for (const loc of data.locations) {
        // Validate required fields
        if (typeof loc.lat !== 'number' || typeof loc.lon !== 'number' || !loc.name) {
          skipped++;
          continue;
        }

        // Check for duplicate by proximity
        if (isLocationSaved(loc.lat, loc.lon)) {
          skipped++;
          continue;
        }

        // Save the location (don't preserve isHome from import to avoid conflicts)
        await ForecastStorage.saveLocation({
          lat: loc.lat,
          lon: loc.lon,
          name: loc.name,
          admin1: loc.admin1,
          country: loc.country,
          timezone: loc.timezone,
          notes: loc.notes,
        });
        imported++;
      }

      await loadSavedLocations();
      renderApp();

      // Show result message
      const message = imported > 0
        ? `${strings.importedLocations || 'Imported'}: ${imported}${skipped > 0 ? ` (${skipped} ${strings.skipped || 'skipped'})` : ''}`
        : strings.noNewLocations || 'No new locations to import';
      alert(message);
    } catch (e) {
      console.error('Import error:', e);
      alert(strings.importError || 'Failed to import locations. Please check the file format.');
    }

    // Reset file input
    event.target.value = '';
  }

  /**
   * Load saved locations from storage.
   */
  async function loadSavedLocations() {
    try {
      state.savedLocations = await ForecastStorage.getLocations();
      state.homeLocation = await ForecastStorage.getHomeLocation();
    } catch (e) {
      console.error('Error loading locations:', e);
      state.savedLocations = [];
      state.homeLocation = null;
    }
  }

  /**
   * Scroll the forecast grid to the current hour.
   */
  function scrollToCurrentHour() {
    requestAnimationFrame(() => {
      const grid = document.getElementById('grid-data');
      const currentCol = grid?.querySelector('.current-hour');
      if (currentCol && grid) {
        const scrollLeft = currentCol.offsetLeft - grid.offsetWidth / 4;
        grid.scrollTo({ left: Math.max(0, scrollLeft), behavior: scrollBehavior() });
      }
      // Set up scroll listener and update day display
      setupGridScrollListener();
      updateCurrentDayDisplay();
    });
  }

  /** Debounce timer for scroll event. */
  let scrollDebounceTimer = null;

  /**
   * Set up scroll listener on the grid to update day display.
   */
  function setupGridScrollListener() {
    const grid = document.getElementById('grid-data');
    if (!grid || grid.dataset.scrollListenerAttached) return;

    grid.addEventListener('scroll', () => {
      // Debounce to avoid excessive updates
      if (scrollDebounceTimer) clearTimeout(scrollDebounceTimer);
      scrollDebounceTimer = setTimeout(updateCurrentDayDisplay, 50);
    });
    grid.dataset.scrollListenerAttached = 'true';
  }

  // ============================================================
  // ONLINE/OFFLINE HANDLING
  // ============================================================

  window.addEventListener('online', () => {
    state.isOnline = true;
    renderApp();
  });

  window.addEventListener('offline', () => {
    state.isOnline = false;
    renderApp();
  });

  // ============================================================
  // APP INITIALIZATION
  // ============================================================

  /**
   * Check for shared location in URL parameters.
   * URL format: /forecast-app/?lat=51.8986&lon=-8.4756&name=Cork&region=Cork&country=Ireland
   * @returns {Object|null} Location object or null if no valid params.
   */
  function parseSharedLocationFromUrl() {
    const params = new URLSearchParams(window.location.search);
    const lat = parseFloat(params.get('lat'));
    const lon = parseFloat(params.get('lon'));

    if (isNaN(lat) || isNaN(lon)) {
      return null;
    }

    // Validate coordinate ranges
    if (lat < -90 || lat > 90 || lon < -180 || lon > 180) {
      addDebug(`Shared location: Invalid coordinates lat=${lat} lon=${lon}`);
      return null;
    }

    const name = params.get('loc') || `${lat.toFixed(4)}, ${lon.toFixed(4)}`;
    const admin1 = params.get('region') || '';
    const country = params.get('country') || '';

    addDebug(`Shared location: ${name} (${lat}, ${lon})`);

    return {
      lat,
      lon,
      name: decodeURIComponent(name),
      admin1: admin1 ? decodeURIComponent(admin1) : '',
      country: country ? decodeURIComponent(country) : '',
    };
  }

  /**
   * Load forecast for a shared location from URL parameters.
   */
  async function loadSharedLocation() {
    const location = parseSharedLocationFromUrl();
    if (!location) return;

    await selectLocation(location);

    if (state.error) {
      addDebug(`Shared location error: ${state.error}`);
    }
  }

  async function init() {
    // Show debug info at startup
    if (DEBUG_MODE) {
      addDebug('=== App Starting ===');
      addDebug(`UA: ${navigator.userAgent}`);
      addDebug(`Browser: ${getBrowserType()}`);
      addDebug(`Service Worker: ${('serviceWorker' in navigator) ? 'supported' : 'not supported'}`);
    }

    try {
      // Apply saved theme and font size.
      applyTheme();
      applyFontSize();

      // Open database and load saved data.
      await ForecastStorage.openDatabase();
      await loadSavedLocations();

      if (DEBUG_MODE) {
        addDebug(`Loaded ${state.savedLocations.length} locations`);
      }

      // Clean expired cache.
      await ForecastStorage.cleanExpiredCache();

      // Check for shared location in URL parameters.
      const hasSharedLocation = parseSharedLocationFromUrl() !== null;

      // Render initial UI.
      renderApp();

      if (hasSharedLocation) {
        // A shared URL wins over the home location.
        await loadSharedLocation();
      } else if (state.homeLocation) {
        await selectLocation(state.homeLocation);
      } else {
        // Nothing to show yet, so open the picker rather than an empty view.
        state.showLocationPicker = true;
        renderApp();
      }

      if (DEBUG_MODE) {
        addDebug('App rendered successfully');
      }
    } catch (e) {
      console.error('App initialization error:', e);
      addDebug(`Init error: ${e.message}`);
      state.error = e.message;
      renderApp();
    }
  }

  // Start the app.
  init();
})(window);
