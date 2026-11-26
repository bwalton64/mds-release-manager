# MDS Release Manager

Comprehensive WordPress music catalog, artist discography, streaming links, and unified audio preview manager — built for single-site installs and Hub-and-Spoke WordPress Multisite networks.

- **Version:** `2.1.0` (Extension API `2.0.0`)
- **Requirements:** WordPress `7.0+` (Tested up to `7.x`) · PHP `8.0+`
- **License:** GPL-2.0-or-later

> Looking for end-user and site-admin instructions? See [USER-MANUAL.md](USER-MANUAL.md).  
> Looking for version history? See [changelog.md](changelog.md).  
> Looking for deep architectural contracts? See [.agents/technical-blueprint.md](.agents/technical-blueprint.md).

---

## Features

- **Releases & Artists CPTs:** Dedicated `release` and `artist` post types with **Release Types** (`release_type`), **Media Formats** (`release_media_format`), and **Release Tags** (`release_tag`), plus React-powered **Release Manager** and **Artist Manager** workspaces with Live Preview modals.
- **4 Gutenberg Blocks & Matching Shortcodes:**
  - `mds-rm/release-meta` / `[release-meta]` — Structured metadata with visual Font Awesome / Dashicons picker.
  - `mds-rm/release-links` / `[release-links]` — Streaming links and multi-format **Buy Now** modal (`picker` or `direct`).
  - `mds-rm/release-player` / `[release-player]` — Mobile-first, zero-bandwidth JIT audio preview player with sequential EP/album sampler crossfade.
  - `mds-rm/artist-discography` / `[artist-discography]` — Paginated, styleable artist discography table.
- **Theme-Adaptive Styling:** Automatic WCAG-aware theme color-scheme detection (`ThemeScheme`) for light and dark themes (including Twenty Twenty-Five variations), centralized **Default Templates**, and **Block Defaults** with sticky Live Preview.
- **2-Tier Artist & Release Routing:** Artist-level default release policies with per-release overrides and extension routing hooks.
- **Hub-and-Spoke Multisite Sync:** Diff-based broadcast with read-only spokes, asynchronous `SyncQueue`, and zero-duplication `VirtualMedia`.
- **Import/Export (CSV, JSON & XML), Cache Management & Abilities API:** 3-step React Import Wizard with Smart/AI column mapping, Duplicate/Identical-Record skipping, Hub+Spoke Purge & Reload, multi-format CSV/JSON/XML export & lossless backup restore, 9-plugin coalesced cache purging, Elementor widgets/tags, native GitHub Releases updater, and WordPress Abilities API (`mds/v1`).

---

## Installation

1. Clone or copy this repository into `wp-content/plugins/mds-release-manager/`.
2. Install PHP dependencies and generate the Composer autoloader:
   ```bash
   composer install
   ```
3. Activate **MDS Release Manager** in WordPress (**Network Activate** on Multisite).
4. Configure settings under **Releases → Settings** (and **Network Admin → Settings → MDS Release Manager** on Multisite).

---

## Local Development (Windows 11 / LocalWP)

```bash
# Install PHP dev tools (WPCS 3 + PHPCompatibilityWP)
composer install

# Run WordPress Coding Standards & PHP 8.0 compatibility checks
composer phpcs
composer phpcbf

# Run standalone PHP unit tests
php tests/theme-scheme.php
php tests/audio-api.php
php tests/block-defaults.php
php tests/block-styles-parity.php
php tests/cache.php
php tests/release-search.php
php tests/settings-save.php
php tests/settings-tabs.php
php tests/sync.php
php tests/uninstall.php

# JS/CSS linting & Playwright E2E (requires Node 22+)
npm install
npm run lint:js
npm run lint:css
npm run test:e2e
```

---

## Companion Plugin Integration

Companion plugins (such as `mds-woocommerce`, `mds-bandcamp-integration`, and `mds-audio-providers`) integrate via `MDS\ReleaseManager\Api\ExtensionApi` (`MDS_RM_API_VERSION = '2.0.0'`) and `mds_rm_*` hooks:
- Direct context resolution: `mds_rm_release_context_post` (resolves external posts like WooCommerce products directly to a `WP_Post` release) and `mds_rm_release_meta_context_upc`.
- Settings & UI extensibility: `mds_rm_site_settings_tabs`, `mds_rm_network_settings_tabs`, `mds_rm_register_custom_meta_boxes`, `mds_rm_dynamic_block_context_attrs`.
- Audio & Sync extensibility: `mds_rm_audio_providers`, `mds_rm_audio_manifest`, `mds_rm_sync_target_sites`, `mds_rm_spoke_protected_meta_keys`, `mds_rm_after_shadow_sync`, `mds_rm_purge_cache`.

See [.agents/technical-blueprint.md](.agents/technical-blueprint.md) for the complete hook, REST, and option contract.
