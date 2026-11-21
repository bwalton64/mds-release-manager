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
- Assets: Vanilla JS. Block editor scripts live in `/blocks/`; `/js/meta-boxes.js`, `/js/release-taxonomies.js`, and `/js/settings.js` are for admin screens (using the jQuery bundled with WordPress).
- Styles: Vanilla CSS in `/css/`. Admin view layout is bundled in `admin-styles.css`; `admin-defaults.css` is the fallback for the existing stored `css/admin-styles.css` override key.
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
│   ├── class-mds-rm-plugin.php
│   ├── admin/
│   │   ├── class-mds-rm-admin-interface.php
│   │   ├── class-mds-rm-csv-handler.php
│   │   ├── class-mds-rm-editor.php   # Classic edit-screen setup and spoke read-only rules
│   │   ├── class-mds-rm-network-admin.php
│   │   └── views/                    # Bundled admin views, never resolved through the theme
│   ├── core/
│   │   ├── class-mds-rm-builder-detection.php
│   │   ├── class-mds-rm-logger.php
│   │   ├── class-mds-rm-post-types.php
│   │   ├── class-mds-rm-providers.php
│   │   ├── class-mds-rm-release-model.php  # Release format/UPC validation and sync model
│   │   ├── class-mds-rm-sync.php
│   │   └── class-mds-rm-template-loader.php
│   ├── frontend/
│   │   ├── class-mds-rm-artist-routing.php
│   │   ├── class-mds-rm-assets.php
│   │   ├── class-mds-rm-customizer.php
│   │   ├── class-mds-rm-discography.php
│   │   ├── class-mds-rm-frontend-templates.php
│   │   └── class-mds-rm-shortcodes.php
│   ├── blocks/
│   │   ├── class-mds-rm-gutenberg-blocks.php
│   │   ├── class-mds-rm-release-meta.php
│   │   └── class-mds-rm-release-links.php
│   ├── api/
│   │   ├── class-mds-rm-abilities.php
│   │   ├── class-mds-rm-rest-api.php
│   │   └── class-mds-rm-virtual-media.php
│   └── integrations/
│       └── elementor/
│           ├── class-mds-rm-elementor-tags.php
│           ├── class-mds-rm-elementor-widget.php
│           ├── class-mds-rm-elementor-links-widget.php
│           └── class-mds-rm-elementor-release-meta-tag.php
├── templates/                        # Theme-overridable frontend fallbacks
│   ├── artist-fallback.php
│   └── release-fallback.php
├── blocks/                           # Gutenberg metadata and editor assets
│   ├── artist-discography/           # block.json and edit.js
│   ├── release-links/                # block.json and edit.js
│   ├── release-meta/                 # block.json and edit.js
│   ├── core-block-extensions.js       # Metadata bindings for core blocks
│   └── style-control.js              # Shared Release block inspector controls
├── js/                               # Admin scripts
│   ├── meta-boxes.js                 # Meta box interactivity and shortcode copy
│   ├── release-taxonomies.js         # Native tag editor integration
│   └── settings.js                   # Settings page tabs
├── css/                              # Frontend and admin styles
│   ├── admin-defaults.css
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
  - Supports: title, editor, thumbnail, excerpt
  - Slug: `release`
  - Menu icon: `dashicons-album`

- **CPT: `artist`**
  - Singular: "Artist"
  - Archive: Enabled
  - REST: Enabled
  - Supports: title, editor, thumbnail, excerpt
  - Slug: `artist`
  - Menu icon: `dashicons-groups`
  - Menu placement: Under Releases

Both CPTs use the classic edit screen: `MDS_RM_Editor` opts them out of the
block editor so the reorganized meta boxes, extension meta boxes, and SEO
plugin meta boxes host natively on `post.php`. On spoke sites the CPTs register
with `create_posts => do_not_allow`, and `MDS_RM_Editor` denies the
`edit_post`/`delete_post`/`publish_post` meta capabilities, making the catalog
read-only outside the hub.

