# MDS Release Manager Blocks - Quick Reference

## Block Overview

| Block | Name | Folder | Purpose |
|-------|------|--------|---------|
| Artist Discography | `mds-rm/artist-discography` | `blocks/artist-discography/` | Display artist releases |
| Release Meta | `mds-rm/release-meta` | `blocks/release-meta/` | Display release metadata |
| Release Links | `mds-rm/release-links` | `blocks/release-links/` | Display release links |

## File Structure Template

Every block folder contains:
```
blocks/[block-name]/
├── block.json         ← Block configuration (metadata)
├── index.js           ← Editor UI & registration
├── index.asset.php    ← Dependencies & version
├── render.php         ← Server-side rendering
└── frontend.css       ← Frontend styles
```

## Key Files

### block.json
```json
{
  "apiVersion": 3,
  "name": "mds-rm/block-name",
  "title": "Block Title",
  "category": "widgets",
  "attributes": { /* ... */ },
  "editorScript": "file:./index.js",
  "render": "file:./render.php"
}
```

### index.js (Editor Component)
```javascript
(function(wp) {
    wp.blocks.registerBlockType('mds-rm/block-name', {
        // Editor UI definition
        edit: function(props) { /* ... */ },
        save: function() { return null; } // Dynamic block
    });
})(window.wp);
```

### render.php (Server Rendering)
```php
<?php
if (!defined('ABSPATH')) exit;

$wrapper_attributes = get_block_wrapper_attributes();
// Get context, process data
// Call render class method
?>
<div <?php echo wp_kses_post($wrapper_attributes); ?>>
    <!-- Output -->
</div>
```

## Block Render Classes

Each block has an associated render class:

| Block | Render Class | Method |
|-------|--------------|--------|
| Artist Discography | `MDS_RM_Discography` | `render_block()` |
| Release Meta | `MDS_RM_Release_Meta` | `render()` |
| Release Links | `MDS_RM_Release_Links` | `render()` |

### Usage in render.php
```php
$output = MDS_RM_Release_Meta::render($attributes, $post_id);
echo $output ? '<div ' . get_block_wrapper_attributes() . '>' . wp_kses_post($output) . '</div>' : '';
```

## Adding a New Block

### Step 1: Create Folder
```bash
mkdir blocks/my-new-block
```

### Step 2: Create block.json
```json
{
  "apiVersion": 3,
  "name": "mds-rm/my-new-block",
  "title": "My New Block",
  "category": "widgets",
  "usesContext": ["postId", "postType"],
  "attributes": {
    "myAttribute": { "type": "string", "default": "" }
  },
  "editorScript": "file:./index.js",
  "render": "file:./render.php"
}
```

### Step 3: Create index.js
```javascript
(function(wp) {
    var el = wp.element.createElement;
    var registerBlockType = wp.blocks.registerBlockType;
    
    registerBlockType('mds-rm/my-new-block', {
        apiVersion: 3,
        title: 'My New Block',
        category: 'widgets',
        attributes: { myAttribute: { type: 'string', default: '' } },
        edit: function(props) {
            return el('div', wp.blockEditor.useBlockProps(),
                'Block editor UI here'
            );
        },
        save: function() { return null; }
    });
})(window.wp);
```

### Step 4: Create render.php
```php
<?php
if (!defined('ABSPATH')) exit;

$wrapper_attributes = get_block_wrapper_attributes();
$output = /* render logic */;

echo $output ? '<div ' . wp_kses_post($wrapper_attributes) . '>' . wp_kses_post($output) . '</div>' : '';
```

### Step 5: Create other files
```bash
touch blocks/my-new-block/index.asset.php
touch blocks/my-new-block/frontend.css
```

### Step 6: Update Registration
In `includes/class-mds-rm-gutenberg-blocks.php`, add to the `$blocks` array:
```php
$blocks = array(
    'artist-discography',
    'release-meta',
    'release-links',
    'my-new-block',  // ← Add here
);
```

## Common WordPress Components

```javascript
// Inspector Controls
el(wp.blockEditor.InspectorControls, null,
  el(wp.components.PanelBody, { title: 'Settings' },
    el(wp.components.TextControl, { /* ... */ })
  )
)

// Text Input
el(wp.components.TextControl, {
  label: 'Label',
  value: props.attributes.value,
  onChange: (val) => props.setAttributes({ value: val })
})

// Toggle
el(wp.components.ToggleControl, {
  label: 'Enable',
  checked: props.attributes.enabled,
  onChange: (val) => props.setAttributes({ enabled: val })
})

// Select Dropdown
el(wp.components.SelectControl, {
  label: 'Choose',
  value: props.attributes.selected,
  options: [
    { label: 'Option 1', value: 'opt1' },
    { label: 'Option 2', value: 'opt2' }
  ],
  onChange: (val) => props.setAttributes({ selected: val })
})

// Server-side Render
el(wp.serverSideRender, {
  block: 'mds-rm/block-name',
  attributes: props.attributes
})
```

## Common Patterns

### Get Post Context
```javascript
// In edit function
var postType = wp.data.useSelect(select => 
  select('core/editor').getCurrentPostType()
);

var postId = wp.data.useSelect(select =>
  select('core/editor').getCurrentPostId()
);
```

### Server-side: Get Post from Context
```php
$post_id = isset($block->context['postId']) ? $block->context['postId'] : get_the_ID();
```

### Device-aware Styling
```javascript
var deviceState = wp.element.useState('desktop');
var device = deviceState[0];
var setDevice = deviceState[1];

// Store separate styles per device
var styles = attributes.styles || {};
styles[device] = { /* device-specific styles */ };
```

## Testing Checklist

- [ ] Block appears in editor block library
- [ ] Block can be added to posts
- [ ] Inspector controls work
- [ ] Attributes save correctly
- [ ] Server-side rendering displays
- [ ] Existing posts load without errors
- [ ] Block validation passes (no console errors)
- [ ] Responsive behavior works on different devices

## Useful Links

- [Block Editor Handbook](https://developer.wordpress.org/block-editor/)
- [Block API Reference](https://developer.wordpress.org/block-editor/reference-guides/block-api/)
- [Block.json Reference](https://developer.wordpress.org/block-editor/reference-guides/block-api/block-metadata/)
- [Component Library](https://developer.wordpress.org/block-editor/reference-guides/components/)

## Debugging

### Check block registration
```javascript
console.log(wp.blocks.getBlockType('mds-rm/block-name'));
```

### Check block in post
```javascript
wp.data.select('core/editor').getBlocks()
  .filter(b => b.name === 'mds-rm/block-name')
```

### Enable debug logging
```php
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
define('WP_DEBUG_DISPLAY', false);
// Check wp-content/debug.log
```

## Performance Tips

- Lazy load heavy components
- Use `useMemo` for expensive computations
- Minimize re-renders with proper dependencies
- Server-side render complex logic
- Split large blocks into smaller components

## Security Best Practices

- Always sanitize output with `wp_kses_post()`
- Use `esc_attr()` for HTML attributes
- Use `esc_html()` for text content
- Validate/sanitize attributes in render.php
- Use nonces for AJAX requests
- Check user capabilities before rendering sensitive data
