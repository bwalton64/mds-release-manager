(function (wp) {
    const { createElement: el, Fragment } = wp.element;
    const { Button, ComboboxControl, Notice } = wp.components;
    const { __, sprintf } = wp.i18n;

    function useLookup(route, params, delay = 0) {
        const path = wp.url.addQueryArgs('/mds/v1/' + route, params);
        const [result, setResult] = wp.element.useState({});
        wp.element.useEffect(() => {
            let active = true;
            const timer = setTimeout(() => {
                wp.apiFetch({ path }).then(
                    (data) => { if (active) setResult({ path, data }); },
                    (error) => { if (active) setResult({ path, error }); }
                );
            }, delay);
            return () => { active = false; clearTimeout(timer); };
        }, [path, delay]);
        return result.path === path ? result : { loading: true };
    }

    function title(item) {
        return wp.htmlEntities.decodeEntities(item.title) || __('Untitled Release', 'mds-release-manager');
    }

    function option(item) {
        const artist = wp.htmlEntities.decodeEntities(item.artist);
        return { value: String(item.id), label: [title(item), artist, item.upc || `#${item.id}`].filter(Boolean).join(' — ') };
    }

    window.mdsRmUseReleasePicker = function ({ attributes, setAttributes, contextId }) {
            const [search, setSearch] = wp.element.useState('');
            const [page, setPage] = wp.element.useState(1);
            const results = useLookup('release-links-choices', { search, page }, 250);
            const source = useLookup('release-links-source', { context_id: contextId, release_id: attributes.releaseId || 0, upc: attributes.upcOverride || '' });
            const saved = attributes.releaseId || attributes.upcOverride;
            const resolved = source.data?.item;
            const selected = saved && !source.data?.missing ? resolved : null;
            const options = (results.data?.items || []).map(option);
            if (selected && !options.some(({ value }) => value === String(selected.id))) options.unshift(option(selected));
            const changeRelease = (value) => setAttributes({ releaseId: Number(value) || 0, upcOverride: '' });
            // translators: %s: The Release title.
            const contextMessage = __('Using the current context: %s.', 'mds-release-manager');
            const fallbackMessage = resolved
                ? sprintf(contextMessage, title(resolved))
                : __('No Release is available in this context.', 'mds-release-manager');
            return { source, resolved, picker: el(Fragment, null,
                        el(ComboboxControl, {
                            label: __('Release', 'mds-release-manager'),
                            help: __('Search by title or enter the full UPC/EAN. Leave blank to use the current context.', 'mds-release-manager'),
                            value: selected ? String(selected.id) : null,
                            options,
                            isLoading: results.loading || source.loading,
                            onFilterValueChange: (value) => { setSearch(value.trim().slice(0, 160)); setPage(1); },
                            onChange: changeRelease,
                        }),
                        !!saved && el(Button, { variant: 'secondary', onClick: () => changeRelease(null) }, __('Use current context', 'mds-release-manager')),
                        page > 1 && el(Button, { variant: 'tertiary', onClick: () => setPage(page - 1) }, __('Previous results', 'mds-release-manager')),
                        page < results.data?.pages && el(Button, { variant: 'tertiary', onClick: () => setPage(page + 1) }, __('Next results', 'mds-release-manager')),
                        results.error && el(Notice, { status: 'warning', isDismissible: false }, __('Release search failed. Change the search to try again.', 'mds-release-manager')),
                        source.error && el(Notice, { status: 'warning', isDismissible: false }, __('The Release selection could not be checked. The saved selection has not changed.', 'mds-release-manager')),
                        source.data?.missing && el(Notice, { status: 'warning', isDismissible: false },
                            __('The selected Release is unavailable. The saved selection has not changed.', 'mds-release-manager'), ' ', fallbackMessage),
                        !saved && resolved && el('p', null, fallbackMessage)
            ) };
    };
})(window.wp);
