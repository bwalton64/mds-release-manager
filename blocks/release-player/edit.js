( function ( wp ) {
	'use strict';

	const { registerBlockType } = wp.blocks;
	const { createElement: el, Fragment } = wp.element;
	const { InspectorControls, useBlockProps, useSettings } = wp.blockEditor;
	const { Button, PanelBody, ToggleControl, RangeControl, SelectControl, TextControl } = wp.components;
	const ServerSideRender = wp.serverSideRender.ServerSideRender || wp.serverSideRender;
	const { __, sprintf } = wp.i18n;

	const BLOCK_NAME = 'mds-rm/release-player';

	const providerList = ( window.mdsReleasePlayer && Array.isArray( window.mdsReleasePlayer.providers ) )
		? window.mdsReleasePlayer.providers
		: [];

	const providerOptions = [
		{ label: __( 'Auto (Priority Order)', 'mds-release-manager' ), value: '' },
	].concat(
		providerList.map( ( p ) => ( { label: p.label || p.slug, value: p.slug } ) )
	);

	const shadowOptions = [
		{ label: __( 'Default (None)', 'mds-release-manager' ), value: '' },
		{ label: __( 'None (Flat)', 'mds-release-manager' ), value: 'none' },
		{ label: __( 'Subtle', 'mds-release-manager' ), value: 'subtle' },
		{ label: __( 'Elevated', 'mds-release-manager' ), value: 'elevated' },
	];

	const maxWidthPresets = [
		{ label: __( 'Full Width (100%)', 'mds-release-manager' ), value: '' },
		{ label: __( 'Compact (360px)', 'mds-release-manager' ), value: '360px' },
		{ label: __( 'Medium (480px)', 'mds-release-manager' ), value: '480px' },
		{ label: __( 'Wide (640px)', 'mds-release-manager' ), value: '640px' },
		{ label: __( 'Custom…', 'mds-release-manager' ), value: '__custom' },
	];

	const siteDefaults = ( window.mdsReleasePlayer && window.mdsReleasePlayer.defaults && ! Array.isArray( window.mdsReleasePlayer.defaults ) )
		? window.mdsReleasePlayer.defaults
		: {};

	const themeScheme = ( window.mdsReleasePlayer && window.mdsReleasePlayer.themeScheme )
		? window.mdsReleasePlayer.themeScheme
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
			const [ palette, customColor ] = useSettings( 'color.palette', 'color.custom' );
			const primitives = window.mdsRmStylePrimitives || {};
			const SectionInfo = primitives.SectionInfo;
			const ControlGroup = primitives.ControlGroup;
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

			const presetValues = [ '', '360px', '480px', '640px' ];
			const currentMaxWidth = val( 'maxWidth' ) || '';
			const isCustomWidth = ! presetValues.includes( currentMaxWidth );

			const hasModuleOrPlaybackOverrides = Boolean(
				attributes.provider !== undefined ||
				attributes.showArtwork !== undefined ||
				attributes.showMeta !== undefined ||
				attributes.showProgress !== undefined ||
				attributes.showTime !== undefined ||
				attributes.showTracklist !== undefined ||
				attributes.showProviderBadge !== undefined ||
				attributes.compact !== undefined ||
				attributes.samplerMode !== undefined ||
				attributes.previewSeconds !== undefined ||
				attributes.crossfadeMs !== undefined
			);

			function resetModulesAndPlayback() {
				setAttributes( {
					provider: undefined,
					showArtwork: undefined,
					showMeta: undefined,
					showProgress: undefined,
					showTime: undefined,
					showTracklist: undefined,
					showProviderBadge: undefined,
					compact: undefined,
					samplerMode: undefined,
					previewSeconds: undefined,
					crossfadeMs: undefined,
				} );
			}

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

			function colorField( key, label, fallbackHex ) {
				return el( 'div', { className: 'mds-style-field mds-style-field--color' },
					el( 'span', { className: 'mds-style-field__label' }, label ),
					customColor !== false && window.mdsRmColorControl && el( window.mdsRmColorControl, {
						label,
						value: val( key ) || '',
						palette,
						fallbackHint: fallbackHex ? sprintf( __( 'Auto (%s)', 'mds-release-manager' ), fallbackHex ) : __( 'Theme default', 'mds-release-manager' ),
						onChange: ( next ) => setAttributes( { [ key ]: next || undefined } ),
					} )
				);
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
							onChange: ( value ) => setAttributes( { provider: value || undefined } ),
						} )
					),
					el(
						PanelBody,
						{ title: __( 'Player Modules & Source', 'mds-release-manager' ), initialOpen: true },
						el( ToggleControl, {
							label: __( 'Show Artwork', 'mds-release-manager' ),
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
							label: __( 'Show Time Readout', 'mds-release-manager' ),
							checked: !! val( 'showTime' ),
							onChange: ( value ) => setAttributes( { showTime: value } ),
						} ),
						el( ToggleControl, {
							label: __( 'Show Tracklist', 'mds-release-manager' ),
							checked: !! val( 'showTracklist' ),
							onChange: ( value ) => setAttributes( { showTracklist: value } ),
						} ),
						el( ToggleControl, {
							label: __( 'Show Provider Badge', 'mds-release-manager' ),
							checked: !! val( 'showProviderBadge' ),
							onChange: ( value ) => setAttributes( { showProviderBadge: value } ),
						} ),
						el( ToggleControl, {
							label: __( 'Compact Bar Layout', 'mds-release-manager' ),
							checked: !! val( 'compact' ),
							onChange: ( value ) => setAttributes( { compact: value } ),
						} )
					),
					el(
						PanelBody,
						{ title: __( 'Preview & Sampler Playback', 'mds-release-manager' ), initialOpen: false },
						el( ToggleControl, {
							label: __( 'Enable Sampler Mode', 'mds-release-manager' ),
							help: __( 'Automatically advances and crossfades across tracks.', 'mds-release-manager' ),
							checked: !! val( 'samplerMode' ),
							onChange: ( value ) => setAttributes( { samplerMode: value } ),
						} ),
						el( RangeControl, {
							label: __( 'Preview Clip Length (seconds)', 'mds-release-manager' ),
							help: __( 'Default: 20s. Applied to full-length streams; native timed previews play as provided.', 'mds-release-manager' ),
							value: val( 'previewSeconds' ),
							min: 10,
							max: 60,
							step: 5,
							allowReset: true,
							onChange: ( value ) => setAttributes( { previewSeconds: value === undefined ? undefined : Math.round( value ) } ),
						} ),
						el( RangeControl, {
							label: __( 'Crossfade Duration (ms)', 'mds-release-manager' ),
							help: __( 'Default: 1500ms.', 'mds-release-manager' ),
							value: val( 'crossfadeMs' ),
							min: 0,
							max: 4000,
							step: 100,
							allowReset: true,
							onChange: ( value ) => setAttributes( { crossfadeMs: value === undefined ? undefined : Math.round( value ) } ),
						} ),
						hasModuleOrPlaybackOverrides && el( 'div', { className: 'mds-reset-row' },
							el( Button, {
								variant: 'tertiary',
								isDestructive: true,
								onClick: resetModulesAndPlayback,
							}, __( 'Reset modules & playback to site defaults', 'mds-release-manager' ) )
						)
					)
				),
				el(
					InspectorControls,
					{ group: 'styles' },
					el(
						PanelBody,
						{ title: __( 'Colors & Surface Styling', 'mds-release-manager' ), initialOpen: true },
						SectionInfo && el( SectionInfo, {
							title: __( 'About Release Player Styling', 'mds-release-manager' ),
							summary: __( 'Overrides site-wide Release Player colors, border, padding, max width, and elevation for this block only.', 'mds-release-manager' ),
							inheritance: __( 'Leave any control unset to inherit from Releases → Settings → Block Defaults (or auto-match your active theme).', 'mds-release-manager' ),
						} ),
						ControlGroup
							? el( ControlGroup, { title: __( 'Player Palette', 'mds-release-manager' ) },
								colorField( 'backgroundColor', __( 'Background', 'mds-release-manager' ), themeScheme.surface ),
								colorField( 'textColor', __( 'Text', 'mds-release-manager' ), themeScheme.text ),
								colorField( 'mutedColor', __( 'Muted Text', 'mds-release-manager' ), themeScheme.muted ),
								colorField( 'accentColor', __( 'Accent / Play Button', 'mds-release-manager' ), themeScheme.accent ),
								colorField( 'playIconColor', __( 'Play Icon', 'mds-release-manager' ), themeScheme.accentText ),
								colorField( 'borderColor', __( 'Border', 'mds-release-manager' ), themeScheme.border )
							)
							: el( Fragment, null,
								colorField( 'backgroundColor', __( 'Background', 'mds-release-manager' ), themeScheme.surface ),
								colorField( 'textColor', __( 'Text', 'mds-release-manager' ), themeScheme.text ),
								colorField( 'mutedColor', __( 'Muted Text', 'mds-release-manager' ), themeScheme.muted ),
								colorField( 'accentColor', __( 'Accent / Play Button', 'mds-release-manager' ), themeScheme.accent ),
								colorField( 'playIconColor', __( 'Play Icon', 'mds-release-manager' ), themeScheme.accentText ),
								colorField( 'borderColor', __( 'Border', 'mds-release-manager' ), themeScheme.border )
							),
						el( ControlGroup || Fragment, ControlGroup ? { title: __( 'Surface, Border & Sizing', 'mds-release-manager' ) } : null,
							el( RangeControl, {
								label: __( 'Corner Radius (px)', 'mds-release-manager' ),
								help: __( 'Default: 10px.', 'mds-release-manager' ),
								value: val( 'borderRadius' ),
								min: 0,
								max: 32,
								step: 1,
								allowReset: true,
								onChange: ( value ) => setAttributes( { borderRadius: value === undefined ? undefined : Math.round( value ) } ),
							} ),
							el( RangeControl, {
								label: __( 'Border Width (px)', 'mds-release-manager' ),
								help: __( 'Default: 1px.', 'mds-release-manager' ),
								value: val( 'borderWidth' ),
								min: 0,
								max: 6,
								step: 1,
								allowReset: true,
								onChange: ( value ) => setAttributes( { borderWidth: value === undefined ? undefined : Math.round( value ) } ),
							} ),
							el( RangeControl, {
								label: __( 'Inner Padding (px)', 'mds-release-manager' ),
								help: __( 'Default: 12px.', 'mds-release-manager' ),
								value: val( 'padding' ),
								min: 0,
								max: 32,
								step: 1,
								allowReset: true,
								onChange: ( value ) => setAttributes( { padding: value === undefined ? undefined : Math.round( value ) } ),
							} ),
							el( SelectControl, {
								label: __( 'Max Width', 'mds-release-manager' ),
								value: isCustomWidth ? '__custom' : currentMaxWidth,
								options: maxWidthPresets,
								onChange: ( value ) => setAttributes( { maxWidth: value === '__custom' ? '520px' : ( value || undefined ) } ),
							} ),
							isCustomWidth && el( TextControl, {
								label: __( 'Custom Max Width', 'mds-release-manager' ),
								help: __( 'Accepts px, rem, em, %, or vw (e.g. 520px or 36rem).', 'mds-release-manager' ),
								value: currentMaxWidth,
								onChange: ( value ) => setAttributes( { maxWidth: value.trim() || undefined } ),
							} ),
							el( SelectControl, {
								label: __( 'Elevation Shadow', 'mds-release-manager' ),
								value: attributes.shadow !== undefined ? attributes.shadow : ( siteDefaults.shadow || '' ),
								options: shadowOptions,
								onChange: ( value ) => setAttributes( { shadow: value || undefined } ),
							} )
						),
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
