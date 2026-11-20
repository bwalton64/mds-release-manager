# MDS Release Manager — Structure Consolidation Plan

**Status:** Approved — Phase 2 complete; Phase 3 in progress
**Owner:** @bwalton64
**Created:** 2026-11-20
**Supersedes:** The abandoned file-merge consolidation effort (25 → 11 merged class files).

---

## 1. Purpose

Align the plugin's structure, nomenclature, and templates with WordPress best practices so the
codebase is readable, compliant, stable, and future-proof — and get it to a state that passes
WordPress.org directory review.

### Lessons from the failed consolidation attempt

The previous effort tried to merge 25 class files into 11 multi-class files in bulk. It produced
corrupted files with `declare(strict_types=1)` placement errors and PHP parse failures, and
functionality may have been lost.

This plan takes the opposite approach:

1. **No file merging.** One class per file stays the rule (it is also the WPCS rule).
   Organization improves through *folders*, not mega-files.
2. **One class per commit.** Every phase is broken into reviewable, individually revertible steps.
3. **Mandatory verification gate after every step** (see §6). No step is complete until the gate
   passes. No bulk automated rewrites of multiple files in a single pass.
4. **The public API is frozen** (see §5). Hook names, shortcodes, block names, REST routes,
   option names, and meta keys never change in this effort.

---

## 2. Approved decisions

| Decision | Choice |
| --- | --- |
| PHP / WP minimums | **PHP 8.0+ / WordPress 6.9+** (code already uses `mixed`; Abilities API requires 6.9) |
| `includes/` organization | **Functional subfolders** (WooCommerce style) |
| Block registration | **Migrate to `block.json` metadata** in a `blocks/` directory (as in `mds-bandcamp-integration`) |
| Method naming | **Strip the redundant `mds_rm_` prefix from class methods** (classes keep the `MDS_RM_` prefix) |
| Settings page | **Keep REST-based saving**; fix i18n and inline styles only |
| Distribution target | **WordPress.org** — requires `readme.txt`, GPL license headers, full i18n, Plugin Check pass |
| This document | Canonical, lives in `.github/` beside `technical-blueprint.md` |

---

## 3. Target structure

```
mds-release-manager/
├── mds-release-manager.php              # Header + constants + autoloader + bootstrap call
├── uninstall.php
├── readme.txt                           # NEW — WordPress.org readme
├── README.md
├── changelog.md
├── blocks/                              # NEW — block.json metadata blocks
│   ├── release-meta/
│   │   ├── block.json
│   │   ├── edit.js
│   │   └── editor.css (if needed)
│   ├── release-links/
│   │   └── …
│   └── artist-discography/
│       └── …
├── includes/
│   ├── class-mds-rm-plugin.php          # NEW — bootstrap: registers all hooks on plugins_loaded
│   ├── core/
│   │   ├── class-mds-rm-post-types.php
│   │   ├── class-mds-rm-release-model.php
│   │   ├── class-mds-rm-builder-detection.php
│   │   ├── class-mds-rm-logger.php          # NEW — split from Core_Functions
│   │   ├── class-mds-rm-sync.php            # NEW — split from Core_Functions
│   │   ├── class-mds-rm-providers.php       # NEW — split from Core_Functions
│   │   └── class-mds-rm-template-loader.php # NEW — split from Core_Functions
│   ├── admin/
│   │   ├── class-mds-rm-admin-interface.php
│   │   ├── class-mds-rm-network-admin.php
│   │   ├── class-mds-rm-csv-handler.php
│   │   └── views/                       # NEW — admin templates (NOT theme-overridable)
│   │       ├── artist-meta-box.php
│   │       ├── release-meta-box.php
│   │       ├── csv-page.php
│   │       ├── settings-page.php
│   │       └── network-settings-page.php
│   ├── frontend/
│   │   ├── class-mds-rm-assets.php
│   │   ├── class-mds-rm-frontend-templates.php
│   │   ├── class-mds-rm-customizer.php
│   │   ├── class-mds-rm-artist-routing.php
│   │   ├── class-mds-rm-discography.php
│   │   └── class-mds-rm-shortcodes.php
│   ├── blocks/
│   │   ├── class-mds-rm-gutenberg-blocks.php
│   │   ├── class-mds-rm-release-meta.php
│   │   └── class-mds-rm-release-links.php
│   ├── api/
│   │   ├── class-mds-rm-rest-api.php
│   │   ├── class-mds-rm-abilities.php
│   │   └── class-mds-rm-virtual-media.php
│   └── integrations/
│       └── elementor/
│           ├── class-mds-rm-elementor-tags.php
│           ├── class-mds-rm-elementor-widget.php
│           ├── class-mds-rm-elementor-links-widget.php
│           └── class-mds-rm-elementor-release-meta-tag.php
├── templates/                           # Theme-overridable FRONTEND partials only
│   ├── artist-fallback.php
│   └── release-fallback.php
├── css/                                 # Unchanged (admin/frontend styles)
├── js/                                  # Admin scripts; blocks.js content moves to blocks/*/edit.js
└── languages/                           # NEW — generated .pot file
```

