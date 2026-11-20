# MDS Release Manager - Technical Blueprint

## 1. Plugin Overview & Tech Stack

**Plugin:** MDS Release Manager  
**Version:** 1.0.0  
**Purpose:** Comprehensive artist release manager for streaming platforms, integrating releases, artists, audio players, and third-party services (WooCommerce, Bandcamp, Elementor).  
**Network:** Multisite-enabled.

**Requirements:**
- PHP 8.0+.
- WordPress 6.9+.
- No Composer or npm runtime dependencies. npm is used for development smoke tests only.

**Build Tools:**
- None. Direct PHP class autoloading via SPL (not PSR-4 composer).
- Assets: Vanilla JS. `/js/blocks.js` for the Gutenberg block editor; `/js/meta-boxes.js`, `/js/release-taxonomies.js`, and `/js/settings.js` for admin screens (using the jQuery bundled with WordPress).
- Styles: Vanilla CSS in `/css/frontend.css`, `/css/release-meta.css`, `/css/release-links.css`, `/css/release-fallback.css`, `/css/artist-fallback.css`, and `/css/admin-styles.css`.
- No build pipeline; all code is interpreted live.

---

## 2. Directory Structure & Architecture

```
mds-release-manager/
├── mds-release-manager.php           # Main plugin file (entry point)
├── uninstall.php                     # Uninstall handler
├── README.md                         # End-user and contributor documentation
├── changelog.md                      # Version history (Keep a Changelog format)
├── includes/                         # All class definitions (SPL autoloader)
│   ├── class-mds-rm-abilities.php           # WordPress Abilities API integration
│   ├── class-mds-rm-admin-interface.php
│   ├── class-mds-rm-artist-routing.php
│   ├── class-mds-rm-assets.php
│   ├── core/
│   │   ├── class-mds-rm-builder-detection.php
│   │   ├── class-mds-rm-logger.php
│   │   ├── class-mds-rm-post-types.php
│   │   ├── class-mds-rm-providers.php
│   │   ├── class-mds-rm-release-model.php  # Release format/UPC validation and sync model
│   │   ├── class-mds-rm-sync.php
│   │   └── class-mds-rm-template-loader.php
│   ├── class-mds-rm-csv-handler.php
│   ├── class-mds-rm-customizer.php
│   ├── class-mds-rm-discography.php
│   ├── class-mds-rm-elementor-tags.php
│   ├── class-mds-rm-elementor-widget.php
│   ├── class-mds-rm-elementor-links-widget.php
│   ├── class-mds-rm-elementor-release-meta-tag.php
│   ├── class-mds-rm-gutenberg-blocks.php
│   ├── class-mds-rm-frontend-templates.php
│   ├── class-mds-rm-release-meta.php
│   ├── class-mds-rm-release-links.php
│   ├── class-mds-rm-network-admin.php
│   ├── class-mds-rm-rest-api.php
│   ├── class-mds-rm-shortcodes.php
│   └── class-mds-rm-virtual-media.php
├── templates/                        # Frontend/backend templates
│   ├── artist-fallback.php
│   ├── artist-meta-box.php
│   ├── csv-page.php
│   ├── network-settings-page.php
│   ├── release-fallback.php
│   ├── release-meta-box.php
│   └── settings-page.php
├── js/                               # Editor and admin scripts
│   ├── blocks.js                     # Gutenberg block editor registration
│   ├── meta-boxes.js                 # Meta box interactivity and shortcode copy
│   ├── release-taxonomies.js         # Native tag editor integration
│   └── settings.js                   # Settings page tabs
├── css/                              # Frontend and admin styles
│   ├── admin-styles.css
│   ├── artist-fallback.css
│   ├── frontend.css
│   ├── release-fallback.css
│   ├── release-links.css
│   └── release-meta.css
├── .github/                          # Technical documentation and contributor guidance
│   ├── copilot-instructions.md
│   └── technical-blueprint.md        # This document
└── .local/                           # Internal implementation notes and historical reports
    ├── ABILITIES-API.md              # WordPress Abilities API reference
    ├── IMPLEMENTATION-SUMMARY.md     # Abilities API implementation summary
    └── ...                           # Refactoring and security audit reports
```

**Architecture Pattern:**
- **Static class-based:** All classes are `final` and use static `register_hooks()` and static methods.
- **No DI container:** Direct static hook registration in the main plugin file.
- **Autoloading:** SPL autoloader in `mds-release-manager.php` loads `/includes/class-mds-rm-*.php` files by converting class name `MDS_RM_ClassName` → `includes/class-mds-rm-classname.php`.
- **Namespace prefix:** All plugin classes use `MDS_RM_` prefix to avoid conflicts.
- **Separation of concerns:** Each class handles one domain (Post Types, REST API, Admin UI, Customizer, etc.).

