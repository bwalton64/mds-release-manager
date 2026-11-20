(function(wp) {
    var addFilter = wp.hooks.addFilter;
    var createHigherOrderComponent = wp.compose.createHigherOrderComponent;
    var Fragment = wp.element.Fragment;
    var el = wp.element.createElement;
    var InspectorControls = wp.blockEditor.InspectorControls;
    var PanelBody = wp.components.PanelBody;
    var SelectControl = wp.components.SelectControl;
    var TextControl = wp.components.TextControl;
    var registerBlockType = wp.blocks.registerBlockType;
    var ServerSideRender = wp.serverSideRender;
    var styleControl = window.mdsRmBlockStyleControl;

    // --- 1. Dynamic Text HOC for Core Blocks ---
    var allowedBlocks = ['core/paragraph', 'core/heading', 'core/button', 'core/image'];
    var metaOptions = [
        { label: '-- None (Static content) --', value: '' },
        { label: 'UPC/EAN', value: 'upc_ean' },
        { label: 'Release Year', value: 'release_year' },
        { label: 'Record Label', value: 'record_label' },
        { label: 'Distributor', value: 'distributor' },
        { label: 'Release Format', value: 'release_format' },
        { label: 'Release Tags', value: 'release_tags' },
        { label: 'Buy Now Link (URL)', value: 'buy_now_link' },
        { label: 'Spotify Link (URL)', value: 'stream_Spotify' },
        { label: 'Apple Music Link (URL)', value: 'stream_Apple Music' },
        { label: 'Amazon Music Link (URL)', value: 'stream_Amazon Music' },
        { label: 'YouTube Music Link (URL)', value: 'stream_YouTube Music' },
        { label: 'Soundcloud Link (URL)', value: 'stream_Soundcloud' }
    ];

    addFilter('blocks.registerBlockType', 'mds-rm/add-attributes', function(settings, name) {
        if (allowedBlocks.includes(name)) {
            settings.attributes = Object.assign(settings.attributes || {}, {
                mdsMetaField: { type: 'string', default: '' },
                mdsUpcOverride: { type: 'string', default: '' }
            });
        }
        return settings;
    });

    var withMdsControls = createHigherOrderComponent(function(BlockEdit) {
        return function(props) {
            var postType = wp.data.useSelect(function(select) {
                var editor = select('core/editor');
                return editor ? editor.getCurrentPostType() : '';
            }, []);
            if (!allowedBlocks.includes(props.name) || postType === 'artist') return el(BlockEdit, props);
            var attributes = props.attributes;
            var setAttributes = props.setAttributes;
            return el( Fragment, null,
                el(BlockEdit, props),
                el( InspectorControls, null,
                    el( PanelBody, { title: 'MDS Release Data (Dynamic)', initialOpen: false },
                        el(SelectControl, {
                            label: 'Bind to Metadata',
                            value: attributes.mdsMetaField,
                            options: metaOptions,
                            onChange: function(val) { setAttributes({ mdsMetaField: val }); }
                        }),
                        attributes.mdsMetaField ? el(TextControl, {
                            label: 'UPC Override (Optional)',
                            help: 'Leave blank to automatically inherit from the current post or Query Loop.',
                            value: attributes.mdsUpcOverride,
                            onChange: function(val) { setAttributes({ mdsUpcOverride: val }); }
                        }) : null,
                        attributes.mdsMetaField ? el('p', {style: {fontSize: '12px', color: '#666'}}, 
                            'Important for Buttons/Images: You must enter a dummy URL (like "#") in the block settings so WordPress generates the link HTML, which we will dynamically replace.'
                        ) : null
                    )
                )
            );
        };
    }, 'withMdsControls');
    addFilter('editor.BlockEdit', 'mds-rm/add-controls', withMdsControls);

    var withMdsIndicator = createHigherOrderComponent(function(BlockListBlock) {
        return function(props) {
            if (allowedBlocks.includes(props.name) && props.attributes.mdsMetaField) {
                var customWrapperProps = props.wrapperProps || {};
                customWrapperProps.className = (customWrapperProps.className || '') + ' mds-dynamic-block-active';
                return el(BlockListBlock, Object.assign({}, props, { wrapperProps: customWrapperProps }));
            }
            return el(BlockListBlock, props);
        };
    }, 'withMdsIndicator');
    addFilter('editor.BlockListBlock', 'mds-rm/add-indicator', withMdsIndicator);


    registerBlockType('mds-rm/artist-discography', {
        apiVersion: 3,
        title: 'Artist Discography',
        icon: 'album',
        category: 'widgets',
        usesContext: ['postId', 'postType'],
        attributes: { artistId: { type: 'integer', default: 0 } },
        edit: function(props) {
            return el('div', wp.blockEditor.useBlockProps(),
                el(InspectorControls, null,
                    el(PanelBody, { title: 'Artist Discography', initialOpen: true },
                        el(TextControl, {
                            label: 'Artist post ID (optional)',
                            type: 'number',
                            min: 0,
                            help: 'Leave blank to use the current Artist.',
                            value: props.attributes.artistId || '',
                            onChange: function(value) { props.setAttributes({ artistId: parseInt(value, 10) || 0 }); }
                        })
                    )
                ),
                el(ServerSideRender, { block: 'mds-rm/artist-discography', attributes: props.attributes, httpMethod: 'POST' })
            );
        },
        save: function() { return null; }
    });

    registerBlockType('mds-rm/release-links', {
        apiVersion: 3,
        title: 'Release Links Block',
        icon: 'album',
        category: 'widgets',
        usesContext: ['postId', 'postType'],
        attributes: {
            upcOverride: { type: 'string', default: '' },
            items: { type: 'array', default: [] },
            newTab: { type: 'boolean', default: true },
            showAudio: { type: 'boolean', default: true },
            styles: { type: 'object', default: {} }
        },
        edit: function(props) {
            var attributes = props.attributes;
            var setAttributes = props.setAttributes;
            var deviceState = wp.element.useState('desktop');
            var device = deviceState[0];
            var customState = wp.element.useState('');
            var customName = customState[0];
            var styles = attributes.styles || {};
            var items = (attributes.items || []).slice();
            mdsReleaseLinks.providers.map(function(provider) { return provider.name; }).concat(['__buy_now']).forEach(function(name) {
                if (name && !items.some(function(item) { return item.name.toLowerCase() === name.toLowerCase(); })) {
                    items.push({ name: name, enabled: true });
                }
            });
            function updateItem(index, change) {
                setAttributes({ items: items.map(function(item, i) { return i === index ? Object.assign({}, item, change) : item; }) });
            }
            function moveItem(index, direction) {
                var target = index + direction;
                if (target < 0 || target >= items.length) return;
                var next = items.slice();
                var item = next[index];
                next[index] = next[target];
                next[target] = item;
                setAttributes({ items: next });
            }
            function updateStyle(name, value) {
                var next = Object.assign({}, styles);
                next[device] = Object.assign({}, next[device] || {});
                if (value === '') delete next[device][name];
                else next[device][name] = value;
                setAttributes({ styles: next });
            }
            return el('div', wp.blockEditor.useBlockProps(),
                el(InspectorControls, null,
                    el(PanelBody, { title: 'Release Links', initialOpen: true },
                        el(TextControl, {
                            label: 'UPC/EAN Override (Optional)',
                            help: 'Leave blank to use the current release or supported context.',
                            value: attributes.upcOverride,
                            onChange: function(value) { setAttributes({ upcOverride: value }); }
                        }),
                        el(wp.components.ToggleControl, {
                            label: 'Open links in a new tab', checked: attributes.newTab,
                            onChange: function(value) { setAttributes({ newTab: value }); }
                        }),
                        el(wp.components.ToggleControl, {
                            label: 'Show audio player', checked: attributes.showAudio,
                            onChange: function(value) { setAttributes({ showAudio: value }); }
                        }),
                        el('p', null, 'URLs come from the release. Providers without overrides remain visible.'),
                        el(TextControl, {
                            label: 'Additional provider name',
                            help: 'Add an override for a saved provider not in the current settings.',
                            value: customName, onChange: customState[1]
                        }),
                        el(wp.components.Button, {
                            variant: 'secondary',
                            disabled: !customName.trim() || items.some(function(item) { return item.name.toLowerCase() === customName.trim().toLowerCase(); }),
                            onClick: function() { setAttributes({ items: items.concat([{ name: customName.trim(), enabled: true }]) }); customState[1](''); }
                        }, 'Add provider override')
                    ),
                    items.map(function(item, index) {
                        var label = item.name === '__buy_now' ? 'Buy Now' : item.name;
                        return el(PanelBody, { key: item.name, title: label, initialOpen: false },
                            el(wp.components.ToggleControl, {
                                label: 'Show link', checked: item.enabled !== false,
                                onChange: function(value) { updateItem(index, { enabled: value }); }
                            }),
                            el(TextControl, {
                                label: 'Icon classes', help: 'Leave blank to use the provider default. Dashicons and Font Awesome are supported.',
                                value: item.icon || '', onChange: function(value) { updateItem(index, { icon: value }); }
                            }),
                            el(TextControl, {
                                label: 'Tooltip title', help: 'Leave blank to use the provider name.',
                                value: item.title || '', onChange: function(value) { updateItem(index, { title: value }); }
                            }),
                            el(wp.components.Button, {
                                variant: 'secondary', disabled: index === 0,
                                onClick: function() { moveItem(index, -1); }, 'aria-label': 'Move ' + label + ' up'
                            }, 'Move up'),
                            ' ',
                            el(wp.components.Button, {
                                variant: 'secondary', disabled: index === items.length - 1,
                                onClick: function() { moveItem(index, 1); }, 'aria-label': 'Move ' + label + ' down'
                            }, 'Move down')
                        );
                    })
                ),
                el(InspectorControls, { group: 'styles' },
                    el(PanelBody, { title: 'Release Links Styles', initialOpen: true },
                        el(SelectControl, {
                            label: 'Device', value: device,
                            options: [
                                { label: 'Desktop', value: 'desktop' },
                                { label: 'Tablet (1024px and below)', value: 'tablet' },
                                { label: 'Mobile (767px and below)', value: 'mobile' }
                            ],
                            onChange: deviceState[1]
                        }),
                        el('p', null, 'Leave blank to inherit the larger device or the default.'),
                        Object.keys(mdsReleaseLinks.styles).map(function(name) {
                            return styleControl(name, mdsReleaseLinks.styles[name], (styles[device] || {})[name] || '', updateStyle);
                        })
                    )
                ),
                el(ServerSideRender, { block: 'mds-rm/release-links', attributes: attributes, httpMethod: 'POST' })
            );
        },
        save: function() { return null; }
    });

})(window.wp);
