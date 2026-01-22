# Cloud Cover Forecast

A WordPress plugin that displays detailed cloud cover data for any location worldwide. Perfect for photographers and astronomy enthusiasts who need to plan their activities based on cloud conditions. Inspired by the [Clear Outside](https://clearoutside.com) website and app.

![WordPress Plugin](https://img.shields.io/badge/WordPress-Plugin-blue.svg)
![PHP Version](https://img.shields.io/badge/PHP-7.4%2B-green.svg)
![License](https://img.shields.io/badge/License-GPL%20v2%2B-blue.svg)

## Features

### 🌤️ Comprehensive Cloud Data
- **Total cloud cover percentage** - Overall sky coverage
- **Low altitude clouds** (0-3km) - Cumulus, stratus, fog
- **Medium altitude clouds** (3-8km) - Altocumulus, altostratus
- **High altitude clouds** (8km+) - Cirrus, cirrostratus, cirrocumulus
- **Hourly forecasts** for up to 48 hours

### 📸 Photography-Focused Features
- **Sunset/sunrise times** for golden hour planning
- **Astronomical twilight** times for night photography
- **Moon phase** and moonrise/moonset times
- **Specialized rendering** for photography planning

### 🎯 Multiple Input Methods
- **Coordinates**: Use latitude and longitude directly
- **Location names**: City, country, or full addresses
- **Smart geocoding**: Automatic coordinate conversion

### 🎨 Flexible Display Options
- **Shortcode**: `[cloud_cover location="London, UK" hours="24"]`
- **Gutenberg Block**: Visual block editor integration
- **Admin Settings**: Easy configuration panel

### ⚡ Performance & Caching
- **Smart caching system** reduces API calls
- **Configurable cache TTL** (default: 15 minutes weather, 24 hours geocoding)
- **WordPress transients** for efficient data storage

### 🔒 Security Hardened
- **Input validation** for coordinates and location names
- **Rate limiting** to prevent API abuse
- **Proxy-aware IP detection** for CDN/reverse proxy compatibility
- **API response validation** to prevent XSS attacks

## Installation

### From WordPress Admin
1. Go to **Plugins > Add New**
2. Search for "Cloud Cover Forecast"
3. Click **Install Now** and **Activate**

### Manual Installation
1. Download the plugin files
2. Upload to `/wp-content/plugins/cloud-cover-forecast/`
3. Activate through the **Plugins** menu in WordPress

## Usage

### Shortcode
```php
// Using coordinates
[cloud_cover lat="51.8986" lon="-8.4756" hours="24" label="Cork"]

// Using location name
[cloud_cover location="London, UK" hours="48"]

// Photography mode with astronomical data
[cloud_cover location="Paris, France" hours="12" show_astro="true"]
```

### Gutenberg Block
1. Add the **"Cloud Cover Forecast"** block in the block editor
2. Configure location and settings in the block sidebar
3. Preview your forecast in real-time

### Admin Settings
1. Go to **Settings > Cloud Cover Forecast**
2. Set your default location and preferences
3. Use the location search to find coordinates automatically

## API & Data Sources

### Open-Meteo and Met.no APIs
- **Weather Data**: Free, no API key required
- **Geocoding**: Automatic location name to coordinate conversion
- **High Accuracy**: Based on multiple meteorological models
- **Global Coverage**: Worldwide weather data

### IPGeolocation.io API
- **Astronomical Data**: Moon phase, moonrise/moonset times, and astronomical twilight
- **High Precision**: Accurate astronomical calculations for any location
- **Professional Service**: Enterprise-grade IP geolocation and astronomical data
- **API Key Required**: Free tier available with registration at [ipgeolocation.io](https://ipgeolocation.io/)
- **Trusted Worldwide**: Used by companies like 8x8, Atlassian, Avan, and many others since 2017

#### About IPGeolocation.io
[IPGeolocation.io](https://ipgeolocation.io/) is a comprehensive IP intelligence platform that provides:

- **Accurate IP Geolocation**: Precise location data for any IP address
- **Astronomy API**: Detailed astronomical calculations including moon phases, sunrise/sunset times
- **Timezone API**: Exact timezone information for any location
- **Security Intelligence**: VPN, proxy, and threat detection
- **Enterprise-Grade**: Trusted by major companies worldwide since 2017
- **Developer-Friendly**: Easy integration with comprehensive documentation
- **Free Tier**: Generous free usage limits for development and testing

The plugin uses IPGeolocation.io specifically for astronomical data to provide photographers and astronomy enthusiasts with accurate moon phase information, moonrise/moonset times, and astronomical twilight calculations.

## Privacy & External Services

This plugin connects to external services to provide weather forecasts. **By installing and activating this plugin, site administrators consent to these external API calls on behalf of site visitors.**

### External API Services Used

#### Open-Meteo API (https://open-meteo.com)
- **Purpose**: Retrieve hourly cloud cover forecasts
- **Data Sent**: Geographic coordinates (latitude, longitude)
- **Privacy Policy**: https://open-meteo.com/en/terms
- **Frequency**: Once per location per cache period (default: 15 minutes)
- **API Key**: Not required
- **User Consent**: Automatic (required for core functionality)

#### Open-Meteo Geocoding API (https://geocoding-api.open-meteo.com)
- **Purpose**: Convert location names to coordinates
- **Data Sent**: Location name (e.g., "London, UK")
- **Privacy Policy**: https://open-meteo.com/en/terms
- **Frequency**: Once per location search (cached for 15 minutes)
- **API Key**: Not required
- **User Consent**: Automatic (required for core functionality)

#### Met.no Weather API (https://api.met.no)
- **Purpose**: Supplement cloud cover data (merged with Open-Meteo for accuracy)
- **Data Sent**: Geographic coordinates, your site URL and admin email in User-Agent header
- **Privacy Policy**: https://api.met.no/doc/TermsOfService
- **Frequency**: Once per location per cache period (default: 15 minutes)
- **API Key**: Not required
- **User-Agent**: Includes site name, URL, and admin email (required by Met.no terms)
- **User Consent**: Automatic (required for core functionality)

#### IPGeolocation Astronomy API (https://api.ipgeolocation.io) - OPTIONAL
- **Purpose**: Moon phase, moonrise/moonset times, and astronomical twilight data
- **Data Sent**: Geographic coordinates, date
- **Privacy Policy**: https://ipgeolocation.io/privacy-policy.html
- **Frequency**: Once per day per location (cached for 24 hours)
- **API Key**: Required (free tier: 1000 requests/day)
- **User Consent**: Opt-in (disabled by default, requires admin to add API key)

### Personal Data Collection

**No Personally Identifiable Information (PII) is collected or stored by this plugin.**

- No user accounts or registration
- No cookies or tracking
- No analytics or metrics collection
- Only geographic coordinates and location names are sent to APIs
- No IP addresses logged or transmitted (except as standard HTTP requests)
- No email addresses collected from users

### Data Storage

- Weather data is cached locally using WordPress transients
- Cache duration: 15 minutes for weather, 24 hours for astronomical data
- All cached data is automatically deleted after expiration
- Coordinates and location names are stored in WordPress options (admin settings only)

### GDPR Compliance

This plugin is GDPR-compliant:
- No personal data processing
- No cookies requiring consent
- Location lookups are anonymous
- No data shared with third parties beyond necessary API providers
- Users can request site administrators disable the plugin at any time

### For Site Administrators

When using this plugin on a website that serves EU visitors:
1. Disclose the use of external weather APIs in your privacy policy
2. Inform users that location coordinates are sent to weather services
3. Note that Met.no receives your site's admin email in the User-Agent header

## Requirements

- **WordPress**: 5.0 or higher
- **PHP**: 7.4 or higher
- **Internet connection** for API calls
- **Gutenberg** support (for block functionality)
- **IPGeolocation.io API Key** (for astronomical data - free tier available)

## Development

### Project Structure
```
cloud-cover-forecast/
├── cloud-cover-forecast.php    # Main plugin file
├── block.js                    # Gutenberg block JavaScript
├── public-block.js            # Frontend block JavaScript
├── assets/                    # CSS and JS assets
├── includes/                  # PHP class files
│   ├── class-admin.php        # Admin functionality
│   ├── class-api.php          # API integration
│   ├── class-shortcode.php    # Shortcode handling
│   ├── class-photography.php  # Photography features
│   └── ...
└── readme.txt                 # WordPress plugin directory readme
```

### Key Components
- **Main Plugin Class**: `Cloud_Cover_Forecast_Plugin`
- **API Integration**: Open-Meteo weather and geocoding APIs
- **Caching System**: WordPress transients with configurable TTL
- **Gutenberg Block**: JavaScript-based block editor integration
- **Admin Interface**: Settings page with location search

### No Build Process Required
This plugin uses vanilla JavaScript and PHP - no compilation or build steps needed.

## Testing

### Manual Testing
- Test shortcode with various locations
- Test Gutenberg block functionality
- Test admin location search feature
- Test mobile responsiveness
- Test caching behavior

### Default Test Location
- **Cork, Ireland** (51.8986, -8.4756) - Default location for testing

## Contributing

1. Fork the repository
2. Create a feature branch
3. Make your changes
4. Test thoroughly
5. Submit a pull request

## Support

- **GitHub Issues**: [Report bugs or request features](https://github.com/donnchawp/cloud-cover-forecast/issues)
- **WordPress Support**: Available through the WordPress plugin directory

## License

This plugin is licensed under the GPL v2 or later.

## Changelog

### 1.0.1
- **Security**: Added coordinate range validation for shortcodes (-90/90 latitude, -180/180 longitude)
- **Security**: Improved IP detection for rate limiting behind CDNs and proxies
- **Security**: Added validation for localStorage data to prevent tampering
- **Security**: Added type checking for geocoding API responses in Gutenberg blocks
- **Security**: Added coordinate validation before setting URL parameters

### 1.0.0
- Initial release
- Shortcode support with coordinate and location name input
- Gutenberg block with intuitive interface
- Admin settings page with location search
- Photography-focused rendering with astronomical data
- Responsive design and mobile support
- Smart caching system
- Integration with Open-Meteo API

---

**Perfect for photographers, astronomy enthusiasts, and weather watchers!** 📸🌙☁️
