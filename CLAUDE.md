# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Important: Reference Documentation

**Before implementing any changes, consult `reference.md`** for the complete codebase index including:
- All source files with descriptions
- Class relationships and data flow
- API endpoints and rate limits
- Constants and default settings

**When committing changes to GIT, update `reference.md`** with:
- New or modified files
- Changed class relationships
- New API endpoints or constants
- Add an entry to the Changelog section

## Project Overview

This is a WordPress plugin called "Cloud Cover Forecast" that displays cloud cover data using the Open-Meteo API. The plugin consists of:
- Main plugin file: `cloud-cover-forecast.php`
- PHP classes in `includes/` directory (see `reference.md` for full index)
- Gutenberg block JavaScript: `block.js`, `public-block.js`, `sunrise-sunset-block.js`
- PWA application in `pwa/` and `assets/` directories

## Architecture

### Plugin Structure
- **Main class**: `Cloud_Cover_Forecast_Plugin` - orchestrates all plugin components
- **Modular design**: Each feature is separated into its own class in `includes/`
- **See `reference.md`** for complete class documentation and relationships

### Core Classes (in `includes/`)
| Class | Purpose |
|-------|---------|
| `Cloud_Cover_Forecast_Admin` | Admin settings page and Gutenberg block registration |
| `Cloud_Cover_Forecast_API` | External API integration (Open-Meteo, Met.no, IPGeolocation, Nominatim) |
| `Cloud_Cover_Forecast_Shortcode` | `[cloud_cover]` shortcode handler |
| `Cloud_Cover_Forecast_Photography_Renderer` | Photography time calculations and widget rendering |
| `Cloud_Cover_Forecast_Public_Block` | Public-facing location lookup block |
| `Cloud_Cover_Forecast_Sunrise_Sunset_Block` | 3-day sunrise/sunset forecast block |
| `Cloud_Cover_Forecast_PWA` | Progressive Web App functionality |
| `Cloud_Cover_Forecast_Assets` | CSS/JS enqueuing |
| `Cloud_Cover_Forecast_Autoloader` | Class autoloading |
| `Cloud_Cover_Forecast_Location_Search_Form` | Reusable location search component |

### Key Features
- **Dual-source weather data**: Merges Open-Meteo and Met.no for accuracy
- **Photography-focused**: Calculates golden hour, blue hour, astronomical twilight, Milky Way visibility
- **PWA support**: Installable app at configurable URL endpoint
- **Rate limiting**: Per-service rate limits to respect API terms
- **Caching**: WordPress transients with configurable TTL and version-based cache busting

### WordPress Integration
- Hooks into `admin_menu`, `admin_init`, `wp_enqueue_scripts`, `init`
- Uses WordPress APIs: `wp_remote_get()`, `get_transient()`, `sanitize_text_field()`, `register_block_type()`, etc.
- Follows WordPress coding standards and security practices
- Internationalization ready with text domain `cloud-cover-forecast`

## Development

### No Build Process
This plugin has no build or compilation step. It consists of PHP and vanilla JavaScript files that run directly in WordPress.

### Testing
- `tests/run.sh` runs the whole suite: node and php only, no dependencies
- The suite checks markup and logic, **never pixels** — no CSS is rendered
  anywhere in it, so layout, theming and anything visual still needs a browser
- Read `tests/README.md` before trusting a green run; it records what the
  suite deliberately does not cover
- Manual testing through WordPress admin interface
- Test shortcode with coordinates: `[cloud_cover lat="51.8986" lon="-8.4756" hours="24" label="Cork"]`
- Test shortcode with location name: `[cloud_cover location="London, UK" hours="48"]`
- Test Gutenberg block in block editor with both location name and coordinates
- Test admin location search functionality
- Default location is Cork, Ireland (51.8986, -8.4756)

### Configuration
- Plugin settings stored in WordPress options table
- Weather cache uses transients with prefix `cloud_cover_forecast_cache_`
- Geocoding cache uses transients with prefix `cloud_cover_forecast_geocoding_`
- Default weather cache TTL: 15 minutes
- Default geocoding cache TTL: 24 hours
- Default forecast window: 24 hours
- Chart display can be enabled/disabled (disabled by default in Gutenberg blocks)

### Shortcode Usage
- **With coordinates**: `[cloud_cover lat="51.8986" lon="-8.4756" hours="24" label="Cork"]`
- **With location name**: `[cloud_cover location="London, UK" hours="48"]`
- **Mixed parameters**: `[cloud_cover location="Paris" hours="12" label="Custom Label"]`
- Location parameter takes precedence over lat/lon if both are provided

### API Dependencies
- **Open-Meteo Weather API**: No authentication required, provides hourly cloud cover and extended forecast data
- **Open-Meteo Geocoding API**: No authentication required, converts location names to coordinates
- **Met.no Locationforecast API**: No authentication required, secondary weather source for data merging
- **IPGeolocation Astronomy API**: Optional API key for moon phases and rise/set times (1000 req/day free)
- **Nominatim/OpenStreetMap**: No authentication required, reverse geocoding (1 req/sec limit)
- **WordPress**: Requires WordPress 5.0+ with standard functions
- **Gutenberg**: Block functionality requires Gutenberg/block editor support

See `reference.md` for rate limits and cache TTL values.

### Admin Features
- Location search functionality in settings page
- JavaScript-powered geocoding with live results
- Auto-population of latitude/longitude fields from location search
- Visual feedback for successful/failed location searches

## Git Workflow

When committing changes:
1. Update `reference.md` with any new/modified files
2. Add changelog entry to `reference.md` describing the changes
3. Keep this file (`CLAUDE.md`) focused on guidance; detailed documentation belongs in `reference.md`
