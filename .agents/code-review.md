# Code Review Report — MDS Release Manager

- **Date:** 2026-11-22
- **Scope:** Full repository audit (`mds-release-manager.php`, `uninstall.php`, `includes/`, `blocks/`, `js/`, `css/`, `templates/`, `.agents/rules/`)
- **Summary:** 5 Critical / High | 3 Medium / Low

## Executive Summary

All 45 non-test PHP files in `mds-release-manager` pass PHP syntax checking (`php -l`) with zero parse errors, and all 7 standalone unit test suites (`tests/release-links-audio.php`, `tests/settings-save.php`, `tests/release-search.php`, `tests/settings-tabs.php`, `tests/block-defaults.php`, `tests/customizer.php`, `tests/uninstall.php`) pass.

The audit identified **5 Critical / High** issues (2 unbounded queries, 1 broken internal REST route causing site-wide release redirects to fail silently, 1 Abilities API JSON Schema enum mismatch for Font Awesome support, and 1 unguarded post-meta recursion path) and **3 Medium / Low** maintenance items (documentation minimum-version drift, omitted option/transient keys in `uninstall.php` plus unused script localization keys, and legacy meta-bar helper methods).

---

## Findings Checklist

> Reference the **ID** (`CR-001`, `CR-002`, etc.) when asking the agent to implement fixes.

### Critical & High Priority

- [ ] **CR-001** `[Runaway State]` — Unbounded `posts_per_page => -1` Artist query in `MDS_RM_Release_Search::artist_names()`
  - **Location:** `includes/core/class-mds-rm-release-search.php:262-280`
  - **Issue:** `MDS_RM_Release_Search::artist_names()` queries `'post_type' => 'artist'` with `'posts_per_page' => -1` and a `meta_query` (`_mds_artist_uuid IN (...)`). Unlike `MDS_RM_CSV_Handler::export_csv()` (which batches Artist UUID lookups in chunks of 100), `artist_names()` uses `-1` without `'no_found_rows' => true` or a bounded post limit.
  - **Recommended Fix:** Replace `'posts_per_page' => -1` with `'posts_per_page' => min( self::CATALOG_LIMIT, count( $unique_uuids ) )` and add `'no_found_rows' => true`, `'update_post_term_cache' => false`.

- [ ] **CR-002** `[Runaway State]` — Unbounded `numberposts => -1` queries in CSV import UPC lookup and full-wipe uninstall
  - **Location:** `includes/admin/class-mds-rm-csv-handler.php:353-365`, `uninstall.php:63-75`
  - **Issue:**
    1. In `MDS_RM_CSV_Handler::import_csv()` (lines 353–365), each CSV row queries `release` posts matching `upc_ean` with `'numberposts' => -1`, even though the import logic only needs to distinguish between `0`, `1`, or `>1` matches.
    2. In `uninstall.php` (lines 63–75), `$delete_plugin_posts` loads all `release` and `artist` post IDs into memory at once with `'numberposts' => -1` instead of deleting in bounded batches.
  - **Recommended Fix:** In `MDS_RM_CSV_Handler::import_csv()`, replace `'numberposts' => -1` with `'posts_per_page' => 2, 'no_found_rows' => true`. In `uninstall.php`, loop `get_posts()` in batches of `100` until no posts remain.

- [ ] **CR-003** `[Static Defect]` — Self-REST loopback in `MDS_RM_Assets::handle_global_redirect()` calls unregistered route `/mds/v1/releases/post/{id}`
  - **Location:** `includes/frontend/class-mds-rm-assets.php:86-97`
  - **Issue:** `handle_global_redirect()` dispatches `new WP_REST_Request( 'GET', '/mds/v1/releases/post/' . $post_id )`, but `MDS_RM_REST_API::register_rest_routes()` only registers `/mds/v1/releases` and `/mds/v1/artists` (no `/mds/v1/releases/post/(?P<id>\d+)` endpoint exists). Consequently, `$response->is_error()` is always `true` (`rest_no_route`), `$release` is always `array()`, and **Site-Wide Release Routing (`mds_rm_global_redirect`) silently fails to redirect any release**.
  - **Recommended Fix:** Replace the internal `WP_REST_Request` call in `MDS_RM_Assets::handle_global_redirect()` with a direct `get_post_meta( $post_id, 'buy_now_link', true )` lookup (matching `MDS_RM_Artist_Routing::redirect()`).