**Class names do not change.** Only file locations and method names change. The SPL autoloader
gains an explicit class→subfolder map (deterministic, no directory scanning).

### Core_Functions split map

| Current `MDS_RM_Core_Functions` method | New home |
| --- | --- |
| `mds_rm_log()` | `MDS_RM_Logger::log()` |
| `mds_rm_purge_cache()` | `MDS_RM_Sync::purge_cache()` |
| `mds_rm_get_db_templates()` | `MDS_RM_Template_Loader::get_db_templates()` |
| `mds_rm_render_template()` | `MDS_RM_Template_Loader::render()` |
| `mds_rm_kses_shortcode_html()` | `MDS_RM_Template_Loader::kses_shortcode_html()` |
| `mds_rm_enqueue_editable_style()` | `MDS_RM_Template_Loader::enqueue_editable_style()` |
| `mds_rm_enqueue_editable_script()` | `MDS_RM_Template_Loader::enqueue_editable_script()` |
| `mds_rm_ensure_term_exists()` | `MDS_RM_Sync::ensure_term_exists()` |
| `mds_rm_broadcast_to_spokes()` | `MDS_RM_Sync::broadcast_to_spokes()` |
| `mds_rm_get_default_providers()` | `MDS_RM_Providers::defaults()` |

---

## 4. Known defects this plan corrects

1. **Plugin header incomplete** — missing `Text Domain`, `Requires at least`, `Requires PHP`,
   `License`, `License URI`. All are WordPress.org requirements.
2. **Stated PHP minimum is wrong** — `technical-blueprint.md` claims PHP 7.4+, but
   `MDS_RM_Artist_Routing::valid_url( mixed $url … )` uses the PHP 8.0-only `mixed` type.
3. **`.local/` reports claim the block.json migration is complete** (`BLOCKS_STRUCTURE.md`,
   `REORGANIZATION_COMPLETE.md`). It was never implemented — blocks are still registered with
   inline `register_block_type()` argument arrays plus one monolithic 22 KB `js/blocks.js`.
4. **Two generations of method naming** — older classes prefix methods `mds_rm_*`; newer classes
   (`Abilities`, `Discography`, `Release_Meta`, `Release_Links`, `Artist_Routing`) use clean names.
5. **`MDS_RM_Core_Functions` is a grab-bag** mixing logging, caching, templates, kses, assets,
   terms, hub/spoke sync, and provider defaults.
6. **Admin templates are theme-overridable** — `locate_template( 'mds-release-manager/…' )`
   applies to everything in `templates/`, including meta boxes and settings pages.
7. **Untranslated hardcoded strings and inline `style=""` attributes** in admin templates
   (`settings-page.php` and others).
8. **Inconsistent type declarations** — some classes fully typed, some untyped.
9. **Fresh activation does not flush custom post type rewrite rules.** On a fresh multisite
   install, the Release URL returned 404 after network activation until rewrite rules were
   flushed manually. No activation/deactivation hook is registered in the current plugin source.
   This is a pre-existing runtime finding; do not fold a fix into a structural refactor without
   a focused regression test.

---

## 5. Frozen public API (must not change)

Verified by grep across `mds-bandcamp-integration` and `mds-woocommerce-integration`:

