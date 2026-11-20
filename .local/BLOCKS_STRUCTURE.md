# MDS Release Manager - Gutenberg Blocks Structure

## Overview

The MDS Release Manager plugin now uses the WordPress-recommended Gutenberg block folder structure. Each block is self-contained in its own folder with all necessary files.

## Block Structure

Each block folder contains the following files:

```
blocks/
├── artist-discography/
│   ├── block.json           # Block metadata and configuration
│   ├── index.js             # Block editor component and registration
│   ├── index.asset.php      # Dependencies and version info
│   ├── render.php           # Server-side render callback
│   └── frontend.css         # Frontend styles
├── release-meta/
│   ├── block.json
│   ├── index.js
│   ├── index.asset.php
│   ├── render.php
│   └── frontend.css
└── release-links/
    ├── block.json
    ├── index.js
    ├── index.asset.php
    ├── render.php
    └── frontend.css
```

## Block Files Explained

### block.json
Defines the block metadata including:
- Block name, title, and description
- Supported features (alignment, spacing, etc.)
- Block attributes and their types
- Editor and render scripts
- Dependencies on WordPress packages

### index.js
Contains the block's editor component:
- Block registration with `registerBlockType()`
- Editor UI using WordPress components
- Block attributes management
- Inspector panel controls

### render.php
Server-side render callback that:
- Receives block attributes
- Processes data
- Returns rendered HTML
- Uses `get_block_wrapper_attributes()` for wrapper props

### index.asset.php
Auto-generated dependencies file containing:
- List of WordPress script dependencies
- Version number for cache busting

### frontend.css
Styles for the block on the frontend:
- Display and layout styles
- Responsive design rules
- Currently minimal; can be expanded as needed

## Block Registration

Blocks are registered automatically in `includes/class-mds-rm-gutenberg-blocks.php` using:

```php
register_block_type_from_metadata( MDS_RM_PATH . '/blocks/' . $block );
```

This approach:
- Reads block.json for configuration
- Automatically enqueues assets
- Registers the block with WordPress
- Requires WordPress 5.9+

## Blocks Included

### 1. Artist Discography (mds-rm/artist-discography)
- Displays a complete discography for an artist
- Attributes: `artistId` (integer)
- Uses `MDS_RM_Discography::render_block()` for rendering

### 2. Release Meta (mds-rm/release-meta)
- Displays customizable release metadata
- Attributes: `upcOverride`, `items[]`, `styles{}`
- Uses `MDS_RM_Release_Meta::render()` for rendering
- Responsive styling controls for desktop/tablet/mobile

### 3. Release Links (mds-rm/release-links)
- Displays customizable release links and streaming providers
- Attributes: `upcOverride`, `items[]`, `newTab`, `showAudio`, `styles{}`
- Uses `MDS_RM_Release_Links::render()` for rendering
- Dynamic provider configuration

## Core Block Integration

The plugin also adds metadata fields to core blocks:
- **Core Paragraph**: Replace text with metadata
- **Core Heading**: Replace heading text with metadata
- **Core Button**: Replace button link with metadata
- **Core Image**: Replace image source with metadata

Controls are added via `js/core-blocks-filter.js` which:
- Adds attributes to supported core blocks
- Provides inspector panel controls
- Displays visual indicators for dynamic blocks
- Handles metadata value replacement in `render_block` filter

## Development Workflow

### Adding a New Block

1. Create a new folder under `blocks/`
2. Create the required files:
   - `block.json` with configuration
   - `index.js` with editor component
   - `index.asset.php` with dependencies
   - `render.php` with server-side logic
   - `frontend.css` with styles
3. Add the block name to the `$blocks` array in `mds_rm_register_gutenberg_widget_blocks()`
4. The autoloader will register it automatically

### Modifying Block Behavior

- **Editor behavior**: Edit `blocks/{block-name}/index.js`
- **Frontend output**: Edit `blocks/{block-name}/render.php`
- **Configuration**: Edit `blocks/{block-name}/block.json`
- **Frontend styles**: Edit `blocks/{block-name}/frontend.css`

## Migration Notes

- **Previous structure**: Single `js/blocks.js` file with all block definitions
- **New structure**: Modular per-block files following WordPress standards
- **Legacy file**: Original `class-mds-rm-gutenberg-blocks-legacy.php` preserved for reference
- **Core blocks filter**: Separated into `js/core-blocks-filter.js` for clarity

## Compatibility

- Requires WordPress 5.9+ for `register_block_type_from_metadata()`
- Requires PHP 8.0+ (plugin minimum)
- Uses WordPress REST API and hooks for server-side rendering

## Performance Considerations

- Each block's assets are only loaded when the block is used
- No global script enqueuing when using `register_block_type_from_metadata()`
- Server-side rendering reduces JavaScript bundle size
- CSS modules keep styles scoped and maintainable

## References

- [WordPress Block Development Handbook](https://developer.wordpress.org/block-editor/getting-started/create-block/)
- [Block JSON Documentation](https://developer.wordpress.org/block-editor/reference-guides/block-api/block-metadata/)
- [Server-Side Rendering](https://developer.wordpress.org/block-editor/how-to-guides/block-tutorial/creating-dynamic-blocks/)
