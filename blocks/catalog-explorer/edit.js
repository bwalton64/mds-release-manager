(function (wp) {
	'use strict';

	if (!wp || !wp.blocks || !wp.element || !wp.blockEditor || !wp.components) {
		return;
	}

	const { registerBlockType } = wp.blocks;
	const { createElement: el, Fragment } = wp.element;
	const { InspectorControls, useBlockProps } = wp.blockEditor;
	const { PanelBody, RangeControl, SelectControl, TextControl, ToggleControl } = wp.components;
	const ServerSideRender = wp.serverSideRender;
	const { __ } = wp.i18n;

	registerBlockType('mds-rm/catalog-explorer', {
		edit: function EditCatalogExplorer(props) {
			const { attributes, setAttributes } = props;
			const blockProps = useBlockProps();

			return el(
				Fragment,
				null,
				el(
					InspectorControls,
					null,
					el(
						PanelBody,
						{ title: __('Catalog Layout & Scope', 'mds-release-manager'), initialOpen: true },
						el(TextControl, {
							label: __('Scope to Artist (Optional)', 'mds-release-manager'),
							help: __('Leave blank to show the entire catalog, or enter an Artist Post ID or UUID.', 'mds-release-manager'),
							value: attributes.artistId || '',
							onChange: (val) => setAttributes({ artistId: val }),
						}),
						el(RangeControl, {
							label: __('Grid Columns (Desktop)', 'mds-release-manager'),
							min: 2,
							max: 4,
							value: attributes.columns || 3,
							onChange: (val) => setAttributes({ columns: val || 3 }),
						}),
						el(RangeControl, {
							label: __('Releases Per Page', 'mds-release-manager'),
							min: 4,
							max: 48,
							step: 1,
							value: attributes.perPage || 12,
							onChange: (val) => setAttributes({ perPage: val || 12 }),
						}),
						el(SelectControl, {
							label: __('Pagination Style', 'mds-release-manager'),
							value: attributes.pagination || 'numbered',
							options: [
								{ label: __('Numbered Pagination (1, 2, 3…)', 'mds-release-manager'), value: 'numbered' },
								{ label: __('Load More Button', 'mds-release-manager'), value: 'load_more' },
							],
							onChange: (val) => setAttributes({ pagination: val }),
						}),
						el(SelectControl, {
							label: __('Default Sort Order', 'mds-release-manager'),
							value: attributes.defaultSort || 'date_desc',
							options: [
								{ label: __('Newest First', 'mds-release-manager'), value: 'date_desc' },
								{ label: __('Oldest First', 'mds-release-manager'), value: 'date_asc' },
								{ label: __('Title (A–Z)', 'mds-release-manager'), value: 'title_asc' },
								{ label: __('Artist (A–Z)', 'mds-release-manager'), value: 'artist_asc' },
							],
							onChange: (val) => setAttributes({ defaultSort: val }),
						})
					),
					el(
						PanelBody,
						{ title: __('Controls & Card Elements', 'mds-release-manager'), initialOpen: false },
						el(ToggleControl, {
							label: __('Show Search Box', 'mds-release-manager'),
							checked: attributes.showSearch !== false,
							onChange: (val) => setAttributes({ showSearch: Boolean(val) }),
						}),
						el(ToggleControl, {
							label: __('Show Faceted Filters', 'mds-release-manager'),
							checked: attributes.showFilters !== false,
							onChange: (val) => setAttributes({ showFilters: Boolean(val) }),
						}),
						el(ToggleControl, {
							label: __('Show Sort Selector', 'mds-release-manager'),
							checked: attributes.showSort !== false,
							onChange: (val) => setAttributes({ showSort: Boolean(val) }),
						}),
						el(ToggleControl, {
							label: __('Show Inline Audio Preview Button', 'mds-release-manager'),
							checked: attributes.showAudio !== false,
							onChange: (val) => setAttributes({ showAudio: Boolean(val) }),
						}),
						el(ToggleControl, {
							label: __('Show Quick Buy Now / Streaming Links', 'mds-release-manager'),
							checked: attributes.showLinks !== false,
							onChange: (val) => setAttributes({ showLinks: Boolean(val) }),
						})
					)
				),
				el(
					'div',
					blockProps,
					ServerSideRender
						? el(ServerSideRender, {
								block: 'mds-rm/catalog-explorer',
								attributes: attributes,
							})
						: el('p', null, __('Catalog / Discography Explorer', 'mds-release-manager'))
				)
			);
		},
	});
})(window.wp);
