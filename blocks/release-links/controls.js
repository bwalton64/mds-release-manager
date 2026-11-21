(function (wp) {
	const { createElement: el, Fragment } = wp.element;
	const { InspectorControls } = wp.blockEditor;
	const { PanelBody, RangeControl } = wp.components;
	const { __ } = wp.i18n;

	window.mdsRmReleaseLinksControls = function (props) {
		const { value, update, select, range, size, box, color, border, widthChoices, axis, responsive, siteDefault } = window.mdsRmUseNativeReleaseStyles({ ...props, styleKey: 'linkStyles' });
		return el(props.standalone ? Fragment : InspectorControls, props.standalone ? null : { group: 'styles' },
			el(PanelBody, { title: __('Release Links layout', 'mds-release-manager'), initialOpen: false },
				color('background', __('Background color', 'mds-release-manager')),
				box('margin', __('Margin', 'mds-release-manager')),
				box('padding', __('Padding', 'mds-release-manager')),
				size('width', __('Container width', 'mds-release-manager'), 1200, widthChoices),
				size('gap', __('Gap between links and audio', 'mds-release-manager'), 100),
				select('align', __('Main-axis alignment', 'mds-release-manager'), [...axis, { value: 'space-between', label: __('Space between', 'mds-release-manager') }]),
				select('vertical_align', __('Cross-axis alignment', 'mds-release-manager'), [...axis, { value: 'stretch', label: __('Stretch', 'mds-release-manager') }]),
				select('direction', __('Direction', 'mds-release-manager'), [
					{ value: 'row', label: __('Row', 'mds-release-manager') },
					{ value: 'row-reverse', label: __('Reverse row', 'mds-release-manager') },
					{ value: 'column', label: __('Column', 'mds-release-manager') },
					{ value: 'column-reverse', label: __('Reverse column', 'mds-release-manager') },
				]),
				select('wrap', __('Wrapping', 'mds-release-manager'), [
					{ value: 'wrap', label: __('Wrap', 'mds-release-manager') },
					{ value: 'nowrap', label: __('Do not wrap', 'mds-release-manager') },
					{ value: 'wrap-reverse', label: __('Reverse wrap', 'mds-release-manager') },
				]),
				border('')
			),
			el(PanelBody, { title: __('Release Links buttons', 'mds-release-manager'), initialOpen: false },
				size('link_gap', __('Gap between buttons', 'mds-release-manager'), 100),
				el(RangeControl, {
					label: __('Button size (px)', 'mds-release-manager'), min: 44, max: 120, allowReset: true,
					value: value.button_size == null ? undefined : parseFloat(value.button_size),
					onChange: (setting) => update('button_size', setting === undefined ? undefined : `${setting}px`),
				}),
				siteDefault('button_size'),
				range('icon_size', __('Icon size (px)', 'mds-release-manager'), 80),
				color('button_color', __('Icon color', 'mds-release-manager')),
				color('button_background', __('Button background', 'mds-release-manager')),
				border('button_'),
				range('hover_opacity', __('Hover opacity', 'mds-release-manager'), 1, '', 0.05)
			),
			el(PanelBody, { title: __('Release Links audio', 'mds-release-manager'), initialOpen: false },
				size('audio_width', __('Audio player width', 'mds-release-manager'), 1200, widthChoices)
			),
			responsive
		);
	};
})(window.wp);
