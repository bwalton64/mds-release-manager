# MDS Release Manager

A comprehensive, extensible WordPress plugin for managing artist releases, discographies, and digital asset distribution. Designed for independent artists, record labels, and music platforms to showcase releases with metadata, streaming links, and customizable templates.

## Overview

**MDS Release Manager** transforms WordPress into a powerful music release management system. It provides native post types for Releases and Artists, advanced metadata handling, responsive content templates, Gutenberg/Elementor integrations, and WordPress Abilities API support for programmatic access.

### What Problem Does It Solve?

- **Scattered Metadata:** Centralizes release information (UPC, format, links) in a structured database
- **Template Limitations:** Provides flexible, builder-friendly templates for release and artist pages
- **Limited Integrations:** Natively supports Gutenberg, Elementor, and shortcodes
- **Multisite Complexity:** Offers network-wide sync and configuration for WordPress Multisite
- **API Access:** Exposes core features via REST API and WordPress Abilities API (MCP tools)

---

## Key Features

### Core Release Management
- ✅ **Custom Post Types:** Native "Release" and "Artist" post types with full WordPress integration
- ✅ **Structured Metadata:** UPC/EAN, format (Single/EP/Album/Digital), artist associations, and release dates
- ✅ **Release Links:** Manage links to streaming platforms (Spotify, Apple Music, Bandcamp, etc.) with per-provider configuration
- ✅ **Discography:** Automatically curated artist discographies with pagination and responsive layouts

### Frontend & Templates
- ✅ **Fallback Templates:** Smart `the_content` fallbacks for Release and Artist pages with responsive imagery
- ✅ **Gutenberg Blocks:** Native Release Meta, Release Links, and Discography blocks with per-field styling
- ✅ **Elementor Widgets:** Companion Elementor widgets and dynamic tags for builder flexibility
- ✅ **Shortcodes:** `[release-meta]`, `[release-links]`, `[artist-discography]` with responsive options
- ✅ **Builder Detection:** Automatically suppresses fallback rendering on builder-authored pages

### Customization & Control
- ✅ **Customizer Controls:** Dedicated theme customizer panel for Release and Artist appearance
- ✅ **Audio Player Support:** Native and HTML5 audio-player settings for release previews
- ✅ **Responsive Design:** Mobile-first styling with device-specific column and cell filters
- ✅ **Artist Routing:** Optional temporary routing to official artist homepages with editor warnings

### Multisite & Network
- ✅ **Network Sync:** Synchronize releases across WordPress Multisite installations
- ✅ **Network Settings:** Centralized hub site and wipe-on-uninstall configuration
- ✅ **Read-Only Spokes:** Catalog editing happens on the hub; spoke sites browse synchronized copies
  - The optional full wipe permanently removes Release and Artist posts, including Trash, from the current network. By default, uninstall keeps posts and network settings.
- ✅ **Per-Site Control:** Individual site customization with network defaults

### API & Integration
- ✅ **REST API:** Full REST API endpoints for programmatic access to releases and settings
- ✅ **WordPress Abilities API:** WordPress 6.9+ support with 4 native MCP tools for Claude/external clients
- ✅ **Hook Registry:** Extensible action/filter system for safe third-party integration
- ✅ **CSV Import/Export:** Bulk release import/export with format validation

---

## Prerequisites

### System Requirements
- **WordPress:** 6.9+
- **PHP:** 8.0+
- **Theme:** Any WordPress theme (uses fallback rendering as needed)

### Optional Dependencies
- **Gutenberg/Block Editor:** For native block support
- **Elementor:** For Elementor widget integration (Free or Pro)
- **LiteSpeed Cache:** Automatic cache purge on release updates

Font Awesome Free is bundled and loaded locally by default. Under Releases > Settings, you can select a theme or plugin that already provides the icons, or disable the bundled stylesheet.
Streaming provider and audio preview settings are available under the Release Links tab.

The Release Meta, Release Links, and Artist Discography tabs also manage site-wide
block presentation defaults. Existing and new blocks inherit changes unless a
block has an explicit override; older saved field and link lists remain complete
overrides. The automatic components in the fallback templates use these same defaults.
The block controls can restore inherited values. These defaults do not change shortcodes
or Elementor widgets.

