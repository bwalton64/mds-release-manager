(function (wp) {
	const { createElement: el, Fragment, useEffect, useState } = wp.element;
	const { InspectorControls, useBlockProps } = wp.blockEditor;
	const { Button, ComboboxControl, Notice, PanelBody } = wp.components;
	const { __, sprintf } = wp.i18n;
	const ServerSideRender = wp.serverSideRender.ServerSideRender || wp.serverSideRender;
	const name = 'mds-rm/artist-discography';
	const requestTimeout = 30000;

	function useArtists(path, delay, collection = false, enabled = true) {
		const [result, setResult] = useState({});
		useEffect(() => {
			if (!path || !enabled) {
				return undefined;
			}
			let active = true;
			const timers = [];
			let controller = null;
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
				controller = typeof AbortController === 'function' ? new AbortController() : null;
				const timer = setTimeout(() => {
					timedOut = true;
					if (controller) controller.abort();
					fail({ code: 'timeout' }, attempt);
				}, requestTimeout);
				timers.push(timer);
				const fetchOpts = { path, parse: !collection };
				if (controller) fetchOpts.signal = controller.signal;
				wp.apiFetch(fetchOpts).then(async (response) => {
					const data = collection ? await response.json() : response;
					clearTimeout(timer);
					if (active && !timedOut) setResult({ path, data, pages: collection ? Number(response.headers.get('X-WP-TotalPages')) : 0 });
				}).catch(
					(error) => { clearTimeout(timer); if (active && !timedOut) fail(error, attempt); }
				);
			}
			timers.push(setTimeout(() => run(0), delay));
			return () => {
				active = false;
				timers.forEach(clearTimeout);
				if (controller) controller.abort();
			};
		}, [path, delay, collection, enabled]);
		return path && result.path === path ? result : { loading: !!path };
	}

	function option(artist) {
		return {
			value: String(artist.id),
			label: artistTitle(artist),
		};
	}

	function artistTitle(artist) {
		return wp.htmlEntities.decodeEntities(artist.title.rendered) || __('Untitled Artist', 'mds-release-manager');
	}

	function Edit({ attributes, setAttributes, context = {}, isSelected }) {
		const blockProps = useBlockProps();
		const [search, setSearch] = useState('');
		const [page, setPage] = useState(1);
		const artistId = attributes.artistId || 0;
		const contextId = context.postType === 'artist' ? context.postId : 0;
		const base = '/wp/v2/artist';
		const results = useArtists(wp.url.addQueryArgs(base, {
			search, search_columns: ['post_title'], page, per_page: 20, status: 'publish', orderby: 'title', order: 'asc', _fields: 'id,title,status',
		}), 250, true, isSelected);
		const selected = useArtists(artistId ? `${base}/${artistId}?_fields=id,title,status` : '', 0, false, isSelected);
		const contextual = useArtists(!artistId && contextId ? `${base}/${contextId}?_fields=id,title` : '', 0, false, isSelected);
		const available = selected.data?.status === 'publish';
		const missing = artistId && (selected.data && !available || [401, 403, 404].includes(selected.error?.data?.status));
		// translators: %s: Current Artist title.
		const currentArtistMessage = __('Using the current Artist: %s.', 'mds-release-manager');
		const options = (results.data || []).map(option);
		if (available && !options.some(({ value }) => value === String(artistId))) {
			options.unshift(option(selected.data));
		}
		return el('div', blockProps,
			el(InspectorControls, null, el(PanelBody, { title: __('Artist Discography', 'mds-release-manager') },
				el(ComboboxControl, {
					label: __('Artist', 'mds-release-manager'),
					help: !artistId && contextual.data
						? sprintf(currentArtistMessage, artistTitle(contextual.data))
						: __('Leave blank to use the current Artist.', 'mds-release-manager'),
					value: artistId ? String(artistId) : null,
					options,
					isLoading: results.loading || selected.loading,
					onFilterValueChange: (value) => { setSearch(value.trim()); setPage(1); },
					onChange: (value) => setAttributes({ artistId: Number(value) || 0 }),
				}),
				!!artistId && el(Button, { variant: 'secondary', onClick: () => setAttributes({ artistId: 0 }) }, __('Use current Artist', 'mds-release-manager')),
				page > 1 && el(Button, { variant: 'tertiary', onClick: () => setPage(page - 1) }, __('Previous results', 'mds-release-manager')),
				page < results.pages && el(Button, { variant: 'tertiary', onClick: () => setPage(page + 1) }, __('Next results', 'mds-release-manager')),
				results.error && el(Notice, { status: 'warning', isDismissible: false },
					results.error.code === 'rest_post_invalid_page_number' ? __('No more Artists match this search.', 'mds-release-manager') : __('Artist search failed. Change the search to try again.', 'mds-release-manager'))
			)),
			el(window.mdsRmDiscographyTableControls, { value: attributes.tableStyles || {}, defaults: mdsDiscography.tableStyles || {}, onChange: (tableStyles) => setAttributes({ tableStyles }) }),
			missing ? el(Notice, { status: 'warning', isDismissible: false }, __('The selected Artist is unavailable. Choose another Artist or clear the selection.', 'mds-release-manager'))
				: selected.error ? el(Notice, { status: 'warning', isDismissible: false }, __('The selected Artist could not be checked. The saved selection has not changed.', 'mds-release-manager'))
					: artistId || contextId ? el(ServerSideRender, {
						block: name,
						attributes: { ...attributes, artistId: artistId || contextId },
						skipBlockSupportAttributes: true,
						httpMethod: 'POST',
					}) : el(Fragment, null, __('Select an Artist to preview the discography.', 'mds-release-manager'))
		);
	}

	wp.blocks.registerBlockType(name, { edit: Edit, save: () => null });
})(window.wp);
