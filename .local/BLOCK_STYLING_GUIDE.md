# Block Styling System - Quick Reference Guide

## Architecture Overview

```
┌─────────────────────────────────────────────────────────────┐
│                    WordPress Customizer                      │
│                                                               │
│  MDS Release Manager Panel                                  │
│  ├── Release Fallback Template (existing)                   │
│  ├── Artist Fallback Template (existing)                    │
│  └── Block Styling (NEW)                                    │
│      ├── Release Meta                                        │
│      │   ├── Container Styles (responsive)                  │
│      │   ├── Text Styles (responsive)                       │
│      │   └── Icon Styles (responsive)                       │
│      ├── Release Links                                       │
│      │   ├── Container Styles (responsive)                  │
│      │   ├── Button Styles (responsive)                     │
│      │   └── Icon Styles (responsive)                       │
│      └── Artist Discography                                  │
│          ├── Container Styles (responsive)                  │
│          ├── Text Styles (responsive)                       │
│          └── Table Styles (responsive)                      │
└─────────────────────────────────────────────────────────────┘
                              │
                              ▼
                    Theme Mods Storage
              (mds_rm_[block]_[breakpoint]_[property])
                              │
                 ┌────────────┴────────────┐
                 ▼                         ▼
         Frontend Rendering         Gutenberg Editor
                 │                         │
         MDS_RM_Block_Styles::        Block Attributes
         get_global_styles()          (styles object)
                 │                         │
                 └────────────┬────────────┘
                              ▼
                   Merge Global + Block-Level
                   (block overrides global)
                              │
                              ▼
                        Inline CSS on
                        Block Wrapper <div>
```

## Style Application Flow

### Frontend Rendering
1. **Block render.php** receives `attributes['styles']` from Gutenberg
2. Calls `MDS_RM_Block_Styles::get_global_styles('block-name')` to fetch Customizer defaults
3. Merges: `array_merge($global_styles, $block_styles)`
4. Calls `generate_css($merged_styles)` to convert to CSS string
5. Applies CSS as inline `style` attribute on block wrapper div

### CSS Generation
- Each style key maps to a CSS property (e.g., `text_color` → `color`)
- Multiple values can be combined (e.g., `container_padding` → `padding` with original value)
- Output: `property:value;property:value;...`

## Customizer Setting Keys Format

```
mds_rm_[block]_[breakpoint]_[property]

Examples:
- mds_rm_release_meta_desktop_text_color
- mds_rm_release_meta_tablet_font_size
- mds_rm_release_meta_mobile_container_padding
- mds_rm_release_links_desktop_button_background
- mds_rm_artist_discography_tablet_table_border
```

**Breakpoints:**
- `desktop` - 1200px and up
- `tablet` - 768px to 1199px
- `mobile` - 0 to 767px

## Block Attribute Structure

Block JSON attributes already include:
```json
{
  "styles": {
    "type": "object",
    "default": {},
    "additionalProperties": true
  }
}
```

This stores all block-level style overrides as a flat object (future phases can organize by breakpoint).

## Font Selection

Available fonts in Customizer (system fonts only):
- Generic families: serif, sans-serif, monospace, cursive, fantasy
- Common families: Arial, Helvetica, Times New Roman, Georgia, Verdana, etc.

## Available Style Properties by Block

### Release Meta Block
**Container:** background, padding, gap (item), align (flex)
**Text:** color, font-family, font-size, font-weight, line-height
**Icon:** color, size, opacity, gap (icon-to-text)

### Release Links Block
**Container:** background, padding, gap (item)
**Button:** background, color (icon), size, radius
**Icon:** size

### Artist Discography Block
**Container:** background, padding, gap (item)
**Text:** color, font-family, font-size, font-weight
**Table:** background, border (color), padding (cells)

## CSS Priority

Block-level styles > Global defaults > No style

Each block wrapper gets inline CSS from merged global + block styles.
Customizer generates additional media-query CSS for responsive breakpoints.

## Implementation Status

✅ **Phase 1 - Complete**
- Backend: MDS_RM_Block_Styles class with Customizer registration
- Frontend: Render integration with style merging and CSS generation
- Configuration: Block JSON updated with styles attribute

🔄 **Phase 2 - Future**
- Gutenberg editor UI: Visual controls for each style property
- Responsive preview in editor
- "Reset to Global" buttons for per-block overrides

## Class Reference

### MDS_RM_Block_Styles

**Public Methods:**
- `register_hooks()` - Register all actions and filters
- `register_customizer_settings($wp_customize)` - Set up Customizer panels
- `get_global_styles($block_name, $breakpoint)` - Fetch theme mod styles
- `merge_styles($block_name, $block_styles, $breakpoint)` - Combine styles
- `generate_css($styles, $selector)` - Convert to CSS string
- `enqueue_block_styles()` - Add global styles to frontend

**Key Constants:**
- `$managed_blocks` - Array of block names
- `$breakpoints` - Array of breakpoints (desktop, tablet, mobile)

## Testing the Implementation

1. Go to WordPress Customizer (Appearance → Customize)
2. Navigate to MDS Release Manager → Block Styling
3. Configure styles for each block at each breakpoint
4. Save and view blocks on frontend - styles should apply
5. In Gutenberg editor, add block styling attributes to override global styles
6. Per-block styles should override global styles
