(function (wp) {
	const { createElement: el, useState } = wp.element;
	const { BlockEditorProvider, useSettings } = wp.blockEditor;
	const { BoxControl, Button, Notice, PanelBody, RangeControl, SelectControl, TextControl, ToggleControl } = wp.components;
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

	function Items({ value, onChange, panelProps }) {
		const items = value.items || config.metaItems;
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
		return el(PanelBody, { title: __('Metadata fields: visibility, order, and icons', 'mds-release-manager'), ...panelProps },
			el('p', { className: 'description' }, __('Choose which metadata fields can appear and in what order. Each shown field still needs a value on the release. Use the field’s icon and tooltip controls to customize that entry only.', 'mds-release-manager')),
			items.map((item, index) => {
				const name = item.field;
				const label = config.metaFields[name]?.label;
				if (!label) return null;
				return el(PanelBody, { key: name, title: label, initialOpen: false },
					el(ToggleControl, { label: __('Show field', 'mds-release-manager'), checked: item.enabled !== false, onChange: (enabled) => update(index, { enabled }) }),
					el(TextControl, { label: __('Icon CSS classes', 'mds-release-manager'), help: __('Enter icon classes such as fas fa-link.', 'mds-release-manager'), value: item.icon || '', onChange: (icon) => update(index, { icon }) }),
					el(TextControl, {
						label: __('Field tooltip', 'mds-release-manager'),
						help: __('Changes the hover text, not the displayed field value.', 'mds-release-manager'),
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

	function LinkProviders({ value, onChange, providers, onProvidersChange, panelProps }) {
		const [newName, setNewName] = useState('');
		const fallback = providers.map((provider) => ({ name: provider.name, enabled: true })).concat([{ name: '__buy_now', enabled: true }]);
		const items = (value.items || fallback).slice();
		fallback.forEach((item) => {
			if (!items.some((existing) => existing.name.toLowerCase() === item.name.toLowerCase())) items.push(item);
		});
		const name = newName.trim();
		const invalidName = name.length > 200 || /[<>\u0000-\u001f]|%[0-9a-f]{2}/i.test(name)
			|| name.toLowerCase() === '__buy_now' || providers.some((provider) => provider.name.toLowerCase() === name.toLowerCase());
		function updateItem(index, patch) {
			onChange({ items: items.map((item, i) => i === index ? { ...item, ...patch } : item) });
		}
		function removeIconOverride(index) {
			onChange({ items: items.map((item, i) => {
				if (i !== index) return item;
				const next = { ...item };
				delete next.icon;
				return next;
			}) });
		}
		function updateProvider(index, patch) {
			onProvidersChange(providers.map((provider, i) => i === index ? { ...provider, ...patch } : provider));
		}
		function move(index, direction) {
			const target = index + direction;
			if (target < 0 || target >= items.length) return;
			const next = items.slice();
			[next[index], next[target]] = [next[target], next[index]];
			onChange({ items: next });
		}
		return el(PanelBody, { title: __('Link buttons and providers', 'mds-release-manager'), ...panelProps },
			el('p', { className: 'description' }, __('Manage provider defaults for the release editor, shortcodes, Elementor, and blocks here. Visibility, tooltip, and order apply to blocks; URLs remain on each release. A shared button background overrides provider colors.', 'mds-release-manager')),
			items.map((item, index) => {
				const isBuy = item.name === '__buy_now';
				const label = isBuy ? __('Buy Now', 'mds-release-manager') : item.name;
				const providerIndex = providers.findIndex((provider) => provider.name.toLowerCase() === item.name.toLowerCase());
				const provider = providers[providerIndex];
				const conflict = provider && item.icon && item.icon.trim() !== (provider.icon || '').trim();
				return el(PanelBody, { key: item.name, title: label, initialOpen: false },
					!isBuy && !provider && el(Notice, { status: 'info', isDismissible: false }, __('This saved block override has no configured provider. Existing release URLs and this override are retained.', 'mds-release-manager')),
					el(ToggleControl, { label: __('Show link in blocks', 'mds-release-manager'), checked: item.enabled !== false, onChange: (enabled) => updateItem(index, { enabled }) }),
					provider
						? el(TextControl, { label: __('Provider icon CSS classes', 'mds-release-manager'), help: __('Used by the release editor, shortcodes, Elementor, and blocks unless a block icon override applies.', 'mds-release-manager'), value: provider.icon || '', onChange: (icon) => updateProvider(providerIndex, { icon }) })
						: el(TextControl, { label: __('Block icon CSS classes', 'mds-release-manager'), help: __('Changes the icon for this link in blocks.', 'mds-release-manager'), value: item.icon || '', onChange: (icon) => updateItem(index, { icon }) }),
					!!conflict && el(Notice, { status: 'warning', isDismissible: false },
						el('p', null, sprintf(__('The saved block icon override is "%s"; it takes precedence over the provider icon in blocks. Other outputs continue to use the provider icon.', 'mds-release-manager'), item.icon)),
						el(Button, { variant: 'secondary', onClick: () => removeIconOverride(index) }, __('Use provider icon in blocks', 'mds-release-manager')),
						' ',
						el(Button, { variant: 'secondary', onClick: () => { updateProvider(providerIndex, { icon: item.icon }); removeIconOverride(index); } }, __('Use block icon for all outputs', 'mds-release-manager'))
					),
					provider && el(wp.element.Fragment, null,
						el('p', { className: 'description' }, __('Provider background color. A shared block button background takes precedence. Clearing a built-in provider color restores its brand color.', 'mds-release-manager')),
						el(window.mdsRmColorControl, { label: sprintf(__('Background color for %s', 'mds-release-manager'), label), value: provider.color || '', onChange: (color) => updateProvider(providerIndex, { color: color || '' }) }),
						el('p', { className: 'description' }, __('Provider names cannot be changed because existing release links use them.', 'mds-release-manager'))
					),
					isBuy && el('p', { className: 'description' }, __('Buy Now uses its default background unless a shared button background is set.', 'mds-release-manager')),
					el(TextControl, { label: __('Link name and tooltip', 'mds-release-manager'), help: __('Changes the hover text and the name read by screen readers in blocks, not the destination URL.', 'mds-release-manager'), value: item.title || '', onChange: (title) => updateItem(index, { title }) }),
					el(Button, { variant: 'secondary', disabled: index === 0, onClick: () => move(index, -1), 'aria-label': sprintf(__('Move %s up', 'mds-release-manager'), label) }, __('Move up', 'mds-release-manager')),
					' ',
					el(Button, { variant: 'secondary', disabled: index === items.length - 1, onClick: () => move(index, 1), 'aria-label': sprintf(__('Move %s down', 'mds-release-manager'), label) }, __('Move down', 'mds-release-manager')),
					provider && el(Button, {
						variant: 'tertiary', isDestructive: true, disabled: providers.length === 1,
						onClick: () => {
							if (window.confirm(sprintf(__('Remove %s from configured providers? Saved release URLs and block overrides remain, but the provider defaults will no longer apply.', 'mds-release-manager'), label))) {
								onProvidersChange(providers.filter((_, i) => i !== providerIndex));
							}
						},
					}, __('Remove provider', 'mds-release-manager'))
				);
			}),
			el(TextControl, { label: __('New provider name', 'mds-release-manager'), value: newName, onChange: setNewName,
				help: invalidName ? __('Enter a unique provider name without markup or encoded characters (maximum 200 characters).', 'mds-release-manager') : __('Names are fixed after adding to protect saved release links.', 'mds-release-manager') }),
			el(Button, { variant: 'secondary', disabled: !name || invalidName, onClick: () => {
				onProvidersChange([...providers, { name, icon: 'fas fa-link', color: '' }]);
				setNewName('');
			} }, __('Add provider', 'mds-release-manager')),
			el('p', { className: 'description' }, __('At least one provider must remain configured. Hide its block link if it should not appear.', 'mds-release-manager')),
			value.items && el(Button, { variant: 'tertiary', onClick: () => onChange({ items: undefined }) }, __('Restore block link defaults', 'mds-release-manager'))
		);
	}

	function Defaults({ slug }) {
		const [value, setValue] = useState(all[slug] || {});
		const [providers, setProviders] = useState(config.providers);
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
		function changeProviders(next) {
			const fields = document.getElementById('mds-provider-fields');
			fields.replaceChildren(...next.flatMap((provider) => ['name', 'icon', 'color'].map((key) => {
				const field = document.createElement('input');
				field.type = 'hidden';
				field.name = `mds_rm_providers[${key}][]`;
				field.value = provider[key] || '';
				return field;
			})));
			input.dispatchEvent(new Event('change', { bubbles: true }));
			setProviders(next);
		}
		const isMeta = slug === 'release-meta';
		const isLinks = slug === 'release-links';
		return el(BlockEditorProvider, { settings: config.editorSettings, value: emptyBlocks, onInput: () => {}, onChange: () => {} },
			isMeta && el(Items, { value, onChange: change, panelProps: stylePanelProps('items') }),
			isLinks && el(LinkProviders, { value, onChange: change, providers, onProvidersChange: changeProviders, panelProps: stylePanelProps('items') }),
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

	function AudioPlayerFields({ player, values, onChange }) {
		const [palette, customColor] = useSettings('color.palette', 'color.custom');
		const colorPresets = presetChoices(palette, 'color');

		function updateField(key, nextValue) {
			const next = { ...values };
			if (nextValue === undefined || nextValue === '') {
				delete next[key];
			} else {
				next[key] = nextValue;
			}
			onChange(next);
		}

		return el(wp.element.Fragment, null,
			player.fields.map((field) => {
				const current = Object.prototype.hasOwnProperty.call(values, field.key) ? values[field.key] : field.default;
				if (field.type === 'toggle') {
					return el(ToggleControl, {
						key: field.key,
						label: field.label,
						help: field.help || undefined,
						checked: Boolean(current),
						onChange: (checked) => updateField(field.key, Boolean(checked)),
					});
				}
				if (field.type === 'select') {
					const options = Object.entries(field.options || {}).map(([val, label]) => ({ value: String(val), label }));
					return el(SelectControl, {
						key: field.key,
						label: field.label,
						help: field.help || undefined,
						value: current != null ? String(current) : '',
						options,
						onChange: (selected) => updateField(field.key, selected),
					});
				}
				if (field.type === 'range' || field.type === 'number') {
					return el(RangeControl, {
						key: field.key,
						label: field.label,
						help: field.help || undefined,
						min: field.min ?? 0,
						max: field.max ?? 1000,
						step: field.step ?? 1,
						allowReset: true,
						value: current != null && current !== '' ? Number(current) : undefined,
						onChange: (num) => updateField(field.key, num === undefined ? undefined : Number(num)),
					});
				}
				if (field.type === 'color') {
					return el(wp.element.Fragment, { key: field.key },
						colorPresets.length > 0 && el(SelectControl, {
							// translators: %s: Name of the color setting.
							label: sprintf(__('%s preset', 'mds-release-manager'), field.label),
							value: colorPresets.some((opt) => opt.value === current) ? current : '',
							options: [{ label: __('Custom / default', 'mds-release-manager'), value: '' }, ...colorPresets],
							onChange: (preset) => updateField(field.key, preset || (field.default ?? '')),
						}),
						customColor !== false && el(window.mdsRmColorControl, {
							label: field.label,
							value: current || '',
							onChange: (colorVal) => updateField(field.key, colorVal || (field.default ?? '')),
						}),
						field.help && el('p', { className: 'description' }, field.help)
					);
				}
				return el(TextControl, {
					key: field.key,
					label: field.label,
					help: field.help || undefined,
					value: current != null ? String(current) : '',
					onChange: (text) => updateField(field.key, text),
				});
			})
		);
	}

	function AudioPlayerManager() {
		const players = Array.isArray(config.audioPlayers) ? config.audioPlayers : [];
		const [activePlayer, setActivePlayer] = useState(config.activeAudioPlayer || 'html5');
		const [playerSettings, setPlayerSettings] = useState(
			config.audioPlayerSettings && !Array.isArray(config.audioPlayerSettings) ? { ...config.audioPlayerSettings } : {}
		);
		const [openSlug, setOpenSlug] = useState(config.activeAudioPlayer || 'html5');
		const settingsInput = document.getElementById('mds-rm-audio-player-settings');
		const playerFieldsContainer = document.getElementById('mds-audio-player-fields');

		function selectActivePlayer(slug) {
			setActivePlayer(slug);
			if (playerFieldsContainer) {
				playerFieldsContainer.replaceChildren();
				if (slug) {
					const hidden = document.createElement('input');
					hidden.type = 'hidden';
					hidden.name = 'mds_rm_audio_players[]';
					hidden.value = slug;
					playerFieldsContainer.appendChild(hidden);
				}
			}
			if (settingsInput) {
				settingsInput.dispatchEvent(new Event('change', { bubbles: true }));
			}
		}

		function updatePlayerSettings(slug, nextValues) {
			const nextAll = { ...playerSettings };
			if (nextValues && Object.keys(nextValues).length > 0) {
				nextAll[slug] = nextValues;
			} else {
				delete nextAll[slug];
			}
			setPlayerSettings(nextAll);
			if (settingsInput) {
				settingsInput.value = JSON.stringify(nextAll);
				settingsInput.dispatchEvent(new Event('change', { bubbles: true }));
			}
		}

		function resetPlayerSettings(slug) {
			const defaults = {};
			const player = players.find((item) => item.slug === slug);
			(player?.fields || []).forEach((field) => {
				if (field.default !== undefined) defaults[field.key] = field.default;
			});
			updatePlayerSettings(slug, Object.keys(defaults).length ? defaults : undefined);
		}

		return el(BlockEditorProvider, { settings: config.editorSettings, value: emptyBlocks, onInput: () => {}, onChange: () => {} },
			players.map((player) => {
				const isActive = activePlayer === player.slug;
				const statusText = isActive
					? __('Active Player', 'mds-release-manager')
					: player.isBuiltin
						? __('Automatic Fallback', 'mds-release-manager')
						: __('Inactive', 'mds-release-manager');
				// translators: 1: Audio player label. 2: Current player status (Active Player, Automatic Fallback, or Inactive).
				const panelTitle = sprintf(__('%1$s — %2$s', 'mds-release-manager'), player.label, statusText);
				const values = playerSettings[player.slug] || {};
				const hasFields = Array.isArray(player.fields) && player.fields.length > 0;

				return el(PanelBody, {
					key: player.slug,
					title: panelTitle,
					opened: openSlug === player.slug,
					onToggle: () => setOpenSlug((current) => current === player.slug ? null : player.slug),
				},
					player.description && el('p', { className: 'description' }, player.description),
					el('p', null,
						el('label', { className: 'mds-admin-option' },
							el('input', {
								type: 'radio',
								name: 'mds_rm_active_audio_player_radio',
								value: player.slug,
								checked: isActive,
								onChange: () => selectActivePlayer(player.slug),
							}),
							' ',
							// translators: %s: Audio player label.
							sprintf(__('Set %s as the active site-wide audio preview player', 'mds-release-manager'), player.label)
						)
					),
					player.isBuiltin && !isActive && el(Notice, { status: 'info', isDismissible: false },
						__('This core player remains active as an automatic fallback whenever the selected third-party player is not configured on a release.', 'mds-release-manager')
					),
					hasFields
						? el(wp.element.Fragment, null,
							el(AudioPlayerFields, {
								player,
								values,
								onChange: (nextValues) => updatePlayerSettings(player.slug, nextValues),
							}),
							el(Button, {
								variant: 'tertiary',
								onClick: () => resetPlayerSettings(player.slug),
							}, __('Restore player defaults', 'mds-release-manager'))
						)
						: el('p', { className: 'description' },
							__('This player uses the release’s Audio Preview URL (.mp3) and requires no additional player parameters. To resize the audio container within the links block, use the Audio preview player panel under the Release Links Block tab.', 'mds-release-manager')
						)
				);
			})
		);
	}

	const audioManagerTarget = document.getElementById('mds-audio-player-manager');
	if (audioManagerTarget) {
		const managerElement = el(AudioPlayerManager);
		if (wp.element.createRoot) wp.element.createRoot(audioManagerTarget).render(managerElement);
		else wp.element.render(managerElement, audioManagerTarget);
	}
})(window.wp);
