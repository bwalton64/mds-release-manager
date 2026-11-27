(function (wp) {
	const { createElement: el, Fragment, useState } = wp.element;
	const { BlockEditorProvider, useSettings } = wp.blockEditor;
	const { BoxControl, Button, Notice, PanelBody, RangeControl, SelectControl, TabPanel, TextControl, ToggleControl } = wp.components;
	const { __, sprintf } = wp.i18n;
	const speak = wp.a11y && typeof wp.a11y.speak === 'function' ? wp.a11y.speak : () => {};
	const config = window.mdsRmDefaultsSettings;
	const input = document.getElementById('mds-rm-block-defaults');
	let all = Array.isArray(config.defaults) ? {} : { ...config.defaults };
	const emptyBlocks = [];
	const { ICON_PRESETS, SectionInfo, ControlGroup, AccordionToolbar, RepeaterCard, ThemeSchemeBar } = window.mdsRmStylePrimitives;

	const PANEL_IDS = {
		'release-meta': ['items', 'container', 'typography', 'icons'],
		'release-links': ['items', 'behavior', 'container', 'buttons'],
		'release-player': ['modules', 'playback', 'styles'],
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
					__next40pxDefaultSize: true,
					__nextHasNoMarginBottom: true,
				})
			);
		}
		function color(path, label) {
			const current = get(path);
			return el('div', { className: 'mds-style-field mds-style-field--color' },
				el('span', { className: 'mds-style-field__label' }, label),
				customColor !== false && el(window.mdsRmColorControl, {
					label,
					value: current,
					palette,
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
					__next40pxDefaultSize: true,
					__nextHasNoMarginBottom: true,
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
					__next40pxDefaultSize: true,
				})
			);
		}
		return el(PanelBody, { title: __('Outer Section Box', 'mds-release-manager'), ...panelProps },
			el(SectionInfo, {
				title: __('About Outer Section Box', 'mds-release-manager'),
				summary: __('Styles the outer box surrounding the entire Artist Discography block, including its heading, table, and pagination links.', 'mds-release-manager'),
				inheritance: __('Individual table layers (Table Frame & Base Text, Column Headers, Table Rows, and Cells) below can override these base colors and typography.', 'mds-release-manager'),
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
						window.mdsRmIconPicker && window.mdsRmIconPicker.IconPickerControl
							? el(window.mdsRmIconPicker.IconPickerControl, {
								label: __('Field Icon', 'mds-release-manager'),
								help: __('Choose a Font Awesome or Dashicons icon, or leave empty for no icon.', 'mds-release-manager'),
								value: item.icon || '',
								defaultCategory: 'meta',
								onChange: (icon) => update(index, { icon }),
							})
							: el(TextControl, {
								label: __('Icon CSS Classes', 'mds-release-manager'),
								value: item.icon || '',
								onChange: (icon) => update(index, { icon }),
							}),
						el(TextControl, {
							label: __('Field Tooltip Label', 'mds-release-manager'),
							help: __('Changes the hover text and accessible description for this field, not the displayed metadata value.', 'mds-release-manager'),
							value: item.title || '',
							__next40pxDefaultSize: true,
							__nextHasNoMarginBottom: true,
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
						window.mdsRmIconPicker && window.mdsRmIconPicker.IconPickerControl
							? el(window.mdsRmIconPicker.IconPickerControl, {
								label: provider ? __('Provider Icon', 'mds-release-manager') : __('Block Icon', 'mds-release-manager'),
								help: provider
									? __('Used across the release editor, shortcodes, Elementor, and blocks unless overridden.', 'mds-release-manager')
									: __('Changes the icon for this link in blocks.', 'mds-release-manager'),
								value: provider ? (provider.icon || '') : (item.icon || ''),
								defaultCategory: isBuy ? 'commerce' : 'brands',
								onChange: (icon) => {
									if (provider) updateProvider(providerIndex, { icon });
									else updateItem(index, { icon });
								},
							})
							: el(TextControl, {
								label: __('Icon CSS Classes', 'mds-release-manager'),
								value: provider ? (provider.icon || '') : (item.icon || ''),
								onChange: (icon) => {
									if (provider) updateProvider(providerIndex, { icon });
									else updateItem(index, { icon });
								},
							}),
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
							__next40pxDefaultSize: true,
							__nextHasNoMarginBottom: true,
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
					__next40pxDefaultSize: true,
					__nextHasNoMarginBottom: true,
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

	const PLAYER_MAX_WIDTH_PRESETS = [
		{ label: __('Full Width (100%)', 'mds-release-manager'), value: '' },
		{ label: __('Compact (360px)', 'mds-release-manager'), value: '360px' },
		{ label: __('Medium (480px)', 'mds-release-manager'), value: '480px' },
		{ label: __('Wide (640px)', 'mds-release-manager'), value: '640px' },
		{ label: __('Custom…', 'mds-release-manager'), value: '__custom' },
	];

	function ReleasePlayerDefaults({ value, onChange, stylePanelProps }) {
		const [palette, customColor] = useSettings('color.palette', 'color.custom');
		const scheme = config.themeScheme || {};
		const providerOptions = [
			{ label: __('Auto (Priority Order)', 'mds-release-manager'), value: '' },
			...(Array.isArray(config.audioProviders) ? config.audioProviders : []).map((p) => ({
				label: p.label || p.slug,
				value: p.slug,
			})),
		];
		const boolDefault = (key, fallback) => (Object.prototype.hasOwnProperty.call(value, key) ? Boolean(value[key]) : fallback);
		const currentMaxWidth = value.maxWidth || '';
		const isPresetWidth = ['', '360px', '480px', '640px'].includes(currentMaxWidth);

		function colorField(key, label, fallbackHex) {
			const current = value[key] || '';
			return el('div', { className: 'mds-style-field mds-style-field--color' },
				el('span', { className: 'mds-style-field__label' }, label),
				customColor !== false && el(window.mdsRmColorControl, {
					label,
					value: current,
					palette,
					fallbackHint: fallbackHex ? sprintf(__('Auto (%s)', 'mds-release-manager'), fallbackHex) : __('Theme default', 'mds-release-manager'),
					onChange: (setting) => onChange({ [key]: setting || undefined }),
				})
			);
		}

		return el(Fragment, null,
			el(PanelBody, { title: __('Player Modules & Source', 'mds-release-manager'), ...stylePanelProps('modules') },
				el(SectionInfo, {
					title: __('About Release Player Modules', 'mds-release-manager'),
					summary: __('Configure default visibility for Release Player modules and the default audio source priority across all Release Player blocks and shortcodes.', 'mds-release-manager'),
					inheritance: __('Individual Release Player blocks can override any setting below in the block inspector.', 'mds-release-manager'),
				}),
				el(ControlGroup, { title: __('Audio Source & Layout', 'mds-release-manager'), twoCol: true },
					el(SelectControl, {
						label: __('Default Audio Provider', 'mds-release-manager'),
						help: __('Auto uses the priority order configured under the Audio Providers tab (or falls back to the built-in HTML5 preview).', 'mds-release-manager'),
						value: value.provider || '',
						options: providerOptions,
						onChange: (provider) => onChange({ provider: provider || undefined }),
						__next40pxDefaultSize: true,
						__nextHasNoMarginBottom: true,
					}),
					el(ToggleControl, {
						label: __('Compact Bar Layout', 'mds-release-manager'),
						help: __('Uses a condensed single-row player layout by default.', 'mds-release-manager'),
						checked: boolDefault('compact', false),
						onChange: (compact) => onChange({ compact }),
						__nextHasNoMarginBottom: true,
					})
				),
				el(ControlGroup, { title: __('Visible Modules', 'mds-release-manager'), twoCol: true },
					el(ToggleControl, {
						label: __('Show Artwork', 'mds-release-manager'),
						checked: boolDefault('showArtwork', true),
						onChange: (showArtwork) => onChange({ showArtwork }),
						__nextHasNoMarginBottom: true,
					}),
					el(ToggleControl, {
						label: __('Show Title & Artist', 'mds-release-manager'),
						checked: boolDefault('showMeta', true),
						onChange: (showMeta) => onChange({ showMeta }),
						__nextHasNoMarginBottom: true,
					}),
					el(ToggleControl, {
						label: __('Show Progress Bar', 'mds-release-manager'),
						checked: boolDefault('showProgress', true),
						onChange: (showProgress) => onChange({ showProgress }),
						__nextHasNoMarginBottom: true,
					}),
					el(ToggleControl, {
						label: __('Show Time Readout', 'mds-release-manager'),
						checked: boolDefault('showTime', true),
						onChange: (showTime) => onChange({ showTime }),
						__nextHasNoMarginBottom: true,
					}),
					el(ToggleControl, {
						label: __('Show Tracklist', 'mds-release-manager'),
						checked: boolDefault('showTracklist', false),
						onChange: (showTracklist) => onChange({ showTracklist }),
						__nextHasNoMarginBottom: true,
					}),
					el(ToggleControl, {
						label: __('Show Provider Badge', 'mds-release-manager'),
						checked: boolDefault('showProviderBadge', true),
						onChange: (showProviderBadge) => onChange({ showProviderBadge }),
						__nextHasNoMarginBottom: true,
					})
				)
			),
			el(PanelBody, { title: __('Preview & Sampler Playback', 'mds-release-manager'), ...stylePanelProps('playback') },
				el(SectionInfo, {
					title: __('About Preview & Sampler Playback', 'mds-release-manager'),
					summary: __('Controls default preview clip duration and automatic crossfade between tracks when Sampler Mode is enabled.', 'mds-release-manager'),
				}),
				el(ControlGroup, { title: __('Sampler Controls', 'mds-release-manager'), twoCol: true },
					el(ToggleControl, {
						label: __('Enable Sampler Mode', 'mds-release-manager'),
						help: __('Automatically advances and crossfades across tracks.', 'mds-release-manager'),
						checked: boolDefault('samplerMode', true),
						onChange: (samplerMode) => onChange({ samplerMode }),
						__nextHasNoMarginBottom: true,
					}),
					el(RangeControl, {
						label: __('Preview Clip Length (seconds)', 'mds-release-manager'),
						help: __('Default: 20s. Applied to full-length streams; native timed previews play as provided.', 'mds-release-manager'),
						min: 10,
						max: 60,
						step: 5,
						allowReset: true,
						value: typeof value.previewSeconds === 'number' ? value.previewSeconds : undefined,
						onChange: (previewSeconds) => onChange({ previewSeconds: previewSeconds === undefined ? undefined : Math.round(previewSeconds) }),
						__next40pxDefaultSize: true,
						__nextHasNoMarginBottom: true,
					}),
					el(RangeControl, {
						label: __('Crossfade Duration (ms)', 'mds-release-manager'),
						help: __('Default: 1500ms.', 'mds-release-manager'),
						min: 0,
						max: 4000,
						step: 100,
						allowReset: true,
						value: typeof value.crossfadeMs === 'number' ? value.crossfadeMs : undefined,
						onChange: (crossfadeMs) => onChange({ crossfadeMs: crossfadeMs === undefined ? undefined : Math.round(crossfadeMs) }),
						__next40pxDefaultSize: true,
						__nextHasNoMarginBottom: true,
					})
				)
			),
			el(PanelBody, { title: __('Colors & Surface Styling', 'mds-release-manager'), ...stylePanelProps('styles') },
				el(SectionInfo, {
					title: __('About Release Player Styling', 'mds-release-manager'),
					summary: __('Sets site-wide CSS custom property defaults for the Release Player card, typography, accents, border, and elevation.', 'mds-release-manager'),
					inheritance: __('Leave any color unset to automatically adapt to your active theme color scheme.', 'mds-release-manager'),
				}),
				el(ControlGroup, { title: __('Player Palette', 'mds-release-manager'), twoCol: true },
					colorField('backgroundColor', __('Background', 'mds-release-manager'), scheme.surface),
					colorField('textColor', __('Text', 'mds-release-manager'), scheme.text),
					colorField('mutedColor', __('Muted Text', 'mds-release-manager'), scheme.muted),
					colorField('accentColor', __('Accent / Play Button', 'mds-release-manager'), scheme.accent),
					colorField('playIconColor', __('Play Icon', 'mds-release-manager'), scheme.accentText),
					colorField('borderColor', __('Border', 'mds-release-manager'), scheme.border)
				),
				el(ControlGroup, { title: __('Surface, Border & Sizing', 'mds-release-manager'), twoCol: true },
					el(RangeControl, {
						label: __('Corner Radius (px)', 'mds-release-manager'),
						help: __('Default: 10px.', 'mds-release-manager'),
						min: 0,
						max: 32,
						step: 1,
						allowReset: true,
						value: typeof value.borderRadius === 'number' ? value.borderRadius : undefined,
						onChange: (borderRadius) => onChange({ borderRadius: borderRadius === undefined ? undefined : Math.round(borderRadius) }),
						__next40pxDefaultSize: true,
						__nextHasNoMarginBottom: true,
					}),
					el(RangeControl, {
						label: __('Border Width (px)', 'mds-release-manager'),
						help: __('Default: 1px.', 'mds-release-manager'),
						min: 0,
						max: 6,
						step: 1,
						allowReset: true,
						value: typeof value.borderWidth === 'number' ? value.borderWidth : undefined,
						onChange: (borderWidth) => onChange({ borderWidth: borderWidth === undefined ? undefined : Math.round(borderWidth) }),
						__next40pxDefaultSize: true,
						__nextHasNoMarginBottom: true,
					}),
					el(RangeControl, {
						label: __('Inner Padding (px)', 'mds-release-manager'),
						help: __('Default: 12px.', 'mds-release-manager'),
						min: 0,
						max: 32,
						step: 1,
						allowReset: true,
						value: typeof value.padding === 'number' ? value.padding : undefined,
						onChange: (padding) => onChange({ padding: padding === undefined ? undefined : Math.round(padding) }),
						__next40pxDefaultSize: true,
						__nextHasNoMarginBottom: true,
					}),
					el(SelectControl, {
						label: __('Max Width', 'mds-release-manager'),
						value: isPresetWidth ? currentMaxWidth : '__custom',
						options: PLAYER_MAX_WIDTH_PRESETS,
						onChange: (preset) => onChange({ maxWidth: preset === '__custom' ? '520px' : (preset || undefined) }),
						__next40pxDefaultSize: true,
						__nextHasNoMarginBottom: true,
					}),
					!isPresetWidth && el(TextControl, {
						label: __('Custom Max Width', 'mds-release-manager'),
						help: __('Accepts px, rem, em, %, or vw (e.g. 520px or 36rem).', 'mds-release-manager'),
						value: currentMaxWidth,
						onChange: (maxWidth) => onChange({ maxWidth: maxWidth.trim() || undefined }),
						__next40pxDefaultSize: true,
						__nextHasNoMarginBottom: true,
					}),
					el(SelectControl, {
						label: __('Elevation Shadow', 'mds-release-manager'),
						value: value.shadow || '',
						options: [
							{ label: __('Default (None)', 'mds-release-manager'), value: '' },
							{ label: __('None (Flat)', 'mds-release-manager'), value: 'none' },
							{ label: __('Subtle', 'mds-release-manager'), value: 'subtle' },
							{ label: __('Elevated', 'mds-release-manager'), value: 'elevated' },
						],
						onChange: (shadow) => onChange({ shadow: shadow || undefined }),
						__next40pxDefaultSize: true,
						__nextHasNoMarginBottom: true,
					})
				),
				!!Object.keys(value).length && el('div', { className: 'mds-reset-row' },
					el(Button, {
						variant: 'tertiary',
						isDestructive: true,
						onClick: () => {
							const clear = {};
							Object.keys(value).forEach((k) => { clear[k] = undefined; });
							onChange(clear);
						},
					}, __('Reset all Release Player defaults', 'mds-release-manager'))
				)
			)
		);
	}

	const SAMPLE_META_VALUES = {
		release_year: '2025',
		record_label: 'Midnight Records',
		distributor: 'DistroKid',
		upc_ean: '012345678901',
		release_type: 'Album',
		release_media_format: 'Vinyl LP, CD, Digital',
		release_date: 'Oct 24, 2025',
		release_title: 'Neon Horizons',
		artist: 'The Midnight Drive',
		release_tags: 'Synthwave, Electronic',
	};

	const SAMPLE_DISCOGRAPHY = [
		{ year: '2025', title: 'Neon Horizons', format: 'Album', label: 'Midnight Records' },
		{ year: '2023', title: 'Starlight Overdrive', format: 'EP', label: 'Midnight Records' },
		{ year: '2021', title: 'Afterglow', format: 'Single', label: 'Independent' },
	];

	function cssVarsFromStyles(styles, prefix) {
		const vars = {};
		if (!styles || typeof styles !== 'object') return vars;
		Object.entries(styles).forEach(([key, val]) => {
			if (val === null || val === undefined || val === '') return;
			const prop = `--mds-${prefix}-${key.replace(/_/g, '-')}-desktop`;
			if (typeof val === 'object') {
				const sides = ['top', 'right', 'bottom', 'left'].map((s) => val[s] || '');
				if (sides.some(Boolean)) {
					vars[prop] = sides.map((s) => s || '0').join(' ');
				}
			} else {
				vars[prop] = String(val);
			}
		});
		return vars;
	}

	function ReleaseMetaPreview({ value }) {
		const items = (Array.isArray(value.items) && value.items.length ? value.items : config.metaItems) || [];
		const visible = items.filter((item) => item && item.enabled !== false && config.metaFields[item.field]);
		const styleVars = cssVarsFromStyles(value.metaStyles, 'meta');

		if (!visible.length) {
			return el('p', { className: 'mds-bd-preview-empty' }, __('All metadata fields are currently hidden. Enable one or more fields under Fields & Ordering to preview.', 'mds-release-manager'));
		}

		return el('div', { className: 'wp-block-mds-rm-release-meta', style: styleVars },
			el('div', { className: 'mds-release-meta' },
				visible.map((item) => {
					const sample = SAMPLE_META_VALUES[item.field] || config.metaFields[item.field]?.label || item.field;
					const tooltip = item.title || config.metaFields[item.field]?.label || '';
					return el('span', { key: item.field, className: 'mds-release-meta__item', title: tooltip },
						item.icon && el('i', { className: `mds-release-meta__icon ${item.icon}`, 'aria-hidden': true }),
						el('span', null, sample)
					);
				})
			)
		);
	}

	function ReleaseLinksPreview({ value, providers }) {
		const list = Array.isArray(providers) ? providers : [];
		const fallback = list.map((p) => ({ name: p.name, enabled: true })).concat([{ name: '__buy_now', enabled: true }]);
		const items = (Array.isArray(value.items) && value.items.length ? value.items : fallback).slice();
		fallback.forEach((item) => {
			if (!items.some((existing) => existing.name.toLowerCase() === item.name.toLowerCase())) items.push(item);
		});
		const visible = items.filter((item) => item && item.enabled !== false);
		const styleVars = cssVarsFromStyles(value.linkStyles, 'links');
		const newTabText = value.newTab !== false
			? __('Opens in new tab', 'mds-release-manager')
			: __('Opens in same tab', 'mds-release-manager');
		const buyModeText = (value.buyNowMode || 'picker') === 'direct'
			? __('Buy Now: Primary Direct Link', 'mds-release-manager')
			: __('Buy Now: Choose Format Modal', 'mds-release-manager');

		if (!visible.length) {
			return el('p', { className: 'mds-bd-preview-empty' }, __('All link buttons are currently hidden. Enable one or more providers to preview.', 'mds-release-manager'));
		}

		return el(Fragment, null,
			el('div', { className: 'wp-block-mds-rm-release-links', style: styleVars },
				el('div', { className: 'mds-release-links' },
					el('div', { className: 'mds-release-links__buttons' },
						visible.map((item) => {
							const isBuy = item.name === '__buy_now';
							const provider = list.find((p) => p.name.toLowerCase() === item.name.toLowerCase());
							const icon = (item.icon && item.icon.trim()) || (provider && provider.icon) || (isBuy ? 'fas fa-shopping-cart' : 'fas fa-link');
							const bg = isBuy ? '#2271b1' : ((provider && provider.color) || '#333333');
							const label = item.title || (isBuy ? __('Buy Now', 'mds-release-manager') : item.name);
							return el('a', {
								key: item.name,
								href: '#preview',
								className: `mds-release-links__link${isBuy ? ' mds-release-links__link--buy' : ''}`,
								style: { '--mds-links-provider-background': bg },
								title: label,
								onClick: (e) => e.preventDefault(),
							}, el('i', { className: icon, 'aria-hidden': true }));
						})
					)
				)
			),
			el('p', { className: 'mds-bd-preview-caption' }, `${newTabText} · ${buyModeText}`)
		);
	}

	function ReleasePlayerPreview({ value }) {
		const boolDefault = (key, fallback) => (Object.prototype.hasOwnProperty.call(value, key) ? Boolean(value[key]) : fallback);
		const showArtwork = boolDefault('showArtwork', true);
		const showMeta = boolDefault('showMeta', true);
		const showProgress = boolDefault('showProgress', true);
		const showTime = boolDefault('showTime', true);
		const showTracklist = boolDefault('showTracklist', false);
		const showProviderBadge = boolDefault('showProviderBadge', true);
		const samplerMode = boolDefault('samplerMode', true);
		const compact = boolDefault('compact', false);
		const previewSecs = typeof value.previewSeconds === 'number' ? value.previewSeconds : 20;
		const crossfadeMs = typeof value.crossfadeMs === 'number' ? value.crossfadeMs : 1500;
		const shadow = value.shadow || 'none';

		const vars = {};
		if (value.backgroundColor) vars['--mds-player-bg'] = value.backgroundColor;
		if (value.textColor) vars['--mds-player-text'] = value.textColor;
		if (value.mutedColor) vars['--mds-player-muted'] = value.mutedColor;
		if (value.accentColor) vars['--mds-player-accent'] = value.accentColor;
		if (value.playIconColor) vars['--mds-player-play-icon'] = value.playIconColor;
		if (value.borderColor) vars['--mds-player-border'] = value.borderColor;
		if (typeof value.borderRadius === 'number') vars['--mds-player-radius'] = `${value.borderRadius}px`;
		if (typeof value.borderWidth === 'number') vars['--mds-player-border-width'] = `${value.borderWidth}px`;
		if (typeof value.padding === 'number') vars['--mds-player-padding'] = `${value.padding}px`;
		if (value.maxWidth) vars['--mds-player-max-width'] = value.maxWidth;

		const classes = ['mds-rm-player'];
		if (compact) classes.push('mds-rm-player--compact');
		if (shadow === 'subtle' || shadow === 'elevated') classes.push(`mds-rm-player--shadow-${shadow}`);

		const providerList = Array.isArray(config.audioProviders) ? config.audioProviders : [];
		const selectedProvider = value.provider
			? (providerList.find((p) => p.slug === value.provider)?.label || value.provider)
			: (providerList[0]?.label || __('Built-in HTML5 Preview', 'mds-release-manager'));

		const formatSecs = (secs) => `0:${String(secs).padStart(2, '0')}`;

		return el('div', { className: classes.join(' '), style: vars },
			el('div', { className: 'mds-rm-player__main' },
				showArtwork && el('div', { className: 'mds-rm-player__art' },
					el('div', { className: 'mds-bd-preview-art', 'aria-hidden': true },
						el('span', { className: 'mds-brand-icon' })
					)
				),
				el('div', { className: 'mds-rm-player__controls' },
					el('button', {
						type: 'button',
						className: 'mds-rm-player__btn mds-rm-player__btn--play',
						'aria-label': __('Play preview', 'mds-release-manager'),
						onClick: (e) => e.preventDefault(),
					}, el('span', { className: 'mds-rm-player__icon mds-rm-player__icon--play', 'aria-hidden': true }, '\u25B6'))
				),
				el('div', { className: 'mds-rm-player__body' },
					showMeta && el('div', { className: 'mds-rm-player__meta' },
						el('span', { className: 'mds-rm-player__title' }, 'Neon Horizons'),
						el('span', { className: 'mds-rm-player__artist' }, 'The Midnight Drive')
					),
					(showProgress || showTime) && el('div', { className: 'mds-rm-player__timeline' },
						showProgress && el('input', {
							type: 'range',
							className: 'mds-rm-player__scrubber',
							min: 0,
							max: 100,
							value: 35,
							readOnly: true,
							tabIndex: -1,
							'aria-hidden': true,
						}),
						showTime && el('span', { className: 'mds-rm-player__time', 'aria-hidden': true }, `0:11 / ${formatSecs(previewSecs)}`)
					)
				),
				showTracklist && el('button', {
					type: 'button',
					className: 'mds-rm-player__btn mds-rm-player__btn--tracks',
					'aria-label': __('Toggle tracklist', 'mds-release-manager'),
					onClick: (e) => e.preventDefault(),
				}, el('span', { 'aria-hidden': true }, '\u2630'))
			),
			showTracklist && el('ol', { className: 'mds-rm-player__tracklist' },
				el('li', { className: 'mds-rm-player__track is-active' },
					el('span', { className: 'mds-rm-player__track-num' }, '1.'),
					el('span', { className: 'mds-rm-player__track-title' }, 'Neon Horizons'),
					el('span', { className: 'mds-rm-player__track-dur' }, formatSecs(previewSecs))
				),
				el('li', { className: 'mds-rm-player__track' },
					el('span', { className: 'mds-rm-player__track-num' }, '2.'),
					el('span', { className: 'mds-rm-player__track-title' }, 'Night Runner'),
					el('span', { className: 'mds-rm-player__track-dur' }, formatSecs(previewSecs))
				)
			),
			showProviderBadge && el('div', { className: 'mds-rm-player__footer' },
				el('span', { className: 'mds-rm-player__badge' },
					// translators: %s: Audio provider name.
					sprintf(__('Preview via %s', 'mds-release-manager'), selectedProvider),
					samplerMode ? ` · ${sprintf(__('Sampler (%dms fade)', 'mds-release-manager'), crossfadeMs)}` : ''
				)
			)
		);
	}

	function ArtistDiscographyPreview({ value }) {
		const wrapper = value.style || {};
		const table = value.tableStyles || {};
		const outerStyle = {};

		if (wrapper.color?.background) outerStyle.backgroundColor = wrapper.color.background;
		if (wrapper.color?.text) outerStyle.color = wrapper.color.text;
		if (wrapper.typography?.fontFamily) outerStyle.fontFamily = wrapper.typography.fontFamily;
		if (wrapper.typography?.fontSize) outerStyle.fontSize = wrapper.typography.fontSize;
		if (wrapper.typography?.fontWeight) outerStyle.fontWeight = wrapper.typography.fontWeight;
		if (wrapper.typography?.fontStyle) outerStyle.fontStyle = wrapper.typography.fontStyle;
		if (wrapper.typography?.lineHeight) outerStyle.lineHeight = wrapper.typography.lineHeight;
		if (wrapper.typography?.letterSpacing) outerStyle.letterSpacing = wrapper.typography.letterSpacing;
		if (wrapper.border?.style) outerStyle.borderStyle = wrapper.border.style;
		if (wrapper.border?.width) outerStyle.borderWidth = wrapper.border.width;
		if (wrapper.border?.color) outerStyle.borderColor = wrapper.border.color;
		if (wrapper.border?.radius) outerStyle.borderRadius = wrapper.border.radius;

		['margin', 'padding'].forEach((prop) => {
			const val = wrapper.spacing?.[prop];
			if (!val) return;
			if (typeof val === 'string') outerStyle[prop] = val;
			else if (typeof val === 'object') {
				const sides = ['top', 'right', 'bottom', 'left'].map((s) => val[s] || '');
				if (sides.some(Boolean)) outerStyle[prop] = sides.map((s) => s || '0').join(' ');
			}
		});

		Object.entries(table).forEach(([key, val]) => {
			if (key === 'striped' || val === null || val === undefined || val === '') return;
			outerStyle[`--mds-discography-${key}`] = String(val);
		});
		if (table.striped && !table['stripe-background']) {
			outerStyle['--mds-discography-stripe-background'] = 'rgba(127, 127, 127, 0.08)';
		}
		if (wrapper.elements?.link?.color?.text && !table['link-color']) {
			outerStyle['--mds-discography-link-color'] = wrapper.elements.link.color.text;
		}

		return el('div', { className: 'wp-block-mds-rm-artist-discography', style: outerStyle },
			el('div', { className: 'mds-discography' },
				el('div', { className: 'mds-discography__scroll' },
					el('table', { className: 'mds-bd-preview-table' },
						el('thead', null,
							el('tr', null,
								el('th', null, __('Year', 'mds-release-manager')),
								el('th', null, __('Release', 'mds-release-manager')),
								el('th', null, __('Format', 'mds-release-manager')),
								el('th', null, __('Label', 'mds-release-manager'))
							)
						),
						el('tbody', null,
							SAMPLE_DISCOGRAPHY.map((row, idx) =>
								el('tr', {
									key: row.title,
									style: table.striped && idx % 2 === 1
										? { backgroundColor: outerStyle['--mds-discography-stripe-background'] }
										: undefined,
								},
									el('td', null, row.year),
									el('td', null, el('a', {
										href: '#preview',
										onClick: (e) => e.preventDefault(),
										style: outerStyle['--mds-discography-link-color']
											? { color: outerStyle['--mds-discography-link-color'], textDecoration: table['link-decoration'] || undefined }
											: { textDecoration: table['link-decoration'] || undefined },
									}, row.title)),
									el('td', null, row.format),
									el('td', null, row.label)
								)
							)
						)
					)
				)
			)
		);
	}

	const BLOCK_TITLES = {
		'release-meta': __('Release Meta', 'mds-release-manager'),
		'release-links': __('Release Links', 'mds-release-manager'),
		'release-player': __('Release Player', 'mds-release-manager'),
		'artist-discography': __('Artist Discography', 'mds-release-manager'),
	};

	function BlockLivePreview({ slug, value, providers, canvasMode }) {
		const scheme = config.themeScheme || {};
		const isDark = canvasMode === 'dark' || (canvasMode === 'theme' && Boolean(scheme.isDark));
		const stageStyle = canvasMode === 'theme' && scheme.background
			? {
				backgroundColor: scheme.background,
				color: scheme.text || (scheme.isDark ? '#f9fafb' : '#1d2327'),
				borderColor: scheme.border || undefined,
			}
			: undefined;
		const canvasLabel = canvasMode === 'theme'
			// translators: %s: Active theme name.
			? sprintf(__('Active Theme (%s)', 'mds-release-manager'), scheme.themeName || (isDark ? __('Dark', 'mds-release-manager') : __('Light', 'mds-release-manager')))
			: (isDark ? __('Dark canvas', 'mds-release-manager') : __('Light canvas', 'mds-release-manager'));

		return el('aside', {
			id: `mds-bd-live-preview-${slug}`,
			className: 'mds-tpl-preview-col',
			role: 'region',
			// translators: %s: Block name.
			'aria-label': sprintf(__('%s Live Preview', 'mds-release-manager'), BLOCK_TITLES[slug] || slug),
		},
			el('div', { className: 'mds-tpl-preview-card' },
				el('div', { className: 'mds-tpl-preview-card__header' },
					el('strong', null, sprintf(__('Live Preview — %s', 'mds-release-manager'), BLOCK_TITLES[slug] || slug)),
					el('span', { className: 'mds-tpl-preview-card__hint' }, canvasLabel)
				),
				el('div', { className: `mds-bd-preview-stage${isDark ? ' is-dark' : ''}`, style: stageStyle },
					slug === 'release-meta' && el(ReleaseMetaPreview, { value }),
					slug === 'release-links' && el(ReleaseLinksPreview, { value, providers }),
					slug === 'release-player' && el(ReleasePlayerPreview, { value }),
					slug === 'artist-discography' && el(ArtistDiscographyPreview, { value })
				),
				el('p', { className: 'mds-bd-preview-caption' },
					__('Updates in real time as you adjust controls. Click Save Settings to apply site-wide.', 'mds-release-manager')
				)
			)
		);
	}

	function Defaults({ slug, isPreviewOpen, onTogglePreview, canvasMode, onCycleCanvas }) {
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
		const isPlayer = slug === 'release-player';
		const isDiscography = slug === 'artist-discography';
		const canvasBtnLabel = canvasMode === 'theme'
			? __('Canvas: Active Theme', 'mds-release-manager')
			: (canvasMode === 'dark' ? __('Canvas: Dark', 'mds-release-manager') : __('Canvas: Light', 'mds-release-manager'));

		return el(BlockEditorProvider, { settings: config.editorSettings, value: emptyBlocks, onInput: () => {}, onChange: () => {} },
			el('div', { className: 'mds-bd-toolbar' },
				el(AccordionToolbar, {
					openCount,
					totalCount: panelList.length,
					onExpandAll: expandAll,
					onCollapseAll: collapseAll,
				}),
				el('div', { className: 'mds-bd-toolbar__actions' },
					isPreviewOpen && el(Button, {
						variant: 'tertiary',
						size: 'compact',
						onClick: onCycleCanvas,
					}, canvasBtnLabel),
					el(Button, {
						variant: 'secondary',
						size: 'compact',
						'aria-expanded': isPreviewOpen,
						'aria-controls': `mds-bd-live-preview-${slug}`,
						onClick: onTogglePreview,
					}, isPreviewOpen ? __('Hide Live Preview', 'mds-release-manager') : __('Show Live Preview', 'mds-release-manager'))
				)
			),
			el('div', { className: `mds-bd-workspace${isPreviewOpen ? ' has-preview' : ''}` },
				el('div', { className: 'mds-bd-controls-col' },
					isMeta && el(Items, { value, onChange: change, panelProps: stylePanelProps('items') }),
					isLinks && el(LinkProviders, { value, onChange: change, providers, onProvidersChange: changeProviders, panelProps: stylePanelProps('items') }),
					isLinks && el(PanelBody, { title: __('Behavior & Display', 'mds-release-manager'), ...stylePanelProps('behavior') },
						el(SectionInfo, {
							title: __('About Behavior & Display', 'mds-release-manager'),
							summary: __('Controls link target behavior when visitors click a streaming or Buy Now button.', 'mds-release-manager'),
							inheritance: __('Applies site-wide to all Release Links blocks unless overridden on an individual block.', 'mds-release-manager'),
						}),
						el(ControlGroup, { title: __('Interaction Defaults', 'mds-release-manager'), twoCol: true },
							el(ToggleControl, {
								label: __('Open Links in a New Tab', 'mds-release-manager'),
								help: __('Adds target="_blank" and rel="noopener noreferrer" to streaming and Buy Now links.', 'mds-release-manager'),
								checked: value.newTab !== false,
								__nextHasNoMarginBottom: true,
								onChange: (newTab) => change({ newTab }),
							}),
							el(SelectControl, {
								label: __('Multi-Format Buy Now Behavior', 'mds-release-manager'),
								help: __('When a release has multiple Media Formats and no WooCommerce White-Label override is active, choose whether clicking Buy Now opens a format picker modal or links directly to the Primary format.', 'mds-release-manager'),
								value: value.buyNowMode || 'picker',
								__next40pxDefaultSize: true,
								__nextHasNoMarginBottom: true,
								options: [
									{ label: __('Choose Format Modal (Recommended)', 'mds-release-manager'), value: 'picker' },
									{ label: __('Primary Direct Link', 'mds-release-manager'), value: 'direct' },
								],
								onChange: (buyNowMode) => change({ buyNowMode }),
							})
						)
					),
					isPlayer && el(ReleasePlayerDefaults, { value, onChange: change, stylePanelProps }),
					isMeta && el(window.mdsRmReleaseMetaControls, { standalone: true, stylePanelProps, attributes: value, setAttributes: change }),
					isLinks && el(window.mdsRmReleaseLinksControls, { standalone: true, stylePanelProps, attributes: value, setAttributes: change }),
					isMeta && value.metaStyles && el('div', { className: 'mds-reset-row' },
						el(Button, { variant: 'tertiary', isDestructive: true, onClick: () => change({ metaStyles: undefined }) }, __('Reset Release Meta styles', 'mds-release-manager'))
					),
					isLinks && value.linkStyles && el('div', { className: 'mds-reset-row' },
						el(Button, { variant: 'tertiary', isDestructive: true, onClick: () => change({ linkStyles: undefined }) }, __('Reset Release Links styles', 'mds-release-manager'))
					),
					isDiscography && el(WrapperStyles, { panelProps: stylePanelProps('outer'), value: value.style || {}, onChange: (style) => change({ style }) }),
					isDiscography && el(window.mdsRmDiscographyTableControls, { standalone: true, stylePanelProps, value: value.tableStyles || {}, onChange: (tableStyles) => change({ tableStyles }) })
				),
				isPreviewOpen && el(BlockLivePreview, { slug, value, providers, canvasMode })
			)
		);
	}

	function BlockDefaultsTabs() {
		const [isPreviewOpen, setIsPreviewOpen] = useState(false);
		const [canvasMode, setCanvasMode] = useState('theme');
		const [presetRevision, setPresetRevision] = useState(0);

		function togglePreview() {
			const next = !isPreviewOpen;
			setIsPreviewOpen(next);
			speak(next ? __('Live preview shown.', 'mds-release-manager') : __('Live preview hidden.', 'mds-release-manager'), 'polite');
		}

		function cycleCanvas() {
			const order = ['theme', 'light', 'dark'];
			const next = order[(order.indexOf(canvasMode) + 1) % order.length];
			setCanvasMode(next);
			speak(sprintf(__('Preview canvas switched to %s.', 'mds-release-manager'), next), 'polite');
		}

		function resetColorOverrides() {
			const nextAll = { ...all };
			const colorKeys = {
				'release-meta': { nested: 'metaStyles', keys: ['background', 'color', 'icon_color', 'border_color'] },
				'release-links': { nested: 'linkStyles', keys: ['background', 'color', 'border_color', 'button_background', 'button_color', 'button_border_color'] },
				'release-player': { keys: ['backgroundColor', 'textColor', 'mutedColor', 'accentColor', 'playIconColor', 'borderColor'] },
				'artist-discography': { nested: 'tableStyles', keys: ['header-background', 'header-color', 'body-background', 'body-color', 'stripe-background', 'cell-border-color', 'link-color'] },
			};
			Object.entries(colorKeys).forEach(([slug, spec]) => {
				if (!nextAll[slug] || typeof nextAll[slug] !== 'object') return;
				const current = { ...nextAll[slug] };
				if (spec.nested && current[spec.nested] && typeof current[spec.nested] === 'object') {
					const nestedCopy = { ...current[spec.nested] };
					spec.keys.forEach((k) => delete nestedCopy[k]);
					if (Object.keys(nestedCopy).length) current[spec.nested] = nestedCopy;
					else delete current[spec.nested];
				} else if (spec.keys) {
					spec.keys.forEach((k) => delete current[k]);
				}
				if (slug === 'artist-discography' && current.style && current.style.color) {
					const styleCopy = { ...current.style };
					delete styleCopy.color;
					if (Object.keys(styleCopy).length) current.style = styleCopy;
					else delete current.style;
				}
				if (Object.keys(current).length) nextAll[slug] = current;
				else delete nextAll[slug];
			});
			all = nextAll;
			input.value = JSON.stringify(all);
			input.dispatchEvent(new Event('change', { bubbles: true }));
			setPresetRevision((r) => r + 1);
			setCanvasMode('theme');
			speak(__('Reset block color overrides to the detected theme baseline. Click Save Settings to persist.', 'mds-release-manager'), 'polite');
		}

		const tabs = [
			{ name: 'release-meta', title: __('Release Meta', 'mds-release-manager') },
			{ name: 'release-links', title: __('Release Links', 'mds-release-manager') },
			{ name: 'release-player', title: __('Release Player', 'mds-release-manager') },
			{ name: 'artist-discography', title: __('Artist Discography', 'mds-release-manager') },
		];
		return el(Fragment, null,
			el(ThemeSchemeBar, { scheme: config.themeScheme || {}, onResetColors: resetColorOverrides }),
			el(TabPanel, {
				className: 'mds-block-defaults-tabs',
				activeClass: 'is-active',
				tabs,
			}, (tab) => el('div', {
				className: 'mds-block-defaults-tab-content',
				'data-block-tab': tab.name,
			}, el(Defaults, {
				key: `${tab.name}-${presetRevision}`,
				slug: tab.name,
				isPreviewOpen,
				onTogglePreview: togglePreview,
				canvasMode,
				onCycleCanvas: cycleCanvas,
			})))
		);
	}

	const blockDefaultsTarget = document.getElementById('mds-block-defaults-root');
	if (blockDefaultsTarget) {
		const element = el(BlockDefaultsTabs);
		if (wp.element.createRoot) wp.element.createRoot(blockDefaultsTarget).render(element);
		else wp.element.render(element, blockDefaultsTarget);
	}
})(window.wp);
