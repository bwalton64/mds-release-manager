# WordPress 7.x Capabilities & Architecture Scout: Release Manager Top-Level Menu & Dashboard

## Feature Goal & Constraints

1. Rename the top-level WordPress admin menu from **Releases** to **Release Manager** (keeping the official MDS SVG logo `BrandIcon::data_uri()`).
2. Create a dedicated **Dashboard** page (`admin.php?page=mds-release-manager`) and make it the **default landing page** when clicking **Release Manager**, positioned at the very top of the inner submenu.
3. Preserve all existing CPTs (`release`, `artist`), taxonomies (`release_type`, `release_media_format`, `release_tag`), Settings, Import/Export, Multisite Hub/Spoke behavior, Section 508 accessibility, and Clean-Core UI rules (zero nag banners or hardcoded upsells).

---

## Native WordPress Core Capabilities

| Capability | WP Version | Fit | Notes |
| :--- | :--- | :--- | :--- |
| **`add_menu_page()` + `add_submenu_page()`** | Core (3.0–7.x) | **Exact Fit** | Register top-level `'mds-release-manager'` with label `'Release Manager'` and `BrandIcon::data_uri()`, then register the first submenu with the identical slug `'mds-release-manager'` titled `'Dashboard'` so clicking the top-level menu opens the Dashboard directly. |
| **`show_in_menu => false` on `release` & `artist` CPTs** | Core (3.0–7.x) | **Exact Fit** | Prevents `release` from hijacking the top-level menu slot; instead we explicitly attach `edit.php?post_type=release` (*All Releases*), `edit.php?post_type=artist` (*All Artists*), and all 3 release taxonomies (`edit-tags.php?taxonomy=...&post_type=release`) beneath `'mds-release-manager'`. |
| **`parent_file` & `submenu_file` filters** | Core (4.4–7.x) | **Exact Fit** | Ensures that when viewing/editing a Release, Artist, or Release Taxonomy (`post.php`, `post-new.php`, `edit.php`, `edit-tags.php`, `term.php`), WordPress keeps **Release Manager** expanded and highlights the exact active submenu item instead of collapsing or highlighting Posts. |
| **`@wordpress/components` (`Card`, `CardHeader`, `CardBody`, `CardFooter`, `Button`, `Notice`)** | Core (5.9–7.x) | **Exact Fit** | Gives the Dashboard a native WordPress Admin Design Language look that inherits core focus rings, spacing, and accessibility automatically. |
| **WordPress REST API (`GET /mds/v1/dashboard`)** | Core (4.7–7.x) | **High Fit** | Lightweight cached/SQL-backed summary endpoint (or initial server-localized payload + refresh button) for KPI counts, Catalog Health completeness checks, recent releases, and active Theme Scheme / Archive status. |
| **Neutral `SlotFill` / `do_action( 'mds_rm_dashboard_cards' )`** | Core (5.0–7.x) | **Exact Fit** | Allows installed paid companion plugins (`mds-audio-providers`, `mds-metadata`, `mds-commerce`, `mds-page-builders`) to register their own Dashboard status cards without Core ever referencing or upselling paid plugins. |

---

## Ecosystem Interoperability & Zero-Breakage Migration

### 1. Submenu Audit Across All 5 MDS Plugins
- `mds-audio-providers`, `mds-commerce`, `mds-metadata`, and `mds-page-builders` all integrate into Core's Settings tabs rather than hardcoding `edit.php?post_type=release` as a submenu parent.
- Internal Core references to update from `'edit.php?post_type=release'` → `'mds-release-manager'`:
  - `src/Core/PostTypes.php` (`release` and `artist` `show_in_menu => false`)
  - `src/Admin/AdminInterface.php` (`register_settings_page()`, `organize_releases_submenu()`, `settingsUrl` / `settings_url` links)
  - `src/Admin/ImportExportHandler.php` (parent slug + redirect URL)
  - `src/Core/Security/CredentialsAdmin.php` (settings links)
  - `src/Frontend/Assets.php` (enqueue hook checks: `release-manager_page_mds-release-settings`, `toplevel_page_mds-release-manager`, etc.)
