/**
 * Cloud Cover Forecast - IndexedDB Storage Layer
 *
 * Provides persistent storage for saved locations, settings, and cached forecasts.
 *
 * @package CloudCoverForecast
 * @since 1.0.0
 */

(function (global) {
  'use strict';

  const DB_NAME = 'CloudCoverForecastDB';
  const DB_VERSION = 1;

  // Store names.
  const STORES = {
    LOCATIONS: 'locations',
    SETTINGS: 'settings',
    FORECAST_CACHE: 'forecastCache',
  };

  let db = null;
  let dbReady = null;

  /**
   * Open the IndexedDB database.
   * @returns {Promise<IDBDatabase>}
   */
  function openDatabase() {
    if (dbReady) {
      return dbReady;
    }

    dbReady = new Promise((resolve, reject) => {
      const request = indexedDB.open(DB_NAME, DB_VERSION);

      request.onupgradeneeded = (event) => {
        const database = event.target.result;

        // Locations store.
        if (!database.objectStoreNames.contains(STORES.LOCATIONS)) {
          const locationStore = database.createObjectStore(STORES.LOCATIONS, {
            keyPath: 'id',
            autoIncrement: true,
          });
          locationStore.createIndex('name', 'name', { unique: false });
          locationStore.createIndex('isHome', 'isHome', { unique: false });
        }

        // Settings store.
        if (!database.objectStoreNames.contains(STORES.SETTINGS)) {
          database.createObjectStore(STORES.SETTINGS, { keyPath: 'key' });
        }

        // Forecast cache store.
        if (!database.objectStoreNames.contains(STORES.FORECAST_CACHE)) {
          const cacheStore = database.createObjectStore(STORES.FORECAST_CACHE, {
            keyPath: 'locationId',
          });
          cacheStore.createIndex('timestamp', 'timestamp', { unique: false });
        }
      };

      request.onsuccess = (event) => {
        db = event.target.result;
        resolve(db);
      };

      request.onerror = (event) => {
        console.error('IndexedDB error:', event.target.error);
        reject(event.target.error);
      };
    });

    return dbReady;
  }

  /**
   * Execute a transaction on the database.
   * @param {string} storeName - Store name.
   * @param {string} mode - Transaction mode ('readonly' or 'readwrite').
   * @param {Function} callback - Callback that receives the object store.
   * @returns {Promise}
   */
  async function transaction(storeName, mode, callback) {
    const database = await openDatabase();
    return new Promise((resolve, reject) => {
      const tx = database.transaction(storeName, mode);
      const store = tx.objectStore(storeName);

      tx.oncomplete = () => resolve();
      tx.onerror = () => reject(tx.error);

      callback(store, resolve, reject);
    });
  }

  // ============================================================
  // LOCATIONS
  // ============================================================

  /**
   * Save a location.
   * @param {Object} location - Location object with lat, lon, name.
   * @returns {Promise<number>} Location ID.
   */
  async function saveLocation(location) {
    const database = await openDatabase();
    return new Promise((resolve, reject) => {
      const tx = database.transaction(STORES.LOCATIONS, 'readwrite');
      const store = tx.objectStore(STORES.LOCATIONS);

      // If no locations exist, make this the home location.
      const countRequest = store.count();
      countRequest.onsuccess = () => {
        const isFirst = countRequest.result === 0;
        const locationData = {
          ...location,
          isHome: isFirst || location.isHome || false,
          createdAt: location.createdAt || Date.now(),
          updatedAt: Date.now(),
        };

        // If this is being set as home, unset other home locations.
        if (locationData.isHome) {
          const cursorRequest = store.openCursor();
          cursorRequest.onsuccess = (event) => {
            const cursor = event.target.result;
            if (cursor) {
              if (cursor.value.isHome && cursor.value.id !== locationData.id) {
                cursor.update({ ...cursor.value, isHome: false });
              }
              cursor.continue();
            }
          };
        }

        const request = location.id ? store.put(locationData) : store.add(locationData);
        request.onsuccess = () => resolve(request.result);
        request.onerror = () => reject(request.error);
      };
    });
  }

  /**
   * Get all saved locations.
   * @returns {Promise<Array>} Array of locations.
   */
  async function getLocations() {
    const database = await openDatabase();
    return new Promise((resolve, reject) => {
      const tx = database.transaction(STORES.LOCATIONS, 'readonly');
      const store = tx.objectStore(STORES.LOCATIONS);
      const request = store.getAll();

      request.onsuccess = () => {
        // Sort: home location first, then by name.
        const locations = request.result.sort((a, b) => {
          if (a.isHome && !b.isHome) return -1;
          if (!a.isHome && b.isHome) return 1;
          return (a.name || '').localeCompare(b.name || '');
        });
        resolve(locations);
      };
      request.onerror = () => reject(request.error);
    });
  }

  /**
   * Get a location by ID.
   * @param {number} id - Location ID.
   * @returns {Promise<Object|null>} Location object or null.
   */
  async function getLocation(id) {
    const database = await openDatabase();
    return new Promise((resolve, reject) => {
      const tx = database.transaction(STORES.LOCATIONS, 'readonly');
      const store = tx.objectStore(STORES.LOCATIONS);
      const request = store.get(id);

      request.onsuccess = () => resolve(request.result || null);
      request.onerror = () => reject(request.error);
    });
  }

  /**
   * Get the home location.
   * @returns {Promise<Object|null>} Home location or null.
   */
  async function getHomeLocation() {
    const locations = await getLocations();
    return locations.find((loc) => loc.isHome) || locations[0] || null;
  }

  /**
   * Set a location as home.
   * @param {number} id - Location ID.
   * @returns {Promise}
   */
  async function setHomeLocation(id) {
    const database = await openDatabase();
    return new Promise((resolve, reject) => {
      const tx = database.transaction(STORES.LOCATIONS, 'readwrite');
      const store = tx.objectStore(STORES.LOCATIONS);

      // First, unset all home flags.
      const cursorRequest = store.openCursor();
      cursorRequest.onsuccess = (event) => {
        const cursor = event.target.result;
        if (cursor) {
          const isTarget = cursor.value.id === id;
          if (cursor.value.isHome !== isTarget) {
            cursor.update({ ...cursor.value, isHome: isTarget, updatedAt: Date.now() });
          }
          cursor.continue();
        }
      };

      tx.oncomplete = () => resolve();
      tx.onerror = () => reject(tx.error);
    });
  }

  /**
   * Delete a location.
   * @param {number} id - Location ID.
   * @returns {Promise}
   */
  async function deleteLocation(id) {
    const database = await openDatabase();
    return new Promise((resolve, reject) => {
      const tx = database.transaction(STORES.LOCATIONS, 'readwrite');
      const store = tx.objectStore(STORES.LOCATIONS);
      const request = store.delete(id);

      request.onsuccess = () => resolve();
      request.onerror = () => reject(request.error);
    });
  }

  // ============================================================
  // SETTINGS
  // ============================================================

  /**
   * Get a setting value.
   * @param {string} key - Setting key.
   * @param {*} defaultValue - Default value if not found.
   * @returns {Promise<*>} Setting value.
   */
  async function getSetting(key, defaultValue = null) {
    const database = await openDatabase();
    return new Promise((resolve, reject) => {
      const tx = database.transaction(STORES.SETTINGS, 'readonly');
      const store = tx.objectStore(STORES.SETTINGS);
      const request = store.get(key);

      request.onsuccess = () => {
        resolve(request.result ? request.result.value : defaultValue);
      };
      request.onerror = () => reject(request.error);
    });
  }

  /**
   * Set a setting value.
   * @param {string} key - Setting key.
   * @param {*} value - Setting value.
   * @returns {Promise}
   */
  async function setSetting(key, value) {
    const database = await openDatabase();
    return new Promise((resolve, reject) => {
      const tx = database.transaction(STORES.SETTINGS, 'readwrite');
      const store = tx.objectStore(STORES.SETTINGS);
      const request = store.put({ key, value, updatedAt: Date.now() });

      request.onsuccess = () => resolve();
      request.onerror = () => reject(request.error);
    });
  }

  // ============================================================
  // FORECAST CACHE
  // ============================================================

  /**
   * Cache forecast data for a location.
   * @param {number} locationId - Location ID.
   * @param {Object} data - Forecast data.
   * @returns {Promise}
   */
  async function cacheForecast(locationId, data) {
    const database = await openDatabase();
    return new Promise((resolve, reject) => {
      const tx = database.transaction(STORES.FORECAST_CACHE, 'readwrite');
      const store = tx.objectStore(STORES.FORECAST_CACHE);
      const request = store.put({
        locationId,
        data,
        timestamp: Date.now(),
      });

      request.onsuccess = () => resolve();
      request.onerror = () => reject(request.error);
    });
  }

  /**
   * Get cached forecast for a location.
   * @param {number} locationId - Location ID.
   * @param {number} maxAge - Maximum cache age in milliseconds (default 15 min).
   * @returns {Promise<Object|null>} Cached forecast or null.
   */
  async function getCachedForecast(locationId, maxAge = 15 * 60 * 1000) {
    const database = await openDatabase();
    return new Promise((resolve, reject) => {
      const tx = database.transaction(STORES.FORECAST_CACHE, 'readonly');
      const store = tx.objectStore(STORES.FORECAST_CACHE);
      const request = store.get(locationId);

      request.onsuccess = () => {
        const cached = request.result;
        if (!cached) {
          resolve(null);
          return;
        }

        const age = Date.now() - cached.timestamp;
        if (age > maxAge) {
          resolve(null);
          return;
        }

        resolve(cached.data);
      };
      request.onerror = () => reject(request.error);
    });
  }

  /**
   * Clear all cached forecasts.
   * @returns {Promise}
   */
  async function clearForecastCache() {
    const database = await openDatabase();
    return new Promise((resolve, reject) => {
      const tx = database.transaction(STORES.FORECAST_CACHE, 'readwrite');
      const store = tx.objectStore(STORES.FORECAST_CACHE);
      const request = store.clear();

      request.onsuccess = () => resolve();
      request.onerror = () => reject(request.error);
    });
  }

  /**
   * Clean up expired cache entries.
   * @param {number} maxAge - Maximum cache age in milliseconds.
   * @returns {Promise}
   */
  async function cleanExpiredCache(maxAge = 60 * 60 * 1000) {
    const database = await openDatabase();
    return new Promise((resolve, reject) => {
      const tx = database.transaction(STORES.FORECAST_CACHE, 'readwrite');
      const store = tx.objectStore(STORES.FORECAST_CACHE);
      const index = store.index('timestamp');
      const cutoff = Date.now() - maxAge;
      const range = IDBKeyRange.upperBound(cutoff);

      const request = index.openCursor(range);
      request.onsuccess = (event) => {
        const cursor = event.target.result;
        if (cursor) {
          cursor.delete();
          cursor.continue();
        }
      };

      tx.oncomplete = () => resolve();
      tx.onerror = () => reject(tx.error);
    });
  }

  // ============================================================
  // EXPORT
  // ============================================================

  const ForecastStorage = {
    // Database.
    openDatabase,

    // Locations.
    saveLocation,
    getLocations,
    getLocation,
    getHomeLocation,
    setHomeLocation,
    deleteLocation,

    // Settings.
    getSetting,
    setSetting,

    // Forecast cache.
    cacheForecast,
    getCachedForecast,
    clearForecastCache,
    cleanExpiredCache,
  };

  // Export to global scope.
  global.ForecastStorage = ForecastStorage;
})(window);
