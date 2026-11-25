( function ( wp ) {
	'use strict';

	const { registerBlockType } = wp.blocks;
	const { createElement: el, Fragment } = wp.element;
	const { InspectorControls, PanelColorSettings, useBlockProps } = wp.blockEditor;
	const { Button, PanelBody, ToggleControl, RangeControl, SelectControl, TextControl } = wp.components;
	const ServerSideRender = wp.serverSideRender.ServerSideRender || wp.serverSideRender;
	const { __ } = wp.i18n;

	const BLOCK_NAME = 'mds-rm/release-player';

	const providerList = ( window.mdsReleasePlayer && Array.isArray( window.mdsReleasePlayer.providers ) )
		? window.mdsReleasePlayer.providers
		: [];

	const providerOptions = [
		{ label: __( 'Automatic (Priority Fallback)', 'mds-release-manager' ), value: '' },
	].concat(
		providerList.map( ( p ) => ( { label: p.label, value: p.slug } ) )
	);

	const shadowOptions = [
		{ label: __( 'None', 'mds-release-manager' ), value: 'none' },
		{ label: __( 'Subtle', 'mds-release-manager' ), value: 'subtle' },
		{ label: __( 'Elevated', 'mds-release-manager' ), value: 'elevated' },
	];

	const maxWidthPresets = [
		{ label: __( 'Full Width (100%)', 'mds-release-manager' ), value: '' },
		{ label: __( 'Compact Card (380px)', 'mds-release-manager' ), value: '380px' },
		{ label: __( 'Medium Card (520px)', 'mds-release-manager' ), value: '520px' },
		{ label: __( 'Wide Bar (720px)', 'mds-release-manager' ), value: '720px' },
		{ label: __( 'Custom…', 'mds-release-manager' ), value: '__custom' },
	];

	const siteDefaults = ( window.mdsReleasePlayer && window.mdsReleasePlayer.defaults && ! Array.isArray( window.mdsReleasePlayer.defaults ) )
		? window.mdsReleasePlayer.defaults
		: {};

	const builtinDefaults = {
		provider: '',
		showArtwork: true,
		showMeta: true,
		showProgress: true,
		showTime: true,
		showTracklist: false,
		showProviderBadge: true,
		samplerMode: true,
		previewSeconds: 20,
		crossfadeMs: 1500,
		compact: false,
		backgroundColor: '',
		textColor: '',
		mutedColor: '',
		accentColor: '',
		playIconColor: '',
		borderColor: '',
		borderRadius: 10,
		borderWidth: 1,
		padding: 12,
		maxWidth: '',
		shadow: 'none',
	};

	const effectiveDefaults = { ...builtinDefaults, ...siteDefaults };

	registerBlockType( BLOCK_NAME, {
		edit: function ( props ) {
			const { attributes, setAttributes, context, isSelected } = props;
			const blockProps = useBlockProps();
			const contextId = context?.postId || 0;
			const val = ( key ) => ( attributes[ key ] !== undefined ? attributes[ key ] : effectiveDefaults[ key ] );

			const pickerResult = typeof window.mdsRmUseReleasePicker === 'function'
				? window.mdsRmUseReleasePicker( {
					attributes,
					setAttributes,
					contextId,
					enabled: isSelected,
					onSelect: function ( item, set ) {
						set( { upc: item ? ( item.upc || '' ) : '' } );
					},
				} )
				: { source: {}, resolved: null, picker: null };

			const { source, resolved, picker } = pickerResult;

			const presetValues = [ '', '380px', '520px', '720px' ];
			const currentMaxWidth = val( 'maxWidth' ) || '';
			const isCustomWidth = ! presetValues.includes( currentMaxWidth );

			const hasStyleOverrides = Boolean(
				attributes.backgroundColor !== undefined ||
				attributes.textColor !== undefined ||
				attributes.mutedColor !== undefined ||
				attributes.accentColor !== undefined ||
				attributes.playIconColor !== undefined ||
				attributes.borderColor !== undefined ||
				attributes.borderRadius !== undefined ||
				attributes.borderWidth !== undefined ||
				attributes.padding !== undefined ||
				attributes.maxWidth !== undefined ||
				attributes.shadow !== undefined
			);

			function resetStyles() {
				setAttributes( {
					backgroundColor: undefined,
					textColor: undefined,
					mutedColor: undefined,
					accentColor: undefined,
					playIconColor: undefined,
					borderColor: undefined,
					borderRadius: undefined,
					borderWidth: undefined,
					padding: undefined,
					maxWidth: undefined,
					shadow: undefined,
				} );
			}

			return el(
				Fragment,
				null,
				el(
					InspectorControls,
					null,
					el(
						PanelBody,
						{ title: __( 'Release & Provider', 'mds-release-manager' ), initialOpen: true },
						picker,
						el( SelectControl, {
							label: __( 'Audio Provider', 'mds-release-manager' ),
							value: val( 'provider' ) || '',
							options: providerOptions,
							onChange: ( value ) => setAttributes( { provider: value } ),
						} )
					),
					el(
						PanelBody,
						{ title: __( 'Player Modules', 'mds-release-manager' ), initialOpen: true },
						el( ToggleControl, {
							label: __( 'Show Featured Image', 'mds-release-manager' ),
							checked: !! val( 'showArtwork' ),
							onChange: ( value ) => setAttributes( { showArtwork: value } ),
						} ),
						el( ToggleControl, {
							label: __( 'Show Title & Artist', 'mds-release-manager' ),
							checked: !! val( 'showMeta' ),
							onChange: ( value ) => setAttributes( { showMeta: value } ),
						} ),
						el( ToggleControl, {
							label: __( 'Show Progress Bar', 'mds-release-manager' ),
							checked: !! val( 'showProgress' ),
							onChange: ( value ) => setAttributes( { showProgress: value } ),
						} ),
						el( ToggleControl, {
							label: __( 'Show Play Time', 'mds-release-manager' ),
							checked: !! val( 'showTime' ),
							onChange: ( value ) => setAttributes( { showTime: value } ),
						} ),
						el( ToggleControl, {
							label: __( 'Show Expandable Tracklist', 'mds-release-manager' ),
							checked: !! val( 'showTracklist' ),
							onChange: ( value ) => setAttributes( { showTracklist: value } ),
						} ),
						el( ToggleControl, {
							label: __( 'Show Provider Attribution', 'mds-release-manager' ),
							checked: !! val( 'showProviderBadge' ),
							onChange: ( value ) => setAttributes( { showProviderBadge: value } ),
						} ),
						el( ToggleControl, {
							label: __( 'Compact Layout', 'mds-release-manager' ),
							checked: !! val( 'compact' ),
							onChange: ( value ) => setAttributes( { compact: value } ),
						} )
					),
					el(
						PanelBody,
						{ title: __( 'Preview & Sampler Playback', 'mds-release-manager' ), initialOpen: false },
						el( ToggleControl, {
							label: __( 'Sequential EP/Album Sampler', 'mds-release-manager' ),
							help: __( 'Automatically advance across tracks with fade-over.', 'mds-release-manager' ),
							checked: !! val( 'samplerMode' ),
							onChange: ( value ) => setAttributes( { samplerMode: value } ),
						} ),
						el( RangeControl, {
							label: __( 'Fallback Preview Length (seconds)', 'mds-release-manager' ),
							help: __( 'Applied only to full-length streams; native timed previews (Apple Music, Deezer, SoundCloud, Spotify) play as provided.', 'mds-release-manager' ),
							value: val( 'previewSeconds' ),
							min: 10,
							max: 60,
							step: 5,
							onChange: ( value ) => setAttributes( { previewSeconds: value } ),
						} ),
						el( RangeControl, {
							label: __( 'Crossfade Duration (ms)', 'mds-release-manager' ),
							value: val( 'crossfadeMs' ),
							min: 0,
							max: 4000,
							step: 250,
							onChange: ( value ) => setAttributes( { crossfadeMs: value } ),
						} )
					)
				),
				el(
					InspectorControls,
					{ group: 'styles' },
					PanelColorSettings && el( PanelColorSettings, {
						title: __( 'Player Colors', 'mds-release-manager' ),
						initialOpen: true,
						enableAlpha: true,
						colorSettings: [
							{
								label: __( 'Background', 'mds-release-manager' ),
								value: val( 'backgroundColor' ) || '',
								onChange: ( value ) => setAttributes( { backgroundColor: value || '' } ),
							},
							{
								label: __( 'Accent (Play Button & Scrubber)', 'mds-release-manager' ),
								value: val( 'accentColor' ) || '',
								onChange: ( value ) => setAttributes( { accentColor: value || '' } ),
							},
							{
								label: __( 'Play Button Icon', 'mds-release-manager' ),
								value: val( 'playIconColor' ) || '',
								onChange: ( value ) => setAttributes( { playIconColor: value || '' } ),
							},
							{
								label: __( 'Primary Text', 'mds-release-manager' ),
								value: val( 'textColor' ) || '',
								onChange: ( value ) => setAttributes( { textColor: value || '' } ),
							},
							{
								label: __( 'Secondary / Time Text', 'mds-release-manager' ),
								value: val( 'mutedColor' ) || '',
								onChange: ( value ) => setAttributes( { mutedColor: value || '' } ),
							},
							{
								label: __( 'Border', 'mds-release-manager' ),
								value: val( 'borderColor' ) || '',
								onChange: ( value ) => setAttributes( { borderColor: value || '' } ),
							},
						],
					} ),
					el(
						PanelBody,
						{ title: __( 'Surface, Border & Sizing', 'mds-release-manager' ), initialOpen: true },
						el( RangeControl, {
							label: __( 'Corner Radius (px)', 'mds-release-manager' ),
							value: val( 'borderRadius' ),
							min: 0,
							max: 32,
							step: 1,
							onChange: ( value ) => setAttributes( { borderRadius: value ?? 10 } ),
						} ),
						el( RangeControl, {
							label: __( 'Border Width (px)', 'mds-release-manager' ),
							value: val( 'borderWidth' ),
							min: 0,
							max: 6,
							step: 1,
							onChange: ( value ) => setAttributes( { borderWidth: value ?? 1 } ),
						} ),
						el( RangeControl, {
							label: __( 'Inner Padding (px)', 'mds-release-manager' ),
							value: val( 'padding' ),
							min: 0,
							max: 32,
							step: 2,
							onChange: ( value ) => setAttributes( { padding: value ?? 12 } ),
						} ),
						el( SelectControl, {
							label: __( 'Max Width', 'mds-release-manager' ),
							value: isCustomWidth ? '__custom' : currentMaxWidth,
							options: maxWidthPresets,
							onChange: ( value ) => setAttributes( { maxWidth: value === '__custom' ? '600px' : value } ),
						} ),
						isCustomWidth && el( TextControl, {
							label: __( 'Custom Max Width', 'mds-release-manager' ),
							help: __( 'Accepts px, rem, em, %, vw (e.g. 640px or 40rem).', 'mds-release-manager' ),
							value: currentMaxWidth,
							onChange: ( value ) => setAttributes( { maxWidth: value } ),
						} ),
						el( SelectControl, {
							label: __( 'Elevation Shadow', 'mds-release-manager' ),
							value: val( 'shadow' ) || 'none',
							options: shadowOptions,
							onChange: ( value ) => setAttributes( { shadow: value } ),
						} ),
						hasStyleOverrides && el( Button, {
							variant: 'tertiary',
							isDestructive: true,
							onClick: resetStyles,
						}, __( 'Reset to site defaults', 'mds-release-manager' ) )
					)
				),
				el(
					'div',
					blockProps,
					el( ServerSideRender, {
						block: BLOCK_NAME,
						attributes: attributes,
						httpMethod: 'POST',
						urlQueryArgs: contextId ? { post_id: contextId } : undefined,
						EmptyResponsePlaceholder: function () {
							return el( 'p', null, resolved
								? __( 'This Release has no audio preview available to play.', 'mds-release-manager' )
								: source.error
									? __( 'The Release source could not be checked.', 'mds-release-manager' )
									: source.loading
										? __( 'Checking Release context…', 'mds-release-manager' )
										: __( 'Select a Release to preview the audio player.', 'mds-release-manager' ) );
						},
					} )
				)
			);
		},
		save: function () {
			return null;
		},
	} );
} )( window.wp );
