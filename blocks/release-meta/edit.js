(function (wp) {
	'use strict';

	const { createElement: el } = wp.element;
	const { InspectorControls, useBlockProps } = wp.blockEditor;
	const { Button, PanelBody, TextControl, ToggleControl } = wp.components;
	const { registerBlockType } = wp.blocks;
	const ServerSideRender = wp.serverSideRender.ServerSideRender || wp.serverSideRender;
	const { __, sprintf } = wp.i18n;
	const name = 'mds-rm/release-meta';

	registerBlockType(name, {
		edit: function (props) {
			const { attributes, setAttributes, context, isSelected } = props;
			const defaults = (window.mdsReleaseMeta && window.mdsReleaseMeta.defaults) || {};
			const inheritedItems = defaults.items || (window.mdsReleaseMeta && window.mdsReleaseMeta.items) || [];
			const fields = (window.mdsReleaseMeta && window.mdsReleaseMeta.fields) || {};
			const items = window.mdsRmReleaseItems.resolve(inheritedItems, attributes, 'field');
			const contextId = context?.postId || 0;
			const { picker, source, resolved } = window.mdsRmUseReleasePicker({ attributes, setAttributes, contextId, enabled: isSelected });
			const { RepeaterCard, SectionInfo } = window.mdsRmStylePrimitives || {};

			function updateItem(index, change) {
				window.mdsRmReleaseItems.update(attributes, setAttributes, items, 'field', index, change);
			}
			function moveItem(index, direction) {
				window.mdsRmReleaseItems.move(attributes, setAttributes, items, 'field', index, direction);
			}
			function resetProperty(item, key, label) {
				if (attributes.items || !Object.prototype.hasOwnProperty.call(attributes.itemOverrides?.[item.field] || {}, key)) {
					return null;
				}
				return el(Button, {
					variant: 'link',
					className: 'mds-site-default-btn',
					onClick: function () { window.mdsRmReleaseItems.resetProperty(attributes, setAttributes, item.field, key); },
				}, label);
			}

			const hasOverrides = Boolean(attributes.items || attributes.itemOrder || attributes.itemOverrides);

			return el('div', useBlockProps(),
				el(InspectorControls, null,
					el(PanelBody, { title: __('Release Source', 'mds-release-manager'), initialOpen: true }, picker),
					el(PanelBody, { title: __('Fields & Ordering', 'mds-release-manager'), initialOpen: true },
						SectionInfo && el(SectionInfo, {
							title: __('About Fields & Ordering', 'mds-release-manager'),
							summary: __('Toggle visibility, reorder fields, or click Customize to override icons and tooltips for this block only.', 'mds-release-manager'),
							inheritance: __('Unchanged fields inherit from Releases → Settings → Block Defaults.', 'mds-release-manager'),
						}),
						el('div', { className: 'mds-repeater-list' },
							items.map(function (item, index) {
								const definition = fields[item.field];
								if (!definition) return null;
								const isEnabled = item.enabled !== false;
								const controls = [
									resetProperty(item, 'enabled', __('Use site visibility', 'mds-release-manager')),
									window.mdsRmIconPicker && window.mdsRmIconPicker.IconPickerControl
										? el(window.mdsRmIconPicker.IconPickerControl, {
											key: 'icon-picker',
											label: __('Field Icon', 'mds-release-manager'),
											help: __('Choose a Font Awesome or Dashicons icon, or clear for no icon.', 'mds-release-manager'),
											value: item.icon || '',
											defaultCategory: 'meta',
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
										label: __('Field Tooltip Label', 'mds-release-manager'),
										value: item.title || '',
										onChange: function (value) { updateItem(index, { title: value }); },
									}),
									resetProperty(item, 'title', __('Use site tooltip', 'mds-release-manager')),
									!attributes.items && attributes.itemOverrides?.[item.field] && el(Button, {
										key: 'reset-item',
										variant: 'tertiary',
										isDestructive: true,
										onClick: function () { window.mdsRmReleaseItems.resetItem(attributes, setAttributes, item.field); },
									}, __('Use site defaults for this field', 'mds-release-manager')),
								];

								if (RepeaterCard) {
									return el(RepeaterCard, {
										key: item.field,
										icon: item.icon || '',
										title: definition.label,
										badge: isEnabled ? null : __('Hidden', 'mds-release-manager'),
										enabled: isEnabled,
										onToggleEnabled: function (value) { updateItem(index, { enabled: value }); },
										canMoveUp: index > 0,
										canMoveDown: index < items.length - 1,
										onMoveUp: function () { moveItem(index, -1); },
										onMoveDown: function () { moveItem(index, 1); },
									}, controls);
								}

								return el(PanelBody, { key: item.field, title: definition.label, initialOpen: false },
									el(ToggleControl, {
										label: __('Show field', 'mds-release-manager'),
										checked: isEnabled,
										onChange: function (value) { updateItem(index, { enabled: value }); },
									}),
									controls,
									el(Button, {
										variant: 'secondary',
										disabled: index === 0,
										onClick: function () { moveItem(index, -1); },
										'aria-label': sprintf(__('Move %s up', 'mds-release-manager'), definition.label),
									}, __('Move up', 'mds-release-manager')),
									' ',
									el(Button, {
										variant: 'secondary',
										disabled: index === items.length - 1,
										onClick: function () { moveItem(index, 1); },
										'aria-label': sprintf(__('Move %s down', 'mds-release-manager'), definition.label),
									}, __('Move down', 'mds-release-manager'))
								);
							})
						),
						!attributes.items && attributes.itemOrder && el(Button, {
							variant: 'link',
							className: 'mds-site-default-btn',
							onClick: function () { setAttributes({ itemOrder: undefined }); },
						}, __('Use site field order', 'mds-release-manager')),
						hasOverrides && el('div', { className: 'mds-reset-row' },
							el(Button, {
								variant: 'tertiary',
								isDestructive: true,
								onClick: function () { setAttributes({ items: undefined, itemOrder: undefined, itemOverrides: undefined }); },
							}, __('Use site field defaults', 'mds-release-manager'))
						)
					)
				),
				el(window.mdsRmReleaseMetaControls, { attributes, setAttributes, defaults: defaults.metaStyles || {} }),
				el(ServerSideRender, {
					block: name,
					attributes,
					httpMethod: 'POST',
					urlQueryArgs: contextId ? { post_id: contextId } : undefined,
					EmptyResponsePlaceholder: () => el('p', null, resolved
						? __('This Release has no enabled metadata to display.', 'mds-release-manager')
						: source.error
							? __('The Release source could not be checked.', 'mds-release-manager')
							: source.loading
								? __('Checking Release context…', 'mds-release-manager')
								: __('Select a Release to preview its metadata.', 'mds-release-manager')),
				})
			);
		},
		save: function () { return null; },
	});
})(window.wp);
