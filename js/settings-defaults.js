(function (wp) {
	const { createElement: el, Fragment, useState } = wp.element;
	const { BlockEditorProvider, useSettings } = wp.blockEditor;
	const { BoxControl, Button, Notice, PanelBody, RangeControl, SelectControl, TextControl, ToggleControl } = wp.components;
	const { __, sprintf } = wp.i18n;
	const config = window.mdsRmDefaultsSettings;
	const input = document.getElementById('mds-rm-block-defaults');
	let all = Array.isArray(config.defaults) ? {} : { ...config.defaults };
	const emptyBlocks = [];
	const { ICON_PRESETS, SectionInfo, ControlGroup, AccordionToolbar, RepeaterCard } = window.mdsRmStylePrimitives;

	const PANEL_IDS = {
		'release-meta': ['items', 'container', 'typography', 'icons'],
		'release-links': ['items', 'behavior', 'container', 'buttons', 'audio'],
		'artist-discography': ['outer', 'table', 'header', 'rows', 'cells'],
	};

	function presetChoices(list, prefix) {
		const entries = Array.isArray(list) ? list : ['default', 'theme', 'custom'].flatMap((origin) => list?.[origin] || []);
		return Array.from(new Map(entries.map(({ slug, name }) => [slug, { label: name, value: `var(--wp--preset--${prefix}--${slug})` }])).values());
	}

	function WrapperStyles({ value, onChange, panelProps }) {
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
			return el('div', { className: 'mds-style-field' },
				el(SelectControl, {
					label,
					value: options.some((option) => option.value === current) ? current : '',
					options: [{ label: __('Theme / default', 'mds-release-manager'), value: '' }, ...options],
					onChange: (setting) => set(path, setting),
				})
			);
		}
		function color(path, label) {
			const current = get(path);
			const options = presetChoices(palette, 'color');
			return el('div', { className: 'mds-style-field mds-style-field--color' },
				// translators: %s: Name of the color setting.
				!!options.length && select(path, sprintf(__('%s (Theme Preset)', 'mds-release-manager'), label), options),
				!options.length && customColor !== false && el('span', { className: 'mds-style-field__label' }, label),
				customColor !== false && el(window.mdsRmColorControl, {
					label,
					value: current,
					onChange: (setting) => set(path, setting),
				})
			);
		}
		function range(path, label, min, max, step = 1, unit = 'px') {
			const current = get(path);
			return el('div', { className: 'mds-style-field' },
				el(RangeControl, {
					label,
					min,
					max,
					step,
					allowReset: true,
					value: typeof current === 'string' && !current.startsWith('var(') ? parseFloat(current) : undefined,
					onChange: (setting) => set(path, setting === undefined ? undefined : `${setting}${unit}`),
				})
			);
		}
		function box(key, label) {
			const path = ['spacing', key];
			const current = get(path);
			const sides = ['top', 'right', 'bottom', 'left'];
			const values = current && typeof current === 'object' ? current : Object.fromEntries(sides.map((side) => [side, current || '']));
			const spacePresets = presetChoices(spacing, 'spacing');
			return el('div', { className: 'mds-style-field mds-style-field--paired' },
				// translators: %s: Name of the spacing setting.
				!!spacePresets.length && select(path, sprintf(__('%s (Theme Preset)', 'mds-release-manager'), label), spacePresets),
				customSpacing !== false && el(BoxControl, {
					label,
					values,
					onChange: (next) => set(path, Object.fromEntries(sides.filter((side) => next?.[side]).map((side) => [side, next[side]]))),
				})
			);
		}
		return el(PanelBody, { title: __('Block Container', 'mds-release-manager'), ...panelProps },
			el(SectionInfo, {
				title: __('About Block Container', 'mds-release-manager'),
				summary: __('Styles the outer box surrounding the entire Artist Discography block, including the heading, table, and pagination links.', 'mds-release-manager'),
				inheritance: __('Individual table layers (Table Wrapper, Column Headers, Table Rows, and Cells) below can override these base colors and typography.', 'mds-release-manager'),
			}),
			el(ControlGroup, { title: __('Surface & Link Colors', 'mds-release-manager'), twoCol: true },
				color(['color', 'background'], __('Background Color', 'mds-release-manager')),
				color(['color', 'text'], __('Text Color', 'mds-release-manager')),
				color(['elements', 'link', 'color', 'text'], __('Default Link Color', 'mds-release-manager'))
			),
			el(ControlGroup, { title: __('Dimensions & Spacing', 'mds-release-manager'), twoCol: true },
				box('margin', __('Outer Margin', 'mds-release-manager')),
				box('padding', __('Inner Padding', 'mds-release-manager'))
			),
			el(ControlGroup, { title: __('Base Typography', 'mds-release-manager'), twoCol: true },
				select(['typography', 'fontFamily'], __('Font Family', 'mds-release-manager'), presetChoices(families, 'font-family')),
				select(['typography', 'fontSize'], __('Font Size (Theme Preset)', 'mds-release-manager'), presetChoices(sizes, 'font-size')),
				customSize !== false && range(['typography', 'fontSize'], __('Custom Font Size (px)', 'mds-release-manager'), 8, 96),
				select(['typography', 'fontWeight'], __('Font Weight', 'mds-release-manager'), [
					{ label: __('Normal (400)', 'mds-release-manager'), value: '400' },
					{ label: __('Medium (500)', 'mds-release-manager'), value: '500' },
					{ label: __('Semibold (600)', 'mds-release-manager'), value: '600' },
					{ label: __('Bold (700)', 'mds-release-manager'), value: '700' },
				]),
				select(['typography', 'fontStyle'], __('Font Style', 'mds-release-manager'), [
					{ label: __('Normal', 'mds-release-manager'), value: 'normal' },
					{ label: __('Italic', 'mds-release-manager'), value: 'italic' },
				]),
				lineHeight !== false && range(['typography', 'lineHeight'], __('Line Height', 'mds-release-manager'), 0.8, 3, 0.1, ''),
				range(['typography', 'letterSpacing'], __('Letter Spacing (px)', 'mds-release-manager'), -2, 10, 0.1)
			),
			el(ControlGroup, { title: __('Border & Corners', 'mds-release-manager'), twoCol: true },
				select(['border', 'style'], __('Border Style', 'mds-release-manager'), [
					{ label: __('None', 'mds-release-manager'), value: 'none' },
					{ label: __('Solid', 'mds-release-manager'), value: 'solid' },
					{ label: __('Dashed', 'mds-release-manager'), value: 'dashed' },
					{ label: __('Dotted', 'mds-release-manager'), value: 'dotted' },
					{ label: __('Double', 'mds-release-manager'), value: 'double' },
				]),
				range(['border', 'width'], __('Border Width (px)', 'mds-release-manager'), 0, 16),
				color(['border', 'color'], __('Border Color', 'mds-release-manager')),
				range(['border', 'radius'], __('Corner Radius (px)', 'mds-release-manager'), 0, 48)
			),
			!!Object.keys(value).length && el('div', { className: 'mds-reset-row' },
				el(Button, { variant: 'tertiary', isDestructive: true, onClick: () => onChange(undefined) }, __('Reset Block Container styles', 'mds-release-manager'))
			)
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
		return el(PanelBody, { title: __('Fields & Ordering', 'mds-release-manager'), ...panelProps },
			el(SectionInfo, {
				title: __('About Fields & Ordering', 'mds-release-manager'),
				summary: __('Toggle visibility and reorder metadata fields directly from each row below. Click Customize on any field to change its icon or hover tooltip.', 'mds-release-manager'),
				inheritance: __('Only fields that have a saved value on a given Release will render on the frontend.', 'mds-release-manager'),
			}),
			el('div', { className: 'mds-repeater-list' },
				items.map((item, index) => {
					const name = item.field;
					const label = config.metaFields[name]?.label;
					if (!label) return null;
					const isEnabled = item.enabled !== false;
					return el(RepeaterCard, {
						key: name,
						icon: item.icon || '',
						title: label,
						badge: isEnabled ? null : __('Hidden', 'mds-release-manager'),
						enabled: isEnabled,
						onToggleEnabled: (enabled) => update(index, { enabled }),
						canMoveUp: index > 0,
						canMoveDown: index < items.length - 1,
						onMoveUp: () => move(index, -1),
						onMoveDown: () => move(index, 1),
					},
						el('div', { className: 'mds-style-grid-2col' },
							el(SelectControl, {
								label: __('Choose Icon Preset', 'mds-release-manager'),
								value: '',
								options: ICON_PRESETS,
								onChange: (preset) => {
									if (preset) update(index, { icon: preset === '__none' ? '' : preset });
								},
							}),
							el(TextControl, {
								label: __('Icon CSS Classes', 'mds-release-manager'),
								help: __('Use Dashicons (dashicons dashicons-album) or Font Awesome (fas fa-calendar). Leave blank for no icon.', 'mds-release-manager'),
								value: item.icon || '',
								onChange: (icon) => update(index, { icon }),
							})
						),
						el(TextControl, {
							label: __('Field Tooltip Label', 'mds-release-manager'),
							help: __('Changes the hover text and accessible description for this field, not the displayed metadata value.', 'mds-release-manager'),
							value: item.title || '',
							onChange: (title) => update(index, { title }),
						})
					);
				})
			),
			value.items && el('div', { className: 'mds-reset-row' },
				el(Button, { variant: 'tertiary', isDestructive: true, onClick: () => onChange({ items: undefined }) }, __('Restore built-in field defaults', 'mds-release-manager'))
			)
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
			onChange({
				items: items.map((item, i) => {
					if (i !== index) return item;
					const next = { ...item };
					delete next.icon;
					return next;
				}),
			});
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
		return el(PanelBody, { title: __('Streaming Providers & Buy Now', 'mds-release-manager'), ...panelProps },
			el(SectionInfo, {
				title: __('About Streaming Providers & Buy Now', 'mds-release-manager'),
				summary: __('Manage site-wide streaming providers (Spotify, Apple Music, Bandcamp, etc.) and the Buy Now button. Toggle visibility and reorder buttons directly on each row, or click Customize to edit icons, brand colors, and tooltips.', 'mds-release-manager'),
				inheritance: __('Provider icons and colors apply across the release editor, shortcodes, Elementor, and blocks. A Shared Button Background in Button Styling overrides individual provider colors.', 'mds-release-manager'),
			}),
			el('div', { className: 'mds-repeater-list' },
				items.map((item, index) => {
					const isBuy = item.name === '__buy_now';
					const label = isBuy ? __('Buy Now', 'mds-release-manager') : item.name;
					const providerIndex = providers.findIndex((provider) => provider.name.toLowerCase() === item.name.toLowerCase());
					const provider = providers[providerIndex];
					const conflict = provider && item.icon && item.icon.trim() !== (provider.icon || '').trim();
					const isEnabled = item.enabled !== false;
					const displayIcon = provider ? (provider.icon || '') : (item.icon || '');
					return el(RepeaterCard, {
						key: item.name,
						icon: displayIcon,
						swatchColor: provider && provider.color ? provider.color : null,
						title: label,
						badge: isBuy ? __('Core CTA', 'mds-release-manager') : (!isEnabled ? __('Hidden', 'mds-release-manager') : null),
						enabled: isEnabled,
						onToggleEnabled: (enabled) => updateItem(index, { enabled }),
						canMoveUp: index > 0,
						canMoveDown: index < items.length - 1,
						onMoveUp: () => move(index, -1),
						onMoveDown: () => move(index, 1),
					},
						!isBuy && !provider && el(Notice, { status: 'info', isDismissible: false }, __('This saved block override has no configured provider. Existing release URLs and this override are retained.', 'mds-release-manager')),
						el('div', { className: 'mds-style-grid-2col' },
							el(SelectControl, {
								label: __('Choose Icon Preset', 'mds-release-manager'),
								value: '',
								options: ICON_PRESETS,
								onChange: (preset) => {
									if (!preset) return;
									const nextIcon = preset === '__none' ? '' : preset;
									if (provider) updateProvider(providerIndex, { icon: nextIcon });
									else updateItem(index, { icon: nextIcon });
								},
							}),
							provider
								? el(TextControl, {
									label: __('Provider Icon CSS Classes', 'mds-release-manager'),
									help: __('Used across the release editor, shortcodes, Elementor, and blocks unless overridden.', 'mds-release-manager'),
									value: provider.icon || '',
									onChange: (icon) => updateProvider(providerIndex, { icon }),
								})
								: el(TextControl, {
									label: __('Block Icon CSS Classes', 'mds-release-manager'),
									help: __('Changes the icon for this link in blocks.', 'mds-release-manager'),
									value: item.icon || '',
									onChange: (icon) => updateItem(index, { icon }),
								})
						),
						!!conflict && el(Notice, { status: 'warning', isDismissible: false },
							el('p', null, sprintf(__('The saved block icon override is "%s"; it takes precedence over the provider icon in blocks. Other outputs continue to use the provider icon.', 'mds-release-manager'), item.icon)),
							el(Button, { variant: 'secondary', onClick: () => removeIconOverride(index) }, __('Use provider icon in blocks', 'mds-release-manager')),
							' ',
							el(Button, { variant: 'secondary', onClick: () => { updateProvider(providerIndex, { icon: item.icon }); removeIconOverride(index); } }, __('Use block icon for all outputs', 'mds-release-manager'))
						),
						provider && el('div', { className: 'mds-style-field mds-style-field--color' },
							el('span', { className: 'mds-style-field__label' }, sprintf(__('Brand Background Color for %s', 'mds-release-manager'), label)),
							el(window.mdsRmColorControl, {
								label: sprintf(__('Background color for %s', 'mds-release-manager'), label),
								value: provider.color || '',
								onChange: (color) => updateProvider(providerIndex, { color: color || '' }),
							}),
							el('p', { className: 'mds-style-field__help' }, __('Clearing a built-in provider color restores its default brand color. Provider names are fixed to protect existing release links.', 'mds-release-manager'))
						),
						isBuy && el('p', { className: 'mds-style-field__help' }, __('Buy Now uses its default accent background unless a Shared Button Background is set under Button Styling.', 'mds-release-manager')),
						el(TextControl, {
							label: __('Accessible Label & Tooltip', 'mds-release-manager'),
							help: __('Changes the hover text and screen-reader label in blocks, not the destination URL.', 'mds-release-manager'),
							value: item.title || '',
							onChange: (title) => updateItem(index, { title }),
						}),
						provider && el('div', { className: 'mds-reset-row' },
							el(Button, {
								variant: 'tertiary',
								isDestructive: true,
								disabled: providers.length === 1,
								onClick: () => {
									if (window.confirm(sprintf(__('Remove %s from configured providers? Saved release URLs and block overrides remain, but the provider defaults will no longer apply.', 'mds-release-manager'), label))) {
										onProvidersChange(providers.filter((_, i) => i !== providerIndex));
									}
								},
							}, __('Remove provider', 'mds-release-manager'))
						)
					);
				})
			),
			el(ControlGroup, {
				title: __('Add New Streaming Provider', 'mds-release-manager'),
				description: __('At least one provider must remain configured. Hide its toggle above if it should not appear by default.', 'mds-release-manager'),
			},
				el(TextControl, {
					label: __('Provider Name', 'mds-release-manager'),
					value: newName,
					onChange: setNewName,
					help: invalidName
						? __('Enter a unique provider name without markup or encoded characters (maximum 200 characters).', 'mds-release-manager')
						: __('Provider names are fixed after adding to protect saved release links.', 'mds-release-manager'),
				}),
				el('div', null,
					el(Button, {
						variant: 'secondary',
						disabled: !name || invalidName,
						onClick: () => {
							onProvidersChange([...providers, { name, icon: 'fas fa-link', color: '' }]);
							setNewName('');
						},
					}, __('Add Provider', 'mds-release-manager'))
				)
			),
			value.items && el('div', { className: 'mds-reset-row' },
				el(Button, { variant: 'tertiary', isDestructive: true, onClick: () => onChange({ items: undefined }) }, __('Restore block link defaults', 'mds-release-manager'))
			)
		);
	}

	function Defaults({ slug }) {
		const [value, setValue] = useState(all[slug] || {});
		const [providers, setProviders] = useState(config.providers);
		const panelList = PANEL_IDS[slug] || [];
		const [openPanels, setOpenPanels] = useState(() => (panelList.length ? { [panelList[0]]: true } : {}));

		function stylePanelProps(id) {
			return {
				opened: Boolean(openPanels[id]),
				onToggle: () => setOpenPanels((current) => ({ ...current, [id]: !current[id] })),
			};
		}
		function expandAll() {
			setOpenPanels(Object.fromEntries(panelList.map((id) => [id, true])));
		}
		function collapseAll() {
			setOpenPanels({});
		}
		const openCount = panelList.filter((id) => openPanels[id]).length;

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
			el(AccordionToolbar, {
				openCount,
				totalCount: panelList.length,
				onExpandAll: expandAll,
				onCollapseAll: collapseAll,
			}),
			isMeta && el(Items, { value, onChange: change, panelProps: stylePanelProps('items') }),
			isLinks && el(LinkProviders, { value, onChange: change, providers, onProvidersChange: changeProviders, panelProps: stylePanelProps('items') }),
			isLinks && el(PanelBody, { title: __('Behavior & Display', 'mds-release-manager'), ...stylePanelProps('behavior') },
				el(SectionInfo, {
					title: __('About Behavior & Display', 'mds-release-manager'),
					summary: __('Controls link target behavior when visitors click a streaming or Buy Now button, and whether the audio preview slot renders when audio is available.', 'mds-release-manager'),
					inheritance: __('To choose which audio player is active or adjust its skin/height, use the Audio Player Manager tab. To resize the audio slot width, use Audio Player Sizing below.', 'mds-release-manager'),
				}),
				el(ControlGroup, { title: __('Interaction Defaults', 'mds-release-manager'), twoCol: true },
					el(ToggleControl, {
						label: __('Open Links in a New Tab', 'mds-release-manager'),
						help: __('Adds target="_blank" and rel="noopener noreferrer" to streaming and Buy Now links.', 'mds-release-manager'),
						checked: value.newTab !== false,
						onChange: (newTab) => change({ newTab }),
					}),
					el(ToggleControl, {
						label: __('Show Audio Preview Player', 'mds-release-manager'),
						help: __('Displays the active audio preview player whenever the Release has audio configured.', 'mds-release-manager'),
						checked: value.showAudio !== false,
						onChange: (showAudio) => change({ showAudio }),
					}),
					el(SelectControl, {
						label: __('Multi-Format Buy Now Behavior', 'mds-release-manager'),
						help: __('When a release has multiple Media Formats and no WooCommerce White-Label override is active, choose whether clicking Buy Now opens a format picker modal or links directly to the Primary format.', 'mds-release-manager'),
						value: value.buyNowMode || 'picker',
						options: [
							{ label: __('Choose Format Modal (Recommended)', 'mds-release-manager'), value: 'picker' },
							{ label: __('Primary Direct Link', 'mds-release-manager'), value: 'direct' },
						],
						onChange: (buyNowMode) => change({ buyNowMode }),
					})
				)
			),
			isMeta && el(window.mdsRmReleaseMetaControls, { standalone: true, stylePanelProps, attributes: value, setAttributes: change }),
			isLinks && el(window.mdsRmReleaseLinksControls, { standalone: true, stylePanelProps, attributes: value, setAttributes: change }),
			isMeta && value.metaStyles && el('div', { className: 'mds-reset-row' },
				el(Button, { variant: 'tertiary', isDestructive: true, onClick: () => change({ metaStyles: undefined }) }, __('Reset Release Meta styles', 'mds-release-manager'))
			),
			isLinks && value.linkStyles && el('div', { className: 'mds-reset-row' },
				el(Button, { variant: 'tertiary', isDestructive: true, onClick: () => change({ linkStyles: undefined }) }, __('Reset Release Links styles', 'mds-release-manager'))
			),
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

		return el('div', { className: 'mds-style-grid-2col' },
			player.fields.map((field) => {
				const current = Object.prototype.hasOwnProperty.call(values, field.key) ? values[field.key] : field.default;
				if (field.type === 'toggle') {
					return el('div', { key: field.key, className: 'mds-style-field' },
						el(ToggleControl, {
							label: field.label,
							help: field.help || undefined,
							checked: Boolean(current),
							onChange: (checked) => updateField(field.key, Boolean(checked)),
						})
					);
				}
				if (field.type === 'select') {
					const options = Object.entries(field.options || {}).map(([val, label]) => ({ value: String(val), label }));
					return el('div', { key: field.key, className: 'mds-style-field' },
						el(SelectControl, {
							label: field.label,
							help: field.help || undefined,
							value: current != null ? String(current) : '',
							options,
							onChange: (selected) => updateField(field.key, selected),
						})
					);
				}
				if (field.type === 'range' || field.type === 'number') {
					return el('div', { key: field.key, className: 'mds-style-field' },
						el(RangeControl, {
							label: field.label,
							help: field.help || undefined,
							min: field.min ?? 0,
							max: field.max ?? 1000,
							step: field.step ?? 1,
							allowReset: true,
							value: current != null && current !== '' ? Number(current) : undefined,
							onChange: (num) => updateField(field.key, num === undefined ? undefined : Number(num)),
						})
					);
				}
				if (field.type === 'color') {
					return el('div', { key: field.key, className: 'mds-style-field mds-style-field--color' },
						colorPresets.length > 0 && el(SelectControl, {
							// translators: %s: Name of the color setting.
							label: sprintf(__('%s (Theme Preset)', 'mds-release-manager'), field.label),
							value: colorPresets.some((opt) => opt.value === current) ? current : '',
							options: [{ label: __('Custom / default', 'mds-release-manager'), value: '' }, ...colorPresets],
							onChange: (preset) => updateField(field.key, preset || (field.default ?? '')),
						}),
						customColor !== false && el(window.mdsRmColorControl, {
							label: field.label,
							value: current || '',
							onChange: (colorVal) => updateField(field.key, colorVal || (field.default ?? '')),
						}),
						field.help && el('p', { className: 'mds-style-field__help' }, field.help)
					);
				}
				return el('div', { key: field.key, className: 'mds-style-field' },
					el(TextControl, {
						label: field.label,
						help: field.help || undefined,
						value: current != null ? String(current) : '',
						onChange: (text) => updateField(field.key, text),
					})
				);
			})
		);
	}

	function AudioPlayerManager() {
		const players = Array.isArray(config.audioPlayers) ? config.audioPlayers : [];
		const [activePlayer, setActivePlayer] = useState(config.activeAudioPlayer || 'html5');
		const [playerSettings, setPlayerSettings] = useState(
			config.audioPlayerSettings && !Array.isArray(config.audioPlayerSettings) ? { ...config.audioPlayerSettings } : {}
		);
		const [openPanels, setOpenPanels] = useState(() => ({ [config.activeAudioPlayer || 'html5']: true }));
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
					opened: Boolean(openPanels[player.slug]),
					onToggle: () => setOpenPanels((current) => ({ ...current, [player.slug]: !current[player.slug] })),
				},
					player.description && el(SectionInfo, {
						title: player.label,
						summary: player.description,
						inheritance: player.isBuiltin && !isActive
							? __('This core player remains active as an automatic fallback whenever the selected third-party player is not configured on a release.', 'mds-release-manager')
							: undefined,
					}),
					el(ControlGroup, { title: __('Player Activation', 'mds-release-manager') },
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
					hasFields
						? el(ControlGroup, {
							title: __('Player Styling & Parameters', 'mds-release-manager'),
							description: __('Site-wide appearance and embed options applied whenever this player renders.', 'mds-release-manager'),
						},
							el(AudioPlayerFields, {
								player,
								values,
								onChange: (nextValues) => updatePlayerSettings(player.slug, nextValues),
							}),
							el('div', { className: 'mds-reset-row' },
								el(Button, {
									variant: 'tertiary',
									isDestructive: true,
									onClick: () => resetPlayerSettings(player.slug),
								}, __('Restore player defaults', 'mds-release-manager'))
							)
						)
						: el('p', { className: 'mds-style-field__help' },
							__('This player uses the release’s Audio Preview URL (.mp3) and requires no additional player parameters. To resize the audio slot width inside the Release Links block, use Audio Player Sizing under the Release Links Block tab.', 'mds-release-manager')
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