Styling panels start collapsed. Opening one closes the previous styling panel in that
tab; click the open heading again to collapse it. Visibility, order, and behavior
panels open independently. Release Meta separates
the container, field values, and icons. Release Links separates the container, individual
buttons, and audio player. Artist Discography separates the outer block, table frame,
column headings, release rows, and cell grid.

### Multisite Support
- Fully compatible with WordPress Multisite networks
- Network activation recommended for hub+spoke setups

---

## Installation

### Windows/Cross-Platform Setup

#### Option 1: Manual Installation (Recommended for Development)

1. **Download the Plugin**
   ```bash
   cd C:\Users\[YourName]\Local Sites\[SiteName]\app\public\wp-content\plugins\
   git clone https://github.com/bwalton64/mds-release-manager.git
   cd mds-release-manager
   ```

2. **Verify PHP Requirements**
   ```powershell
   php -v  # Verify PHP 8.0+
   php --ini  # Confirm debugging configuration
   ```

3. **No Build Step Required**
   The plugin has no Composer or npm runtime dependencies. npm dependencies are used only for development smoke tests.

4. **Activate in WordPress**
   - WordPress Admin → Plugins → Find "MDS Release Manager"
   - Click **Activate**
   - For Multisite: Use network activation via Network Admin → Plugins

#### Option 2: Upload via WordPress Admin

1. WordPress Admin → Plugins → Add New
2. Click **Upload Plugin**
3. Select the plugin ZIP file
4. Click **Install Now**
5. Click **Activate**

#### Option 3: Using Local by Flywheel (Recommended for Local Development)

1. **In Local by Flywheel:**
   - Right-click your site → Open site shell
   ```bash
   cd app/public/wp-content/plugins
   git clone https://github.com/bwalton64/mds-release-manager.git
   ```

2. **Activate in WordPress:**
   - WordPress Admin → Plugins → Activate "MDS Release Manager"

3. **Enable Debugging (Optional):**
   Add to `wp-config.php`:
   ```php
   define( 'WP_DEBUG', true );
   define( 'WP_DEBUG_LOG', true );
   define( 'WP_DEBUG_DISPLAY', false );
   ```
   Routine Release Manager diagnostics appear in the PHP log only when both debug flags are `true`. Operational failures are logged even when debugging is off.

#### Multisite Installation

```bash
# Network activate for all sites
# WordPress Admin → Network Admin → Plugins → Activate "MDS Release Manager"

# Or via WP-CLI:
wp plugin activate mds-release-manager --network
```

---

## Usage

### Creating a Release (via WordPress Admin)

Releases and Artists use the classic edit screen with the plugin's organized
meta boxes; the block editor is reserved for pages and posts. On a multisite
network, catalog items can only be created and edited on the hub site — spoke
sites show a read-only, automatically synchronized copy.

1. **Navigate:** Dashboard → Releases → Add New
2. **Fill Release Information:**
   - **Title:** Release name (e.g., "Midnight Echo")
   - **UPC/EAN:** Unique product code (required)
   - **Format:** Single, EP, Album, or Digital (required, one only)
   - **Artists:** Associate artists (via post relationship)
   - **Release Date:** Publish date
   - **Excerpt:** Optional summary used by themes and SEO tools
   - **Cover Art:** Featured image (used in fallback and blocks)

3. **Add Release Links:**
   - Use the Release Links meta box to add streaming provider links
   - Configure provider-specific visibility and display order

4. **Publish Release**
   - Set status to Published
   - Release becomes visible in Discography and REST API

### Creating an Artist (via WordPress Admin)

1. **Navigate:** Dashboard → Artists → Add New
2. **Fill Artist Information:**
   - **Name:** Artist display name
   - **Bio:** Artist biography (rich text)
   - **Contact Info:** Contact details (optional)
   - **Featured Image:** Artist photo/avatar
   - **Official Homepage:** Link to artist's website (optional)

3. **Configure Routing (Optional):**
   - Enable "Route to Official Homepage" if needed
   - Provides temporary redirect to external site

4. **Publish Artist**
   - Releases automatically appear in Artist Discography

### Code Examples

#### Display a Release Block in Gutenberg

