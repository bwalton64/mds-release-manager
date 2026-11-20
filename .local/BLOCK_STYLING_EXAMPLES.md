# Block Styling System - Developer Examples

## Getting Global Styles for a Block

```php
<?php
// Get desktop styles for Release Meta block
$desktop_styles = MDS_RM_Block_Styles::get_global_styles( 'mds-rm/release-meta', 'desktop' );

// Output: Array of style key => value pairs
// [
//   'text_color' => '#333333',
//   'font_size' => '16px',
//   'container_padding' => '20px',
//   ...
// ]
?>
```

## Merging Global and Block Styles

```php
<?php
// In block render.php (this is what we do)
$block_styles = isset( $attributes['styles'] ) ? (array) $attributes['styles'] : array();
$global_styles = MDS_RM_Block_Styles::get_global_styles( 'mds-rm/release-meta' );
$merged_styles = MDS_RM_Block_Styles::merge_styles( 'mds-rm/release-meta', $block_styles );

// Block styles override global styles
?>
```

## Generating CSS from Styles

```php
<?php
// Convert style array to inline CSS string
$styles = array(
    'text_color' => '#333333',
    'font_size' => '16px',
    'container_padding' => '20px',
);

$css = MDS_RM_Block_Styles::generate_css( $styles );
// Output: "color:#333333;font-size:16px;padding:20px"

// Apply to element
echo '<div style="' . esc_attr( $css ) . '">Content</div>';

// Or with selector for use in <style> tag
$css_rule = MDS_RM_Block_Styles::generate_css( $styles, '.my-block' );
// Output: ".my-block{color:#333333;font-size:16px;padding:20px}"
?>
```

## Getting All Available Fonts

```php
<?php
// Access available fonts (private method, but here's the list)
$fonts = array(
    '' => 'Default / Inherit',
    'inherit' => 'Inherit',
    'serif' => 'Serif',
    'sans-serif' => 'Sans-serif',
    'monospace' => 'Monospace',
    'cursive' => 'Cursive',
    'fantasy' => 'Fantasy',
    'Arial' => 'Arial',
    'Helvetica' => 'Helvetica',
    // ... and more
);
?>
```

## Customizer Setting Keys

### Release Meta Block

**Desktop Styles:**
- `mds_rm_release_meta_desktop_container_background`
- `mds_rm_release_meta_desktop_container_padding`
- `mds_rm_release_meta_desktop_container_gap`
- `mds_rm_release_meta_desktop_container_align`
- `mds_rm_release_meta_desktop_text_color`
- `mds_rm_release_meta_desktop_font_family`
- `mds_rm_release_meta_desktop_font_size`
- `mds_rm_release_meta_desktop_font_weight`
- `mds_rm_release_meta_desktop_line_height`
- `mds_rm_release_meta_desktop_icon_color`
- `mds_rm_release_meta_desktop_icon_size`
- `mds_rm_release_meta_desktop_icon_opacity`
- `mds_rm_release_meta_desktop_icon_gap`

**Tablet and Mobile:** Replace `desktop` with `tablet` or `mobile` in the key above.

### Release Links Block

**Desktop Styles:**
- `mds_rm_release_links_desktop_container_background`
- `mds_rm_release_links_desktop_container_padding`
- `mds_rm_release_links_desktop_container_gap`
- `mds_rm_release_links_desktop_button_background`
- `mds_rm_release_links_desktop_button_color`
- `mds_rm_release_links_desktop_button_size`
- `mds_rm_release_links_desktop_button_radius`
- `mds_rm_release_links_desktop_icon_size`

**Tablet and Mobile:** Replace `desktop` with `tablet` or `mobile`.

### Artist Discography Block

**Desktop Styles:**
- `mds_rm_artist_discography_desktop_container_background`
- `mds_rm_artist_discography_desktop_container_padding`
- `mds_rm_artist_discography_desktop_container_gap`
- `mds_rm_artist_discography_desktop_text_color`
- `mds_rm_artist_discography_desktop_font_family`
- `mds_rm_artist_discography_desktop_font_size`
- `mds_rm_artist_discography_desktop_font_weight`
- `mds_rm_artist_discography_desktop_table_background`
- `mds_rm_artist_discography_desktop_table_border`
- `mds_rm_artist_discography_desktop_table_padding`

**Tablet and Mobile:** Replace `desktop` with `tablet` or `mobile`.

## Retrieving Specific Theme Mods

```php
<?php
// Get a single theme mod value
$text_color = get_theme_mod( 'mds_rm_release_meta_desktop_text_color', '' );

// Get all theme mods with prefix (would need custom code)
$all_mods = get_theme_mods();
$release_meta_desktop = array_filter( 
    $all_mods, 
    function( $key ) {
        return strpos( $key, 'mds_rm_release_meta_desktop_' ) === 0;
    },
    ARRAY_FILTER_USE_KEY
);
?>
```

## Style Key to CSS Property Mapping

The system automatically maps style keys to CSS properties:

| Style Key | CSS Property |
|-----------|--------------|
| `container_background` | `background-color` |
| `container_padding` | `padding` |
| `container_gap` | `gap` |
| `container_align` | `justify-content` |
| `text_color` | `color` |
| `font_family` | `font-family` |
| `font_size` | `font-size` |
| `font_weight` | `font-weight` |
| `line_height` | `line-height` |
| `icon_color` | `color` |
| `icon_size` | `font-size` |
| `icon_opacity` | `opacity` |
| `icon_gap` | `gap` |
| `button_background` | `background-color` |
| `button_color` | `color` |
| `button_size` | `width` |
| `button_radius` | `border-radius` |
| `table_background` | `background-color` |
| `table_border` | `border-color` |
| `table_padding` | `padding` |

## Extending the System

### Adding a New Block

1. Update `MDS_RM_Block_Styles::$managed_blocks` to include new block name
2. Create `register_[block_name]_customizer()` method
3. Add settings with prefix `mds_rm_[block_name]_`
4. Update `get_all_customizer_keys()` to return keys for new block
5. Update `get_customizer_prefix()` to handle new block prefix
6. Update render.php for new block to call styling methods

### Adding New Style Properties

For each block, follow this pattern:

1. Add control definition in customizer registration:
   ```php
   $controls = array(
       'new_property' => array( 'Label', 'type' ),
   );
   ```

2. Add mapping in `map_style_key_to_css_property()`:
   ```php
   'new_property' => 'css-property-name',
   ```

3. Add to control keys in `get_all_customizer_keys()`

4. Add control in render.php to merge and apply

### Custom CSS Modifiers

If you need to transform a value before applying it:

```php
<?php
// Example: Convert padding shorthand
$padding = $styles['container_padding']; // "10px 20px"
$css_value = $padding; // Already valid CSS

// Or custom logic
if ( isset( $styles['button_size'] ) ) {
    // Could convert to other units or add !important
    $button_size = $styles['button_size'];
}
?>
```

## Sanitization

All theme mod values are sanitized with `sanitize_text_field()` to prevent injection attacks.

Color values are validated against hex color patterns before use.

## Backward Compatibility

The system is non-breaking:
- Existing blocks continue to work without styles
- Customizer defaults don't change existing behavior
- Block-level styles only apply when explicitly set
