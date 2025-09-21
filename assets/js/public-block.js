(function($) {
    'use strict';

    // Rate limiting configuration
    var RATE_LIMIT_WINDOW = 60; // 1 minute in seconds
    var RATE_LIMIT_MAX_REQUESTS = 5; // Max requests per window
    var RATE_LIMIT_STORAGE_KEY = 'cloud_cover_forecast_rate_limit';

    // Rate limiting functions
    function getRateLimitData() {
        var data = localStorage.getItem(RATE_LIMIT_STORAGE_KEY);
        if (!data) {
            return { requests: [], windowStart: Date.now() };
        }
        return JSON.parse(data);
    }

    function setRateLimitData(data) {
        localStorage.setItem(RATE_LIMIT_STORAGE_KEY, JSON.stringify(data));
    }

    function isRateLimited() {
        var data = getRateLimitData();
        var now = Date.now();

        // Reset window if more than RATE_LIMIT_WINDOW seconds have passed
        if (now - data.windowStart > RATE_LIMIT_WINDOW * 1000) {
            data.requests = [];
            data.windowStart = now;
            setRateLimitData(data);
            return false;
        }

        // Remove requests older than the window
        data.requests = data.requests.filter(function(timestamp) {
            return now - timestamp < RATE_LIMIT_WINDOW * 1000;
        });

        // Check if we've exceeded the limit
        if (data.requests.length >= RATE_LIMIT_MAX_REQUESTS) {
            return true;
        }

        // Add current request
        data.requests.push(now);
        setRateLimitData(data);
        return false;
    }

    function getRemainingTime() {
        var data = getRateLimitData();
        var now = Date.now();
        var oldestRequest = Math.min.apply(Math, data.requests);
        var resetTime = oldestRequest + (RATE_LIMIT_WINDOW * 1000);
        return Math.ceil((resetTime - now) / 1000);
    }

    // URL parameter management
    function updateURL(location, lat, lon) {
        if (history.pushState) {
            var url = new URL(window.location);
            if (location) {
                url.searchParams.set('location', location);
            }
            if (lat && lon) {
                url.searchParams.set('lat', lat);
                url.searchParams.set('lon', lon);
            }
            history.pushState(null, '', url);
        }
    }

    function getURLParams() {
        var urlParams = new URLSearchParams(window.location.search);
        return {
            location: urlParams.get('location'),
            lat: urlParams.get('lat'),
            lon: urlParams.get('lon')
        };
    }

    // Main public block functionality
    function initPublicBlock() {
        $('.cloud-cover-forecast-public-lookup').each(function() {
            var $block = $(this);
            var $searchInput = $block.find('.location-search-input');
            var $searchButton = $block.find('.location-search-button');
            var $resultsContainer = $block.find('.forecast-results');
            var $errorContainer = $block.find('.error-message');
            var $rateLimitMessage = $block.find('.rate-limit-message');
            var $loadingSpinner = $block.find('.loading-spinner');

            var blockData = $block.data('block-attributes');
            var isSearching = false;

            // Get AJAX data from localized script
            if (typeof cloudCoverForecastPublic !== 'undefined') {
                blockData.ajaxUrl = cloudCoverForecastPublic.ajaxUrl;
                blockData.nonce = cloudCoverForecastPublic.nonce;
                blockData.strings = cloudCoverForecastPublic.strings || {};
            }

            // Initialize from URL parameters
            var urlParams = getURLParams();
            if (urlParams.location || (urlParams.lat && urlParams.lon)) {
                if (urlParams.location) {
                    $searchInput.val(urlParams.location);
                }
                // Don't show searching state for URL parameter initialization
                fetchForecast(urlParams.lat, urlParams.lon, urlParams.location);
            }

            // Search button click handler
            $searchButton.on('click', function(e) {
                e.preventDefault();
                var location = $searchInput.val().trim();
                if (location && !isSearching) {
                    performSearch(location);
                }
            });

            // Enter key handler
            $searchInput.on('keypress', function(e) {
                if (e.which === 13) {
                    e.preventDefault();
                    $searchButton.click();
                }
            });

            // Geocoding search function
            function performSearch(location, lat, lon) {
                if (isSearching) return;

                // Check rate limiting
                if (isRateLimited()) {
                    showRateLimitError();
                    return;
                }

                isSearching = true;
                $searchButton.prop('disabled', true).text(blockData.searchingText || 'Searching...');
                $loadingSpinner.show();
                $errorContainer.hide();
                $rateLimitMessage.hide();
                $resultsContainer.hide();

                // If coordinates are provided, use them directly
                if (lat && lon) {
                    fetchForecast(lat, lon, location);
                    return;
                }

                // Otherwise, geocode the location first
                var geocodeUrl = 'https://geocoding-api.open-meteo.com/v1/search?name=' +
                    encodeURIComponent(location) + '&count=1&format=json';

                $.get(geocodeUrl)
                    .done(function(data) {
                        if (data.results && data.results.length > 0) {
                            var result = data.results[0];
                            var coords = {
                                lat: result.latitude,
                                lon: result.longitude,
                                name: result.name + (result.country ? ', ' + result.country : '')
                            };

                            // Update URL with location and coordinates
                            updateURL(coords.name, coords.lat, coords.lon);

                            fetchForecast(coords.lat, coords.lon, coords.name);
                        } else {
                            showError(blockData.strings.locationNotFoundText || 'Location not found. Please try a different search term.');
                        }
                    })
                    .fail(function() {
                        showError(blockData.strings.geocodingErrorText || 'Unable to find location. Please check your internet connection and try again.');
                    })
                    .always(function() {
                        isSearching = false;
                        $searchButton.prop('disabled', false).text(blockData.buttonText || 'Get Forecast');
                        $loadingSpinner.hide();
                    });
            }

            // Fetch forecast data
            function fetchForecast(lat, lon, locationName) {
                var ajaxData = {
                    action: 'cloud_cover_forecast_public_lookup',
                    lat: lat,
                    lon: lon,
                    location: locationName,
                    hours: blockData.maxHours || 48,
                    show_photography: blockData.showPhotographyMode ? 1 : 0,
                    nonce: blockData.nonce
                };

                $.post(blockData.ajaxUrl, ajaxData)
                    .done(function(response) {
                        if (response.success) {
                            displayResults(response.data, locationName);
                        } else {
                            showError(response.data || 'Unable to fetch forecast data.');
                        }
                    })
                    .fail(function(xhr, status, error) {
                        showError(blockData.strings.forecastErrorText || 'Unable to fetch forecast data. Please try again later.');
                    });
            }

            // Display forecast results
            function displayResults(data, locationName) {
                $resultsContainer.html(data.html).show();

                // Update page title if possible
                if (locationName && document.title) {
                    var originalTitle = document.title;
                    if (!originalTitle.includes(locationName)) {
                        document.title = locationName + ' - ' + originalTitle;
                    }
                }
            }

            // Show error message
            function showError(message) {
                $errorContainer.html('<div class="error">' + message + '</div>').show();
            }

            // Show rate limit error
            function showRateLimitError() {
                var remainingTime = getRemainingTime();
                var message = blockData.strings.rateLimitText || 'Too many requests. Please wait {time} seconds before trying again.';
                message = message.replace('{time}', remainingTime);
                $rateLimitMessage.html('<div class="rate-limit-error">' + message + '</div>').show();

                // Update countdown
                var countdown = setInterval(function() {
                    remainingTime--;
                    if (remainingTime <= 0) {
                        clearInterval(countdown);
                        $rateLimitMessage.hide();
                    } else {
                        var updatedMessage = (blockData.strings.rateLimitText || 'Too many requests. Please wait {time} seconds before trying again.').replace('{time}', remainingTime);
                        $rateLimitMessage.html('<div class="rate-limit-error">' + updatedMessage + '</div>');
                    }
                }, 1000);
            }
        });
    }

    // Initialize when document is ready
    $(document).ready(function() {
        initPublicBlock();
    });

    // Re-initialize on AJAX content loads (for dynamic content)
    $(document).on('DOMNodeInserted', function(e) {
        if ($(e.target).find('.cloud-cover-forecast-public-lookup').length > 0) {
            initPublicBlock();
        }
    });

})(jQuery);
