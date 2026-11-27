# Music Creator Profile & EPK Manager — WordPress Core & Ecosystem Capabilities

**Topic Slug:** `artist-profiles`  
**Target Compatibility:** WordPress `7.0+` (Tested up to `7.x`) · PHP `8.0+` · Multisite (`Network: true`)  
**Date:** 2026-11-27

---

## 1. Feature Goal & Constraints

Build a new **Free Tier** WordPress.org-compliant plugin serving **all 9 music creator archetypes** (recording artists/bands, beatmakers/producers, DJs/electronic acts, session musicians/engineers, songwriters/composers/sync, classical/sheet-music creators, live/event entertainers, music educators/streamers, and record labels/managers) — working both **100% standalone** and deeply integrated with `mds-release-manager`:

1. **Creator-Type Onboarding & 75+ Platform Registry:** Admins select one or more creator archetypes to filter a built-in catalog of 75+ music platforms (plus unlimited custom platforms) down to the exact starter kit relevant to their career.
2. **Admin Profile Command Center:** Provision/Claim links, 1-click Edit deep-links, onboarding status (`Not Started -> Claimed -> Active -> Paused`), platform constraint cards (bio char caps, link slots, embed support), Drift Detection, and 1-click Copy-Ready Bio / Discography / Press Kit Prep tools.
3. **Visitor-Facing Profile Directory & Link-in-Bio Hub:** Theme-adaptive Gutenberg block + shortcode with optional Interactivity API category filtering, IndieWeb `rel="me"` verification, and collision-free **Schema.org `MusicGroup` / `Person` / `Organization` `sameAs` JSON-LD**.
4. **Standards & Clean-Core Compliance:** WordPress 7.0 Abilities API (`meta.mcp.public`), Section 508 / WCAG 2.1 AA, zero external npm/Composer bloat, `LF` line endings, and zero nag banners or locked Pro toggles.

---

## 2. Native WordPress Core Capabilities

| Capability | WP Version | Fit | Implementation Notes |
| :--- | :--- | :--- | :--- |
| **Post Meta (`register_post_meta`) + Site Options** | WP 4.6+ / 7.0+ | **High (Primary Storage)** | When `mds-release-manager` is active, attach directly to `artist` CPT via `_mds_cp_roles`, `_mds_cp_profiles`, `_mds_cp_press_kit`, `_mds_cp_source_updated_at` (`show_in_rest => true` + JSON Schema). When standalone, store in site option `mds_cp_standalone_profile`. Zero custom tables needed. |
| **DataViews (`@wordpress/dataviews`)** | WP 6.6+ / 7.0+ | **High (Admin Command Center)** | Essential at **75+ platforms**: gives admins instant search, facet filters by **Creator Role**, **Category** (`EPK`, `DSP`, `Beats/DJ`, `Session/Credits`, `Sync/Scores`, `Live/Booking`, `Link-in-Bio`, `Monetization`, `Registries`), and **Status** (`Not Started`, `Claimed`, `Active`, `Needs Update`), plus inline quick-actions (**Open Edit Portal**, **Copy Bio**, **Mark Verified**). |
| **Block Editor (`block.json` API v3) + `@wordpress/components`** | WP 6.3+ / 7.0+ | **High** | Register Visitor Profile Directory / Link-in-Bio block with InspectorControls, `TabPanel`, `Modal`, and `wp.a11y.speak()` Section 508 announcements. |
| **Block Bindings API** | WP 6.5+ / 7.0+ | **Medium–High** | Custom binding source (`mds-cp/profile-field`) lets core Button, Paragraph, Heading, and Image blocks bind dynamically to short bio, booking email, primary EPK URL, or BeatStars/ReelCrafter URL. |
| **Interactivity API (`@wordpress/interactivity`)** | WP 6.5+ / 7.0+ | **High** | Zero-jQuery declarative category filter pills on the visitor frontend (e.g., *All / Listen / Beats & Store / Credits / EPK & Booking / Support*) with fast SSR and tiny client hydration. |
| **WordPress Abilities API (`wp_register_ability`)** | WP 7.0+ | **High (Automation Bridge)** | Expose `get-creator-profiles`, `get-press-kit-bundle`, and `list-stale-profiles` with `meta.mcp.public = true`. Enables external AI/MCP workflows (including Linktree, Beacons, JamBase, and Chartmetric MCP servers) to read the WordPress SSOT cleanly. |
| **`WP_HTML_Tag_Processor`** | WP 6.2+ / 7.0+ | **Medium** | Safely inject `rel="noopener noreferrer me"` and accessible `aria-label` attributes onto rendered links without regex. |
| **Native `ZipArchive` + WP Filesystem** | PHP 8.0+ / WP Core | **High (Press Kit Export)** | Generate downloadable Press Kit ZIP bundles (hi-res photos + trimmed bios + discography/credits sheet) with capability/nonce checks and HTML/Markdown fallback if `ZipArchive` is disabled. |
| **Global Styles (`wp_get_global_styles`) / `ThemeScheme`** | WP 6.1+ / 7.0+ | **High** | Reuse `ExtensionApi::get_theme_scheme()` (`--mds-theme-*`) when `mds-release-manager` is active; fall back to native `wp_get_global_styles()` luminance detection when standalone. |

