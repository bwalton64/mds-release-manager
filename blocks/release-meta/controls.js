(function (wp) {
	const { createElement: el, Fragment } = wp.element;
	const { InspectorControls, useSettings } = wp.blockEditor;
	const { PanelBody } = wp.components;
	const { __ } = wp.i18n;
	const choices = (values) => values.map(([value, label]) => ({ value, label }));

	window.mdsRmReleaseMetaControls = function (props) {
		const { select, range, size, box, color, border, widthChoices, axis, responsive, presets } =
			window.mdsRmUseNativeReleaseStyles({ ...props, styleKey: 'metaStyles' });
		const [families, sizes, customSize, lineHeight, appearance, letterSpacing] = useSettings(
			'typography.fontFamilies', 'typography.fontSizes', 'typography.customFontSize',
			'typography.lineHeight', 'typography.fontStyle', 'typography.letterSpacing'
		);
		const help = (text) => el('p', { className: 'description' }, text);
		return el(props.standalone ? Fragment : InspectorControls, props.standalone ? null : { group: 'styles' },
			el(PanelBody, { title: __('Metadata container', 'mds-release-manager'), initialOpen: false, ...props.stylePanelProps?.('container') },
				help(__('This is the box around all metadata fields, such as the release year, label, and UPC/EAN. Padding adds room inside the box; margin adds room outside it; field gap separates the entries inside. Alignment follows the selected row or column direction.', 'mds-release-manager')),
				color('background', __('Metadata container background', 'mds-release-manager')),
				box('margin', __('Margin', 'mds-release-manager')),
				box('padding', __('Padding', 'mds-release-manager')),
				size('width', __('Container width', 'mds-release-manager'), 1200, widthChoices),
				size('gap', __('Gap between fields', 'mds-release-manager'), 100),
				select('align', __('Main-axis alignment', 'mds-release-manager'), [...axis, { value: 'space-between', label: __('Space between', 'mds-release-manager') }]),
				select('vertical_align', __('Cross-axis alignment', 'mds-release-manager'), [...axis, { value: 'stretch', label: __('Stretch', 'mds-release-manager') }]),
				select('direction', __('Direction', 'mds-release-manager'), choices([
					['row', __('Row', 'mds-release-manager')], ['row-reverse', __('Reverse row', 'mds-release-manager')],
					['column', __('Column', 'mds-release-manager')], ['column-reverse', __('Reverse column', 'mds-release-manager')],
				])),
				select('wrap', __('Wrapping', 'mds-release-manager'), choices([
					['wrap', __('Wrap', 'mds-release-manager')], ['nowrap', __('Do not wrap', 'mds-release-manager')],
					['wrap-reverse', __('Reverse wrap', 'mds-release-manager')],
				])),
				border('')
			),
			el(PanelBody, { title: __('Release Meta typography', 'mds-release-manager'), initialOpen: false, ...props.stylePanelProps?.('typography') },
				help(__('Styles the displayed metadata values, such as the record label name. Icons inherit the text color unless you set an icon color separately. This panel does not style the page title or body added by the theme.', 'mds-release-manager')),
				color('color', __('Metadata value text color', 'mds-release-manager')),
				select('font_family', __('Font family', 'mds-release-manager'), presets(families, 'font-family')),
				select('font_size', __('Font size preset', 'mds-release-manager'), presets(sizes, 'font-size')),
				customSize !== false && range('font_size', __('Custom font size (px)', 'mds-release-manager'), 96, 'px', 1, 8),
				select('font_weight', __('Font weight', 'mds-release-manager'), choices([
					['400', __('Normal', 'mds-release-manager')], ['500', __('Medium', 'mds-release-manager')],
					['600', __('Semibold', 'mds-release-manager')], ['700', __('Bold', 'mds-release-manager')],
				])),
				appearance !== false && select('font_style', __('Font style', 'mds-release-manager'), choices([
					['normal', __('Normal', 'mds-release-manager')], ['italic', __('Italic', 'mds-release-manager')],
				])),
				lineHeight !== false && range('line_height', __('Line height', 'mds-release-manager'), 3, '', 0.1, 0.8),
				letterSpacing !== false && range('letter_spacing', __('Letter spacing (px)', 'mds-release-manager'), 10, 'px', 0.1, -2),
				select('text_align', __('Text alignment', 'mds-release-manager'), choices([
					['start', __('Start', 'mds-release-manager')], ['center', __('Center', 'mds-release-manager')],
					['end', __('End', 'mds-release-manager')],
				]))
			),
			el(PanelBody, { title: __('Release Meta icons', 'mds-release-manager'), initialOpen: false, ...props.stylePanelProps?.('icons') },
				help(__('Styles the small icon beside each metadata value. The icon-to-text gap is inside a single field, not the space between fields.', 'mds-release-manager')),
				help(props.standalone
					? __('Choose which icon appears for each field in Metadata fields: visibility, order, and icons.', 'mds-release-manager')
					: __('To choose a different icon for a field, open that field’s panel in the block’s Settings tab.', 'mds-release-manager')),
				color('icon_color', __('Metadata field icon color', 'mds-release-manager')),
				range('icon_size', __('Icon size (px)', 'mds-release-manager'), 80, 'px', 1, 8),
				range('icon_opacity', __('Icon opacity', 'mds-release-manager'), 1, '', 0.05),
				size('icon_gap', __('Gap between icon and text', 'mds-release-manager'), 100)
			),
			responsive
		);
	};
})(window.wp);
