( function ( wp ) {
	'use strict';

	const { registerBlockType } = wp.blocks;
	const { createElement: el, Fragment } = wp.element;
	const { InspectorControls, useBlockProps } = wp.blockEditor;
	const { PanelBody, ToggleControl, RangeControl, SelectControl, TextControl } = wp.components;
	const ServerSideRender = wp.serverSideRender;
	const { __ } = wp.i18n;

	const providerList = ( window.mdsReleasePlayer && Array.isArray( window.mdsReleasePlayer.providers ) )
		? window.mdsReleasePlayer.providers
		: [];

	const providerOptions = [
		{ label: __( 'Automatic (Priority Fallback)', 'mds-release-manager' ), value: '' },
	].concat(
		providerList.map( ( p ) => ( { label: p.label, value: p.slug } ) )
	);

	registerBlockType( 'mds-release-manager/release-player', {
		edit: function ( props ) {
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
						{ title: __( 'Release & Provider', 'mds-release-manager' ), initialOpen: true },
						el( TextControl, {
							label: __( 'UPC / EAN Override (Optional)', 'mds-release-manager' ),
							help: __( 'Leave blank to use the current Release post.', 'mds-release-manager' ),
							value: attributes.upc || '',
							onChange: ( value ) => setAttributes( { upc: value } ),
						} ),
						el( SelectControl, {
							label: __( 'Audio Provider', 'mds-release-manager' ),
							value: attributes.provider || '',
							options: providerOptions,
							onChange: ( value ) => setAttributes( { provider: value } ),
						} )
					),
					el(
						PanelBody,
						{ title: __( 'Player Modules', 'mds-release-manager' ), initialOpen: true },
						el( ToggleControl, {
							label: __( 'Show Featured Image', 'mds-release-manager' ),
							checked: !! attributes.showArtwork,
							onChange: ( value ) => setAttributes( { showArtwork: value } ),
						} ),
						el( ToggleControl, {
							label: __( 'Show Title & Artist', 'mds-release-manager' ),
							checked: !! attributes.showMeta,
							onChange: ( value ) => setAttributes( { showMeta: value } ),
						} ),
						el( ToggleControl, {
							label: __( 'Show Progress Bar', 'mds-release-manager' ),
							checked: !! attributes.showProgress,
							onChange: ( value ) => setAttributes( { showProgress: value } ),
						} ),
						el( ToggleControl, {
							label: __( 'Show Play Time', 'mds-release-manager' ),
							checked: !! attributes.showTime,
							onChange: ( value ) => setAttributes( { showTime: value } ),
						} ),
						el( ToggleControl, {
							label: __( 'Show Expandable Tracklist', 'mds-release-manager' ),
							checked: !! attributes.showTracklist,
							onChange: ( value ) => setAttributes( { showTracklist: value } ),
						} ),
						el( ToggleControl, {
							label: __( 'Show Provider Attribution', 'mds-release-manager' ),
							checked: !! attributes.showProviderBadge,
							onChange: ( value ) => setAttributes( { showProviderBadge: value } ),
						} ),
						el( ToggleControl, {
							label: __( 'Compact Layout', 'mds-release-manager' ),
							checked: !! attributes.compact,
							onChange: ( value ) => setAttributes( { compact: value } ),
						} )
					),
					el(
						PanelBody,
						{ title: __( 'Preview & Sampler Playback', 'mds-release-manager' ), initialOpen: false },
						el( ToggleControl, {
							label: __( 'Sequential EP/Album Sampler', 'mds-release-manager' ),
							help: __( 'Automatically advance across tracks with fade-over.', 'mds-release-manager' ),
							checked: !! attributes.samplerMode,
							onChange: ( value ) => setAttributes( { samplerMode: value } ),
						} ),
						el( RangeControl, {
							label: __( 'Fallback Preview Length (seconds)', 'mds-release-manager' ),
							help: __( 'Applied only to full-length streams; native timed previews (Apple Music, Deezer, SoundCloud, Spotify) play as provided.', 'mds-release-manager' ),
							value: attributes.previewSeconds || 20,
							min: 10,
							max: 60,
							step: 5,
							onChange: ( value ) => setAttributes( { previewSeconds: value } ),
						} ),
						el( RangeControl, {
							label: __( 'Crossfade Duration (ms)', 'mds-release-manager' ),
							value: attributes.crossfadeMs ?? 1500,
							min: 0,
							max: 4000,
							step: 250,
							onChange: ( value ) => setAttributes( { crossfadeMs: value } ),
						} )
					)
				),
				el(
					'div',
					blockProps,
					el( ServerSideRender, {
						block: 'mds-release-manager/release-player',
						attributes: attributes,
					} )
				)
			);
		},
		save: function () {
			return null;
		},
	} );
} )( window.wp );