### Core Actions & Filters
- **`init`** → `MDS_RM_Post_Types::register_cpts()` — Registers release and artist CPTs.
- **`wp_enqueue_scripts`** → `MDS_RM_Assets::enqueue_frontend_assets()` — Loads frontend CSS and Font Awesome.
- **`template_redirect`** → `MDS_RM_Assets::handle_global_redirect()` — Redirects release pages if enabled.
- **`template_redirect`** → `MDS_RM_Artist_Routing::redirect()` — Redirects an Artist to its valid enabled homepage with HTTP 302, except in admin, previews, feeds, and REST requests. Invalid, missing, or self-referencing URLs leave the Artist page available and show a warning in Artist Settings.
- **`admin_enqueue_scripts`** → `MDS_RM_Assets::admin_assets()` — Loads admin JS/CSS.
- **`customize_register`** → `MDS_RM_Customizer::customize_register()` — Adds Customizer settings.
- **`enqueue_block_assets`** → `MDS_RM_Release_Meta::enqueue_assets()` — Loads metadata CSS and icon assets in the frontend and editor.
- **`rest_api_init`** → `MDS_RM_REST_API::register_rest_routes()` — Registers REST endpoints.
- **`add_meta_boxes`** → `MDS_RM_Admin_Interface::add_meta_boxes()` — Adds the Release Identity, Release Details, Streaming & Audio, and Commerce boxes for releases and the Artist Profile box for artists.
- **`use_block_editor_for_post_type`** → `MDS_RM_Editor::disable_block_editor()` — Keeps Releases and Artists on the classic edit screen.
- **`map_meta_cap`** → `MDS_RM_Editor::deny_editing_on_spokes()` — Denies catalog editing capabilities on spoke sites.
- **`admin_notices`** → `MDS_RM_Editor::render_spoke_notice()` — Explains the read-only catalog on spoke list screens with a link to the hub.
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
- **`apply_filters( 'mds_rm_site_settings_tabs', $tabs )`** and **`apply_filters( 'mds_rm_network_settings_tabs', $tabs )`** — Register keyed extension tabs with `label` and callable `render`. The owning extension renders and saves its own form; site and network capabilities remain separate. Existing section filters remain supported.
- **`apply_filters( 'mds_rm_sync_target_sites', $site_ids, $post_id, $post_type )`** — Supplies sites to the generic hub/spoke synchronizer. The WooCommerce extension supplies the existing `mds_rm_woo_sites` list.
- **`do_action( 'mds_rm_after_shadow_meta_sync', $shadow_id, $site_id, $source_id, $source_meta )`** — Lets extensions map their own metadata after a shadow record is copied.
- **`apply_filters( 'mds_rm_release_meta_context_upc', $upc, $context_post_id )`** — Resolves non-release contexts. The WooCommerce extension resolves product SKUs here.
- **`apply_filters( 'mds_rm_discography_columns', $columns, $artist )`** — Filters the ordered associative array of column key to plain-text heading for an Artist `WP_Post`.
- **`apply_filters( 'mds_rm_discography_cell', $html, $column_key, $release, $artist )`** — Filters each cell for the Release and Artist `WP_Post` objects. Return a string; output is sanitized with `wp_kses_post`.
- **`do_action( 'mds_rm_after_buy_now_field', $post )`** — Lets an integration add controls beside the existing Buy Now URL field.
- The Bandcamp extension registers `mds_rm_after_release_meta`; the Release fallback partial does not invoke that hook.

### Shortcodes
- **`[release-meta release_id="123" upc="012345678901"]`** — Renders metadata for a Release. A readable Release ID takes precedence over UPC/EAN; an unresolved ID falls through to UPC, then current Release or supported extension context. UPC/EAN is a string and leading zeroes are significant.
- **`[release-links release_id="123" upc="012345678901"]`** — Renders streaming links, Buy Now, and the configured audio preview using the same Release ID/UPC/context precedence.
- **`[artist-discography artist_id="123"]`** — Renders an Artist's Discography. Omit `artist_id` to use the current Artist. Flat shortcode attributes expose table and outer-wrapper styles; see the shortcode reference in `README.md`.
- `[release_links]` and the old links block/widget are not registered, and their legacy renderer and template have been removed. Stored streaming, purchase, audio and legacy styling data remain, but existing embeds must be replaced with the current shortcode or block.