**Hooks consumed by extension plugins — preserve where currently emitted:**
- `mds_rm_register_custom_meta_boxes` (Bandcamp, Woo)
- `mds_rm_save_custom_meta` (Bandcamp, Woo)
- `mds_rm_after_release_meta` (Bandcamp listens for this, but no emission was found in the
  current plugin PHP source; investigate the existing integration gap before deciding on a fix)
- `mds_rm_network_settings_fields` (Woo)
- `mds_rm_sync_target_sites` (Woo)
- `mds_rm_after_shadow_meta_sync` (Woo)
- `mds_rm_release_meta_context_upc` (Woo)
- `mds_rm_after_buy_now_field` (Woo)

**Also frozen:** every other documented `mds_rm_*` action/filter, all shortcode tags, block names
(`mds-rm/release-meta`, `mds-rm/release-links`, `mds-rm/artist-discography`), REST routes and
schemas, Abilities names, option/site-option names, post meta keys, post type and taxonomy slugs,
CSS class names in rendered output, and the theme-override path `mds-release-manager/…`.

**Cross-plugin method calls:** a static search of the two available extension plugins found
`MDS_RM_Release_Meta::render()` in WooCommerce integration. It already has a clean name — do
not rename it. This search cannot establish that other callers or hook removals do not exist;
assess each public method rename as a compatibility change and retain compatibility where needed.

---

## 6. Verification gate (run after every step)

A step is complete only when all of these pass:

1. **Syntax:** `php -l` on every touched file (or all plugin PHP files — it is cheap).
2. **Activation:** On multisite, verify with `wp plugin is-active --network mds-release-manager`;
   run `wp eval` to confirm bootstrap and check WordPress logs for plugin warnings/fatals.
   Separate known LocalWP PHP CLI startup warnings from plugin runtime errors.
3. **Hook integrity:** the hook snapshot (taken in Phase 0) diff shows only expected changes —
   same hook names, same priorities, only callback method names differ where renamed.
4. **Smoke test:** the Playwright smoke suite from Phase 0 passes (release single page renders
   meta + links, artist page renders discography, settings page saves, release saves in admin).
5. **Commit:** one logical change per commit; never commit a failing gate.

Full-suite checks (PHPCS/WordPress ruleset and the Plugin Check plugin) run at the end of each
phase rather than each step.

---

## 7. Phased implementation plan

### Phase 0 — Baseline and safety net *(no structural refactor)*
1. Create a `consolidation` working branch.
2. Run `php -l` across all PHP files; record a clean baseline.
3. Capture a **hook inventory snapshot**: `wp eval` dump of all `mds_rm_*` and plugin-registered
   hooks (hook name → callback → priority) to `.local/hook-baseline.txt`.
4. Capture option/meta inventory (grep of `get_option`/`get_site_option`/meta keys) alongside it.
5. Build the minimal **Playwright smoke suite** + WP-CLI seeding (per repo testing standards):
   seed one artist + one release, verify frontend render, admin save, settings save; clean up.
6. Install PHPCS + WordPress-Coding-Standards and the **Plugin Check** plugin; record baseline
   reports (expect failures — they define the Phase 8 worklist).

**Gate:** baseline results are recorded. Before Phase 1, run the fresh-activation permalink
regression suite and persist the complete baseline smoke tests in a runnable suite. Do not treat
the post-flush smoke pass as proof that activation behavior is correct.

A narrow fix for a defect discovered by the baseline is allowed before Phase 1, provided it is
isolated from the structural refactor and has a regression test.

Run the Phase 0 suite only against a disposable, one-site local multisite with the plugin already
network-active. Use a WordPress root separate from the source checkout:

```powershell
$env:MDS_RM_TEST_URL = 'http://dev-test.local'
$env:MDS_RM_TEST_ALLOW_MUTATIONS = '1'
$env:MDS_RM_WP_PATH = 'C:\path\to\wordpress'
$env:MDS_RM_PHP = 'C:\path\to\php.exe'
$env:MDS_RM_WP_CLI = 'C:\path\to\wp-cli.phar'
# Optional when PHP does not load mysqli by default:
$env:MDS_RM_PHP_INI = 'C:\path\to\php.ini'
npm.cmd ci
npx.cmd playwright install chromium
npm.cmd run test:e2e
```

