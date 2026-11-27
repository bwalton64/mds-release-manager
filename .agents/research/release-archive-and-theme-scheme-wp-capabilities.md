# WordPress 7.x Capabilities & Architecture Scout: Release Archive Page, Designated Page Routing & Intelligent Theme Scheme

## Feature Goal & Constraints

Redesign the **Release Archive Page**, **Default Templates** (retiring the term *"Fallback"*), and **Theme Scheme Detection** in **MDS Release Manager** (`mds-release-manager`, Free Core, WordPress `7.0+`, PHP `8.0+`) to solve 7 requirements:

1. **Unified, Intelligent Theme Scheme Detection:**
   - Single unified **Detected Theme Scheme** banner/control shared across **Default Templates** and **Block Defaults** (no duplicate UI or divergent preset logic).
   - Detect styling not only from unmodified WordPress themes (`theme.json` / Global Styles / Customizer), but also from **Elementor Kits** (`elementor_active_kit` → `_elementor_page_settings`), **Bricks Theme Styles** (`bricks_theme_styles`), and popular theme customizer palettes (Astra, Kadence, GeneratePress).
   - Remove the manual *"Light Scheme Defaults"* / *"Dark Scheme Defaults"* buttons — automatically detect the active theme/builder scheme and apply the matching baseline automatically.
   - **Auto-Reset on Theme or Scheme Change:** Track a persisted **theme/scheme fingerprint** (`theme_stylesheet + variation_hash + builder_kit_hash + resolved_bg/text/accent`). Whenever the active theme, style variation, or builder kit scheme changes, automatically reset designer color customizations back to the newly detected baseline (with a clear admin notice / undo note).
2. **First-Class Release Archive Styling:**
   - Treat **Release Archive** as a dedicated 3rd template panel alongside **Release Page** and **Artist Page** inside **Default Templates**, with full styling controls (layout, grid columns, card surface/border/radius, cover aspect ratio, toolbar/filter styling, pagination, spacing, typography) and a live interactive preview.
3. **Custom Archive URL, Title & Slug Inheritance (e.g. `https://mywebsite/music`):**
   - Allow admins to pick a physical WordPress Page (e.g., `Music` at `/music/`) as the **Release Archive Page** (the proven WooCommerce Shop Page pattern), automatically inheriting its title, slug, permalink, SEO metadata, and page template.
4. **Native WordPress Menu Integration:**
   - Adding the Release Archive Page to Classic Menus (**Appearance → Menus**) and Block Navigation (**Appearance → Editor → Navigation**) works out of the box with zero custom links and full `current-menu-item` active-state highlighting.
5. **Leverage Built-In WordPress Template Selectors:**
   - Leverage **both** WordPress's built-in **Page Template** selector (on the designated Archive Page) AND WordPress 6.7–7.x's native **`register_block_template()`** API for Block Themes (`mds-release-manager//archive-release`, `mds-release-manager//single-release`, `mds-release-manager//single-artist`).
6. **Retire the Term "Fallback" Everywhere:**
   - Replace *"Release Fallback Template"* / *"Artist Fallback Template"* / *"Fallback"* in all UI labels, notices, settings, and docs with clean product terminology: **Release Page Template**, **Artist Page Template**, **Release Archive Template** (or **Default Templates**).

---

## Native WordPress Core Capabilities

