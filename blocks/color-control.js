(function (wp) {
	const { createElement: el } = wp.element;
	const { Button, ColorPicker, Dropdown } = wp.components;
	const { __, sprintf } = wp.i18n;

	window.mdsRmColorControl = function ({ label, value, onChange }) {
		const custom = typeof value === 'string' && !value.startsWith('var(') ? value : '';
		return el(Dropdown, {
			renderToggle: ({ isOpen, onToggle }) => el(Button, {
				variant: 'secondary',
				__next40pxDefaultSize: true,
				onClick: onToggle,
				'aria-expanded': isOpen,
				'aria-label': sprintf(__('Choose custom color for %s', 'mds-release-manager'), label),
			}, custom && el('span', {
				'aria-hidden': true,
				style: { display: 'inline-block', width: '18px', height: '18px', marginRight: '8px', border: '1px solid currentColor', backgroundColor: custom },
			}), custom || __('Choose custom color', 'mds-release-manager')),
			renderContent: () => el('div', { style: { padding: '12px' } },
				el(ColorPicker, { 'aria-label': label, color: custom || '#000000', enableAlpha: true, onChange }),
				el(Button, { variant: 'secondary', onClick: () => onChange(undefined) }, __('Clear / use default', 'mds-release-manager'))
			),
		});
	};
})(window.wp);