```php
// In a Gutenberg template or custom block
// Use the native "Release Meta" block to display:
// - Release title
// - Format, UPC, artist name
// - Per-field styling options

// Select by stable Release post ID, UPC/EAN, or the current Release context:
echo do_shortcode( '[release-meta release_id="123" fields="release_title,release_format,upc_ean"]' );
```

In the **Release Meta** block, search published Releases by title or full UPC/EAN. Leave the picker blank to use the current Release, supported WooCommerce context, or the Artist’s latest published Release. An unavailable saved selection falls back to context without being cleared.

Use **Styles** to adjust layout, typography, and icons. Saved tablet and mobile overrides still apply when previewing those widths. To remove them without clearing desktop or new settings, use **Legacy responsive styles → Remove legacy tablet/mobile overrides**.

##### `[release-meta]` shortcode reference

The shortcode accepts a stable Release post ID, a UPC/EAN string, or the current post context. A readable `release_id` takes precedence over `upc`; if the ID does not resolve, a supplied UPC is tried next. Without either attribute, the shortcode uses the current Release or the existing extension-provided context. UPC/EAN values are strings, so keep leading zeroes.

| Attribute | Purpose |
| --- | --- |
| `release_id` | Release post ID, matching the block's Release picker. |
| `upc` | UPC/EAN string. |
| `fields` | Optional comma-separated field names in display order. Omitting it uses the default fields. Available fields: `release_year`, `record_label`, `distributor`, `upc_ean`, `release_format`, `release_date`, `release_title`, `artist`, `release_tags`. |
| `icon_FIELD` | Override the icon class for a field, using a supported Dashicon or Font Awesome class. Use an empty value to omit that icon. |
| `title_FIELD` | Override a field's tooltip title. |

For example:

```text
[release-meta release_id="123" fields="release_title,release_format,upc_ean" icon_release_title="dashicons dashicons-album" title_upc_ean="Barcode"]
```

Style attributes set the same component controls as the block. The values apply to desktop unless a device suffix is added:

| Attribute | Style control | Attribute | Style control |
| --- | --- | --- | --- |
| `background`, `color`, `icon_color` | Background, text, and icon colors | `font_family`, `font_size`, `font_weight`, `font_style`, `line_height`, `letter_spacing`, `text_align` | Typography |
| `icon_size`, `icon_opacity`, `icon_gap` | Icon size, opacity, and icon-to-text gap | `gap` | Gap between fields |
| `align`, `vertical_align`, `direction`, `wrap`, `width` | Layout and container width | `padding`, `margin` | Container spacing |
| `border_width`, `border_style`, `border_color`, `radius` | Container border and corner radius |  |  |

Append `_tablet` or `_mobile` to a style attribute for a device-specific override, such as `gap_mobile="8px"` or `text_align_tablet="center"`. Tablet styles apply up to 1024px; mobile styles apply up to 767px and inherit tablet, then desktop values when omitted. Colors accept hex, `rgb()`/`rgba()`, `transparent`, `inherit`, or a WordPress color preset such as `var(--wp--preset--color--primary)`. Font-family and font-size accept their corresponding WordPress preset variables. Lengths accept `0` or CSS lengths (`px`, `em`, `rem`, `%`, `vw`, `vh`); spacing accepts one to four lengths. Alignment, direction, wrapping, border style, font style, and text alignment use the values offered by the block controls. Opacity is from `0` to `1`; invalid style values are omitted.

#### Display Release Links

In the **Release Links** block, search published Releases by title or full UPC/EAN. Results include the Artist where available. Leave the picker blank to use the current Release, supported WooCommerce product context, or the current Artist's latest published Release. If a saved choice becomes unavailable, the editor warns you and uses the current context without deleting the choice. If that Release has no links or audio, nothing is shown to visitors.

Use the block's **Styles** tab for layout, button and icon styling, and audio-player width. New settings apply at all widths unless a previously saved tablet or mobile override takes precedence. Check those widths in Gutenberg's viewport preview. To remove the old breakpoint styles, use **Legacy responsive styles → Remove legacy tablet/mobile overrides**.

```php
// Display streaming provider links for a release
echo do_shortcode( '[release-links release_id="123" show_audio="1" new_tab="1"]' );

// Output example:
// [Spotify] [Apple Music] [Bandcamp]
```