- [ ] **CR-004** `[Static Defect]` — `MDS_RM_Abilities` `fa_support` enum mismatch vs. actual Font Awesome option values
  - **Location:** `includes/api/class-mds-rm-abilities.php:182-186`
  - **Issue:** The `mds_rm_save_settings` ability input schema defines `'fa_support'` with `'enum' => array( 'enabled', 'disabled' )`, whereas `MDS_RM_Admin_Interface::settings_page()` (`includes/admin/class-mds-rm-admin-interface.php:676-681`) and `MDS_RM_Assets::enqueue_font_awesome()` (`includes/frontend/class-mds-rm-assets.php:56-57`) expect `'cdn'`, `'plugin'`, `'theme'`, `'none'` (or legacy `'internal'`). Any MCP/Abilities client validating against the schema cannot submit valid `mds_rm_fa_support` values.
  - **Recommended Fix:** Update the `fa_support` schema `enum` in `MDS_RM_Abilities` to `array( 'cdn', 'plugin', 'theme', 'none', 'internal' )`.

- [ ] **CR-005** `[Static Defect]` — Redundant meta write re-entry in `MDS_RM_Release_Model::sync_normalized_upc_meta()` and missing recursion guard in `MDS_RM_Virtual_Media::virtual_media_meta_fallback()`
  - **Location:** `includes/core/class-mds-rm-release-model.php:63-65, 134-146`, `includes/api/class-mds-rm-virtual-media.php:29, 74-96`
  - **Issue:**
    1. `sync_normalized_upc_meta()` runs on `added_post_meta`, `updated_post_meta`, and `deleted_post_meta`. When `upc_ean` is updated, calling `update_post_meta( $post_id, self::NORMALIZED_UPC_META_KEY, $upc )` re-triggers `updated_post_meta` and `invalidate_audit_on_meta_change()` even when `_mds_normalized_upc` already matches `$upc`.
    2. `MDS_RM_Virtual_Media::virtual_media_meta_fallback()` hooks `get_post_metadata` and calls `get_post_meta( $object_id, '_mds_is_hub_media', true )` without a static re-entry guard, relying solely on the `$meta_key` whitelist check on line 75.
  - **Recommended Fix:** Add a static `$in_progress` re-entry guard to `virtual_media_meta_fallback()`, and guard `sync_normalized_upc_meta()` against redundant meta updates or recursive invocations.

---

### Medium & Low Priority (Orphaned Code & Cleanup)

- [ ] **CR-006** `[Compatibility]` — Stale PHP 7.4 / WordPress 6.3 minimum version rule in `.agents/rules/general.md`
  - **Location:** `.agents/rules/general.md:6`, `mds-release-manager.php:6-7`
  - **Issue:** `mds-release-manager.php` and `.agents/technical-blueprint.md` target **WordPress 6.9+** and **PHP 8.0+** (and the codebase uses PHP 8.0 union types and `str_ends_with()` in `includes/core/class-mds-rm-audio-players.php:510`), whereas `.agents/rules/general.md:6` still references WordPress 6.3 and PHP 7.4.
  - **Recommended Fix:** Update `.agents/rules/general.md` line 6 to match the plugin header (`WordPress 6.9+` and `PHP 8.0+`).

- [ ] **CR-007** `[Orphaned Code]` — Missing option/transient cleanup in `uninstall.php` and unused localization strings in `MDS_RM_Assets::admin_assets()`
  - **Location:** `uninstall.php:18-48`, `includes/frontend/class-mds-rm-assets.php:232-234`
  - **Issue:**
    1. `uninstall.php` does not delete `mds_rm_normalized_upc_migration_complete`, `mds_rm_network_sync_job`, `mds_rm_network_sync_lock`, single-site `mds_rm_db_templates` (`delete_option`), or the `mds_rm_release_audit` transient (`delete_transient`).
    2. `MDS_RM_Assets::admin_assets()` passes `provider_name`, `hex_color`, and `remove` in `mds_rm_data` to `js/settings.js`, which are unused remnants from the older provider repeater moved to `js/settings-defaults.js`.
  - **Recommended Fix:** Add the missing option and transient cleanup calls to `uninstall.php` and remove the unused keys from `mds_rm_data` in `MDS_RM_Assets::admin_assets()`.

- [ ] **CR-008** `[Orphaned Code]` — Legacy `MDS_RM_Shortcodes::render_meta_bar_html()` and `mds_rm_render_meta_bar_html()` unused internally
  - **Location:** `includes/frontend/class-mds-rm-shortcodes.php:39-53`, `mds-release-manager.php:394-396`
  - **Issue:** Neither `MDS_RM_Shortcodes::render_meta_bar_html()` nor the global wrapper `mds_rm_render_meta_bar_html()` is called anywhere in `mds-release-manager` or `mds-bandcamp-integration`, and `$include_shortcode` is unused inside `render_meta_bar_html()`.
  - **Recommended Fix:** Add `@deprecated` PHPDoc annotations (or remove if no external theme/plugin callers depend on `mds_rm_render_meta_bar_html()`).
