(function (wp) {
	const { createElement: el, Fragment } = wp.element;
	const { InspectorControls, useSettings } = wp.blockEditor;
	const { BoxControl, Button, PanelBody, RangeControl, SelectControl, ToggleControl } = wp.components;
	const { __, sprintf } = wp.i18n;
	const sides = ['top', 'right', 'bottom', 'left'];

	window.mdsRmDiscographyTableControls = function TableControls({ value: own, defaults = {}, onChange, standalone = false, stylePanelProps }) {
		const value = { ...defaults, ...own };
		const { SectionInfo, ControlGroup } = window.mdsRmStylePrimitives;
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
				className: 'mds-site-default-btn',
				onClick: () => update({ [key]: own[key] === null ? undefined : null }),
			}, own[key] === null ? __('Use site default', 'mds-release-manager') : __('Ignore site default', 'mds-release-manager'));
		}
		function select(key, label, options, showSiteDefault = true, help = undefined) {
			return el('div', { className: 'mds-style-field' },
				el(SelectControl, {
					label,
					help,
					value: value[key] || '',
					options: [{ label: __('Theme / default', 'mds-release-manager'), value: '' }, ...options],
					onChange: (setting) => update({ [key]: setting }),
					__next40pxDefaultSize: true,
					__nextHasNoMarginBottom: true,
				}),
				showSiteDefault && siteDefault(key)
			);
		}
		function presetChoices(list, prefix) {
			const presets = Array.isArray(list) ? list : ['default', 'theme', 'custom'].flatMap(
				(origin) => Array.isArray(list?.[origin]) ? list[origin] : []
			);
			return Array.from(new Map(presets.map(({ name, slug }) => [
				slug, { label: name, value: `var(--wp--preset--${prefix}--${slug})` },
			])).values());
		}
		function range(key, label, min, max, step = 1, unit = '', help = undefined) {
			return el('div', { className: 'mds-style-field' },
				el(RangeControl, {
					label,
					help,
					min,
					max,
					step,
					allowReset: true,
					value: value[key] !== undefined && value[key] !== null && !String(value[key]).startsWith('var(') ? parseFloat(value[key]) : undefined,
					onChange: (setting) => update({ [key]: setting === undefined ? undefined : `${setting}${unit}` }),
					__next40pxDefaultSize: true,
					__nextHasNoMarginBottom: true,
				}),
				siteDefault(key)
			);
		}
		function color(key, label, help = undefined) {
			const options = presetChoices(palette, 'color');
			return el('div', { className: 'mds-style-field mds-style-field--color' },
				// translators: %s: Name of the color setting.
				!!options.length && select(key, sprintf(__('%s (Theme Preset)', 'mds-release-manager'), label), options, false),
				!options.length && customColor !== false && el('span', { className: 'mds-style-field__label' }, label),
				customColor !== false && el(window.mdsRmColorControl, {
					label,
					value: value[key],
					onChange: (setting) => update({ [key]: setting }),
				}),
				help && el('p', { className: 'mds-style-field__help' }, help),
				siteDefault(key)
			);
		}
		function typographyGroup(prefix, includeAlign = true) {
			return el(ControlGroup, { title: includeAlign ? __('Typography & Alignment', 'mds-release-manager') : __('Typography', 'mds-release-manager'), twoCol: true },
				select(`${prefix}-font-family`, __('Font Family', 'mds-release-manager'), presetChoices(families, 'font-family')),
				select(`${prefix}-font-size`, __('Font Size (Theme Preset)', 'mds-release-manager'), presetChoices(sizes, 'font-size')),
				customSize !== false && range(`${prefix}-font-size`, __('Custom Font Size (px)', 'mds-release-manager'), 8, 96, 1, 'px'),
				select(`${prefix}-font-weight`, __('Font Weight', 'mds-release-manager'), [
					{ label: __('Normal (400)', 'mds-release-manager'), value: '400' },
					{ label: __('Medium (500)', 'mds-release-manager'), value: '500' },
					{ label: __('Semibold (600)', 'mds-release-manager'), value: '600' },
					{ label: __('Bold (700)', 'mds-release-manager'), value: '700' },
				]),
				select(`${prefix}-font-style`, __('Font Style', 'mds-release-manager'), [
					{ label: __('Normal', 'mds-release-manager'), value: 'normal' },
					{ label: __('Italic', 'mds-release-manager'), value: 'italic' },
				]),
				lineHeight !== false && range(`${prefix}-line-height`, __('Line Height', 'mds-release-manager'), 0.8, 3, 0.1),
				includeAlign && select(`${prefix}-text-align`, __('Text Alignment', 'mds-release-manager'), [
					{ label: __('Left / Start', 'mds-release-manager'), value: 'start' },
					{ label: __('Center', 'mds-release-manager'), value: 'center' },
					{ label: __('Right / End', 'mds-release-manager'), value: 'end' },
				])
			);
		}
		function spacingControl(prefix, label, help = undefined) {
			const presets = presetChoices(spacing, 'spacing');
			const sideValues = Object.fromEntries(sides.map((side) => [side, value[`${prefix}-${side}`] || '']));
			const uniformPreset = sides.every((side) => sideValues[side] && sideValues[side] === sideValues.top)
				&& presets.some((preset) => preset.value === sideValues.top)
				? sideValues.top
				: '';
			const hasSiteDefault = sides.some((side) => Object.prototype.hasOwnProperty.call(defaults, `${prefix}-${side}`));
			const isIgnoringDefault = hasSiteDefault && sides.every((side) => own[`${prefix}-${side}`] === null);

			return el('div', { className: 'mds-style-field mds-style-field--paired' },
				!!presets.length && el(SelectControl, {
					// translators: %s: Name of the spacing setting.
					label: sprintf(__('%s (Theme Preset)', 'mds-release-manager'), label),
					value: uniformPreset,
					options: [{ label: __('Custom / theme default', 'mds-release-manager'), value: '' }, ...presets],
					__next40pxDefaultSize: true,
					__nextHasNoMarginBottom: true,
					onChange: (setting) => {
						const patch = Object.fromEntries(sides.map((side) => [`${prefix}-${side}`, setting || undefined]));
						update(patch);
					},
				}),
				customSpacing !== false && BoxControl && el(BoxControl, {
					label,
					values: sideValues,
					inputProps: { min: 0 },
					__next40pxDefaultSize: true,
					onChange: (settings) => update(Object.fromEntries(sides.filter((side) =>
						(settings?.[side] || '') !== (value[`${prefix}-${side}`] || '')
					).map((side) => [`${prefix}-${side}`, settings?.[side]]))),
				}),
				help && el('p', { className: 'mds-style-field__help' }, help),
				hasSiteDefault && el(Button, {
					variant: 'link',
					className: 'mds-site-default-btn',
					onClick: () => update(Object.fromEntries(sides.map((side) => [`${prefix}-${side}`, isIgnoringDefault ? undefined : null]))),
				}, isIgnoringDefault ? __('Use site default', 'mds-release-manager') : __('Ignore site default', 'mds-release-manager'))
			);
		}
		function borderGroup(prefix, title, description, includeRadius = false) {
			return el(ControlGroup, { title, description, twoCol: true },
				select(`${prefix}-style`, __('Border Style', 'mds-release-manager'), [
					{ label: __('None', 'mds-release-manager'), value: 'none' },
					{ label: __('Solid', 'mds-release-manager'), value: 'solid' },
					{ label: __('Dashed', 'mds-release-manager'), value: 'dashed' },
					{ label: __('Dotted', 'mds-release-manager'), value: 'dotted' },
					{ label: __('Double', 'mds-release-manager'), value: 'double' },
				]),
				range(`${prefix}-width`, __('Border Width (px)', 'mds-release-manager'), 0, 16, 1, 'px'),
				color(`${prefix}-color`, __('Border Color', 'mds-release-manager')),
				includeRadius && range('table-radius', __('Corner Radius (px)', 'mds-release-manager'), 0, 48, 1, 'px')
			);
		}

		return el(standalone ? Fragment : InspectorControls, standalone ? null : { group: 'styles' },
			!standalone && el(PanelBody, { title: __('Block Container', 'mds-release-manager'), initialOpen: false },
				el(SectionInfo, {
					title: __('About Block Container', 'mds-release-manager'),
					summary: __('Use WordPress’s built-in Color, Typography, Dimensions, and Border controls in this Styles tab to style the outer discography wrapper and pagination.', 'mds-release-manager'),
					inheritance: __('The panels below target individual table layers (Wrapper, Column Headers, Table Rows, and Cells).', 'mds-release-manager'),
				})
			),
			el(PanelBody, { title: __('Table Wrapper & Base Typography', 'mds-release-manager'), initialOpen: false, ...stylePanelProps?.('table') },
				el(SectionInfo, {
					title: __('About Table Wrapper & Base Typography', 'mds-release-manager'),
					summary: __('Styles the scrolling table frame and sets base colors and typography inherited by the table. Column Headers and Table Rows can override these base values.', 'mds-release-manager'),
					inheritance: __('Table Padding adds space around the table inside its frame. To adjust spacing inside individual cells, use Cells, Grid Borders & Links.', 'mds-release-manager'),
				}),
				el(ControlGroup, { title: __('Wrapper Spacing', 'mds-release-manager'), twoCol: true },
					spacingControl('table-margin', __('Table Margin', 'mds-release-manager')),
					spacingControl('table-padding', __('Table Padding', 'mds-release-manager'))
				),
				el(ControlGroup, { title: __('Surface & Colors', 'mds-release-manager'), twoCol: true },
					color('table-background', __('Table Background Color', 'mds-release-manager')),
					color('table-color', __('Table Text Color', 'mds-release-manager'))
				),
				typographyGroup('table', false),
				borderGroup('table-border', __('Table Border & Corners', 'mds-release-manager'), __('Outline and corner rounding around the scrolling table frame.', 'mds-release-manager'), true)
			),
			el(PanelBody, { title: __('Column Headers', 'mds-release-manager'), initialOpen: false, ...stylePanelProps?.('header') },
				el(SectionInfo, {
					title: __('About Column Headers', 'mds-release-manager'),
					summary: __('Styles only the top header row containing column labels such as Title, UPC/EAN, Label, Distributor, and Release Year.', 'mds-release-manager'),
					inheritance: __('Overrides Table Wrapper base colors and typography for the header row only.', 'mds-release-manager'),
				}),
				el(ControlGroup, { title: __('Header Colors', 'mds-release-manager'), twoCol: true },
					color('header-background', __('Background Color', 'mds-release-manager')),
					color('header-color', __('Text Color', 'mds-release-manager'))
				),
				typographyGroup('header', true)
			),
			el(PanelBody, { title: __('Table Rows & Zebra Striping', 'mds-release-manager'), initialOpen: false, ...stylePanelProps?.('rows') },
				el(SectionInfo, {
					title: __('About Table Rows & Zebra Striping', 'mds-release-manager'),
					summary: __('Styles the data rows listing each release below the column headers. Enable Zebra Striping to apply a distinct background tint to alternating rows for easier scanning.', 'mds-release-manager'),
					inheritance: __('Overrides Table Wrapper base colors and typography for release rows.', 'mds-release-manager'),
				}),
				el(ControlGroup, { title: __('Row Colors & Striping', 'mds-release-manager'), twoCol: true },
					color('body-background', __('Row Background Color', 'mds-release-manager')),
					color('body-color', __('Row Text Color', 'mds-release-manager')),
					el('div', { className: 'mds-style-field' },
						el(ToggleControl, {
							label: __('Enable Zebra Striping', 'mds-release-manager'),
							help: __('Applies an alternating background color to every second release row.', 'mds-release-manager'),
							checked: value.striped === true,
							onChange: (striped) => update({ striped }),
						})
					),
					value.striped === true && color('stripe-background', __('Alternating Row Color', 'mds-release-manager'))
				),
				typographyGroup('body', true)
			),
			el(PanelBody, { title: __('Cells, Grid Borders & Links', 'mds-release-manager'), initialOpen: false, ...stylePanelProps?.('cells') },
				el(SectionInfo, {
					title: __('About Cells, Grid Borders & Links', 'mds-release-manager'),
					summary: __('Controls internal padding for each table cell, divider lines between cells, and the appearance of clickable release title links inside the table.', 'mds-release-manager'),
					inheritance: __('Table Link settings apply to release titles inside the table and do not alter pagination links.', 'mds-release-manager'),
				}),
				el(ControlGroup, { title: __('Cell Spacing', 'mds-release-manager') },
					spacingControl('cell-padding', __('Cell Padding', 'mds-release-manager'), __('Applies inside every header and data cell.', 'mds-release-manager'))
				),
				borderGroup('cell-border', __('Cell Grid Lines', 'mds-release-manager'), __('Interior divider lines separating table rows and columns.', 'mds-release-manager'), false),
				el(ControlGroup, { title: __('Release Title Links', 'mds-release-manager'), twoCol: true },
					color('link-color', __('Table Link Color', 'mds-release-manager')),
					select('link-decoration', __('Link Underline Style', 'mds-release-manager'), [
						{ label: __('Underline', 'mds-release-manager'), value: 'underline' },
						{ label: __('No Underline', 'mds-release-manager'), value: 'none' },
					])
				)
			),
			!!Object.keys(own).length && el('div', { className: 'mds-reset-row' },
				el(Button, { variant: 'tertiary', isDestructive: true, onClick: () => onChange(undefined) }, __('Reset discography table styles', 'mds-release-manager'))
			)
		);
	};
})(window.wp);