`MDS_RM_WP_PATH` must point to the WordPress installation being tested; WP-CLI runs with that
directory as its working directory so it cannot accidentally target another local site.
`MDS_RM_PHP_INI` is optional and is useful when the PHP executable needs an explicit configuration
to load `mysqli`.

The suite clears rewrite rules **before** activating, checks the public archive and seeded single
URLs, and confirms deactivation removes the custom rules. It also checks Release metadata and links,
Artist discography, Gutenberg metadata persistence, and the settings form's REST save. It removes its
own posts and temporary admin and restores the site options it touches. The plugin is returned to
network-active.

#### Stored-data inventory

The static call-site report in `.local/phase-0-static-data-calls.txt` is supplemented by these
contracts. This inventories source behavior, not all keys present in any particular database.

| Scope | Keys and behavior to preserve |
|---|---|
| Site options | `mds_rm_providers` (provider rows: `name`, `icon`, `color`), `mds_rm_audio_players`, `mds_rm_global_redirect`, `mds_rm_fa_support`, `mds_rm_bandcamp_template` (legacy integration), `mds_rm_artist_image_cleanup_complete`. `mds_rm_db_templates` is read here only outside multisite. The additional legacy site keys in `uninstall.php` are a deletion contract, not evidence of live reads or writes. |
| Network options | `mds_rm_master_blog_id`, `mds_rm_db_templates`, `mds_rm_wipe_on_uninstall`, `spoke_sites`. WooCommerce owns `mds_rm_wc_integrated_sites` and reads legacy `mds_rm_woo_sites`; Bandcamp owns its settings and migration flags and can remove `mds_rm_bandcamp_template`. |
| Release post meta | `upc_ean`, `artist_id` (Artist UUID, not numeric post ID), `record_label`, `distributor`, `buy_now_link`, `bandcamp_shortcode`, `audio_preview_url`, `streaming_links` (JSON provider-to-URL map), `featured_image_url`, `release_tags`, legacy `release_format`, `_mds_previous_upcs`, `_mds_hub_release_key`. The `release_format` and `release_tag` taxonomies are separate from legacy post meta. |
| Artist and media meta | `_mds_artist_uuid`, `artist_contact`, `artist_homepage_url`, `artist_homepage_routing`, retired `artist_featured_image`; `_mds_is_hub_media` on virtual attachments, alongside core `_wp_attached_file`, `_wp_attachment_metadata`, and post thumbnails (`_thumbnail_id`). |
| Theme mods | `mds_rm_layout_style` and `mds_rm_primary_color`; the exact `mds_rm_release_fallback_*` and `mds_rm_artist_fallback_*` sets come from the numeric/color control maps in `includes/class-mds-rm-customizer.php`. Array-indexed `get_theme_mod()` reads resolve to those literal keys, not separate options. |
| Other reads and writes | `settings_errors` is a short-lived core transient used by CSV import. UPC validation uses a database advisory lock (`mds-upc-` plus a site/UPC-derived hash), not an option or transient. Builder detection reads external Elementor, Divi, Bricks, WPBakery, and Beaver Builder metadata; those keys remain externally owned. |

Dynamic post-meta access requires extra care:

- The Gutenberg Release Data block uses its saved `meta_field`; the Elementor tag uses
  `mds_meta_key`. Both have special cases (such as streaming links and taxonomy-backed fields)
  and generic post-meta reads. Do not infer a storage allowlist solely from editor choices.
- Release Meta resolves fields through its field map and renderer, including a generic post-meta
  read. Discography reads only `upc_ean`, `record_label`, and `distributor` through its dynamic
  `$key` branch; custom columns and cells can be supplied by filters.
- Multisite shadow sync copies the source post's entire metadata map except the retired Artist
  image key, replacing the shadow's existing keys before rebuilding linkage and media. Its dynamic
  reads also resolve the `upc_ean` / `_mds_artist_uuid` identity and `_mds_hub_release_key` /
  historical UPC lookup paths. Third-party metadata can therefore be part of the sync contract.

#### Baseline progress — 2026-11-20