### Artist Discography
- **`mds-rm/artist-discography`** uses the current Artist or an optional Artist post ID. Only published Releases associated through `artist_id` and the Artist's `_mds_artist_uuid` are listed, ordered by Release post date and ID descending, 25 per page. Release year is derived from the post date; UPC/EAN is displayed as text.
- Pagination uses `mds_discography_page_{artist_id}`. The table scrolls horizontally when necessary. The automatic table and inserted blocks inherit the site-wide block defaults; shortcodes use their own attributes.
- The editor queries the site's existing `/wp/v2/artist` endpoint for published Artists and resolves a saved selection independently of search results. The stored integer `artistId` remains unchanged; zero or absent resolves block context. The editor sends the context ID to the block renderer for preview only.
- Core block supports own wrapper styles. An optional `tableStyles` attribute stores per-region overrides (including references to theme presets). Both block and shortcode renderers validate table settings and emit scoped CSS variables; shortcode `table_*`, `header_*`, `body_*`, `cell_*`, and `link_*` attributes map underscores to the corresponding `tableStyles` keys. `striped="1"` enables alternate rows. `wrapper_*` attributes mirror the core wrapper's color, typography, spacing, and border controls. Table styles are not responsive; outer styles are inline on the shortcode wrapper.

### Gutenberg Block
- Release Meta, Release Links, and Artist Discography register from `blocks/{name}/block.json` with their existing PHP renderers. The editor filters for metadata bindings on core blocks live separately in `blocks/core-block-extensions.js`.
- **`mds-rm/release-meta`** — Dynamic API v3 block with `postId` context, `upcOverride`, ordered `items`, and per-device `styles`. The Elementor widget `mds_release_meta` uses the same renderer. The block icon is `dashicons-album`.
- Release Meta and Release Links share published Release lookups and block context resolution. New selections store `releaseId`; legacy `upcOverride` remains readable. Artist context selects the latest published Release by Artist UUID, date, and ID. Context fallback does not rewrite a missing explicit selection.
- Release Meta's native controls write `metaStyles` for layout, typography, and icons. New values replace corresponding legacy desktop values for the block only; legacy tablet/mobile rules still apply until reset. Core viewport previews do not edit those stored overrides.
- Available fields: `release_year`, `record_label`, `distributor`, `upc_ean`, `release_format`, `release_date`, `release_title`, `artist`, `release_tags`. Only the first four are enabled by default; empty values are omitted. Full date uses the site's date format; artist resolves the stored artist UUID.
- Each field can be reordered, hidden, and assigned a tooltip and Dashicons or Font Awesome classes. Shortcode `fields` is a comma-separated ordered selection; `icon_FIELD` and `title_FIELD` override each field's icon and tooltip, for example:

  `[release-meta upc="012345678901" fields="release_title,release_format,upc_ean" icon_release_title="dashicons dashicons-album" title_upc_ean="Barcode" color="#ffffff" gap="15px" gap_mobile="8px"]`

- Styles are per instance, not read from legacy widget options. Shortcode style attributes also include `font_style`, `text_align`, and `letter_spacing`; all component-style values can use `_tablet` and `_mobile` suffixes. Mobile is at most 767px and tablet at most 1024px, with inheritance from the next larger device. Elementor retains its existing device controls.
- Colors accept hex, `rgb()`/`rgba()`, `transparent`, `inherit`, or `var(--wp--preset--color--{slug})`; font-family and font-size accept their corresponding WordPress preset variables. Lengths accept zero or `px`, `em`, `rem`, `%`, `vw`, and `vh`; width also accepts `auto`; spacing accepts up to four lengths. Main-axis alignment accepts `flex-start`, `center`, `flex-end`, or `space-between`; cross-axis alignment accepts `flex-start`, `center`, `flex-end`, or `stretch`. Direction accepts `row`, `row-reverse`, `column`, or `column-reverse`; wrapping accepts `wrap`, `nowrap`, or `wrap-reverse`. Border style accepts `none`, `solid`, `dashed`, `dotted`, or `double`; font style is `normal` or `italic`, text alignment is `start`, `center`, or `end`, and opacity is 0–1. Invalid style/icon values are omitted and logged.
- The WooCommerce integration must be active on the hub to provide existing sync targets and on each store for product-context lookup and automatic product-page metadata. It honors the previously saved `mds_rm_woo_injection` placement/disabled value; an empty or nonnumeric SKU does not resolve a release. Product metadata and Release Links are not combined.

