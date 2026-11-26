(function () {
	'use strict';

	// Shared single-playback audio controller across all cards on the page.
	let activeAudio = null;
	let activePlayBtn = null;
	let activeTimer = null;

	function stopActiveAudio() {
		if (activeTimer) {
			window.clearTimeout(activeTimer);
			activeTimer = null;
		}
		if (activeAudio) {
			try {
				activeAudio.pause();
				activeAudio.currentTime = 0;
			} catch (e) {
				// Ignore media errors on stop.
			}
			activeAudio = null;
		}
		if (activePlayBtn) {
			activePlayBtn.classList.remove('is-playing', 'is-loading');
			activePlayBtn.setAttribute('aria-pressed', 'false');
			const icon = activePlayBtn.querySelector('.mds-catalog-card__play-icon');
			if (icon) icon.textContent = '\u25B6';
			activePlayBtn = null;
		}
	}

	// Pause card preview if another Release Player starts playing.
	window.addEventListener('mds-rm:audio-play', function (ev) {
		if (!ev || !ev.detail || ev.detail.source !== 'catalog-explorer') {
			stopActiveAudio();
		}
	});

	function createEl(tag, className, text) {
		const node = document.createElement(tag);
		if (className) node.className = className;
		if (text !== undefined && text !== null) node.textContent = String(text);
		return node;
	}

	function buildCardNode(card, config) {
		const article = createEl('article', 'mds-catalog-card');
		article.setAttribute('data-release-id', String(card.id));

		const media = createEl('div', 'mds-catalog-card__media');
		const coverLink = createEl('a', 'mds-catalog-card__cover-link');
		coverLink.href = card.url || '#';
		if (card.isExternal) {
			coverLink.target = '_blank';
			coverLink.rel = 'noopener noreferrer';
		}

		if (card.coverUrl) {
			const img = createEl('img', 'mds-catalog-card__img');
			img.src = card.coverUrl;
			img.alt = card.title || '';
			img.loading = 'lazy';
			coverLink.appendChild(img);
		} else {
			const ph = createEl('span', 'mds-catalog-card__placeholder');
			ph.setAttribute('aria-hidden', 'true');
			ph.appendChild(createEl('span', 'dashicons dashicons-album'));
			coverLink.appendChild(ph);
		}
		media.appendChild(coverLink);

		if (card.releaseType) {
			media.appendChild(createEl('span', 'mds-catalog-card__type', card.releaseType));
		}

		if (config.showAudio && card.hasAudio) {
			const playBtn = createEl('button', 'mds-catalog-card__play');
			playBtn.type = 'button';
			playBtn.setAttribute('data-mds-play-id', String(card.id));
			playBtn.setAttribute('aria-pressed', 'false');
			playBtn.setAttribute('aria-label', (config.labels.play || 'Play preview') + ' — ' + (card.title || ''));
			const icon = createEl('span', 'mds-catalog-card__play-icon', '\u25B6');
			icon.setAttribute('aria-hidden', 'true');
			playBtn.appendChild(icon);
			media.appendChild(playBtn);
		}
		article.appendChild(media);

		const body = createEl('div', 'mds-catalog-card__body');
		const h3 = createEl('h3', 'mds-catalog-card__title');
		const titleLink = createEl('a', '', card.title || '');
		titleLink.href = card.url || '#';
		if (card.isExternal) {
			titleLink.target = '_blank';
			titleLink.rel = 'noopener noreferrer';
		}
		h3.appendChild(titleLink);
		body.appendChild(h3);

		const meta = createEl('div', 'mds-catalog-card__meta');
		if (card.artistName) {
			if (card.artistUrl) {
				const aLink = createEl('a', 'mds-catalog-card__artist', card.artistName);
				aLink.href = card.artistUrl;
				meta.appendChild(aLink);
			} else {
				meta.appendChild(createEl('span', 'mds-catalog-card__artist', card.artistName));
			}
		}
		if (card.year) {
			meta.appendChild(createEl('span', 'mds-catalog-card__year', card.year));
		}
		body.appendChild(meta);

		const linksObj = card.streamingLinks && typeof card.streamingLinks === 'object' ? card.streamingLinks : {};
		const providerNames = Object.keys(linksObj);
		if (config.showLinks && (card.buyNowUrl || providerNames.length > 0)) {
			const actions = createEl('div', 'mds-catalog-card__actions');
			if (card.buyNowUrl) {
				const buy = createEl('a', 'mds-catalog-card__btn is-primary', config.labels.buyNow || 'Buy Now');
				buy.href = card.buyNowUrl;
				buy.target = '_blank';
				buy.rel = 'noopener noreferrer';
				actions.appendChild(buy);
			}
			providerNames.slice(0, 2).forEach(function (prov) {
				const link = createEl('a', 'mds-catalog-card__btn', prov);
				link.href = linksObj[prov];
				link.target = '_blank';
				link.rel = 'noopener noreferrer';
				actions.appendChild(link);
			});
			body.appendChild(actions);
		}

		article.appendChild(body);
		return article;
	}

	function initExplorer(root) {
		let config;
		try {
			config = JSON.parse(root.getAttribute('data-mds-catalog-config') || '{}');
		} catch (e) {
			return;
		}
		if (!config || !config.restUrl) {
			return;
		}

		const grid = root.querySelector('[data-mds-grid]');
		const paginationEl = root.querySelector('[data-mds-pagination]');
		const countEl = root.querySelector('.mds-catalog-count');
		const chipsEl = root.querySelector('[data-mds-chips]');
		const filterToggleBtn = root.querySelector('.mds-catalog-filter-toggle');
		const filtersPanel = root.querySelector('.mds-catalog-filters');
		const labels = config.labels || {};

		const state = Object.assign(
			{
				search: '',
				artist: config.scopedArtist || '',
				type: '',
				year: 0,
				tag: '',
				label: '',
				format: '',
				sort: config.defaultSort || 'date_desc',
				page: 1,
				per_page: config.perPage || 12,
			},
			config.state || {}
		);

		let activeRequestId = 0;
		let searchTimer = null;

		if (filterToggleBtn && filtersPanel) {
			filterToggleBtn.addEventListener('click', function () {
				const expanded = filterToggleBtn.getAttribute('aria-expanded') === 'true';
				filterToggleBtn.setAttribute('aria-expanded', expanded ? 'false' : 'true');
				filtersPanel.classList.toggle('is-open', !expanded);
			});
		}

		function syncUrlParams() {
			if (!window.history || typeof window.history.replaceState !== 'function') {
				return;
			}
			const url = new URL(window.location.href);
			const mapping = [
				['q', state.search],
				['artist', config.scopedArtist ? '' : state.artist],
				['type', state.type],
				['year', state.year > 0 ? String(state.year) : ''],
				['tag', state.tag],
				['label', state.label],
				['format', state.format],
				['sort', state.sort !== (config.defaultSort || 'date_desc') ? state.sort : ''],
				['cpage', state.page > 1 ? String(state.page) : ''],
			];
			mapping.forEach(function (pair) {
				if (pair[1]) {
					url.searchParams.set(pair[0], pair[1]);
				} else {
					url.searchParams.delete(pair[0]);
				}
			});
			window.history.replaceState(null, '', url.toString());
		}

		function syncControlsFromState() {
			const inputs = root.querySelectorAll('[data-mds-filter]');
			inputs.forEach(function (input) {
				const key = input.getAttribute('data-mds-filter');
				if (key === 'year') {
					input.value = String(state.year || 0);
				} else if (state[key] !== undefined) {
					input.value = String(state[key] || '');
				}
			});
		}

		function renderActiveChips() {
			if (!chipsEl) return;
			chipsEl.textContent = '';

			const active = [];
			if (state.search) active.push({ key: 'search', label: '"' + state.search + '"' });
			if (!config.scopedArtist && state.artist) {
				const sel = root.querySelector('[data-mds-filter="artist"]');
				const optText = sel && sel.selectedOptions && sel.selectedOptions[0] ? sel.selectedOptions[0].textContent : state.artist;
				active.push({ key: 'artist', label: optText });
			}
			if (state.type) {
				const sel = root.querySelector('[data-mds-filter="type"]');
				const optText = sel && sel.selectedOptions && sel.selectedOptions[0] ? sel.selectedOptions[0].textContent : state.type;
				active.push({ key: 'type', label: optText });
			}
			if (state.year > 0) active.push({ key: 'year', label: String(state.year) });
			if (state.tag) {
				const sel = root.querySelector('[data-mds-filter="tag"]');
				const optText = sel && sel.selectedOptions && sel.selectedOptions[0] ? sel.selectedOptions[0].textContent : state.tag;
				active.push({ key: 'tag', label: '#' + optText });
			}
			if (state.label) active.push({ key: 'label', label: state.label });
			if (state.format) {
				const sel = root.querySelector('[data-mds-filter="format"]');
				const optText = sel && sel.selectedOptions && sel.selectedOptions[0] ? sel.selectedOptions[0].textContent : state.format;
				active.push({ key: 'format', label: optText });
			}

			if (!active.length) return;

			active.forEach(function (chip) {
				const btn = createEl('button', 'mds-catalog-chip', chip.label + ' \u00D7');
				btn.type = 'button';
				btn.addEventListener('click', function () {
					state[chip.key] = chip.key === 'year' ? 0 : '';
					state.page = 1;
					syncControlsFromState();
					fetchCatalog(false);
				});
				chipsEl.appendChild(btn);
			});

			const clearBtn = createEl('button', 'mds-catalog-clear', labels.clearAll || 'Clear all filters');
			clearBtn.type = 'button';
			clearBtn.addEventListener('click', function () {
				state.search = '';
				state.artist = config.scopedArtist || '';
				state.type = '';
				state.year = 0;
				state.tag = '';
				state.label = '';
				state.format = '';
				state.sort = config.defaultSort || 'date_desc';
				state.page = 1;
				syncControlsFromState();
				fetchCatalog(false);
			});
			chipsEl.appendChild(clearBtn);
		}

		function renderPagination(totalPages, currentPage) {
			if (!paginationEl) return;
			paginationEl.textContent = '';
			if (totalPages <= 1) return;

			if (config.pagination === 'load_more') {
				if (currentPage < totalPages) {
					const btn = createEl('button', 'mds-catalog-load-more', labels.loadMore || 'Load More Releases');
					btn.type = 'button';
					btn.addEventListener('click', function () {
						state.page = currentPage + 1;
						fetchCatalog(true);
					});
					paginationEl.appendChild(btn);
				}
				return;
			}

			const nav = createEl('nav', 'mds-catalog-pages');
			nav.setAttribute('aria-label', 'Catalog Pagination');

			const addBtn = function (label, targetPage, isActive, isDisabled) {
				const btn = createEl('button', 'mds-catalog-page-btn' + (isActive ? ' is-active' : ''), label);
				btn.type = 'button';
				btn.disabled = Boolean(isDisabled);
				if (isActive) btn.setAttribute('aria-current', 'page');
				btn.addEventListener('click', function () {
					state.page = targetPage;
					fetchCatalog(false);
					root.scrollIntoView({ behavior: 'smooth', block: 'start' });
				});
				nav.appendChild(btn);
			};

			addBtn(labels.prev || 'Previous', Math.max(1, currentPage - 1), false, currentPage <= 1);

			const pagesToShow = [];
			for (let p = 1; p <= totalPages; p++) {
				if (p === 1 || p === totalPages || Math.abs(p - currentPage) <= 1) {
					pagesToShow.push(p);
				} else if (pagesToShow[pagesToShow.length - 1] !== '...') {
					pagesToShow.push('...');
				}
			}

			pagesToShow.forEach(function (entry) {
				if (entry === '...') {
					nav.appendChild(createEl('span', 'mds-catalog-page-ellipsis', '\u2026'));
				} else {
					addBtn(String(entry), entry, entry === currentPage, false);
				}
			});

			addBtn(labels.next || 'Next', Math.min(totalPages, currentPage + 1), false, currentPage >= totalPages);
			paginationEl.appendChild(nav);
		}

		function fetchCatalog(appendMode) {
			const reqId = ++activeRequestId;
			root.classList.add('is-loading');
			syncUrlParams();
			renderActiveChips();

			const queryUrl = new URL(config.restUrl);
			if (state.search) queryUrl.searchParams.set('q', state.search);
			if (state.artist) queryUrl.searchParams.set('artist', state.artist);
			if (state.type) queryUrl.searchParams.set('type', state.type);
			if (state.year > 0) queryUrl.searchParams.set('year', String(state.year));
			if (state.tag) queryUrl.searchParams.set('tag', state.tag);
			if (state.label) queryUrl.searchParams.set('label', state.label);
			if (state.format) queryUrl.searchParams.set('format', state.format);
			if (state.sort) queryUrl.searchParams.set('sort', state.sort);
			queryUrl.searchParams.set('page', String(state.page));
			queryUrl.searchParams.set('per_page', String(state.per_page));

			window
				.fetch(queryUrl.toString(), { credentials: 'same-origin' })
				.then(function (res) {
					return res.json();
				})
				.then(function (data) {
					if (reqId !== activeRequestId) return;
					const items = Array.isArray(data.items) ? data.items : [];
					const total = Number(data.total || 0);
					const pages = Number(data.pages || 0);

					if (!appendMode && grid) {
						stopActiveAudio();
						grid.textContent = '';
					}

					if (grid) {
						items.forEach(function (card) {
							grid.appendChild(buildCardNode(card, config));
						});
					}

					const visibleCount = grid ? grid.querySelectorAll('.mds-catalog-card').length : items.length;
					if (countEl) {
						if (total > 0) {
							countEl.textContent = (labels.showing || 'Showing %1$d of %2$d releases')
								.replace('%1$d', String(visibleCount))
								.replace('%2$d', String(total));
						} else {
							countEl.textContent = labels.noResults || 'No releases match your current filters.';
						}
					}

					renderPagination(pages, state.page);
				})
				.catch(function () {
					if (reqId !== activeRequestId) return;
					if (countEl) {
						countEl.textContent = labels.noResults || 'Unable to load releases.';
					}
				})
				.finally(function () {
					if (reqId === activeRequestId) {
						root.classList.remove('is-loading');
					}
				});
		}

		// Bind filter & search inputs.
		root.querySelectorAll('[data-mds-filter]').forEach(function (control) {
			const key = control.getAttribute('data-mds-filter');
			if (key === 'search') {
				control.addEventListener('input', function () {
					if (searchTimer) window.clearTimeout(searchTimer);
					searchTimer = window.setTimeout(function () {
						state.search = control.value.trim();
						state.page = 1;
						fetchCatalog(false);
					}, 250);
				});
			} else {
				control.addEventListener('change', function () {
					state[key] = key === 'year' ? parseInt(control.value || '0', 10) || 0 : control.value;
					state.page = 1;
					fetchCatalog(false);
				});
			}
		});

		// Bind initial SSR pagination buttons & inline audio play buttons via event delegation.
		root.addEventListener('click', function (ev) {
			const pageBtn = ev.target.closest('[data-mds-page]');
			if (pageBtn) {
				state.page = parseInt(pageBtn.getAttribute('data-mds-page') || '1', 10) || 1;
				fetchCatalog(false);
				root.scrollIntoView({ behavior: 'smooth', block: 'start' });
				return;
			}

			const loadMoreBtn = ev.target.closest('[data-mds-load-more]');
			if (loadMoreBtn) {
				state.page = (state.page || 1) + 1;
				fetchCatalog(true);
				return;
			}

			const playBtn = ev.target.closest('[data-mds-play-id]');
			if (playBtn) {
				const releaseId = playBtn.getAttribute('data-mds-play-id');
				if (!releaseId) return;

				if (activePlayBtn === playBtn && activeAudio) {
					stopActiveAudio();
					return;
				}

				stopActiveAudio();
				activePlayBtn = playBtn;
				playBtn.classList.add('is-loading');
				const icon = playBtn.querySelector('.mds-catalog-card__play-icon');
				if (icon) icon.textContent = '\u2026';

				window.dispatchEvent(new CustomEvent('mds-rm:audio-play', { detail: { source: 'catalog-explorer', releaseId: releaseId } }));

				window
					.fetch(config.audioBaseUrl + '/' + encodeURIComponent(releaseId) + '/audio', { credentials: 'same-origin' })
					.then(function (res) {
						return res.json();
					})
					.then(function (manifest) {
						if (activePlayBtn !== playBtn) return;
						const tracks = manifest && Array.isArray(manifest.tracks) ? manifest.tracks : [];
						const firstTrack = tracks.find(function (t) {
							return t && t.streamUrl;
						});
						if (!firstTrack) {
							stopActiveAudio();
							return;
						}
						const audio = new window.Audio(firstTrack.streamUrl);
						activeAudio = audio;
						audio.addEventListener('ended', stopActiveAudio);
						audio.addEventListener('error', stopActiveAudio);
						audio
							.play()
							.then(function () {
								if (activePlayBtn !== playBtn) return;
								playBtn.classList.remove('is-loading');
								playBtn.classList.add('is-playing');
								playBtn.setAttribute('aria-pressed', 'true');
								if (icon) icon.textContent = '\u275A\u275A';
								if (!manifest.isTimedPreview && !firstTrack.isTimedPreview) {
									activeTimer = window.setTimeout(stopActiveAudio, 20000);
								}
							})
							.catch(stopActiveAudio);
					})
					.catch(stopActiveAudio);
			}
		});

		renderActiveChips();
	}

	document.addEventListener('DOMContentLoaded', function () {
		document.querySelectorAll('.mds-catalog-explorer[data-mds-catalog-config]').forEach(initExplorer);
	});
})();
