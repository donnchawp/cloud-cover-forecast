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
		registerBlockType('cloud-cover-forecast/sunrise-sunset', {
			title: __('3-Day Sunrise & Sunset Forecast', 'cloud-cover-forecast'),
			icon: 'clock',
			category: 'widgets',
			description: __('Display 3-day sunrise and sunset forecast with shooting condition summaries.', 'cloud-cover-forecast'),

			attributes: {
				latitude: {
					type: 'number',
					default: 0
				},
				longitude: {
					type: 'number',
					default: 0
				},
				location: {
					type: 'string',
					default: ''
				}
			},

			edit: function(props) {
				var attributes = props.attributes;
				var setAttributes = props.setAttributes;
				var hasComponents = InspectorControls && PanelBody && TextControl && Button && Notice;

				if (!hasComponents) {
					return el('div', {
						className: 'sunrise-sunset-block-editor',
						style: {
							padding: '16px',
							border: '1px solid #ccd0d4',
							borderRadius: '4px',
							backgroundColor: '#fff',
							color: '#1d2327'
						}
					},
						__('3-Day Sunrise & Sunset block controls are unavailable because required WordPress components failed to load.', 'cloud-cover-forecast')
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

					// Using fetch for geocoding
					fetch('https://geocoding-api.open-meteo.com/v1/search?name=' + encodeURIComponent(currentSearchTerm) + '&count=5&format=json')
						.then(function(response) {
							return response.json();
						})
						.then(function(data) {
							if (data.results && Array.isArray(data.results) && data.results.length > 0) {
								// Filter results with valid numeric coordinates.
								var validResults = data.results.filter(function(r) {
									return typeof r.latitude === 'number' && typeof r.longitude === 'number';
								});
								if (validResults.length === 0) {
									setSearchState('error');
									setSearchResults([]);
									return;
								}
								if (validResults.length === 1) {
									// Auto-select single result
									var result = validResults[0];
									setAttributes({
										latitude: result.latitude,
										longitude: result.longitude,
										location: currentSearchTerm
									});
									setSearchState('idle');
									setSearchResults([]);
								} else {
									// Show multiple results for selection
									setSearchResults(validResults);
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
					// Validate result has numeric coordinates.
					if (typeof result.latitude !== 'number' || typeof result.longitude !== 'number') {
						setSearchState('error');
						return;
					}
					setAttributes({
						latitude: result.latitude,
						longitude: result.longitude,
						location: currentSearchTerm
					});
					setSearchState('idle');
					setSearchResults([]);
				}

				var inspector = null;
				if (InspectorControls && PanelBody) {
					inspector = el(InspectorControls, {},
						el(PanelBody, {
							title: __('3-Day Sunrise & Sunset Settings', 'cloud-cover-forecast'),
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
												marginTop: '8px',
												padding: '8px',
												backgroundColor: '#fff',
												border: '1px solid #ddd',
												borderRadius: '3px',
												cursor: 'pointer'
											}
										},
											el('div', {
												style: { marginBottom: '4px', fontWeight: 'bold' }
											}, displayName),
											el('div', {
												style: { fontSize: '0.85em', color: '#666', marginBottom: '6px' }
											}, __('Coordinates: ', 'cloud-cover-forecast') + result.latitude.toFixed(4) + ', ' + result.longitude.toFixed(4)),
											el(Button, {
												isSecondary: true,
												isSmall: true,
												onClick: function() {
													selectLocation(result);
												}
											}, __('Use this location', 'cloud-cover-forecast'))
										);
									})
								),
								// Error message
								currentSearchState === 'error' && el(Notice, {
									status: 'error',
									isDismissible: false
								}, __('Location not found. Please try a different search term.', 'cloud-cover-forecast'))
							),

							// Manual coordinate input
							el('div', { style: { marginTop: '20px', paddingTop: '15px', borderTop: '1px solid #ddd' } },
								el('label', {
									style: { display: 'block', marginBottom: '5px', fontWeight: 'bold' }
								}, __('Or enter coordinates manually:', 'cloud-cover-forecast')),
								el(TextControl, {
									label: __('Latitude', 'cloud-cover-forecast'),
									value: attributes.latitude,
									onChange: function(value) {
										setAttributes({ latitude: parseFloat(value) || 0 });
									},
									type: 'number',
									step: '0.0001'
								}),
								el(TextControl, {
									label: __('Longitude', 'cloud-cover-forecast'),
									value: attributes.longitude,
									onChange: function(value) {
										setAttributes({ longitude: parseFloat(value) || 0 });
									},
									type: 'number',
									step: '0.0001'
								}),
								el(TextControl, {
									label: __('Location Label', 'cloud-cover-forecast'),
									value: attributes.location,
									onChange: function(value) {
										setAttributes({ location: value });
									},
									help: __('Optional display name for the location', 'cloud-cover-forecast')
								})
							)
						)
					);
				}

				// Block preview in editor
				var previewText = __('3-Day Sunrise & Sunset Forecast', 'cloud-cover-forecast');
				if (attributes.location) {
					previewText += ' - ' + attributes.location;
				} else if (attributes.latitude !== 0 || attributes.longitude !== 0) {
					previewText += ' (' + attributes.latitude.toFixed(4) + ', ' + attributes.longitude.toFixed(4) + ')';
				} else {
					previewText += ' - ' + __('Using default location', 'cloud-cover-forecast');
				}

				return el('div', { className: props.className },
					inspector,
					el('div', {
						style: {
							padding: '20px',
							border: '2px dashed #ccd0d4',
							borderRadius: '4px',
							backgroundColor: '#f0f0f1',
							textAlign: 'center'
						}
					},
						el('div', {
							style: {
								fontSize: '16px',
								fontWeight: 'bold',
								marginBottom: '10px',
								color: '#1d2327'
							}
						}, '🌅 ' + previewText),
						el('div', {
							style: {
								fontSize: '14px',
								color: '#50575e'
							}
						}, __('The forecast will be displayed here on the frontend.', 'cloud-cover-forecast')),
						el('div', {
							style: {
								fontSize: '12px',
								color: '#787c82',
								marginTop: '8px'
							}
						}, __('Use the block settings sidebar to configure the location.', 'cloud-cover-forecast'))
					)
				);
			},

			save: function() {
				// Server-side rendering, so return null
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
