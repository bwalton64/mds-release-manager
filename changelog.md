# Changelog - MDS Release Manager

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

---

## [0.8.0] - Previous
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