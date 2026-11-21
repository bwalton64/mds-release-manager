(function (wp) {
	const { createElement: el, Fragment, useEffect, useState } = wp.element;
	const { InspectorControls, useBlockProps } = wp.blockEditor;
	const { Button, ComboboxControl, Notice, PanelBody } = wp.components;
	const { __, sprintf } = wp.i18n;
	const ServerSideRender = wp.serverSideRender.ServerSideRender || wp.serverSideRender;
	const name = 'mds-rm/artist-discography';

	function useArtists(path, delay, collection = false) {
		const [result, setResult] = useState({});
		useEffect(() => {
			if (!path) {
				return;
			}
			let active = true;
			const request = setTimeout(() => {
				wp.apiFetch({ path, parse: !collection }).then(async (response) => {
					const data = collection ? await response.json() : response;
					if (active) setResult({ path, data, pages: collection ? Number(response.headers.get('X-WP-TotalPages')) : 0 });
				}).catch(
					(error) => { if (active) setResult({ path, error }); }
				);
			}, delay);
			return () => { active = false; clearTimeout(request); };
		}, [path, delay, collection]);
		return path && result.path === path ? result : { loading: !!path };
	}

	function option(artist) {
		return {
			value: String(artist.id),
			// translators: %1$s: Artist title. %2$s: Artist post ID.
			label: sprintf(__('%1$s (ID %2$s)', 'mds-release-manager'), artistTitle(artist), artist.id),
		};
	}

	function artistTitle(artist) {
		return wp.htmlEntities.decodeEntities(artist.title.rendered) || __('Untitled Artist', 'mds-release-manager');
	}

	function Edit({ attributes, setAttributes, context = {} }) {
		const blockProps = useBlockProps();
		const [search, setSearch] = useState('');
		const [page, setPage] = useState(1);
		const artistId = attributes.artistId || 0;
		const contextId = context.postType === 'artist' ? context.postId : 0;
		const base = '/wp/v2/artist';
		const results = useArtists(wp.url.addQueryArgs(base, {
			search, search_columns: ['post_title'], page, per_page: 20, status: 'publish', orderby: 'title', order: 'asc', _fields: 'id,title,status',
		}), 250, true);
		const selected = useArtists(artistId ? `${base}/${artistId}?_fields=id,title,status` : '', 0);
		const contextual = useArtists(!artistId && contextId ? `${base}/${contextId}?_fields=id,title` : '', 0);
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