- **Backward Compatibility Safeguards:**
  1. In `organize_releases_submenu()` (priority `999`), automatically migrate any third-party submenu items still registered under `'edit.php?post_type=release'` into `'mds-release-manager'`.
  2. On `admin_init`, safely redirect legacy bookmark URLs (`edit.php?post_type=release&page=mds-release-settings` or `mds-release-csv`) to `admin.php?page=mds-release-settings` / `admin.php?page=mds-release-csv`.

### 2. Clean Submenu Order Under **Release Manager**
1. **Dashboard** *(default landing page — `admin.php?page=mds-release-manager`)*
2. **All Releases** (`edit.php?post_type=release`)
3. **All Artists** (`edit.php?post_type=artist`)
4. **Release Types** (`edit-tags.php?taxonomy=release_type&post_type=release`)
5. **Media Formats** (`edit-tags.php?taxonomy=release_media_format&post_type=release`)
6. **Release Tags** (`edit-tags.php?taxonomy=release_tag&post_type=release`)
7. *(Any companion / third-party submenu items)*
8. **Settings** (`admin.php?page=mds-release-settings`)
9. **Import / Export** (`admin.php?page=mds-release-csv`)

---

## Recommended Dashboard UX Layout

Built with native React (`@wordpress/components` `Card`, `CardHeader`, `CardBody`, `CardFooter`, `Button`) and zero custom build bloat (`js/dashboard.js`, matching `js/template-defaults.js` and `js/settings-defaults.js`):

1. **Top Brand Header:**
   - Official MDS SVG logo (`.mds-brand-icon`), **Release Manager** heading, version badge, Multisite Hub/Spoke badge (on Multisite), and primary Quick Actions (**Add New Release**, **Add New Artist**, **Import / Export**, **Settings**).
2. **Row 1 — 4 KPI Summary Cards:**
   - **Releases:** Total published + drafts count → links to All Releases.
   - **Artists:** Total published count → links to All Artists.
   - **Catalog Completeness:** Percentage score of published releases that have Cover Art, UPC/EAN, and at least one Streaming/Buy link.
   - **Release Archive:** Shows active archive URL (e.g., designated Page slug or `/release/`), Enabled/Disabled status, and quick link to **View Archive** or **Customize**.
3. **Row 2 — Two-Column Operational Grid:**
   - **Left Column:**
     - **Catalog Health & Attention Needed:** Actionable breakdown of releases missing Cover Art, missing UPC/EAN, missing Streaming/Buy links, or missing Artist assignment (with direct links to review/edit, or a clean green *"All releases look complete!"* state).
     - **Recent Releases:** Latest 5 releases with cover art thumbnail (or `.mds-brand-icon` placeholder), title, artist name, year, UPC, and Edit / View actions.
   - **Right Column:**
     - **Active Theme Scheme & Templates:** Compact summary of `ThemeScheme::detect()` (Theme Name, Detection Source e.g. Elementor Kit / WordPress Global Styles, Light/Dark mode, 5 color swatches) + quick links to **Default Templates**, **Block Defaults**, and **Site Editor** (on Block Themes).
     - **Multisite Hub & Spoke Status** *(Multisite only)*: Shows Hub/Spoke role, connected Spoke count, and link to Network Settings.
     - **Extension Dashboard Slot (`mds_rm_dashboard_cards`):** Neutral container where installed paid companion plugins can mount their own status cards (hidden when empty).

---

## Build-vs-Reuse Decisions & Open Questions

- **Reuse existing `ThemeScheme::detect()`, `BrandIcon`, `TemplateDefaults::get( 'archive' )`, `Sync::is_hub()`:** Zero duplicated logic.
- **Fast SQL queries (`COUNT` + lightweight `WP_Query` of 5 recent releases):** No full-catalog memory loads; fast even on 10,000-release label sites.
- **Capability:** Top-level menu & Dashboard use `edit_posts` (so editors/catalog managers can access Dashboard, Releases, and Artists), while **Settings** and **Import / Export** remain gated to `manage_options`.