##### `[release-links]` shortcode reference

Release selection follows the same `release_id` → `upc` → current-context precedence as `[release-meta]`. The block's provider URLs and defaults still come from the selected Release and **Releases → Settings**.

| Attribute | Purpose |
| --- | --- |
| `release_id`, `upc` | Select a Release by post ID or UPC/EAN. |
| `order` | Comma-separated provider names in display order. Include `__buy_now` to position Buy Now. |
| `hide` | Comma-separated provider names to hide. |
| `icon_PROVIDER`, `title_PROVIDER` | Override a provider's icon or tooltip. Use a lowercase provider name with spaces replaced by underscores, such as `icon_apple_music`; use `icon_buy_now` and `title_buy_now` for Buy Now. Names in `order` can also identify saved providers that are not configured in the default provider list. |
| `new_tab` | `1` (default) opens links in a new tab; `0` opens them in the current tab. |
| `show_audio` | `1` (default) displays the configured audio preview; `0` hides it. |

Both toggles accept only `0` or `1`; an invalid value is logged and uses the enabled default.

For example:

```text
[release-links release_id="123" order="Apple Music,Spotify,__buy_now" hide="Spotify" icon_apple_music="dashicons dashicons-album" title_apple_music="Listen on Apple Music" show_audio="0" new_tab="0"]
```

Style attributes match the Release Links block: `background`, `gap`, `align`, `vertical_align`, `direction`, `wrap`, `width`, `padding`, `margin`, `border_width`, `border_style`, `border_color`, `radius`, `link_gap`, `button_size`, `button_radius`, `button_color`, `button_background`, `button_border_width`, `button_border_style`, `button_border_color`, `icon_size`, `hover_opacity`, and `audio_width`. Append `_tablet` or `_mobile` to override a style at that breakpoint; inheritance, value types, and WordPress preset references match `[release-meta]`. A `button_background` override takes precedence over configured provider colors.

#### Add Discography to an Artist Page

Insert **Artist Discography** in the block editor. Search for a published Artist on this site under **Settings → Artist Discography**, or leave the field blank to use the current Artist. On a regular page without an Artist context, select an Artist. If a saved Artist becomes unavailable, the editor warns you and retains the selection until you clear or replace it.

Use the block's **Styles** tab for outer spacing, colors, typography, and borders. **Outer discography block** explains which native WordPress controls style the whole block. **Table frame and base styles**, **Column headings**, **Release rows and striping**, and **Cell grid and release links** explain which parts of the table their controls affect. Available presets and custom options depend on the active theme. Use **Reset discography table styles** to remove the table overrides without resetting the block's outer styles. The shortcode accepts the same table and outer-wrapper settings.

```php
// As a shortcode (omit artist_id to use the current Artist):
echo do_shortcode( '[artist-discography artist_id="5" striped="1" header_background="#222222" cell_padding_top="10px" wrapper_text_color="#ffffff"]' );
```

##### `[artist-discography]` shortcode reference

Omit `artist_id` to use the current Artist; otherwise pass the local Artist post ID. The shortcode renders the same paginated table as the block and automatic Artist-page fallback.

Table controls are flat shortcode attributes corresponding to the block's `tableStyles` keys: replace each hyphen with an underscore. Supported attributes are `link_color`, `link_decoration`, `table_radius`; `table_background`, `table_color`, `table_font_family`, `table_font_size`, `table_font_style`, `table_font_weight`, `table_line_height`; the same `background`, `color`, `font_family`, `font_size`, `font_style`, `font_weight`, and `line_height` suffixes prefixed with `header_` or `body_` (plus `header_text_align` and `body_text_align`); `table_margin_top/right/bottom/left`, `table_padding_top/right/bottom/left`, `cell_padding_top/right/bottom/left`; `table_border_width/color/style`; and `cell_border_width/color/style`.

Use `striped="1"` to enable alternating row backgrounds and optionally set `stripe_background`; `striped="0"` (the default) disables striping. For example:

```text
[artist-discography artist_id="5" table_background="#ffffff" header_background="#222222" header_text_align="center" cell_padding_top="10px" cell_border_style="solid" link_color="#0055aa" striped="1" stripe_background="#f4f4f4"]
```