| Capability | WP Version | Fit | Notes |
| :--- | :--- | :--- | :--- |
| **`register_post_type()` `has_archive` → `get_page_uri( $page_id )`** | Core (3.0–7.x) | **Exact Fit** | Passing the designated Page's URI string (e.g. `'music'`) to `'has_archive'` dynamically routes `/music/` directly to the `release` CPT archive while keeping single releases at `/release/{slug}/` (or `/music/{slug}/` if desired). Flushing rewrite rules on page selection change keeps permalinks seamless. |
| **`display_post_states` filter** | Core (4.7–7.x) | **Exact Fit** | Appends a `— Release Archive Page` badge next to the chosen page in **Pages → All Pages** (identical to WordPress's `— Posts Page` and WooCommerce's `— Shop Page`). |
| **`register_block_template()` (`WP_Block_Templates_Registry`)** | Core **6.7+ / 7.x** | **Exact Fit** | Registers plugin-owned block templates (`mds-release-manager//archive-release`, `mds-release-manager//single-release`, `mds-release-manager//single-artist`) directly into **Appearance → Editor → Templates** and the post/page **Template** selector! Site admins can customize them visually in the Site Editor, and theme overrides take priority cleanly. |
| **Native Page Template Selector (`theme_page_templates`)** | Core (4.7–7.x) | **High Fit** | When a physical Page is assigned as the Release Archive Page, it appears in **Pages** and supports the theme's built-in **Template** selector (Full Width, Canvas, Elementor Full Width, etc.) and standard **Navigation** / **Menus** pickers automatically. |
| **`wp_get_global_styles()` + `wp_get_global_settings()`** | Core (5.9–7.x) | **High Fit** | Primary source for Block Themes & FSE variations (TT5 Default, Evening, Midnight, etc.). |
| **`switch_theme` & `save_post_wp_global_styles` hooks** | Core (5.9–7.x) | **Exact Fit** | Fires immediately when an admin switches themes or saves a Global Styles variation change — ideal triggers for automatic scheme re-detection & designer override reset. |

---

## Ecosystem Interoperability & Collision Avoidance

### 1. Intelligent Multi-Source Theme Scheme Detection (`ThemeScheme::detect()`)

Right now, `ThemeScheme::detect()` only reads `wp_get_global_styles()` and `get_background_color()`. When a site uses **Elementor**, **Bricks**, or a customizer-driven classic/hybrid theme, `theme.json` often reports default `#ffffff` even though the actual site is dark or branded!

**Recommended Priority Detection Waterfall (each adapter returns normalized `{source, sourceLabel, bg, text, accent, ...}` and stops at the highest-specificity active design system):**

1. **Elementor Active Kit** *(when Elementor is active and `elementor_active_kit` has custom settings)*:
   - Read `get_post_meta( (int) get_option( 'elementor_active_kit' ), '_elementor_page_settings', true )`.
   - Extract:
     - **Background:** `body_background_color` (when `body_background_background === 'classic'` or set) or custom global color matching background.
     - **System Colors (`system_colors`):** `_id === 'primary'` (accent), `_id === 'secondary'`, `_id === 'text'` (body text), `_id === 'accent'`.
     - **Typography:** `body_typography_font_family`, `h1_typography_font_family` (bonus: optional font-family baseline!).
   - Also hook `elementor/document/after_save` (when saving the active Kit post) to trigger the **scheme-change fingerprint check & auto-reset**!
2. **Bricks Builder Theme Styles** *(when Bricks is active and `bricks_theme_styles` has an active global style)*:
   - Read `get_option( 'bricks_theme_styles' )` for the site-wide (`conditions` empty or `entireWebsite`) theme style.
   - Extract `settings.colors` / `settings.typography.body` / `settings.general.siteBackground`.
   - Hook `update_option_bricks_theme_styles` to trigger scheme-change auto-reset.
3. **WordPress Global Styles (`theme.json` + Site Editor Variations):**
   - Existing `wp_get_global_styles()` + `wp_get_global_settings( array( 'color', 'palette' ) )`.
   - Works natively for Twenty Twenty-Five variations, Ollie, Frost, GeneratePress Block, Spectra One, etc.
4. **Popular Classic / Hybrid Theme Mods (Lightweight Zero-Dependency Probes):**
   - **Kadence:** `get_option( 'kadence_global_palette' )` (JSON palette slots `palette1`–`palette9`, where `palette9` = site bg, `palette3` = text, `palette1` = accent).
   - **Astra:** `get_option( 'astra-settings' )` (`site-layout-outside-bg-obj-responsive`, `text-color`, `theme-color`).
   - **GeneratePress:** `get_option( 'generate_settings' )` (`background_color`, `text_color`, `link_color`).
   - **Core Customizer:** `get_background_color()`.
5. **Extensible Filter (`mds_rm_theme_scheme_sources`):**
   - Paid extensions (e.g., `mds-page-builders` for Divi 5 / Bricks deep styles) or child themes can plug into the detection waterfall cleanly via filter.

### 2. Automatic Baseline Reset on Theme / Scheme Change

- Compute a deterministic **`scheme_fingerprint`** hash from:
  `md5( wp_json_encode( [ stylesheet, source, background, text, accent, mode ] ) )`