---

## 3. WordPress Hooks & Lifecycle

### Post Types & Taxonomies
- **CPT: `release`**
  - Singular: "Release"
  - Archive: Enabled (`has_archive: true`)
  - REST: Enabled (`show_in_rest: true`)
  - Supports: title, editor, thumbnail, custom-fields
  - Slug: `release`
  - Menu icon: `dashicons-album`

- **CPT: `artist`**
  - Singular: "Artist"
  - Archive: Enabled
  - REST: Enabled
  - Supports: title, editor, thumbnail, custom-fields
  - Slug: `artist`
  - Menu icon: `dashicons-groups`
  - Menu placement: Under Releases

### Core Actions & Filters
- **`init`** → `MDS_RM_Post_Types::register_cpts()` — Registers release and artist CPTs.
- **`wp_enqueue_scripts`** → `MDS_RM_Assets::enqueue_frontend_assets()` — Loads frontend CSS and Font Awesome.
- **`template_redirect`** → `MDS_RM_Assets::handle_global_redirect()` — Redirects release pages if enabled.
- **`template_redirect`** → `MDS_RM_Artist_Routing::redirect()` — Redirects an Artist to its valid enabled homepage with HTTP 302, except in admin, previews, feeds, and REST requests. Invalid, missing, or self-referencing URLs leave the Artist page available and show a warning in Artist Settings.
- **`admin_enqueue_scripts`** → `MDS_RM_Assets::admin_assets()` — Loads admin JS/CSS.
- **`customize_register`** → `MDS_RM_Customizer::customize_register()` — Adds Customizer settings.
- **`enqueue_block_assets`** → `MDS_RM_Release_Meta::enqueue_assets()` — Loads metadata CSS and icon assets in the frontend and editor.
- **`rest_api_init`** → `MDS_RM_REST_API::register_rest_routes()` — Registers REST endpoints.
- **`add_meta_boxes`** → `MDS_RM_Admin_Interface::add_meta_boxes()` — Adds release/artist meta boxes.
- **`save_post_release`** → `MDS_RM_Admin_Interface::save_release_meta_data()` — Saves release meta.
- **`save_post_artist`** → `MDS_RM_Admin_Interface::save_artist_meta_data()` — Saves artist meta.
- **`admin_menu`** → `MDS_RM_Admin_Interface::register_settings_page()` — Registers Releases Settings page.
- **`network_admin_menu`** → `MDS_RM_Network_Admin::register_menu()` — Registers the network settings page.
- **`network_admin_edit_save_network_settings`** → `MDS_RM_Network_Admin::save_settings()` — Registered network settings save callback.
- **`enqueue_block_editor_assets`** → `MDS_RM_Gutenberg_Blocks::enqueue_block_editor_assets()` — Loads block editor JS.
- **`init`** → `MDS_RM_Gutenberg_Blocks::register_gutenberg_widget_blocks()` — Registers `mds-rm/release-meta`, `mds-rm/release-links`, and `mds-rm/artist-discography`.
- **`render_block`** → `MDS_RM_Gutenberg_Blocks::render_core_block_with_meta()` — Injects release meta into core blocks.
- **`the_content`** → `MDS_RM_Frontend_Templates::filter_release_content()` — Renders the named Release fallback partial only for singular Release main-loop content, unless Site-Wide Routing or a supported content builder is active. The default Meta component precedes the filtered post content; existing Meta/Links blocks and shortcodes (including synced patterns) suppress their matching defaults.
- **`the_content`** → `MDS_RM_Frontend_Templates::filter_artist_content()` — Uses the active theme and adds the Artist featured image, content, Discography and contact, in that order. Existing Discography blocks or shortcodes, including synced patterns, suppress the default. Main-loop, query and builder guards apply.
- **`post_thumbnail_html`** → `MDS_RM_Frontend_Templates::filter_release_featured_image()` — Suppresses the theme's duplicate main thumbnail only when the eligible Release or Artist fallback renders an image.
- **`render_block`** → `MDS_RM_Frontend_Templates::filter_release_author_group()` — Hides theme author blocks on singular Release and Artist pages, including the complete Twenty Twenty-Five byline pattern; other sibling blocks, post content, and builder layouts are excluded.
- **`wp_enqueue_scripts`** → `MDS_RM_Frontend_Templates::enqueue_release_fallback_assets()` — Loads fallback CSS before the header for eligible Release pages.
- **Builder detection** → `MDS_RM_Builder_Detection::detect_builder()` — Detects Elementor, Divi 4/5, Bricks, WPBakery, and Beaver Builder. The `mds_rm_detected_content_builder` filter permits additional detection.
- WooCommerce product hooks belong to the separate `mds-woocommerce-integration` plugin, not this plugin.

