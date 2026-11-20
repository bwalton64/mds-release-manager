(function(wp) {
    var el = wp.element.createElement;
    var InspectorControls = wp.blockEditor.InspectorControls;
    var PanelBody = wp.components.PanelBody;
    var TextControl = wp.components.TextControl;
    var ServerSideRender = wp.serverSideRender;

    wp.blocks.registerBlockType('mds-rm/artist-discography', {
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
})(window.wp);
