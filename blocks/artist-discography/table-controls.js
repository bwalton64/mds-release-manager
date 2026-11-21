(function (wp) {
	const { createElement: el, Fragment } = wp.element;
	const { InspectorControls, useSettings } = wp.blockEditor;
	const { BoxControl, Button, ColorPalette, PanelBody, RangeControl, SelectControl, ToggleControl } = wp.components;
	const { __, sprintf } = wp.i18n;
	const sides = ['top', 'right', 'bottom', 'left'];

	window.mdsRmDiscographyTableControls = function TableControls({ value: own, defaults = {}, onChange, standalone = false, stylePanelProps }) {
		const value = { ...defaults, ...own };
		const [palette, customColor, sizes, customSize, families, spacing, customSpacing, lineHeight] = useSettings(
			'color.palette', 'color.custom', 'typography.fontSizes', 'typography.customFontSize',
			'typography.fontFamilies', 'spacing.spacingSizes', 'spacing.customSpacingSize', 'typography.lineHeight'
		);
		const targets = {
			table: __('Table', 'mds-release-manager'),
			header: __('Column headings', 'mds-release-manager'),
			body: __('Release rows', 'mds-release-manager'),
			'table-border': __('Table frame', 'mds-release-manager'),
			'cell-border': __('Cell grid', 'mds-release-manager'),
		};
		// translators: %1$s: Part of the discography. %2$s: Style setting.
		const scope = (target, label) => sprintf(__('%1$s: %2$s', 'mds-release-manager'), targets[target], label);
		const help = (text) => el('p', { className: 'description' }, text);
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
				// translators: %s: Name of the color setting.
				select(key, sprintf(__('%s preset', 'mds-release-manager'), label), presetChoices(palette, 'color')),
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
				select(`${prefix}-font-family`, scope(prefix, __('Font family', 'mds-release-manager')), presetChoices(families, 'font-family')),
				select(`${prefix}-font-size`, scope(prefix, __('Font size preset', 'mds-release-manager')), presetChoices(sizes, 'font-size')),
				customSize !== false && range(`${prefix}-font-size`, scope(prefix, __('Custom font size (px)', 'mds-release-manager')), 8, 96, 1, 'px'),
				select(`${prefix}-font-weight`, scope(prefix, __('Font weight', 'mds-release-manager')), [
					{ label: __('Normal', 'mds-release-manager'), value: '400' }, { label: __('Medium', 'mds-release-manager'), value: '500' },
					{ label: __('Semibold', 'mds-release-manager'), value: '600' }, { label: __('Bold', 'mds-release-manager'), value: '700' },
				]),
				select(`${prefix}-font-style`, scope(prefix, __('Font style', 'mds-release-manager')), [
					{ label: __('Normal', 'mds-release-manager'), value: 'normal' }, { label: __('Italic', 'mds-release-manager'), value: 'italic' },
				]),
				lineHeight !== false && range(`${prefix}-line-height`, scope(prefix, __('Line height', 'mds-release-manager')), 0.8, 3, 0.1)
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
				select(`${prefix}-style`, scope(prefix, __('Border style', 'mds-release-manager')), [
					{ label: __('None', 'mds-release-manager'), value: 'none' }, { label: __('Solid', 'mds-release-manager'), value: 'solid' },
					{ label: __('Dashed', 'mds-release-manager'), value: 'dashed' }, { label: __('Dotted', 'mds-release-manager'), value: 'dotted' },
					{ label: __('Double', 'mds-release-manager'), value: 'double' },
				]),
				range(`${prefix}-width`, scope(prefix, __('Border width (px)', 'mds-release-manager')), 0, 16, 1, 'px'),
				color(`${prefix}-color`, scope(prefix, __('Border color', 'mds-release-manager')))
			);
		}
		function region(prefix) {
			return el(Fragment, null,
				color(`${prefix}-background`, scope(prefix, __('Background color', 'mds-release-manager'))),
				color(`${prefix}-color`, scope(prefix, __('Text color', 'mds-release-manager'))),
				typography(prefix),
				prefix !== 'table' && select(`${prefix}-text-align`, scope(prefix, __('Text alignment', 'mds-release-manager')), [
					{ label: __('Start', 'mds-release-manager'), value: 'start' }, { label: __('Center', 'mds-release-manager'), value: 'center' },
					{ label: __('End', 'mds-release-manager'), value: 'end' },
				])
			);
		}
		return el(standalone ? Fragment : InspectorControls, standalone ? null : { group: 'styles' },
			!standalone && el(PanelBody, { title: __('Outer discography block', 'mds-release-manager'), initialOpen: false },
				help(__('Use WordPress’s built-in Color, Typography, Dimensions, and Border controls in this Styles tab to style the whole discography, including the area around the table and pagination. The panels below style parts of the table separately. Available controls depend on the active theme.', 'mds-release-manager'))
			),
			el(PanelBody, { title: __('Table frame and base styles', 'mds-release-manager'), initialOpen: false, ...stylePanelProps?.('table') },
				help(__('Styles the table and the scrolling frame around it, not the outer block or pagination. The table sets the base text and background; column headings and release rows can override them. Frame padding adds space around the table, not inside each cell.', 'mds-release-manager')),
				spacingControl('table-margin', __('Table frame margin', 'mds-release-manager')),
				spacingControl('table-padding', __('Table frame padding', 'mds-release-manager')),
				region('table'), border('table-border'),
				range('table-radius', __('Table corner radius (px)', 'mds-release-manager'), 0, 48, 1, 'px')
			),
			el(PanelBody, { title: __('Column headings', 'mds-release-manager'), initialOpen: false, ...stylePanelProps?.('header') },
				help(__('Styles only the top row with labels such as Title, UPC/EAN, and Release Year. It does not style the page heading or the rows below.', 'mds-release-manager')),
				region('header')
			),
			el(PanelBody, { title: __('Release rows and striping', 'mds-release-manager'), initialOpen: false, ...stylePanelProps?.('rows') },
				help(__('Styles the rows that list releases, below the column headings. Enable striped rows to give every second release row a separate background color.', 'mds-release-manager')),
				region('body'),
				el(ToggleControl, { label: __('Striped rows', 'mds-release-manager'), checked: value.striped === true, onChange: (striped) => update({ striped }) }),
				value.striped === true && color('stripe-background', __('Alternate row color', 'mds-release-manager'))
			),
			el(PanelBody, { title: __('Cell grid and release links', 'mds-release-manager'), initialOpen: false, ...stylePanelProps?.('cells') },
				help(__('Cell padding and grid borders apply to each heading and data cell, not the table frame. Link color and underline apply to links inside the table, not the pagination links.', 'mds-release-manager')),
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