### Custom Hooks (Extensibility)
- **`do_action( 'mds_rm_register_custom_meta_boxes', $post )`** — Fire inside `add_meta_boxes`. Third-party plugins can register custom meta boxes here.
- **`do_action( 'mds_rm_save_custom_meta', $post_id, $_POST )`** — Fire after core meta is saved. Third-party plugins can save their custom meta here.
- **`apply_filters( 'mds_rm_settings_audio_fields', $html )`** — Filter audio preview section HTML on the Settings page.
- **`apply_filters( 'mds_rm_network_settings_fields', $html )`** — Filter network admin settings page HTML.
- **`apply_filters( 'mds_rm_sync_target_sites', $site_ids, $post_id, $post_type )`** — Supplies sites to the generic hub/spoke synchronizer. The WooCommerce extension supplies the existing `mds_rm_woo_sites` list.
- **`do_action( 'mds_rm_after_shadow_meta_sync', $shadow_id, $site_id, $source_id, $source_meta )`** — Lets extensions map their own metadata after a shadow record is copied.
- **`apply_filters( 'mds_rm_release_meta_context_upc', $upc, $context_post_id )`** — Resolves non-release contexts. The WooCommerce extension resolves product SKUs here.
- **`apply_filters( 'mds_rm_discography_columns', $columns, $artist )`** — Filters the ordered associative array of column key to plain-text heading for an Artist `WP_Post`.
- **`apply_filters( 'mds_rm_discography_cell', $html, $column_key, $release, $artist )`** — Filters each cell for the Release and Artist `WP_Post` objects. Return a string; output is sanitized with `wp_kses_post`.
- **`do_action( 'mds_rm_after_buy_now_field', $post )`** — Lets an integration add controls beside the existing Buy Now URL field.
- The Bandcamp extension registers `mds_rm_after_release_meta`; the Release fallback partial does not invoke that hook.

### Shortcodes
- **`[release-meta upc="012345678901"]`** — Renders metadata for a release; UPC/EAN is a string and leading zeroes are significant. Without `upc`, the current Release or supported extension context is used.
- **`[release-links upc="012345678901"]`** — Renders streaming links, Buy Now, and the configured audio preview for a release using the same UPC/context rules.
- **`[artist-discography artist_id="123"]`** — Renders an Artist's Discography. Omit `artist_id` to use the current Artist.
- `[release_links]` and the old links block/widget are not registered, and their legacy renderer and template have been removed. Stored streaming, purchase, audio and legacy styling data remain, but existing embeds must be replaced with the current shortcode or block.

### Artist Discography
- **`mds-rm/artist-discography`** uses the current Artist or an optional Artist post ID. Only published Releases associated through `artist_id` and the Artist's `_mds_artist_uuid` are listed, ordered by Release post date and ID descending, 25 per page. Release year is derived from the post date; UPC/EAN is displayed as text.
- Pagination uses `mds_discography_page_{artist_id}`. The table scrolls horizontally when necessary. Artist fallback Customizer table styles apply to the automatic table; independently placed blocks and shortcodes use their surrounding theme styles.

### Gutenberg Block
- Dynamic blocks are currently registered in `includes/class-mds-rm-gutenberg-blocks.php` with `register_block_type()` and use editor definitions in `js/blocks.js`. Migration to `block.json` is planned for Phase 5, not part of the metadata change.
- **`mds-rm/release-meta`** — Dynamic API v3 block with `postId` context, `upcOverride`, ordered `items`, and per-device `styles`. The Elementor widget `mds_release_meta` uses the same renderer. The block icon is `dashicons-album`.
- Available fields: `release_year`, `record_label`, `distributor`, `upc_ean`, `release_format`, `release_date`, `release_title`, `artist`, `release_tags`. Only the first four are enabled by default; empty values are omitted. Full date uses the site's date format; artist resolves the stored artist UUID.
- Each field can be reordered, hidden, and assigned a tooltip and Dashicons or Font Awesome classes. Shortcode `fields` is a comma-separated ordered selection; `icon_FIELD` and `title_FIELD` override each field's icon and tooltip, for example:

  `[release-meta upc="012345678901" fields="release_title,release_format,upc_ean" icon_release_title="dashicons dashicons-album" title_upc_ean="Barcode" color="#ffffff" gap="15px" gap_mobile="8px"]`

