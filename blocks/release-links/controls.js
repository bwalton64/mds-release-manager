(function (wp) {
	const { createElement: el, Fragment } = wp.element;
	const { InspectorControls } = wp.blockEditor;
	const { PanelBody, RangeControl } = wp.components;
	const { __ } = wp.i18n;
	const choices = (values) => values.map(([value, label]) => ({ value, label }));

	window.mdsRmReleaseLinksControls = function (props) {
		const { value, update, select, range, size, box, color, border, widthChoices, responsive, siteDefault } = window.mdsRmUseNativeReleaseStyles({ ...props, styleKey: 'linkStyles' });
		const { SectionInfo, ControlGroup } = window.mdsRmStylePrimitives;

		return el(props.standalone ? Fragment : InspectorControls, props.standalone ? null : { group: 'styles' },
			el(PanelBody, { title: __('Container & Layout', 'mds-release-manager'), initialOpen: false, ...props.stylePanelProps?.('container') },
				el(SectionInfo, {
					title: __('About Container & Layout', 'mds-release-manager'),
					summary: __('Controls the outer wrapper around both the streaming/Buy Now button group and the audio preview player slot. To adjust spacing between individual link buttons, use the Button Styling panel.', 'mds-release-manager'),
					inheritance: props.standalone
						? __('Applies site-wide to all Release Links blocks and automatic fallback templates unless overridden on an individual block.', 'mds-release-manager')
						: __('Overrides the site-wide Release Links defaults for this block only. Leave controls unset to inherit.', 'mds-release-manager'),
				}),
				el(ControlGroup, { title: __('Surface & Colors', 'mds-release-manager') },
					color('background', __('Container Background Color', 'mds-release-manager'))
				),
				el(ControlGroup, { title: __('Dimensions & Spacing', 'mds-release-manager'), twoCol: true },
					size('width', __('Container Width', 'mds-release-manager'), 1200, widthChoices),
					size('gap', __('Gap Between Links & Audio', 'mds-release-manager'), 100, [], __('Separates the link button group from the audio preview player slot.', 'mds-release-manager')),
					box('margin', __('Outer Margin', 'mds-release-manager')),
					box('padding', __('Inner Padding', 'mds-release-manager'))
				),
				el(ControlGroup, {
					title: __('Layout & Alignment', 'mds-release-manager'),
					description: __('Choose whether the button group and audio player sit side by side or stacked vertically, and set how they align.', 'mds-release-manager'),
					twoCol: true,
				},
					select('direction', __('Layout Direction', 'mds-release-manager'), choices([
						['row', __('Horizontal (Row)', 'mds-release-manager')],
						['column', __('Vertical (Column)', 'mds-release-manager')],
						['row-reverse', __('Reverse Horizontal', 'mds-release-manager')],
						['column-reverse', __('Reverse Vertical', 'mds-release-manager')],
					])),
					select('wrap', __('Line Wrapping', 'mds-release-manager'), choices([
						['wrap', __('Wrap to Multiple Lines', 'mds-release-manager')],
						['nowrap', __('Single Line (No Wrap)', 'mds-release-manager')],
						['wrap-reverse', __('Reverse Wrap', 'mds-release-manager')],
					])),
					select('align', __('Justify Items', 'mds-release-manager'), choices([
						['flex-start', __('Start / Left', 'mds-release-manager')],
						['center', __('Center', 'mds-release-manager')],
						['flex-end', __('End / Right', 'mds-release-manager')],
						['space-between', __('Space Between', 'mds-release-manager')],
					])),
					select('vertical_align', __('Align Items', 'mds-release-manager'), choices([
						['flex-start', __('Top / Start', 'mds-release-manager')],
						['center', __('Center', 'mds-release-manager')],
						['flex-end', __('Bottom / End', 'mds-release-manager')],
						['stretch', __('Stretch to Fill', 'mds-release-manager')],
					]))
				),
				border('')
			),
			el(PanelBody, { title: __('Button Styling', 'mds-release-manager'), initialOpen: false, ...props.stylePanelProps?.('buttons') },
				el(SectionInfo, {
					title: __('About Button Styling', 'mds-release-manager'),
					summary: __('Styles each individual streaming or Buy Now button, including button dimensions, icon size, colors, borders, and hover feedback.', 'mds-release-manager'),
					inheritance: !props.standalone && Object.prototype.hasOwnProperty.call(props.defaults || {}, 'button_background')
						? __('A site-wide Shared Button Background is active. Click "Ignore site default" under Shared Button Background to restore individual provider brand colors on this block.', 'mds-release-manager')
						: __('Leave Shared Button Background unset to preserve each provider’s configured brand color.', 'mds-release-manager'),
				}),
				el(ControlGroup, { title: __('Sizing & Spacing', 'mds-release-manager'), twoCol: true },
					el('div', { className: 'mds-style-field' },
						el(RangeControl, {
							label: __('Button Size (px)', 'mds-release-manager'),
							help: __('Minimum width and height for each square/pill link button (44px minimum for touch accessibility).', 'mds-release-manager'),
							min: 44,
							max: 120,
							allowReset: true,
							value: value.button_size == null ? undefined : parseFloat(value.button_size),
							onChange: (setting) => update('button_size', setting === undefined ? undefined : `${setting}px`),
						}),
						siteDefault('button_size')
					),
					range('icon_size', __('Icon Size (px)', 'mds-release-manager'), 80),
					size('link_gap', __('Gap Between Buttons', 'mds-release-manager'), 100)
				),
				el(ControlGroup, { title: __('Colors & Hover', 'mds-release-manager'), twoCol: true },
					color('button_color', __('Button Icon Color', 'mds-release-manager')),
					color('button_background', __('Shared Button Background', 'mds-release-manager'), __('Overrides individual provider brand colors with a uniform button fill.', 'mds-release-manager')),
					range('hover_opacity', __('Hover Opacity', 'mds-release-manager'), 1, '', 0.05, 0, true, __('Controls button transparency when a visitor hovers or focuses on a link.', 'mds-release-manager'))
				),
				border('button_')
			),
			el(PanelBody, { title: __('Audio Player Sizing', 'mds-release-manager'), initialOpen: false, ...props.stylePanelProps?.('audio') },
				el(SectionInfo, {
					title: __('About Audio Player Sizing', 'mds-release-manager'),
					summary: __('Controls how wide the audio preview player slot renders inside the Release Links container. Does not resize streaming buttons.', 'mds-release-manager'),
					inheritance: __('Active player selection, player skins, and height parameters are managed in the Audio Player Manager tab.', 'mds-release-manager'),
				}),
				el(ControlGroup, { title: __('Player Slot Width', 'mds-release-manager') },
					size('audio_width', __('Audio Player Width', 'mds-release-manager'), 1200, widthChoices, __('Choose Auto, Full Width (100%), a theme spacing token, or a custom pixel width.', 'mds-release-manager'))
				)
			),
			responsive
		);
	};
})(window.wp);