- Store `mds_rm_active_scheme_fingerprint` in `wp_options`.
- On `switch_theme`, `save_post_wp_global_styles`, `elementor/document/after_save` (Kit), `customize_save_after`, and lazily on admin settings load if the live fingerprint differs from the stored fingerprint:
  1. Clear explicit designer color overrides across **Block Defaults** (`mds_rm_block_defaults`) AND **Default Templates** (`mds_rm_template_defaults` — Release Page, Artist Page, Release Archive).
  2. Update `mds_rm_active_scheme_fingerprint` to the new hash.
  3. Store a dismissible transient notice (`"Theme/scheme changed to {themeName} ({sourceLabel} — {mode}). Designer color overrides were reset to match the new baseline."`) so the admin always knows *why* customizations reset.
- Keep a single **"Reset Customizations to Detected Baseline"** button in the unified Theme Scheme card (replacing the 3 old buttons: *Auto-Match Theme*, *Light Scheme Defaults*, *Dark Scheme Defaults*).

---

## Recommended Native Architecture

### A. Designated Release Archive Page (Solves Requirements #3, #4, and #5 Together)

Follow the exact **WooCommerce Shop Page** model — the gold standard in WordPress:

1. **New Setting (`archive_page_id`, stored in `mds_rm_template_defaults['archive']['page_id']`):**
   - Dropdown of published WordPress Pages (plus a one-click **"Create 'Music' Archive Page"** helper button right beside the selector, or **"None — use default `/release/` archive"**).
   - When an admin selects a page (e.g., **Music**, slug `/music/`):
     - `PostTypes.php` sets `'has_archive' => get_page_uri( $archive_page_id )` (e.g., `'music'`).
     - Rewrite rules flush automatically when `archive_page_id` changes or when that page's `post_name` (slug) is edited (`post_updated` hook).
     - `display_post_states` labels it **`— Release Archive Page`** in **Pages → All Pages**.
     - `get_the_archive_title` / `<title>` (`document_title_parts`) automatically inherit the designated Page's **Title** (`Music`) instead of `Archives: Releases`!
     - If the designated Page contains intro content (text/hero blocks placed in the page editor), optionally render that intro content above the Catalog Explorer (just like WooCommerce's Shop Page description).
2. **Menus (#4) Work Automatically:**
   - Because `/music/` is a real WordPress **Page**, admins add **Music** in **Appearance → Menus** or the **Site Editor Navigation Block** just like any other page — no custom links, and WordPress automatically adds `current-menu-item` when visiting `/music/` (and `current_page_parent` on single releases!).
   - Even if no physical page is selected (using default `/release/`), ensure `show_in_nav_menus => true` is set on the `release` CPT so **"Releases" → "All Releases"** appears natively under **Appearance → Menus → Releases → View All**.
3. **Built-In Template Selector (#5) Works for BOTH Block & Classic Themes:**
   - **Block Themes (WordPress 6.7–7.x):** Register native block templates via `register_block_template()`:
     - `mds-release-manager//archive-release` (*"Release Archive"*) containing Header + Archive Title + `<!-- wp:mds-rm/catalog-explorer /-->` + Footer.
     - `mds-release-manager//single-release` (*"Single Release"*) & `mds-release-manager//single-artist` (*"Single Artist"*) as optional native Site Editor templates!
     - Because they are registered via `register_block_template()`, they appear natively in **Appearance → Editor → Templates** AND in the post/page **Template** selector — completely eliminating the need for hardcoded `archive-release-catalog.php` HTML boilerplate on Block Themes!
   - **Classic / Hybrid Themes:** When a designated Page is selected (or on `/release/`), load the theme's standard `page.php` / selected Page Template (honoring `_wp_page_template` from the built-in Template dropdown!) or clean `archive-release-catalog.php` wrapper.

### B. Dedicated Release Archive Styling Panel (Requirement #2)

Upgrade **Default Templates** (retiring *"Fallback"*) to have **3 peer template panels** under one unified **Detected Theme Scheme** banner:

1. **Release Page** *(formerly "Release Fallback Template")*
2. **Artist Page** *(formerly "Artist Fallback Template")*
3. **Release Archive** *(new full styling & routing panel)*:
   - **Archive Routing & Page Assignment:** Enable Catalog Archive toggle, **Designated Archive Page** picker (e.g., `/music/`), optional intro-content display toggle, and quick link to edit the page or open it in Site Editor.
   - **Grid & Layout:** Columns (2–5), Per Page, Default Sort, Pagination Style (`numbered`, `load_more`, `none`), Container Max Width, Grid Gap, Container Padding.
   - **Card Styling:** Card Background, Card Border Color & Width, Card Radius, Cover Aspect Ratio (`1:1`, `4:3`, `auto`), Shadow (`none`, `subtle`, `elevated`), Hover Lift toggle.
   - **Toolbar & Controls:** Show Search, Show Artist Filter, Show Release Type Filter, Show Media Format Filter, Show Year Filter, Show Sort, Show Audio Play Button, Show Quick Links.
   - **Live Interactive Preview:** Matching the live preview stage already built for Release Page and Artist Page!

### C. Unified Theme Scheme Component (Requirement #1)

Extract a single shared React component (`js/theme-scheme-banner.js` or shared module) rendered at the top of **both** the **Default Templates** tab and the **Block Defaults** tab:
- Displays:
  - Active Theme / Design System badge (e.g., **`Twenty Twenty-Five (Evening)`** or **`Elementor Kit (Dark)`** or **`Bricks Theme Styles (Light)`**).
  - Live swatch strip (`Background`, `Surface`, `Text`, `Border`, `Accent`).
  - Clear status note: *"Auto-applied as your baseline. Switching your theme or color scheme automatically resets designer overrides to match."*
  - Single **"Reset Customizations to Baseline"** button (no *"Light Scheme Defaults"* or *"Dark Scheme Defaults"* buttons).

### D. Retiring "Fallback" (Requirement #6)

Cleanly rename all user-facing strings while keeping internal option keys, CSS classes, and template filenames backward-compatible (zero breaking changes for existing sites):
- `Release Fallback Template` → **`Release Page Template`**
- `Artist Fallback Template` → **`Artist Page Template`**
- `Fallback Page` / `Fallback Layout` → **`Default Release Page`** / **`Default Artist Page`**
- `Release Archive` → **`Release Archive Page`**

---

## Build-vs-Reuse Decisions & Open Questions

| Decision | Verdict | Rationale |
| :--- | :--- | :--- |
| **Designated Archive Page (`/music/`)** | **Adopt WooCommerce Shop Page Pattern** | Native WordPress `has_archive => get_page_uri($page_id)` + `display_post_states`. Zero custom rewrite hacks; solves URL slug, page title, SEO, nav menus, and page template selector in one stroke. |
| **Block Theme Archive & Single Templates** | **Adopt WP 6.7+ `register_block_template()`** | Native WordPress 7.x API; exposes templates in Site Editor & Template Selector; users can customize visually without plugin updates clobbering changes. |
| **Elementor & Bricks Scheme Detection** | **Read Kit / Theme Styles directly in PHP** | Lightweight (`get_post_meta` / `get_option`), zero external dependencies, instant detection with automatic reset hook on Kit save. |
| **Remove Light/Dark Manual Preset Buttons** | **Auto-Detect Only + Single Reset Button** | Eliminates redundant manual buttons and aligns with the baseline-reset workflow. |

### Open Questions Before Writing the Implementation Plan
1. **Single Release URL when Archive Page is `/music/`:** When an admin picks `/music/` as the Release Archive Page, should single releases stay at `/release/album-name/` (most stable for existing SEO/links) or optionally follow `/music/album-name/`? *(Recommended: keep single releases at `/release/album-name/` by default to avoid breaking existing permalinks, with an optional checkbox if they want single releases under `/music/` too.)*
2. **Auto-Reset Scope on Theme/Scheme Switch:** When the theme or builder color scheme changes, should auto-reset clear **color/surface overrides only** (preserving structural choices like columns, layout split vs. stacked, visible fields, and component order), or reset **all** template & block customizations? *(Recommended: reset **color & visual surface overrides** so the designer keeps their layout/columns/field order while instantly inheriting the new theme's palette — or let them hit "Reset All" if they want layout reset too.)*
3. **WordPress 7.x `register_block_template()` Scope:** Should we register `mds-release-manager//archive-release` first (for the Release Archive), or register all three (`archive-release`, `single-release`, `single-artist`) in the Site Editor?
