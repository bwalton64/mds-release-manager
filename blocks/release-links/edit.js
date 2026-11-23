(function(wp) {
    var Fragment = wp.element.Fragment;
    var el = wp.element.createElement;
    var InspectorControls = wp.blockEditor.InspectorControls;
    var PanelBody = wp.components.PanelBody;
    var TextControl = wp.components.TextControl;
    var registerBlockType = wp.blocks.registerBlockType;
    var ServerSideRender = wp.serverSideRender.ServerSideRender || wp.serverSideRender;
    const { __ } = wp.i18n;
    const name = 'mds-rm/release-links';

    registerBlockType(name, {
        edit: function(props) {
            var attributes = props.attributes;
            var setAttributes = props.setAttributes;
            const defaults = mdsReleaseLinks.defaults || {};
            var customState = wp.element.useState('');
            var customName = customState[0];
            const contextId = props.context?.postId || 0;
            const { source, resolved, picker } = window.mdsRmUseReleasePicker({ attributes, setAttributes, contextId, enabled: props.isSelected });
            var inheritedItems = (defaults.items || []).slice();
            mdsReleaseLinks.providers.map(function(provider) { return provider.name; }).concat(['__buy_now']).forEach(function(name) {
                if (name && !inheritedItems.some(function(item) { return item.name.toLowerCase() === name.toLowerCase(); })) {
                    inheritedItems.push({ name: name, enabled: true });
                }
            });
            var items = window.mdsRmReleaseItems.resolve(inheritedItems, attributes, 'name');
            if (attributes.items) inheritedItems.forEach(function(item) {
                if (!items.some(function(saved) { return saved.name.toLowerCase() === item.name.toLowerCase(); })) items.push(item);
            });
            function updateItem(index, change) {
                window.mdsRmReleaseItems.update(attributes, setAttributes, items, 'name', index, change);
            }
            function moveItem(index, direction) {
                window.mdsRmReleaseItems.move(attributes, setAttributes, items, 'name', index, direction);
            }
            function resetProperty(item, key, label) {
                if (attributes.items || !Object.prototype.hasOwnProperty.call(attributes.itemOverrides?.[item.name] || {}, key)) return null;
                return el(wp.components.Button, {
                    variant: 'link',
                    onClick: function() { window.mdsRmReleaseItems.resetProperty(attributes, setAttributes, item.name, key); }
                }, label);
            }
            return el('div', wp.blockEditor.useBlockProps(),
                el(InspectorControls, null,
                    el(PanelBody, { title: 'Release Links', initialOpen: true },
                        picker,
                        el(wp.components.SelectControl, {
                            label: __('Open links in a new tab', 'mds-release-manager'),
                            value: attributes.newTab === undefined ? '' : String(attributes.newTab),
                            options: [{ label: defaults.newTab === false ? __('Use site default (No)', 'mds-release-manager') : __('Use site default (Yes)', 'mds-release-manager'), value: '' }, { label: __('Yes', 'mds-release-manager'), value: 'true' }, { label: __('No', 'mds-release-manager'), value: 'false' }],
                            onChange: function(value) { setAttributes({ newTab: value === '' ? undefined : value === 'true' }); }
                        }),
                        el(wp.components.SelectControl, {
                            label: __('Show audio player', 'mds-release-manager'),
                            value: attributes.showAudio === undefined ? '' : String(attributes.showAudio),
                            options: [{ label: defaults.showAudio === false ? __('Use site default (No)', 'mds-release-manager') : __('Use site default (Yes)', 'mds-release-manager'), value: '' }, { label: __('Yes', 'mds-release-manager'), value: 'true' }, { label: __('No', 'mds-release-manager'), value: 'false' }],
                            onChange: function(value) { setAttributes({ showAudio: value === '' ? undefined : value === 'true' }); }
                        }),
                        el(wp.components.SelectControl, {
                            label: __('Multi-format Buy Now behavior', 'mds-release-manager'),
                            help: __('Applies when a release has 2+ Media Formats and no store extension overrides Buy Now.', 'mds-release-manager'),
                            value: attributes.buyNowMode === undefined ? '' : String(attributes.buyNowMode),
                            options: [
                                { label: defaults.buyNowMode === 'direct' ? __('Use site default (Primary Direct Link)', 'mds-release-manager') : __('Use site default (Choose Format Modal)', 'mds-release-manager'), value: '' },
                                { label: __('Choose Format Modal', 'mds-release-manager'), value: 'picker' },
                                { label: __('Primary Direct Link', 'mds-release-manager'), value: 'direct' }
                            ],
                            onChange: function(value) { setAttributes({ buyNowMode: value === '' ? undefined : value }); }
                        }),
                        (attributes.items || attributes.itemOrder || attributes.itemOverrides) && el(wp.components.Button, {
                            variant: 'tertiary',
                            onClick: function() { setAttributes({ items: undefined, itemOrder: undefined, itemOverrides: undefined }); }
                        }, __('Use site link defaults', 'mds-release-manager')),
                        !attributes.items && attributes.itemOrder && el(wp.components.Button, {
                            variant: 'link', onClick: function() { setAttributes({ itemOrder: undefined }); }
                        }, __('Use site link order', 'mds-release-manager')),
                        el('p', null, 'URLs come from the release. Providers without overrides remain visible.'),
                        el(TextControl, {
                            label: 'Additional provider name',
                            help: 'Add an override for a saved provider not in the current settings.',
                            value: customName, onChange: customState[1]
                        }),
                        el(wp.components.Button, {
                            variant: 'secondary',
                            disabled: !customName.trim() || items.some(function(item) { return item.name.toLowerCase() === customName.trim().toLowerCase(); }),
                            onClick: function() {
                                if (attributes.items) setAttributes({ items: items.concat([{ name: customName.trim(), enabled: true }]) });
                                else setAttributes({ itemOverrides: { ...attributes.itemOverrides, [customName.trim()]: { enabled: true } } });
                                customState[1]('');
                            }
                        }, 'Add provider override')
                    ),
                    items.map(function(item, index) {
                        var label = item.name === '__buy_now' ? 'Buy Now' : item.name;
                        return el(PanelBody, { key: item.name, title: label, initialOpen: false },
                            el(wp.components.ToggleControl, {
                                label: 'Show link', checked: item.enabled !== false,
                                onChange: function(value) { updateItem(index, { enabled: value }); }
                            }),
                            resetProperty(item, 'enabled', __('Use site visibility', 'mds-release-manager')),
                            el(wp.components.SelectControl, {
                                label: __('Choose icon preset', 'mds-release-manager'),
                                value: '',
                                options: (window.mdsRmStylePrimitives && window.mdsRmStylePrimitives.ICON_PRESETS) || [],
                                onChange: function(value) {
                                    if (value) updateItem(index, { icon: value === '__none' ? '' : value });
                                }
                            }),
                            el(TextControl, {
                                label: 'Icon classes', help: 'Leave blank to use the provider default. Dashicons and Font Awesome are supported.',
                                value: item.icon || '', onChange: function(value) { updateItem(index, { icon: value }); }
                            }),
                            resetProperty(item, 'icon', __('Use site icon', 'mds-release-manager')),
                            el(TextControl, {
                                label: 'Tooltip title', help: 'Leave blank to use the provider name.',
                                value: item.title || '', onChange: function(value) { updateItem(index, { title: value }); }
                            }),
                            resetProperty(item, 'title', __('Use site tooltip', 'mds-release-manager')),
                            !attributes.items && attributes.itemOverrides?.[item.name] && el(wp.components.Button, {
                                variant: 'tertiary',
                                onClick: function() { window.mdsRmReleaseItems.resetItem(attributes, setAttributes, item.name); }
                            }, __('Use site defaults for this link', 'mds-release-manager')),
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
                el(window.mdsRmReleaseLinksControls, { attributes, setAttributes, defaults: defaults.linkStyles || {} }),
                el(ServerSideRender, {
                    block: name, attributes, httpMethod: 'POST',
                    urlQueryArgs: contextId ? { post_id: contextId } : undefined,
                    EmptyResponsePlaceholder: () => el('p', null, resolved
                        ? __('This Release has no links or audio to display.', 'mds-release-manager')
                        : source.error
                            ? __('The Release source could not be checked.', 'mds-release-manager')
                            : source.loading
                                ? __('Checking Release context…', 'mds-release-manager')
                                : __('Select a Release to preview its links.', 'mds-release-manager')),
                })
            );
        },
        save: function() { return null; }
    });

})(window.wp);