- Styles are per instance, not read from legacy widget options. Shortcode style attributes are `background`, `color`, `icon_color`, `font_family`, `font_size`, `font_weight`, `line_height`, `icon_size`, `icon_opacity`, `icon_gap`, `gap`, `align`, `vertical_align`, `direction`, `wrap`, `width`, `padding`, `margin`, `border_width`, `border_style`, `border_color`, and `radius`. Add `_tablet` or `_mobile` for responsive overrides; mobile is at most 767px and tablet at most 1024px, with inheritance from the next larger device. Gutenberg's Styles tab and Elementor's Style tab expose the same controls.
- Colors accept hex, `transparent`, or `inherit`; lengths accept zero or `px`, `em`, `rem`, `%`, `vw`, and `vh`; width also accepts `auto`; spacing accepts up to four lengths. Main-axis alignment accepts `flex-start`, `center`, `flex-end`, or `space-between`; cross-axis alignment accepts `flex-start`, `center`, `flex-end`, or `stretch`. Direction accepts `row`, `row-reverse`, `column`, or `column-reverse`; wrapping accepts `wrap`, `nowrap`, or `wrap-reverse`. Border style accepts `none`, `solid`, `dashed`, `dotted`, or `double`; opacity is 0–1. Invalid style/icon values are omitted and logged.
- The WooCommerce integration must be active on the hub to provide existing sync targets and on each store for product-context lookup and automatic product-page metadata. It honors the previously saved `mds_rm_woo_injection` placement/disabled value; an empty or nonnumeric SKU does not resolve a release. Product metadata and Release Links are not combined.

### Release Links Block
- **`mds-rm/release-links`** and the Elementor widget `mds_release_links` render independently of Release Meta, use the album Dashicon, and are placed manually, including in fallback release templates and product pages. Explicit UPC, current Release/Query Loop and extension-provided product SKU contexts use `MDS_RM_Release_Meta::resolve_post`.
- Provider URLs come from each release; configured provider names, icons and colors remain defaults in Releases → Settings. Available saved links and Buy Now appear as icons, and missing URLs are omitted. Per-instance overrides control visibility, order, icon classes and tooltip; providers not in the override list remain visible. Add a provider override by its exact saved name, or use `__buy_now` for Buy Now. Dashicons and Font Awesome classes are supported. Link titles also supply accessible names.
- Links open in a new tab by default; `new_tab="0"` uses the same tab. `show_audio="0"` hides audio. When enabled in settings, a Bandcamp shortcode takes priority over HTML5 audio; if its shortcode is unavailable, the error is logged and HTML5 is used if enabled. Legacy `native` and current `html5` player identifiers are supported.
- Shortcode `order` and `hide` are comma-separated provider names; `icon_PROVIDER` and `title_PROVIDER` override individual values, with lowercase provider names and spaces replaced by underscores (for example `icon_apple_music`). Use `icon_buy_now` and `title_buy_now` for Buy Now:

  `[release-links upc="012345678901" order="Apple Music,Spotify,__buy_now" hide="Spotify" icon_apple_music="dashicons dashicons-album" title_apple_music="Listen on Apple Music" show_audio="0" new_tab="0" button_size="48px" button_size_mobile="36px"]`

- Styles are per instance; stored legacy widget options are not applied. The shortcode style attributes are `background`, `gap`, `align`, `vertical_align`, `direction`, `wrap`, `width`, `padding`, `margin`, `border_width`, `border_style`, `border_color`, `radius`, `link_gap`, `button_size`, `button_radius`, `button_color`, `button_background`, `button_border_width`, `button_border_style`, `button_border_color`, `icon_size`, `hover_opacity`, and `audio_width`. Add `_tablet` or `_mobile` for responsive overrides; breakpoints, inheritance, and valid values match the Release Meta styles. A button background override supersedes the configured provider colors.

---

## 4. Data Storage & APIs

