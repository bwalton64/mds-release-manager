(function(wp) {
    var Fragment = wp.element.Fragment;
    var el = wp.element.createElement;
    var InspectorControls = wp.blockEditor.InspectorControls;
    var PanelBody = wp.components.PanelBody;
    var SelectControl = wp.components.SelectControl;
    var TextControl = wp.components.TextControl;
    var registerBlockType = wp.blocks.registerBlockType;
    var ServerSideRender = wp.serverSideRender.ServerSideRender || wp.serverSideRender;
    const { __ } = wp.i18n;
    const name = 'mds-rm/release-meta';

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

    registerBlockType(name, {
        edit: function(props) {
            var attributes = props.attributes;
            var setAttributes = props.setAttributes;
            var items = attributes.items || mdsReleaseMeta.items;
            const contextId = props.context?.postId || 0;
            const { picker, source, resolved } = window.mdsRmUseReleasePicker({ attributes, setAttributes, contextId });

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
            return el('div', wp.blockEditor.useBlockProps(),
                el(InspectorControls, null,
                    el(PanelBody, { title: __('Release Meta', 'mds-release-manager'), initialOpen: true }, picker),
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
                el(window.mdsRmReleaseMetaControls, { attributes, setAttributes }),
                el(ServerSideRender, {
                    block: name, attributes, httpMethod: 'POST',
                    urlQueryArgs: contextId ? { post_id: contextId } : undefined,
                    EmptyResponsePlaceholder: () => el('p', null, resolved
                        ? __('This Release has no enabled metadata to display.', 'mds-release-manager')
                        : source.error
                            ? __('The Release source could not be checked.', 'mds-release-manager')
                            : source.loading
                                ? __('Checking Release context…', 'mds-release-manager')
                                : __('Select a Release to preview its metadata.', 'mds-release-manager')),
                })
            );
        },
        save: function() { return null; }
    });

})(window.wp);