- Created the `consolidation` branch from `main` at `2f99103`, preserving existing worktree changes.
- PHP 8.5.12 and LocalWP PHP 8.2.29 syntax checks: all 32 plugin PHP files passed. PHP 8.0
  compatibility remains unverified.
- Isolated `dev-test` runtime: WordPress 7.1.5, PHP 8.2.29, multisite, plugin network-active.
  The plugin was copied from the baseline source at `2f99103`; WP-CLI was run from a temporary,
  checksum-verified PHAR because it is not on PATH.
- PHPCS 3.13.6 with the installed `WordPress` standard: 214 errors and 237 warnings in 28 files.
  Reports: `.local/phase-0-phpcs-summary.txt` and `.local/phase-0-phpcs-full.txt`.
- Plugin Check 2.2.0: 36 errors and 64 warnings. Full report:
  `.local/phase-0-plugin-check.txt`. Findings include missing distribution metadata/readme,
  i18n issues, and output-escaping findings; this is a baseline, not a security review.
- Runtime hook/callback snapshot: 45 hook names in `.local/phase-0-runtime-hooks.json`.
  Static call-site inventories: `.local/phase-0-static-hooks.txt` (71 matches) and
  `.local/phase-0-static-data-calls.txt` (169 matches); see the stored-data inventory above
  for dynamic access and integration-owned keys.
- Smoke assertions passed after a manual rewrite-rule flush: Release fallback rendered metadata
  and a Spotify link; Artist fallback rendered a Discography row; settings REST save returned
  HTTP 200 and persisted its value; Gutenberg editor save persisted Release metadata.
  Fixture IDs 5/6 and temporary user ID 2 were removed; the four settings options created by the
  test were deleted. Details: `.local/phase-0-fixtures.json`.
- **Baseline failure:** before the manual flush, the new Release permalink returned 404. The
  baseline source had no activation/deactivation hook.
- **Focused fix in current worktree:** `MDS_RM_Post_Types` now registers activation/deactivation callbacks to
  register or unregister the content types before flushing rewrite rules. Network activation and
  deactivation flush every site. A Playwright regression test resets rules, toggles network
  activation, and checks both archive routes; its test-side cycle checks deactivation cleanup.
  The test passed against `dev-test` (WordPress 7.1.5, PHP 8.2.29); it confirmed both archive URLs
  resolve after activation and the custom routes are removed on deactivation. The plugin was
  restored to network-active. The runtime snapshot diff showed only the two lifecycle hooks.
- **Repeatable baseline:** both Playwright specs passed on `dev-test` in 32 seconds. The
  four baseline assertions passed without a post-activation rewrite flush. An independent
  post-run check found no test posts or users and confirmed that the five snapshotted
  options matched their pre-run values. The test runner refused to run without an explicit
  opt-in to modify the isolated site.
- Plugin Check and its temporary test admin were removed. The Local PHP CLI config emits
  a missing `php_imagick.dll` startup warning. The supported minimum PHP and WordPress
  versions and network activation across multiple subsites were not exercised in this run.
- A comparison with commit `4eea94f` found only `strict_types` placement and BOM changes in
  the PHP plugin files through `HEAD`; this comparison does not establish feature completeness
  before that commit or prove behavior is intact.

### Phase 1 — Compliance metadata *(tiny, high value)*
1. Complete the plugin header: `Text Domain: mds-release-manager`, `Requires at least: 6.9`,
   `Requires PHP: 8.0`, `License: GPL-2.0-or-later`, `License URI`, proper `Author`/`Author URI`.
2. Add `readme.txt` (WordPress.org format: description, installation, FAQ, changelog pointer).
3. Correct `technical-blueprint.md`: PHP 8.0+ / WP 6.9+, remove the claim of no-block.json.
4. Generate the translation template from the existing localized strings:

   ```powershell
   wp i18n make-pot . languages\mds-release-manager.pot --domain=mds-release-manager '--exclude=.github,.local,tests,test-results,playwright-report,playwright.config.js'
   ```

**Gate:** §6 + header lint via Plugin Check.