### Release Model
- A release represents one UPC/EAN and one release format. Different releases may share a title, but each must have its own UPC/EAN.
- Use the dedicated `release_format` taxonomy as the authoritative format, with exactly one of Single, EP, Album, or Digital per release. Do not use the built-in post category taxonomy.
- The four root terms are created automatically and cannot be renamed or deleted through the WordPress editor or REST API; unsupported or nested new format terms are rejected.
- Group the required Release Format and UPC/EAN fields together. Require both for saved drafts and published releases; WordPress's initial empty auto-draft is exempt.
- Store UPC/EAN as a string, preserving leading zeroes, and reject duplicate UPCs. Allow corrections to existing UPCs without creating duplicate spoke releases.
- The classic editor, block editor, CSV import, and REST release writes validate these fields. The block editor forwards the required meta-box fields in the REST save; quick and bulk editing cannot assign formats.
- Use a per-site MySQL advisory lock while checking and saving a UPC through these paths. Direct programmatic WordPress writes must perform equivalent validation and locking.
- Format displays and CSV exports read the taxonomy. The release list reports missing or duplicate UPCs and missing, multiple, unsupported, or conflicting legacy formats for review; conflicting values require an explicit choice.
- Spoke synchronization uses a stable hub-site/post key and can adopt legacy shadows by current or previous UPC. Synchronization errors are reported rather than silently overwriting conflicting records.

### Post Meta Keys (Release Posts)
- `upc_ean` (string) — Universal Product Code or EAN for the release.
- `artist_id` (string) — Artist post ID or UUID.
- `streaming_links` (JSON string) — Encoded array of streaming platform URLs: `{"Spotify": "url", "Apple Music": "url", ...}`.
- `buy_now_link` (string) — URL for purchase/buy now button.
- `bandcamp_shortcode` (string) — Bandcamp embed shortcode template.
- `audio_preview_url` (string) — URL to preview audio file.
- `release_format` (legacy string) — Obsolete; the taxonomy is authoritative. Conflicts require review, and successful saves remove this legacy value.
- `_mds_previous_upcs` (JSON string) — Former UPCs used to identify legacy spoke releases after a correction.
- `_mds_hub_release_key` (string, spoke posts) — Stable hub site ID and release post ID used for synchronization.
- `record_label` (string) — Record label name.
- `distributor` (string) — Distributor name.
- `release_tags` (string) — Comma-separated tags.
- `featured_image_url` (string) — Featured image URL (for virtual media sync).

### Post Meta Keys (Artist Posts)
- `_mds_artist_uuid` (string) — Unique artist identifier.
- `artist_contact` (string) — Contact information.
- `artist_homepage_url` (string) — Optional HTTP(S) homepage.
- `artist_homepage_routing` (string) — Per-Artist redirect toggle (`0` or `1`).
- The deprecated `artist_featured_image` URL is no longer used or synchronized. On each site's administrator visits, up to 100 Artist records are cleaned per request until the per-site completion marker is set; sites not visited or with outstanding batches remain pending. The WordPress featured image is used instead.

### Post Meta Keys (Attachments)
- `_mds_is_hub_media` (string) — Hub site media URL (for virtual media fallback).
- `_wp_attached_file` (string) — Attached file path.

### wp_options Keys (Site-Level)
- `mds_rm_fa_support` — Font Awesome source: `cdn`, `plugin`, `theme`, or `none` (`internal` is supported for legacy configurations).
- Legacy widget options remain stored for review but do not style the Release Meta Block:
- `mds_rm_widget_bg_color` — Widget background color.
- `mds_rm_shortcode_gap` — Gap between shortcode elements.
- `mds_rm_shortcode_align` — Shortcode alignment.
- `mds_rm_links_icon_bg` — Streaming links icon background.
- `mds_rm_links_color` — Streaming links text color.
- `mds_rm_links_bg` — Streaming links background.
- `mds_rm_links_radius` — Streaming links border radius.
- `mds_rm_links_gap` — Gap between streaming link icons.
- `mds_rm_links_padding` — Streaming links padding.
- `mds_rm_links_align` — Streaming links alignment (flex-start, center, flex-end).
- `mds_rm_buy_now_icon` — Buy Now button icon (Font Awesome class).
- `mds_rm_buy_now_color` — Buy Now button text color.
- `mds_rm_buy_now_bg` — Buy Now button background.
- `mds_rm_meta_color` — Legacy meta bar text color.
- `mds_rm_meta_bg` — Meta bar background.
- `mds_rm_meta_radius` — Meta bar border radius.
- `mds_rm_meta_gap` — Meta bar gap.
- `mds_rm_meta_padding` — Meta bar padding.
- `mds_rm_meta_align` — Meta bar alignment.
- `mds_rm_woo_meta_bg` — WooCommerce product meta background.
- `mds_rm_woo_meta_color` — WooCommerce product meta text color.
- `mds_rm_woo_meta_gap` — WooCommerce product meta gap.
- `mds_rm_woo_meta_align` — WooCommerce product meta alignment.
- `mds_rm_audio_players` (array) — Active audio player types: 'html5', 'bandcamp', etc.
- `mds_rm_bandcamp_template` — Bandcamp embed shortcode template.
- `mds_rm_providers` (array) — Global provider configuration (name, icon, color).
- `mds_rm_global_redirect` — Site-Wide Routing toggle ('0' or '1'). Releases with a Buy Now URL redirect; when enabled, automatic Release fallback composition is suppressed for every Release.
- `mds_rm_artist_image_cleanup_complete` — Per-site marker for the deprecated Artist image URL cleanup.
- `mds_rm_db_templates` (array) — Database-backed template overrides: `{"css/frontend.css": "...", ...}`.

