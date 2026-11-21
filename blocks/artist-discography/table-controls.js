(function (wp) {
	const { createElement: el, Fragment } = wp.element;
	const { InspectorControls, useSettings } = wp.blockEditor;
	const { BoxControl, Button, ColorPalette, PanelBody, RangeControl, SelectControl, ToggleControl } = wp.components;
	const { __, sprintf } = wp.i18n;
	const sides = ['top', 'right', 'bottom', 'left'];

	window.mdsRmDiscographyTableControls = function TableControls({ value: own, defaults = {}, onChange, standalone = false }) {
		const value = { ...defaults, ...own };
		const [palette, customColor, sizes, customSize, families, spacing, customSpacing, lineHeight] = useSettings(
			'color.palette', 'color.custom', 'typography.fontSizes', 'typography.customFontSize',
			'typography.fontFamilies', 'spacing.spacingSizes', 'spacing.customSpacingSize', 'typography.lineHeight'
		);
		function update(changes) {
			const next = { ...own };
			Object.entries(changes).forEach(([key, setting]) => {
				if (setting === undefined || setting === '') delete next[key];
				else next[key] = setting;
			});
			onChange(Object.keys(next).length ? next : undefined);
		}
		function siteDefault(key) {
			if (!Object.prototype.hasOwnProperty.call(defaults, key)) return null;
			return el(Button, {
				variant: 'link',
				onClick: () => update({ [key]: own[key] === null ? undefined : null }),
			}, own[key] === null ? __('Use site default', 'mds-release-manager') : __('Ignore site default', 'mds-release-manager'));
		}
		function select(key, label, options) {
			return el(Fragment, null, el(SelectControl, {
				label, value: value[key] || '',
				options: [{ label: __('Default / inherit', 'mds-release-manager'), value: '' }, ...options],
				onChange: (setting) => update({ [key]: setting }),
			}), siteDefault(key));
		}
		function presetChoices(list, prefix) {
			const presets = Array.isArray(list) ? list : ['default', 'theme', 'custom'].flatMap(
				(origin) => Array.isArray(list?.[origin]) ? list[origin] : []
			);
			return Array.from(new Map(presets.map(({ name, slug }) => [
				slug, { label: name, value: `var(--wp--preset--${prefix}--${slug})` },
			])).values());
		}
		function range(key, label, min, max, step = 1, unit = '') {
			return el(Fragment, null, el(RangeControl, {
				label, min, max, step, allowReset: true,
				value: value[key] !== undefined && value[key] !== null && !String(value[key]).startsWith('var(') ? parseFloat(value[key]) : undefined,
				onChange: (setting) => update({ [key]: setting === undefined ? undefined : `${setting}${unit}` }),
			}), siteDefault(key));
		}
		function color(key, label) {
			return el(Fragment, null,
				select(key, label, presetChoices(palette, 'color')),
				customColor !== false && el(ColorPalette, {
					// Presets are selected separately so a theme color remains a reference.
					colors: [], clearable: true, 'aria-label': label,
					value: typeof value[key] === 'string' && !value[key].startsWith('var(') ? value[key] : undefined,
					onChange: (setting) => update({ [key]: setting }),
				})
			);
		}
		function typography(prefix) {
			return el(Fragment, null,
				select(`${prefix}-font-family`, __('Font family', 'mds-release-manager'), presetChoices(families, 'font-family')),
				select(`${prefix}-font-size`, __('Font size preset', 'mds-release-manager'), presetChoices(sizes, 'font-size')),
				customSize !== false && range(`${prefix}-font-size`, __('Custom font size (px)', 'mds-release-manager'), 8, 96, 1, 'px'),
				select(`${prefix}-font-weight`, __('Font weight', 'mds-release-manager'), [
					{ label: __('Normal', 'mds-release-manager'), value: '400' }, { label: __('Medium', 'mds-release-manager'), value: '500' },
					{ label: __('Semibold', 'mds-release-manager'), value: '600' }, { label: __('Bold', 'mds-release-manager'), value: '700' },
				]),
				select(`${prefix}-font-style`, __('Font style', 'mds-release-manager'), [
					{ label: __('Normal', 'mds-release-manager'), value: 'normal' }, { label: __('Italic', 'mds-release-manager'), value: 'italic' },
				]),
				lineHeight !== false && range(`${prefix}-line-height`, __('Line height', 'mds-release-manager'), 0.8, 3, 0.1)
			);
		}
		function spacingControl(prefix, label) {
			const presets = presetChoices(spacing, 'spacing');
			const sideLabels = {
				top: __('Top', 'mds-release-manager'),
				right: __('Right', 'mds-release-manager'),
				bottom: __('Bottom', 'mds-release-manager'),
				left: __('Left', 'mds-release-manager'),
			};
			// translators: %1$s: Spacing setting. %2$s: Side of the table or cell.
			const presetLabel = __('%1$s preset (%2$s)', 'mds-release-manager');
			// translators: %1$s: Spacing setting. %2$s: Side of the table or cell.
			const pixelLabel = __('%1$s (%2$s, px)', 'mds-release-manager');
			return el(Fragment, null,
				customSpacing !== false && BoxControl && el(BoxControl, {
					label, values: Object.fromEntries(sides.map((side) => [side, value[`${prefix}-${side}`] || ''])),
					inputProps: { min: 0 },
					onChange: (settings) => update(Object.fromEntries(sides.filter((side) =>
						(settings?.[side] || '') !== (value[`${prefix}-${side}`] || '')
					).map((side) => [`${prefix}-${side}`, settings?.[side]]))),
				}),
				sides.map((side) => el(Fragment, { key: side },
					!!presets.length && select(`${prefix}-${side}`, sprintf(presetLabel, label, sideLabels[side]), presets),
					customSpacing !== false && !BoxControl && range(`${prefix}-${side}`, sprintf(pixelLabel, label, sideLabels[side]), 0, 100, 1, 'px')
				))
			);
		}
		function border(prefix) {
			return el(Fragment, null,
				select(`${prefix}-style`, __('Border style', 'mds-release-manager'), [
					{ label: __('None', 'mds-release-manager'), value: 'none' }, { label: __('Solid', 'mds-release-manager'), value: 'solid' },
					{ label: __('Dashed', 'mds-release-manager'), value: 'dashed' }, { label: __('Dotted', 'mds-release-manager'), value: 'dotted' },
					{ label: __('Double', 'mds-release-manager'), value: 'double' },
				]),
				range(`${prefix}-width`, __('Border width (px)', 'mds-release-manager'), 0, 16, 1, 'px'),
				color(`${prefix}-color`, __('Border color', 'mds-release-manager'))
			);
		}
		function region(prefix) {
			return el(Fragment, null,
				color(`${prefix}-background`, __('Background color', 'mds-release-manager')),
				color(`${prefix}-color`, __('Text color', 'mds-release-manager')),
				typography(prefix),
				prefix !== 'table' && select(`${prefix}-text-align`, __('Text alignment', 'mds-release-manager'), [
					{ label: __('Start', 'mds-release-manager'), value: 'start' }, { label: __('Center', 'mds-release-manager'), value: 'center' },
					{ label: __('End', 'mds-release-manager'), value: 'end' },
				])
			);
		}
		return el(standalone ? Fragment : InspectorControls, standalone ? null : { group: 'styles' },
			el(PanelBody, { title: __('Discography table', 'mds-release-manager'), initialOpen: false },
				spacingControl('table-margin', __('Table margin', 'mds-release-manager')),
				spacingControl('table-padding', __('Table padding', 'mds-release-manager')),
				region('table'), border('table-border'),
				range('table-radius', __('Table corner radius (px)', 'mds-release-manager'), 0, 48, 1, 'px')
			),
			el(PanelBody, { title: __('Discography header', 'mds-release-manager'), initialOpen: false }, region('header')),
			el(PanelBody, { title: __('Discography body', 'mds-release-manager'), initialOpen: false },
				region('body'),
				el(ToggleControl, { label: __('Striped rows', 'mds-release-manager'), checked: value.striped === true, onChange: (striped) => update({ striped }) }),
				value.striped === true && color('stripe-background', __('Alternate row color', 'mds-release-manager'))
			),
			el(PanelBody, { title: __('Discography cells and links', 'mds-release-manager'), initialOpen: false },
				spacingControl('cell-padding', __('Cell padding', 'mds-release-manager')), border('cell-border'),
				color('link-color', __('Table link color', 'mds-release-manager')),
				select('link-decoration', __('Table link decoration', 'mds-release-manager'), [
					{ label: __('Underline', 'mds-release-manager'), value: 'underline' }, { label: __('No underline', 'mds-release-manager'), value: 'none' },
				])
			),
			!!Object.keys(own).length && el(Button, { variant: 'tertiary', onClick: () => onChange(undefined) }, __('Reset discography table styles', 'mds-release-manager'))
		);
	};
})(window.wp);
