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
            var customState = wp.element.useState('');
            var customName = customState[0];
            const contextId = props.context?.postId || 0;
            const { source, resolved, picker } = window.mdsRmUseReleasePicker({ attributes, setAttributes, contextId });
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
            return el('div', wp.blockEditor.useBlockProps(),
                el(InspectorControls, null,
                    el(PanelBody, { title: 'Release Links', initialOpen: true },
                        picker,
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
                el(window.mdsRmReleaseLinksControls, { attributes, setAttributes }),
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
