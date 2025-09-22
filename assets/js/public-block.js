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
            var $locationResults = $block.find('.location-search-results');

            var blockData = $block.data('block-attributes');
            var isSearching = false;

            blockData = blockData || {};
            blockData.strings = blockData.strings || {};
            blockData.showClearOutsideLink = blockData.showClearOutsideLink !== false;

            initInstructionToggles($block);

            // Get AJAX data from localized script
            if (typeof cloudCoverForecastPublic !== 'undefined') {
                blockData.ajaxUrl = cloudCoverForecastPublic.ajaxUrl;
                blockData.nonce = cloudCoverForecastPublic.nonce;
                blockData.strings = cloudCoverForecastPublic.strings || blockData.strings;
            }

            var defaultButtonText = blockData.buttonText || 'Get Forecast';

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
                clearLocationResults();
                $searchButton.prop('disabled', true).text(getString('searchingText', 'Searching...'));
                $loadingSpinner.show();
                $errorContainer.hide();
                $rateLimitMessage.hide();
                $resultsContainer.hide();

                // If coordinates are provided, use them directly
                if (lat && lon) {
                    updateURL(location, lat, lon);
                    fetchForecast(lat, lon, location).always(resetSearchUI);
                    return;
                }

                // Otherwise, geocode the location first via AJAX proxy
                var ajaxUrl = blockData.ajaxUrl || (typeof window.ajaxurl !== 'undefined' ? window.ajaxurl : '');
                if (!ajaxUrl) {
                    showError(blockData.strings.geocodingErrorText || 'Unable to find location. Please check your internet connection and try again.');
                    resetSearchUI();
                    return;
                }

                $.ajax({
                    url: ajaxUrl,
                    type: 'POST',
                    dataType: 'json',
                    data: {
                        action: 'cloud_cover_forecast_public_geocode',
                        nonce: blockData.nonce,
                        location: location
                    }
                })
                    .done(function(response) {
                        if (!response || !response.success) {
                            var message = (response && response.data && response.data.message) ? response.data.message : (blockData.strings.geocodingErrorText || 'Unable to find location. Please check your internet connection and try again.');
                            showError(message);
                            resetSearchUI();
                            return;
                        }

                        var results = (response.data && Array.isArray(response.data.results)) ? response.data.results : [];
                        if (!results.length) {
                            showError(blockData.strings.locationNotFoundText || 'Location not found. Please try a different search term.');
                            resetSearchUI();
                            return;
                        }

                        if (results.length === 1) {
                            var single = results[0];
                            var singleLabel = buildLocationLabel(single);
                            updateURL(singleLabel, single.latitude, single.longitude);
                            fetchForecast(single.latitude, single.longitude, singleLabel).always(resetSearchUI);
                            return;
                        }

                        showLocationChoices(results, location);
                        resetSearchUI();
                    })
                    .fail(function(jqXHR) {
                        var message = blockData.strings.geocodingErrorText || 'Unable to find location. Please check your internet connection and try again.';
                        if (jqXHR && jqXHR.responseJSON && jqXHR.responseJSON.data && jqXHR.responseJSON.data.message) {
                            message = jqXHR.responseJSON.data.message;
                        }
                        showError(message);
                        resetSearchUI();
                    });
            }

            function clearLocationResults() {
                $locationResults.hide().empty();
            }

            function getString(key, fallback) {
                if (blockData.strings && blockData.strings[key]) {
                    return blockData.strings[key];
                }
                return fallback;
            }

            function resetSearchUI() {
                isSearching = false;
                $searchButton.prop('disabled', false).text(defaultButtonText);
                $loadingSpinner.hide();
            }

            function buildLocationLabel(result) {
                var parts = [];
                if (result.name) {
                    parts.push(result.name);
                }
                ['admin1', 'admin2', 'country'].forEach(function(key) {
                    if (result[key] && parts.indexOf(result[key]) === -1) {
                        parts.push(result[key]);
                    }
                });
                return parts.join(', ');
            }

            function initInstructionToggles($context) {
                $context.find('.cloud-cover-forecast-instructions').each(function() {
                    var $wrapper = $(this);
                    if ($wrapper.data('ccfInstructionsInit')) {
                        return;
                    }

                    var $toggle = $wrapper.find('.instructions-toggle').first();
                    var $content = $wrapper.find('.instructions-content').first();
                    if (!$toggle.length || !$content.length) {
                        return;
                    }

                    var $icon = $toggle.find('.instructions-toggle-icon').first();
                    $wrapper.data('ccfInstructionsInit', true);
                    $wrapper.addClass('is-collapsed').removeClass('is-open');
                    $toggle.attr('aria-expanded', 'false');
                    $content.attr('hidden', 'hidden');
                    if ($icon.length) {
                        $icon.text('▸');
                    }
                });
            }

            function toggleInstructionState($toggle) {
                var $wrapper = $toggle.closest('.cloud-cover-forecast-instructions');
                var $content = $wrapper.find('.instructions-content').first();
                if (!$content.length) {
                    return;
                }

                var $icon = $toggle.find('.instructions-toggle-icon').first();
                var isExpanded = $toggle.attr('aria-expanded') === 'true';
                if (isExpanded) {
                    $toggle.attr('aria-expanded', 'false');
                    $wrapper.addClass('is-collapsed').removeClass('is-open');
                    $content.attr('hidden', 'hidden');
                    if ($icon.length) {
                        $icon.text('▸');
                    }
                } else {
                    $toggle.attr('aria-expanded', 'true');
                    $wrapper.addClass('is-open').removeClass('is-collapsed');
                    $content.removeAttr('hidden');
                    if ($icon.length) {
                        $icon.text('▾');
                    }
                }
            }

            $(document)
                .off('click.ccfInstructions')
                .on('click.ccfInstructions', '.cloud-cover-forecast-instructions .instructions-toggle', function(event) {
                    event.preventDefault();
                    toggleInstructionState($(this));
                });

            function showLocationChoices(results, originalQuery) {
                clearLocationResults();

                var promptText = (blockData.strings && blockData.strings.multipleLocationsText) ||
                    'Multiple matches found. Please pick one:';
                var buttonText = (blockData.strings && blockData.strings.useLocationButtonText) ||
                    'Use this location';

                var $wrapper = $('<div/>').addClass('location-results-wrapper');
                $('<p/>').addClass('location-results-title').text(promptText).appendTo($wrapper);

                var $list = $('<ul/>').addClass('location-results-list');

                results.slice(0, 5).forEach(function(result) {
                    var label = buildLocationLabel(result);
                    if (!label) {
                        label = originalQuery || '';
                    }

                    var $item = $('<li/>').addClass('location-results-item');
                    var $button = $('<button/>')
                        .attr('type', 'button')
                        .addClass('location-results-button')
                        .attr('role', 'option')
                        .attr('aria-selected', 'false')
                        .on('click', function() {
                            performSearch(label, result.latitude, result.longitude);
                        });

                    $('<span/>').addClass('location-results-label').text(label).appendTo($button);

                    var extra = [];
                    if (typeof result.latitude === 'number' && typeof result.longitude === 'number') {
                        extra.push(result.latitude.toFixed(2) + ', ' + result.longitude.toFixed(2));
                    }
                    if (result.timezone) {
                        extra.push(result.timezone);
                    }

                    if (extra.length) {
                        $('<span/>').addClass('location-results-meta').text(extra.join(' • ')).appendTo($button);
                    }

                    $('<span/>').addClass('location-results-cta').text(buttonText).appendTo($button);

                    $button.appendTo($item);
                    $item.appendTo($list);
                });

                $list.appendTo($wrapper);
                $locationResults.append($wrapper).show();
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
                    show_clear_outside_link: blockData.showClearOutsideLink ? 1 : 0,
                    nonce: blockData.nonce
                };

                return $.post(blockData.ajaxUrl, ajaxData)
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
                initInstructionToggles($resultsContainer);

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
