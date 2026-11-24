(function (wp) {
	const { createElement: el, Fragment, useEffect, useRef, useState } = wp.element;
	const {
		Button,
		Card,
		CardBody,
		ComboboxControl,
		FormTokenField,
		Modal,
		Notice,
		PanelBody,
		SelectControl,
		TabPanel,
		TextControl,
	} = wp.components;
	const { __, sprintf } = wp.i18n;
	const speak = wp.a11y && typeof wp.a11y.speak === 'function' ? wp.a11y.speak : () => {};

	const data = window.mdsReleaseEditorData;
	const mountTarget = document.getElementById('mds-release-editor-root');
	if (!data || !mountTarget) {
		return;
	}

	function escapeHtml(str) {
		return String(str || '')
			.replace(/&/g, '&amp;')
			.replace(/</g, '&lt;')
			.replace(/>/g, '&gt;')
			.replace(/"/g, '&quot;');
	}

	function buildSrcdoc(res) {
		const links = (res.css_urls || [])
			.map((href) => `<link rel="stylesheet" href="${escapeHtml(href)}" />`)
			.join('\n');
		const modalScript = res.buy_now_modal_js
			? `<script src="${escapeHtml(res.buy_now_modal_js)}" defer></script>`
			: '';
		return (
			'<!DOCTYPE html><html lang="en"><head><meta charset="utf-8" />' +
			'<meta name="viewport" content="width=device-width, initial-scale=1" />' +
			links +
			'<style>body{margin:0;padding:24px;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Oxygen-Sans,Ubuntu,Cantarell,sans-serif;color:#1e1e1e;background:#fff;}h1.mds-preview-page-title{max-width:1200px;margin:0 auto 20px;font-size:28px;line-height:1.25;}</style>' +
			'</head><body>' +
			(res.title ? `<h1 class="mds-preview-page-title">${escapeHtml(res.title)}</h1>` : '') +
			(res.html || '') +
			modalScript +
			'</body></html>'
		);
	}

	function InfoCallout({ title, summary, tip }) {
		return el('div', { className: 'mds-section-info', role: 'note' },
			el('span', { className: 'dashicons dashicons-info-outline mds-section-info__icon', 'aria-hidden': true }),
			el('div', { className: 'mds-section-info__body' },
				el('strong', { className: 'mds-section-info__title' }, title),
				el('p', { className: 'mds-section-info__summary' }, summary),
				tip && el('p', { className: 'mds-section-info__inheritance' }, tip)
			)
		);
	}

	function ExtensionSlot({ active }) {
		const hostRef = useRef(null);
		useEffect(() => {
			const source = document.getElementById('mds-woo-buy-now-slot-source');
			const host = hostRef.current;
			if (!source || !host) return;
			if (active && source.childNodes.length) {
				while (source.firstChild) {
					host.appendChild(source.firstChild);
				}
			}
			return () => {
				if (host && source) {
					while (host.firstChild) {
						source.appendChild(host.firstChild);
					}
				}
			};
		}, [active]);
		return el('span', { ref: hostRef, className: 'mds-woo-slot-host' });
	}

	function ReleaseWorkspace() {
		const [upcEan, setUpcEan] = useState(data.upcEan || '');
		const [releaseType, setReleaseType] = useState(String(data.releaseType || ''));
		const [artistId, setArtistId] = useState(data.artistId || '');
		const [artistTitle, setArtistTitle] = useState(data.artistTitle || '');
		const [artistOptions, setArtistOptions] = useState(() =>
			data.artistId && data.artistTitle
				? [{ value: data.artistId, label: data.artistTitle }]
				: []
		);
		const [recordLabel, setRecordLabel] = useState(data.recordLabel || '');
		const [distributor, setDistributor] = useState(data.distributor || '');
		const [tags, setTags] = useState(Array.isArray(data.tags) ? data.tags : []);
		const [streamingLinks, setStreamingLinks] = useState(
			Array.isArray(data.streamingLinks) ? data.streamingLinks : []
		);
		const [audioPreviewUrl, setAudioPreviewUrl] = useState(data.audioPreviewUrl || '');
		const [buyNowLinks, setBuyNowLinks] = useState(() => {
			const list = Array.isArray(data.buyNowLinks) && data.buyNowLinks.length
				? data.buyNowLinks
				: [{ format: '', store: '', url: '', primary: true }];
			return list;
		});
		const [buyNowMode, setBuyNowMode] = useState(data.buyNowMode || '');
		const [routingMode, setRoutingMode] = useState(data.routingMode || 'inherit');
		const [routingUrl, setRoutingUrl] = useState(data.routingUrl || '');

		// Live Preview Modal state
		const [previewOpen, setPreviewOpen] = useState(false);
		const [previewLoading, setPreviewLoading] = useState(false);
		const [previewError, setPreviewError] = useState('');
		const [previewRes, setPreviewRes] = useState(null);
		const [viewport, setViewport] = useState('desktop');

		const searchTimerRef = useRef(null);
		const audioUploaderRef = useRef(null);

		// Inject "Live Release Preview" button right next to WordPress's "Add New Release" (.page-title-action).
		useEffect(() => {
			const existing = document.getElementById('mds-header-live-preview-btn');
			if (existing) {
				existing.remove();
			}
			const addNewBtn = document.querySelector('.wrap .page-title-action');
			if (!addNewBtn) {
				return;
			}
			const btn = document.createElement('button');
			btn.type = 'button';
			btn.id = 'mds-header-live-preview-btn';
			btn.className = 'page-title-action mds-header-preview-action';
			btn.textContent = __('Live Release Preview', 'mds-release-manager');
			btn.addEventListener('click', (e) => {
				e.preventDefault();
				setPreviewOpen(true);
			});
			addNewBtn.insertAdjacentElement('afterend', btn);
			return () => {
				if (btn.parentNode) {
					btn.parentNode.removeChild(btn);
				}
			};
		}, []);

		// Fetch server-rendered live preview whenever the modal opens.
		useEffect(() => {
			if (!previewOpen) {
				return;
			}
			setPreviewLoading(true);
			setPreviewError('');
			setPreviewRes(null);
			speak(__('Rendering live release preview…', 'mds-release-manager'), 'polite');

			const titleEl = document.getElementById('title');
			const title = titleEl ? titleEl.value : '';
			let content = '';
			if (window.tinymce && window.tinymce.get('content') && !window.tinymce.get('content').isHidden()) {
				content = window.tinymce.get('content').getContent();
			} else {
				const textarea = document.getElementById('content');
				content = textarea ? textarea.value : '';
			}
			const thumbEl = document.getElementById('_thumbnail_id');
			const thumbnailId = thumbEl ? parseInt(thumbEl.value, 10) || 0 : 0;
			const postIdEl = document.getElementById('post_ID');
			const postId = postIdEl ? parseInt(postIdEl.value, 10) || data.postId : data.postId;

			window.fetch(`${data.restBase}releases/${postId}/preview`, {
				method: 'POST',
				credentials: 'same-origin',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': data.nonce,
				},
				body: JSON.stringify({
					title,
					content,
					thumbnail_id: thumbnailId,
					upc_ean: upcEan,
					release_type: parseInt(releaseType, 10) || 0,
					artist_id: artistId,
					record_label: recordLabel,
					distributor,
					audio_preview_url: audioPreviewUrl,
					buy_now_mode: buyNowMode,
					release_routing_mode: routingMode,
					release_routing_url: routingUrl,
					streaming_links: streamingLinks.filter((r) => r.provider && r.url),
					buy_now_links: buyNowLinks.filter((r) => r.url),
				}),
			})
				.then((r) => {
					if (!r.ok) throw new Error('Preview request failed');
					return r.json();
				})
				.then((res) => {
					setPreviewRes(res);
					setPreviewLoading(false);
					speak(__('Live Release Preview loaded.', 'mds-release-manager'), 'polite');
				})
				.catch(() => {
					setPreviewError(__('Could not render preview. Please save draft once or try again.', 'mds-release-manager'));
					setPreviewLoading(false);
				});
		}, [previewOpen]);

		function handleArtistFilter(inputValue) {
			window.clearTimeout(searchTimerRef.current);
			const term = String(inputValue || '').trim();
			if (term.length < 2) {
				return;
			}
			searchTimerRef.current = window.setTimeout(() => {
				const url = new URL(`${data.restBase}artists`, window.location.origin);
				url.searchParams.set('search', term);
				url.searchParams.set('page', '1');
				window.fetch(url.toString(), {
					credentials: 'same-origin',
					headers: { 'X-WP-Nonce': data.nonce },
				})
					.then((r) => (r.ok ? r.json() : { items: [] }))
					.then((payload) => {
						const opts = (payload.items || []).map((item) => ({
							value: item.uuid,
							label: item.title,
						}));
						if (artistId && artistTitle && !opts.some((o) => o.value === artistId)) {
							opts.unshift({ value: artistId, label: artistTitle });
						}
						setArtistOptions(opts);
					})
					.catch(() => {});
			}, 250);
		}

		function openAudioUploader() {
			if (!window.wp || !window.wp.media) {
				return;
			}
			if (audioUploaderRef.current) {
				audioUploaderRef.current.open();
				return;
			}
			audioUploaderRef.current = window.wp.media({
				title: __('Choose Audio Preview', 'mds-release-manager'),
				library: { type: 'audio' },
				multiple: false,
			});
			audioUploaderRef.current.on('select', () => {
				const att = audioUploaderRef.current.state().get('selection').first().toJSON();
				if (att && att.url) {
					setAudioPreviewUrl(att.url);
					speak(__('Audio preview selected.', 'mds-release-manager'), 'polite');
				}
			});
			audioUploaderRef.current.open();
		}

		// UPC digit badge
		const upcDigits = String(upcEan || '').replace(/\D+/g, '');
		let upcBadge = '';
		let upcBadgeClass = 'mds-upc-badge';
		if (upcDigits.length === 12) {
			upcBadge = __('12 digits (UPC-A)', 'mds-release-manager');
			upcBadgeClass += ' is-valid';
		} else if (upcDigits.length === 13) {
			upcBadge = __('13 digits (EAN-13)', 'mds-release-manager');
			upcBadgeClass += ' is-valid';
		} else if (upcDigits.length > 0) {
			upcBadge = sprintf(__('%d digits', 'mds-release-manager'), upcDigits.length);
			upcBadgeClass += ' is-warn';
		}

		const primaryIndex = Math.max(0, buyNowLinks.findIndex((r) => r.primary));
		const primaryBuyNowUrl = (buyNowLinks[primaryIndex] && buyNowLinks[primaryIndex].url) || (buyNowLinks[0] && buyNowLinks[0].url) || '';

		function resolvedRoutingText() {
			if (routingMode === 'fallback') return __('Release Fallback Page', 'mds-release-manager');
			if (routingMode === 'custom_url') {
				return routingUrl
					? sprintf(__('Custom URL (%s)', 'mds-release-manager'), routingUrl)
					: __('Custom URL (enter a URL above)', 'mds-release-manager');
			}
			if (routingMode === 'buy_now') {
				return primaryBuyNowUrl
					? sprintf(__('Primary Buy Now URL (%s)', 'mds-release-manager'), primaryBuyNowUrl)
					: __('Release Fallback Page (no Buy Now URL configured)', 'mds-release-manager');
			}
			if (routingMode === 'artist_website') {
				return data.artistHomepage
					? sprintf(__('Artist Website (%s)', 'mds-release-manager'), data.artistHomepage)
					: __('Release Fallback Page (Artist has no website configured)', 'mds-release-manager');
			}
			if (routingMode === 'inherit') {
				return data.routingPreviewLabel || __('Inherit Artist Policy', 'mds-release-manager');
			}
			const found = (data.routingModes || []).find((m) => m.value === routingMode);
			return found ? found.label : routingMode;
		}

		const releaseTypeOptions = [
			{ value: '', label: __('Select a release type', 'mds-release-manager') },
			...(data.releaseTypes || []).map((t) => ({ value: String(t.id), label: t.name })),
		];

		const providerOptions = [
			{ value: '', label: __('Select Provider', 'mds-release-manager') },
			...(data.providers || []).map((p) => ({ value: p, label: p })),
		];

		const mediaFormatOptions = [
			{ value: '', label: __('— Media Format —', 'mds-release-manager') },
			...(data.mediaFormats || []).map((m) => ({ value: m.slug, label: m.name })),
		];

		const showThirdPartyPlayerNote =
			data.activeAudioPlayerSlug &&
			data.activeAudioPlayerSlug !== 'html5' &&
			data.activeAudioPlayerSlug !== 'native' &&
			data.activeAudioPlayerLabel;

		const tabs = [
			{ name: 'identity', title: __('Identity & Catalog', 'mds-release-manager') },
			{ name: 'streaming', title: __('Streaming & Audio', 'mds-release-manager') },
			{ name: 'commerce', title: __('Buy Now & Routing', 'mds-release-manager') },
		];

		return el(Fragment, null,
			// Always-mounted hidden POST inputs so every tab's state is saved reliably on Publish/Update.
			el('div', { className: 'mds-hidden-post-inputs', 'aria-hidden': true, style: { display: 'none' } },
				el('input', { type: 'hidden', name: 'upc_ean', value: upcEan }),
				el('input', { type: 'hidden', name: 'release_type', value: releaseType }),
				el('input', { type: 'hidden', name: 'artist_id', value: artistId }),
				el('input', { type: 'hidden', name: 'record_label', value: recordLabel }),
				el('input', { type: 'hidden', name: 'distributor', value: distributor }),
				el('input', { type: 'hidden', name: 'tax_input[release_tag]', value: tags.join(',') }),
				el('input', { type: 'hidden', name: 'audio_preview_url', value: audioPreviewUrl }),
				el('input', { type: 'hidden', name: 'mds_buy_now_mode', value: buyNowMode }),
				el('input', { type: 'hidden', name: 'mds_release_routing_mode', value: routingMode }),
				el('input', { type: 'hidden', name: 'mds_release_routing_url', value: routingUrl }),
				el('input', { type: 'hidden', name: 'mds_buy_now_primary', value: String(primaryIndex) }),
				streamingLinks.map((row, idx) =>
					el(Fragment, { key: `h-stream-${idx}` },
						el('input', { type: 'hidden', name: 'mds_streaming_provider[]', value: row.provider || '' }),
						el('input', { type: 'hidden', name: 'mds_streaming_url[]', value: row.url || '' })
					)
				),
				buyNowLinks.map((row, idx) =>
					el(Fragment, { key: `h-buy-${idx}` },
						el('input', { type: 'hidden', name: 'mds_buy_now_format[]', value: row.format || '' }),
						el('input', { type: 'hidden', name: 'mds_buy_now_store[]', value: row.store || '' }),
						el('input', { type: 'hidden', name: 'mds_buy_now_url[]', value: row.url || '' })
					)
				)
			),

			el(TabPanel, { className: 'mds-release-tabs', activeClass: 'is-active', tabs }, (tab) => {
				if (tab.name === 'identity') {
					return el('div', { className: 'mds-release-tab-body' },
						el(InfoCallout, {
							title: __('Catalog Identity & Synchronization', 'mds-release-manager'),
							summary: __('The Barcode (UPC/EAN) and Release Type uniquely identify this release across your catalog, CSV exports, and Multisite Spoke sync. Assigning a Primary Artist automatically lists this release in their Discography and connects artist-level routing.', 'mds-release-manager'),
							tip: __('Designer tip: These fields power the Release Meta block, [release-meta] shortcode, and Elementor tags. Configure field visibility and styling under Releases → Settings → Block Defaults.', 'mds-release-manager'),
						}),
						data.formatConflict && el(Notice, { status: 'warning', isDismissible: false },
							__('The existing release type conflicts with legacy data. Select the correct Release Type below to resolve it.', 'mds-release-manager')
						),
						el(Card, { className: 'mds-editor-card' },
							el(CardBody, null,
								el('div', { className: 'mds-editor-grid mds-editor-grid--2col' },
									el('div', { className: 'mds-editor-field' },
										el('div', { className: 'mds-upc-header' },
											el(TextControl, {
												label: __('Barcode (UPC / EAN) *', 'mds-release-manager'),
												help: __('Unique 12-digit UPC or 13-digit EAN. Leading zeroes are preserved automatically.', 'mds-release-manager'),
												value: upcEan,
												onChange: setUpcEan,
												__next40pxDefaultSize: true,
												__nextHasNoMarginBottom: true,
											}),
											upcBadge && el('span', { className: upcBadgeClass, 'aria-live': 'polite' }, upcBadge)
										)
									),
									el('div', { className: 'mds-editor-field' },
										el(SelectControl, {
											label: __('Release Type *', 'mds-release-manager'),
											help: __('Primary classification for this UPC (Single, EP, Album, or Digital).', 'mds-release-manager'),
											value: releaseType,
											options: releaseTypeOptions,
											disabled: !data.canAssignTypes,
											onChange: setReleaseType,
											__next40pxDefaultSize: true,
											__nextHasNoMarginBottom: true,
										})
									),
									el('div', { className: 'mds-editor-field mds-editor-field--full' },
										artistId && artistTitle && el('div', { className: 'mds-artist-chip' },
											el('span', { className: 'mds-artist-chip__name' }, artistTitle),
											data.artistEditUrl && el('a', { className: 'mds-artist-chip__edit', href: data.artistEditUrl }, __('Edit Artist', 'mds-release-manager')),
											el(Button, {
												variant: 'link',
												isDestructive: true,
												size: 'small',
												onClick: () => {
													setArtistId('');
													setArtistTitle('');
													speak(__('Primary artist cleared.', 'mds-release-manager'), 'polite');
												},
											}, __('Clear', 'mds-release-manager'))
										),
										el(ComboboxControl, {
											label: __('Primary Artist', 'mds-release-manager'),
											help: __('Type at least 2 characters to search published Artist profiles.', 'mds-release-manager'),
											value: artistId,
											options: artistOptions,
											onFilterValueChange: handleArtistFilter,
											onChange: (val) => {
												const nextId = val || '';
												setArtistId(nextId);
												const match = artistOptions.find((o) => o.value === nextId);
												setArtistTitle(match ? match.label : '');
												if (match) {
													speak(sprintf(__('Selected artist: %s', 'mds-release-manager'), match.label), 'polite');
												}
											},
											__next40pxDefaultSize: true,
											__nextHasNoMarginBottom: true,
										})
									)
								)
							)
						),
						el(Card, { className: 'mds-editor-card' },
							el(CardBody, null,
								el('div', { className: 'mds-editor-grid mds-editor-grid--2col' },
									el(TextControl, {
										label: __('Record Label', 'mds-release-manager'),
										placeholder: __('e.g. Independent / Label Name', 'mds-release-manager'),
										value: recordLabel,
										onChange: setRecordLabel,
										__next40pxDefaultSize: true,
										__nextHasNoMarginBottom: true,
									}),
									el(TextControl, {
										label: __('Distributor', 'mds-release-manager'),
										placeholder: __('e.g. DistroKid, CD Baby, TuneCore', 'mds-release-manager'),
										value: distributor,
										onChange: setDistributor,
										__next40pxDefaultSize: true,
										__nextHasNoMarginBottom: true,
									}),
									el('div', { className: 'mds-editor-field mds-editor-field--full' },
										el(FormTokenField, {
											label: __('Release Tags', 'mds-release-manager'),
											value: tags,
											suggestions: data.tagSuggestions || [],
											onChange: (next) => setTags(next),
											__next40pxDefaultSize: true,
											__nextHasNoMarginBottom: true,
										})
									)
								)
							)
						)
					);
				}

				if (tab.name === 'streaming') {
					return el('div', { className: 'mds-release-tab-body' },
						el(InfoCallout, {
							title: __('Streaming Platforms & Audio Preview', 'mds-release-manager'),
							summary: __('Add direct listening URLs for each streaming service where this release is available. The Audio Preview (.mp3) powers the built-in HTML5 player and serves as an automatic fallback whenever a third-party player is not configured for this release.', 'mds-release-manager'),
							tip: __('Designer tip: Manage global streaming providers, icons, and colors under Releases → Settings → Block Defaults, and choose your active player under Audio Player Manager.', 'mds-release-manager'),
						}),
						el(Card, { className: 'mds-editor-card' },
							el(CardBody, null,
								el('h3', { className: 'mds-card-subheading' }, __('Streaming Platforms', 'mds-release-manager')),
								streamingLinks.length === 0 && el('p', { className: 'description' },
									__('No streaming links added yet. Click "+ Add Streaming Link" to link Spotify, Apple Music, YouTube, or other providers.', 'mds-release-manager')
								),
								streamingLinks.map((row, idx) => {
									const opts = row.provider && !providerOptions.some((o) => o.value === row.provider)
										? [...providerOptions, { value: row.provider, label: row.provider }]
										: providerOptions;
									return el('div', { key: `stream-${idx}`, className: 'mds-react-repeater-row mds-react-repeater-row--stream' },
										el(SelectControl, {
											label: __('Provider', 'mds-release-manager'),
											hideLabelFromVision: true,
											value: row.provider || '',
											options: opts,
											onChange: (val) => {
												const next = streamingLinks.slice();
												next[idx] = { ...next[idx], provider: val };
												setStreamingLinks(next);
											},
											__next40pxDefaultSize: true,
											__nextHasNoMarginBottom: true,
										}),
										el(TextControl, {
											label: __('Streaming URL', 'mds-release-manager'),
											hideLabelFromVision: true,
											type: 'url',
											placeholder: 'https://...',
											value: row.url || '',
											onChange: (val) => {
												const next = streamingLinks.slice();
												next[idx] = { ...next[idx], url: val };
												setStreamingLinks(next);
											},
											__next40pxDefaultSize: true,
											__nextHasNoMarginBottom: true,
										}),
										row.url && /^https?:\/\//i.test(row.url) && el('a', {
											className: 'button button-secondary mds-test-url-btn',
											href: row.url,
											target: '_blank',
											rel: 'noopener noreferrer',
											'aria-label': __('Open streaming URL in a new tab', 'mds-release-manager'),
										}, el('span', { className: 'dashicons dashicons-external', 'aria-hidden': true })),
										el(Button, {
											variant: 'tertiary',
											isDestructive: true,
											onClick: () => {
												setStreamingLinks(streamingLinks.filter((_, i) => i !== idx));
												speak(__('Streaming link removed.', 'mds-release-manager'), 'polite');
											},
										}, __('Remove', 'mds-release-manager'))
									);
								}),
								el('div', { className: 'mds-repeater-actions' },
									el(Button, {
										variant: 'secondary',
										onClick: () => {
											setStreamingLinks([...streamingLinks, { provider: '', url: '' }]);
											speak(__('Streaming link row added.', 'mds-release-manager'), 'polite');
										},
									}, __('+ Add Streaming Link', 'mds-release-manager'))
								)
							)
						),
						el(Card, { className: 'mds-editor-card' },
							el(CardBody, null,
								el('h3', { className: 'mds-card-subheading' }, __('Core Audio Preview (.mp3)', 'mds-release-manager')),
								showThirdPartyPlayerNote && el(Notice, { status: 'info', isDismissible: false },
									sprintf(__('Active site-wide player: %s. This .mp3 URL is used automatically as a fallback if that player is not configured for this release.', 'mds-release-manager'), data.activeAudioPlayerLabel)
								),
								el('div', { className: 'mds-audio-bar' },
									el('div', { style: { flex: '1 1 auto' } },
										el(TextControl, {
											label: __('Audio Preview URL (.mp3)', 'mds-release-manager'),
											type: 'url',
											placeholder: 'https://...',
											value: audioPreviewUrl,
											onChange: setAudioPreviewUrl,
											__next40pxDefaultSize: true,
											__nextHasNoMarginBottom: true,
										})
									),
									el(Button, { variant: 'secondary', onClick: openAudioUploader }, __('Select Audio', 'mds-release-manager')),
									audioPreviewUrl && el(Button, {
										variant: 'tertiary',
										isDestructive: true,
										onClick: () => {
											setAudioPreviewUrl('');
											speak(__('Audio preview cleared.', 'mds-release-manager'), 'polite');
										},
									}, __('Clear', 'mds-release-manager'))
								),
								audioPreviewUrl && /^https?:\/\//i.test(audioPreviewUrl) && el('div', { className: 'mds-audio-player-preview' },
									el('audio', { controls: true, preload: 'none', src: audioPreviewUrl })
								)
							)
						)
					);
				}

				// Commerce & Routing tab
				return el('div', { className: 'mds-release-tab-body' },
					el(InfoCallout, {
						title: __('Multi-Format Purchase Links', 'mds-release-manager'),
						summary: __('Configure one Buy Now URL per physical or digital Media Format (Digital Download, CD, Vinyl LP, etc.) and mark one as Primary for direct links and routing. When 2 or more formats are configured, visitors can choose a format in an accessible modal unless overridden below.', 'mds-release-manager'),
						tip: __('Designer tip: Manage Media Formats and icons under Releases → Media Formats. Site-wide Buy Now styling and default behavior live under Releases → Settings → Block Defaults.', 'mds-release-manager'),
					}),
					el(Card, { className: 'mds-editor-card' },
						el(CardBody, null,
							el('h3', { className: 'mds-card-subheading' }, __('Purchase Formats', 'mds-release-manager')),
							buyNowLinks.map((row, idx) =>
								el('div', { key: `buy-${idx}`, className: `mds-buy-now-row${row.primary ? ' is-primary' : ''}` },
									el('label', { className: 'mds-buy-now-primary' },
										el('input', {
											type: 'radio',
											name: 'mds_react_buy_now_primary',
											checked: Boolean(row.primary),
											onChange: () => {
												setBuyNowLinks(buyNowLinks.map((item, i) => ({ ...item, primary: i === idx })));
											},
										}),
										' ',
										__('Primary', 'mds-release-manager')
									),
									el(SelectControl, {
										label: __('Media Format', 'mds-release-manager'),
										hideLabelFromVision: true,
										value: row.format || '',
										options: mediaFormatOptions,
										onChange: (val) => {
											const next = buyNowLinks.slice();
											next[idx] = { ...next[idx], format: val };
											setBuyNowLinks(next);
										},
										__next40pxDefaultSize: true,
										__nextHasNoMarginBottom: true,
									}),
									el(TextControl, {
										label: __('Store / Edition', 'mds-release-manager'),
										hideLabelFromVision: true,
										placeholder: __('Store / Edition (optional)', 'mds-release-manager'),
										value: row.store || '',
										onChange: (val) => {
											const next = buyNowLinks.slice();
											next[idx] = { ...next[idx], store: val };
											setBuyNowLinks(next);
										},
										__next40pxDefaultSize: true,
										__nextHasNoMarginBottom: true,
									}),
									el(TextControl, {
										label: __('Buy Now URL', 'mds-release-manager'),
										hideLabelFromVision: true,
										type: 'url',
										placeholder: 'https://...',
										value: row.url || '',
										onChange: (val) => {
											const next = buyNowLinks.slice();
											next[idx] = { ...next[idx], url: val };
											setBuyNowLinks(next);
										},
										__next40pxDefaultSize: true,
										__nextHasNoMarginBottom: true,
									}),
									row.url && /^https?:\/\//i.test(row.url) && el('a', {
										className: 'button button-secondary mds-test-url-btn',
										href: row.url,
										target: '_blank',
										rel: 'noopener noreferrer',
										'aria-label': __('Open Buy Now URL in a new tab', 'mds-release-manager'),
									}, el('span', { className: 'dashicons dashicons-external', 'aria-hidden': true })),
									el(Button, {
										variant: 'tertiary',
										isDestructive: true,
										onClick: () => {
											const remaining = buyNowLinks.filter((_, i) => i !== idx);
											if (remaining.length && !remaining.some((r) => r.primary)) {
												remaining[0] = { ...remaining[0], primary: true };
											}
											setBuyNowLinks(remaining);
											speak(__('Purchase format row removed.', 'mds-release-manager'), 'polite');
										},
									}, __('Remove', 'mds-release-manager'))
								)
							),
							el('div', { className: 'mds-buy-now-actions' },
								el(Button, {
									variant: 'secondary',
									onClick: () => {
										setBuyNowLinks([
											...buyNowLinks,
											{ format: '', store: '', url: '', primary: buyNowLinks.length === 0 },
										]);
										speak(__('Purchase format row added.', 'mds-release-manager'), 'polite');
									},
								}, __('+ Add Media Format', 'mds-release-manager')),
								el(ExtensionSlot, { active: tab.name === 'commerce' })
							),
							el('div', { style: { marginTop: '16px', maxWidth: '460px' } },
								el(SelectControl, {
									label: __('Buy Now Button Behavior', 'mds-release-manager'),
									help: __('Controls what happens when a visitor clicks Buy Now in the Release Links block or shortcode for this release.', 'mds-release-manager'),
									value: buyNowMode,
									options: [
										{ value: '', label: __('Use site / block default', 'mds-release-manager') },
										{ value: 'direct', label: __('Use Primary URL Only (direct link)', 'mds-release-manager') },
										{ value: 'picker', label: __('Show Modal Popup (choose format)', 'mds-release-manager') },
									],
									onChange: setBuyNowMode,
									__next40pxDefaultSize: true,
									__nextHasNoMarginBottom: true,
								})
							)
						)
					),
					el(PanelBody, {
						title: __('Advanced: Page Routing Override', 'mds-release-manager'),
						initialOpen: false,
						className: 'mds-routing-panel',
					},
						el(InfoCallout, {
							title: __('Per-Release Page Routing', 'mds-release-manager'),
							summary: __('By default, this release inherits its Primary Artist’s routing policy (displaying the Release Fallback Template or redirecting with a 302 Found redirect). Choose a mode below only if this release needs an individual override.', 'mds-release-manager'),
							tip: __('Designer tip: Bulk-manage routing for all releases on the Artist Edit screen, or style the fallback page under Releases → Settings → Default Templates.', 'mds-release-manager'),
						}),
						el('div', { className: 'mds-editor-grid mds-editor-grid--2col' },
							el(SelectControl, {
								label: __('Destination Mode', 'mds-release-manager'),
								value: routingMode,
								options: data.routingModes || [],
								onChange: setRoutingMode,
								__next40pxDefaultSize: true,
								__nextHasNoMarginBottom: true,
							}),
							routingMode === 'custom_url' && el(TextControl, {
								label: __('Custom Redirect URL', 'mds-release-manager'),
								type: 'url',
								placeholder: 'https://...',
								value: routingUrl,
								onChange: setRoutingUrl,
								__next40pxDefaultSize: true,
								__nextHasNoMarginBottom: true,
							})
						),
						el('p', { className: 'mds-routing-resolved', 'aria-live': 'polite' },
							el('span', { className: 'dashicons dashicons-randomize', 'aria-hidden': true }),
							el('span', null, __('Resolved destination:', 'mds-release-manager'), ' ', el('strong', null, resolvedRoutingText())),
							data.artistEditUrl && artistTitle && el('a', {
								className: 'mds-routing-artist-link',
								href: `${data.artistEditUrl}#mds_artist_release_routing`,
							}, sprintf(__('Manage all routing for %s →', 'mds-release-manager'), artistTitle))
						)
					)
				);
			}),

			// Native @wordpress/components Live Preview Modal
			previewOpen && el(Modal, {
				title: __('Live Release Preview', 'mds-release-manager'),
				className: 'mds-wp-preview-modal',
				onRequestClose: () => {
					setPreviewOpen(false);
					speak(__('Live Release Preview closed.', 'mds-release-manager'), 'polite');
				},
			},
				el('div', { className: 'mds-preview-modal-bar' },
					el('div', { className: 'mds-preview-dialog__viewports', role: 'group', 'aria-label': __('Preview viewport', 'mds-release-manager') },
						['desktop', 'tablet', 'mobile'].map((vp) => {
							const labels = {
								desktop: __('Desktop', 'mds-release-manager'),
								tablet: __('Tablet (768px)', 'mds-release-manager'),
								mobile: __('Mobile (390px)', 'mds-release-manager'),
							};
							return el(Button, {
								key: vp,
								variant: viewport === vp ? 'primary' : 'secondary',
								'aria-pressed': viewport === vp,
								onClick: () => {
									setViewport(vp);
									speak(sprintf(__('Preview viewport switched to %s.', 'mds-release-manager'), labels[vp]), 'polite');
								},
							}, labels[vp]);
						})
					)
				),
				previewRes && previewRes.routing_notice && el(Notice, { status: 'warning', isDismissible: false }, previewRes.routing_notice),
				previewError && el(Notice, { status: 'error', isDismissible: false }, previewError),
				el('div', { className: 'mds-preview-dialog__stage' },
					previewLoading && el('div', { className: 'mds-preview-dialog__loading' }, __('Rendering live preview…', 'mds-release-manager')),
					!previewLoading && previewRes && el('iframe', {
						className: 'mds-preview-dialog__frame',
						'data-viewport': viewport,
						title: __('Live Release Preview', 'mds-release-manager'),
						srcDoc: buildSrcdoc(previewRes),
					})
				)
			)
		);
	}

	const app = el(ReleaseWorkspace);
	if (wp.element.createRoot) {
		wp.element.createRoot(mountTarget).render(app);
	} else {
		wp.element.render(app, mountTarget);
	}
})(window.wp);
