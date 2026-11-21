(function (wp) {
	const { createElement: el, useState } = wp.element;
	const { BlockEditorProvider, useSettings } = wp.blockEditor;
	const { BoxControl, Button, PanelBody, RangeControl, SelectControl, TextControl, ToggleControl } = wp.components;
	const { __, sprintf } = wp.i18n;
	const config = window.mdsRmDefaultsSettings;
	const input = document.getElementById('mds-rm-block-defaults');
	let all = Array.isArray(config.defaults) ? {} : { ...config.defaults };
	const emptyBlocks = [];

	function presetChoices(list, prefix) {
		const entries = Array.isArray(list) ? list : ['default', 'theme', 'custom'].flatMap((origin) => list?.[origin] || []);
		return Array.from(new Map(entries.map(({ slug, name }) => [slug, { label: name, value: `var(--wp--preset--${prefix}--${slug})` }])).values());
	}

	function WrapperStyles({ value, onChange, panelProps }) {
		// translators: %s: Name of a style setting for the outer discography block.
		const wrapperLabel = (label) => sprintf(__('Outer block: %s', 'mds-release-manager'), label);
		const [palette, customColor, spacing, customSpacing, sizes, customSize, families, lineHeight] = useSettings(
			'color.palette', 'color.custom', 'spacing.spacingSizes', 'spacing.customSpacingSize',
			'typography.fontSizes', 'typography.customFontSize', 'typography.fontFamilies', 'typography.lineHeight'
		);
		function get(path) {
			return path.reduce((current, key) => current && current[key], value);
		}
		function set(path, setting) {
			function apply(current, index) {
				const result = { ...current };
				const key = path[index];
				if (index === path.length - 1) {
					if (setting === undefined || setting === '' || (typeof setting === 'object' && !Object.keys(setting).length)) delete result[key];
					else result[key] = setting;
				} else {
					const child = apply(result[key] || {}, index + 1);
					if (Object.keys(child).length) result[key] = child;
					else delete result[key];
				}
				return result;
			}
			const next = apply(value, 0);
			onChange(Object.keys(next).length ? next : undefined);
		}
		function select(path, label, options) {
			const current = get(path);
			return el(SelectControl, {
				label: wrapperLabel(label), value: options.some((option) => option.value === current) ? current : '',
				options: [{ label: __('Default / inherit', 'mds-release-manager'), value: '' }, ...options],
				onChange: (setting) => set(path, setting),
			});
		}
		function color(path, label) {
			const current = get(path);
			return el(wp.element.Fragment, null,
				// translators: %s: Name of the color setting.
				select(path, sprintf(__('%s preset', 'mds-release-manager'), label), presetChoices(palette, 'color')),
				customColor !== false && el(window.mdsRmColorControl, {
					label: wrapperLabel(label), value: current,
					onChange: (setting) => set(path, setting),
				})
			);
		}
		function range(path, label, min, max, step = 1, unit = 'px') {
			const current = get(path);
			return el(RangeControl, {
				label: wrapperLabel(label), min, max, step, allowReset: true,
				value: typeof current === 'string' && !current.startsWith('var(') ? parseFloat(current) : undefined,
				onChange: (setting) => set(path, setting === undefined ? undefined : `${setting}${unit}`),
			});
		}
		function box(key, label) {
			const path = ['spacing', key];
			const current = get(path);
			const sides = ['top', 'right', 'bottom', 'left'];
			const values = current && typeof current === 'object' ? current : Object.fromEntries(sides.map((side) => [side, current || '']));
			return el(wp.element.Fragment, null,
				// translators: %s: Name of the spacing setting.
				select(path, sprintf(__('%s preset', 'mds-release-manager'), label), presetChoices(spacing, 'spacing')),
				customSpacing !== false && el(BoxControl, {
					label: wrapperLabel(label), values,
					onChange: (next) => set(path, Object.fromEntries(sides.filter((side) => next?.[side]).map((side) => [side, next[side]]))),
				})
			);
		}
		return el(PanelBody, { title: __('Outer discography block', 'mds-release-manager'), ...panelProps },
			el('p', { className: 'description' }, __('Styles the outer box that contains the table and any pagination links. Use its padding to add space between the box edge and its contents. The table, column headings, and release rows can set their own colors and typography; table link settings can override the outer link color.', 'mds-release-manager')),
			box('margin', __('Margin', 'mds-release-manager')),
			box('padding', __('Padding', 'mds-release-manager')),
			color(['color', 'background'], __('Background color', 'mds-release-manager')),
			color(['color', 'text'], __('Text color', 'mds-release-manager')),
			color(['elements', 'link', 'color', 'text'], __('Link color', 'mds-release-manager')),
			select(['typography', 'fontFamily'], __('Font family', 'mds-release-manager'), presetChoices(families, 'font-family')),
			select(['typography', 'fontSize'], __('Font size preset', 'mds-release-manager'), presetChoices(sizes, 'font-size')),
			customSize !== false && range(['typography', 'fontSize'], __('Custom font size (px)', 'mds-release-manager'), 8, 96),
			select(['typography', 'fontWeight'], __('Font weight', 'mds-release-manager'), [
				{ label: __('Normal', 'mds-release-manager'), value: '400' }, { label: __('Medium', 'mds-release-manager'), value: '500' },
				{ label: __('Semibold', 'mds-release-manager'), value: '600' }, { label: __('Bold', 'mds-release-manager'), value: '700' },
			]),
			select(['typography', 'fontStyle'], __('Font style', 'mds-release-manager'), [
				{ label: __('Normal', 'mds-release-manager'), value: 'normal' }, { label: __('Italic', 'mds-release-manager'), value: 'italic' },
			]),
			lineHeight !== false && range(['typography', 'lineHeight'], __('Line height', 'mds-release-manager'), 0.8, 3, 0.1, ''),
			range(['typography', 'letterSpacing'], __('Letter spacing (px)', 'mds-release-manager'), -2, 10, 0.1),
			select(['border', 'style'], __('Border style', 'mds-release-manager'), [
				{ label: __('None', 'mds-release-manager'), value: 'none' }, { label: __('Solid', 'mds-release-manager'), value: 'solid' },
				{ label: __('Dashed', 'mds-release-manager'), value: 'dashed' }, { label: __('Dotted', 'mds-release-manager'), value: 'dotted' },
				{ label: __('Double', 'mds-release-manager'), value: 'double' },
			]),
			range(['border', 'width'], __('Border width (px)', 'mds-release-manager'), 0, 16),
			color(['border', 'color'], __('Border color', 'mds-release-manager')),
			range(['border', 'radius'], __('Corner radius (px)', 'mds-release-manager'), 0, 48),
			!!Object.keys(value).length && el(Button, { variant: 'tertiary', onClick: () => onChange(undefined) }, __('Reset wrapper styles', 'mds-release-manager'))
		);
	}

	function Items({ slug, value, onChange, panelProps }) {
		const isMeta = slug === 'release-meta';
		const key = isMeta ? 'field' : 'name';
		const fallback = isMeta ? config.metaItems : config.providers.map((provider) => ({ name: provider.name, enabled: true })).concat([{ name: '__buy_now', enabled: true }]);
		const items = value.items || fallback;
		function update(index, change) {
			onChange({ items: items.map((item, i) => i === index ? { ...item, ...change } : item) });
		}
		function move(index, direction) {
			const target = index + direction;
			if (target < 0 || target >= items.length) return;
			const next = items.slice();
			[next[index], next[target]] = [next[target], next[index]];
			onChange({ items: next });
		}
		return el(PanelBody, { title: isMeta ? __('Metadata fields: visibility, order, and icons', 'mds-release-manager') : __('Link buttons: visibility, order, and icons', 'mds-release-manager'), ...panelProps },
			el('p', { className: 'description' }, isMeta
				? __('Choose which metadata fields can appear and in what order. Each shown field still needs a value on the release. Use the field’s icon and tooltip controls to customize that entry only.', 'mds-release-manager')
				: __('Choose which streaming services and Buy Now buttons can appear, in what order, and with which icons. Buttons still need URLs on the release. This panel does not style the audio player or the button backgrounds.', 'mds-release-manager')),
			items.map((item, index) => {
				const name = item[key];
				const label = isMeta ? config.metaFields[name]?.label : name === '__buy_now' ? __('Buy Now', 'mds-release-manager') : name;
				if (!label) return null;
				return el(PanelBody, { key: name, title: label, initialOpen: false },
					el(ToggleControl, { label: isMeta ? __('Show field', 'mds-release-manager') : __('Show link', 'mds-release-manager'), checked: item.enabled !== false, onChange: (enabled) => update(index, { enabled }) }),
					el(TextControl, { label: __('Icon CSS classes', 'mds-release-manager'), help: __('Enter icon classes such as fas fa-link.', 'mds-release-manager'), value: item.icon || '', onChange: (icon) => update(index, { icon }) }),
					el(TextControl, {
						label: isMeta ? __('Field tooltip', 'mds-release-manager') : __('Link name and tooltip', 'mds-release-manager'),
						help: isMeta ? __('Changes the hover text, not the displayed field value.', 'mds-release-manager')
							: __('Changes the hover text and the name read by screen readers, not the destination URL.', 'mds-release-manager'),
						value: item.title || '',
						onChange: (title) => update(index, { title }),
					}),
					el(Button, { variant: 'secondary', disabled: index === 0, onClick: () => move(index, -1), 'aria-label': sprintf(__('Move %s up', 'mds-release-manager'), label) }, __('Move up', 'mds-release-manager')),
					' ',
					el(Button, { variant: 'secondary', disabled: index === items.length - 1, onClick: () => move(index, 1), 'aria-label': sprintf(__('Move %s down', 'mds-release-manager'), label) }, __('Move down', 'mds-release-manager'))
				);
			}),
			value.items && el(Button, { variant: 'tertiary', onClick: () => onChange({ items: undefined }) }, __('Restore built-in field defaults', 'mds-release-manager'))
		);
	}

	function Defaults({ slug }) {
		const [value, setValue] = useState(all[slug] || {});
		const [openPanel, setOpenPanel] = useState(null);
		function stylePanelProps(id) {
			return {
				opened: openPanel === id,
				onToggle: () => setOpenPanel((current) => current === id ? null : id),
			};
		}
		function change(patch) {
			const next = { ...value, ...patch };
			Object.keys(next).forEach((key) => { if (next[key] === undefined) delete next[key]; });
			all = { ...all, [slug]: next };
			input.value = JSON.stringify(all);
			input.dispatchEvent(new Event('change', { bubbles: true }));
			setValue(next);
		}
		const isMeta = slug === 'release-meta';
		const isLinks = slug === 'release-links';
		return el(BlockEditorProvider, { settings: config.editorSettings, value: emptyBlocks, onInput: () => {}, onChange: () => {} },
			isMeta || isLinks ? el(Items, { slug, value, onChange: change, panelProps: stylePanelProps('items') }) : null,
			isLinks && el(PanelBody, { title: __('Link and audio behavior', 'mds-release-manager'), ...stylePanelProps('behavior') },
				el('p', { className: 'description' }, __('Controls what happens when visitors follow a streaming or Buy Now link and whether an available audio preview appears. To resize the player, use Audio preview player.', 'mds-release-manager')),
				el(ToggleControl, { label: __('Open links in a new tab', 'mds-release-manager'), checked: value.newTab !== false, onChange: (newTab) => change({ newTab }) }),
				el(ToggleControl, { label: __('Show audio player', 'mds-release-manager'), checked: value.showAudio !== false, onChange: (showAudio) => change({ showAudio }) })
			),
			isMeta && el(window.mdsRmReleaseMetaControls, { standalone: true, stylePanelProps, attributes: value, setAttributes: change }),
			isLinks && el(window.mdsRmReleaseLinksControls, { standalone: true, stylePanelProps, attributes: value, setAttributes: change }),
			isMeta && value.metaStyles && el(Button, { variant: 'tertiary', onClick: () => change({ metaStyles: undefined }) }, __('Reset Release Meta styles', 'mds-release-manager')),
			isLinks && value.linkStyles && el(Button, { variant: 'tertiary', onClick: () => change({ linkStyles: undefined }) }, __('Reset Release Links styles', 'mds-release-manager')),
			!isMeta && !isLinks && el(WrapperStyles, { panelProps: stylePanelProps('outer'), value: value.style || {}, onChange: (style) => change({ style }) }),
			!isMeta && !isLinks && el(window.mdsRmDiscographyTableControls, { standalone: true, stylePanelProps, value: value.tableStyles || {}, onChange: (tableStyles) => change({ tableStyles }) })
		);
	}

	['release-meta', 'release-links', 'artist-discography'].forEach((slug) => {
		const target = document.getElementById(`mds-defaults-${slug}`);
		if (!target) return;
		const element = el(Defaults, { slug });
		if (wp.element.createRoot) wp.element.createRoot(target).render(element);
		else wp.element.render(element, target);
	});
})(window.wp);
