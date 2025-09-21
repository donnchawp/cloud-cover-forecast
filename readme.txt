=== Cloud Cover Forecast ===
Contributors: donnchawp
Tags: weather, cloud cover, forecast, photography, astronomy
Requires at least: 5.0
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Display total, low, medium, and high cloud cover percentages for any location using the Open‑Meteo and met.no APIs.

== Description ==

Cloud Cover Forecast is a WordPress plugin that displays detailed cloud cover data for any location worldwide. It's designed specifically for photographers and astronomy enthusiasts who need to plan their activities based on cloud conditions. It is inspired by the [Clear Outside](https://clearoutside.com/) website and app.

**Key Features:**

* **Comprehensive Cloud Data**: Shows total, low, medium, and high altitude cloud cover percentages
* **Multiple Input Methods**: Use coordinates (lat/lon) or location names (city, country)
* **Flexible Display Options**: Shortcode and Gutenberg block support
* **Photography-Focused**: Special rendering for photography planning with sunset/sunrise times
* **Astronomical Data**: Includes moon phase, moonrise/moonset times, and astronomical twilight
* **Smart Caching**: Built-in caching system to reduce API calls and improve performance
* **Responsive Design**: Mobile-friendly display with clean, modern styling
* **Admin Settings**: Easy configuration through WordPress admin panel

**Perfect for:**
* Photographers planning outdoor shoots
* Astronomy enthusiasts tracking viewing conditions
* Weather enthusiasts monitoring cloud patterns
* Anyone needing detailed cloud cover forecasts

**Data Sources:**
* Weather data from Open-Meteo and met.no APIs (free, no API key required)
* Location geocoding for city/country name lookup through Open Meteo.
* Astronomical calculations for sun/moon data uses [ip Geolocation](https://ipgeolocation.io/).

== Installation ==

1. Upload the plugin files to the `/wp-content/plugins/cloud-cover-forecast` directory, or install the plugin through the WordPress plugins screen directly.
2. Activate the plugin through the 'Plugins' screen in WordPress
3. Go to Settings > Cloud Cover Forecast to configure your default location and settings
4. Use the shortcode `[cloud_cover]` in posts/pages or add the Gutenberg block "Cloud Cover Forecast" or "Public Cloud Cover Lookup".

== Frequently Asked Questions ==

= How do I use the plugin? =

You can use the plugin in two ways:

**Shortcode:**
`[cloud_cover lat="51.8986" lon="-8.4756" hours="24" label="Cork"]`
`[cloud_cover location="London, UK" hours="48"]`

**Gutenberg Block:**
Add the "Cloud Cover Forecast" or "Public Cloud Cover Lookup" block in the block editor and configure your location and settings.

= What data does it show? =

The plugin displays:
* Total cloud cover percentage
* Low altitude cloud cover (0-3km)
* Medium altitude cloud cover (3-8km)
* High altitude cloud cover (8km+)
* Hourly forecasts for up to 33 hours
* Sunset/sunrise times (photography mode)
* Moon phase and moonrise/moonset times
* Astronomical twilight times

= Do I need an API key? =

The plugin uses the free Open-Meteo and Met.no APIs which don't require registration or API keys. It does use an [IP Geolocation](https://ipgeolocation.io/) API that requires registration and an API key to determine Moon set and rise times. IPGeolocation.io offers a generous free tier that's perfect for most users.

= How accurate is the data? =

The plugin uses data from Open-Meteo and Met.no, that provides high-quality weather forecasts based on multiple meteorological models. Accuracy is generally very good for short-term forecasts (24-48 hours).

= Can I use location names instead of coordinates? =

Yes! You can use city names, country names, or full addresses. The plugin will automatically convert them to coordinates using the Open-Meteo Geocoding API.

= How does caching work? =

The plugin caches weather data for 15 minutes by default and geocoding data for 24 hours. This reduces API calls and improves performance. You can adjust cache settings in the admin panel.

= Is the plugin mobile-friendly? =

Yes! The plugin includes responsive CSS that works well on all device sizes.

= What is IPGeolocation.io and why does the plugin use it? =

IPGeolocation.io is a professional IP intelligence platform that provides accurate astronomical data including moon phases, moonrise/moonset times, and astronomical twilight calculations. The plugin uses this service specifically for photography and astronomy features because it offers:

* High-precision astronomical calculations for any location worldwide
* Professional-grade data trusted by major companies since 2017
* Comprehensive astronomy API with detailed moon and sun data
* Free tier available for development and personal use
* Enterprise-grade reliability and accuracy

This ensures photographers and astronomy enthusiasts get the most accurate astronomical data for planning their activities.

= How do I get an IPGeolocation.io API key? =

1. Visit [ipgeolocation.io](https://ipgeolocation.io/)
2. Sign up for a free account
3. Navigate to your dashboard to get your API key
4. Enter the API key in the plugin's admin settings
5. The free tier provides generous usage limits for most users

== Screenshots ==

1. Cloud cover forecast display with hourly data
2. Admin settings page with location search
3. Gutenberg block editor interface
4. Photography-focused view with astronomical data
5. Mobile-responsive display

== Changelog ==

= 1.0.0 =
* Initial release
* Shortcode support with coordinate and location name input
* Gutenberg block with intuitive interface
* Admin settings page with location search
* Photography-focused rendering with astronomical data
* Responsive design and mobile support
* Smart caching system
* Integration with Open-Meteo API

== Upgrade Notice ==

= 1.0.0 =
Initial release of Cloud Cover Forecast plugin.

== Support ==

For support, feature requests, or bug reports, please visit the [GitHub repository](https://github.com/donnchawp/cloud-cover-forecast).

== Privacy Policy ==

This plugin uses multiple APIs to provide comprehensive weather and astronomical data:

**Data Collection:**
* No personal data is collected or stored by the plugin
* Only location data (coordinates or city names) entered by users is processed
* No tracking, analytics, or user behavior monitoring

**External Services Used:**
* **Open-Meteo API**: Free weather data service (no API key required)
* **Met.no API**: Norwegian Meteorological Institute weather data (no API key required)
* **IPGeolocation.io API**: Professional astronomical data service (API key required)

**Data Transmission:**
* Location data is only sent to the above services to fetch relevant weather and astronomical information
* No data is shared with any other third parties
* All API communications are secure and encrypted

**Data Storage:**
* Weather data is cached locally using WordPress transients for performance
* No user data is permanently stored
* Cache can be cleared through WordPress admin or expires automatically

== Technical Details ==

**Requirements:**
* WordPress 5.0 or higher
* PHP 7.4 or higher
* Internet connection for API calls

**External Services:**
* **Open-Meteo Weather API**: Free weather data service (no authentication required)
* **Open-Meteo Geocoding API**: Free location-to-coordinates conversion (no authentication required)
* **IPGeolocation.io API**: Professional astronomical data service (registration and API key required)

**IPGeolocation.io Integration:**
* Provides accurate moon phase, moonrise/moonset times, and astronomical twilight data
* Enterprise-grade service trusted by major companies worldwide since 2017
* Free tier available with generous usage limits for most users
* High-precision astronomical calculations for any global location
* Essential for photography and astronomy planning features

**WordPress Integration:**
* Uses WordPress transients for caching
* Follows WordPress coding standards
* Internationalization ready
* Gutenberg block editor compatible
