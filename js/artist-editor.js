(function (wp) {
	const { createElement: el, Fragment, useEffect, useMemo, useRef, useState } = wp.element;
	const {
		Button,
		Card,
		CardBody,
		Modal,
		Notice,
		SearchControl,
		SelectControl,
		TabPanel,
		TextControl,
		ToggleControl,
	} = wp.components;
	const { __, sprintf } = wp.i18n;
	const speak = wp.a11y && typeof wp.a11y.speak === 'function' ? wp.a11y.speak : () => {};

	const data = window.mdsArtistEditorData;
	const mountTarget = document.getElementById('mds-artist-editor-root');
	if (!data || !mountTarget) {
		return;
	}

	// Extensible top-bar "Artist Tools" registry for companion plugins.
	const artistToolsList = [];
	window.mdsRmArtistTools = {
		register(item) {
			if (!item || !item.id || typeof item.onSelect !== 'function') {
				return;
			}
			const existingIdx = artistToolsList.findIndex((t) => t.id === item.id);
			if (existingIdx >= 0) {
				artistToolsList[existingIdx] = item;
			} else {
				artistToolsList.push(item);
			}
			window.dispatchEvent(new CustomEvent('mds-rm:artist-tools-changed'));
		},
		getAll() {
			return artistToolsList.slice();
		},
	};

	function escapeHtml(str) {
		return String(str || '')
			.replace(/&/g, '&amp;')
			.replace(/</g, '&lt;')
			.replace(/>/g, '&gt;')
			.replace(/"/g, '&quot;');
	}

	function isValidExternalUrl(url, permalink) {
		const trimmed = String(url || '').trim();
		if (!trimmed || !/^https?:\/\//i.test(trimmed)) {
			return false;
		}
		if (permalink) {
			const normA = trimmed.replace(/\/+$/, '').toLowerCase();
			const normB = String(permalink).replace(/\/+$/, '').toLowerCase();
			if (normA === normB) {
				return false;
			}
		}
		return true;
	}

	function buildSrcdoc(res) {
		const links = (res.css_urls || [])
			.map((href) => `<link rel="stylesheet" href="${escapeHtml(href)}" />`)
			.join('\n');
		const scheme = data.themeScheme || {};
		const bg = scheme.background || '#fff';
		const fg = scheme.text || '#1e1e1e';
		return (
			'<!DOCTYPE html><html lang="en"><head><meta charset="utf-8" />' +
			'<meta name="viewport" content="width=device-width, initial-scale=1" />' +
			links +
			`<style>body{margin:0;padding:24px;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Oxygen-Sans,Ubuntu,Cantarell,sans-serif;color:${escapeHtml(fg)};background:${escapeHtml(bg)};}h1.mds-preview-page-title{max-width:900px;margin:0 auto 20px;font-size:28px;line-height:1.25;}</style>` +
			'</head><body>' +
			(res.title ? `<h1 class="mds-preview-page-title">${escapeHtml(res.title)}</h1>` : '') +
			(res.html || '') +
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

	function ContactEditorSlot() {
		const hostRef = useRef(null);
		useEffect(() => {
			const source = document.getElementById('mds-artist-contact-editor-slot-source');
			const host = hostRef.current;
			if (!source || !host) return;
			while (source.firstChild) {
				host.appendChild(source.firstChild);
			}
		}, []);
		return el('div', { ref: hostRef, className: 'mds-artist-contact-slot' });
	}

	function ArtistWorkspace() {
		const initialTab = window.location.hash === '#mds_artist_release_routing' ? 'routing' : 'profile';
		const [activeTab, setActiveTab] = useState(initialTab);

		// Profile state
		const [homepageUrl, setHomepageUrl] = useState(data.homepageUrl || '');
		const [homepageRouting, setHomepageRouting] = useState(Boolean(data.homepageRouting));

		// Release Routing state
		const [artistPolicy, setArtistPolicy] = useState(data.artistReleasesRoutingMode || 'fallback');
		const [releases, setReleases] = useState(() =>
			(Array.isArray(data.releases) ? data.releases : []).map((r) => ({ ...r, dirty: false }))
		);
		const [selectedIds, setSelectedIds] = useState({});
		const [bulkMode, setBulkMode] = useState('');
		const [searchQuery, setSearchQuery] = useState('');
		const [routingSaving, setRoutingSaving] = useState(false);
		const [routingNotice, setRoutingNotice] = useState(null);

		// Live Preview Modal state
		const [previewOpen, setPreviewOpen] = useState(false);
		const [previewLoading, setPreviewLoading] = useState(false);
		const [previewError, setPreviewError] = useState('');
		const [previewRes, setPreviewRes] = useState(null);
		const [viewport, setViewport] = useState('desktop');

		// Bridge for companion plugins (e.g. MDS Metadata) to update Artist profile fields.
		useEffect(() => {
			function handleApplyArtistMeta(event) {
				const detail = event && event.detail ? event.detail : null;
				if (!detail || typeof detail !== 'object') {
					return;
				}
				if (typeof detail.artist_website === 'string' && detail.artist_website.trim()) {
					setHomepageUrl(detail.artist_website.trim());
				}
			}
			window.addEventListener('mds-rm:apply-artist-metadata', handleApplyArtistMeta);
			return () => window.removeEventListener('mds-rm:apply-artist-metadata', handleApplyArtistMeta);
		}, []);

		// Inject "Live Artist Preview" button and extensible "Artist Tools ▾" dropdown next to "Add New Artist".
		useEffect(() => {
			const existing = document.getElementById('mds-header-live-preview-btn');
			if (existing) {
				existing.remove();
			}
			const existingTools = document.getElementById('mds-header-artist-tools-wrap');
			if (existingTools) {
				existingTools.remove();
			}
			const addNewBtn = document.querySelector('.wrap .page-title-action');
			if (!addNewBtn) {
				return;
			}
			const btn = document.createElement('button');
			btn.type = 'button';
			btn.id = 'mds-header-live-preview-btn';
			btn.className = 'page-title-action mds-header-preview-action';
			btn.textContent = __('Live Artist Preview', 'mds-release-manager');
			btn.addEventListener('click', (e) => {
				e.preventDefault();
				setPreviewOpen(true);
			});
			addNewBtn.insertAdjacentElement('afterend', btn);

			const toolsWrap = document.createElement('span');
			toolsWrap.id = 'mds-header-artist-tools-wrap';
			toolsWrap.className = 'mds-header-tools-wrap';
			toolsWrap.hidden = true;

			const toolsBtn = document.createElement('button');
			toolsBtn.type = 'button';
			toolsBtn.className = 'page-title-action mds-header-tools-btn';
			toolsBtn.setAttribute('aria-haspopup', 'menu');
			toolsBtn.setAttribute('aria-expanded', 'false');
			toolsBtn.textContent = __('Artist Tools ▾', 'mds-release-manager');

			const menu = document.createElement('div');
			menu.className = 'mds-header-tools-menu';
			menu.setAttribute('role', 'menu');
			menu.hidden = true;

			toolsWrap.appendChild(toolsBtn);
			toolsWrap.appendChild(menu);
			btn.insertAdjacentElement('afterend', toolsWrap);

			function closeMenu() {
				menu.hidden = true;
				toolsBtn.setAttribute('aria-expanded', 'false');
			}

			function renderMenu() {
				const items = window.mdsRmArtistTools.getAll();
				toolsWrap.hidden = items.length === 0;
				menu.innerHTML = '';
				if (!items.length) {
					closeMenu();
					return;
				}
				const grouped = new Map();
				items.forEach((item) => {
					const cat = item.category || __('Tools', 'mds-release-manager');
					if (!grouped.has(cat)) {
						grouped.set(cat, []);
					}
					grouped.get(cat).push(item);
				});
				grouped.forEach((catItems, catLabel) => {
					const groupEl = document.createElement('div');
					groupEl.className = 'mds-header-tools-group';
					groupEl.setAttribute('role', 'group');

					const heading = document.createElement('div');
					heading.className = 'mds-header-tools-group__label';
					heading.textContent = catLabel;
					groupEl.appendChild(heading);

					catItems.forEach((item) => {
						const itemBtn = document.createElement('button');
						itemBtn.type = 'button';
						itemBtn.className = 'mds-header-tools-item';
						itemBtn.setAttribute('role', 'menuitem');

						const titleRow = document.createElement('span');
						titleRow.className = 'mds-header-tools-item__title';
						if (item.icon) {
							const iconSpan = document.createElement('span');
							iconSpan.className = `${item.icon} mds-header-tools-item__icon`;
							iconSpan.setAttribute('aria-hidden', 'true');
							titleRow.appendChild(iconSpan);
						}
						const labelSpan = document.createElement('span');
						labelSpan.textContent = item.label || item.id;
						titleRow.appendChild(labelSpan);
						if (item.badge) {
							const badgeSpan = document.createElement('span');
							badgeSpan.className = 'mds-header-tools-item__badge';
							badgeSpan.textContent = item.badge;
							titleRow.appendChild(badgeSpan);
						}
						itemBtn.appendChild(titleRow);

						if (item.description) {
							const desc = document.createElement('span');
							desc.className = 'mds-header-tools-item__desc';
							desc.textContent = item.description;
							itemBtn.appendChild(desc);
						}

						itemBtn.addEventListener('click', (e) => {
							e.preventDefault();
							closeMenu();
							item.onSelect();
						});
						groupEl.appendChild(itemBtn);
					});
					menu.appendChild(groupEl);
				});
			}

			toolsBtn.addEventListener('click', (e) => {
				e.preventDefault();
				e.stopPropagation();
				const nextOpen = menu.hidden;
				menu.hidden = !nextOpen;
				toolsBtn.setAttribute('aria-expanded', nextOpen ? 'true' : 'false');
			});

			function onDocClick(e) {
				if (!toolsWrap.contains(e.target)) {
					closeMenu();
				}
			}
			function onKeyDown(e) {
				if (e.key === 'Escape' && !menu.hidden) {
					closeMenu();
					toolsBtn.focus();
				}
			}

			document.addEventListener('click', onDocClick);
			document.addEventListener('keydown', onKeyDown);
			window.addEventListener('mds-rm:artist-tools-changed', renderMenu);
			renderMenu();

			return () => {
				document.removeEventListener('click', onDocClick);
				document.removeEventListener('keydown', onKeyDown);
				window.removeEventListener('mds-rm:artist-tools-changed', renderMenu);
				if (btn.parentNode) {
					btn.parentNode.removeChild(btn);
				}
				if (toolsWrap.parentNode) {
					toolsWrap.parentNode.removeChild(toolsWrap);
				}
			};
		}, []);

		// Fetch server-rendered Live Artist Preview when modal opens.
		useEffect(() => {
			if (!previewOpen) {
				return;
			}
			setPreviewLoading(true);
			setPreviewError('');
			setPreviewRes(null);
			speak(__('Rendering live artist preview…', 'mds-release-manager'), 'polite');

			const titleEl = document.getElementById('title');
			const title = titleEl ? titleEl.value : '';
			let content = '';
			if (window.tinymce && window.tinymce.get('content') && !window.tinymce.get('content').isHidden()) {
				content = window.tinymce.get('content').getContent();
			} else {
				const textarea = document.getElementById('content');
				content = textarea ? textarea.value : '';
			}
			let contactHtml = '';
			if (window.tinymce && window.tinymce.get('artist_contact') && !window.tinymce.get('artist_contact').isHidden()) {
				contactHtml = window.tinymce.get('artist_contact').getContent();
			} else {
				const contactTextarea = document.getElementById('artist_contact');
				contactHtml = contactTextarea ? contactTextarea.value : '';
			}
			const thumbEl = document.getElementById('_thumbnail_id');
			const thumbnailId = thumbEl ? parseInt(thumbEl.value, 10) || 0 : 0;
			const postIdEl = document.getElementById('post_ID');
			const postId = postIdEl ? parseInt(postIdEl.value, 10) || data.postId : data.postId;

			window.fetch(`${data.restBase}artists/${postId}/preview`, {
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
					artist_contact: contactHtml,
					artist_homepage_url: homepageUrl,
					artist_homepage_routing: homepageRouting,
				}),
			})
				.then((r) => {
					if (!r.ok) throw new Error('Preview request failed');
					return r.json();
				})
				.then((res) => {
					setPreviewRes(res);
					setPreviewLoading(false);
					speak(__('Live Artist Preview loaded.', 'mds-release-manager'), 'polite');
				})
				.catch(() => {
					setPreviewError(__('Could not render preview. Please save draft once or try again.', 'mds-release-manager'));
					setPreviewLoading(false);
				});
		}, [previewOpen]);

		const filteredReleases = useMemo(() => {
			const q = searchQuery.trim().toLowerCase();
			if (!q) return releases;
			return releases.filter(
				(r) =>
					String(r.title || '').toLowerCase().includes(q) ||
					String(r.upc || '').toLowerCase().includes(q)
			);
		}, [releases, searchQuery]);

		const allVisibleSelected =
			filteredReleases.length > 0 &&
			filteredReleases.every((r) => Boolean(selectedIds[r.id]));

		function toggleSelectAll(checked) {
			const next = { ...selectedIds };
			filteredReleases.forEach((r) => {
				if (checked) next[r.id] = true;
				else delete next[r.id];
			});
			setSelectedIds(next);
		}

		function updateReleaseRow(id, patch) {
			setReleases((prev) =>
				prev.map((r) => (r.id === id ? { ...r, ...patch, dirty: true } : r))
			);
		}

		function applyBulkMode() {
			if (!bulkMode) return;
			const ids = Object.keys(selectedIds).filter((k) => selectedIds[k]).map(Number);
			if (!ids.length) {
				setRoutingNotice({ status: 'warning', message: __('Select at least one release to apply a bulk action.', 'mds-release-manager') });
				return;
			}
			const idSet = new Set(ids);
			setReleases((prev) =>
				prev.map((r) => (idSet.has(r.id) ? { ...r, ownMode: bulkMode, dirty: true } : r))
			);
			setRoutingNotice(null);
			speak(__('Bulk routing mode applied. Click Save Routing Changes to persist.', 'mds-release-manager'), 'polite');
		}

		function resetAllToInherit() {
			setReleases((prev) => prev.map((r) => ({ ...r, ownMode: 'inherit', customUrl: '', dirty: true })));
			setRoutingNotice(null);
			speak(__('All releases reset to Inherit Artist Default. Click Save Routing Changes to persist.', 'mds-release-manager'), 'polite');
		}

		function saveRoutingChanges() {
			setRoutingSaving(true);
			setRoutingNotice(null);
			const postIdEl = document.getElementById('post_ID');
			const postId = postIdEl ? parseInt(postIdEl.value, 10) || data.postId : data.postId;

			window.fetch(`${data.restBase}artists/${postId}/release-routing`, {
				method: 'POST',
				credentials: 'same-origin',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': data.nonce,
				},
				body: JSON.stringify({
					artist_releases_routing_mode: artistPolicy,
					releases: releases.map((r) => ({
						id: r.id,
						mode: r.ownMode,
						custom_url: r.customUrl,
					})),
				}),
			})
				.then((r) => r.json().then((body) => ({ ok: r.ok, body })))
				.then(({ ok, body }) => {
					setRoutingSaving(false);
					if (!ok || !body.success) {
						const errMsg = (body && body.message) || __('Could not save routing changes.', 'mds-release-manager');
						setRoutingNotice({ status: 'error', message: errMsg });
						speak(errMsg, 'assertive');
						return;
					}
					const previews = body.previews || {};
					setReleases((prev) =>
						prev.map((r) => ({
							...r,
							dirty: false,
							preview: previews[r.id] || r.preview,
						}))
					);
					const msg = body.message || __('Routing saved and synced.', 'mds-release-manager');
					setRoutingNotice({ status: 'success', message: msg });
					speak(msg, 'polite');
				})
				.catch(() => {
					setRoutingSaving(false);
					const msg = __('Could not save routing changes.', 'mds-release-manager');
					setRoutingNotice({ status: 'error', message: msg });
					speak(msg, 'assertive');
				});
		}

		const bulkOptions = [
			{ value: '', label: __('Bulk Actions…', 'mds-release-manager') },
			...(data.releaseModes || [])
				.filter((m) => m.value !== 'custom_url')
				.map((m) => ({
					value: m.value,
					label: sprintf(__('Set selected to: %s', 'mds-release-manager'), m.label),
				})),
		];

		const showInvalidRedirectWarning = homepageRouting && !isValidExternalUrl(homepageUrl, data.permalink);

		const tabs = [
			{ name: 'profile', title: __('Profile & Contact', 'mds-release-manager') },
			{
				name: 'routing',
				title: sprintf(
					/* translators: %d: Number of releases linked to this artist. */
					__('Release Routing (%d)', 'mds-release-manager'),
					releases.length
				),
			},
		];

		return el(Fragment, null,
			// Always-mounted hidden POST inputs so standard WordPress Update/Publish persists every field cleanly.
			el('div', { className: 'mds-hidden-post-inputs', 'aria-hidden': true, style: { display: 'none' } },
				el('input', { type: 'hidden', name: 'artist_homepage_url', value: homepageUrl }),
				homepageRouting && el('input', { type: 'hidden', name: 'artist_homepage_routing', value: '1' }),
				el('input', { type: 'hidden', name: 'artist_releases_routing_mode', value: artistPolicy })
			),

			el(TabPanel, {
				className: 'mds-release-tabs',
				activeClass: 'is-active',
				initialTabName: initialTab,
				onSelect: (tabName) => setActiveTab(tabName),
				tabs,
			}, (tab) => {
				const currentTab = tab && tab.name ? tab.name : activeTab;
				return el(Fragment, null,
					// Keep Profile & Contact mounted (toggled with `hidden`) so the slotted TinyMCE `artist_contact` iframe never resets!
					el('div', { className: 'mds-release-tab-body', hidden: currentTab !== 'profile' },
						el(InfoCallout, {
							title: __('Artist Profile & Fallback Composition', 'mds-release-manager'),
							summary: __('Configure the artist’s official website, optional homepage redirect, and rich-text Contact / Booking details. Together with the Biography, Portrait (Featured Image), and Discography, these compose the automatic Artist Fallback Page.', 'mds-release-manager'),
							tip: __('Designer tip: Customize Artist layout (Centered Stacked vs. Split), portrait shape, biography typography, and contact card styling under Releases → Settings → Default Templates.', 'mds-release-manager'),
						}),
						el(Card, { className: 'mds-editor-card' },
							el(CardBody, null,
								el('h3', { className: 'mds-card-subheading' }, __('Official Website & Redirect', 'mds-release-manager')),
								el('div', { className: 'mds-audio-bar' },
									el('div', { style: { flex: '1 1 auto' } },
										el(TextControl, {
											label: __('Official Homepage URL', 'mds-release-manager'),
											type: 'url',
											placeholder: 'https://...',
											value: homepageUrl,
											onChange: setHomepageUrl,
											__next40pxDefaultSize: true,
											__nextHasNoMarginBottom: true,
										})
									),
									homepageUrl && /^https?:\/\//i.test(homepageUrl) && el('a', {
										className: 'button button-secondary mds-test-url-btn',
										href: homepageUrl,
										target: '_blank',
										rel: 'noopener noreferrer',
										'aria-label': __('Open official homepage URL in a new tab', 'mds-release-manager'),
									}, el('span', { className: 'dashicons dashicons-external', 'aria-hidden': true }))
								),
								el('div', { style: { marginTop: '12px' } },
									el(ToggleControl, {
										label: __('Redirect visitors to official homepage (302 Found)', 'mds-release-manager'),
										help: __('When enabled with a valid external URL, visiting this Artist page redirects to their official website instead of showing the fallback profile.', 'mds-release-manager'),
										checked: homepageRouting,
										onChange: setHomepageRouting,
										__nextHasNoMarginBottom: true,
									})
								),
								showInvalidRedirectWarning && el('div', { style: { marginTop: '10px' } },
									el(Notice, { status: 'warning', isDismissible: false },
										__('Routing is enabled, but the homepage URL is missing, invalid, or points to this artist page. Visitors will see the fallback artist page until a valid external URL is entered.', 'mds-release-manager')
									)
								)
							)
						),
						el(Card, { className: 'mds-editor-card' },
							el(CardBody, null,
								el('h3', { className: 'mds-card-subheading' }, __('Contact & Booking Information', 'mds-release-manager')),
								el(ContactEditorSlot)
							)
						)
					),
					el('div', { className: 'mds-release-tab-body', hidden: currentTab !== 'routing' },
						el(InfoCallout, {
							title: __('Artist & Per-Release Routing', 'mds-release-manager'),
							summary: __('Set a default routing policy for every release belonging to this Artist, or override individual releases below. Changes saved here batch-sync automatically to all Multisite Spokes.', 'mds-release-manager'),
							tip: __('Designer tip: All redirects use 302 Found and fall back gracefully to the Release Fallback Page (styled under Releases → Settings → Default Templates) if a target URL is missing.', 'mds-release-manager'),
						}),
						el(Card, { className: 'mds-editor-card' },
							el(CardBody, null,
								el('div', { style: { maxWidth: '520px' } },
									el(SelectControl, {
										label: __('Default Routing Policy for All Releases', 'mds-release-manager'),
										help: __('Applies to every release below set to "Inherit Artist Default".', 'mds-release-manager'),
										value: artistPolicy,
										options: data.artistModes || [],
										onChange: setArtistPolicy,
										__next40pxDefaultSize: true,
										__nextHasNoMarginBottom: true,
									})
								)
							)
						),
						el(Card, { className: 'mds-editor-card' },
							el(CardBody, null,
								releases.length === 0
									? el('p', { className: 'description' },
										__('No releases are currently linked to this Artist. Once releases are assigned to this Artist, you can manage their individual and bulk routing rules here.', 'mds-release-manager')
									)
									: el(Fragment, null,
										el('div', { className: 'mds-routing-toolbar' },
											el('div', { className: 'mds-routing-bulk-controls' },
												el(SelectControl, {
													label: __('Bulk routing action', 'mds-release-manager'),
													hideLabelFromVision: true,
													value: bulkMode,
													options: bulkOptions,
													onChange: setBulkMode,
													__next40pxDefaultSize: true,
													__nextHasNoMarginBottom: true,
												}),
												el(Button, { variant: 'secondary', onClick: applyBulkMode }, __('Apply', 'mds-release-manager')),
												el(Button, { variant: 'tertiary', onClick: resetAllToInherit }, __('Reset All to Inherit', 'mds-release-manager'))
											),
											el('div', { className: 'mds-routing-search-wrap' },
												el(SearchControl, {
													label: __('Filter releases by title or UPC', 'mds-release-manager'),
													hideLabelFromVision: true,
													placeholder: __('Filter releases by title or UPC…', 'mds-release-manager'),
													value: searchQuery,
													onChange: setSearchQuery,
													__nextHasNoMarginBottom: true,
												})
											)
										),
										el('div', { className: 'mds-routing-table-wrap' },
											el('table', { className: 'wp-list-table widefat fixed striped mds-routing-table' },
												el('thead', null,
													el('tr', null,
														el('td', { className: 'manage-column column-cb check-column' },
															el('input', {
																type: 'checkbox',
																checked: allVisibleSelected,
																'aria-label': __('Select all visible releases', 'mds-release-manager'),
																onChange: (e) => toggleSelectAll(e.target.checked),
															})
														),
														el('th', { style: { width: '56px' } }, __('Cover', 'mds-release-manager')),
														el('th', null, __('Release', 'mds-release-manager')),
														el('th', { style: { width: '140px' } }, __('UPC / EAN', 'mds-release-manager')),
														el('th', { style: { width: '220px' } }, __('Routing Mode', 'mds-release-manager')),
														el('th', null, __('Custom URL / Resolved Destination', 'mds-release-manager'))
													)
												),
												el('tbody', null,
													filteredReleases.map((row) => {
														const isCustom = row.ownMode === 'custom_url';
														return el('tr', {
															key: row.id,
															className: `mds-routing-row${row.dirty ? ' is-dirty' : ''}`,
														},
															el('th', { scope: 'row', className: 'check-column' },
																el('input', {
																	type: 'checkbox',
																	checked: Boolean(selectedIds[row.id]),
																	'aria-label': sprintf(__('Select %s', 'mds-release-manager'), row.title),
																	onChange: (e) => {
																		const next = { ...selectedIds };
																		if (e.target.checked) next[row.id] = true;
																		else delete next[row.id];
																		setSelectedIds(next);
																	},
																})
															),
															el('td', null,
																row.thumbUrl
																	? el('img', { src: row.thumbUrl, alt: '', width: 40, height: 40, style: { borderRadius: '3px', objectFit: 'cover' } })
																	: el('span', { className: 'mds-brand-icon mds-routing-thumb-placeholder', 'aria-hidden': true })
															),
															el('td', null,
																el('strong', null,
																	el('a', { href: row.editUrl }, row.title)
																)
															),
															el('td', null, el('code', null, row.upc || '—')),
															el('td', null,
																el(SelectControl, {
																	label: sprintf(__('Routing mode for %s', 'mds-release-manager'), row.title),
																	hideLabelFromVision: true,
																	value: row.ownMode,
																	options: data.releaseModes || [],
																	onChange: (val) => updateReleaseRow(row.id, { ownMode: val }),
																	__next40pxDefaultSize: true,
																	__nextHasNoMarginBottom: true,
																})
															),
															el('td', null,
																isCustom
																	? el(TextControl, {
																		label: sprintf(__('Custom redirect URL for %s', 'mds-release-manager'), row.title),
																		hideLabelFromVision: true,
																		type: 'url',
																		placeholder: 'https://...',
																		value: row.customUrl || '',
																		onChange: (val) => updateReleaseRow(row.id, { customUrl: val }),
																		__next40pxDefaultSize: true,
																		__nextHasNoMarginBottom: true,
																	})
																	: el('span', {
																		className: `mds-routing-preview${row.preview && row.preview.is_warning ? ' is-warning' : ''}`,
																	}, (row.preview && row.preview.label) || '')
															)
														);
													})
												)
											)
										),
										routingNotice && el('div', { style: { marginTop: '12px' } },
											el(Notice, {
												status: routingNotice.status,
												isDismissible: true,
												onRemove: () => setRoutingNotice(null),
											}, routingNotice.message)
										),
										el('div', { className: 'mds-routing-footer' },
											el(Button, {
												variant: 'primary',
												isBusy: routingSaving,
												disabled: routingSaving,
												onClick: saveRoutingChanges,
											}, routingSaving ? __('Saving & syncing…', 'mds-release-manager') : __('Save Routing Changes', 'mds-release-manager'))
										)
									)
							)
						)
					)
				);
			}),

			// Native @wordpress/components Live Artist Preview Modal
			previewOpen && el(Modal, {
				title: __('Live Artist Preview', 'mds-release-manager'),
				className: 'mds-wp-preview-modal',
				onRequestClose: () => {
					setPreviewOpen(false);
					speak(__('Live Artist Preview closed.', 'mds-release-manager'), 'polite');
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
						title: __('Live Artist Preview', 'mds-release-manager'),
						srcDoc: buildSrcdoc(previewRes),
					})
				)
			)
		);
	}

	const app = el(ArtistWorkspace);
	if (wp.element.createRoot) {
		wp.element.createRoot(mountTarget).render(app);
	} else {
		wp.element.render(app, mountTarget);
	}
})(window.wp);
