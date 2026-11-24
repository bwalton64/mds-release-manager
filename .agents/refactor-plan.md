# Refactor Migration Plan — MDS Release Manager

> **Status:** Core PHP PSR-4 migration (`src/`), Composer autoloading, Tier 1/2 constants (`src/Config.php`), lazy companion-plugin `class_alias()` shims, `wpml-config.xml`, `init` textdomain loading, and `wp_add_inline_script()` migration executed & verified.
> Target configuration resolved from [project-config.md](file:///c:/Users/Brian/Local%20Sites/bgw-dev/app/public/wp-content/plugins/mds-release-manager/.agents/rules/project-config.md).

## Target Configuration Summary

| Variable | Target Value |
| :--- | :--- |
| **Plugin Name** | `MDS Release Manager` |
| **Plugin Slug / Folder** | `mds-release-manager` |
| **Text Domain** | `mds-release-manager` |
| **PHP Root Namespace** | `MDS\ReleaseManager` |
| **Global Class Prefix** | `MDS_RM_` |
| **Function / Hook Prefix** | `mds_rm_` |
| **Constant Prefix** | `MDS_RM_` |
| **Minimum WordPress** | `7.0` (`Tested up to: 7.x`) |
| **Minimum PHP** | `8.0` |
| **LocalWP Site URL** | `http://bgw-dev.local/` |

## Current Baseline Snapshot

- **PSR-4 Migration Status:** Core classes are already moved to `src/` under `MDS\ReleaseManager\` with lazy compatibility aliases in `includes/legacy-class-aliases.php`.
- **PHPCS Baseline (`vendor/bin/phpcs --report=summary`):** `614 errors, 202 warnings` across `38 / 59` files (`471 errors` are inside `/tests/*` because `phpcs.xml.dist` does not exclude standalone test stubs; `143 errors, 111 warnings` are in production PHP files).

---

## Phase 1: Composer, Linters & Metadata Alignment

- [ ] **Exclude standalone test stubs in `phpcs.xml.dist`:** Add `<exclude-pattern>/tests/*</exclude-pattern>` (matching `mds-bandcamp-integration` and `mds-woocommerce-integration`) so PHPCS focuses on production code while standalone test fixtures run via PHP CLI.
- [ ] **Sync `composer.json` and plugin header version:** Align `composer.json` (`optimize-autoloader: true`), `package.json`, `mds-release-manager.php`, and `readme.txt` metadata for `WordPress 7.0+` / `PHP 8.0+`.
- [ ] **Audit `blocks/*/block.json`:** Confirm `apiVersion: 3`, `category: "mds-release-manager"`, `icon: "album"`, and `textdomain: "mds-release-manager"` across `release-meta`, `release-links`, and `artist-discography` (currently compliant; verify no stale handles after Phase 3).
- [ ] **Resolve `mds-release-manager.php` & `uninstall.php` WPCS errors:** Fix the 1 error in `mds-release-manager.php` and 7 errors in `uninstall.php` (global variable prefixing `$mds_rm_*` and prepared query annotations).

---

## Phase 2: PHP Classes, Autoloading & Views Cleanup

- [ ] **Audit internal class references in `src/`:** Verify all 35 PSR-4 classes under `src/` (`Admin/`, `Api/`, `Blocks/`, `Core/`, `Frontend/`, `Integrations/Elementor/`) use native `MDS\ReleaseManager\*` `use` imports rather than legacy `MDS_RM_*` class names.
- [ ] **Keep `includes/legacy-class-aliases.php` as a thin BC bridge:** Retain lazy `spl_autoload_register` aliases for companion plugins (`mds-bandcamp-integration`, `mds-woocommerce-integration`) and theme overrides until all companion plugins migrate to `MDS\ReleaseManager\*` imports.
- [ ] **Prefix template-scoped variables in `includes/admin/views/*.php` and `templates/*.php`:** Prefix local variables with `$mds_rm_` (or wrap view rendering inside closures) to clear the 81 `WordPress.NamingConventions.PrefixAllGlobals` errors across:
  - `includes/admin/views/artist-release-routing-box.php` (20 errors)
  - `includes/admin/views/release-commerce-box.php` (14 errors)
  - `includes/admin/views/settings-page.php` (12 errors)
  - `includes/admin/views/network-settings-page.php` (6 errors)
  - `includes/admin/views/release-streaming-box.php` (6 errors)
  - `includes/admin/views/release-identity-box.php` (2 errors)
  - `includes/admin/views/release-details-box.php` (1 error)
  - `templates/release-fallback.php` (13 errors)
  - `templates/artist-fallback.php` (10 errors)
- [ ] **Resolve remaining `src/` WPCS errors & warnings:** Run `composer phpcbf` on `src/` and manually resolve remaining sniffs in `AdminInterface.php`, `RestApi.php`, `BlockDefaults.php`, `BuilderDetection.php`, `Cache.php`, `ReleaseLinksBlock.php`, `CsvHandler.php`, `Sync.php`, `ReleaseMeta.php`, `ReleaseModel.php`, `Assets.php`, and `Discography.php`.

---

## Phase 3: Functions, Hooks, Constants, Handles & Text Domain

- [ ] **Standardize script/style handles to `mds-rm-*` prefix** (audit any theme/extension dependencies before renaming):
  - `mds-admin-view-styles` → `mds-rm-admin-view-styles` (`src/Frontend/Assets.php`)
  - `mds-settings-tabs` → `mds-rm-settings-tabs` (`src/Frontend/Assets.php`)
  - `mds-font-awesome` → `mds-rm-font-awesome` (`src/Frontend/Assets.php`, check companion plugins for handle lookups)
  - `mds-buy-now-modal` → `mds-rm-buy-now-modal` (`src/Blocks/ReleaseLinks.php`)
  - `mds-release-links-block` → `mds-rm-release-links-block` (`src/Blocks/ReleaseLinks.php`)
  - `mds-release-meta` → `mds-rm-release-meta` (`src/Blocks/ReleaseMeta.php`)
- [ ] **Constants review (`mds-release-manager.php`):** Keep `MDS_RM_VERSION`, `MDS_RM_FILE`, `MDS_RM_PATH`, `MDS_RM_URL`, `MDS_RM_BASENAME` as canonical constants; document legacy aliases `MDS_RELEASE_MANAGER_VERSION` and `MDS_RM_MAIN_FILE` as deprecated compatibility shims.
- [ ] **Custom hooks verification:** All 27 custom `do_action()` / `apply_filters()` hooks already use the `mds_rm_` prefix. Add targeted `phpcs:ignore` comments in `src/Core/Cache.php` for intentional third-party cache plugin hooks (`litespeed_purge_all`, `litespeed_purge_cssjs`, `breeze_clear_all_cache`).
- [ ] **Text domain audit (`src/Frontend/FrontendTemplates.php`):** Review lines 225 and 227 where `__( 'Written by ', 'twentytwentyfive' )` and `__( 'in', 'twentytwentyfive' )` intentionally match Twenty Twenty-Five's byline pattern—add explicit `phpcs:ignore WordPress.WP.I18n.TextDomainMismatch` with justification so PHPCS passes cleanly.

---

## Phase 4: Data & Backward-Compatibility Risks (Do NOT Rename Without Migration)

The following persisted identifiers are live on `http://bgw-dev.local/` and/or shared with companion plugins (`mds-bandcamp-integration`, `mds-woocommerce-integration`). **Preserve these exact keys** unless a dedicated versioned migration routine is authored:

- [ ] **WordPress Options (`wp_options`):**
  - `mds_rm_block_defaults`, `mds_rm_template_defaults`, `mds_rm_cache_settings`, `mds_rm_providers`, `mds_rm_audio_players`, `mds_rm_audio_player_settings`, `mds_rm_fa_support`, `mds_rm_global_redirect`, `mds_rm_network_sync_job`, `mds_rm_artist_image_cleanup_complete`, `mds_rm_db_templates`
- [ ] **Multisite Network Options (`wp_sitemeta`):**
  - `spoke_sites` (legacy unprefixed key — **high migration risk**; preserve or dual-read if migrating to `mds_rm_spoke_sites`)
  - `mds_rm_master_blog_id`, `mds_rm_woo_sites`, `mds_rm_db_templates`, `mds_rm_wipe_on_uninstall`
- [ ] **Post Types, Taxonomies & Meta Keys:**
  - CPTs: `release`, `artist`
  - Taxonomies: `release_type`, `release_media_format`, `release_tag`
  - Post Meta: `upc_ean`, `_mds_normalized_upc`, `artist_id`, `_mds_artist_uuid`, `buy_now_link`, `buy_now_links`, `buy_now_mode`, `streaming_links`, `audio_preview_url`, `featured_image_url`, `record_label`, `distributor`, `artist_releases_routing_mode`, `release_routing_mode`, `release_routing_url`
  - Term Meta: `_mds_media_format_icon`
- [ ] **Public Contracts (Blocks, Shortcodes, REST API, Abilities):**
  - Blocks: `mds-rm/release-meta`, `mds-rm/release-links`, `mds-rm/artist-discography`
  - Shortcodes: `[release-meta]`, `[release-links]`, `[artist-discography]`
  - REST Namespace: `mds/v1` (referenced by JS pickers, settings UI, and external clients)

---

## Phase 5: Verification & Documentation Wrap-Up

- [ ] Run `composer dump-autoload -o` and `composer phpcs` (target: `0 errors, 0 warnings`).
- [ ] Run `npm run lint:js` and `npm run lint:css` (if `@wordpress/scripts` linters are configured).
- [ ] Run PHP standalone tests (`tests/*.php`) and Playwright smoke suite (`npm run test:e2e`) against `http://bgw-dev.local/`.
- [ ] Update `.agents/technical-blueprint.md`, `README.md`, `readme.txt`, and `changelog.md` once refactoring phases are executed.
