(function (wp) {
	const { createElement: el, Fragment } = wp.element;
	const { useSettings } = wp.blockEditor;
	const { BoxControl, Button, ColorPalette, Notice, PanelBody, RangeControl, SelectControl } = wp.components;
	const { __ } = wp.i18n;
	const sides = ['top', 'right', 'bottom', 'left'];

	function presets(list, type) {
		const entries = Array.isArray(list) ? list : ['default', 'theme', 'custom'].flatMap((origin) => list?.[origin] || []);
		return Array.from(new Map(entries.map(({ slug, name }) => [slug, {
			label: name, value: `var(--wp--preset--${type}--${slug})`,
		}])).values());
	}

	window.mdsRmUseNativeReleaseStyles = function ({ attributes, setAttributes, styleKey }) {
		const [palette, customColor, spacing, customSpacing] = useSettings('color.palette', 'color.custom', 'spacing.spacingSizes', 'spacing.customSpacingSize');
		const value = attributes[styleKey] || {};
		const legacy = attributes.styles || {};
		const inherited = __('Default / legacy', 'mds-release-manager');
		const spacePresets = presets(spacing, 'spacing');
		function update(key, setting) {
			const next = { ...value };
			if (setting === '' || setting === undefined) delete next[key];
			else next[key] = setting;
			setAttributes({ [styleKey]: Object.keys(next).length ? next : undefined });
		}
		function select(key, label, options) {
			return el(SelectControl, {
				label, options: [{ label: inherited, value: '' }, ...options],
				value: options.some((option) => option.value === value[key]) ? value[key] : '',
				onChange: (setting) => update(key, setting),
			});
		}
		function range(key, label, max, unit = 'px', step = 1, min = 0) {
			const current = value[key];
			const matches = current !== undefined && String(current).endsWith(unit) && Number.isFinite(parseFloat(current));
			return el(RangeControl, {
				label, min, max, step, allowReset: true,
				value: matches ? parseFloat(current) : undefined,
				onChange: (setting) => update(key, setting === undefined ? undefined : `${setting}${unit}`),
			});
		}
		function size(key, label, max, extra = []) {
			return el(Fragment, null,
				!!(spacePresets.length || extra.length) && select(key, label, [...extra, ...spacePresets]),
				customSpacing !== false && range(key, label, max)
			);
		}
		function box(key, label) {
			const current = value[key];
			const values = current && typeof current === 'object' ? current : Object.fromEntries(sides.map((side) => [side, current || '']));
			return el(Fragment, null,
				!!spacePresets.length && select(key, label, spacePresets),
				customSpacing !== false && el(BoxControl, {
					label, values, onChange: (next) => update(key, next && sides.some((side) => next[side]) ? next : undefined),
				})
			);
		}
		function color(key, label) {
			const options = presets(palette, 'color');
			return el(Fragment, null,
				!!options.length && select(key, label, options),
				customColor !== false && el(ColorPalette, {
					colors: [], clearable: true, 'aria-label': label,
					value: typeof value[key] === 'string' && !value[key].startsWith('var(') ? value[key] : undefined,
					onChange: (setting) => update(key, setting),
				})
			);
		}
		function border(prefix) {
			return el(Fragment, null,
				box(`${prefix}border_width`, __('Border width', 'mds-release-manager')),
				select(`${prefix}border_style`, __('Border style', 'mds-release-manager'), [
					{ value: 'none', label: __('None', 'mds-release-manager') },
					{ value: 'solid', label: __('Solid', 'mds-release-manager') },
					{ value: 'dashed', label: __('Dashed', 'mds-release-manager') },
					{ value: 'dotted', label: __('Dotted', 'mds-release-manager') },
					{ value: 'double', label: __('Double', 'mds-release-manager') },
				]),
				color(`${prefix}border_color`, __('Border color', 'mds-release-manager')),
				box(prefix ? 'button_radius' : 'radius', __('Corner radius', 'mds-release-manager'))
			);
		}
		const widthChoices = [
			{ value: 'auto', label: __('Auto', 'mds-release-manager') },
			{ value: '100%', label: __('Full width', 'mds-release-manager') },
		];
		const axis = [
			{ value: 'flex-start', label: __('Start', 'mds-release-manager') },
			{ value: 'center', label: __('Center', 'mds-release-manager') },
			{ value: 'flex-end', label: __('End', 'mds-release-manager') },
		];
		const hasResponsive = ['tablet', 'mobile'].some((device) => Object.keys(legacy[device] || {}).length);
		const responsive = hasResponsive && el(PanelBody, { title: __('Legacy responsive styles', 'mds-release-manager'), initialOpen: false },
				el(Notice, { status: 'info', isDismissible: false }, __('Saved tablet and mobile overrides still apply at their original widths. Use Gutenberg’s device preview to check them.', 'mds-release-manager')),
				el(Button, { variant: 'secondary', onClick: () => {
					const { tablet, mobile, ...rest } = legacy;
					setAttributes({ styles: rest });
				} }, __('Remove legacy tablet/mobile overrides', 'mds-release-manager'))
			);
		return { value, update, select, range, size, box, color, border, widthChoices, axis, responsive, presets };
	};
})(window.wp);
