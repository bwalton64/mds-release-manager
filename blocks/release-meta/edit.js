(function(wp) {
    var Fragment = wp.element.Fragment;
    var el = wp.element.createElement;
    var InspectorControls = wp.blockEditor.InspectorControls;
    var PanelBody = wp.components.PanelBody;
    var SelectControl = wp.components.SelectControl;
    var TextControl = wp.components.TextControl;
    var registerBlockType = wp.blocks.registerBlockType;
    var ServerSideRender = wp.serverSideRender;
    var styleControl = window.mdsRmBlockStyleControl;

    var iconPresets = [
        { label: 'Choose a common icon...', value: '' },
        { label: 'No icon', value: '__none' },
        { label: 'Album (Dashicons)', value: 'dashicons dashicons-album' },
        { label: 'Calendar (Dashicons)', value: 'dashicons dashicons-calendar-alt' },
        { label: 'Artist (Dashicons)', value: 'dashicons dashicons-groups' },
        { label: 'Tag (Dashicons)', value: 'dashicons dashicons-tag' },
        { label: 'Star (Dashicons)', value: 'dashicons dashicons-star-filled' },
        { label: 'Calendar (Font Awesome)', value: 'fas fa-calendar' },
        { label: 'Disc (Font Awesome)', value: 'fas fa-compact-disc' },
        { label: 'Distributor (Font Awesome)', value: 'fas fa-tower-broadcast' },
        { label: 'Barcode (Font Awesome)', value: 'fas fa-barcode' },
        { label: 'Music (Font Awesome)', value: 'fas fa-music' }
    ];

    registerBlockType('mds-rm/release-meta', {
        apiVersion: 3,
        title: 'Release Meta Block',
        icon: 'album',
        category: 'widgets',
        usesContext: ['postId', 'postType'],
        attributes: {
            upcOverride: { type: 'string', default: '' },
            items: { type: 'array', default: mdsReleaseMeta.items },
            styles: { type: 'object', default: {} }
        },
        edit: function(props) {
            var attributes = props.attributes;
            var setAttributes = props.setAttributes;
            var useState = wp.element.useState;
            var state = useState('desktop');
            var device = state[0];
            var setDevice = state[1];
            var items = attributes.items || mdsReleaseMeta.items;
            var styles = attributes.styles || {};

            function updateItem(index, change) {
                var next = items.map(function(item, i) {
                    return i === index ? Object.assign({}, item, change) : item;
                });
                setAttributes({ items: next });
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
                    el(PanelBody, { title: 'Release Meta', initialOpen: true },
                        el(TextControl, {
                            label: 'UPC/EAN Override (Optional)',
                            help: 'Leave blank to use the current release or supported context.',
                            value: attributes.upcOverride,
                            onChange: function(val) { setAttributes({ upcOverride: val }); }
                        })
                    ),
                    items.map(function(item, index) {
                        var definition = mdsReleaseMeta.fields[item.field];
                        if (!definition) return null;
                        return el(PanelBody, { key: item.field, title: definition.label, initialOpen: false },
                            el(wp.components.ToggleControl, {
                                label: 'Show field',
                                checked: !!item.enabled,
                                onChange: function(value) { updateItem(index, { enabled: value }); }
                            }),
                            el(SelectControl, {
                                label: 'Choose icon',
                                value: '',
                                options: iconPresets,
                                onChange: function(value) {
                                    if (value) updateItem(index, { icon: value === '__none' ? '' : value });
                                }
                            }),
                            el(TextControl, {
                                label: 'Icon classes',
                                help: 'Use Dashicons (dashicons dashicons-album) or Font Awesome (fas fa-calendar). Leave blank for no icon.',
                                value: item.icon,
                                onChange: function(value) { updateItem(index, { icon: value }); }
                            }),
                            el(TextControl, {
                                label: 'Tooltip title',
                                value: item.title,
                                onChange: function(value) { updateItem(index, { title: value }); }
                            }),
                            el(wp.components.Button, {
                                variant: 'secondary',
                                disabled: index === 0,
                                onClick: function() { moveItem(index, -1); },
                                'aria-label': 'Move ' + definition.label + ' up'
                            }, 'Move up'),
                            ' ',
                            el(wp.components.Button, {
                                variant: 'secondary',
                                disabled: index === items.length - 1,
                                onClick: function() { moveItem(index, 1); },
                                'aria-label': 'Move ' + definition.label + ' down'
                            }, 'Move down')
                        );
                    })
                ),
                el(InspectorControls, { group: 'styles' },
                    el(PanelBody, { title: 'Release Meta Styles', initialOpen: true },
                        el(SelectControl, {
                            label: 'Device',
                            value: device,
                            options: [
                                { label: 'Desktop', value: 'desktop' },
                                { label: 'Tablet (1024px and below)', value: 'tablet' },
                                { label: 'Mobile (767px and below)', value: 'mobile' }
                            ],
                            onChange: setDevice
                        }),
                        el('p', null, 'Leave blank to inherit the larger device or the default.'),
                        Object.keys(mdsReleaseMeta.styles).map(function(name) {
                            return styleControl(name, mdsReleaseMeta.styles[name], (styles[device] || {})[name] || '', updateStyle);
                        })
                    )
                ),
                el(ServerSideRender, { block: 'mds-rm/release-meta', attributes: attributes, httpMethod: 'POST' })
            );
        },
        save: function() { return null; }
    });

})(window.wp);
