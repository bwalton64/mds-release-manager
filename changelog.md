# Changelog

All notable changes to MDS Release Manager are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added
- Add WordPress.org distribution metadata, a directory readme, and a translation template for existing localized strings.
- Add `.github/consolidation-plan.md`, the canonical phased plan for aligning plugin structure, nomenclature, blocks, and templates with WordPress standards. It supersedes the abandoned file-merge consolidation attempt, freezes the public API (hooks, blocks, shortcodes, routes, options, meta keys), and defines per-step verification gates.
- Add an isolated Playwright smoke suite covering Release and Artist fallbacks, Gutenberg metadata persistence, REST settings saves, and rewrite setup and cleanup.

### Changed
- Log routine Release Manager diagnostics only when WordPress debug logging is enabled. Operational failures still go to the PHP log.
- Serve Font Awesome Free locally instead of using a CDN. Existing icon settings and source selections continue to work.
- Load the five admin views from the plugin only, localize their labels, and move their layout styles into CSS. Theme overrides still work for the Artist and Release frontend fallbacks.
- Register Release Meta, Release Links, and Artist Discography from `block.json` without changing their saved markup or PHP renderers. Move core-block metadata bindings into a dedicated editor script.
- Organize PHP classes by function and register runtime hooks on `plugins_loaded`. Activation and deactivation callbacks still register when the plugin file loads.
- Set the documented minimums to WordPress 6.9 and PHP 8.0 and license the plugin under GPL-2.0-or-later.
- Move internal implementation summaries, refactoring reports, and the Abilities API reference documentation from the plugin root into the `.local/` directory. `README.md`, `changelog.md`, and `.github/technical-blueprint.md` are the canonical, maintained documentation.

### Fixed
- Validate that the CSV importer reads only temporary files received through an HTTP upload.
- When the full uninstall wipe is enabled, clear the stored spoke selection and Release Manager site options on every site in the network. The default uninstall behavior is unchanged.
- Flush custom post type rewrite rules on activation and deactivation, across all sites for network-wide changes, to prevent stale or missing Release and Artist routes.
- Prevent strict-types parse errors by keeping `declare(strict_types=1);` as the first executable statement after the file DocBlock and removing stray UTF-8 byte-order marks.

### Removed
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