(function(wp) {
    var addFilter = wp.hooks.addFilter;
    var createHigherOrderComponent = wp.compose.createHigherOrderComponent;
    var Fragment = wp.element.Fragment;
    var el = wp.element.createElement;
    var InspectorControls = wp.blockEditor.InspectorControls;
    var PanelBody = wp.components.PanelBody;
    var SelectControl = wp.components.SelectControl;
    var TextControl = wp.components.TextControl;
    // --- 1. Dynamic Text HOC for Core Blocks ---
    var allowedBlocks = ['core/paragraph', 'core/heading', 'core/button', 'core/image'];
    var metaOptions = [
        { label: '-- None (Static content) --', value: '' },
        { label: 'UPC/EAN', value: 'upc_ean' },
        { label: 'Release Year', value: 'release_year' },
        { label: 'Record Label', value: 'record_label' },
        { label: 'Distributor', value: 'distributor' },
        { label: 'Release Type', value: 'release_type' },
        { label: 'Media Formats', value: 'release_media_format' },
        { label: 'Release Tags', value: 'release_tags' },
        { label: 'Buy Now Link (Primary URL)', value: 'buy_now_link' },
        { label: 'Spotify Link (URL)', value: 'stream_Spotify' },
        { label: 'Apple Music Link (URL)', value: 'stream_Apple Music' },
        { label: 'Amazon Music Link (URL)', value: 'stream_Amazon Music' },
        { label: 'YouTube Music Link (URL)', value: 'stream_YouTube Music' },
        { label: 'Soundcloud Link (URL)', value: 'stream_Soundcloud' }
    ];

    addFilter('blocks.registerBlockType', 'mds-rm/add-attributes', function(settings, name) {
        if (name.indexOf('mds-rm/') === 0 || name.indexOf('mds-commerce/') === 0) {
            settings.icon = el('span', { className: 'mds-brand-icon', 'aria-hidden': true });
        }
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


})(window.wp);