---

## 3. Ecosystem Interoperability & Collision Avoidance

| Ecosystem Plugin / Standard | Collision Risk | Strategy (Reuse / Hook vs. Standalone Fallback) |
| :--- | :--- | :--- |
| **MDS Release Manager (`mds-release-manager`)** | Duplicate `artist` CPT or conflicting meta | Hook `mds_rm_loaded`. Attach to existing `artist` CPT (`_mds_artist_uuid`), add Command Center tab via `mds_rm_site_settings_tabs`, save via `mds_rm_save_artist_meta`, and pull live discography via `ReleaseModel` / `ReleaseSearch`. |
| **Yoast SEO / Rank Math SEO** | Duplicate Schema.org JSON-LD | Hook `wpseo_schema_graph_pieces` / `rank_math/json_ld` to merge verified external URLs into `sameAs`. Choose appropriate `@type` by Creator Role (`MusicGroup` for bands/artists, `Person` for composers/engineers/session/educators, `Organization` for record labels). |
| **Standalone (No SEO Plugin)** | Missing structured data | Emit a clean, toggleable `<script type="application/ld+json">` node matching the creator's Schema.org `@type` + `sameAs` array. |
| **ArtistKit / Linktree-clone / EPK plugins** | Rewrite slug collision (`/epk`, `/links`) | Default to Gutenberg blocks and shortcodes placed on any user-chosen page; keep any optional EPK/Link-in-Bio route opt-in and filterable. |
| **WPML / Polylang** | Untranslatable labels & bios | Ship `wpml-config.xml` covering custom platform labels, bios, and press-kit copy. |

---

## 4. Recommended Native Architecture

### 4.1 Platform Registry & Role-Based Filtering (`src/Core/PlatformRegistry.php`)
- Stores **75+ canonical platform definitions** in a lightweight, versioned PHP registry (zero DB queries, zero outbound HTTP calls):
  - `slug`, `name`, `category` (9 categories), `creator_roles` (array of matching archetypes from the 9 roles), `public_url_template`, `signup_url`, `edit_url`, `bio_max_chars`, `max_links`, `supports_html_embed`, `supports_rel_me`, `api_tier` (`prep_only` | `read_api` | `webhook` | `oauth_push` | `mcp`), `icon_svg`.
- Filterable via a neutral registry hook so users or companion plugins can add niche regional/genre portals cleanly.

### 4.2 Multisite Hub-and-Spoke Behavior
- When `mds-release-manager` is active on Multisite, respect `ExtensionApi::is_hub()`: Hub owns profile authoring and Command Center editing; Spokes receive public profile arrays and press-kit copy via `mds-release-manager`'s existing Hub-to-Spoke sync, while private admin notes can be excluded on spokes via `mds_rm_sync_excluded_meta_keys`.

### 4.3 Neutral Extension Surface (for Paid MDS Plugins)
- `loaded` action with versioned `ExtensionApi` facade.
- `platform_registry` filter (register additional platforms).
- `sync_providers` filter (register automated API/MCP/webhook sync providers implementing `ProfileSyncProviderInterface`).
- `press_kit_payload` filter (allows `mds-metadata`, `mds-audio-providers`, `mds-commerce`, and `mds-page-builders` to enrich profiles, bios, previews, merch links, and builder Dynamic Data tags).
