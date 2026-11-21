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

	window.mdsRmUseNativeReleaseStyles = function ({ attributes, setAttributes, styleKey, defaults = {} }) {
		const [palette, customColor, spacing, customSpacing] = useSettings('color.palette', 'color.custom', 'spacing.spacingSizes', 'spacing.customSpacingSize');
		const own = attributes[styleKey] || {};
		const legacy = attributes.styles || {};
		const inheritedDefaults = { ...defaults };
		Object.keys(legacy.desktop || {}).forEach((key) => {
			if (!Object.prototype.hasOwnProperty.call(own, key)) delete inheritedDefaults[key];
		});
		const value = { ...inheritedDefaults, ...own };
		Object.keys(own).forEach((key) => {
			if (own[key] && typeof own[key] === 'object' && inheritedDefaults[key]) {
				const inherited = typeof inheritedDefaults[key] === 'object' ? inheritedDefaults[key] : Object.fromEntries(sides.map((side) => [side, inheritedDefaults[key]]));
				value[key] = { ...inherited, ...own[key] };
			}
		});
		const inherited = __('Default / legacy', 'mds-release-manager');
		const spacePresets = presets(spacing, 'spacing');
		function update(key, setting) {
			const next = { ...own };
			if (setting === '' || setting === undefined) delete next[key];
			else next[key] = setting;
			setAttributes({ [styleKey]: Object.keys(next).length ? next : undefined });
		}
		function siteDefault(key) {
			if (!Object.prototype.hasOwnProperty.call(inheritedDefaults, key)) return null;
			return el(Button, {
				variant: 'link',
				onClick: () => update(key, own[key] === null ? undefined : null),
			}, own[key] === null ? __('Use site default', 'mds-release-manager') : __('Ignore site default', 'mds-release-manager'));
		}
		function select(key, label, options, showSiteDefault = true) {
			return el(Fragment, null, el(SelectControl, {
				label, options: [{ label: inherited, value: '' }, ...options],
				value: options.some((option) => option.value === value[key]) ? value[key] : '',
				onChange: (setting) => update(key, setting),
			}), showSiteDefault && siteDefault(key));
		}
		function range(key, label, max, unit = 'px', step = 1, min = 0, showSiteDefault = true) {
			const current = value[key];
			const matches = current !== undefined && String(current).endsWith(unit) && Number.isFinite(parseFloat(current));
			return el(Fragment, null, el(RangeControl, {
				label, min, max, step, allowReset: true,
				value: matches ? parseFloat(current) : undefined,
				onChange: (setting) => update(key, setting === undefined ? undefined : `${setting}${unit}`),
			}), showSiteDefault && siteDefault(key));
		}
		function size(key, label, max, extra = []) {
			return el(Fragment, null,
				!!(spacePresets.length || extra.length) && select(key, label, [...extra, ...spacePresets], false),
				customSpacing !== false && range(key, label, max, 'px', 1, 0, false),
				siteDefault(key)
			);
		}
		function box(key, label) {
			const current = value[key];
			const values = current && typeof current === 'object' ? current : Object.fromEntries(sides.map((side) => [side, current || '']));
			return el(Fragment, null,
				!!spacePresets.length && select(key, label, spacePresets, false),
				customSpacing !== false && el(BoxControl, {
					label, values, onChange: (next) => {
						const overrides = own[key] && typeof own[key] === 'object' ? { ...own[key] }
							: own[key] ? Object.fromEntries(sides.map((side) => [side, own[key]])) : {};
						sides.forEach((side) => {
							if ((next?.[side] || '') === (values[side] || '')) return;
							if (next?.[side]) overrides[side] = next[side];
							else delete overrides[side];
						});
						update(key, Object.keys(overrides).length ? overrides : undefined);
					},
				}),
				siteDefault(key)
			);
		}
		function color(key, label) {
			const options = presets(palette, 'color');
			return el(Fragment, null,
				!!options.length && select(key, label, options, false),
				customColor !== false && el(ColorPalette, {
					colors: [], clearable: true, 'aria-label': label,
					value: typeof value[key] === 'string' && !value[key].startsWith('var(') ? value[key] : undefined,
					onChange: (setting) => update(key, setting),
				}),
				siteDefault(key)
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
		return { value, update, select, range, size, box, color, border, widthChoices, axis, responsive, presets, siteDefault };
	};
})(window.wp);
