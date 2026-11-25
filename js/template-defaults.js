(function (wp) {
	const { createElement: el, useState } = wp.element;
	const { BlockEditorProvider, useSettings } = wp.blockEditor;
	const {
		Button,
		PanelBody,
		RangeControl,
		SelectControl,
		TextControl,
		ToggleControl,
		__experimentalToggleGroupControl: ToggleGroupControl,
		__experimentalToggleGroupControlOption: ToggleGroupControlOption,
	} = wp.components;
	const { __, sprintf } = wp.i18n;
	const speak = wp.a11y && typeof wp.a11y.speak === 'function' ? wp.a11y.speak : () => {};

	const config = window.mdsRmTemplateDefaultsData;
	const target = document.getElementById('mds-default-templates-root');
	const hiddenInput = document.getElementById('mds-rm-template-defaults');
	if (!config || !target || !hiddenInput || !window.mdsRmStylePrimitives) {
		return;
	}

	const { SectionInfo, ControlGroup, AccordionToolbar } = window.mdsRmStylePrimitives;
	const emptyBlocks = [];

	const SHADOW_MAP = {
		release: {
			none: 'none',
			subtle: '0 2px 6px rgba(0, 0, 0, .08)',
			default: '0 4px 12px rgba(0, 0, 0, .12)',
			elevated: '0 12px 28px rgba(0, 0, 0, .18)',
		},
		artist: {
			none: 'none',
			subtle: '0 2px 6px rgba(0, 0, 0, .08)',
			default: '0 4px 12px rgba(0, 0, 0, .1)',
			elevated: '0 12px 28px rgba(0, 0, 0, .18)',
		},
	};

	const RELEASE_PANELS = ['layout', 'image', 'components', 'typography'];
	const ARTIST_PANELS = ['layout', 'portrait', 'biography', 'discography', 'contact'];

	function presetChoices(list, prefix) {
		const entries = Array.isArray(list) ? list : ['default', 'theme', 'custom'].flatMap((origin) => list?.[origin] || []);
		return Array.from(new Map(entries.map(({ slug, name }) => [slug, { label: name, value: `var(--wp--preset--${prefix}--${slug})` }])).values());
	}

	function SegmentedControl({ label, help, value, options, onChange }) {
		if (ToggleGroupControl && ToggleGroupControlOption) {
			return el('div', { className: 'mds-style-field' },
				el(ToggleGroupControl, {
					label,
					help,
					value,
					isBlock: true,
					__next40pxDefaultSize: true,
					__nextHasNoMarginBottom: true,
					onChange: (next) => {
						if (next !== undefined && next !== null) onChange(String(next));
					},
				},
				options.map((opt) => el(ToggleGroupControlOption, { key: opt.value, value: opt.value, label: opt.label }))
				)
			);
		}
		return el('div', { className: 'mds-style-field' },
			el(SelectControl, {
				label,
				help,
				value,
				options,
				onChange,
			})
		);
	}

	function ColorField({ label, help, value, onChange, palette, customColor, fallbackHint }) {
		return el('div', { className: 'mds-style-field mds-style-field--color' },
			el('span', { className: 'mds-style-field__label' }, label),
			customColor !== false && window.mdsRmColorControl && el(window.mdsRmColorControl, {
				label,
				value: value || '',
				palette,
				fallbackHint,
				onChange: (next) => onChange(next || ''),
			}),
			help && el('p', { className: 'mds-style-field__help' }, help)
		);
	}

	function resolveCanvasPalette(canvasMode, scheme) {
		if (canvasMode === 'dark') {
			return { isDark: true, bg: '#111827', text: '#f3f4f6', surface: '#1f2937', border: '#374151', accent: '#7dd3fc' };
		}
		if (canvasMode === 'light') {
			return { isDark: false, bg: '#ffffff', text: '#1d2327', surface: '#f4f4f0', border: '#e2e4e7', accent: '#2271b1' };
		}
		return {
			isDark: Boolean(scheme.isDark),
			bg: scheme.background || '#ffffff',
			text: scheme.text || '#1d2327',
			surface: scheme.surface || '#f4f4f0',
			border: scheme.border || '#e2e4e7',
			accent: scheme.accent || '#2271b1',
		};
	}

	function ReleaseLivePreview({ s, canvasMode, scheme }) {
		const theme = resolveCanvasPalette(canvasMode, scheme);
		const ratioMap = {
			'40_60': ['1fr', '1.4fr'],
			'50_50': ['1fr', '1fr'],
			'35_65': ['1fr', '1.85fr'],
		};
		const cols = ratioMap[s.split_ratio] || ratioMap['40_60'];
		const gridCols = s.image_position === 'right' ? `${cols[1]} ${cols[0]}` : `${cols[0]} ${cols[1]}`;
		const shadow = SHADOW_MAP.release[s.image_shadow] || SHADOW_MAP.release.default;

		const containerStyle = {
			padding: `${Math.min(24, Math.round(s.padding_vertical * 0.4))}px ${Math.min(24, Math.round(s.padding_horizontal * 0.4))}px`,
			background: s.container_bg || theme.bg,
			color: s.content_color || theme.text,
			border: s.container_border ? `1px solid ${s.container_border}` : `1px solid ${theme.border}`,
			borderRadius: `${s.container_radius || 0}px`,
		};

		const gridStyle = s.layout === 'split' && s.show_image
			? {
				display: 'grid',
				gridTemplateColumns: gridCols,
				gap: `${Math.max(8, Math.round(s.column_gap * 0.45))}px`,
				alignItems: 'start',
			}
			: {
				display: 'flex',
				flexDirection: 'column',
				gap: `${Math.max(8, Math.round(s.component_gap * 0.5))}px`,
			};

		const coverStyle = {
			borderRadius: `${s.image_radius}px`,
			boxShadow: shadow,
			border: s.image_border_color ? `1px solid ${s.image_border_color}` : 'none',
			aspectRatio: s.image_aspect === 'auto' ? '1 / 1' : s.image_aspect,
			order: s.layout === 'split' && s.image_position === 'right' ? 2 : 1,
		};

		const mainStyle = {
			display: 'flex',
			flexDirection: 'column',
			gap: `${Math.max(6, Math.round(s.component_gap * 0.45))}px`,
			order: s.layout === 'split' && s.image_position === 'right' ? 1 : 2,
		};

		const bodyStyle = {
			color: s.content_color || theme.text,
			fontFamily: s.content_font_family || 'inherit',
			fontSize: `${Math.max(10, Math.round(s.content_font_size * 0.78))}px`,
			lineHeight: s.content_line_height / 100,
			textAlign: s.content_align || 'left',
		};

		const orderMap = {
			meta_body_links: ['meta', 'body', 'links'],
			meta_links_body: ['meta', 'links', 'body'],
			links_meta_body: ['links', 'meta', 'body'],
			body_meta_links: ['body', 'meta', 'links'],
		};
		const slots = orderMap[s.component_order] || orderMap.meta_body_links;

		return el('div', { className: 'mds-tpl-preview-stage', style: containerStyle },
			el('div', { style: gridStyle },
				s.show_image && el('div', { className: 'mds-tpl-preview-cover', style: coverStyle },
					el('span', { className: 'dashicons dashicons-album', 'aria-hidden': true }),
					s.sticky_image && s.layout === 'split' && el('span', { className: 'mds-tpl-preview-badge' }, __('Sticky', 'mds-release-manager'))
				),
				el('div', { style: mainStyle },
					slots.map((slot) => {
						if (slot === 'meta' && s.show_meta) {
							const pillStyle = { background: theme.surface, color: theme.text, border: `1px solid ${theme.border}` };
							return el('div', { key: 'meta', className: 'mds-tpl-preview-meta' },
								el('span', { style: pillStyle }, '2026'),
								el('span', { style: pillStyle }, 'Midnight Records'),
								el('span', { style: pillStyle }, 'Album')
							);
						}
						if (slot === 'body') {
							return el('div', { key: 'body', className: 'mds-tpl-preview-body', style: bodyStyle },
								__('Recorded live in Seattle, this release blends analog warmth with cinematic percussion and layered vocal harmonies.', 'mds-release-manager')
							);
						}
						if (slot === 'links' && s.show_links) {
							return el('div', { key: 'links', className: 'mds-tpl-preview-links' },
								el('span', { className: 'mds-tpl-preview-pill' }, 'Spotify'),
								el('span', { className: 'mds-tpl-preview-pill' }, 'Apple'),
								el('span', { className: 'mds-tpl-preview-pill is-primary', style: { background: theme.accent } }, __('Buy Now', 'mds-release-manager'))
							);
						}
						return null;
					})
				)
			)
		);
	}

	function ArtistLivePreview({ s, canvasMode, scheme }) {
		const theme = resolveCanvasPalette(canvasMode, scheme);
		const shadow = SHADOW_MAP.artist[s.image_shadow] || SHADOW_MAP.artist.default;
		const scaledGap = `${Math.max(8, Math.round(s.gap * 0.5))}px`;

		const containerStyle = {
			padding: `${Math.min(24, Math.round(s.padding_vertical * 0.4))}px ${Math.min(24, Math.round(s.padding_horizontal * 0.4))}px`,
			background: s.container_bg || theme.bg,
			color: s.body_color || theme.text,
			border: s.container_border ? `1px solid ${s.container_border}` : `1px solid ${theme.border}`,
			borderRadius: `${s.container_radius || 0}px`,
			display: 'flex',
			flexDirection: 'column',
			gap: scaledGap,
		};

		const portraitBoxSize = Math.max(48, Math.min(110, Math.round(s.image_size * 0.32)));
		const portraitStyle = {
			width: `${portraitBoxSize}px`,
			aspectRatio: s.image_aspect === 'auto' ? '1 / 1' : s.image_aspect,
			borderRadius: `${s.image_radius}%`,
			boxShadow: shadow,
			border: s.image_border_color ? `2px solid ${s.image_border_color}` : 'none',
		};

		const bioStyle = {
			color: s.body_color || theme.text,
			fontFamily: s.body_font_family || 'inherit',
			fontSize: s.body_font_size > 0 ? `${Math.max(10, Math.round(s.body_font_size * 0.78))}px` : '12px',
			lineHeight: s.body_line_height / 100,
			textAlign: s.body_align || 'left',
		};

		const contactStyle = {
			padding: `${Math.max(8, Math.round(s.contact_padding * 0.5))}px`,
			borderRadius: `${s.contact_radius}px`,
			background: s.contact_background || theme.surface,
			color: s.contact_color || theme.text,
			border: s.contact_border_color && s.contact_border_width > 0
				? `${s.contact_border_width}px solid ${s.contact_border_color}`
				: `1px solid ${theme.border}`,
		};

		const heroEl = s.layout === 'split' && s.show_portrait
			? el('div', {
				className: 'mds-tpl-preview-artist-hero',
				style: { display: 'grid', gridTemplateColumns: `${portraitBoxSize}px minmax(0, 1fr)`, gap: scaledGap, alignItems: 'start' },
			},
				el('div', { className: 'mds-tpl-preview-portrait', style: portraitStyle },
					el('span', { className: 'dashicons dashicons-admin-users', 'aria-hidden': true })
				),
				el('div', { style: bioStyle },
					__('Multi-instrumentalist and producer crafting atmospheric folk and electronic records since 2018.', 'mds-release-manager')
				)
			)
			: el('div', { style: { display: 'flex', flexDirection: 'column', gap: scaledGap } },
				s.show_portrait && el('div', { style: { textAlign: s.portrait_align || 'center' } },
					el('div', { className: 'mds-tpl-preview-portrait is-inline', style: portraitStyle },
						el('span', { className: 'dashicons dashicons-admin-users', 'aria-hidden': true })
					)
				),
				el('div', { style: bioStyle },
					__('Multi-instrumentalist and producer crafting atmospheric folk and electronic records since 2018.', 'mds-release-manager')
				)
			);

		const discographyEl = s.show_discography && el('div', { className: 'mds-tpl-preview-discog' },
			s.discography_heading && el('h4', { className: 'mds-tpl-preview-heading', style: { color: theme.text } }, s.discography_heading),
			el('div', { className: 'mds-tpl-preview-table', style: { borderColor: theme.border } },
				el('div', { className: 'mds-tpl-preview-row is-head', style: { background: theme.surface, color: theme.text } }, el('span', null, __('Title', 'mds-release-manager')), el('span', null, __('Year', 'mds-release-manager'))),
				el('div', { className: 'mds-tpl-preview-row', style: { borderTopColor: theme.border } }, el('span', null, 'Neon Horizons'), el('span', null, '2026')),
				el('div', { className: 'mds-tpl-preview-row', style: { borderTopColor: theme.border } }, el('span', null, 'Northern Lights'), el('span', null, '2024'))
			)
		);

		const contactEl = s.show_contact && el('div', { className: 'mds-tpl-preview-contact', style: contactStyle },
			s.contact_heading && el('h4', { className: 'mds-tpl-preview-heading' }, s.contact_heading),
			el('p', { style: { margin: 0, fontSize: '11px' } }, 'booking@artist-example.com')
		);

		return el('div', { className: 'mds-tpl-preview-stage', style: containerStyle },
			heroEl,
			s.section_order === 'contact_first' ? contactEl : discographyEl,
			s.section_order === 'contact_first' ? discographyEl : contactEl
		);
	}

	function TemplatePresetBar({ scheme, presets, onApplyPreset }) {
		if (!Array.isArray(presets) || !presets.length) return null;
		const modeLabel = scheme.isDark ? __('Dark', 'mds-release-manager') : __('Light', 'mds-release-manager');
		return el('div', { className: 'mds-theme-preset-bar' },
			el('div', { className: 'mds-theme-preset-bar__status' },
				el('span', { className: 'mds-theme-preset-bar__swatches', 'aria-hidden': true },
					scheme.background && el('span', { className: 'mds-theme-preset-bar__dot', style: { backgroundColor: scheme.background }, title: __('Theme Background', 'mds-release-manager') }),
					scheme.text && el('span', { className: 'mds-theme-preset-bar__dot', style: { backgroundColor: scheme.text }, title: __('Theme Text', 'mds-release-manager') }),
					scheme.accent && el('span', { className: 'mds-theme-preset-bar__dot', style: { backgroundColor: scheme.accent }, title: __('Theme Accent', 'mds-release-manager') })
				),
				el('div', null,
					el('span', { className: 'mds-theme-preset-bar__title' },
						// translators: 1: Active theme name. 2: Detected color scheme (Light or Dark).
						sprintf(__('Detected Theme Scheme: %1$s (%2$s)', 'mds-release-manager'), scheme.themeName || __('Active Theme', 'mds-release-manager'), modeLabel)
					),
					el('span', { className: 'mds-theme-preset-bar__sub' },
						__('Unset colors automatically adapt to your active theme or Twenty Twenty-Five variation. Or apply a starter preset to both templates:', 'mds-release-manager')
					)
				)
			),
			el('div', { className: 'mds-theme-preset-bar__actions' },
				presets.map((preset) => el(Button, {
					key: preset.id,
					variant: preset.id === 'auto' ? 'secondary' : 'tertiary',
					size: 'compact',
					title: preset.description || '',
					onClick: () => onApplyPreset(preset),
				}, preset.label))
			)
		);
	}

	function TemplateDefaultsEditor() {
		const [palette, customColor, families] = useSettings(
			'color.palette',
			'color.custom',
			'typography.fontFamilies'
		);
		const scheme = config.themeScheme || {};
		const presets = Array.isArray(config.templatePresets) ? config.templatePresets : [];

		const [all, setAll] = useState(() => ({
			release: { ...config.defaults.release, ...(config.values?.release || {}) },
			artist: { ...config.defaults.artist, ...(config.values?.artist || {}) },
		}));
		const [activeTemplate, setActiveTemplate] = useState('release');
		const [isPreviewOpen, setIsPreviewOpen] = useState(true);
		const [canvasMode, setCanvasMode] = useState('theme');
		const [openPanels, setOpenPanels] = useState({
			release: { layout: true, image: false, components: false, typography: false },
			artist: { layout: true, portrait: false, biography: false, discography: false, contact: false },
		});

		const fontFamilyOptions = [
			{ label: __('Theme / default', 'mds-release-manager'), value: '' },
			...presetChoices(families, 'font-family'),
		];

		const shadowOptions = [
			{ label: __('None', 'mds-release-manager'), value: 'none' },
			{ label: __('Subtle', 'mds-release-manager'), value: 'subtle' },
			{ label: __('Default', 'mds-release-manager'), value: 'default' },
			{ label: __('Elevated', 'mds-release-manager'), value: 'elevated' },
		];

		function persist(nextAll) {
			setAll(nextAll);
			hiddenInput.value = JSON.stringify(nextAll);
			hiddenInput.dispatchEvent(new Event('change', { bubbles: true }));
		}

		function updateField(section, key, val) {
			persist({
				...all,
				[section]: {
					...all[section],
					[key]: val,
				},
			});
		}

		function resetSection(section) {
			persist({
				...all,
				[section]: { ...config.defaults[section] },
			});
			speak(
				section === 'release'
					? __('Release Fallback Template settings reset to defaults.', 'mds-release-manager')
					: __('Artist Fallback Template settings reset to defaults.', 'mds-release-manager'),
				'polite'
			);
		}

		function togglePreview() {
			const next = !isPreviewOpen;
			setIsPreviewOpen(next);
			speak(
				next
					? __('Live template preview opened.', 'mds-release-manager')
					: __('Live template preview closed.', 'mds-release-manager'),
				'polite'
			);
		}

		const panelIds = activeTemplate === 'release' ? RELEASE_PANELS : ARTIST_PANELS;
		const currentOpen = openPanels[activeTemplate];
		const openCount = panelIds.filter((id) => currentOpen[id]).length;

		function togglePanel(id) {
			setOpenPanels((prev) => ({
				...prev,
				[activeTemplate]: {
					...prev[activeTemplate],
					[id]: !prev[activeTemplate][id],
				},
			}));
		}

		function setAllPanels(state) {
			setOpenPanels((prev) => ({
				...prev,
				[activeTemplate]: Object.fromEntries(panelIds.map((id) => [id, state])),
			}));
		}

		const r = all.release;
		const a = all.artist;

		const releaseControls = el('div', { className: 'mds-tpl-accordions' },
			el(PanelBody, {
				title: __('Layout & Page Container', 'mds-release-manager'),
				opened: Boolean(currentOpen.layout),
				onToggle: () => togglePanel('layout'),
			},
				el(SectionInfo, {
					title: __('About Release Page Layout', 'mds-release-manager'),
					summary: __('Controls the overall column structure, container width, padding, and spacing for automatic Release pages when no page builder is active.', 'mds-release-manager'),
					inheritance: __('Release Meta and Release Links internal styling are configured separately under their respective Block tabs.', 'mds-release-manager'),
				}),
				el(ControlGroup, { title: __('Structure', 'mds-release-manager'), twoCol: true },
					el(SegmentedControl, {
						label: __('Page Layout', 'mds-release-manager'),
						help: __('Split places cover art beside details on desktop; Stacked places cover art above.', 'mds-release-manager'),
						value: r.layout,
						options: [
							{ label: __('Split', 'mds-release-manager'), value: 'split' },
							{ label: __('Stacked', 'mds-release-manager'), value: 'stacked' },
						],
						onChange: (val) => updateField('release', 'layout', val),
					}),
					r.layout === 'split' && el(SelectControl, {
						label: __('Split Column Ratio', 'mds-release-manager'),
						help: __('Adjusts the relative width of the cover art column versus the details column.', 'mds-release-manager'),
						value: r.split_ratio,
						options: [
							{ label: __('40 / 60 (Default)', 'mds-release-manager'), value: '40_60' },
							{ label: __('50 / 50 (Balanced)', 'mds-release-manager'), value: '50_50' },
							{ label: __('35 / 65 (Content-focused)', 'mds-release-manager'), value: '35_65' },
						],
						onChange: (val) => updateField('release', 'split_ratio', val),
					}),
					r.layout === 'split' && el(SegmentedControl, {
						label: __('Cover Art Side', 'mds-release-manager'),
						help: __('Choose which side displays the cover artwork on desktop screens.', 'mds-release-manager'),
						value: r.image_position,
						options: [
							{ label: __('Left', 'mds-release-manager'), value: 'left' },
							{ label: __('Right', 'mds-release-manager'), value: 'right' },
						],
						onChange: (val) => updateField('release', 'image_position', val),
					}),
					r.layout === 'split' && el('div', { className: 'mds-style-field' },
						el(ToggleControl, {
							label: __('Sticky cover art while scrolling', 'mds-release-manager'),
							help: __('Keeps the artwork visible on desktop as visitors read long tracklists or liner notes.', 'mds-release-manager'),
							checked: Boolean(r.sticky_image),
							onChange: (val) => updateField('release', 'sticky_image', Boolean(val)),
							__nextHasNoMarginBottom: true,
						})
					),
					el(SegmentedControl, {
						label: __('Mobile Stacking Order', 'mds-release-manager'),
						help: __('Determines whether artwork or release details appear first on small screens.', 'mds-release-manager'),
						value: r.mobile_order,
						options: [
							{ label: __('Cover Art First', 'mds-release-manager'), value: 'image_first' },
							{ label: __('Content First', 'mds-release-manager'), value: 'content_first' },
						],
						onChange: (val) => updateField('release', 'mobile_order', val),
					})
				),
				el(ControlGroup, { title: __('Width & Spacing', 'mds-release-manager'), twoCol: true },
					el('div', { className: 'mds-style-field' },
						el(RangeControl, {
							label: __('Maximum Content Width (px)', 'mds-release-manager'),
							help: __('Caps the total width of the release template container.', 'mds-release-manager'),
							min: 480, max: 2000, step: 40, allowReset: true,
							resetFallbackValue: config.defaults.release.max_width,
							value: r.max_width,
							onChange: (val) => updateField('release', 'max_width', val ?? config.defaults.release.max_width),
						})
					),
					el('div', { className: 'mds-style-field' },
						el(RangeControl, {
							label: __('Column Gap (px)', 'mds-release-manager'),
							help: __('Horizontal space between cover art and release details in Split layout.', 'mds-release-manager'),
							min: 0, max: 120, step: 4, allowReset: true,
							resetFallbackValue: config.defaults.release.column_gap,
							value: r.column_gap,
							onChange: (val) => updateField('release', 'column_gap', val ?? config.defaults.release.column_gap),
						})
					),
					el('div', { className: 'mds-style-field' },
						el(RangeControl, {
							label: __('Section Spacing (px)', 'mds-release-manager'),
							help: __('Vertical space between Release Meta, Description, and Release Links.', 'mds-release-manager'),
							min: 0, max: 100, step: 1, allowReset: true,
							resetFallbackValue: config.defaults.release.component_gap,
							value: r.component_gap,
							onChange: (val) => updateField('release', 'component_gap', val ?? config.defaults.release.component_gap),
						})
					),
					el('div', { className: 'mds-style-field' },
						el(RangeControl, {
							label: __('Page Vertical Padding (px)', 'mds-release-manager'),
							help: __('Top and bottom inner breathing room around the template container.', 'mds-release-manager'),
							min: 0, max: 120, step: 4, allowReset: true,
							resetFallbackValue: config.defaults.release.padding_vertical,
							value: r.padding_vertical,
							onChange: (val) => updateField('release', 'padding_vertical', val ?? config.defaults.release.padding_vertical),
						})
					),
					el('div', { className: 'mds-style-field' },
						el(RangeControl, {
							label: __('Page Horizontal Padding (px)', 'mds-release-manager'),
							help: __('Left and right inner padding—helpful on full-width themes.', 'mds-release-manager'),
							min: 0, max: 120, step: 4, allowReset: true,
							resetFallbackValue: config.defaults.release.padding_horizontal,
							value: r.padding_horizontal,
							onChange: (val) => updateField('release', 'padding_horizontal', val ?? config.defaults.release.padding_horizontal),
						})
					)
				),
				el(ControlGroup, { title: __('Container Surface (Optional)', 'mds-release-manager'), twoCol: true },
					el(ColorField, {
						label: __('Background Color', 'mds-release-manager'),
						help: __('Leave unset to inherit the page background.', 'mds-release-manager'),
						value: r.container_bg,
						palette, customColor,
						onChange: (val) => updateField('release', 'container_bg', val),
					}),
					el(ColorField, {
						label: __('Border Color', 'mds-release-manager'),
						help: __('Adds a subtle 1px frame around the release container when set.', 'mds-release-manager'),
						value: r.container_border,
						palette, customColor,
						onChange: (val) => updateField('release', 'container_border', val),
					}),
					el('div', { className: 'mds-style-field' },
						el(RangeControl, {
							label: __('Container Corner Radius (px)', 'mds-release-manager'),
							help: __('Rounds the corners of the outer container when a background or border is set.', 'mds-release-manager'),
							min: 0, max: 48, step: 1, allowReset: true,
							resetFallbackValue: config.defaults.release.container_radius,
							value: r.container_radius,
							onChange: (val) => updateField('release', 'container_radius', val ?? config.defaults.release.container_radius),
						})
					)
				)
			),
			el(PanelBody, {
				title: __('Cover Art / Featured Image', 'mds-release-manager'),
				opened: Boolean(currentOpen.image),
				onToggle: () => togglePanel('image'),
			},
				el(ControlGroup, { title: __('Artwork Display', 'mds-release-manager'), twoCol: true },
					el('div', { className: 'mds-style-field' },
						el(ToggleControl, {
							label: __('Show Cover Art in Template', 'mds-release-manager'),
							help: __('Disable if your active theme already renders a featured image hero.', 'mds-release-manager'),
							checked: Boolean(r.show_image),
							onChange: (val) => updateField('release', 'show_image', Boolean(val)),
							__nextHasNoMarginBottom: true,
						})
					),
					el(SelectControl, {
						label: __('Aspect Ratio', 'mds-release-manager'),
						help: __('Crops cover artwork consistently across releases or preserves original dimensions.', 'mds-release-manager'),
						value: r.image_aspect,
						options: [
							{ label: __('Original / Auto', 'mds-release-manager'), value: 'auto' },
							{ label: __('Square (1:1)', 'mds-release-manager'), value: '1/1' },
							{ label: __('Portrait (4:5)', 'mds-release-manager'), value: '4/5' },
							{ label: __('Landscape (16:9)', 'mds-release-manager'), value: '16/9' },
						],
						onChange: (val) => updateField('release', 'image_aspect', val),
					}),
					el('div', { className: 'mds-style-field' },
						el(RangeControl, {
							label: __('Corner Radius (px)', 'mds-release-manager'),
							help: __('Rounds the corners of the release artwork.', 'mds-release-manager'),
							min: 0, max: 100, step: 1, allowReset: true,
							resetFallbackValue: config.defaults.release.image_radius,
							value: r.image_radius,
							onChange: (val) => updateField('release', 'image_radius', val ?? config.defaults.release.image_radius),
						})
					),
					el(SelectControl, {
						label: __('Drop Shadow', 'mds-release-manager'),
						help: __('Controls depth elevation behind the release artwork.', 'mds-release-manager'),
						value: r.image_shadow,
						options: shadowOptions,
						onChange: (val) => updateField('release', 'image_shadow', val),
					}),
					el(ColorField, {
						label: __('Artwork Border Color', 'mds-release-manager'),
						help: __('Optional 1px outline—useful for white album covers on light backgrounds.', 'mds-release-manager'),
						value: r.image_border_color,
						palette, customColor,
						onChange: (val) => updateField('release', 'image_border_color', val),
					})
				)
			),
			el(PanelBody, {
				title: __('Component Visibility & Order', 'mds-release-manager'),
				opened: Boolean(currentOpen.components),
				onToggle: () => togglePanel('components'),
			},
				el(SectionInfo, {
					title: __('Automatic Block Composition', 'mds-release-manager'),
					summary: __('Controls the order and visibility of automatic Release Meta and Release Links components inside the details column.', 'mds-release-manager'),
					inheritance: __('Manually inserted Release Meta or Release Links blocks/shortcodes in post content always take precedence.', 'mds-release-manager'),
				}),
				el(ControlGroup, { title: __('Order & Toggles', 'mds-release-manager'), twoCol: true },
					el(SelectControl, {
						label: __('Component Order', 'mds-release-manager'),
						help: __('Choose where streaming/Buy Now links appear relative to metadata and description.', 'mds-release-manager'),
						value: r.component_order,
						options: [
							{ label: __('Release Meta → Description → Release Links (Default)', 'mds-release-manager'), value: 'meta_body_links' },
							{ label: __('Release Meta → Release Links → Description', 'mds-release-manager'), value: 'meta_links_body' },
							{ label: __('Release Links → Release Meta → Description', 'mds-release-manager'), value: 'links_meta_body' },
							{ label: __('Description → Release Meta → Release Links', 'mds-release-manager'), value: 'body_meta_links' },
						],
						onChange: (val) => updateField('release', 'component_order', val),
					}),
					el('div', { className: 'mds-style-field' },
						el(ToggleControl, {
							label: __('Show Automatic Release Meta', 'mds-release-manager'),
							help: __('Displays release year, label, distributor, and UPC automatically.', 'mds-release-manager'),
							checked: Boolean(r.show_meta),
							onChange: (val) => updateField('release', 'show_meta', Boolean(val)),
							__nextHasNoMarginBottom: true,
						}),
						el(ToggleControl, {
							label: __('Show Automatic Release Links', 'mds-release-manager'),
							help: __('Displays streaming icons, Buy Now button, and audio preview automatically.', 'mds-release-manager'),
							checked: Boolean(r.show_links),
							onChange: (val) => updateField('release', 'show_links', Boolean(val)),
							__nextHasNoMarginBottom: true,
						})
					)
				)
			),
			el(PanelBody, {
				title: __('Description Typography', 'mds-release-manager'),
				opened: Boolean(currentOpen.typography),
				onToggle: () => togglePanel('typography'),
			},
				el(ControlGroup, { title: __('Text Appearance', 'mds-release-manager'), twoCol: true },
					el(SelectControl, {
						label: __('Font Family', 'mds-release-manager'),
						help: __('Select a theme font preset or inherit the active theme body font.', 'mds-release-manager'),
						value: r.content_font_family,
						options: fontFamilyOptions,
						onChange: (val) => updateField('release', 'content_font_family', val),
					}),
					el('div', { className: 'mds-style-field' },
						el(RangeControl, {
							label: __('Font Size (px)', 'mds-release-manager'),
							help: __('Base text size for release descriptions and liner notes.', 'mds-release-manager'),
							min: 12, max: 32, step: 1, allowReset: true,
							resetFallbackValue: config.defaults.release.content_font_size,
							value: r.content_font_size,
							onChange: (val) => updateField('release', 'content_font_size', val ?? config.defaults.release.content_font_size),
						})
					),
					el('div', { className: 'mds-style-field' },
						el(RangeControl, {
							label: __('Line Height (%)', 'mds-release-manager'),
							help: __('Vertical line spacing for readability (160% = 1.6).', 'mds-release-manager'),
							min: 80, max: 300, step: 10, allowReset: true,
							resetFallbackValue: config.defaults.release.content_line_height,
							value: r.content_line_height,
							onChange: (val) => updateField('release', 'content_line_height', val ?? config.defaults.release.content_line_height),
						})
					),
					el(SegmentedControl, {
						label: __('Text Alignment', 'mds-release-manager'),
						help: __('Horizontal alignment for the release description text.', 'mds-release-manager'),
						value: r.content_align,
						options: [
							{ label: __('Left', 'mds-release-manager'), value: 'left' },
							{ label: __('Center', 'mds-release-manager'), value: 'center' },
							{ label: __('Right', 'mds-release-manager'), value: 'right' },
						],
						onChange: (val) => updateField('release', 'content_align', val),
					}),
					el(ColorField, {
						label: __('Text Color', 'mds-release-manager'),
						help: __('Leave unset to inherit your theme text color.', 'mds-release-manager'),
						value: r.content_color,
						palette, customColor,
						onChange: (val) => updateField('release', 'content_color', val),
					})
				)
			)
		);

		const artistControls = el('div', { className: 'mds-tpl-accordions' },
			el(PanelBody, {
				title: __('Layout & Page Container', 'mds-release-manager'),
				opened: Boolean(currentOpen.layout),
				onToggle: () => togglePanel('layout'),
			},
				el(SectionInfo, {
					title: __('About Artist Page Layout', 'mds-release-manager'),
					summary: __('Controls the structure, section order, container width, and spacing for automatic Artist profile pages.', 'mds-release-manager'),
					inheritance: __('Table colors and typography for the discography are configured under the Artist Discography Block tab.', 'mds-release-manager'),
				}),
				el(ControlGroup, { title: __('Structure', 'mds-release-manager'), twoCol: true },
					el(SegmentedControl, {
						label: __('Header Layout', 'mds-release-manager'),
						help: __('Stacked centers the portrait above the bio; Split places portrait and bio side-by-side.', 'mds-release-manager'),
						value: a.layout,
						options: [
							{ label: __('Centered Stacked', 'mds-release-manager'), value: 'stacked' },
							{ label: __('Split (Portrait + Bio)', 'mds-release-manager'), value: 'split' },
						],
						onChange: (val) => updateField('artist', 'layout', val),
					}),
					a.layout === 'stacked' && el(SegmentedControl, {
						label: __('Portrait Alignment', 'mds-release-manager'),
						help: __('Horizontal alignment of the artist portrait in Stacked layout.', 'mds-release-manager'),
						value: a.portrait_align,
						options: [
							{ label: __('Left', 'mds-release-manager'), value: 'left' },
							{ label: __('Center', 'mds-release-manager'), value: 'center' },
							{ label: __('Right', 'mds-release-manager'), value: 'right' },
						],
						onChange: (val) => updateField('artist', 'portrait_align', val),
					}),
					el(SegmentedControl, {
						label: __('Section Order', 'mds-release-manager'),
						help: __('Choose whether the Discography or Contact card appears first below the biography.', 'mds-release-manager'),
						value: a.section_order,
						options: [
							{ label: __('Discography First', 'mds-release-manager'), value: 'discography_first' },
							{ label: __('Contact First', 'mds-release-manager'), value: 'contact_first' },
						],
						onChange: (val) => updateField('artist', 'section_order', val),
					})
				),
				el(ControlGroup, { title: __('Width & Spacing', 'mds-release-manager'), twoCol: true },
					el('div', { className: 'mds-style-field' },
						el(RangeControl, {
							label: __('Maximum Content Width (px)', 'mds-release-manager'),
							help: __('Caps the total width of the artist profile layout.', 'mds-release-manager'),
							min: 320, max: 2000, step: 10, allowReset: true,
							resetFallbackValue: config.defaults.artist.width,
							value: a.width,
							onChange: (val) => updateField('artist', 'width', val ?? config.defaults.artist.width),
						})
					),
					el('div', { className: 'mds-style-field' },
						el(RangeControl, {
							label: __('Section Spacing (px)', 'mds-release-manager'),
							help: __('Vertical gap between portrait, biography, discography, and contact card.', 'mds-release-manager'),
							min: 0, max: 100, step: 1, allowReset: true,
							resetFallbackValue: config.defaults.artist.gap,
							value: a.gap,
							onChange: (val) => updateField('artist', 'gap', val ?? config.defaults.artist.gap),
						})
					),
					el('div', { className: 'mds-style-field' },
						el(RangeControl, {
							label: __('Page Vertical Padding (px)', 'mds-release-manager'),
							help: __('Top and bottom inner padding for the artist container.', 'mds-release-manager'),
							min: 0, max: 120, step: 1, allowReset: true,
							resetFallbackValue: config.defaults.artist.padding_vertical,
							value: a.padding_vertical,
							onChange: (val) => updateField('artist', 'padding_vertical', val ?? config.defaults.artist.padding_vertical),
						})
					),
					el('div', { className: 'mds-style-field' },
						el(RangeControl, {
							label: __('Page Horizontal Padding (px)', 'mds-release-manager'),
							help: __('Left and right inner padding for the artist container.', 'mds-release-manager'),
							min: 0, max: 120, step: 1, allowReset: true,
							resetFallbackValue: config.defaults.artist.padding_horizontal,
							value: a.padding_horizontal,
							onChange: (val) => updateField('artist', 'padding_horizontal', val ?? config.defaults.artist.padding_horizontal),
						})
					)
				),
				el(ControlGroup, { title: __('Container Surface (Optional)', 'mds-release-manager'), twoCol: true },
					el(ColorField, {
						label: __('Background Color', 'mds-release-manager'),
						help: __('Leave unset to inherit the page background.', 'mds-release-manager'),
						value: a.container_bg,
						palette, customColor,
						onChange: (val) => updateField('artist', 'container_bg', val),
					}),
					el(ColorField, {
						label: __('Border Color', 'mds-release-manager'),
						help: __('Adds a subtle 1px frame around the artist container when set.', 'mds-release-manager'),
						value: a.container_border,
						palette, customColor,
						onChange: (val) => updateField('artist', 'container_border', val),
					}),
					el('div', { className: 'mds-style-field' },
						el(RangeControl, {
							label: __('Container Corner Radius (px)', 'mds-release-manager'),
							help: __('Rounds the outer container corners when a background or border is set.', 'mds-release-manager'),
							min: 0, max: 48, step: 1, allowReset: true,
							resetFallbackValue: config.defaults.artist.container_radius,
							value: a.container_radius,
							onChange: (val) => updateField('artist', 'container_radius', val ?? config.defaults.artist.container_radius),
						})
					)
				)
			),
			el(PanelBody, {
				title: __('Artist Portrait', 'mds-release-manager'),
				opened: Boolean(currentOpen.portrait),
				onToggle: () => togglePanel('portrait'),
			},
				el(ControlGroup, { title: __('Portrait Display', 'mds-release-manager'), twoCol: true },
					el('div', { className: 'mds-style-field' },
						el(ToggleControl, {
							label: __('Show Artist Portrait', 'mds-release-manager'),
							help: __('Disable if your theme already displays the Artist featured image.', 'mds-release-manager'),
							checked: Boolean(a.show_portrait),
							onChange: (val) => updateField('artist', 'show_portrait', Boolean(val)),
							__nextHasNoMarginBottom: true,
						})
					),
					el('div', { className: 'mds-style-field' },
						el(RangeControl, {
							label: __('Portrait Size (px)', 'mds-release-manager'),
							help: __('Maximum width of the artist portrait image.', 'mds-release-manager'),
							min: 80, max: 800, step: 10, allowReset: true,
							resetFallbackValue: config.defaults.artist.image_size,
							value: a.image_size,
							onChange: (val) => updateField('artist', 'image_size', val ?? config.defaults.artist.image_size),
						})
					),
					el(SelectControl, {
						label: __('Aspect Ratio', 'mds-release-manager'),
						help: __('Square (1:1) pairs with 50% radius for circular portraits.', 'mds-release-manager'),
						value: a.image_aspect,
						options: [
							{ label: __('Square / Circle (1:1)', 'mds-release-manager'), value: '1/1' },
							{ label: __('Portrait (3:4)', 'mds-release-manager'), value: '3/4' },
							{ label: __('Landscape (4:3)', 'mds-release-manager'), value: '4/3' },
							{ label: __('Original / Auto', 'mds-release-manager'), value: 'auto' },
						],
						onChange: (val) => updateField('artist', 'image_aspect', val),
					}),
					el('div', { className: 'mds-style-field' },
						el(RangeControl, {
							label: __('Portrait Corner Radius (%)', 'mds-release-manager'),
							help: __('Use 50% for a circular portrait or 0% for sharp corners.', 'mds-release-manager'),
							min: 0, max: 50, step: 1, allowReset: true,
							resetFallbackValue: config.defaults.artist.image_radius,
							value: a.image_radius,
							onChange: (val) => updateField('artist', 'image_radius', val ?? config.defaults.artist.image_radius),
						})
					),
					el(SelectControl, {
						label: __('Portrait Shadow', 'mds-release-manager'),
						help: __('Elevation shadow displayed behind the artist portrait.', 'mds-release-manager'),
						value: a.image_shadow,
						options: shadowOptions,
						onChange: (val) => updateField('artist', 'image_shadow', val),
					}),
					el(ColorField, {
						label: __('Portrait Border Color', 'mds-release-manager'),
						help: __('Optional 2px ring/border around the artist portrait.', 'mds-release-manager'),
						value: a.image_border_color,
						palette, customColor,
						onChange: (val) => updateField('artist', 'image_border_color', val),
					})
				)
			),
			el(PanelBody, {
				title: __('Biography Typography', 'mds-release-manager'),
				opened: Boolean(currentOpen.biography),
				onToggle: () => togglePanel('biography'),
			},
				el(ControlGroup, { title: __('Text Appearance', 'mds-release-manager'), twoCol: true },
					el(SelectControl, {
						label: __('Font Family', 'mds-release-manager'),
						help: __('Select a theme font preset or inherit the active theme body font.', 'mds-release-manager'),
						value: a.body_font_family,
						options: fontFamilyOptions,
						onChange: (val) => updateField('artist', 'body_font_family', val),
					}),
					el('div', { className: 'mds-style-field' },
						el(RangeControl, {
							label: __('Font Size (px — 0 inherits theme)', 'mds-release-manager'),
							help: __('Set to 0 to inherit your theme’s default paragraph size.', 'mds-release-manager'),
							min: 0, max: 32, step: 1, allowReset: true,
							resetFallbackValue: config.defaults.artist.body_font_size,
							value: a.body_font_size,
							onChange: (val) => updateField('artist', 'body_font_size', val ?? config.defaults.artist.body_font_size),
						})
					),
					el('div', { className: 'mds-style-field' },
						el(RangeControl, {
							label: __('Line Height (%)', 'mds-release-manager'),
							help: __('Vertical line spacing for the artist biography (160% = 1.6).', 'mds-release-manager'),
							min: 80, max: 300, step: 10, allowReset: true,
							resetFallbackValue: config.defaults.artist.body_line_height,
							value: a.body_line_height,
							onChange: (val) => updateField('artist', 'body_line_height', val ?? config.defaults.artist.body_line_height),
						})
					),
					el(SegmentedControl, {
						label: __('Biography Alignment', 'mds-release-manager'),
						help: __('Horizontal alignment for the artist biography copy.', 'mds-release-manager'),
						value: a.body_align,
						options: [
							{ label: __('Left', 'mds-release-manager'), value: 'left' },
							{ label: __('Center', 'mds-release-manager'), value: 'center' },
						],
						onChange: (val) => updateField('artist', 'body_align', val),
					}),
					el(ColorField, {
						label: __('Biography Text Color', 'mds-release-manager'),
						help: __('Leave unset to inherit your theme text color.', 'mds-release-manager'),
						value: a.body_color,
						palette, customColor,
						onChange: (val) => updateField('artist', 'body_color', val),
					})
				)
			),
			el(PanelBody, {
				title: __('Discography Section', 'mds-release-manager'),
				opened: Boolean(currentOpen.discography),
				onToggle: () => togglePanel('discography'),
			},
				el(ControlGroup, { title: __('Automatic Discography', 'mds-release-manager'), twoCol: true },
					el('div', { className: 'mds-style-field' },
						el(ToggleControl, {
							label: __('Show Automatic Discography', 'mds-release-manager'),
							help: __('Renders the artist’s releases table automatically unless a Discography block/shortcode is already in the bio.', 'mds-release-manager'),
							checked: Boolean(a.show_discography),
							onChange: (val) => updateField('artist', 'show_discography', Boolean(val)),
							__nextHasNoMarginBottom: true,
						})
					),
					el('div', { className: 'mds-style-field' },
						el(TextControl, {
							label: __('Section Heading (Optional)', 'mds-release-manager'),
							help: __('Displayed as an H2 above the discography table (e.g. "Discography"). Leave blank to omit.', 'mds-release-manager'),
							value: a.discography_heading,
							onChange: (val) => updateField('artist', 'discography_heading', val),
						})
					)
				)
			),
			el(PanelBody, {
				title: __('Contact Information Card', 'mds-release-manager'),
				opened: Boolean(currentOpen.contact),
				onToggle: () => togglePanel('contact'),
			},
				el(ControlGroup, { title: __('Visibility & Heading', 'mds-release-manager'), twoCol: true },
					el('div', { className: 'mds-style-field' },
						el(ToggleControl, {
							label: __('Show Contact Information Card', 'mds-release-manager'),
							help: __('Displays the artist’s Contact / Booking details when populated on their profile.', 'mds-release-manager'),
							checked: Boolean(a.show_contact),
							onChange: (val) => updateField('artist', 'show_contact', Boolean(val)),
							__nextHasNoMarginBottom: true,
						})
					),
					el('div', { className: 'mds-style-field' },
						el(TextControl, {
							label: __('Contact Heading', 'mds-release-manager'),
							help: __('Heading displayed inside the contact card (e.g. "Booking & Press"). Leave blank to hide heading.', 'mds-release-manager'),
							value: a.contact_heading,
							onChange: (val) => updateField('artist', 'contact_heading', val),
						})
					)
				),
				el(ControlGroup, { title: __('Card Styling', 'mds-release-manager'), twoCol: true },
					el('div', { className: 'mds-style-field' },
						el(RangeControl, {
							label: __('Inner Padding (px)', 'mds-release-manager'),
							help: __('Space inside the contact card.', 'mds-release-manager'),
							min: 0, max: 100, step: 1, allowReset: true,
							resetFallbackValue: config.defaults.artist.contact_padding,
							value: a.contact_padding,
							onChange: (val) => updateField('artist', 'contact_padding', val ?? config.defaults.artist.contact_padding),
						})
					),
					el('div', { className: 'mds-style-field' },
						el(RangeControl, {
							label: __('Corner Radius (px)', 'mds-release-manager'),
							help: __('Rounds the corners of the contact card.', 'mds-release-manager'),
							min: 0, max: 100, step: 1, allowReset: true,
							resetFallbackValue: config.defaults.artist.contact_radius,
							value: a.contact_radius,
							onChange: (val) => updateField('artist', 'contact_radius', val ?? config.defaults.artist.contact_radius),
						})
					),
					el(ColorField, {
						label: __('Card Background', 'mds-release-manager'),
						help: __('Leave unset to automatically adapt to your active theme surface color (light or dark).', 'mds-release-manager'),
						value: a.contact_background,
						palette, customColor,
						fallbackHint: scheme.surface ? sprintf(__('Auto (%s)', 'mds-release-manager'), scheme.surface) : undefined,
						onChange: (val) => updateField('artist', 'contact_background', val),
					}),
					el(ColorField, {
						label: __('Card Text Color', 'mds-release-manager'),
						help: __('Ensure accessible contrast against the card background.', 'mds-release-manager'),
						value: a.contact_color,
						palette, customColor,
						onChange: (val) => updateField('artist', 'contact_color', val),
					}),
					el('div', { className: 'mds-style-field' },
						el(RangeControl, {
							label: __('Border Width (px)', 'mds-release-manager'),
							help: __('Set above 0 and choose a border color to outline the contact card.', 'mds-release-manager'),
							min: 0, max: 8, step: 1, allowReset: true,
							resetFallbackValue: config.defaults.artist.contact_border_width,
							value: a.contact_border_width,
							onChange: (val) => updateField('artist', 'contact_border_width', val ?? config.defaults.artist.contact_border_width),
						})
					),
					el(ColorField, {
						label: __('Border Color', 'mds-release-manager'),
						help: __('Color of the contact card border when border width is greater than 0.', 'mds-release-manager'),
						value: a.contact_border_color,
						palette, customColor,
						onChange: (val) => updateField('artist', 'contact_border_color', val),
					})
				)
			)
		);

		function cycleCanvas() {
			const order = ['theme', 'light', 'dark'];
			const next = order[(order.indexOf(canvasMode) + 1) % order.length];
			setCanvasMode(next);
			speak(sprintf(__('Preview canvas switched to %s.', 'mds-release-manager'), next), 'polite');
		}

		function applyPreset(preset) {
			if (!preset || !preset.templates) return;
			persist({
				release: { ...all.release, ...(preset.templates.release || {}) },
				artist: { ...all.artist, ...(preset.templates.artist || {}) },
			});
			if (preset.id === 'tt5-evening') setCanvasMode('dark');
			else if (preset.id === 'tt5-light') setCanvasMode('light');
			else if (preset.id === 'auto') setCanvasMode('theme');
			// translators: %s: Preset name.
			speak(sprintf(__('Applied "%s" preset to Default Templates. Click Save Settings to persist.', 'mds-release-manager'), preset.label), 'polite');
		}

		const canvasBtnLabel = canvasMode === 'theme'
			? __('Canvas: Active Theme', 'mds-release-manager')
			: (canvasMode === 'dark' ? __('Canvas: Dark', 'mds-release-manager') : __('Canvas: Light', 'mds-release-manager'));

		const canvasHint = canvasMode === 'theme'
			// translators: %s: Active theme name.
			? sprintf(__('Active Theme (%s)', 'mds-release-manager'), scheme.themeName || (scheme.isDark ? __('Dark', 'mds-release-manager') : __('Light', 'mds-release-manager')))
			: (canvasMode === 'dark' ? __('Dark canvas', 'mds-release-manager') : __('Light canvas', 'mds-release-manager'));

		return el('div', { className: 'mds-tpl-workspace' },
			el(TemplatePresetBar, { scheme, presets, onApplyPreset: applyPreset }),
			el('div', { className: 'mds-tpl-header-bar' },
				el('div', { className: 'mds-tpl-switcher' },
					el(SegmentedControl, {
						label: __('Template to Customize', 'mds-release-manager'),
						value: activeTemplate,
						options: [
							{ label: __('Release Fallback Template', 'mds-release-manager'), value: 'release' },
							{ label: __('Artist Fallback Template', 'mds-release-manager'), value: 'artist' },
						],
						onChange: (val) => setActiveTemplate(val),
					})
				),
				el('div', { className: 'mds-tpl-header-actions' },
					isPreviewOpen && el(Button, {
						variant: 'tertiary',
						size: 'compact',
						onClick: cycleCanvas,
					}, canvasBtnLabel),
					el(Button, {
						variant: 'secondary',
						size: 'compact',
						'aria-expanded': isPreviewOpen,
						'aria-controls': 'mds-tpl-live-preview-pane',
						onClick: togglePreview,
					}, isPreviewOpen ? __('Hide Live Preview', 'mds-release-manager') : __('Show Live Preview', 'mds-release-manager')),
					el(Button, {
						variant: 'tertiary',
						size: 'compact',
						isDestructive: true,
						onClick: () => resetSection(activeTemplate),
					}, activeTemplate === 'release'
						? __('Reset Release Template', 'mds-release-manager')
						: __('Reset Artist Template', 'mds-release-manager')
					)
				)
			),
			el('div', { className: `mds-bd-workspace${isPreviewOpen ? ' has-preview' : ''}` },
				el('div', { className: 'mds-tpl-controls-col' },
					el(AccordionToolbar, {
						openCount,
						totalCount: panelIds.length,
						onExpandAll: () => setAllPanels(true),
						onCollapseAll: () => setAllPanels(false),
					}),
					activeTemplate === 'release' ? releaseControls : artistControls
				),
				isPreviewOpen && el('aside', {
					id: 'mds-tpl-live-preview-pane',
					className: 'mds-tpl-preview-col',
					role: 'region',
					'aria-label': activeTemplate === 'release'
						? __('Release Fallback Template Live Preview', 'mds-release-manager')
						: __('Artist Fallback Template Live Preview', 'mds-release-manager'),
				},
					el('div', { className: 'mds-tpl-preview-card' },
						el('div', { className: 'mds-tpl-preview-card__header' },
							el('strong', null, activeTemplate === 'release'
								? __('Live Preview — Release Fallback', 'mds-release-manager')
								: __('Live Preview — Artist Fallback', 'mds-release-manager')
							),
							el('span', { className: 'mds-tpl-preview-card__hint' }, canvasHint)
						),
						activeTemplate === 'release'
							? el(ReleaseLivePreview, { s: r, canvasMode, scheme })
							: el(ArtistLivePreview, { s: a, canvasMode, scheme }),
						el('p', { className: 'mds-bd-preview-caption' },
							__('Updates in real time as you adjust controls. Click Save Settings to apply site-wide.', 'mds-release-manager')
						)
					)
				)
			)
		);
	}

	const app = el(BlockEditorProvider, {
		settings: config.editorSettings,
		value: emptyBlocks,
		onInput: () => {},
		onChange: () => {},
	}, el(TemplateDefaultsEditor));

	if (wp.element.createRoot) {
		wp.element.createRoot(target).render(app);
	} else {
		wp.element.render(app, target);
	}
})(window.wp);