The block's native outer styles are also available with `wrapper_`-prefixed attributes: `wrapper_background`, `wrapper_text_color`, `wrapper_link_color`, `wrapper_font_size`, `wrapper_font_family`, `wrapper_font_weight`, `wrapper_font_style`, `wrapper_line_height`, `wrapper_letter_spacing`, `wrapper_margin`, `wrapper_padding`, `wrapper_border_color`, `wrapper_border_radius`, `wrapper_border_style`, and `wrapper_border_width`. Outer colors, font sizes, font families, and spacing accept validated CSS values or their matching WordPress preset variables (for example `var(--wp--preset--spacing--30)`). Table font-family values use a WordPress font-family preset; table font sizes accept pixel values or a font-size preset. Table font weights are hundreds from 100 to 900; styles are `normal` or `italic`; header/body text alignment is `start`, `center`, or `end`; link decoration is `underline` or `none`; and table/cell border styles accept `none`, `solid`, `dashed`, `dotted`, `double`, `groove`, `ridge`, `inset`, `outset`, or `hidden`. Table-style settings apply at all viewport sizes; the Release Meta and Release Links shortcodes additionally support responsive style suffixes.

#### Retrieve Releases via REST API

```bash
# Get the first page of published releases (ID, title, and UPC/EAN)
curl -i "https://yoursite.com/wp-json/mds/v1/releases?per_page=100&page=1"

# The Release post type is also exposed via the WordPress core REST API
curl "https://yoursite.com/wp-json/wp/v2/release?per_page=10"
```

The plugin endpoint keeps its array response and supports `page` (default `1`) and `per_page` (default and maximum `100`). Use the `X-WP-Total` and `X-WP-TotalPages` response headers to retrieve subsequent pages.

The Release editor's Artist picker uses the authenticated `GET /wp-json/mds/v1/artists?search=<term>&page=<page>` endpoint, which returns at most 20 matching Artists per page to users who can edit posts.

Manual network sync uses `POST /wp-json/mds/v1/sync-network`. Each request processes a bounded batch and returns `job_id`, cumulative counts, and `done`; pass the returned `job_id` on subsequent requests until `done` is `true`. If a request is interrupted, call the endpoint again with that job ID to resume.

#### Use WordPress Abilities API (MCP Tools)

If using Claude or other MCP clients (WordPress 6.9+):

```
User: "Get all releases from the music site"
Claude: Calls mds_rm_get_releases ability
Result: Returns paginated releases with metadata

User: "Update the audio player setting to HTML5"
Claude: Calls mds_rm_save_settings ability
Result: Updates plugin configuration

User: "Synchronize the network catalog"
Claude: Calls mds_rm_sync_network and continues with the returned job_id until done is true
```

#### Access via REST API (JavaScript)

```javascript
// Fetch every page using the plugin's REST API
async function getReleases() {
  const releases = [];
  let page = 1;

  while (true) {
    const response = await fetch(`/wp-json/mds/v1/releases?per_page=100&page=${page}`);
    if (!response.ok) throw new Error(`Release lookup failed: ${response.status}`);
    releases.push(...await response.json());

    const pages = Number(response.headers.get('X-WP-TotalPages'));
    if (!pages || page >= pages) return releases;
    page += 1;
  }
}

getReleases().then(releases => {
  releases.forEach(release => console.log(`${release.title} (${release.upc})`));
});
```

#### Style fallback templates without a builder

The plugin automatically composes Release and Artist pages inside the active theme when no
builder such as Elementor is used. In **Appearance → Customize → MDS Release Manager**,
open **Release Fallback Template** for Layout (including split or stacked), Featured Image,
and Content Typography. Open **Artist Fallback Template** for Layout, Portrait, Biography,
and Contact Information. Style the automatic Release Meta, Release Links, and Artist Discography
blocks in their respective tabs under **Releases → Settings**. Older Customizer values for
these blocks are no longer applied.
The WordPress color picker supports opacity; clear a color to use the stylesheet or inherited
appearance. Reset a range or select the default shadow to restore its default.
Publish the Customizer changes to apply them to eligible pages using the active theme.

