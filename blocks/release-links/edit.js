(function (wp) {
	'use strict';

	const { createElement: el, useState } = wp.element;
	const { InspectorControls, useBlockProps } = wp.blockEditor;
	const { Button, PanelBody, SelectControl, TextControl, ToggleControl } = wp.components;
	const { registerBlockType } = wp.blocks;
	const ServerSideRender = wp.serverSideRender.ServerSideRender || wp.serverSideRender;
	const { __, sprintf } = wp.i18n;
	const name = 'mds-rm/release-links';

	registerBlockType(name, {
		edit: function (props) {
			const { attributes, setAttributes, context, isSelected } = props;
			const defaults = (window.mdsReleaseLinks && window.mdsReleaseLinks.defaults) || {};
			const providers = (window.mdsReleaseLinks && Array.isArray(window.mdsReleaseLinks.providers)) ? window.mdsReleaseLinks.providers : [];
			const [customName, setCustomName] = useState('');
			const contextId = context?.postId || 0;
			const { source, resolved, picker } = window.mdsRmUseReleasePicker({ attributes, setAttributes, contextId, enabled: isSelected });
			const { RepeaterCard, SectionInfo, ControlGroup } = window.mdsRmStylePrimitives || {};

			const inheritedItems = (defaults.items || []).slice();
			providers.map(function (provider) { return provider.name; }).concat(['__buy_now']).forEach(function (providerName) {
				if (providerName && !inheritedItems.some(function (item) { return item.name.toLowerCase() === providerName.toLowerCase(); })) {
					inheritedItems.push({ name: providerName, enabled: true });
				}
			});
			const items = window.mdsRmReleaseItems.resolve(inheritedItems, attributes, 'name');
			if (attributes.items) {
				inheritedItems.forEach(function (item) {
					if (!items.some(function (saved) { return saved.name.toLowerCase() === item.name.toLowerCase(); })) {
						items.push(item);
					}
				});
			}

			function updateItem(index, change) {
				window.mdsRmReleaseItems.update(attributes, setAttributes, items, 'name', index, change);
			}
			function moveItem(index, direction) {
				window.mdsRmReleaseItems.move(attributes, setAttributes, items, 'name', index, direction);
			}
			function resetProperty(item, key, label) {
				if (attributes.items || !Object.prototype.hasOwnProperty.call(attributes.itemOverrides?.[item.name] || {}, key)) {
					return null;
				}
				return el(Button, {
					variant: 'link',
					className: 'mds-site-default-btn',
					onClick: function () { window.mdsRmReleaseItems.resetProperty(attributes, setAttributes, item.name, key); },
				}, label);
			}

			const hasItemOverrides = Boolean(attributes.items || attributes.itemOrder || attributes.itemOverrides);

			return el('div', useBlockProps(),
				el(InspectorControls, null,
					el(PanelBody, { title: __('Release Source & Behavior', 'mds-release-manager'), initialOpen: true },
						picker,
						el(SelectControl, {
							label: __('Open Links in a New Tab', 'mds-release-manager'),
							value: attributes.newTab === undefined ? '' : String(attributes.newTab),
							options: [
								{ label: defaults.newTab === false ? __('Use site default (No)', 'mds-release-manager') : __('Use site default (Yes)', 'mds-release-manager'), value: '' },
								{ label: __('Yes', 'mds-release-manager'), value: 'true' },
								{ label: __('No', 'mds-release-manager'), value: 'false' },
							],
							onChange: function (value) { setAttributes({ newTab: value === '' ? undefined : value === 'true' }); },
						}),
						el(SelectControl, {
							label: __('Multi-Format Buy Now Behavior', 'mds-release-manager'),
							help: __('Applies when a release has 2+ Media Formats and no store extension overrides Buy Now.', 'mds-release-manager'),
							value: attributes.buyNowMode === undefined ? '' : String(attributes.buyNowMode),
							options: [
								{ label: defaults.buyNowMode === 'direct' ? __('Use site default (Primary Direct Link)', 'mds-release-manager') : __('Use site default (Choose Format Modal)', 'mds-release-manager'), value: '' },
								{ label: __('Choose Format Modal', 'mds-release-manager'), value: 'picker' },
								{ label: __('Primary Direct Link', 'mds-release-manager'), value: 'direct' },
							],
							onChange: function (value) { setAttributes({ buyNowMode: value === '' ? undefined : value }); },
						})
					),
					el(PanelBody, { title: __('Streaming Providers & Buy Now', 'mds-release-manager'), initialOpen: true },
						SectionInfo && el(SectionInfo, {
							title: __('About Streaming Providers & Buy Now', 'mds-release-manager'),
							summary: __('Toggle visibility, reorder buttons, or click Customize to override icons and tooltips for this block only.', 'mds-release-manager'),
							inheritance: __('URLs come from the Release. Unchanged items inherit from Releases → Settings → Block Defaults.', 'mds-release-manager'),
						}),
						el('div', { className: 'mds-repeater-list' },
							items.map(function (item, index) {
								const isBuy = item.name === '__buy_now';
								const label = isBuy ? __('Buy Now', 'mds-release-manager') : item.name;
								const provider = providers.find(function (p) { return p.name.toLowerCase() === item.name.toLowerCase(); });
								const isEnabled = item.enabled !== false;
								const displayIcon = (item.icon && item.icon.trim()) || (provider && provider.icon) || (isBuy ? 'fas fa-shopping-cart' : '');

								const controls = [
									resetProperty(item, 'enabled', __('Use site visibility', 'mds-release-manager')),
									window.mdsRmIconPicker && window.mdsRmIconPicker.IconPickerControl
										? el(window.mdsRmIconPicker.IconPickerControl, {
											key: 'icon-picker',
											label: __('Link Icon', 'mds-release-manager'),
											help: __('Leave empty to use the provider default.', 'mds-release-manager'),
											value: item.icon || '',
											defaultCategory: isBuy ? 'commerce' : 'brands',
											onChange: function (value) { updateItem(index, { icon: value }); },
										})
										: el(TextControl, {
											key: 'icon-text',
											label: __('Icon CSS Classes', 'mds-release-manager'),
											value: item.icon || '',
											onChange: function (value) { updateItem(index, { icon: value }); },
										}),
									resetProperty(item, 'icon', __('Use site icon', 'mds-release-manager')),
									el(TextControl, {
										key: 'tooltip',
										label: __('Accessible Label & Tooltip', 'mds-release-manager'),
										help: __('Leave blank to use the provider name.', 'mds-release-manager'),
										value: item.title || '',
										onChange: function (value) { updateItem(index, { title: value }); },
									}),
									resetProperty(item, 'title', __('Use site tooltip', 'mds-release-manager')),
									!attributes.items && attributes.itemOverrides?.[item.name] && el(Button, {
										key: 'reset-item',
										variant: 'tertiary',
										isDestructive: true,
										onClick: function () { window.mdsRmReleaseItems.resetItem(attributes, setAttributes, item.name); },
									}, __('Use site defaults for this link', 'mds-release-manager')),
								];

								if (RepeaterCard) {
									return el(RepeaterCard, {
										key: item.name,
										icon: displayIcon,
										swatchColor: provider && provider.color ? provider.color : null,
										title: label,
										badge: isBuy ? __('Core CTA', 'mds-release-manager') : (!isEnabled ? __('Hidden', 'mds-release-manager') : null),
										enabled: isEnabled,
										onToggleEnabled: function (value) { updateItem(index, { enabled: value }); },
										canMoveUp: index > 0,
										canMoveDown: index < items.length - 1,
										onMoveUp: function () { moveItem(index, -1); },
										onMoveDown: function () { moveItem(index, 1); },
									}, controls);
								}

								return el(PanelBody, { key: item.name, title: label, initialOpen: false },
									el(ToggleControl, {
										label: __('Show link', 'mds-release-manager'),
										checked: isEnabled,
										onChange: function (value) { updateItem(index, { enabled: value }); },
									}),
									controls,
									el(Button, {
										variant: 'secondary',
										disabled: index === 0,
										onClick: function () { moveItem(index, -1); },
										'aria-label': sprintf(__('Move %s up', 'mds-release-manager'), label),
									}, __('Move up', 'mds-release-manager')),
									' ',
									el(Button, {
										variant: 'secondary',
										disabled: index === items.length - 1,
										onClick: function () { moveItem(index, 1); },
										'aria-label': sprintf(__('Move %s down', 'mds-release-manager'), label),
									}, __('Move down', 'mds-release-manager'))
								);
							})
						),
						el(ControlGroup || 'div', ControlGroup ? { title: __('Custom Provider Override', 'mds-release-manager') } : null,
							el(TextControl, {
								label: __('Additional Provider Name', 'mds-release-manager'),
								help: __('Add an override for a saved provider not in current site settings.', 'mds-release-manager'),
								value: customName,
								onChange: setCustomName,
							}),
							el(Button, {
								variant: 'secondary',
								disabled: !customName.trim() || items.some(function (item) { return item.name.toLowerCase() === customName.trim().toLowerCase(); }),
								onClick: function () {
									const clean = customName.trim();
									if (attributes.items) setAttributes({ items: items.concat([{ name: clean, enabled: true }]) });
									else setAttributes({ itemOverrides: { ...attributes.itemOverrides, [clean]: { enabled: true } } });
									setCustomName('');
								},
							}, __('Add Provider Override', 'mds-release-manager'))
						),
						!attributes.items && attributes.itemOrder && el(Button, {
							variant: 'link',
							className: 'mds-site-default-btn',
							onClick: function () { setAttributes({ itemOrder: undefined }); },
						}, __('Use site link order', 'mds-release-manager')),
						hasItemOverrides && el('div', { className: 'mds-reset-row' },
							el(Button, {
								variant: 'tertiary',
								isDestructive: true,
								onClick: function () { setAttributes({ items: undefined, itemOrder: undefined, itemOverrides: undefined }); },
							}, __('Use site link defaults', 'mds-release-manager'))
						)
					)
				),
				el(window.mdsRmReleaseLinksControls, { attributes, setAttributes, defaults: defaults.linkStyles || {} }),
				el(ServerSideRender, {
					block: name,
					attributes,
					httpMethod: 'POST',
					urlQueryArgs: contextId ? { post_id: contextId } : undefined,
					EmptyResponsePlaceholder: () => el('p', null, resolved
						? __('This Release has no links to display.', 'mds-release-manager')
						: source.error
							? __('The Release source could not be checked.', 'mds-release-manager')
							: source.loading
								? __('Checking Release context…', 'mds-release-manager')
								: __('Select a Release to preview its links.', 'mds-release-manager')),
				})
			);
		},
		save: function () { return null; },
	});
})(window.wp);
