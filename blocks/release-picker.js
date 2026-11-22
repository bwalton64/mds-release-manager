(function (wp) {
    const { createElement: el, Fragment } = wp.element;
    const { Button, ComboboxControl, Notice, TextControl } = wp.components;
    const { __, sprintf } = wp.i18n;
    const requestTimeout = 30000;
    const cacheTtl = 60000;
    const lookups = {};
    const defaultPerPage = 20;

    function canUsePreloadedCatalog() {
        return Boolean(
            window.mdsRmCatalog
            && Array.isArray(window.mdsRmCatalog.items)
            && !window.mdsRmCatalog.truncated
            && !window.__phaseOneLookupCalls
        );
    }

    function queryPreloadedCatalog(term = '', filter = null) {
        const all = Array.isArray(window.mdsRmCatalog?.items) ? window.mdsRmCatalog.items : [];
        const base = typeof filter === 'function' ? all.filter(filter) : all;
        const clean = String(term || '').trim();
        if (!clean) {
            return base;
        }
        if (/^\d+$/.test(clean)) {
            const exact = base.filter((item) => String(item.upc || '') === clean);
            if (exact.length > 0) {
                return exact;
            }
        }
        const needle = clean.toLowerCase();
        return base.filter((item) =>
            String(item.title || '').toLowerCase().includes(needle)
            || String(item.artist || '').toLowerCase().includes(needle)
        );
    }

    function paginatePreloadedCatalog(items, page = 1, perPage = defaultPerPage) {
        const size = Math.max(1, Number(perPage) || defaultPerPage);
        const current = Math.max(1, Number(page) || 1);
        return {
            items: items.slice((current - 1) * size, current * size),
            pages: Math.ceil(items.length / size),
        };
    }

    function findPreloadedRelease({ releaseId = 0, upc = '' } = {}) {
        const all = Array.isArray(window.mdsRmCatalog?.items) ? window.mdsRmCatalog.items : [];
        const numId = Number(releaseId) || 0;
        if (numId > 0) {
            return all.find((item) => Number(item.id) === numId) || null;
        }
        const cleanUpc = String(upc || '').trim();
        if (cleanUpc !== '') {
            return all.find((item) => String(item.upc || '') === cleanUpc) || null;
        }
        return null;
    }

    function queryPreloadedArtists(term = '', filter = null) {
        const all = Array.isArray(window.mdsRmCatalog?.artists) ? window.mdsRmCatalog.artists : [];
        const base = typeof filter === 'function' ? all.filter(filter) : all;
        const clean = String(term || '').trim().toLowerCase();
        if (!clean) {
            return base;
        }
        return base.filter((artist) => String(artist.title || '').toLowerCase().includes(clean));
    }

    function findPreloadedArtist({ artistId = 0, uuid = '' } = {}) {
        const all = Array.isArray(window.mdsRmCatalog?.artists) ? window.mdsRmCatalog.artists : [];
        const numId = Number(artistId) || 0;
        if (numId > 0) {
            return all.find((item) => Number(item.id) === numId) || null;
        }
        const cleanUuid = String(uuid || '').trim();
        if (cleanUuid !== '') {
            return all.find((item) => String(item.uuid || '') === cleanUuid) || null;
        }
        return null;
    }

    window.mdsRmToolkit = {
        getReleases({ search = '', page = 1, perPage = defaultPerPage, filter = null } = {}) {
            const matches = queryPreloadedCatalog(search, filter);
            return paginatePreloadedCatalog(matches, page, perPage);
        },
        findRelease: findPreloadedRelease,
        getCatalog() {
            return Array.isArray(window.mdsRmCatalog?.items) ? window.mdsRmCatalog.items.slice() : [];
        },
        getArtists({ search = '', page = 1, perPage = defaultPerPage, filter = null } = {}) {
            const matches = queryPreloadedArtists(search, filter);
            return paginatePreloadedCatalog(matches, page, perPage);
        },
        findArtist: findPreloadedArtist,
        getArtistCatalog() {
            return Array.isArray(window.mdsRmCatalog?.artists) ? window.mdsRmCatalog.artists.slice() : [];
        },
    };

    function fetchLookup(path, signal) {
        const entry = lookups[path];
        if (!signal && entry && (entry.pending || Date.now() - entry.time < cacheTtl)) {
            return entry.promise;
        }
        const record = { pending: true, time: Date.now() };
        record.promise = wp.apiFetch(signal ? { path, signal } : { path }).then(
            (data) => { record.pending = false; record.time = Date.now(); return data; },
            (error) => { delete lookups[path]; throw error; }
        );
        if (!signal) {
            lookups[path] = record;
        }
        return record.promise;
    }

    function useLookup(route, params, enabled, delay = 0) {
        const path = wp.url.addQueryArgs('/mds/v1/' + route, params);
        const [result, setResult] = wp.element.useState({});
        wp.element.useEffect(() => {
            if (!enabled) {
                return undefined;
            }
            let active = true;
            const timers = [];
            const controllers = [];
            // Retry transient failures: editor boot can saturate PHP workers
            // with block previews, delaying these lookups past the timeout.
            function fail(error, attempt) {
                if (!active) return;
                if (attempt < 2) {
                    timers.push(setTimeout(() => { if (active) run(attempt + 1); }, 2000 * (attempt + 1)));
                    return;
                }
                setResult({ path, error });
            }
            function run(attempt) {
                let timedOut = false;
                const controller = typeof AbortController !== 'undefined' ? new AbortController() : null;
                if (controller) controllers.push(controller);
                const timer = setTimeout(() => {
                    timedOut = true;
                    if (controller) controller.abort();
                    fail({ code: 'timeout' }, attempt);
                }, requestTimeout);
                timers.push(timer);
                fetchLookup(path, controller ? controller.signal : undefined).then(
                    (data) => { clearTimeout(timer); if (active && !timedOut) setResult({ path, data }); },
                    (error) => { clearTimeout(timer); if (active && !timedOut) fail(error, attempt); }
                );
            }
            timers.push(setTimeout(() => run(0), delay));
            return () => {
                active = false;
                timers.forEach(clearTimeout);
                controllers.forEach((controller) => controller.abort());
            };
        }, [path, delay, enabled]);
        return result.path === path ? result : { loading: true };
    }

    function title(item) {
        return wp.htmlEntities.decodeEntities(item.title) || __('Untitled Release', 'mds-release-manager');
    }

    function option(item) {
        const artist = wp.htmlEntities.decodeEntities(item.artist);
        return { value: String(item.id), label: [title(item), artist].filter(Boolean).join(' — ') };
    }

    window.mdsRmUseReleasePicker = function ({
        attributes,
        setAttributes,
        contextId,
        enabled,
        filter,
        metaBindings,
        debugFields,
        onSelect,
    }) {
        const active = enabled !== false;
        const [search, setSearch] = wp.element.useState('');
        const [page, setPage] = wp.element.useState(1);
        const saved = attributes.releaseId || attributes.upcOverride;
        const usePreloaded = canUsePreloadedCatalog();
        const preloadedExplicit = usePreloaded && saved
            ? findPreloadedRelease({ releaseId: attributes.releaseId, upc: attributes.upcOverride })
            : null;
        const preloadedContext = usePreloaded && contextId
            ? findPreloadedRelease({ releaseId: contextId })
            : null;
        const needsSourceRest = active && (!usePreloaded || (Boolean(contextId) && !preloadedContext && !preloadedExplicit));
        const restResults = useLookup('release-links-choices', { search, page }, active && !usePreloaded, 250);
        const restSource = useLookup(
            'release-links-source',
            { context_id: contextId, release_id: attributes.releaseId || 0, upc: attributes.upcOverride || '' },
            needsSourceRest
        );

        const results = usePreloaded
            ? { loading: false, data: window.mdsRmToolkit.getReleases({ search, page, filter }) }
            : restResults;
        const source = !needsSourceRest
            ? {
                loading: false,
                data: {
                    missing: Boolean(saved && !preloadedExplicit),
                    item: preloadedExplicit || preloadedContext || null,
                },
            }
            : restSource;

        const resolved = source.data?.item;
        const selected = saved && !source.data?.missing ? resolved : null;
        const rawItems = results.data?.items || [];
        const items = !usePreloaded && typeof filter === 'function' ? rawItems.filter(filter) : rawItems;
        const options = items.map(option);
        if (selected && !options.some(({ value }) => value === String(selected.id))) options.unshift(option(selected));

        function applyBindings(item) {
            if (!metaBindings || typeof metaBindings !== 'object') {
                return {};
            }
            const patch = {};
            Object.entries(metaBindings).forEach(([attrKey, sourceKey]) => {
                if (!item) {
                    patch[attrKey] = undefined;
                } else if (typeof sourceKey === 'function') {
                    patch[attrKey] = sourceKey(item);
                } else if (typeof sourceKey === 'string') {
                    patch[attrKey] = item[sourceKey];
                }
            });
            return patch;
        }

        const changeRelease = (value) => {
            const item = items.find((candidate) => String(candidate.id) === value)
                || (selected && String(selected.id) === value ? selected : null);
            if (!value || !item) {
                const cleared = { releaseId: 0, upcOverride: '', ...applyBindings(null) };
                setAttributes(cleared);
                if (typeof onSelect === 'function') onSelect(null, setAttributes);
                return;
            }
            // Bind to the catalog's primary ID (UPC/EAN); keep the post ID only when no UPC exists.
            const nextAttrs = {
                upcOverride: item.upc || '',
                releaseId: item.upc ? 0 : Number(item.id) || 0,
                ...applyBindings(item),
            };
            setAttributes(nextAttrs);
            if (typeof onSelect === 'function') onSelect(item, setAttributes);
        };
        // translators: %s: The Release title.
        const contextMessage = __('Using the current context: %s.', 'mds-release-manager');
        const fallbackMessage = resolved
            ? sprintf(contextMessage, title(resolved))
            : __('No Release is available in this context.', 'mds-release-manager');
        const extraDebugFields = typeof debugFields === 'function'
            ? debugFields(selected || resolved, attributes)
            : Array.isArray(debugFields) ? debugFields : [];

        return {
            source,
            resolved,
            selected,
            picker: el(Fragment, null,
                el(ComboboxControl, {
                    label: __('Release', 'mds-release-manager'),
                    help: __('Search by Release title or Artist name. Leave blank to use the current context.', 'mds-release-manager'),
                    value: selected ? String(selected.id) : null,
                    options,
                    isLoading: Boolean(results.loading || source.loading),
                    onFilterValueChange: (value) => { setSearch(value.trim().slice(0, 160)); setPage(1); },
                    onChange: changeRelease,
                }),
                window.mdsRmDebug && el(TextControl, {
                    label: __('Primary ID (UPC/EAN)', 'mds-release-manager'),
                    help: __('Shown because WP_DEBUG is enabled. The block resolves its Release through this ID.', 'mds-release-manager'),
                    value: attributes.upcOverride || (attributes.releaseId ? '#' + attributes.releaseId : ''),
                    readOnly: true,
                    onChange: () => {},
                }),
                window.mdsRmDebug && extraDebugFields.map((field, index) => field && el(TextControl, {
                    key: field.key || field.label || index,
                    label: field.label,
                    help: field.help || __('Shown because WP_DEBUG is enabled.', 'mds-release-manager'),
                    value: field.value != null ? String(field.value) : '',
                    readOnly: true,
                    onChange: () => {},
                })),
                !!saved && el(Button, { variant: 'secondary', onClick: () => changeRelease(null) }, __('Use current context', 'mds-release-manager')),
                page > 1 && el(Button, { variant: 'tertiary', onClick: () => setPage(page - 1) }, __('Previous results', 'mds-release-manager')),
                page < results.data?.pages && el(Button, { variant: 'tertiary', onClick: () => setPage(page + 1) }, __('Next results', 'mds-release-manager')),
                results.error && el(Notice, { status: 'warning', isDismissible: false }, __('Release search failed. Change the search to try again.', 'mds-release-manager')),
                source.error && el(Notice, { status: 'warning', isDismissible: false }, __('The Release selection could not be checked. The saved selection has not changed.', 'mds-release-manager')),
                source.data?.missing && el(Notice, { status: 'warning', isDismissible: false },
                    __('The selected Release is unavailable. The saved selection has not changed.', 'mds-release-manager'), ' ', fallbackMessage),
                !saved && resolved && el('p', null, fallbackMessage)
            ),
        };
    };
})(window.wp);