### Release Links Block
- **`mds-rm/release-links`** and the Elementor widget `mds_release_links` render independently of Release Meta, use the album Dashicon, and are placed manually, including in fallback release templates and product pages. Explicit UPC, current Release/Query Loop and extension-provided product SKU contexts use `MDS_RM_Release_Meta::resolve_post`.
- The block stores a local `releaseId` for new choices and still reads legacy `upcOverride` strings. A missing explicit source falls back without rewriting the attributes. Artist context resolves the latest published Release by Artist UUID, post date, then post ID. The editor uses authenticated lookups for published title/UPC choices and contextual source status. The shortcode accepts `release_id` as well as `upc` and current context.
- Native editor controls store optional `linkStyles`. These values replace matching legacy desktop styles at render time without modifying stored data. Saved tablet and mobile styles keep their existing precedence until explicitly removed. Plugin controls target the same inner layout and button elements as before, rather than applying a second layer of spacing or borders to the block wrapper.
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
- Maintain `_mds_normalized_upc` as a dedicated exact-match lookup key; existing releases are backfilled in administrator-request batches of 100. During migration, validation retains a bounded legacy fallback for unnormalized values.
- The classic editor, CSV import, and REST release writes validate these fields. Releases and Artists use the classic edit screen on the hub; quick and bulk editing cannot assign formats, and spoke sites cannot edit catalog posts at all.
- Use a per-site MySQL advisory lock while checking and saving a UPC through these paths. Direct programmatic WordPress writes must perform equivalent validation and locking.
- Format displays and CSV exports read the taxonomy. The release-list audit caches findings and invalidates them when relevant release metadata or terms change.
- CSV export validates and writes 100-release pages to temporary storage before streaming the download, keeping release and Artist lookups bounded while preserving the CSV schema.
- Spoke synchronization uses a stable hub-site/post key and can adopt legacy shadows by current or previous UPC in one combined lookup. Synchronization errors are reported rather than silently overwriting conflicting records.

### Post Meta Keys (Release Posts)
- `upc_ean` (string) — Universal Product Code or EAN for the release.
- `_mds_normalized_upc` (string) — Digit-only exact-match lookup key maintained from `upc_ean`.
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
- `mds_rm_fa_support` — Font Awesome source: `cdn` and legacy `internal` load bundled Font Awesome Free 6.4.0; `plugin`, `theme`, and `none` do not enqueue the bundled library. Assets and upstream license are in `assets/fontawesome/`, from the official `@fortawesome/fontawesome-free@6.4.0` npm package.
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
- `mds_rm_block_defaults` (array) — Site-local block presentation defaults keyed by block slug. Native style values, table styles, field/link lists, and link/audio switches are validated before storage. Block rendering, including automatic fallback blocks, resolves these against saved attributes; legacy lists remain complete overrides and explicit field/style overrides take precedence. Shortcodes and Elementor do not consume these defaults.
- `mds_rm_global_redirect` — Site-Wide Routing toggle ('0' or '1'). Releases with a Buy Now URL redirect; when enabled, automatic Release fallback composition is suppressed for every Release.
- `mds_rm_artist_image_cleanup_complete` — Per-site marker for the deprecated Artist image URL cleanup.
- `mds_rm_normalized_upc_migration_complete` — Per-site marker set after all existing Release UPC lookup keys have been backfilled in bounded batches.
- `mds_rm_release_audit` (transient) — Cached IDs flagged by the Release list audit; invalidated when audited metadata or format terms change.
- `mds_rm_network_sync_job` — Per-hub resumable manual-sync cursor, cumulative counts, and capped error details.
- `mds_rm_db_templates` (array) — Database-backed template overrides: `{"css/frontend.css": "...", ...}`.

