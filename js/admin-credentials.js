/**
 * MDS Release Manager — Centralized Credentials & Secret Vault Admin UI.
 *
 * Mounts inside Releases → Settings → Credentials on the Hub site.
 * Uses shared `window.mdsRmStylePrimitives` (`SectionInfo`, `RepeaterCard`) with
 * clean `@wordpress/components` fallbacks.
 */
( function ( wp ) {
	'use strict';

	const { createElement: el, useState, useCallback, Component, Fragment } = wp.element;
	const {
		Button,
		Notice,
		PanelBody,
		SnackbarList,
		TabPanel,
		TextControl,
	} = wp.components;
	const apiFetch = wp.apiFetch;
	const { __ } = wp.i18n;
	const speak = wp.a11y && wp.a11y.speak ? wp.a11y.speak : function () {};

	const boot = window.mdsRmCredentialsAdmin || {};
	const restBase = boot.restBase || '/mds/v1/credentials';

	const SOURCE_LABELS = {
		filter: __( 'External KMS / Filter', 'mds-release-manager' ),
		wp_secrets: __( 'WP Core Secrets API', 'mds-release-manager' ),
		env: __( 'Environment (ENV)', 'mds-release-manager' ),
		constant: __( 'wp-config.php Constant', 'mds-release-manager' ),
		vault: __( 'Hub Encrypted Vault', 'mds-release-manager' ),
		none: __( 'Not Configured', 'mds-release-manager' ),
	};

	class CredentialsErrorBoundary extends Component {
		constructor( props ) {
			super( props );
			this.state = { hasError: false, message: '' };
		}
		static getDerivedStateFromError( err ) {
			return { hasError: true, message: err && err.message ? err.message : 'Unexpected UI error.' };
		}
		render() {
			if ( this.state.hasError ) {
				return el(
					Notice,
					{ status: 'error', isDismissible: false },
					__( 'Credentials UI encountered an error: ', 'mds-release-manager' ) + this.state.message
				);
			}
			return this.props.children;
		}
	}

	function FallbackSectionInfo( { title, summary, inheritance } ) {
		return el(
			'div',
			{ className: 'mds-section-info', role: 'note' },
			el( 'span', { className: 'dashicons dashicons-lock mds-section-info__icon', 'aria-hidden': true } ),
			el(
				'div',
				{ className: 'mds-section-info__body' },
				title && el( 'strong', { className: 'mds-section-info__title' }, title ),
				summary && el( 'p', { className: 'mds-section-info__summary' }, summary ),
				inheritance && el( 'p', { className: 'mds-section-info__inheritance' }, inheritance )
			)
		);
	}

	function CredentialsApp() {
		const primitives = window.mdsRmStylePrimitives || {};
		const SectionInfo = primitives.SectionInfo || FallbackSectionInfo;

		const initial = boot.initial || {};
		const [ isHub ] = useState( initial.is_hub !== false );
		const [ hubUrl ] = useState( initial.hub_settings_url || '' );
		const [ posture, setPosture ] = useState( initial.posture || { level: 'good', warnings: [], instructions: [] } );
		const [ schemas, setSchemas ] = useState( Array.isArray( initial.schemas ) ? initial.schemas : [] );
		const [ audit, setAudit ] = useState( Array.isArray( initial.audit ) ? initial.audit : [] );
		const [ inputs, setInputs ] = useState( {} );
		const [ busyNs, setBusyNs ] = useState( '' );
		const [ testingNs, setTestingNs ] = useState( '' );
		const [ rotating, setRotating ] = useState( false );
		const [ generatedSnippet, setGeneratedSnippet ] = useState( '' );
		const [ notices, setNotices ] = useState( [] );

		const pushNotice = useCallback( ( content ) => {
			const id = String( Date.now() + Math.random() );
			setNotices( ( prev ) => prev.concat( [ { id, content } ] ) );
			speak( content, 'polite' );
		}, [] );

		const dismissNotice = useCallback( ( id ) => {
			setNotices( ( prev ) => prev.filter( ( n ) => n.id !== id ) );
		}, [] );

		const setFieldInput = ( ns, fieldKey, val ) => {
			setInputs( ( prev ) => {
				const group = Object.assign( {}, prev[ ns ] || {} );
				group[ fieldKey ] = val;
				return Object.assign( {}, prev, { [ ns ]: group } );
			} );
		};

		const applyPayload = ( res ) => {
			if ( res.posture ) {
				setPosture( res.posture );
			}
			if ( Array.isArray( res.schemas ) ) {
				setSchemas( res.schemas );
			}
			if ( Array.isArray( res.audit ) ) {
				setAudit( res.audit );
			}
		};

		const saveNamespace = async ( ns, clear ) => {
			setBusyNs( ns );
			try {
				const res = await apiFetch( {
					path: restBase,
					method: 'POST',
					data: {
						namespace: ns,
						clear: !! clear,
						fields: clear ? {} : ( inputs[ ns ] || {} ),
					},
				} );
				applyPayload( res );
				setInputs( ( prev ) => Object.assign( {}, prev, { [ ns ]: {} } ) );
				pushNotice( res.message || __( 'Credentials updated.', 'mds-release-manager' ) );
			} catch ( err ) {
				pushNotice( ( err && err.message ) || __( 'Failed to update credentials.', 'mds-release-manager' ) );
			} finally {
				setBusyNs( '' );
			}
		};

		const testNamespace = async ( ns ) => {
			setTestingNs( ns );
			try {
				const res = await apiFetch( {
					path: restBase + '/test',
					method: 'POST',
					data: { namespace: ns },
				} );
				pushNotice( res.message || ( res.ok ? __( 'Connection succeeded.', 'mds-release-manager' ) : __( 'Connection failed.', 'mds-release-manager' ) ) );
			} catch ( err ) {
				pushNotice( ( err && err.message ) || __( 'Connection test failed.', 'mds-release-manager' ) );
			} finally {
				setTestingNs( '' );
			}
		};

		const rotateAll = async () => {
			setRotating( true );
			try {
				const res = await apiFetch( {
					path: restBase + '/rotate',
					method: 'POST',
				} );
				applyPayload( res );
				pushNotice( res.message || __( 'Vault secrets re-encrypted.', 'mds-release-manager' ) );
			} catch ( err ) {
				pushNotice( ( err && err.message ) || __( 'Key rotation failed.', 'mds-release-manager' ) );
			} finally {
				setRotating( false );
			}
		};

		const generateKey = async () => {
			try {
				const res = await apiFetch( {
					path: restBase + '/generate-key',
					method: 'POST',
				} );
				if ( res.snippet ) {
					setGeneratedSnippet( res.snippet );
					pushNotice( __( 'Generated a new 256-bit MDS_RM_SECRETS_KEY snippet.', 'mds-release-manager' ) );
				}
			} catch ( err ) {
				pushNotice( ( err && err.message ) || __( 'Failed to generate master key.', 'mds-release-manager' ) );
			}
		};

		const renderPostureBanner = () => {
			const warnings = Array.isArray( posture.warnings ) ? posture.warnings : [];
			const instructions = Array.isArray( posture.instructions ) ? posture.instructions : [];
			const status = posture.level === 'minimum' || posture.has_minimum_entries
				? 'warning'
				: ( posture.level === 'good' ? 'info' : 'success' );

			return el(
				Notice,
				{ status, isDismissible: false },
				el(
					'p',
					{ style: { margin: '0 0 6px' } },
					el( 'strong', null, __( 'Vault Security Posture: ', 'mds-release-manager' ) ),
					String( posture.level || 'good' ).toUpperCase() +
						' — ' +
						__( 'Cipher: ', 'mds-release-manager' ) +
						String( posture.cipher || 'unknown' ) +
						' | ' +
						__( 'Key Source: ', 'mds-release-manager' ) +
						String( posture.key_source || 'wp_salt' ) +
						' | ' +
						__( 'Env: ', 'mds-release-manager' ) +
						String( posture.environment || 'production' )
				),
				warnings.map( ( w, i ) => el( 'p', { key: 'w' + i, style: { margin: '4px 0' } }, w ) ),
				instructions.length > 0 &&
					el(
						'ul',
						{ style: { margin: '6px 0 0 18px', listStyle: 'disc' } },
						instructions.map( ( step, i ) => el( 'li', { key: 'i' + i }, step ) )
					)
			);
		};

		const renderServiceCard = ( schema ) => {
			const ns = schema.namespace;
			const nsInputs = inputs[ ns ] || {};
			const configuredCount = ( schema.fields || [] ).filter( ( f ) => f.status && f.status.configured ).length;
			const totalCount = ( schema.fields || [] ).length;
			const badge = configuredCount === totalCount && totalCount > 0
				? __( 'Configured', 'mds-release-manager' )
				: ( configuredCount > 0 ? __( 'Partial', 'mds-release-manager' ) : __( 'Not Configured', 'mds-release-manager' ) );

			return el(
				PanelBody,
				{
					key: ns,
					title: schema.label + ' — ' + badge,
					initialOpen: false,
				},
				schema.description && el( 'p', { className: 'mds-style-field__help', style: { marginTop: 0 } }, schema.description ),
				Array.isArray( schema.plugins ) && schema.plugins.length > 0 &&
					el(
						'p',
						{ className: 'description', style: { marginTop: 0 } },
						__( 'Used by: ', 'mds-release-manager' ) + schema.plugins.join( ', ' )
					),
				el(
					'div',
					{ className: 'mds-style-grid-2col', style: { marginTop: '12px' } },
					( schema.fields || [] ).map( ( field ) => {
						const st = field.status || {};
						const sourceLabel = SOURCE_LABELS[ st.source ] || st.source || '';
						return el(
							'div',
							{ key: field.key },
							el( TextControl, {
								label: field.label,
								type: field.is_secret ? 'password' : 'text',
								value: nsInputs[ field.key ] || '',
								disabled: !! st.is_override,
								placeholder: st.is_override
									? __( 'Managed by ', 'mds-release-manager' ) + sourceLabel
									: ( st.configured ? __( 'Leave blank to keep existing', 'mds-release-manager' ) : ( field.placeholder || '' ) ),
								help: st.configured
									? __( 'Active: ', 'mds-release-manager' ) + ( st.masked || '••••' ) + ' (' + sourceLabel + ')'
									: ( field.const_names && field.const_names.length ? __( 'Override constant/ENV: ', 'mds-release-manager' ) + field.const_names[ 0 ] : '' ),
								onChange: ( val ) => setFieldInput( ns, field.key, val ),
								__next40pxDefaultSize: true,
								__nextHasNoMarginBottom: true,
							} )
						);
					} )
				),
				el(
					'div',
					{ style: { display: 'flex', gap: '8px', flexWrap: 'wrap', marginTop: '14px' } },
					el(
						Button,
						{
							variant: 'primary',
							isBusy: busyNs === ns,
							disabled: busyNs !== '',
							onClick: () => saveNamespace( ns, false ),
						},
						__( 'Save Credentials', 'mds-release-manager' )
					),
					schema.can_test &&
						el(
							Button,
							{
								variant: 'secondary',
								isBusy: testingNs === ns,
								disabled: testingNs !== '',
								onClick: () => testNamespace( ns ),
							},
							__( 'Test Connection', 'mds-release-manager' )
						),
					configuredCount > 0 &&
						el(
							Button,
							{
								variant: 'tertiary',
								isDestructive: true,
								disabled: busyNs !== '',
								onClick: () => saveNamespace( ns, true ),
							},
							__( 'Clear Vault Entry', 'mds-release-manager' )
						),
					schema.docs_url &&
						el(
							Button,
							{
								variant: 'link',
								href: schema.docs_url,
								target: '_blank',
								rel: 'noopener noreferrer',
							},
							__( 'Developer Portal', 'mds-release-manager' )
						)
				)
			);
		};

		const renderCredentialsTab = () =>
			el(
				Fragment,
				null,
				el( SectionInfo, {
					title: __( 'Centralized Hub Credential Vault', 'mds-release-manager' ),
					summary: __( 'Manage API keys, OAuth Client IDs, and secret tokens once for every active MDS plugin. Secrets are encrypted at rest on the Hub site using authenticated encryption (libsodium XChaCha20-Poly1305 / OpenSSL AES-256-GCM) and support zero-DB ENV and wp-config.php overrides.', 'mds-release-manager' ),
				} ),
				renderPostureBanner(),
				el( 'div', { style: { marginTop: '16px' } }, schemas.map( renderServiceCard ) )
			);

		const renderSecurityTab = () => {
			const envLines = [];
			schemas.forEach( ( schema ) => {
				( schema.fields || [] ).forEach( ( field ) => {
					const name = ( field.const_names && field.const_names[ 0 ] )
						|| ( 'MDS_SECRET_' + schema.namespace.toUpperCase() + '_' + field.key.toUpperCase() );
					envLines.push( "define( '" + name + "', '' );" );
				} );
			} );

			return el(
				Fragment,
				null,
				el(
					PanelBody,
					{ title: __( 'Master Key & Zero-Downtime Rotation', 'mds-release-manager' ), initialOpen: true },
					el(
						'p',
						null,
						__( 'Define MDS_RM_SECRETS_KEY in wp-config.php to isolate vault encryption from WordPress auth salts. To rotate keys without downtime, move your previous key to MDS_RM_SECRETS_KEY_OLD, set the new MDS_RM_SECRETS_KEY, and click Re-encrypt All Secrets.', 'mds-release-manager' )
					),
					el(
						'div',
						{ style: { display: 'flex', gap: '8px', flexWrap: 'wrap', marginBottom: '12px' } },
						el(
							Button,
							{ variant: 'secondary', onClick: generateKey },
							__( 'Generate MDS_RM_SECRETS_KEY', 'mds-release-manager' )
						),
						el(
							Button,
							{ variant: 'primary', isBusy: rotating, disabled: rotating, onClick: rotateAll },
							__( 'Re-encrypt All Secrets', 'mds-release-manager' )
						)
					),
					generatedSnippet &&
						el(
							'pre',
							{ style: { background: '#f6f7f7', padding: '10px', border: '1px solid #dcdcde', overflowX: 'auto' } },
							generatedSnippet
						)
				),
				el(
					PanelBody,
					{ title: __( 'wp-config.php Override Template', 'mds-release-manager' ), initialOpen: false },
					el(
						'p',
						null,
						__( 'Copy any constant below into wp-config.php (or set the same name as a server environment variable via Doppler, Infisical, or Docker) to keep credentials completely out of the database.', 'mds-release-manager' )
					),
					el(
						'pre',
						{ style: { background: '#f6f7f7', padding: '10px', border: '1px solid #dcdcde', overflowX: 'auto' } },
						envLines.join( '\n' )
					)
				),
				el(
					PanelBody,
					{ title: __( 'Recent Vault Activity (Metadata Only)', 'mds-release-manager' ), initialOpen: false },
					audit.length === 0
						? el( 'p', null, __( 'No vault activity recorded yet.', 'mds-release-manager' ) )
						: el(
							'table',
							{ className: 'widefat striped' },
							el(
								'thead',
								null,
								el(
									'tr',
									null,
									el( 'th', null, __( 'Date', 'mds-release-manager' ) ),
									el( 'th', null, __( 'Action', 'mds-release-manager' ) ),
									el( 'th', null, __( 'Service', 'mds-release-manager' ) ),
									el( 'th', null, __( 'Field', 'mds-release-manager' ) ),
									el( 'th', null, __( 'User ID', 'mds-release-manager' ) )
								)
							),
							el(
								'tbody',
								null,
								audit.slice( 0, 25 ).map( ( row, idx ) =>
									el(
										'tr',
										{ key: idx },
										el( 'td', null, row.date || '' ),
										el( 'td', null, row.action || '' ),
										el( 'td', null, row.namespace || '' ),
										el( 'td', null, row.field || '' ),
										el( 'td', null, String( row.user_id || 0 ) )
									)
								)
							)
						)
				)
			);
		};

		if ( ! isHub ) {
			return el(
				'div',
				{ className: 'mds-rm-credentials-workspace' },
				el( SectionInfo, {
					title: __( 'Credentials Managed on Hub Site', 'mds-release-manager' ),
					summary: __( 'All MDS credentials and API keys are stored centrally on the Hub site. Spoke sites read from the Hub vault automatically.', 'mds-release-manager' ),
				} ),
				hubUrl &&
					el(
						Button,
						{ variant: 'primary', href: hubUrl },
						__( 'Open Hub Credentials Settings', 'mds-release-manager' )
					)
			);
		}

		return el(
			'div',
			{ className: 'mds-rm-credentials-workspace' },
			el( TabPanel, {
				tabs: [
					{ name: 'services', title: __( 'Service Credentials', 'mds-release-manager' ) },
					{ name: 'security', title: __( 'Security, Rotation & Audit', 'mds-release-manager' ) },
				],
				children: ( tab ) => ( tab.name === 'security' ? renderSecurityTab() : renderCredentialsTab() ),
			} ),
			el( SnackbarList, { notices, onRemove: dismissNotice } )
		);
	}

	function mount() {
		const rootEl = document.getElementById( 'mds-rm-credentials-admin-root' );
		if ( ! rootEl ) {
			return;
		}
		if ( wp.element.createRoot ) {
			wp.element.createRoot( rootEl ).render( el( CredentialsErrorBoundary, null, el( CredentialsApp ) ) );
		} else if ( wp.element.render ) {
			wp.element.render( el( CredentialsErrorBoundary, null, el( CredentialsApp ) ), rootEl );
		}
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', mount );
	} else {
		mount();
	}
} )( window.wp );