Builder-authored pages do not receive these fallback layouts. A Release with site-wide
routing enabled also bypasses its fallback. Existing Release Meta, Release Links, or Artist
Discography blocks and shortcodes in the page content suppress the corresponding automatic
component so it is not duplicated. A theme can also override the frontend templates
in its `mds-release-manager/templates/` directory.

---

## Project Folder Structure

```
mds-release-manager/
│
├── README.md                           # This file
├── changelog.md                        # Version history and release notes
├── mds-release-manager.php             # Main plugin file (entry point)
├── uninstall.php                       # Plugin cleanup on uninstall
│
├── includes/                           # Core plugin classes (SPL-autoloaded)
│   ├── class-mds-rm-plugin.php                     # Plugin bootstrap
│   ├── admin/
│   │   ├── class-mds-rm-admin-interface.php        # Settings and admin UI
│   │   ├── class-mds-rm-csv-handler.php             # CSV import/export
│   │   ├── class-mds-rm-network-admin.php           # Multisite network settings
│   │   └── views/                                  # Bundled admin screens and meta boxes
│   ├── core/
│   │   ├── class-mds-rm-builder-detection.php      # Page-builder detection
│   │   ├── class-mds-rm-logger.php                 # Debug logging
│   │   ├── class-mds-rm-post-types.php             # Release/Artist post type registration
│   │   ├── class-mds-rm-providers.php              # Streaming provider defaults
│   │   ├── class-mds-rm-release-model.php          # Release data model, validation, and sync
│   │   ├── class-mds-rm-sync.php                   # Network synchronization and cache purge
│   │   └── class-mds-rm-template-loader.php        # Templates and editable assets
│   ├── frontend/
│   │   ├── class-mds-rm-artist-routing.php          # Artist homepage routing
│   │   ├── class-mds-rm-assets.php                  # CSS/JS enqueuing
│   │   ├── class-mds-rm-customizer.php              # WordPress Customizer integration
│   │   ├── class-mds-rm-discography.php             # Artist discography logic
│   │   ├── class-mds-rm-frontend-templates.php      # Content fallback rendering
│   │   └── class-mds-rm-shortcodes.php              # Shortcode registration
│   ├── blocks/                         # Block registration and Release renderers
│   ├── api/                            # REST, Abilities, and virtual media
│   └── integrations/
│       └── elementor/                   # Widgets and dynamic tags
│
├── templates/                          # Theme-overridable frontend templates
│   ├── artist-fallback.php             # Artist page fallback template
│   └── release-fallback.php            # Release page fallback template
│
├── css/                                # Stylesheet directory
│   ├── admin-defaults.css              # Default replaceable admin stylesheet
│   ├── admin-styles.css                # Bundled admin view layout
│   ├── artist-fallback.css             # Artist fallback template styles
│   ├── frontend.css                    # General frontend styles
│   ├── release-fallback.css            # Release fallback template styles
│   ├── release-links.css               # Release Links block/shortcode styles
│   └── release-meta.css                # Release Meta block/shortcode styles
│
├── blocks/                             # Gutenberg block.json files and editor scripts
│   ├── artist-discography/
│   ├── release-links/
│   ├── release-meta/
│   ├── core-block-extensions.js        # Metadata bindings for core blocks
│   └── style-control.js                # Shared Release block style controls
│
├── js/                                 # Admin JavaScript
│   ├── meta-boxes.js                   # Meta box interactivity and shortcode copy-to-clipboard
│   ├── release-taxonomies.js           # Native tag editor integration for releases
│   └── settings.js                     # Settings page tab navigation
│
├── .github/                            # Project documentation and CI configuration
│   ├── copilot-instructions.md         # Contributor/AI coding guidance
│   └── technical-blueprint.md          # Technical architecture documentation
│
└── .local/                             # Internal implementation notes (not end-user docs)
    ├── ABILITIES-API.md                # WordPress Abilities API reference
    ├── IMPLEMENTATION-SUMMARY.md       # Abilities API implementation summary
    └── ...                             # Historical refactoring and audit reports
```

### Key Directory Descriptions

