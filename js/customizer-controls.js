(function (wp) {
	const { createElement: el, useEffect, useState } = wp.element;
	const { Button, ColorPicker, Dropdown, RadioControl, RangeControl } = wp.components;
	const { __, sprintf } = wp.i18n;

	function cssColor(color) {
		const match = /^#([0-9a-f]{6})([0-9a-f]{2})$/i.exec(color);
		if (!match) return color;
		const rgb = match[1].match(/../g).map((part) => parseInt(part, 16));
		const alpha = Math.round(parseInt(match[2], 16) / 255 * 10000) / 10000;
		return `rgba(${rgb.join(',')},${alpha})`;
	}

	function Control({ config, setting }) {
		const [value, setValue] = useState(setting.get());
		useEffect(() => {
			setting.bind(setValue);
			return () => setting.unbind(setValue);
		}, [setting]);
		function change(next) {
			setting.set(next);
		}
		if (config.type === 'color') {
			return el('div', null,
				el('span', { className: 'customize-control-title' }, config.label),
				!value && el('p', { className: 'description' }, __('Using default color', 'mds-release-manager')),
				el(Dropdown, {
					renderToggle: ({ isOpen, onToggle }) => el(Button, {
						variant: 'secondary', onClick: onToggle, 'aria-expanded': isOpen,
						'aria-label': sprintf(__('Choose color for %s', 'mds-release-manager'), config.label),
					}, value || __('Choose color', 'mds-release-manager')),
					renderContent: () => el('div', null,
						el(ColorPicker, { 'aria-label': config.label, color: value || '#000000', enableAlpha: true, onChange: (color) => change(cssColor(color)) }),
						el(Button, { variant: 'secondary', onClick: () => change('') }, __('Clear / use default', 'mds-release-manager'))
					),
				})
			);
		}
		if (config.type === 'radio') {
			return el(RadioControl, {
				label: config.label, selected: value,
				options: Object.entries(config.choices).map(([key, label]) => ({ label, value: key })),
				onChange: change,
			});
		}
		return el(RangeControl, {
			label: config.label, value: Number(value), onChange: change,
			min: Number(config.attrs.min), max: Number(config.attrs.max), step: Number(config.attrs.step),
			allowReset: true, resetFallbackValue: Number(config.default),
		});
	}

	wp.customize.bind('ready', function () {
		document.querySelectorAll('.mds-rm-customizer-control').forEach((target) => {
			const config = JSON.parse(target.dataset.config);
			const setting = wp.customize(config.id);
			if (!setting) {
				target.textContent = __('This setting is unavailable.', 'mds-release-manager');
				console.error('Missing Customizer setting:', config.id);
				return;
			}
			const component = el(Control, { config, setting });
			if (wp.element.createRoot) wp.element.createRoot(target).render(component);
			else wp.element.render(component, target);
		});
	});
})(window.wp);
