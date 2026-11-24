(function (wp) {
	const { createElement: el, Fragment, useState } = wp.element;
	const { useSettings } = wp.blockEditor;
	const { BoxControl, Button, Notice, PanelBody, RangeControl, SelectControl, ToggleControl } = wp.components;
	const { __, sprintf } = wp.i18n;
	const sides = ['top', 'right', 'bottom', 'left'];

	const ICON_PRESETS = [
		{ label: __('Choose a common icon…', 'mds-release-manager'), value: '' },
		{ label: __('No icon', 'mds-release-manager'), value: '__none' },
		{ label: __('Album (Dashicons)', 'mds-release-manager'), value: 'dashicons dashicons-album' },
		{ label: __('Calendar (Dashicons)', 'mds-release-manager'), value: 'dashicons dashicons-calendar-alt' },
		{ label: __('Artist (Dashicons)', 'mds-release-manager'), value: 'dashicons dashicons-groups' },
		{ label: __('Tag (Dashicons)', 'mds-release-manager'), value: 'dashicons dashicons-tag' },
		{ label: __('Star (Dashicons)', 'mds-release-manager'), value: 'dashicons dashicons-star-filled' },
		{ label: __('External Link (Dashicons)', 'mds-release-manager'), value: 'dashicons dashicons-external' },
		{ label: __('Cart (Dashicons)', 'mds-release-manager'), value: 'dashicons dashicons-cart' },
		{ label: __('Calendar (Font Awesome)', 'mds-release-manager'), value: 'fas fa-calendar' },
		{ label: __('Disc (Font Awesome)', 'mds-release-manager'), value: 'fas fa-compact-disc' },
		{ label: __('Distributor (Font Awesome)', 'mds-release-manager'), value: 'fas fa-tower-broadcast' },
		{ label: __('Barcode (Font Awesome)', 'mds-release-manager'), value: 'fas fa-barcode' },
		{ label: __('Music (Font Awesome)', 'mds-release-manager'), value: 'fas fa-music' },
		{ label: __('Link (Font Awesome)', 'mds-release-manager'), value: 'fas fa-link' },
		{ label: __('Shopping Bag (Font Awesome)', 'mds-release-manager'), value: 'fas fa-shopping-bag' },
		{ label: __('Spotify (Font Awesome)', 'mds-release-manager'), value: 'fab fa-spotify' },
		{ label: __('Apple (Font Awesome)', 'mds-release-manager'), value: 'fab fa-apple' },
		{ label: __('Bandcamp (Font Awesome)', 'mds-release-manager'), value: 'fab fa-bandcamp' },
		{ label: __('YouTube (Font Awesome)', 'mds-release-manager'), value: 'fab fa-youtube' },
		{ label: __('Amazon (Font Awesome)', 'mds-release-manager'), value: 'fab fa-amazon' },
		{ label: __('SoundCloud (Font Awesome)', 'mds-release-manager'), value: 'fab fa-soundcloud' },
	];

	function SectionInfo({ title, summary, inheritance }) {
		return el('div', { className: 'mds-section-info', role: 'note' },
			el('span', { className: 'dashicons dashicons-info-outline mds-section-info__icon', 'aria-hidden': true }),
			el('div', { className: 'mds-section-info__body' },
				title && el('strong', { className: 'mds-section-info__title' }, title),
				summary && el('p', { className: 'mds-section-info__summary' }, summary),
				inheritance && el('p', { className: 'mds-section-info__inheritance' }, inheritance)
			)
		);
	}

	function ControlGroup({ title, description, twoCol = false, children }) {
		return el('div', { className: 'mds-control-group' },
			title && el('h4', { className: 'mds-control-group__title' }, title),
			description && el('p', { className: 'mds-control-group__description' }, description),
			el('div', { className: twoCol ? 'mds-control-group__fields mds-style-grid-2col' : 'mds-control-group__fields' }, children)
		);
	}

	function AccordionToolbar({ openCount, totalCount, onExpandAll, onCollapseAll }) {
		return el('div', { className: 'mds-accordion-toolbar' },
			el('span', { className: 'mds-accordion-toolbar__status' },
				// translators: %1$d: Number of open sections. %2$d: Total number of sections.
				sprintf(__('%1$d of %2$d sections open', 'mds-release-manager'), openCount, totalCount)
			),
			el('div', { className: 'mds-accordion-toolbar__actions' },
				el(Button, { variant: 'secondary', size: 'small', disabled: openCount === totalCount, onClick: onExpandAll }, __('Expand all', 'mds-release-manager')),
				el(Button, { variant: 'secondary', size: 'small', disabled: openCount === 0, onClick: onCollapseAll }, __('Collapse all', 'mds-release-manager'))
			)
		);
	}

	function RepeaterCard({
		icon,
		title,
		swatchColor,
		badge,
		enabled = true,
		onToggleEnabled,
		canMoveUp = false,
		canMoveDown = false,
		onMoveUp,
		onMoveDown,
		defaultOpen = false,
		children,
	}) {
		const [isOpen, setIsOpen] = useState(defaultOpen);
		const iconClasses = typeof icon === 'string' ? icon.trim() : '';
		return el('div', { className: `mds-repeater-card${isOpen ? ' is-open' : ''}${!enabled ? ' is-disabled' : ''}` },
			el('div', { className: 'mds-repeater-card__header' },
				el('div', { className: 'mds-repeater-card__identity' },
					swatchColor && el('span', {
						className: 'mds-repeater-card__swatch',
						'aria-hidden': true,
						style: { backgroundColor: swatchColor },
					}),
					iconClasses && el('span', { className: `mds-repeater-card__icon ${iconClasses}`, 'aria-hidden': true }),
					el('span', { className: 'mds-repeater-card__title' }, title),
					badge && el('span', { className: 'mds-repeater-card__badge' }, badge)
				),
				el('div', { className: 'mds-repeater-card__actions' },
					onToggleEnabled && el('div', { className: 'mds-repeater-card__toggle' },
						el(ToggleControl, {
							label: __('Show', 'mds-release-manager'),
							// translators: %s: Item name.
							'aria-label': sprintf(__('Show %s', 'mds-release-manager'), title),
							checked: Boolean(enabled),
							onChange: onToggleEnabled,
							__nextHasNoMarginBottom: true,
						})
					),
					onMoveUp && el(Button, {
						variant: 'tertiary',
						size: 'small',
						disabled: !canMoveUp,
						onClick: onMoveUp,
						// translators: %s: Item name.
						'aria-label': sprintf(__('Move %s up', 'mds-release-manager'), title),
						title: __('Move up', 'mds-release-manager'),
					}, '↑'),
					onMoveDown && el(Button, {
						variant: 'tertiary',
						size: 'small',
						disabled: !canMoveDown,
						onClick: onMoveDown,
						// translators: %s: Item name.
						'aria-label': sprintf(__('Move %s down', 'mds-release-manager'), title),
						title: __('Move down', 'mds-release-manager'),
					}, '↓'),
					el(Button, {
						variant: 'secondary',
						size: 'small',
						onClick: () => setIsOpen((prev) => !prev),
						'aria-expanded': isOpen,
					}, isOpen ? __('Done', 'mds-release-manager') : __('Customize', 'mds-release-manager'))
				)
			),
			isOpen && el('div', { className: 'mds-repeater-card__body' }, children)
		);
	}

	window.mdsRmStylePrimitives = {
		ICON_PRESETS,
		SectionInfo,
		ControlGroup,
		AccordionToolbar,
		RepeaterCard,
	};

	function presets(list, type) {
		const entries = Array.isArray(list) ? list : ['default', 'theme', 'custom'].flatMap((origin) => list?.[origin] || []);
		return Array.from(new Map(entries.map(({ slug, name }) => [slug, {
			label: name, value: `var(--wp--preset--${type}--${slug})`,
		}])).values());
	}

	window.mdsRmUseNativeReleaseStyles = function ({ attributes, setAttributes, styleKey, defaults = {}, stylePanelProps }) {
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
		const inherited = __('Theme / default', 'mds-release-manager');
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
				className: 'mds-site-default-btn',
				onClick: () => update(key, own[key] === null ? undefined : null),
			}, own[key] === null ? __('Use site default', 'mds-release-manager') : __('Ignore site default', 'mds-release-manager'));
		}
		function select(key, label, options, showSiteDefault = true, help = undefined) {
			return el('div', { className: 'mds-style-field' },
				el(SelectControl, {
					label,
					help,
					options: [{ label: inherited, value: '' }, ...options],
					value: options.some((option) => option.value === value[key]) ? value[key] : '',
					onChange: (setting) => update(key, setting),
					__next40pxDefaultSize: true,
					__nextHasNoMarginBottom: true,
				}),
				showSiteDefault && siteDefault(key)
			);
		}
		function range(key, label, max, unit = 'px', step = 1, min = 0, showSiteDefault = true, help = undefined) {
			const current = value[key];
			const matches = current !== undefined && String(current).endsWith(unit) && Number.isFinite(parseFloat(current));
			return el('div', { className: 'mds-style-field' },
				el(RangeControl, {
					label,
					help,
					min,
					max,
					step,
					allowReset: true,
					value: matches ? parseFloat(current) : undefined,
					onChange: (setting) => update(key, setting === undefined ? undefined : `${setting}${unit}`),
					__next40pxDefaultSize: true,
					__nextHasNoMarginBottom: true,
				}),
				showSiteDefault && siteDefault(key)
			);
		}
		function size(key, label, max, extra = [], help = undefined) {
			// translators: %s: Name of the spacing or width setting.
			const presetLabel = sprintf(__('%s (Theme Preset)', 'mds-release-manager'), label);
			// translators: %s: Name of the spacing or width setting.
			const customLabel = sprintf(__('%s (Custom px)', 'mds-release-manager'), label);
			return el('div', { className: 'mds-style-field mds-style-field--paired' },
				!!(spacePresets.length || extra.length) && select(key, presetLabel, [...extra, ...spacePresets], false),
				customSpacing !== false && range(key, customLabel, max, 'px', 1, 0, false, help),
				siteDefault(key)
			);
		}
		function box(key, label, help = undefined) {
			const current = value[key];
			const values = current && typeof current === 'object' ? current : Object.fromEntries(sides.map((side) => [side, current || '']));
			return el('div', { className: 'mds-style-field mds-style-field--paired' },
				// translators: %s: Name of the spacing setting.
				!!spacePresets.length && select(key, sprintf(__('%s (Theme Preset)', 'mds-release-manager'), label), spacePresets, false),
				customSpacing !== false && el(BoxControl, {
					label,
					values,
					__next40pxDefaultSize: true,
					onChange: (next) => {
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
				help && el('p', { className: 'mds-style-field__help' }, help),
				siteDefault(key)
			);
		}
		function color(key, label, help = undefined) {
			const options = presets(palette, 'color');
			return el('div', { className: 'mds-style-field mds-style-field--color' },
				// translators: %s: Name of the color setting.
				!!options.length && select(key, sprintf(__('%s (Theme Preset)', 'mds-release-manager'), label), options, false),
				!options.length && customColor !== false && el('span', { className: 'mds-style-field__label' }, label),
				customColor !== false && el(window.mdsRmColorControl, {
					label,
					value: value[key],
					onChange: (setting) => update(key, setting),
				}),
				help && el('p', { className: 'mds-style-field__help' }, help),
				siteDefault(key)
			);
		}
		function border(prefix) {
			return el(ControlGroup, {
				title: __('Border & Corners', 'mds-release-manager'),
				description: prefix
					? __('Customize the outline and rounded corners of each individual link button.', 'mds-release-manager')
					: __('Customize the outer frame border and rounded corners of the container box.', 'mds-release-manager'),
			},
				el('div', { className: 'mds-style-grid-2col' },
					select(`${prefix}border_style`, __('Border Style', 'mds-release-manager'), [
						{ value: 'none', label: __('None', 'mds-release-manager') },
						{ value: 'solid', label: __('Solid', 'mds-release-manager') },
						{ value: 'dashed', label: __('Dashed', 'mds-release-manager') },
						{ value: 'dotted', label: __('Dotted', 'mds-release-manager') },
						{ value: 'double', label: __('Double', 'mds-release-manager') },
					]),
					color(`${prefix}border_color`, __('Border Color', 'mds-release-manager'))
				),
				el('div', { className: 'mds-style-grid-2col' },
					box(`${prefix}border_width`, __('Border Width', 'mds-release-manager')),
					box(prefix ? 'button_radius' : 'radius', __('Corner Radius', 'mds-release-manager'))
				)
			);
		}
		const widthChoices = [
			{ value: 'auto', label: __('Auto (Fit Content)', 'mds-release-manager') },
			{ value: '100%', label: __('Full Width (100%)', 'mds-release-manager') },
		];
		const axis = [
			{ value: 'flex-start', label: __('Start', 'mds-release-manager') },
			{ value: 'center', label: __('Center', 'mds-release-manager') },
			{ value: 'flex-end', label: __('End', 'mds-release-manager') },
		];
		const hasResponsive = ['tablet', 'mobile'].some((device) => Object.keys(legacy[device] || {}).length);
		const responsive = hasResponsive && el(PanelBody, { title: __('Legacy Responsive Styles', 'mds-release-manager'), initialOpen: false, ...stylePanelProps?.('legacy-responsive') },
			el(Notice, { status: 'info', isDismissible: false }, __('Saved tablet and mobile overrides still apply at their original widths. Use Gutenberg’s device preview to check them.', 'mds-release-manager')),
			el(Button, {
				variant: 'secondary',
				onClick: () => {
					const { tablet, mobile, ...rest } = legacy;
					setAttributes({ styles: rest });
				},
			}, __('Remove legacy tablet/mobile overrides', 'mds-release-manager'))
		);
		return { value, update, select, range, size, box, color, border, widthChoices, axis, responsive, presets, siteDefault };
	};
})(window.wp);
