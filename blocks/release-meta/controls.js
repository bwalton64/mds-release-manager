(function (wp) {
	const { createElement: el, Fragment } = wp.element;
	const { InspectorControls, useSettings } = wp.blockEditor;
	const { PanelBody } = wp.components;
	const { __ } = wp.i18n;
	const choices = (values) => values.map(([value, label]) => ({ value, label }));

	window.mdsRmReleaseMetaControls = function (props) {
		const { select, range, size, box, color, border, widthChoices, responsive, presets } =
			window.mdsRmUseNativeReleaseStyles({ ...props, styleKey: 'metaStyles' });
		const { SectionInfo, ControlGroup } = window.mdsRmStylePrimitives;
		const [families, sizes, customSize, lineHeight, appearance, letterSpacing] = useSettings(
			'typography.fontFamilies', 'typography.fontSizes', 'typography.customFontSize',
			'typography.lineHeight', 'typography.fontStyle', 'typography.letterSpacing'
		);

		return el(props.standalone ? Fragment : InspectorControls, props.standalone ? null : { group: 'styles' },
			el(PanelBody, { title: __('Container & Layout', 'mds-release-manager'), initialOpen: false, ...props.stylePanelProps?.('container') },
				el(SectionInfo, {
					title: __('About Container & Layout', 'mds-release-manager'),
					summary: __('Controls the outer box surrounding all metadata fields (Release Year, Format, Label, Distributor, UPC/EAN, and Tags). Use Inner Padding for room inside the box, Outer Margin for space around it, and Gap Between Fields to separate individual metadata entries.', 'mds-release-manager'),
					inheritance: props.standalone
						? __('Applies site-wide to all Release Meta blocks and automatic fallback templates unless overridden on an individual block.', 'mds-release-manager')
						: __('Overrides the site-wide Release Meta defaults for this block only. Leave controls unset to inherit.', 'mds-release-manager'),
				}),
				el(ControlGroup, { title: __('Surface & Colors', 'mds-release-manager') },
					color('background', __('Container Background Color', 'mds-release-manager'))
				),
				el(ControlGroup, { title: __('Dimensions & Spacing', 'mds-release-manager'), twoCol: true },
					size('width', __('Container Width', 'mds-release-manager'), 1200, widthChoices),
					size('gap', __('Gap Between Fields', 'mds-release-manager'), 100, [], __('Space between separate metadata entries. To adjust space between an icon and its text, use the Icons panel.', 'mds-release-manager')),
					box('margin', __('Outer Margin', 'mds-release-manager')),
					box('padding', __('Inner Padding', 'mds-release-manager'))
				),
				el(ControlGroup, {
					title: __('Layout & Alignment', 'mds-release-manager'),
					description: __('Arrange metadata fields horizontally in a row or vertically in a column, and control how items justify and align.', 'mds-release-manager'),
					twoCol: true,
				},
					select('direction', __('Layout Direction', 'mds-release-manager'), choices([
						['row', __('Horizontal (Row)', 'mds-release-manager')],
						['column', __('Vertical (Column)', 'mds-release-manager')],
					])),
					select('wrap', __('Line Wrapping', 'mds-release-manager'), choices([
						['wrap', __('Wrap to Multiple Lines', 'mds-release-manager')],
						['nowrap', __('Single Line (No Wrap)', 'mds-release-manager')],
					])),
					select('align', __('Horizontal Alignment', 'mds-release-manager'), choices([
						['flex-start', __('Left / Start', 'mds-release-manager')],
						['center', __('Center', 'mds-release-manager')],
						['flex-end', __('Right / End', 'mds-release-manager')],
						['space-between', __('Spread Evenly', 'mds-release-manager')],
					])),
					select('vertical_align', __('Vertical Alignment', 'mds-release-manager'), choices([
						['flex-start', __('Top', 'mds-release-manager')],
						['center', __('Middle', 'mds-release-manager')],
						['flex-end', __('Bottom', 'mds-release-manager')],
						['stretch', __('Stretch to Fill', 'mds-release-manager')],
					]))
				),
				border('')
			),
			el(PanelBody, { title: __('Typography & Text', 'mds-release-manager'), initialOpen: false, ...props.stylePanelProps?.('typography') },
				el(SectionInfo, {
					title: __('About Typography & Text', 'mds-release-manager'),
					summary: __('Styles the displayed metadata values such as the record label, format, and release year. Icons automatically inherit this text color unless a separate Icon Color is set in the Icons panel.', 'mds-release-manager'),
					inheritance: __('Does not affect the page title or body text controlled by your active theme.', 'mds-release-manager'),
				}),
				el(ControlGroup, { title: __('Text Color', 'mds-release-manager') },
					color('color', __('Field Text Color', 'mds-release-manager'))
				),
				el(ControlGroup, { title: __('Font & Sizing', 'mds-release-manager'), twoCol: true },
					select('font_family', __('Font Family', 'mds-release-manager'), presets(families, 'font-family')),
					select('font_size', __('Font Size (Theme Preset)', 'mds-release-manager'), presets(sizes, 'font-size')),
					customSize !== false && range('font_size', __('Custom Font Size (px)', 'mds-release-manager'), 96, 'px', 1, 8),
					select('font_weight', __('Font Weight', 'mds-release-manager'), choices([
						['400', __('Normal (400)', 'mds-release-manager')],
						['500', __('Medium (500)', 'mds-release-manager')],
						['600', __('Semibold (600)', 'mds-release-manager')],
						['700', __('Bold (700)', 'mds-release-manager')],
					])),
					appearance !== false && select('font_style', __('Font Style', 'mds-release-manager'), choices([
						['normal', __('Normal', 'mds-release-manager')],
						['italic', __('Italic', 'mds-release-manager')],
					])),
					lineHeight !== false && range('line_height', __('Line Height', 'mds-release-manager'), 3, '', 0.1, 0.8),
					letterSpacing !== false && range('letter_spacing', __('Letter Spacing (px)', 'mds-release-manager'), 10, 'px', 0.1, -2),
					select('text_align', __('Text Alignment', 'mds-release-manager'), choices([
						['start', __('Left / Start', 'mds-release-manager')],
						['center', __('Center', 'mds-release-manager')],
						['end', __('Right / End', 'mds-release-manager')],
					]))
				)
			),
			el(PanelBody, { title: __('Icons', 'mds-release-manager'), initialOpen: false, ...props.stylePanelProps?.('icons') },
				el(SectionInfo, {
					title: __('About Metadata Icons', 'mds-release-manager'),
					summary: __('Controls the color, size, opacity, and spacing of the icon displayed beside each metadata value.', 'mds-release-manager'),
					inheritance: props.standalone
						? __('To choose which icon appears for each individual field, expand that field under Fields & Ordering above.', 'mds-release-manager')
						: __('To choose a different icon for a specific field, open that field’s panel in the block’s Settings tab.', 'mds-release-manager'),
				}),
				el(ControlGroup, { title: __('Icon Appearance', 'mds-release-manager'), twoCol: true },
					color('icon_color', __('Icon Color', 'mds-release-manager'), __('Leave empty to inherit the Field Text Color.', 'mds-release-manager')),
					range('icon_size', __('Icon Size (px)', 'mds-release-manager'), 80, 'px', 1, 8),
					range('icon_opacity', __('Icon Opacity', 'mds-release-manager'), 1, '', 0.05),
					size('icon_gap', __('Gap Between Icon & Text', 'mds-release-manager'), 100, [], __('Space between the icon and its label inside a single metadata field.', 'mds-release-manager'))
				)
			),
			responsive
		);
	};
})(window.wp);