### wp_site_options Keys (Multisite Network-Level)
- `mds_rm_master_blog_id` — Hub site ID for multisite network.
- `spoke_sites` (array) — Array of spoke site IDs.
- `mds_rm_woo_sites` (array) — Existing sync targets and automatic-injection eligibility, read by the WooCommerce integration.
- `mds_rm_wipe_on_uninstall` — Wipe all data on uninstall ('0' or '1').

### Customizer Settings
- `mds_rm_primary_color` — Primary accent color (default: #2271b1).
- `mds_rm_layout_style` — Release Fallback Template layout: 'split' or 'stacked' (retained for compatibility).
- `mds_rm_release_fallback_*` — Release fallback width, layout gaps, image radius, content typography, Meta padding/gap/colors, and Links gaps/button/icon sizing and colors.
- `mds_rm_artist_fallback_*` — Artist fallback width, image size, spacing, contact styling, and Discography table typography and colors.
- Legacy widget Customizer values remain stored but are no longer exposed. The WooCommerce extension continues to honor the stored `mds_rm_woo_injection` placement.

### REST API Endpoints

**GET `/wp-json/mds/v1/releases`**
- **Permission:** Public (`__return_true`).
- **Query params:** None. Returns all published releases on the current site.
- **Response:** Array of releases with `id`, `title`, and `upc`.

**POST `/wp-json/mds/v1/settings`**
- **Permission:** `manage_options`.
- **Body params:** Provider config, audio players, feature toggles.
- **Response:** Success/error JSON.

**POST `/wp-json/mds/v1/network-settings`**
- **Permission:** `manage_network_options`.
- **Body params:** Master hub ID, spoke sites, wipe-on-uninstall flag.
- **Response:** Success/error JSON.

**POST `/wp-json/mds/v1/sync-network`**
- **Permission:** `manage_network_options`.
- **Body params:** None.
- **Response:** Success/error JSON with sync counts.

The Release and Artist post types are also exposed through the WordPress core REST API (`/wp-json/wp/v2/release`, `/wp-json/wp/v2/artist`) via `show_in_rest`.

### WordPress Abilities API (WordPress 6.9+)

The plugin exposes core features as native MCP tools using the WordPress Abilities API, automatically discoverable by the official WordPress MCP Adapter.

**Ability Category:** `mds_release_manager`
- **Label:** "MDS Release Manager"
- **Description:** "Manage artist releases, formats, and distribution settings"
- **Registered on:** `wp_abilities_api_categories_init` hook

**Registered Abilities:**

1. **`mds_rm_get_releases`**
   - **Label:** "Get Releases"
   - **Permission:** `read` capability
   - **Input:** Optional pagination (`page`, `per_page`)
   - **Output:** Array of releases with ID, title, UPC, and format
   - **MCP Public:** ✓ (`meta.mcp.public = true`)

2. **`mds_rm_save_settings`**
   - **Label:** "Save Plugin Settings"
   - **Permission:** `manage_options` capability
   - **Input:** Providers array (name/icon/color), audio players, feature toggles (global_redirect, fa_support)
   - **Output:** Success/error response with message
   - **MCP Public:** ✓

3. **`mds_rm_save_network_settings`**
   - **Label:** "Save Network Settings"
   - **Permission:** `manage_network_options` capability
   - **Input:** Master blog ID, wipe-on-uninstall flag
   - **Output:** Success/error response with message
   - **MCP Public:** ✓

4. **`mds_rm_sync_network`**
   - **Label:** "Sync Network Data"
   - **Permission:** `manage_network_options` capability
   - **Input:** No parameters required
   - **Output:** Success/error, sync counts (artists_synced, releases_synced), and any error details
   - **MCP Public:** ✓

**Implementation:**
- Class: `MDS_RM_Abilities` in `/includes/class-mds-rm-abilities.php`
- Registered via `MDS_RM_Abilities::register_hooks()` in main plugin file
- Includes graceful fallback: `if ( ! function_exists( 'wp_register_ability' ) ) return;`
- Complete JSON Schema definitions for input/output with type, description, constraints
- Strict capability checks via dedicated permission callback functions
- All input sanitized consistently with REST API standards

---

## 5. Coding Standards & Security Conventions

### Naming Conventions
- **Classes:** `MDS_RM_` prefix, PascalCase. Example: `MDS_RM_Post_Types`.
- **Functions:** Lowercase with underscores and an `mds_rm_` prefix for global functions. Class methods omit the redundant prefix as they are migrated, e.g. `MDS_RM_Assets::enqueue_frontend_assets()`.
- **Constants:** `MDS_RELEASE_MANAGER_VERSION`, `MDS_RM_PATH`, `MDS_RM_URL`, `MDS_RM_MAIN_FILE`.
- **Hooks:** `mds_rm_` prefix. Example: `do_action( 'mds_rm_save_custom_meta' )`.
- **Options:** `mds_rm_` prefix. Example: `get_option( 'mds_rm_links_color' )`.

### Input Sanitization
- **Text fields:** `wp_unslash()` → `sanitize_text_field()`.
- **URLs:** `wp_unslash()` → `esc_url_raw()`.
- **Numbers:** `absint()` or `intval()`.
- **JSON:** `json_decode()` with second arg `true` for arrays.
- **Always unslash `$_POST` before passing to sanitizers.**

**Example:**
```php
$value = sanitize_text_field( wp_unslash( $_POST['field_name'] ?? '' ) );
$url   = esc_url_raw( wp_unslash( $_POST['url_field'] ?? '' ) );
```

### Output Escaping
- **HTML attributes:** `esc_attr()`.
- **HTML text:** `esc_html()`.
- **Full HTML (trusted markup):** `wp_kses_post()` or `wp_kses()` with allowed tags.
- **JS inline:** `wp_json_encode()`.

**Example:**
```php
echo '<div class="' . esc_attr( $class ) . '">' . esc_html( $title ) . '</div>';
echo wp_kses_post( $trusted_html );
```

### Nonce Verification
- **Admin forms:** `wp_verify_nonce( $_POST['_wpnonce'], 'action_name' )` before processing.
- **Nonce field generation:** `wp_nonce_field( 'action_name', '_wpnonce' )`.
- **REST API:** Use permission callback instead of nonce (nonces are for traditional form submissions).

**Example:**
```php
if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( wp_unslash( $_POST['_wpnonce'] ), 'mds_rm_save_network_settings' ) ) {
    wp_die( 'Security check failed' );
}
```

### Type Declaration & Strict Comparison
- **All new files must include `declare(strict_types=1);` immediately after the opening `<?php` tag**, before the file DocBlock. Placing anything (including comments or a UTF-8 byte-order mark) before the declaration triggers a fatal `strict_types declaration must be the very first statement` error.
- **Save files without a UTF-8 BOM.**
- **Use strict comparison:** `===`, `!==`, not `==`, `!=`.
- **Type hints on function parameters and return types** where applicable. Coverage is being applied progressively; the admin interface, network admin, release model, discography, and artist routing classes are fully typed — match that standard in new and refactored code.

**Example:**
```php
<?php
declare(strict_types=1);

/**
 * File DocBlock.
 *
 * @package MDS_Release_Manager
 */

public static function mds_rm_get_id( int $post_id ): string {
    return (string) $post_id;
}
```

### Code Style
- **WordPress coding style:** Tab indentation (existing code uses WordPress-style tabs — follow nearby code), single blank lines between methods.
- **DocBlocks:** Use standard PHPDoc blocks with `@since`, `@param`, `@return` tags.
- **Comments:** Only for non-obvious logic; avoid reiterating what code does.
- **Final classes:** All classes in this plugin are `final` to prevent accidental inheritance.

**Example:**
```php
/**
 * Renders the release widget.
 *
 * @since 0.9.0
 * @param array    $attributes Block attributes.
 * @param WP_Block $block      The parsed block.
 * @return string              The rendered widget.
 */
public static function mds_rm_render_widget( array $attributes, WP_Block $block ): string {
    // ...
}
```

### Testing & Validation Commands
- **No automated test suite configured.** Manual testing recommended.
- **No PHPCS configured.** Adhere to the conventions above manually.
- **Lint PHP syntax before committing:** `php -l includes/class-mds-rm-<name>.php` (or loop over all files in `includes/`).
- **No build commands required.**

---

## 6. Rules for Copilot

### DO:
1. **Always use `MDS_RM_` prefix** for new functions, constants, and hooks.
2. **Maintain static class structure** — all classes are `final` with static methods; no instance creation.
3. **Sanitize and escape rigorously** — `wp_unslash()` + `sanitize_*()` for input, `esc_*()` for output, `wp_kses_post()` for trusted HTML.
4. **Use strict type declarations** — `declare(strict_types=1);` in every new file, with type hints on function parameters and return values.
5. **Register hooks at plugin load time** via static `register_hooks()` method in each class. Call it once from `mds-release-manager.php`.
6. **Respect multisite separation** — use `get_site_option()` for network-wide settings, `get_option()` for site-local settings. Use `switch_to_blog()` / `restore_current_blog()` when accessing data on other sites.
7. **Use `MDS_RM_Logger::log()` for debugging.** It fires `mds_rm_log_message` when `WP_DEBUG` and `WP_DEBUG_LOG` are both `true`. The old core method delegates to it for compatibility.
8. **Follow the autoloader pattern** — class files must be named `includes/class-mds-rm-*.php` to auto-load.
9. **Cache-aware:** Call `MDS_RM_Sync::purge_cache()` when release data changes. It requests a purge only when LiteSpeed is available.
10. **Provide extension hooks** — when adding new features, include `do_action()` or `apply_filters()` with `mds_rm_` prefix to allow third-party plugins to extend the functionality.

### DON'T:
1. **Never break static class patterns** — do not create instances or use inheritance.
2. **Never hardcode prefix-less function names** — all custom functions must use `mds_rm_` prefix.
3. **Never use deprecated WordPress functions** — check the WordPress Codex before using old APIs.
4. **Never use global variables** except for `$wpdb`, `$post`, and standard WordPress globals.
5. **Never commit secrets or credentials** into the codebase.
6. **Never bypass sanitization/escaping** for "trusted" data; apply it consistently.
7. **Never directly modify `$_POST`, $_GET, or $_FILES` — always unslash and sanitize into a local variable first.
8. **Never use `eval()`, `create_function()`, or dynamic code execution.**
9. **Never assume multisite topology** — always check `is_multisite()` and use appropriate option getters.
10. **Never generate nonce fields in REST endpoints** — use capability checks in permission callback instead.

### Common Patterns

**Saving Release Meta:**
```php
$upc = sanitize_text_field( wp_unslash( $_POST['upc_ean'] ?? '' ) );
$upc_clean = preg_replace( '/[^0-9]/', '', $upc );
update_post_meta( $post_id, 'upc_ean', $upc_clean );

do_action( 'mds_rm_save_custom_meta', $post_id, $_POST );
```

**Rendering Shortcode with Escaping:**
```php
$links_array = json_decode( get_post_meta( $post_id, 'streaming_links', true ), true );
if ( ! is_array( $links_array ) ) {
    $links_array = array();
}
// Process and render...
echo wp_kses_post( MDS_RM_Template_Loader::kses_shortcode_html( $output ) );
```

**Registering a Customizer Setting:**
```php
$wp_customize->add_setting( 'mds_rm_my_setting', array( 'default' => 'default_value' ) );
$wp_customize->add_control( 'mds_rm_my_setting', array(
    'label'   => 'My Setting',
    'section' => 'mds_rm_my_section',
    'type'    => 'text',
) );
```

**Multisite Option Check:**
```php
if ( is_multisite() && get_current_blog_id() !== (int) get_site_option( 'mds_rm_master_blog_id', get_main_site_id() ) ) {
    // Current site is not the hub; skip feature or fetch from hub.
}
```

---

## 7. Architecture Philosophy

**Version 0.9.0+ Philosophy:**  
MDS Release Manager transitioned from a monolithic plugin to an **extensible ecosystem**. Third-party integrations are no longer hardcoded; instead, they hook into documented `do_action()` and `apply_filters()` points with the `mds_rm_` prefix. Feature plugins must verify MDS Release Manager is active before hooking.

**Multisite Strategy:**  
One designated **Hub site** stores authoritative release data. **Spoke sites** may reference or display data via virtual media attachments or cross-site REST API calls. The network admin configures the hub and spoke topology via the network settings page.

---

## Summary

- **Namespace/Prefix:** `MDS_RM_`
- **Class Pattern:** Static, final classes with `register_hooks()` and static methods.
- **Autoload:** SPL-based, file-to-class mapping via `includes/class-mds-rm-*.php`.
- **Security:** Sanitize input (`wp_unslash()` + `sanitize_*()`, `esc_*()` output, nonce checks on traditional forms.
- **Hooks:** Extensive custom action/filter hooks for third-party extensibility.
- **Multisite:** Hub-and-spoke architecture with network options management.
- **No External Dependencies:** Vanilla PHP + WordPress APIs only.
