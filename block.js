(function(blocks, element, blockEditor, components, i18n) {
	'use strict';

	if (!blocks || !element) {
		return;
	}

	var el = element.createElement;
	var registerBlockType = blocks.registerBlockType;
	if (!registerBlockType) {
		return;
	}

	var editorModule = blockEditor || (window.wp && (window.wp.blockEditor || window.wp.editor)) || {};
	var InspectorControls = editorModule.InspectorControls || null;

	var componentLibrary = components || (window.wp && window.wp.components) || {};
	var PanelBody = componentLibrary.PanelBody || null;
	var TextControl = componentLibrary.TextControl || null;
	var ToggleControl = componentLibrary.ToggleControl || null;
	var RangeControl = componentLibrary.RangeControl || null;
	var Button = componentLibrary.Button || null;
	var Notice = componentLibrary.Notice || null;
	var __ = i18n.__;
	var useState = element.useState;

	var domReady = (window.wp && window.wp.domReady) || function(callback) {
		if (document.readyState !== 'loading') {
			callback();
			return;
		}
		document.addEventListener('DOMContentLoaded', callback);
	};

	domReady(function() {
		registerBlockType('cloud-cover-forecast/block', {
			title: __('Cloud Cover Forecast', 'cloud-cover-forecast'),
			icon: 'cloud',
			category: 'widgets',
		description: __('Display cloud cover forecast with photography and astronomical features for a specific location.', 'cloud-cover-forecast'),

		attributes: {
			latitude: {
				type: 'string',
				default: '51.8986'
			},
			longitude: {
				type: 'string',
				default: '-8.4756'
			},
			hours: {
				type: 'number',
				default: 24
			},
			label: {
				type: 'string',
				default: ''
			},
			location: {
				type: 'string',
				default: ''
			},
		},

		edit: function(props) {
			var attributes = props.attributes;
			var setAttributes = props.setAttributes;
			var hasComponents = InspectorControls && PanelBody && TextControl && ToggleControl && RangeControl && Button && Notice;

			if (!hasComponents) {
				return el('div', {
					className: 'cloud-cover-forecast-block-editor',
					style: {
						padding: '16px',
						border: '1px solid #ccd0d4',
						borderRadius: '4px',
						backgroundColor: '#fff',
						color: '#1d2327'
					}
				},
					__('Cloud Cover Forecast block controls are unavailable because required WordPress components failed to load.', 'cloud-cover-forecast')
				);
			}

			// State for location search
			var searchState = useState('idle'); // idle, searching, results, error
			var currentSearchState = searchState[0];
			var setSearchState = searchState[1];

			var searchResults = useState([]);
			var currentSearchResults = searchResults[0];
			var setSearchResults = searchResults[1];

			var searchTerm = useState('');
			var currentSearchTerm = searchTerm[0];
			var setSearchTerm = searchTerm[1];

			// Location search function
			function searchLocation() {
				if (!currentSearchTerm.trim()) {
					return;
				}

				setSearchState('searching');
				setSearchResults([]);

				// Using fetch instead of jQuery since we're in the block editor
				fetch('https://geocoding-api.open-meteo.com/v1/search?name=' + encodeURIComponent(currentSearchTerm) + '&count=5&format=json')
					.then(function(response) {
						return response.json();
					})
					.then(function(data) {
						if (data.results && data.results.length > 0) {
							if (data.results.length === 1) {
								// Auto-select single result
								var result = data.results[0];
								setAttributes({
									latitude: result.latitude.toString(),
									longitude: result.longitude.toString(),
									location: currentSearchTerm
								});
								setSearchState('idle');
								setSearchResults([]);
							} else {
								// Show multiple results for selection
								setSearchResults(data.results);
								setSearchState('results');
							}
						} else {
							setSearchState('error');
							setSearchResults([]);
						}
					})
					.catch(function(error) {
						setSearchState('error');
						setSearchResults([]);
					});
			}

			function selectLocation(result) {
				setAttributes({
					latitude: result.latitude.toString(),
					longitude: result.longitude.toString(),
					location: currentSearchTerm
				});
				setSearchState('idle');
				setSearchResults([]);
			}

			var inspector = null;
			if (InspectorControls && PanelBody) {
				inspector = el(InspectorControls, {},
					el(PanelBody, {
						title: __('Cloud Cover Forecast Settings', 'cloud-cover-forecast'),
						initialOpen: true
					},
						// Location Search Section
						el('div', { style: { marginBottom: '15px' } },
							el('label', {
								style: { display: 'block', marginBottom: '5px', fontWeight: 'bold' }
							}, __('Location Search', 'cloud-cover-forecast')),
							el('div', { style: { display: 'flex', gap: '8px', marginBottom: '8px' } },
								el('input', {
									type: 'text',
									placeholder: __('Enter location name (e.g., London, UK)', 'cloud-cover-forecast'),
									value: currentSearchTerm,
									onChange: function(e) {
										setSearchTerm(e.target.value);
									},
									onKeyPress: function(e) {
										if (e.key === 'Enter') {
											e.preventDefault();
											searchLocation();
										}
									},
									style: { flex: '1' }
								}),
								el(Button, {
									isPrimary: true,
									isBusy: currentSearchState === 'searching',
									disabled: !currentSearchTerm.trim() || currentSearchState === 'searching',
									onClick: searchLocation
								}, currentSearchState === 'searching' ? __('Searching...', 'cloud-cover-forecast') : __('Search', 'cloud-cover-forecast'))
							),
							// Search results
							currentSearchState === 'results' && currentSearchResults.length > 0 && el('div', {
								style: {
									border: '1px solid #b3d9ff',
									borderRadius: '4px',
									padding: '12px',
									backgroundColor: '#e7f3ff',
									marginBottom: '8px'
								}
							},
								el('strong', {}, __('Multiple locations found. Please select one:', 'cloud-cover-forecast')),
								currentSearchResults.map(function(result, index) {
									var locationParts = [result.name];
									if (result.admin1) locationParts.push(result.admin1);
									if (result.country) locationParts.push(result.country);
									var displayName = locationParts.join(', ');

									return el('div', {
										key: index,
										style: {
											margin: '8px 0',
											padding: '8px',
											backgroundColor: 'white',
											border: '1px solid #ddd',
											borderRadius: '4px',
											cursor: 'pointer'
										},
										onClick: function() {
											selectLocation(result);
										}
									},
										el('strong', {}, displayName),
										el('div', {
											style: { fontSize: '12px', color: '#666', marginTop: '4px' }
										}, '(' + result.latitude + ', ' + result.longitude + ')')
									);
								})
							),
							// Error message
							currentSearchState === 'error' && el(Notice, {
								status: 'error',
								isDismissible: false
							}, __('Location not found. Please try a different search term.', 'cloud-cover-forecast')),
							el('p', {
								style: { fontSize: '12px', color: '#666', margin: '4px 0 0 0' }
							}, __('Search will automatically fill coordinates below', 'cloud-cover-forecast'))
						),

						el('hr', { style: { margin: '15px 0' } }),

						// Manual coordinate entry
						el(TextControl, {
							label: __('Location Name Override', 'cloud-cover-forecast'),
							value: attributes.location,
							onChange: function(value) {
								setAttributes({ location: value });
							},
							help: __('Override location name in shortcode (optional)', 'cloud-cover-forecast')
						}),
						el(TextControl, {
							label: __('Latitude', 'cloud-cover-forecast'),
							value: attributes.latitude,
							onChange: function(value) {
								setAttributes({ latitude: value });
							},
							help: __('Enter the latitude coordinate (e.g., 51.8986)', 'cloud-cover-forecast')
						}),
						el(TextControl, {
							label: __('Longitude', 'cloud-cover-forecast'),
							value: attributes.longitude,
							onChange: function(value) {
								setAttributes({ longitude: value });
							},
							help: __('Enter the longitude coordinate (e.g., -8.4756)', 'cloud-cover-forecast')
						}),
						el(RangeControl, {
							label: __('Hours Ahead', 'cloud-cover-forecast'),
							value: attributes.hours,
							onChange: function(value) {
								setAttributes({ hours: value });
							},
							min: 1,
							max: 168,
							help: __('Number of hours to forecast (1-168)', 'cloud-cover-forecast')
						}),
						el(TextControl, {
							label: __('Label (Optional)', 'cloud-cover-forecast'),
							value: attributes.label,
							onChange: function(value) {
								setAttributes({ label: value });
							},
							help: __('Optional label to display with the forecast', 'cloud-cover-forecast')
						}),

					)
				);
			}

			return [
				inspector,
				el('div', {
					className: 'cloud-cover-forecast-block-preview',
					style: {
						padding: '20px',
						border: '1px solid #ddd',
						borderRadius: '4px',
						backgroundColor: '#f9f9f9'
					}
				},
					el('h3', {},
						'🌤️ ' + __('Cloud Cover & Astronomical Forecast', 'cloud-cover-forecast')
					),
					attributes.location && el('p', {},
						__('Location:', 'cloud-cover-forecast') + ' ' + attributes.location
					),
					!attributes.location && el('p', {},
						__('Coordinates:', 'cloud-cover-forecast') + ' ' + attributes.latitude + ', ' + attributes.longitude
					),
					el('p', {},
						__('Hours:', 'cloud-cover-forecast') + ' ' + attributes.hours
					),
					attributes.label && el('p', {},
						__('Label:', 'cloud-cover-forecast') + ' ' + attributes.label
					),

					// Photography features preview
					el('div', {
						style: {
							marginTop: '10px',
							padding: '8px',
							backgroundColor: '#f0f8ff',
							borderRadius: '4px',
							border: '1px solid #b3d9ff'
						}
					},
						el('p', {
							style: { margin: '0 0 5px 0', fontWeight: 'bold', color: '#2563eb' }
						}, '📸 ' + __('Photography Features:', 'cloud-cover-forecast')),
						el('ul', {
							style: { margin: '0', fontSize: '13px', color: '#374151' }
						},
							el('li', {}, '🌅 ' + __('Sunset photography ratings', 'cloud-cover-forecast')),
							el('li', {}, '🌌 ' + __('Astrophotography analysis', 'cloud-cover-forecast')),
							el('li', {}, '🌙 ' + __('Moon phases and rise/set times', 'cloud-cover-forecast')),
							el('li', {}, '⭐ ' + __('Optimal shooting windows', 'cloud-cover-forecast'))
						)
					),

					el('p', {
						style: {
							fontStyle: 'italic',
							color: '#666',
							marginTop: '15px'
						}
					}, __('Preview: The actual forecast will be displayed on the frontend.', 'cloud-cover-forecast'))
				)
			];
		},

		save: function() {
			// Return null because this is a dynamic block
			return null;
		}
		});
	});

})(
	window.wp.blocks,
	window.wp.element,
	window.wp.blockEditor || window.wp.editor,
	window.wp.components,
	window.wp.i18n
);
