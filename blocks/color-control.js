(function (wp) {
	const { createElement: el } = wp.element;
	const { Button, ColorPicker, Dropdown } = wp.components;
	const { __, sprintf } = wp.i18n;

	function normalizePalette(palette) {
		if (!palette) return [];
		const list = Array.isArray(palette)
			? palette
			: ['theme', 'custom', 'default'].flatMap((origin) => (Array.isArray(palette[origin]) ? palette[origin] : []));
		const seen = new Map();
		list.forEach((entry) => {
			if (entry && entry.slug && entry.color && !seen.has(entry.slug)) {
				seen.set(entry.slug, {
					slug: entry.slug,
					name: entry.name || entry.slug,
					color: entry.color,
					varValue: `var(--wp--preset--color--${entry.slug})`,
				});
			}
		});
		return Array.from(seen.values());
	}

	window.mdsRmColorControl = function ({ label, value, onChange, palette, fallbackHint }) {
		const swatches = normalizePalette(palette);
		const isVar = typeof value === 'string' && value.startsWith('var(');
		const matchedSwatch = isVar ? swatches.find((s) => s.varValue === value) : null;
		const swatchBg = matchedSwatch ? matchedSwatch.color : (typeof value === 'string' && value ? value : '');
		const buttonText = matchedSwatch
			? matchedSwatch.name
			: (typeof value === 'string' && value ? value : (fallbackHint || __('Theme default', 'mds-release-manager')));
		const pickerHex = matchedSwatch ? matchedSwatch.color : (!isVar && typeof value === 'string' && value ? value : '#000000');

		return el(Dropdown, {
			renderToggle: ({ isOpen, onToggle }) => el(Button, {
				variant: 'secondary',
				className: 'mds-color-trigger',
				__next40pxDefaultSize: true,
				onClick: onToggle,
				'aria-expanded': isOpen,
				'aria-label': sprintf(__('Choose color for %s', 'mds-release-manager'), label),
			},
				el('span', {
					'aria-hidden': true,
					className: `mds-color-trigger__swatch${swatchBg ? '' : ' is-empty'}`,
					style: swatchBg ? { backgroundColor: swatchBg } : undefined,
				}),
				el('span', { className: 'mds-color-trigger__text' }, buttonText)
			),
			renderContent: () => el('div', { className: 'mds-color-popover' },
				!!swatches.length && el('div', { className: 'mds-color-popover__palette' },
					el('span', { className: 'mds-color-popover__heading' }, __('Theme Palette', 'mds-release-manager')),
					el('div', { className: 'mds-color-popover__swatches' },
						swatches.map((swatch) => el('button', {
							key: swatch.slug,
							type: 'button',
							className: `mds-color-popover__swatch${value === swatch.varValue ? ' is-selected' : ''}`,
							style: { backgroundColor: swatch.color },
							title: swatch.name,
							'aria-label': swatch.name,
							'aria-pressed': value === swatch.varValue,
							onClick: () => onChange(swatch.varValue),
						}))
					)
				),
				el(ColorPicker, { 'aria-label': label, color: pickerHex, enableAlpha: true, onChange }),
				el('div', { className: 'mds-color-popover__footer' },
					el(Button, { variant: 'tertiary', isDestructive: Boolean(value), disabled: !value, onClick: () => onChange(undefined) }, __('Reset to theme default', 'mds-release-manager'))
				)
			),
		});
	};
})(window.wp);
