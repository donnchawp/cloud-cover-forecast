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
   * The UTC offset of a timezone at a given instant, in milliseconds.
   * @param {number} utcMs - Instant to measure at.
   * @param {string} timezone - Timezone identifier.
   * @returns {number} Offset in milliseconds.
   */
  function timezoneOffset(utcMs, timezone) {
    const parts = new Intl.DateTimeFormat('en-US', {
      timeZone: timezone, hour12: false,
      year: 'numeric', month: '2-digit', day: '2-digit',
      hour: '2-digit', minute: '2-digit', second: '2-digit',
    }).formatToParts(new Date(utcMs));

    const field = {};
    for (const part of parts) {
      field[part.type] = part.value;
    }

    // Hour comes back as 24 rather than 00 for midnight in some engines.
    const asUtc = Date.UTC(
      Number(field.year), Number(field.month) - 1, Number(field.day),
      Number(field.hour) % 24, Number(field.minute), Number(field.second)
    );
    return asUtc - utcMs;
  }

  /**
   * Convert a wall-clock date and time in some timezone to a timestamp.
   *
   * Must not depend on the timezone the browser happens to be in. Reading a
   * location's forecast from another country is the normal case, not the edge
   * case, and the old implementation was wrong by exactly the viewer's own UTC
   * offset — invisible in Britain and Ireland during winter, an hour out all
   * summer, and ten hours out from Sydney.
   *
   * @param {string} dateStr - Date string (YYYY-MM-DD).
   * @param {string} timeStr - Time string (HH:MM).
   * @param {string} timezone - Optional timezone identifier.
   * @returns {number|null} Timestamp or null if invalid.
   */
  function parseTimeToTimestamp(dateStr, timeStr, timezone) {
    if (!dateStr || !timeStr) return null;

    // The wall-clock reading, taken as though it were UTC.
    const wall = Date.parse(`${dateStr}T${timeStr}:00Z`);
    if (isNaN(wall)) return null;

    if (!timezone) {
      const local = new Date(`${dateStr}T${timeStr}`).getTime();
      return isNaN(local) ? null : local;
    }

    // Subtract the zone's offset to get the real instant. Measuring the offset
    // needs an instant to measure at, so start from the wall reading and
    // settle: one pass is enough except across a DST change, where the second
    // corrects it.
    let utc = wall - timezoneOffset(wall, timezone);
    utc = wall - timezoneOffset(utc, timezone);
    return isNaN(utc) ? null : utc;
  }

  /**
   * The current date and hour at a location, as strings.
   *
   * Returned as strings so they compare directly against Open-Meteo's local
   * stamps, with no Date round-trip to reintroduce the viewer's offset.
   *
   * @param {string} timezone - Timezone identifier.
   * @returns {Object} { date: 'YYYY-MM-DD', hour: 'HH' }.
   */
  function nowInTimezone(timezone) {
    const options = { hour12: false, year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit' };
    if (timezone) {
      options.timeZone = timezone;
    }

    const field = {};
    for (const part of new Intl.DateTimeFormat('en-CA', options).formatToParts(new Date())) {
      field[part.type] = part.value;
    }

    return {
      date: `${field.year}-${field.month}-${field.day}`,
      // Midnight comes back as 24 rather than 00 in some engines.
      hour: String(Number(field.hour) % 24).padStart(2, '0'),
    };
  }

  /**
   * Convert one of Open-Meteo's hourly stamps to a timestamp.
   *
   * They arrive as 'YYYY-MM-DDTHH:MM' with no offset, in the location's own
   * timezone, so new Date() would read them as the viewer's local time.
   *
   * @param {string} isoLocal - Local time string from the API.
   * @param {string} timezone - Timezone identifier.
   * @returns {number|null} Timestamp or null if invalid.
   */
  function parseHourTimestamp(isoLocal, timezone) {
    if (typeof isoLocal !== 'string') return null;
    const [datePart, timePart] = isoLocal.split('T');
    if (!datePart || !timePart) return null;
    return parseTimeToTimestamp(datePart, timePart.slice(0, 5), timezone);
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
      const hourDate = new Date(parseHourTimestamp(hour.time, timezone));
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
   * Offsets from the event hour that the score samples.
   *
   * PHP attaches Met.no readings to eventIndex-1 .. eventIndex+2 (see
   * MET_NO_WINDOW_BEFORE in includes/class-api.php). Widening this past that
   * window strands hours without a reading and silently degrades every card
   * to single-source, so tests/range.test.js asserts the two agree.
   */
  const MET_NO_SAMPLE_OFFSETS = [0, 1];

  /**
   * Score a sunrise or sunset against both forecast sources.
   *
   * Met.no's three cloud layers are overlaid on the Open-Meteo hour and the
   * identical formula is run again. Visibility and rain chance stay
   * Open-Meteo's in both variants: only cloud was captured from Met.no, and
   * holding everything else constant means the range measures cloud
   * disagreement and nothing else. A range that also moved with visibility
   * would be uninterpretable.
   *
   * Averaging is per source, not per hour. Each source gets its own mean
   * across the sampled hours and the range is min/max of those two means.
   * Taking min/max hour by hour would mix sources and invent a range wider
   * than either source supports.
   *
   * @param {Array}  hourly  Hourly rows.
   * @param {Object} dayData Daily row with date and twilight.
   * @param {string} event   'sunrise' or 'sunset'.
   * @returns {Object|null} {low, high, sources} or null when there is no event.
   */
  function sunriseSunsetRange(hourly, dayData, event) {
    if (!Array.isArray(hourly) || !hourly.length || !dayData) return null;

    const twilight = dayData.twilight || {};
    const timeStr = twilight[event] || null;
    if (!timeStr) return null;

    const eventIndex = findHourIndex(hourly, dayData.date, timeStr);
    if (eventIndex < 0) return null;

    // The glow hour is whichever sits further from midday: after sunset,
    // before sunrise.
    const glowIndex = 'sunset' === event ? eventIndex + 1 : eventIndex;

    let openTotal = 0;
    let metTotal = 0;
    let count = 0;
    let metCount = 0;

    for (const offset of MET_NO_SAMPLE_OFFSETS) {
      const i = eventIndex + offset;
      if (i < 0 || i >= hourly.length) continue;

      const hour = hourly[i];
      const isGlow = i === glowIndex;

      openTotal += scoreLightHour(hour, isGlow);
      count++;

      const met = hour.met_no;
      if (!met) continue;

      metTotal += scoreLightHour(Object.assign({}, hour, {
        cloud_low: met.low ?? hour.cloud_low,
        cloud_mid: met.mid ?? hour.cloud_mid,
        cloud_high: met.high ?? hour.cloud_high,
      }), isGlow);
      metCount++;
    }

    if (!count) return null;

    const open = Math.round(openTotal / count);

    // A range needs Met.no for every sampled hour. Partial coverage would
    // compare a two-hour mean against a one-hour mean, which is not a
    // comparison.
    if (metCount !== count) {
      return { low: open, high: open, sources: 1 };
    }

    const met = Math.round(metTotal / metCount);
    return { low: Math.min(open, met), high: Math.max(open, met), sources: 2 };
  }

  /**
   * The score a range is labelled and coloured by.
   *
   * The low end, so the band word always describes the number the solid arc
   * draws and the two can never contradict. This is the only place that rule
   * lives; changing it here changes every view at once.
   *
   * @param {Object|null} range - A sunriseSunsetRange() result.
   * @returns {number|null} The score to band on.
   */
  function bandScore(range) {
    return range ? range.low : null;
  }

  const ForecastScoring = {
    // Time and light phases.
    parseTimeToTimestamp,
    parseHourTimestamp,
    timezoneOffset,
    getSunlightFallback,
    getSunlightClass,

    nowInTimezone,
    findHourIndex,

    // Scores.
    calculatePhotoScore,
    sunriseSunsetRange,
    bandScore,
    MET_NO_SAMPLE_OFFSETS,
    scoreLightHour,
    calculateWindowScore,
    getScoreClass,
    getScoreLabel,
  };

  // Export to global scope.
  global.ForecastScoring = ForecastScoring;
})(window);
