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

  const { CCF_CONFIG, ForecastStorage } = global;
  const { ajaxUrl, nonce, strings } = CCF_CONFIG;

  // ============================================================
  // APP STATE
  // ============================================================

  const state = {
    activeTab: 'home',
    homeLocation: null,
    currentLocation: null,
    savedLocations: [],
    forecastData: {},
    isLoading: false,
    error: null,
    isOnline: navigator.onLine,
    searchResults: [],
    isSearching: false,
  };

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
      nonce,
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
   * @returns {string} Formatted string.
   */
  function formatDateTime(isoString, format = 'time') {
    const date = new Date(isoString);
    const options = {};

    switch (format) {
      case 'time':
        return date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', hour12: false });
      case 'date':
        return date.toLocaleDateString([], { weekday: 'short', day: 'numeric', month: 'short' });
      case 'datetime':
        return date.toLocaleString([], {
          weekday: 'short',
          day: 'numeric',
          month: 'short',
          hour: '2-digit',
          minute: '2-digit',
          hour12: false,
        });
      case 'day':
        return date.toLocaleDateString([], { weekday: 'short' });
      case 'hour':
        return date.toLocaleTimeString([], { hour: '2-digit', hour12: false });
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
  // COLOR SCHEMES
  // ============================================================

  const COLOR_THRESHOLDS = {
    cloud: [[25, 'excellent'], [50, 'good'], [75, 'fair'], [100, 'poor']],
    rain: [[10, 'excellent'], [30, 'good'], [60, 'fair'], [100, 'poor']],
    humidity: [[70, 'excellent'], [80, 'good'], [90, 'fair'], [100, 'poor']],
    wind: [[15, 'excellent'], [30, 'good'], [50, 'fair'], [200, 'poor']],
    visibility: [[1000, 'poor'], [5000, 'fair'], [10000, 'good'], [Infinity, 'excellent']],
  };

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
   * Reverse geocode coordinates to location name.
   * @param {number} lat - Latitude.
   * @param {number} lon - Longitude.
   * @returns {Promise<string>} Location name.
   */
  async function reverseGeocode(lat, lon) {
    try {
      const results = await searchLocations(`${lat.toFixed(4)},${lon.toFixed(4)}`);
      if (results.length > 0) {
        const loc = results[0];
        return loc.admin1 ? `${loc.name}, ${loc.admin1}` : loc.name;
      }
    } catch (e) {
      // Fallback to coordinates.
    }
    return `${lat.toFixed(4)}, ${lon.toFixed(4)}`;
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
          <h1 class="app-title">${escapeHtml(strings.appTitle)}</h1>
          <div class="app-status">
            ${!state.isOnline ? `<span class="offline-badge">${escapeHtml(strings.offline)}</span>` : ''}
          </div>
        </div>
        <nav class="app-tabs">
          <button class="tab-btn ${state.activeTab === 'home' ? 'active' : ''}" data-tab="home">
            ${escapeHtml(strings.home)}
          </button>
          <button class="tab-btn ${state.activeTab === 'current' ? 'active' : ''}" data-tab="current">
            ${escapeHtml(strings.current)}
          </button>
          <button class="tab-btn ${state.activeTab === 'locations' ? 'active' : ''}" data-tab="locations">
            ${escapeHtml(strings.locations)}
          </button>
        </nav>
      </header>
      <main class="app-content" id="app-content">
        ${renderTabContent()}
      </main>
    `;

    attachEventListeners();
  }

  /**
   * Render the current tab content.
   * @returns {string} HTML string.
   */
  function renderTabContent() {
    switch (state.activeTab) {
      case 'home':
        return renderHomeTab();
      case 'current':
        return renderCurrentTab();
      case 'locations':
        return renderLocationsTab();
      default:
        return '';
    }
  }

  /**
   * Render the home tab.
   * @returns {string} HTML string.
   */
  function renderHomeTab() {
    if (state.isLoading) {
      return renderLoading();
    }

    if (state.error) {
      return renderError(state.error);
    }

    if (!state.homeLocation) {
      return `
        <div class="empty-state">
          <div class="empty-icon">&#127968;</div>
          <h2>${escapeHtml(strings.noHomeLocation)}</h2>
          <p>${escapeHtml(strings.addFirstLocation)}</p>
          <button class="btn btn-primary" data-action="go-to-locations">
            ${escapeHtml(strings.goToLocations)}
          </button>
        </div>
      `;
    }

    const forecast = state.forecastData[state.homeLocation.id];
    if (!forecast) {
      return renderLoading();
    }

    return renderForecastView(state.homeLocation, forecast);
  }

  /**
   * Render the current location tab.
   * @returns {string} HTML string.
   */
  function renderCurrentTab() {
    if (state.isLoading) {
      return `
        <div class="loading-state">
          <div class="loading-spinner"></div>
          <p>${escapeHtml(strings.gettingLocation)}</p>
        </div>
      `;
    }

    if (state.error) {
      if (state.error.includes('denied') || state.error.includes('permission')) {
        return `
          <div class="empty-state">
            <div class="empty-icon">&#128205;</div>
            <h2>${escapeHtml(strings.locationDenied)}</h2>
            <p>${escapeHtml(strings.enableLocation)}</p>
            <button class="btn btn-primary" data-action="retry-location">
              ${escapeHtml(strings.retry)}
            </button>
          </div>
        `;
      }
      return renderError(state.error);
    }

    if (!state.currentLocation) {
      return `
        <div class="loading-state">
          <div class="loading-spinner"></div>
          <p>${escapeHtml(strings.gettingLocation)}</p>
        </div>
      `;
    }

    const forecast = state.forecastData['current'];
    if (!forecast) {
      return renderLoading();
    }

    return renderForecastView(state.currentLocation, forecast);
  }

  /**
   * Render the locations tab.
   * @returns {string} HTML string.
   */
  function renderLocationsTab() {
    return `
      <div class="locations-panel">
        <div class="search-box">
          <input
            type="text"
            class="search-input"
            id="location-search"
            placeholder="${escapeHtml(strings.searchLocation)}"
            autocomplete="off"
          >
          <button class="search-btn" id="search-btn" ${state.isSearching ? 'disabled' : ''}>
            ${state.isSearching ? escapeHtml(strings.loading) : 'Search'}
          </button>
        </div>
        ${state.searchResults.length > 0 ? renderSearchResults() : ''}
        <div class="saved-locations">
          <h2>${escapeHtml(strings.locations)}</h2>
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
            ${location.isHome ? '<span class="home-badge">&#127968;</span>' : ''}
            ${escapeHtml(displayName)}
          </span>
          <span class="location-coords">${location.lat.toFixed(2)}, ${location.lon.toFixed(2)}</span>
        </button>
        <div class="location-actions">
          <a href="${mapsUrl}" target="_blank" rel="noopener" class="btn btn-icon" title="View on Google Maps">
            &#128205;
          </a>
          ${!location.isHome ? `
            <button class="btn btn-icon" data-action="set-home" data-id="${location.id}" title="${escapeHtml(strings.setAsHome)}">
              &#127968;
            </button>
          ` : ''}
          <button class="btn btn-icon btn-danger" data-action="delete-location" data-id="${location.id}" title="${escapeHtml(strings.delete)}">
            &#128465;
          </button>
        </div>
      </li>
    `;
  }

  /**
   * Render the forecast view.
   * @param {Object} location - Location object.
   * @param {Object} forecast - Forecast data.
   * @returns {string} HTML string.
   */
  function renderForecastView(location, forecast) {
    const displayName = location.admin1
      ? `${location.name}, ${location.admin1}`
      : location.name || `${location.lat.toFixed(2)}, ${location.lon.toFixed(2)}`;
    const mapsUrl = getGoogleMapsUrl(location.lat, location.lon);

    return `
      <div class="forecast-view">
        <div class="forecast-header">
          <h2 class="forecast-location">
            ${escapeHtml(displayName)}
            <a href="${mapsUrl}" target="_blank" rel="noopener" class="maps-link" title="View on Google Maps">&#128205;</a>
          </h2>
          ${forecast.location?.timezone_abbr ? `
            <span class="forecast-timezone">${escapeHtml(forecast.location.timezone_abbr)}</span>
          ` : ''}
        </div>
        ${renderSolarInfo(forecast)}
        ${renderLunarInfo(forecast)}
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

    const now = new Date();
    const currentHour = now.getHours();
    const todayStr = now.toISOString().split('T')[0];

    // Group hours by day for date headers.
    let lastDate = '';
    let currentHourIndex = -1;

    // Find current hour index for auto-scroll.
    hourly.forEach((hour, index) => {
      const hourDate = new Date(hour.time);
      if (hourDate.toISOString().split('T')[0] === todayStr && hourDate.getHours() === currentHour) {
        currentHourIndex = index;
      }
    });

    return `
      <div class="forecast-grid-container" id="forecast-grid">
        <div class="forecast-grid">
          ${renderGridHeader()}
          ${renderGridBody(hourly, forecast, todayStr, currentHourIndex)}
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
  function renderGridBody(hourly, forecast, todayStr, currentHourIndex) {
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
          const hourDate = new Date(hour.time);
          const dateStr = hourDate.toISOString().split('T')[0];
          const isNewDay = dateStr !== lastDate;
          lastDate = dateStr;
          const isCurrent = index === currentHourIndex;
          const isPast = hourDate < new Date();
          const dayMoon = forecast.moon?.[dateStr];
          const dayData = dailyByDate[dateStr];

          return renderHourColumn(hour, index, isNewDay, isCurrent, isPast, dayMoon, dayData);
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
   * @returns {string} HTML string.
   */
  function renderHourColumn(hour, index, isNewDay, isCurrent, isPast, moon, dayData) {
    const hourDate = new Date(hour.time);
    const timeStr = formatDateTime(hour.time, 'hour');
    const dayLabel = isNewDay ? formatDateTime(hour.time, 'day') : '';
    const wind = getWindDirection(hour.wind_direction);
    const visKm = hour.visibility != null ? (hour.visibility / 1000).toFixed(1) : '-';

    const sunlightClass = getSunlightClass(hour, hourDate, dayData);
    const moonVisible = isMoonVisible(hourDate, moon);
    const moonIllumination = moon ? moon.moon_illumination : 0;

    return `
      <div class="grid-column ${isCurrent ? 'current-hour' : ''} ${isPast ? 'past-hour' : ''}" data-index="${index}">
        <div class="grid-cell time-cell ${isNewDay ? 'new-day' : ''}">
          ${dayLabel ? `<span class="day-label">${escapeHtml(dayLabel)}</span>` : ''}
          <span class="hour-label">${escapeHtml(timeStr)}</span>
          ${isCurrent ? `<span class="now-badge">${escapeHtml(strings.now)}</span>` : ''}
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
   * Parse a time string to timestamp for a given date.
   * @param {string} dateStr - Date string (YYYY-MM-DD).
   * @param {string} timeStr - Time string (HH:MM).
   * @returns {number|null} Timestamp or null if invalid.
   */
  function parseTimeToTimestamp(dateStr, timeStr) {
    if (!timeStr) return null;
    return new Date(`${dateStr}T${timeStr}`).getTime();
  }

  /**
   * Get sunlight class based on is_day fallback.
   * @param {Object} hour - Hour data.
   * @returns {string} CSS class.
   */
  function getSunlightFallback(hour) {
    if (hour.is_day === 1) return 'sunlight-day';
    if (hour.is_day === 0) return 'sunlight-night';
    return '';
  }

  /**
   * Get sunlight class for an hour (including blue hour and golden hour).
   * @param {Object} hour - Hour data.
   * @param {Date} hourDate - Date object for this hour.
   * @param {Object} dayData - Daily data with sunrise/sunset/twilight info.
   * @returns {string} CSS class.
   */
  function getSunlightClass(hour, hourDate, dayData) {
    if (!dayData) {
      return getSunlightFallback(hour);
    }

    const twilight = dayData.twilight || {};
    const sunriseStr = twilight.sunrise || dayData.sunrise;
    const sunsetStr = twilight.sunset || dayData.sunset;

    if (!sunriseStr || !sunsetStr) {
      return getSunlightFallback(hour);
    }

    const dateStr = dayData.date;
    const hourTs = hourDate.getTime();
    const sunriseTs = parseTimeToTimestamp(dateStr, sunriseStr);
    const sunsetTs = parseTimeToTimestamp(dateStr, sunsetStr);

    // Duration constants
    const HOUR_MS = 60 * 60 * 1000;
    const BLUE_HOUR_MS = 35 * 60 * 1000;

    // Golden hour boundaries
    const goldenMorningEnd = sunriseTs + HOUR_MS;
    const goldenEveningStart = sunsetTs - HOUR_MS;

    // Blue hour boundaries (use civil twilight if available, otherwise estimate)
    const civilDawnTs = parseTimeToTimestamp(dateStr, twilight.civil_dawn) || (sunriseTs - BLUE_HOUR_MS);
    const civilDuskTs = parseTimeToTimestamp(dateStr, twilight.civil_dusk) || (sunsetTs + BLUE_HOUR_MS);

    // Determine sunlight class based on time of day
    if (hourTs >= civilDawnTs && hourTs < sunriseTs) return 'sunlight-blue';
    if (hourTs >= sunriseTs && hourTs < goldenMorningEnd) return 'sunlight-golden';
    if (hourTs >= goldenMorningEnd && hourTs < goldenEveningStart) return 'sunlight-day';
    if (hourTs >= goldenEveningStart && hourTs < sunsetTs) return 'sunlight-golden';
    if (hourTs >= sunsetTs && hourTs < civilDuskTs) return 'sunlight-blue';

    return 'sunlight-night';
  }

  /**
   * Determine if the moon is visible during a given hour.
   * @param {Date} hourDate - Date object for this hour.
   * @param {Object} moon - Moon data for this day.
   * @returns {boolean} True if moon is visible.
   */
  function isMoonVisible(hourDate, moon) {
    if (!moon) return false;

    const hourTs = hourDate.getTime();
    const dateStr = hourDate.toISOString().split('T')[0];
    const moonriseTs = parseTimeToTimestamp(dateStr, moon.moonrise);
    const moonsetTs = parseTimeToTimestamp(dateStr, moon.moonset);

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
      <div class="loading-state">
        <div class="loading-spinner"></div>
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
      <div class="error-state">
        <div class="error-icon">&#9888;</div>
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
      btn.addEventListener('click', () => switchTab(btn.dataset.tab));
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
      case 'go-to-locations':
        switchTab('locations');
        break;

      case 'retry':
      case 'retry-location':
        state.error = null;
        if (state.activeTab === 'home') {
          loadHomeTab();
        } else if (state.activeTab === 'current') {
          loadCurrentTab();
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

      case 'set-home':
        await setHomeLocation(id);
        break;

      case 'delete-location':
        await deleteLocation(id);
        break;
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
   * Switch to a tab.
   * @param {string} tab - Tab name.
   */
  function switchTab(tab) {
    if (state.activeTab === tab) return;

    state.activeTab = tab;
    state.error = null;
    state.searchResults = [];

    renderApp();

    if (tab === 'home') {
      loadHomeTab();
    } else if (tab === 'current') {
      loadCurrentTab();
    }
  }

  /**
   * Load the home tab data.
   */
  async function loadHomeTab() {
    if (!state.homeLocation) {
      renderApp();
      return;
    }

    if (!state.forecastData[state.homeLocation.id]) {
      state.isLoading = true;
      renderApp();

      try {
        const forecast = await fetchForecast(state.homeLocation);
        state.forecastData[state.homeLocation.id] = forecast;
        state.error = null;
      } catch (e) {
        state.error = e.message;
      }

      state.isLoading = false;
      renderApp();
      scrollToCurrentHour();
    }
  }

  /**
   * Load the current location tab data.
   */
  async function loadCurrentTab() {
    state.isLoading = true;
    state.error = null;
    renderApp();

    try {
      const position = await getCurrentPosition();
      const name = await reverseGeocode(position.lat, position.lon);

      state.currentLocation = {
        lat: position.lat,
        lon: position.lon,
        name,
      };

      const forecast = await fetchForecast(state.currentLocation);
      state.forecastData['current'] = forecast;
    } catch (e) {
      state.error = e.message;
    }

    state.isLoading = false;
    renderApp();
    scrollToCurrentHour();
  }

  /**
   * View a saved location's forecast.
   * @param {number} id - Location ID.
   */
  async function viewLocation(id) {
    const location = state.savedLocations.find((loc) => loc.id === id);
    if (!location) return;

    state.homeLocation = location;
    state.activeTab = 'home';
    state.error = null;
    state.isLoading = true;
    renderApp();

    try {
      const forecast = await fetchForecast(location);
      state.forecastData[location.id] = forecast;
    } catch (e) {
      state.error = e.message;
    }

    state.isLoading = false;
    renderApp();
    scrollToCurrentHour();
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
      renderApp();
    } catch (e) {
      console.error('Error deleting location:', e);
    }
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
        grid.scrollTo({ left: Math.max(0, scrollLeft), behavior: 'smooth' });
      }
    });
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

  async function init() {
    try {
      // Open database and load saved data.
      await ForecastStorage.openDatabase();
      await loadSavedLocations();

      // Clean expired cache.
      await ForecastStorage.cleanExpiredCache();

      // Render initial UI.
      renderApp();

      // Load home tab data if we have a home location.
      if (state.homeLocation) {
        loadHomeTab();
      }
    } catch (e) {
      console.error('App initialization error:', e);
      state.error = e.message;
      renderApp();
    }
  }

  // Start the app.
  init();
})(window);