### wp_site_options Keys (Multisite Network-Level)
- `mds_rm_master_blog_id` — Hub site ID for multisite network.
- `spoke_sites` (array) — Array of spoke site IDs.
- `mds_rm_woo_sites` (array) — Existing sync targets and automatic-injection eligibility, read by the WooCommerce integration.
- `mds_rm_wipe_on_uninstall` — Opt in to deleting network settings (including the stored spoke selection), plugin site options across the current network, and Release and Artist posts including Trash ('0' or '1').

### Customizer Settings
- `mds_rm_primary_color` — Primary accent color (default: #2271b1).
- `mds_rm_layout_style` — Release Fallback Template layout: 'split' or 'stacked' (retained for compatibility).
- `mds_rm_release_fallback_*` — Release fallback width, layout gaps, featured image and content typography.
- `mds_rm_artist_fallback_*` — Artist fallback width, spacing, portrait, biography and contact styling.
- Legacy widget Customizer values remain stored but are no longer exposed. The WooCommerce extension continues to honor the stored `mds_rm_woo_injection` placement.

### REST API Endpoints

**GET `/wp-json/mds/v1/releases`**
- **Permission:** Public (`__return_true`).
- **Query params:** `page` (default `1`) and `per_page` (default/max `100`).
- **Response:** Paginated array of releases with `id`, `title`, and `upc`; totals are provided in `X-WP-Total` and `X-WP-TotalPages` headers.

**GET `/wp-json/mds/v1/artists`**
- **Permission:** `edit_posts`.
- **Query params:** Required `search` term and optional `page`.
- **Response:** Up to 20 Artist UUID/title choices and a page count for the Release editor picker.

**POST `/wp-json/mds/v1/settings`**
- **Permission:** `manage_options`.
- **Body params:** Provider config, audio players, feature toggles, optional JSON-encoded `mds_rm_block_defaults`, and Bandcamp shortcode template.
- **Response:** Success/error JSON.

**POST `/wp-json/mds/v1/network-settings`**
- **Permission:** `manage_network_options`.
- **Body params:** Master hub ID, spoke sites, wipe-on-uninstall flag.
- **Response:** Success/error JSON.

**POST `/wp-json/mds/v1/sync-network`**
- **Permission:** `manage_network_options`.
- **Body params:** Optional `job_id` returned by a previous batch.
- **Response:** One bounded batch of up to 10 catalog posts with `job_id`, cumulative counts, phase, errors, and `done`. Repeating the request with the returned ID resumes an interrupted job.

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
   - **Input:** Optional `job_id` to resume a prior batch
   - **Output:** Batch progress (`done`, `job_id`, phase, cumulative counts), and any error details
   - **MCP Public:** ✓

**Implementation:**
- Class: `MDS_RM_Abilities` in `includes/api/class-mds-rm-abilities.php`
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
7. **Route diagnostics through the logger.** `MDS_RM_Logger::diagnostic()` writes routine messages to the PHP log only when `WP_DEBUG` and `WP_DEBUG_LOG` are both `true`. `report_failure()` logs operational failures regardless of debug settings. `log()` still publishes to `mds_rm_log_message` under the same debug flags without writing to the PHP log.
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
One designated **Hub site** stores authoritative release data; catalog editing is only possible there. **Spoke sites** hold read-only shadow records that the hub overwrites on each broadcast (including title, content, and excerpt), and may reference or display data via virtual media attachments or cross-site REST API calls. The network admin configures the hub and spoke topology via the network settings page.

---

## Summary

- **Namespace/Prefix:** `MDS_RM_`
- **Class Pattern:** Static, final classes with `register_hooks()` and static methods.
- **Autoload:** SPL-based, file-to-class mapping via `includes/class-mds-rm-*.php`.
- **Security:** Sanitize input (`wp_unslash()` + `sanitize_*()`, `esc_*()` output, nonce checks on traditional forms.
- **Hooks:** Extensive custom action/filter hooks for third-party extensibility.
- **Multisite:** Hub-and-spoke architecture with network options management.
- **No External Dependencies:** Vanilla PHP + WordPress APIs only.
