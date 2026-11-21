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
            const defaults = mdsReleaseMeta.defaults || {};
            const inheritedItems = defaults.items || mdsReleaseMeta.items;
            var items = window.mdsRmReleaseItems.resolve(inheritedItems, attributes, 'field');
            const contextId = props.context?.postId || 0;
            const { picker, source, resolved } = window.mdsRmUseReleasePicker({ attributes, setAttributes, contextId });

            function updateItem(index, change) {
                window.mdsRmReleaseItems.update(attributes, setAttributes, items, 'field', index, change);
            }
            function moveItem(index, direction) {
                window.mdsRmReleaseItems.move(attributes, setAttributes, items, 'field', index, direction);
            }
            function resetProperty(item, key, label) {
                if (attributes.items || !Object.prototype.hasOwnProperty.call(attributes.itemOverrides?.[item.field] || {}, key)) return null;
                return el(wp.components.Button, {
                    variant: 'link',
                    onClick: function() { window.mdsRmReleaseItems.resetProperty(attributes, setAttributes, item.field, key); }
                }, label);
            }
            return el('div', wp.blockEditor.useBlockProps(),
                el(InspectorControls, null,
                    el(PanelBody, { title: __('Release Meta', 'mds-release-manager'), initialOpen: true }, picker),
                    (attributes.items || attributes.itemOrder) && el(wp.components.Button, {
                        variant: 'tertiary',
                        onClick: function() { setAttributes({ items: undefined, itemOrder: undefined, itemOverrides: undefined }); }
                    }, __('Use site field defaults', 'mds-release-manager')),
                    !attributes.items && attributes.itemOrder && el(wp.components.Button, {
                        variant: 'link', onClick: function() { setAttributes({ itemOrder: undefined }); }
                    }, __('Use site field order', 'mds-release-manager')),
                    items.map(function(item, index) {
                        var definition = mdsReleaseMeta.fields[item.field];
                        if (!definition) return null;
                        return el(PanelBody, { key: item.field, title: definition.label, initialOpen: false },
                            el(wp.components.ToggleControl, {
                                label: 'Show field',
                                checked: !!item.enabled,
                                onChange: function(value) { updateItem(index, { enabled: value }); }
                            }),
                            resetProperty(item, 'enabled', __('Use site visibility', 'mds-release-manager')),
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
                            resetProperty(item, 'icon', __('Use site icon', 'mds-release-manager')),
                            el(TextControl, {
                                label: 'Tooltip title',
                                value: item.title,
                                onChange: function(value) { updateItem(index, { title: value }); }
                            }),
                            resetProperty(item, 'title', __('Use site tooltip', 'mds-release-manager')),
                            !attributes.items && attributes.itemOverrides?.[item.field] && el(wp.components.Button, {
                                variant: 'tertiary',
                                onClick: function() { window.mdsRmReleaseItems.resetItem(attributes, setAttributes, item.field); }
                            }, __('Use site defaults for this field', 'mds-release-manager')),
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
                el(window.mdsRmReleaseMetaControls, { attributes, setAttributes, defaults: defaults.metaStyles || {} }),
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