**Phase 1 results:** PHP syntax, both Playwright specs, and Plugin Check 2.2.0's header and
readme checks passed on `dev-test` (WordPress 7.1.5 / PHP 8.2.29). Runtime hooks were unchanged
from the Phase 0 exit state. The full Plugin Check scan still reports 32 errors and 63
warnings, and PHPCS is unchanged at 214 errors and 237 warnings. Translation extraction
reported existing placeholder and translator-comment findings; generating a catalog does
not complete localization. Verify WordPress 6.9 with PHP 8.0 before publication.

### Phase 2 — Method renames *(one class per commit)*
Strip the `mds_rm_` prefix from methods in these classes, updating `register_hooks()` callbacks
and all internal call sites in the same commit:

`Assets`, `Post_Types`, `Admin_Interface`, `Network_Admin`, `Customizer`, `Frontend_Templates`,
`Artist_Routing` (already partially clean), `REST_API`, `Shortcodes`, `Elementor_Tags`,
`CSV_Handler`, `Gutenberg_Blocks`, `Virtual_Media`, `Release_Model`, `Builder_Detection`,
`Discography` (verify), `Release_Meta`/`Release_Links` (verify).

Rules:
- Grep the **whole plugin plus both extension plugins** for each old method name before renaming.
- Hook *names* never change; only the callback method names do.
- `MDS_RM_Core_Functions` is excluded here — it is handled in Phase 3.

**Gate:** §6 per class; hook-snapshot diff must show identical hook names/priorities.

**Progress:** `Assets`, `Post_Types`, `Admin_Interface`, `Network_Admin`, `Customizer`,
`Frontend_Templates`, and `REST_API` are migrated. Each `dev-test` hook comparison found
only the expected callback renames, and both Playwright smoke tests passed after each step.
The Customizer sanitizers were exercised through their registered settings. The REST
comparison also verified all four route registrations, anonymous permission outcomes,
and the releases response. `Artist_Routing` already follows the naming convention and
needed no changes. `Shortcodes` is migrated; the legacy PHP renderer remains callable
as a compatibility wrapper, and all three shortcode tags are unchanged.
`Elementor_Tags` is migrated. The registration hooks and tag/widget IDs were verified
with test doubles because Elementor is not installed on `dev-test`. `CSV_Handler` is
migrated; the form actions, nonce fields, and export output matched the baseline.
`Gutenberg_Blocks` is migrated; all three block names, attribute schemas, and context
declarations matched the baseline. `Virtual_Media` is migrated; the attachment URL,
dimensions, metadata, and REST response matched on `dev-test`. `Release_Model` is
migrated, including its internal callers and registered UPC sanitizer. UPC normalization,
format lookup, and invalid-input results matched the baseline. `Builder_Detection`
is migrated; all five builder detections via stored metadata and the extension filter
matched the baseline. `Discography`, `Release_Meta`, and `Release_Links` already
follow the naming convention. Their shortcode callbacks and the extension-facing
`MDS_RM_Release_Meta::render()` remain callable. No further Phase 2 renames are needed.

**Separate integration follow-up:** WooCommerce checks for a global
`mds_rm_get_or_create_virtual_attachment()` before using its fallback. The main plugin
already exposed only a class method, not that global function. The rename did not
change the WooCommerce fallback.

**Separate follow-up:** The legacy Network Admin form action is `mds_rm_save_network_settings`,
but its save callback is registered on `network_admin_edit_save_network_settings`. Core appends
the form action to `network_admin_edit_`, so the form does not dispatch that callback. This
pre-existing mismatch was left unchanged in the rename. The passing settings smoke test covers
the site settings REST form, not this legacy network form.

### Phase 3 — Split `MDS_RM_Core_Functions`
1. Create `MDS_RM_Logger`, `MDS_RM_Sync`, `MDS_RM_Providers`, `MDS_RM_Template_Loader` per the
   map in §3 — one new class per commit, moving methods and updating all call sites.
2. Keep `MDS_RM_Core_Functions` temporarily as a thin deprecated shim delegating to the new
   classes; delete it in a final commit once greps show zero remaining references.

**Gate:** §6 per commit.

