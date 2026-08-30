/**
 * Cloud Cover Forecast - Scoring and Light Phases
 *
 * Pure functions deriving photographic qualities from forecast data:
 * time parsing, light-phase classification, and photography scores.
 * No DOM access and no app state, so each function can be reasoned
 * about (and checked) on its own.
 *
 * @package CloudCoverForecast
 * @since 1.0.0
 */

(function (global) {
  'use strict';

  /**
   * Parse a time string to timestamp for a given date.
   * @param {string} dateStr - Date string (YYYY-MM-DD).
   * @param {string} timeStr - Time string (HH:MM).
   * @param {string} timezone - Optional timezone identifier.
   * @returns {number|null} Timestamp or null if invalid.
   */
  function parseTimeToTimestamp(dateStr, timeStr, timezone) {
    if (!timeStr) return null;
    // If timezone provided, create date in that timezone
    if (timezone) {
      // Parse the time components
      const [hours, minutes] = timeStr.split(':').map(Number);
      // Create a date string and use the timezone to get correct UTC time
      const localDateStr = `${dateStr}T${timeStr}:00`;
      // Create date object and get its representation in the target timezone
      // We need to find what UTC time corresponds to this local time in the given timezone
      const tempDate = new Date(localDateStr);
      const utcDate = new Date(tempDate.toLocaleString('en-US', { timeZone: 'UTC' }));
      const tzDate = new Date(tempDate.toLocaleString('en-US', { timeZone: timezone }));
      const offset = utcDate.getTime() - tzDate.getTime();
      const ts = tempDate.getTime() + offset;
      return isNaN(ts) ? null : ts;
    }
    const ts = new Date(`${dateStr}T${timeStr}`).getTime();
    return isNaN(ts) ? null : ts;
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
  function getSunlightClass(hour, hourDate, dayData, timezone) {
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
    const sunriseTs = parseTimeToTimestamp(dateStr, sunriseStr, timezone);
    const sunsetTs = parseTimeToTimestamp(dateStr, sunsetStr, timezone);

    if (!sunriseTs || !sunsetTs) {
      return getSunlightFallback(hour);
    }

    // Duration constants
    const HOUR_MS = 60 * 60 * 1000;
    const BLUE_HOUR_MS = 60 * 60 * 1000; // 1 hour fallback for blue hour

    // Golden hour boundaries
    const goldenMorningEnd = sunriseTs + HOUR_MS;
    const goldenEveningStart = sunsetTs - HOUR_MS;

    // Blue hour boundaries - ensure at least 1 hour window so it's visible in hourly grid
    const parsedCivilDawn = parseTimeToTimestamp(dateStr, twilight.civil_dawn, timezone);
    const parsedCivilDusk = parseTimeToTimestamp(dateStr, twilight.civil_dusk, timezone);
    // Use earlier of parsed civil dawn or 1 hour before sunrise
    const civilDawnTs = parsedCivilDawn ? Math.min(parsedCivilDawn, sunriseTs - BLUE_HOUR_MS) : (sunriseTs - BLUE_HOUR_MS);
    // Use later of parsed civil dusk or 1 hour after sunset (ensures blue hour shows in at least one column)
    const civilDuskTs = Math.max(parsedCivilDusk || 0, sunsetTs + BLUE_HOUR_MS);

    // Determine sunlight class based on time of day
    // Morning blue hour (before sunrise)
    if (civilDawnTs && hourTs >= civilDawnTs && hourTs < sunriseTs) return 'sunlight-blue';
    // Morning golden hour
    if (hourTs >= sunriseTs && hourTs < goldenMorningEnd) return 'sunlight-golden';
    // Daytime
    if (hourTs >= goldenMorningEnd && hourTs < goldenEveningStart) return 'sunlight-day';
    // Evening golden hour
    if (hourTs >= goldenEveningStart && hourTs < sunsetTs) return 'sunlight-golden';
    // Evening blue hour (after sunset)
    if (civilDuskTs && hourTs >= sunsetTs && hourTs < civilDuskTs) return 'sunlight-blue';

    return 'sunlight-night';
  }

  // ============================================================
  // PHOTOGRAPHY SCORE CALCULATION
  // ============================================================

  /**
   * Calculate photography score for an hour (0-100).
   * Higher scores indicate better conditions for photography.
   * @param {Object} hour - Hourly weather data.
   * @param {string} sunlightClass - Current sunlight class (day, golden, blue, night).
   * @returns {number} Score from 0-100.
   */
  function calculatePhotoScore(hour, sunlightClass) {
    let score = 100;

    // Cloud penalty - low clouds are worst, high thin clouds can be good
    const cloudLow = hour.cloud_low || 0;
    const cloudMid = hour.cloud_mid || 0;
    const cloudHigh = hour.cloud_high || 0;
    const cloudTotal = hour.cloud_total || 0;

    // Low clouds heavily penalized (block light, featureless)
    score -= cloudLow * 0.8;
    // Mid clouds moderately penalized
    score -= cloudMid * 0.4;
    // High clouds less penalized (can create drama during golden hour)
    if (sunlightClass === 'sunlight-golden' || sunlightClass === 'sunlight-blue') {
      // High clouds during golden/blue hour can be beneficial
      score -= Math.max(0, cloudHigh - 40) * 0.2;
    } else {
      score -= cloudHigh * 0.3;
    }

    // Rain penalty
    const rainChance = hour.rain_chance || 0;
    score -= rainChance * 0.5;

    // Visibility penalty (poor visibility is bad)
    const visibility = hour.visibility || 10000;
    if (visibility < 5000) {
      score -= (5000 - visibility) / 100;
    }

    // Wind penalty (affects long exposures and stability)
    const windSpeed = hour.wind_speed || 0;
    if (windSpeed > 30) {
      score -= (windSpeed - 30) * 0.3;
    }

    // Bonus for golden/blue hour
    if (sunlightClass === 'sunlight-golden') {
      score += 15;
    } else if (sunlightClass === 'sunlight-blue') {
      score += 10;
    }

    // Ensure score stays in range
    return Math.max(0, Math.min(100, Math.round(score)));
  }

  /**
   * Get score class for photography score.
   * @param {number} score - Score from 0-100.
   * @returns {string} CSS class.
   */
  function getScoreClass(score) {
    if (score >= 80) return 'score-excellent';
    if (score >= 60) return 'score-good';
    if (score >= 40) return 'score-fair';
    return 'score-poor';
  }

  /**
   * Get score label for photography score.
   * @param {number} score - Score from 0-100.
   * @returns {string} Label string.
   */
  function getScoreLabel(score) {
    if (score >= 80) return 'excellent';
    if (score >= 60) return 'good';
    if (score >= 40) return 'fair';
    return 'poor';
  }

  /**
   * Calculate average photo score for a time window.
   * @param {Array} hourly - Array of hourly data.
   * @param {number} startIndex - Start index.
   * @param {number} endIndex - End index.
   * @param {Object} dayData - Daily data for sunlight calculation.
   * @param {string} timezone - Timezone identifier.
   * @returns {number} Average score.
   */
  function calculateWindowScore(hourly, startIndex, endIndex, dayData, timezone) {
    if (startIndex < 0 || endIndex > hourly.length || startIndex >= endIndex) {
      return 0;
    }

    let totalScore = 0;
    let count = 0;

    for (let i = startIndex; i < endIndex; i++) {
      const hour = hourly[i];
      const hourDate = new Date(hour.time);
      const sunlightClass = getSunlightClass(hour, hourDate, dayData, timezone);
      totalScore += calculatePhotoScore(hour, sunlightClass);
      count++;
    }

    return count > 0 ? Math.round(totalScore / count) : 0;
  }

  // ============================================================
  // SUNRISE / SUNSET QUALITY
  // ============================================================

  /**
   * Interpolate along a piecewise-linear curve.
   * @param {number} x - Input value.
   * @param {Array<Array<number>>} points - [x, y] pairs, ascending by x.
   * @returns {number} Interpolated y.
   */
  function interpolate(x, points) {
    if (x <= points[0][0]) return points[0][1];
    for (let i = 1; i < points.length; i++) {
      const [x0, y0] = points[i - 1];
      const [x1, y1] = points[i];
      if (x <= x1) {
        return y0 + ((y1 - y0) * (x - x0)) / (x1 - x0);
      }
    }
    return points[points.length - 1][1];
  }

  // High cloud is the canvas: it stays lit after the sun is down, which is
  // where afterglow comes from. Too much of it and the sky closes over.
  const HIGH_CLOUD_CURVE = [[0, 0], [40, 30], [70, 30], [90, 20], [100, 12]];

  // Mid cloud catches colour around the event itself, but greys out sooner.
  const MID_CLOUD_CURVE = [[0, 0], [30, 15], [50, 15], [80, 4], [100, 0]];

  // Most colour any sky can earn from its cloud, before the horizon is
  // accounted for.
  const MAX_CANVAS = 45;

  // Low cloud fully closes the horizon here, whatever is happening above it.
  const HORIZON_BLOCKED_AT = 70;

  /**
   * Score a single hour for sunrise/sunset colour.
   *
   * Deliberately not calculatePhotoScore, which penalises all cloud. That is
   * right for shooting conditions and backwards for sunsets: a cloudless sky
   * is excellent light and a dull sunset.
   *
   * Low cloud gates rather than subtracts. The sun lights high cloud from
   * underneath, along a path that skims the horizon, so cloud sitting on that
   * horizon stops the light before it arrives. A sky with a beautiful cirrus
   * deck and a bank of stratus to the west produces nothing, and a penalty
   * merely subtracted from the cirrus bonus would still read as a good
   * evening.
   *
   * @param {Object} hour - Hourly weather data.
   * @param {boolean} isGlowHour - True for the hour furthest from midday,
   *   where high cloud carries the afterglow and counts for more.
   * @returns {number} Score from 0-100.
   */
  function scoreLightHour(hour, isGlowHour) {
    const cloudLow = hour.cloud_low || 0;
    const cloudMid = hour.cloud_mid || 0;
    const cloudHigh = hour.cloud_high || 0;
    const rainChance = hour.rain_chance || 0;
    const visibility = hour.visibility == null ? 10000 : hour.visibility;

    // What the sky has to work with, if the light can reach it.
    const canvas = Math.min(
      MAX_CANVAS,
      interpolate(cloudHigh, HIGH_CLOUD_CURVE) * (isGlowHour ? 1.5 : 1)
        + interpolate(cloudMid, MID_CLOUD_CURVE)
    );

    // How much of that light gets through along the horizon.
    const clarity = Math.max(0, 1 - (cloudLow / HORIZON_BLOCKED_AT));

    // A clear sky is the baseline: fine light, unremarkable sunset.
    let score = 40 + (canvas * clarity);

    // Low cloud also just makes for a duller evening overall.
    score -= cloudLow * 0.15;

    // Haze and murk below 10km.
    if (visibility < 10000) {
      score -= ((10000 - visibility) / 10000) * 15;
    }

    // Rain.
    score -= (rainChance / 100) * 20;

    return Math.max(0, Math.min(100, Math.round(score)));
  }

  /**
   * Find the index of the hour containing a given local time.
   *
   * Matches on the string rather than parsing to a Date. Open-Meteo returns
   * local times without an offset, so parsing would interpret them in the
   * viewer's timezone, which is not the location's.
   *
   * @param {Array} hourly - Array of hourly data.
   * @param {string} dateStr - Date string (YYYY-MM-DD).
   * @param {string} timeStr - Time string (HH:MM).
   * @returns {number} Index, or -1 if not present.
   */
  function findHourIndex(hourly, dateStr, timeStr) {
    if (!dateStr || !timeStr) return -1;
    const prefix = `${dateStr}T${timeStr.slice(0, 2)}`;
    return hourly.findIndex((h) => typeof h.time === 'string' && h.time.startsWith(prefix));
  }

  /**
   * Score a sunrise or sunset for colour.
   *
   * Samples the hour holding the event and the hour after it, treating the
   * one furthest from midday as the glow hour.
   *
   * @param {Array} hourly - Array of hourly data.
   * @param {Object} dayData - Daily data, including twilight times.
   * @param {string} event - 'sunrise' or 'sunset'.
   * @returns {number|null} Score from 0-100, or null when data is missing.
   */
  function sunriseSunsetScore(hourly, dayData, event) {
    if (!Array.isArray(hourly) || !hourly.length || !dayData) return null;

    const twilight = dayData.twilight || {};
    const timeStr = twilight[event] || null;
    if (!timeStr) return null;

    const eventIndex = findHourIndex(hourly, dayData.date, timeStr);
    if (eventIndex < 0) return null;

    // The event hour and the one after it. The glow hour is whichever sits
    // further from midday: after sunset, before sunrise.
    const indices = [eventIndex, eventIndex + 1];
    const glowIndex = 'sunset' === event ? eventIndex + 1 : eventIndex;

    let total = 0;
    let count = 0;
    for (const i of indices) {
      if (i < 0 || i >= hourly.length) continue;
      total += scoreLightHour(hourly[i], i === glowIndex);
      count++;
    }

    return count > 0 ? Math.round(total / count) : null;
  }

  const ForecastScoring = {
    // Time and light phases.
    parseTimeToTimestamp,
    getSunlightFallback,
    getSunlightClass,

    // Scores.
    calculatePhotoScore,
    sunriseSunsetScore,
    scoreLightHour,
    calculateWindowScore,
    getScoreClass,
    getScoreLabel,
  };

  // Export to global scope.
  global.ForecastScoring = ForecastScoring;
})(window);