- **includes/**: PHP classes using the `class-mds-rm-*.php` naming convention and WordPress PHP coding style
- **templates/**: Frontend fallbacks that can be overridden in a theme's `mds-release-manager/templates/` directory. Theme copies of admin views are no longer loaded.
- **css/**: Responsive stylesheets with mobile-first design
- **blocks/**: Gutenberg metadata and editor scripts; no build step required
- **js/**: Admin scripts (jQuery bundled with WordPress)
- **.github/**: Technical documentation and contributor guidance
- **.local/**: Historical implementation summaries and audit reports kept for reference

---

## Contributing

For a WordPress release ZIP, run `git archive --format=zip --prefix=mds-release-manager/ --output=mds-release-manager.zip HEAD`. This packages the committed plugin without development tools and repository metadata.

We welcome contributions! Follow these guidelines to maintain code quality and consistency.

### Getting Started

1. **Fork the Repository**
   ```bash
   git clone https://github.com/bwalton64/mds-release-manager.git
   cd mds-release-manager
   ```

2. **Create a Feature Branch**
   ```bash
   git checkout -b feature/my-feature-name
   ```

3. **Make Your Changes**
   - Follow the existing WordPress PHP formatting conventions (tabs and nearby code style)
   - Maintain strict type declarations (`declare(strict_types=1)`)
   - Add inline comments only when clarification is needed
   - Use the `MDS_RM_` class prefix for all new classes

### Code Standards

#### PHP Formatting
```php
<?php
declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class MDS_RM_Feature_Name {
	public static function register_hooks(): void {
		add_action( 'hook_name', array( static::class, 'method_name' ) );
	}
}
```

#### Naming Conventions
- **Classes:** `MDS_RM_Feature_Name` (PascalCase with `MDS_RM_` prefix)
- **Files:** `class-mds-rm-feature-name.php` (lowercase with hyphens)
- **Functions:** `mds_rm_function_name()` (snake_case with prefix)
- **Hooks:** `mds_rm_hook_name` or `mds_rm_filter_name` (snake_case with prefix)

#### Security & Sanitization
- Always use `wp_unslash()` on `$_POST`, `$_GET`, `$_REQUEST` data
- Sanitize with `sanitize_text_field()`, `absint()`, `esc_url_raw()` as appropriate
- Validate nonces before processing forms
- Use prepared statements for custom database queries (via WordPress APIs)
- Escape output with `esc_html()`, `esc_attr()`, `esc_url()`

#### Documentation
- Document public APIs, hooks, and non-obvious behavior with PHPDoc
- Include historically accurate `@since` version tags
- Provide parameter and return type hints
- Document complex logic with inline comments (sparingly)

### Testing Your Changes

1. **Local Testing**
   ```bash
   # Activate the plugin in WordPress
   wp plugin activate mds-release-manager
   
   # Test in WordPress Admin and frontend
   # Use wp-cli for quick tests:
   wp post list --post_type=release
   wp post list --post_type=artist
   ```

2. **Multisite Testing**
   ```bash
   # For network changes, test with network activation
   wp plugin activate mds-release-manager --network
   ```

3. **Code Quality**
   ```bash
   # Verify PHP syntax
   php -l includes/class-mds-rm-*.php
   ```

### Commit Messages

Follow conventional commit format:

```
feat: Add new release metadata field
^--^  ^------------------------------
|     |
|     +-> Description (lowercase, present tense)
|
+-------> Type: feat, fix, refactor, docs, test, chore, perf
```

**Types:**
- `feat` — New feature
- `fix` — Bug fix
- `refactor` — Code restructuring (no functionality change)
- `docs` — Documentation updates
- `test` — Test additions or changes
- `chore` — Build config, deps, or tooling
- `perf` — Performance improvements

**Example Commits:**
```bash
git commit -m "feat: Add WordPress Abilities API integration"
git commit -m "fix: Correct release format validation logic"
git commit -m "docs: Update README with installation steps"
```

### Submitting Pull Requests

1. **Push Your Branch**
   ```bash
   git push origin feature/my-feature-name
   ```

2. **Create a Pull Request**
   - Provide clear title and description
   - Link related issues with `Closes #123`
   - Include testing steps in PR description

3. **PR Template**
   ```markdown
   ## Description
   Brief description of changes
   
   ## Changes
   - Change 1
   - Change 2
   
   ## Testing
   Steps to verify the changes work correctly
   
   ## Checklist
   - [ ] Code follows existing WordPress PHP formatting conventions
   - [ ] No breaking changes
   - [ ] Updated documentation
   - [ ] Tested on Windows/macOS/Linux
   ```

### Extension Development

To extend MDS Release Manager, use the Hook Registry:

```php
// Add a custom meta box (receives the WP_Post being edited)
add_action( 'mds_rm_register_custom_meta_boxes', function( $post ) {
    if ( 'release' === $post->post_type ) {
        add_meta_box( /* ... */ );
    }
});

