(function(blocks, element, components, i18n, data) {
	'use strict';

	if (!blocks || !element) {
		return;
	}

	var el = element.createElement;
	var registerBlockType = blocks.registerBlockType;
	if (!registerBlockType) {
		return;
	}

	var __ = i18n && i18n.__ ? i18n.__ : function(str) { return str; };
	var useState = element.useState;
	var useEffect = element.useEffect;
	var dataModule = data || (window.wp && window.wp.data) || {};
	var useSelect = dataModule.useSelect || function() { return null; };
	var useDispatch = dataModule.useDispatch || function() { return function() {}; };
	var blockEditor = window.wp && ( window.wp.blockEditor || window.wp.editor );
	var InspectorControls = blockEditor ? blockEditor.InspectorControls : null;
	var PanelBody = components && components.PanelBody ? components.PanelBody : null;
	var ToggleControl = components && components.ToggleControl ? components.ToggleControl : null;
	var domReady = (window.wp && window.wp.domReady) || function(callback) {
		if (document.readyState !== 'loading') {
			callback();
			return;
		}
		document.addEventListener('DOMContentLoaded', callback);
	};

	domReady(function() {
		registerBlockType('cloud-cover-forecast/public-lookup', {
		title: __('Public Cloud Cover Lookup', 'cloud-cover-forecast'),
		icon: 'search',
		category: 'widgets',
		description: __('Allow public visitors to search for cloud cover conditions at any location.', 'cloud-cover-forecast'),
		supports: {
			align: ['wide', 'full'],
		},
		attributes: {
			title: {
				type: 'string',
				default: __('Cloud Cover Forecast', 'cloud-cover-forecast')
			},
			placeholder: {
				type: 'string',
				default: __('Enter location (e.g., London, UK)', 'cloud-cover-forecast')
			},
			buttonText: {
				type: 'string',
				default: __('Get Forecast', 'cloud-cover-forecast')
			},
			showPhotographyMode: {
				type: 'boolean',
				default: true
			},
			showOtherForecastApps: {
				type: 'boolean',
				default: true
			},
			maxHours: {
				type: 'number',
				default: 24
			}
		},

		edit: function(props) {
			var attributes = props.attributes;
			var setAttributes = props.setAttributes;
			var controls = null;

			if (InspectorControls && PanelBody && ToggleControl) {
				controls = el(
					InspectorControls,
					null,
					el(
						PanelBody,
						{
							title: __('Display Options', 'cloud-cover-forecast'),
							initialOpen: true
						},
						el(ToggleControl, {
							label: __('Show other forecast apps', 'cloud-cover-forecast'),
							checked: attributes.showOtherForecastApps !== false,
							onChange: function(value) {
								setAttributes({ showOtherForecastApps: value });
							}
						})
					)
				);
			}

			return [
				controls,
				el('div', {
					className: 'cloud-cover-forecast-public-block-editor',
					style: {
						padding: '20px',
						border: '2px dashed #ccc',
						borderRadius: '8px',
						backgroundColor: '#f9f9f9',
						textAlign: 'center'
					}
				},
					el('h3', {
						style: { marginTop: '0', color: '#333' }
					}, '🌤️ ' + attributes.title),
					el('p', {
						style: { color: '#666', marginBottom: '20px' }
					}, __('This block allows visitors to search for cloud cover forecasts at any location.', 'cloud-cover-forecast')),
					el('div', {
						style: {
							display: 'flex',
							gap: '10px',
							justifyContent: 'center',
							alignItems: 'center',
							flexWrap: 'wrap'
						}
					},
						el('input', {
							type: 'text',
							placeholder: attributes.placeholder,
							style: {
								padding: '10px',
								border: '1px solid #ddd',
								borderRadius: '4px',
								minWidth: '200px'
							},
							disabled: true
						}),
						el('button', {
							style: {
								padding: '10px 20px',
								backgroundColor: '#0073aa',
								color: 'white',
								border: 'none',
								borderRadius: '4px',
								cursor: 'not-allowed'
							},
							disabled: true
						}, attributes.buttonText)
					),
					el('p', {
						style: {
							fontSize: '12px',
							color: '#999',
							marginTop: '15px',
							fontStyle: 'italic'
						}
					}, __('Preview: The actual search functionality will be available on the frontend.', 'cloud-cover-forecast'))
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
	window.wp.components,
	window.wp.i18n,
	window.wp.data
);
