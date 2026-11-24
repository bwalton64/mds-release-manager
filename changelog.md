# Changelog

All notable changes to MDS Release Manager are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added
- Add a centralized **Default Templates** tab (`MDS_RM_Template_Defaults`, `js/template-defaults.js`) in **Releases → Settings** between **General Settings** and **Audio Player Manager**, replacing the retired **Appearance → Customize → MDS Release Manager** panel. Includes a segmented Release/Artist template switcher, a closed-by-default togglable Live Preview pane with `wp.a11y.speak()` announcements, theme-independent storage (`mds_rm_template_defaults`) with automatic read-fallback from legacy `theme_mod` keys, and expanded Release & Artist layout/typography/border/visibility controls.
- Add a 2-tier **Artist & Per-Release Routing** system (`MDS_RM_Artist_Routing`) with an AJAX/REST-powered **Release Routing** table meta box on the Artist Edit screen (`POST /mds/v1/artists/{id}/release-routing`, `js/artist-routing.js`) supporting bulk actions, search filtering, live destination previews, Artist default release policies (`fallback`, `artist_website`, `buy_now_or_artist`, `buy_now_or_fallback`), per-release overrides (`inherit`, `fallback`, `artist_website`, `buy_now`, `custom_url`) in the Commerce meta box, automatic external host whitelisting for `302 Found` redirects, and WooCommerce extension hooks (`mds_rm_release_routing_modes`, `mds_rm_resolve_release_redirect_url`, `mds_rm_routing_destination_preview`).
- Add the editable **`release_media_format` (Media Formats)** taxonomy with per-term icon metadata (`_mds_media_format_icon`, seeded with defaults for Digital Download, CD, Vinyl LP, Vinyl EP, 7" Single, Cassette, and USB Flash Drive) and multi-format **Buy Now Links** repeater (`buy_now_links` post meta + Primary `buy_now_link` mirror) on the Release Commerce meta box.
- Add a standalone accessible **Choose Format** `<dialog>` modal (`js/buy-now-modal.js`) in the Release Links Block and `[release-links]` shortcode when 2+ Media Formats exist, configurable site-wide, per-block, or per-release (via the **Buy Now Button Behavior** control in the Commerce meta box, `buy_now_mode` post meta) between **Choose Format Modal** (`picker`) and **Primary Direct Link** (`direct`), plus WooCommerce White-Label extension hooks (`mds_rm_buy_now_links`, `mds_rm_buy_now_url`, `mds_rm_buy_now_display_mode`).
- Add the **Audio Player Manager** settings tab and `MDS_RM_Audio_Players` registry (`mds_rm_register_audio_players`), supporting single active player selection, automatic per-release fallback to the core HTML5 Player (`.mp3`), and validated declarative player styling/configuration schemas (`mds_rm_audio_player_settings`).
- Add an Extension Developer Toolkit for Release relationships and Gutenberg pickers: `MDS_RM_Release_Meta::resolve_release()`, `MDS_RM_Release_Meta::register_extension_meta()`, the `mds_rm_release_picker_item` filter, and a preloaded zero-REST editor catalog (`window.mdsRmCatalog` / `window.mdsRmToolkit` / enhanced `window.mdsRmUseReleasePicker` with `filter`, `metaBindings`, and `WP_DEBUG`-aware `debugFields`).
- Add a searchable, paginated Artist lookup to the Release editor and resumable batch progress to manual network synchronization.
- Add the `mds_rm_release_links_audio_html` filter so extensions supply the Release Links block audio player. The first non-empty result wins over the built-in HTML5 player and is sanitized with the iframe-aware allowlist.
- Add the `MDS_RM_Release_Search` service and artist-name matching to the Release picker endpoints: picker searches now match the Release title, its Artist's name, or an exact all-digit UPC/EAN.
- Show a read-only "Primary ID (UPC/EAN)" field in the Release pickers when `WP_DEBUG` is enabled.
- Add site-wide presentation defaults for the Release Meta, Release Links, and Artist Discography blocks, with per-block overrides and preservation of legacy saved lists.
- Add extension-provided site and network settings tabs while retaining existing section hooks. Bandcamp adds a site tab and WooCommerce adds a network tab.
- Add native block Styles and per-table controls to Artist Discography, covering spacing, typography, colors, borders, links, and striped rows.
- Add excerpt support to Releases and Artists, shown by default on their edit screens and synchronized to spoke sites with the rest of the record.
- Make spoke sites read-only for Releases and Artists: creating, editing, and deleting catalog posts is denied outside the hub (admin UI, Quick Edit, bulk actions, and REST alike), spoke list screens link to the hub editor, and hub broadcasts are unaffected. This also stops spoke-local edits from drifting until the next broadcast.
- Add WordPress.org distribution metadata, a directory readme, and a translation template for existing localized strings.
- Add `.github/consolidation-plan.md`, the canonical phased plan for aligning plugin structure, nomenclature, blocks, and templates with WordPress standards. It supersedes the abandoned file-merge consolidation attempt, freezes the public API (hooks, blocks, shortcodes, routes, options, meta keys), and defines per-step verification gates.
- Add an isolated Playwright smoke suite covering Release and Artist fallbacks, Gutenberg metadata persistence, REST settings saves, and rewrite setup and cleanup.

### Changed
- Replace the bundled 1.07 MB `assets/fontawesome/` directory with an **Auto** Font Awesome integration (`MDS_RM_Assets`): automatically defers to an active Font Awesome plugin (Font Awesome Official, Better Font Awesome, Font Awesome Integration, or registered FA stylesheets) when detected, and otherwise loads Font Awesome Free 6.7.2 from cdnjs with SHA-512 Subresource Integrity (`integrity`, `crossorigin="anonymous"`, `referrerpolicy="no-referrer"`). Exposes `mds_rm_detected_font_awesome_plugin` and `mds_rm_font_awesome_cdn_url` filters and displays live detection status in **Releases → Settings → General Settings**.
- Rename the `release_format` taxonomy to **`release_type` (Release Type)** across post-type registration, validation, Release Identity meta box, CSV import/export (`Release Type` column), blocks, Elementor tags, and Hub-and-Spoke sync, with an automatic one-time database migration preserving all existing Release Type assignments.
- Optimize Hub-and-Spoke broadcast and manual network synchronization (`MDS_RM_Sync`, `MDS_RM_Virtual_Media`, `MDS_RM_Admin_Interface`): replace delete-all/reinsert-all meta sync with diff-based post meta, core field, thumbnail, and taxonomy updates; switch to each spoke once per batch; eliminate empty phase-transition HTTP round-trips; persist real Hub attachment dimensions, MIME types, and domain-agnostic `_mds_hub_media_id` keys on spoke virtual attachments (with a static recursion guard in `virtual_media_meta_fallback()`); and broadcast Hub Release/Artist updates triggered via Quick Edit, REST API, or WP-CLI.
- Redesign and centralize the Release Meta, Release Links, and Artist Discography styling controls across `Releases → Settings` and the Gutenberg Block Inspector (`window.mdsRmStylePrimitives` in `blocks/native-release-styles.js`): standardize industry-standard section and control nomenclature, add informational callout blocks and sub-section grouping with a responsive 2-column admin grid (`960px` canvas), enable multi-open accordions with an Expand/Collapse All toolbar, upgrade metadata fields and streaming providers to compact repeater cards with header Show/Hide and Move Up/Down actions plus shared icon presets, and unify Artist Discography spacing into a single Theme Preset dropdown paired with a 4-sided `BoxControl`.
- Editor Release and Artist pickers fetch choices only while their block is selected, share identical in-flight lookups, and retry transient failures before reporting an error, keeping block-heavy editor sessions responsive.
- Picker options display "Title — Artist" without identifiers; selecting a Release binds the block to its UPC/EAN primary ID (the post ID is kept only for Releases without a UPC, and legacy saved selections still resolve).
- Paginate the public releases REST endpoint with standard total headers while retaining its array response; stream validated CSV exports in bounded pages without changing the CSV columns.
- Automatic Release Meta, Release Links, and Artist Discography blocks use the site-wide defaults under Releases → Settings instead of separate Customizer styles.
- Group Release and Artist fallback Customizer controls by the part of the page they style.
- Use native WordPress Customizer controls for Release and Artist fallback styling, including colors with transparency.
- Expand the Release Meta, Release Links, and Artist Discography shortcodes to expose their corresponding block options and styling controls, including Release ID selection and Discography table styles.
- Move streaming provider and audio preview settings from Release Data to the Release Links settings tab; existing tab links continue to work.
- Edit Releases and Artists on the native classic screen instead of the block editor. The former monolithic Release Settings box is split into Release Identity, Release Details, Streaming & Audio, and Commerce boxes, with Cover Art/Artist Photo featured-image labels and the redundant Slug box removed. Extension meta boxes (`mds_rm_register_custom_meta_boxes` / `mds_rm_save_custom_meta`), SEO plugin meta boxes, saved field names, and hub-to-spoke broadcasting are unchanged.
- Remove Custom Fields support from Releases and Artists so the Custom Fields panel no longer appears; stored post meta is unaffected.
- Give Release Meta a native Release picker with Artist-context fallback and native styling controls for layout, typography, and icons; retain saved responsive overrides until explicitly removed.
- Replace Release Links' UPC field with a native Release picker and use the latest published Release in Artist context. Replace the Device dropdown and raw CSS fields with native WordPress controls while preserving saved tablet and mobile overrides until explicitly removed.
- Replace the Artist Discography numeric ID field with a searchable picker for published Artists. Clearing it uses the current Artist; unavailable saved selections are retained with an editor warning.
- Log routine Release Manager diagnostics only when WordPress debug logging is enabled. Operational failures still go to the PHP log.
- Serve Font Awesome Free locally instead of using a CDN. Existing icon settings and source selections continue to work.
- Load the five admin views from the plugin only, localize their labels, and move their layout styles into CSS. Theme overrides still work for the Artist and Release frontend fallbacks.
- Register Release Meta, Release Links, and Artist Discography from `block.json` without changing their saved markup or PHP renderers. Move core-block metadata bindings into a dedicated editor script.
- Group the Release Meta, Release Links, and Artist Discography blocks under a shared "MDS Release Manager" section in the block inserter instead of Widgets. Extension plugins join the section by using the `mds-release-manager` block category and registering it defensively on `block_categories_all`.
- Organize PHP classes by function and register runtime hooks on `plugins_loaded`. Activation and deactivation callbacks still register when the plugin file loads.
- Set the documented minimums to WordPress 6.9 and PHP 8.0 and license the plugin under GPL-2.0-or-later.
- Move internal implementation summaries, refactoring reports, and the Abilities API reference documentation from the plugin root into the `.local/` directory. `README.md`, `changelog.md`, and `.github/technical-blueprint.md` are the canonical, maintained documentation.

### Fixed
- Replace recurring UPC regular-expression scans with a normalized lookup key and bounded migration, cache and page the Release-list audit, and consolidate legacy UPC adoption lookups during spoke sync.
- Show the Artist Discography settings controls when its tab is selected.
- Persist changes to the Bandcamp shortcode template when the field is submitted.
- Validate that the CSV importer reads only temporary files received through an HTTP upload.
- When the full uninstall wipe is enabled, clear the stored spoke selection and Release Manager site options across the current network, and remove Release and Artist posts including those in Trash. The default uninstall behavior is unchanged.
- Flush custom post type rewrite rules on activation and deactivation, across all sites for network-wide changes, to prevent stale or missing Release and Artist routes.
- Prevent strict-types parse errors by keeping `declare(strict_types=1);` as the first executable statement after the file DocBlock and removing stray UTF-8 byte-order marks.

### Removed
- Remove the core plugin's remaining Bandcamp coupling: the Release Links `bandcamp_shortcode` audio branch, the Bandcamp Shortcode Template setting and its REST save, the CSV "Bandcamp Shortcode" column, and the Elementor Bandcamp Shortcode field. The Bandcamp extension now supplies the player exclusively through the `mds_rm_release_links_audio_html` filter, and `mds_rm_bandcamp_template` remains only as an uninstall deletion contract.
- Remove the internal `MDS_RM_Core_Functions` shim after moving its methods into the Logger, Sync, Providers, and Template Loader classes. Custom PHP code that calls the old class directly should use the replacements in the [consolidation plan](.github/consolidation-plan.md#core_functions-split-map).
- Remove the orphaned, incomplete `includes/class-mds-rm-blocks.php` fragment left over from an abandoned file-consolidation attempt. It duplicated part of `MDS_RM_Release_Meta`, failed PHP linting, and was never loaded at runtime.

## [1.0.0] - 2026-11-19
### The Feature-Complete Release
This major release introduces a comprehensive feature set for artist release management, WordPress Abilities API integration, and extensible plugin architecture. All core features are now production-ready with extensive customization and third-party integration support.

### Added
- Add a responsive Release Meta Block for Gutenberg and Elementor, plus the `[release-meta]` shortcode with per-field and per-device styling.
- Add a separate, manually placed Release Links Block for Gutenberg and Elementor and the `[release-links]` shortcode, with per-provider overrides, audio visibility, and responsive styles.
- Add a Release `the_content` fallback with responsive featured imagery, default Release Meta/Links components, builder detection, and fallback styling controls in the Customizer. Suppress duplicate theme thumbnails when the fallback renders the featured image.
- Add an Artist content fallback, Artist-specific Customizer controls, and a reusable paginated Discography block and shortcode with column and cell filters.
- Add optional per-Artist temporary routing to an official homepage, with an editor warning for invalid destinations.
- Add WordPress Abilities API integration (WordPress 6.9+) exposing core plugin features as native MCP tools: Get Releases, Save Settings, Save Network Settings, and Sync Network Data. Automatically discoverable by the official WordPress MCP Adapter with `meta.mcp.public` metadata and comprehensive JSON Schema definitions.
- Add comprehensive README.md with installation, usage, and contribution guidelines for Windows and cross-platform development.

### Changed
- Replace the Release and Artist whole-template overrides with guarded content fallbacks. Site-Wide Routing suppresses Release fallback composition; builder-authored content suppresses both fallbacks.
- Rename the Release Customizer layout section to Release Fallback Template and expose its layout and component styles.
- Hide the theme author byline on Release pages and place the default Release Meta component before the post content.
- Require one Release Format (Single, EP, Album, or Digital) and a unique UPC/EAN for saved releases, with both fields grouped together.
- Use the Release Format taxonomy for display and CSV export, report legacy conflicts, and synchronize UPC corrections to existing spoke releases.
- Move product-context resolution, automatic product placement, and product-specific sync mapping to the WooCommerce integration; retain existing sync targets.
- Remove the retired Release Links renderer, template, and widget-specific styles. Preserve stored release data and legacy settings; old embeds must be replaced with the current shortcode or block.
- Remove the deprecated Artist image URL field; clean up its stored metadata in batches on each site's administrator visits and exclude it from synchronization. The WordPress featured image is used instead.
- Hide the Release Data dynamic block controls in the Artist editor and preserve supported rich text in Artist contact information.

### Fixed
- Remove the remaining labels in Twenty Twenty-Five's Release and Artist byline pattern.
- Load the WordPress color-picker dependency for Release fallback Customizer color controls.
- Use the native WordPress tag editor and tag cloud in Release Metadata, and remove duplicate taxonomy panels from the block editor sidebar.
- Recognize the current `html5` audio-player setting when displaying release previews, while retaining support for the legacy `native` value.
- Allow all Release Formats to be cleared when editing a release. (Superseded by the required single-format model.)

### Security
- Restructure the core codebase into dedicated `MDS_RM_*` classes and harden the admin interface, network admin, release model, discography, and artist routing classes: nonce and capability verification on form handlers, `wp_unslash()` plus field-appropriate sanitization (`sanitize_text_field()`, `absint()`, `esc_url_raw()`) on request input, consistent output escaping (`esc_html()`, `esc_attr()`, `esc_url()`, `wp_kses_post()`), strict comparisons, `wp_safe_redirect()` for admin redirects, and parameter/return type hints on refactored methods.

## [0.9.0] - 2026-11-14
### The Extensibility Update
This major release transitions MDS Release Manager from a monolithic plugin to a lightweight, extensible platform. Third-party integrations have been stripped from the core and moved to independent feature plugins.

### Added
- **Hook Registry:** Introduced internal APIs (`do_action` and `apply_filters`) to allow safe, upgrade-proof extensions.
- `mds_rm_register_custom_meta_boxes` action for injecting CPT metaboxes.
- `mds_rm_save_custom_meta` action for synchronous data saving.
- `mds_rm_settings_audio_fields` filter for Hub settings expansion.
- `mds_rm_network_settings_fields` filter for Network configuration.
- **Graceful Templating:** Improved `mds_render_template` to silently strip unpassed `{tokens}` instead of outputting raw curly braces.

### Changed
- Refactored entire core codebase to strict PSR-12 PHP formatting standards.
- Strengthened security pipeline: comprehensive implementation of `wp_unslash()`, `sanitize_text_field()`, `esc_url_raw()`, and `absint()` across all database writes.
- Centralized all settings UI logic strictly into `admin-interface.php`.

### Removed
- Removed all WooCommerce network-syncing logic and AJAX API endpoints from the core (Moved to `mds-woocommerce-integration` plugin).
- Removed all Bandcamp Shortcode dependencies and UI logic from the core (Moved to `mds-bandcamp-integration` plugin).

### Fixed
- Fixed fatal parse errors caused by orphaned braces and duplicate function declarations during network activation.
- Fixed a bug where `admin_head` hooks for list table formatting were firing too late in the WordPress lifecycle.

## [0.8.0]
### The UI & Stability Update

### Added
- Implemented native click-to-copy functionality for the Shortcode column in List Tables (flashes "Copied!" visually).
- Added explicit 60px CSS boundaries for the `mds_thumb` image columns in the "All Releases" and "All Artists" views to prevent UI wrapping.

### Changed
- Locked the "Use Linked Product URL" button to an *always enabled* state for better UX.
- Overhauled WooCommerce permalink fallback to read from the HTML `data-permalink` attribute natively.

### Removed
- Permanently purged obsolete UI tabs from `Releases -> Settings` (Audio Preview Player, Release Links Widget Styling, Buy Now Link, WooCommerce Meta Styling).
- Removed legacy "Featured Image URL" logic and storage pipelines from the Artist CPT.

### Fixed
- Fixed broken Select2 AJAX search bindings for WooCommerce product lookups.
- Fixed variable leakage in `admin-interface.php` that was causing undefined warnings in the Artist Settings PHP Meta Box.
- Restored the native WordPress WYSIWYG editor output inside the Artist "Contact Info" section.