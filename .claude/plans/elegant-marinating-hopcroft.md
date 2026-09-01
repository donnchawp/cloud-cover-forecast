# Security Review Plan: Cloud Cover Forecast WordPress Plugin

## Summary

Security audit identified **8 PHP issues** and **10 JavaScript issues**. This plan addresses all findings prioritized by severity.

---

## Critical Issues

### 1. API Key Exposure in Moon Data Endpoint
**File:** `includes/class-api.php:891-897`

**Problem:** IPGeolocation API key passed in URL query parameters:
```php
$params = array(
    'apiKey' => $api_key,  // Exposed in URL
    'lat'    => $lat,
    ...
);
$url = add_query_arg( $params, 'https://api.ipgeolocation.io/astronomy' );
```

**Risk:** API key visible in server logs, browser history, and network requests.

**Fix:**
- Add security warning in admin settings about API key exposure
- This is an inherent limitation of the IPGeolocation API (it only accepts GET requests with apiKey in URL)
- Document that users should use a dedicated API key with rate limits set

---

## High Priority Issues

### 2. ~~Rate Limiting Missing on PWA AJAX Endpoints~~ (VERIFIED OK)
**File:** `includes/class-pwa.php:61-66`

**Status:** Server-side rate limiting EXISTS via API class (`can_make_request()`).

All PWA handlers call API methods that enforce service-level rate limits:
- `open_meteo_forecast`: 45 requests/hour
- `open_meteo_geocoding`: 20 requests/hour
- `nominatim_reverse`: 1 request/minute

**Minor gap:** PWA lacks per-IP rate limiting (unlike public block). One user could consume the global quota. This is a low-priority denial-of-service concern, not an API abuse issue.

**No action required** unless per-IP fairness is desired.

### 3. Input Validation on Shortcode Coordinates
**File:** `includes/class-shortcode.php:92-94`

**Problem:** Latitude/longitude validation happens downstream, not at entry point.

**Fix:**
- Add explicit range validation at shortcode level
- Return user-friendly error for invalid coordinates (-90 to 90 lat, -180 to 180 lon)

### 4. Client IP Retrieval Ignores Proxies
**File:** `includes/class-public-block.php:570-578`

**Problem:** Only checks `REMOTE_ADDR`:
```php
private function get_client_ip() {
    if ( isset( $_SERVER['REMOTE_ADDR'] ) ) {
        $ip = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
        ...
    }
    return '0.0.0.0';
}
```

**Risk:** Behind CDN/proxy, all users appear as same IP, breaking rate limiting.

**Fix:**
- Check proxy headers in priority order: `HTTP_CF_CONNECTING_IP`, `HTTP_X_FORWARDED_FOR`, `HTTP_X_REAL_IP`, then `REMOTE_ADDR`
- For `X-Forwarded-For`, use first IP from comma-separated list
- Validate with `FILTER_VALIDATE_IP` before returning

### 5. XSS Risk in forecast-app.js
**File:** `assets/js/forecast-app.js:409-410`

**Problem:** `$resultsContainer.html(data.html)` renders server HTML without validation.

**Fix:**
- Validate response structure before rendering
- Ensure server response is properly escaped (PHP side handles this, but defense in depth)

---

## Medium Priority Issues

### 6. localStorage Data Validation
**File:** `assets/js/public-block.js:23-44`

**Problem:** `JSON.parse()` on localStorage without structure validation.

**Fix:**
- Validate parsed object has correct shape (requests array, windowStart number)
- Return default object if validation fails

### 7. Coordinate Validation in JavaScript
**File:** `assets/js/public-block.js:83-95`

**Problem:** URL parameters set without validating lat/lon are numeric.

**Fix:**
- Add `parseFloat()` validation before setting URL parameters
- Validate coordinates are within valid ranges

### 8. External API Response Validation
**Files:** `block.js:107`, `sunrise-sunset-block.js`

**Problem:** API responses used without type checking.

**Fix:**
- Validate `typeof result.latitude === 'number'` before use
- Check array types with `Array.isArray()`

### 9. ~~Add Rate Limiting to forecast-app.js~~ (LOW PRIORITY)
**File:** `assets/js/forecast-app.js:410-414`

**Status:** Server-side rate limiting exists in API class. Client-side rate limiting is a UX enhancement only (shows error before server round-trip).

**No action required** - server protection is in place.

---

## Low Priority Issues

### 10. Error Message Handling
**File:** `assets/js/public-block.js:423-426`

**Problem:** Uses `.html()` even with escaped content.

**Fix:**
- Use `.empty().append($('<div class="error">').text(message))` pattern

### 11. AJAX Action Whitelisting
**File:** `assets/js/forecast-app.js:202-213`

**Problem:** No client-side action validation.

**Fix:**
- Add whitelist of allowed actions
- Throw error for invalid action before network request

---

## Files to Modify

| File | Changes |
|------|---------|
| `includes/class-shortcode.php` | Add coordinate range validation |
| `includes/class-public-block.php` | Fix IP retrieval for proxies |
| `assets/js/public-block.js` | Validate localStorage, coordinates, improve error handling |
| `block.js` | Add API response type validation |
| `sunrise-sunset-block.js` | Add API response type validation |

---

## Implementation Order

1. **Shortcode input validation** (prevents invalid data entry)
2. **IP retrieval fix** (improves rate limiting fairness for public block)
3. **JavaScript localStorage/coordinate validation** (defense in depth)
4. **API response validation** (defense in depth)
5. **Error handling improvements** (polish)

---

## Verification

After implementation:
1. Test shortcode with invalid coordinates (lat=999) - should show user-friendly error
2. Test behind proxy (X-Forwarded-For header) - rate limiting should work per-client
3. Test localStorage tampering in browser - should reset to defaults
4. Verify all existing functionality still works (regression testing)
5. Run plugin through WordPress Plugin Check tool if available
