(function(wp) {
    var Fragment = wp.element.Fragment;
    var el = wp.element.createElement;
    var SelectControl = wp.components.SelectControl;
    var TextControl = wp.components.TextControl;

    function styleControl(name, definition, value, updateStyle) {
        var props = {
            key: name,
            label: definition.label,
            value: value,
            onChange: function(next) { updateStyle(name, next); }
        };
        if (definition.type === 'align') {
            props.options = [
                { label: 'Default / inherit', value: '' },
                { label: 'Start', value: 'flex-start' },
                { label: 'Center', value: 'center' },
                { label: 'End', value: 'flex-end' },
                { label: 'Space between', value: 'space-between' }
            ];
            return el(SelectControl, props);
        }
        if (definition.type === 'vertical_align' || definition.type === 'direction' || definition.type === 'wrap') {
            var values = {
                vertical_align: ['flex-start', 'center', 'flex-end', 'stretch'],
                direction: ['row', 'row-reverse', 'column', 'column-reverse'],
                wrap: ['wrap', 'nowrap', 'wrap-reverse']
            };
            props.options = [''].concat(values[definition.type]).map(function(option) {
                return { label: option || 'Default / inherit', value: option };
            });
            return el(SelectControl, props);
        }
        if (definition.type === 'border_style') {
            props.options = ['', 'none', 'solid', 'dashed', 'dotted', 'double'].map(function(option) {
                return { label: option || 'Default / inherit', value: option };
            });
            return el(SelectControl, props);
        }
        if (definition.type === 'color') {
            props.help = 'Hex color, transparent, or inherit.';
            return el(Fragment, { key: name },
                el(TextControl, props),
                el(wp.components.ColorPalette, {
                    value: /^#[0-9a-f]{3,8}$/i.test(value) ? value : undefined,
                    onChange: function(next) { updateStyle(name, next || ''); }
                })
            );
        }
        if (definition.type === 'length' || definition.type === 'spacing') props.help = 'Use CSS lengths, e.g. 12px or 1em. Spacing accepts up to four values.';
        if (definition.type === 'width') props.help = 'Use auto or a CSS length, e.g. 100% or 20rem.';
        if (definition.type === 'opacity') props.help = 'A number between 0 and 1.';
        return el(TextControl, props);
    }

    window.mdsRmBlockStyleControl = styleControl;
})(window.wp);