// Save custom metadata (receives the post ID and the raw, slashed $_POST array)
add_action( 'mds_rm_save_custom_meta', function( $post_id, $raw_post ) {
    $value = sanitize_text_field( wp_unslash( $raw_post['my_custom_field'] ?? '' ) );
    update_post_meta( $post_id, 'my_custom_field', $value );
}, 10, 2 );

// Append custom fields to the audio settings section (HTML string filter)
add_filter( 'mds_rm_settings_audio_fields', function( $html ) {
    $html .= '<p><label>' . esc_html__( 'My Custom Option', 'mds-release-manager' ) . '</label></p>';
    return $html;
});

// Supply the Release Links block audio player. The first extension returning
// non-empty markup wins; the result is sanitized with an iframe-aware allowlist.
add_filter( 'mds_rm_release_links_audio_html', function( $html, $post_id, $attributes ) {
    if ( '' !== trim( $html ) ) {
        return $html; // Another extension already supplied a player.
    }
    return my_extension_player_markup( $post_id );
}, 10, 3 );

// Add a site settings tab; the callback renders its own secure form.
add_filter( 'mds_rm_site_settings_tabs', function( array $tabs ): array {
    $tabs['my-extension'] = array(
        'label'  => __( 'My Extension', 'my-extension' ),
        'render' => 'my_extension_render_settings',
    );
    return $tabs;
});
```

For network settings, use `mds_rm_network_settings_tabs` with the same
slug/label/render structure. Tab callbacks are rendered outside the core
settings forms and must handle their own capability checks, nonces, validation,
and saving. Existing `mds_rm_settings_audio_fields` and
`mds_rm_network_settings_fields` filters remain available.

#### Adding blocks to the "MDS Release Manager" inserter section

All MDS plugin blocks share one block category so they appear together in the
block editor's inserter. To place an extension plugin's blocks in that section:

1. Set `"category": "mds-release-manager"` in each block's `block.json`.
2. Register the category defensively, so the section exists exactly once no
   matter which MDS plugins are active or in what order they load:

```php
add_filter( 'block_categories_all', function( array $categories ): array {
    foreach ( $categories as $category ) {
        if ( isset( $category['slug'] ) && 'mds-release-manager' === $category['slug'] ) {
            return $categories; // Another MDS plugin already registered it.
        }
    }

    $categories[] = array(
        'slug'  => 'mds-release-manager',
        'title' => __( 'MDS Release Manager', 'my-extension-textdomain' ),
        'icon'  => 'album',
    );

    return $categories;
});
```

### Reporting Issues

When reporting bugs, include:
- WordPress version
- PHP version
- Plugin version
- Steps to reproduce
- Expected vs. actual behavior
- Screenshots or error logs

### Questions?

- Check [.local/ABILITIES-API.md](./.local/ABILITIES-API.md) for Abilities API details
- Review [.github/technical-blueprint.md](./.github/technical-blueprint.md) for architecture and technical specs
- See [changelog.md](./changelog.md) for version history

---

## License

© 2026 Walton International Business Group. MDS Release Manager is licensed under the GNU General Public License, version 2 or (at your option) any later version. See [LICENSE.txt](./LICENSE.txt) for the license text.

---

## Support

- **Documentation:** See [.github/technical-blueprint.md](./.github/technical-blueprint.md) and [.local/ABILITIES-API.md](./.local/ABILITIES-API.md)
- **Issues:** Report via [GitHub Issues](https://github.com/bwalton64/mds-release-manager/issues)
- **Discussions:** Ask in [GitHub Discussions](https://github.com/bwalton64/mds-release-manager/discussions)

---

## Changelog

For detailed version history, see [changelog.md](./changelog.md).

**Current Version:** 1.0.0  
**Last Updated:** November 20, 2026
