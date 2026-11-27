(function (wp) {
	const { createElement: el } = wp.element;
	const { Button, Card, CardBody, CardFooter, CardHeader } = wp.components;
	const { __, sprintf } = wp.i18n;

	const data = window.mdsRmDashboardData;
	const root = document.getElementById('mds-rm-dashboard-root');
	if (!data || !root) {
		return;
	}

	function KpiCard({ label, value, sub, href, actionLabel }) {
		return el(Card, { className: 'mds-dash-kpi' },
			el(CardBody, null,
				el('div', { className: 'mds-dash-kpi__label' }, label),
				el('div', { className: 'mds-dash-kpi__value' }, value),
				sub && el('div', { className: 'mds-dash-kpi__sub' }, sub)
			),
			href && el(CardFooter, { isShaded: true, size: 'small' },
				el(Button, { variant: 'link', href }, actionLabel || __('View', 'mds-release-manager'))
			)
		);
	}

	function DashboardApp() {
		const counts = data.counts || {};
		const health = data.completeness || {};
		const archive = data.archive || {};
		const scheme = data.themeScheme || {};
		const ms = data.multisite || {};
		const urls = data.urls || {};
		const recent = Array.isArray(data.recentReleases) ? data.recentReleases : [];

		const modeLabel = scheme.isDark ? __('Dark', 'mds-release-manager') : __('Light', 'mds-release-manager');
		const totalIssues = (health.missingCover || 0) + (health.missingUpc || 0) + (health.missingArtist || 0);

		return el('div', { className: 'mds-dash' },
			el('header', { className: 'mds-dash-header' },
				el('div', { className: 'mds-dash-header__brand' },
					el('span', { className: 'mds-brand-icon mds-dash-header__logo', 'aria-hidden': true }),
					el('div', null,
						el('h1', { className: 'mds-dash-header__title' },
							__('Release Manager', 'mds-release-manager'),
							el('span', { className: 'mds-dash-badge' }, `v${data.version || ''}`),
							ms.isMultisite && el('span', { className: `mds-dash-badge ${ms.isHub ? 'is-hub' : 'is-spoke'}` },
								ms.isHub ? __('Multisite Hub', 'mds-release-manager') : __('Multisite Spoke', 'mds-release-manager')
							)
						),
						el('p', { className: 'mds-dash-header__subtitle' },
							__('Catalog overview, completeness health, archive routing, and active theme scheme.', 'mds-release-manager')
						)
					)
				),
				el('div', { className: 'mds-dash-header__actions' },
					(!ms.isMultisite || ms.isHub) && el(Button, { variant: 'primary', href: urls.addRelease }, __('+ Add Release', 'mds-release-manager')),
					(!ms.isMultisite || ms.isHub) && el(Button, { variant: 'secondary', href: urls.addArtist }, __('+ Add Artist', 'mds-release-manager')),
					data.canManage && el(Button, { variant: 'secondary', href: urls.importExport }, __('Import / Export', 'mds-release-manager')),
					data.canManage && el(Button, { variant: 'tertiary', href: urls.settings }, __('Settings', 'mds-release-manager'))
				)
			),

			el('section', { className: 'mds-dash-kpi-grid', 'aria-label': __('Catalog Summary', 'mds-release-manager') },
				el(KpiCard, {
					label: __('Published Releases', 'mds-release-manager'),
					value: String(counts.releasesPublished ?? 0),
					sub: counts.releasesDraft > 0
						// translators: %d: Number of draft releases.
						? sprintf(__('%d draft(s)', 'mds-release-manager'), counts.releasesDraft)
						: __('All releases published', 'mds-release-manager'),
					href: urls.allReleases,
					actionLabel: __('Manage Releases', 'mds-release-manager'),
				}),
				el(KpiCard, {
					label: __('Artists', 'mds-release-manager'),
					value: String(counts.artistsPublished ?? 0),
					sub: __('Published artist profiles', 'mds-release-manager'),
					href: urls.allArtists,
					actionLabel: __('Manage Artists', 'mds-release-manager'),
				}),
				el(KpiCard, {
					label: __('Catalog Completeness', 'mds-release-manager'),
					value: `${health.score ?? 100}%`,
					sub: totalIssues === 0
						? __('Cover art, UPC, and Artist metadata complete', 'mds-release-manager')
						// translators: %d: Number of missing metadata items.
						: sprintf(__('%d metadata item(s) need attention', 'mds-release-manager'), totalIssues),
					href: urls.allReleases,
					actionLabel: __('Review Catalog', 'mds-release-manager'),
				}),
				el(KpiCard, {
					label: __('Release Archive', 'mds-release-manager'),
					value: archive.enabled ? __('Catalog View', 'mds-release-manager') : __('Theme Default', 'mds-release-manager'),
					sub: archive.pageTitle || __('Default (/release/)', 'mds-release-manager'),
					href: archive.url || urls.templatesTab,
					actionLabel: archive.url ? __('View Archive', 'mds-release-manager') : __('Configure Archive', 'mds-release-manager'),
				})
			),

			el('div', { className: 'mds-dash-columns' },
				el('div', { className: 'mds-dash-col-main' },
					el(Card, { className: 'mds-dash-card' },
						el(CardHeader, null,
							el('strong', null, __('Catalog Health & Completeness', 'mds-release-manager'))
						),
						el(CardBody, null,
							totalIssues === 0
								? el('p', { className: 'mds-dash-ok' }, __('Every published release has Cover Art, a UPC/EAN barcode, and an assigned Artist.', 'mds-release-manager'))
								: el('ul', { className: 'mds-dash-checklist' },
									el('li', null,
										el('span', null, __('Missing Cover Art', 'mds-release-manager')),
										el('strong', { className: health.missingCover > 0 ? 'is-warn' : 'is-ok' }, String(health.missingCover || 0))
									),
									el('li', null,
										el('span', null, __('Missing UPC / EAN', 'mds-release-manager')),
										el('strong', { className: health.missingUpc > 0 ? 'is-warn' : 'is-ok' }, String(health.missingUpc || 0))
									),
									el('li', null,
										el('span', null, __('Missing Artist Assignment', 'mds-release-manager')),
										el('strong', { className: health.missingArtist > 0 ? 'is-warn' : 'is-ok' }, String(health.missingArtist || 0))
									)
								)
						),
						el(CardFooter, { isShaded: true, size: 'small' },
							el(Button, { variant: 'secondary', size: 'compact', href: urls.allReleases }, __('Open Releases List', 'mds-release-manager'))
						)
					),

					el(Card, { className: 'mds-dash-card' },
						el(CardHeader, null,
							el('strong', null, __('Recent Releases', 'mds-release-manager')),
							el(Button, { variant: 'link', href: urls.allReleases }, __('View All', 'mds-release-manager'))
						),
						el(CardBody, null,
							recent.length === 0
								? el('p', null, __('No releases yet. Add your first release or import a catalog to get started.', 'mds-release-manager'))
								: el('ul', { className: 'mds-dash-recent-list' },
									recent.map((item) => el('li', { key: item.id, className: 'mds-dash-recent-item' },
										el('div', { className: 'mds-dash-recent-item__media' },
											item.thumbUrl
												? el('img', { src: item.thumbUrl, alt: '', width: 40, height: 40 })
												: el('span', { className: 'mds-brand-icon', 'aria-hidden': true })
										),
										el('div', { className: 'mds-dash-recent-item__info' },
											el('a', { href: item.editUrl, className: 'mds-dash-recent-item__title' }, item.title || __('(Untitled)', 'mds-release-manager')),
											el('div', { className: 'mds-dash-recent-item__meta' },
												[item.year, item.upc ? `UPC ${item.upc}` : '', item.status !== 'publish' ? item.status : ''].filter(Boolean).join(' · ')
											)
										),
										el('div', { className: 'mds-dash-recent-item__actions' },
											el(Button, { variant: 'tertiary', size: 'small', href: item.editUrl }, __('Edit', 'mds-release-manager')),
											item.status === 'publish' && item.viewUrl && el(Button, { variant: 'tertiary', size: 'small', href: item.viewUrl, target: '_blank' }, __('View', 'mds-release-manager'))
										)
									))
								)
						)
					)
				),

				el('div', { className: 'mds-dash-col-side' },
					el(Card, { className: 'mds-dash-card' },
						el(CardHeader, null,
							el('strong', null, __('Active Theme Scheme & Templates', 'mds-release-manager'))
						),
						el(CardBody, null,
							el('div', { className: 'mds-dash-scheme-meta' },
								el('div', { className: 'mds-theme-preset-bar__swatches', 'aria-hidden': true },
									scheme.background && el('span', { className: 'mds-theme-preset-bar__dot', style: { backgroundColor: scheme.background }, title: __('Background', 'mds-release-manager') }),
									scheme.surface && el('span', { className: 'mds-theme-preset-bar__dot', style: { backgroundColor: scheme.surface }, title: __('Surface', 'mds-release-manager') }),
									scheme.text && el('span', { className: 'mds-theme-preset-bar__dot', style: { backgroundColor: scheme.text }, title: __('Text', 'mds-release-manager') }),
									scheme.border && el('span', { className: 'mds-theme-preset-bar__dot', style: { backgroundColor: scheme.border }, title: __('Border', 'mds-release-manager') }),
									scheme.accent && el('span', { className: 'mds-theme-preset-bar__dot', style: { backgroundColor: scheme.accent }, title: __('Accent', 'mds-release-manager') })
								),
								el('div', null,
									el('strong', null, `${scheme.themeName || __('Active Theme', 'mds-release-manager')} (${modeLabel})`),
									el('div', { className: 'mds-dash-muted' }, scheme.sourceLabel || '')
								)
							),
							el('p', { className: 'mds-dash-muted', style: { marginTop: '10px', marginBottom: 0 } },
								__('Colors adapt automatically to your active theme or page builder and reset cleanly if you switch schemes.', 'mds-release-manager')
							)
						),
						data.canManage && el(CardFooter, { isShaded: true, size: 'small' },
							el('div', { style: { display: 'flex', flexWrap: 'wrap', gap: '6px' } },
								el(Button, { variant: 'secondary', size: 'compact', href: urls.templatesTab }, __('Default Templates', 'mds-release-manager')),
								el(Button, { variant: 'tertiary', size: 'compact', href: urls.blocksTab }, __('Block Defaults', 'mds-release-manager')),
								urls.siteEditor && el(Button, { variant: 'tertiary', size: 'compact', href: urls.siteEditor }, __('Site Editor', 'mds-release-manager'))
							)
						)
					),

					ms.isMultisite && el(Card, { className: 'mds-dash-card' },
						el(CardHeader, null,
							el('strong', null, __('Multisite Hub & Spoke', 'mds-release-manager'))
						),
						el(CardBody, null,
							el('p', { style: { marginTop: 0 } },
								ms.isHub
									// translators: %d: Number of spoke sites.
									? sprintf(__('This site is the Catalog Hub syncing to %d Spoke site(s).', 'mds-release-manager'), ms.spokeCount || 0)
									: __('This site is a read-only Spoke receiving synced releases and artists from the Hub.', 'mds-release-manager')
							)
						),
						ms.networkSettingsUrl && el(CardFooter, { isShaded: true, size: 'small' },
							el(Button, { variant: 'secondary', size: 'compact', href: ms.networkSettingsUrl }, __('Network Settings', 'mds-release-manager'))
						)
					)
				)
			)
		);
	}

	const app = el(DashboardApp);
	if (wp.element.createRoot) {
		wp.element.createRoot(root).render(app);
	} else {
		wp.element.render(app, root);
	}
})(window.wp);