**Progress:** `MDS_RM_Logger` is extracted. The legacy core logging method delegates
to it; debug settings and the `mds_rm_log_message` action and payload are unchanged.
Logger parity, autoloading, the hook comparison, and both smoke tests passed.
`MDS_RM_Sync` is extracted and the legacy core methods delegate to it. On `dev-test`,
a temporary spoke verified artist shadow creation and update, extension hook payloads,
blog context restoration, taxonomy term reuse, and cache purge behavior. The hook
comparison and both smoke tests passed. `MDS_RM_Providers` is extracted and the legacy
core method delegates to it. Defaults, brand color restoration, and proposed option
writes matched the baseline without changing stored test settings. `MDS_RM_Template_Loader`
is next.

### Phase 4 — Functional subfolders
1. Add the explicit class→path map to the autoloader in `mds-release-manager.php` (covering both
   old and new locations during the move).
2. Move files into `core/`, `admin/`, `frontend/`, `blocks/`, `api/`, `integrations/elementor/`
   — one folder per commit, `git mv` only, no content edits in the same commit.
3. Introduce `MDS_RM_Plugin::init()` bootstrap hooked on `plugins_loaded`; the main file becomes
   header + constants + autoloader + one bootstrap call.
4. Update `technical-blueprint.md` §2 directory tree.

**Gate:** §6 per commit; `wp eval 'var_dump( class_exists("MDS_RM_…") );'` for every class.

### Phase 5 — block.json migration
For each block (`release-meta`, `release-links`, `artist-discography`), one block per commit:
1. Create `blocks/{name}/block.json` (apiVersion 3, same block name, attributes, supports,
   textdomain) and move its editor code out of `js/blocks.js` into `blocks/{name}/edit.js`.
2. Switch `MDS_RM_Gutenberg_Blocks` to `register_block_type( MDS_RM_PATH . 'blocks/{name}' )`
   with the existing `render_callback`s.
3. Keep attribute names, defaults, and rendered markup byte-identical — existing post content
   must not invalidate in the editor.
4. Delete `js/blocks.js` only after all three blocks migrate and editor smoke tests pass.

**Gate:** §6 + editor test: open an existing post containing each block → no "block invalid"
errors; re-save produces no markup diff.

### Phase 6 — Template hardening
1. Move the five admin templates to `includes/admin/views/`; render them via a new
   `MDS_RM_Template_Loader::render_admin_view()` that does **not** consult `locate_template()`.
   `templates/` keeps only the two theme-overridable frontend partials.
2. Replace hardcoded admin strings with `esc_html__()/esc_attr__()` + `mds-release-manager`
   text domain; move inline `style=""` attributes into `css/admin-styles.css`.
3. Regenerate the `.pot` file.

**Gate:** §6 + visual check of each admin screen; verify a theme copy of `settings-page.php`
can no longer override the admin view.

### Phase 7 — Type and docblock consistency
1. Add parameter/return type declarations (PHP 8.0-compatible) to untyped classes, one class per
   commit. Be conservative on hook callbacks — WordPress may pass unexpected types; prefer
   widening types or guard clauses over fatals.
2. Normalize docblocks to WordPress PHP Documentation Standards (`@since`, aligned `@param`).

**Gate:** §6 per class.

### Phase 8 — WordPress.org compliance audit
1. Run the Plugin Check plugin and PHPCS/WordPress ruleset; fix all errors, triage warnings.
2. Re-verify `uninstall.php` against the final option inventory from Phase 0.
3. Final full regression: Playwright suite + manual multisite hub/spoke sync test.
4. Update `README.md`, `changelog.md`, and `technical-blueprint.md`; archive stale `.local/`
   reports that describe superseded plans (mark them "historical — superseded by this plan").

**Gate:** Plugin Check clean, PHPCS clean, full suite green, docs accurate.

---

## 8. Explicit non-goals

- No merging of class files (abandoned approach).
- No renaming of classes, hooks, shortcodes, blocks, routes, options, or meta keys.
- No Settings API migration (REST-based saving stays; it also backs the Abilities API).
- No change to the hub/spoke sync model, Elementor widget behavior, or rendered frontend markup.
- No new runtime dependencies (no Composer autoloading, no build pipeline for admin JS;
  block.json blocks use plain ES5/vanilla JS consistent with current `blocks.js`).
