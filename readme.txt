=== MDS Release Manager ===
Contributors: bwalton64
Tags: music, discography, artists, releases, streaming
Requires at least: 7.0
Tested up to: 7.x
Requires PHP: 8.0
Stable tag: 2.0.1
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/old-licenses/gpl-2.0.html

Manage music releases, artist discographies, release metadata, streaming links, and audio previews in WordPress.

== Description ==

MDS Release Manager adds dedicated **Releases** and **Artists** post types to WordPress. Organize your catalog with **Release Types** (Single, EP, Album, Digital), editable **Media Formats** (Digital Download, CD, Vinyl, Cassette, etc.), and **Release Tags**, and manage UPC/EANs, record labels, distributors, streaming links, multi-format Buy Now links, and audio previews from a unified workspace.

Four Gutenberg blocks (under the **MDS Release Manager** category) and matching shortcodes let you display catalog data anywhere:

* **Release Meta** (`mds-rm/release-meta` / `[release-meta]`) — Release year, label, distributor, UPC/EAN, format, tags, and more with a visual Font Awesome & Dashicons picker.
* **Release Links** (`mds-rm/release-links` / `[release-links]`) — Streaming service buttons and a multi-format Buy Now modal or direct link.
* **Release Player** (`mds-rm/release-player` / `[release-player]`) — Mobile-first, zero-bandwidth Just-in-Time audio preview player with optional tracklist and sequential EP/album sampler crossfade.
* **Artist Discography** (`mds-rm/artist-discography` / `[artist-discography]`) — Responsive, paginated discography table.

Release and Artist pages automatically render theme-aware fallback layouts styled under **Releases > Settings > Default Templates** and **Releases > Settings > Block Defaults** with real-time Live Preview. Unset colors automatically adapt to your active theme's light or dark color scheme (including Twenty Twenty-Five variations such as Default, Evening, and Midnight). Builder-authored pages (such as Elementor) and releases with active page-routing redirects bypass the fallback automatically.

On WordPress Multisite, Network Activate the plugin to run a **Hub-and-Spoke** catalog: author Releases and Artists once on your Master Hub site and broadcast updates to read-only Spoke sites with zero image duplication via Virtual Media. Built-in Cache Management automatically purges 9 popular WordPress caching plugins, and CSV Import/Export streamlines bulk catalog updates.

== Installation ==

1. Upload the `mds-release-manager` folder to `wp-content/plugins`, or upload the plugin ZIP through **Plugins > Add New > Upload Plugin**.
2. Activate **MDS Release Manager**. On Multisite, use **Network Admin > Plugins > Network Activate** and select your Master Hub and Spoke sites under **Network Admin > Settings > MDS Release Manager**.
3. Configure streaming providers, icons, and styling under **Releases > Settings**.
4. Create an Artist under **Releases > Artists**, then add a Release under **Releases > Add Release** with a unique UPC/EAN and Release Type.

== Frequently Asked Questions ==

= Do I need Elementor or WooCommerce? =

No. All core release, artist, block, shortcode, player, fallback template, and CSV features work standalone. If Elementor is active, dedicated MDS widgets and dynamic tags register automatically. Optional companion plugins add WooCommerce, Bandcamp, and external Audio Provider integrations.

= Which shortcodes are available? =

Use `[release-meta]`, `[release-links]`, `[release-player]`, and `[artist-discography]`. Release shortcodes automatically detect the current Release (or latest Release on Artist pages) and accept `release_id` or `upc`:

`[release-meta release_id="123" fields="release_title,release_type,upc_ean" gap="12px"]`

`[release-links release_id="123" order="Spotify,Apple Music,__buy_now" buy_now_mode="picker"]`

`[release-player release_id="123" show_tracklist="1" max_width="480px" shadow="subtle"]`

`[artist-discography artist_id="5" striped="1" header_background="#222222"]`

See the bundled `USER-MANUAL.md` for full attribute tables and examples.

= How are icons loaded? =

By default, **Font Awesome Integration** runs in **Auto** mode: if an active Font Awesome plugin is detected, MDS Release Manager defers to it; otherwise it loads Font Awesome Free 6.7.2 from cdnjs with Subresource Integrity (SRI). You can switch to a theme-provided source or disable Font Awesome under **Releases > Settings > General Settings**.

= Can I use this on WordPress Multisite? =

Yes. Network activate the plugin and configure your Master Hub and Spoke sites under **Network Admin > Settings > MDS Release Manager**. Spokes remain read-only so your Hub stays the single source of truth.

== Changelog ==

= 2.0.1 =

* Fixed Network Admin settings save (`NetworkAdmin::register_hooks()`) where the form action name did not match the hooked `network_admin_edit_mds_rm_save_network_settings` handler.
* Added centralized native WordPress `Update URI` GitHub Releases updater (`GitHubUpdater` + `ExtensionApi::register_github_updater()`) supporting private repos via `MDS_RM_GITHUB_TOKEN` and public repos with zero token.

= 2.0.0 =

* Introduced the versioned `MDS\ReleaseManager\Api\ExtensionApi` (`2.0.0`) facade, Unified Audio API (`AudioApi`), and mobile-first **Release Player** (`mds-rm/release-player` / `[release-player]`) with built-in HTML5 preview fallback.
* Added automatic theme color-scheme detection (`ThemeScheme`) via WordPress Global Styles (`theme.json`), sticky real-time **Live Preview** and starter presets (**Auto-Match Theme**, **Twenty Twenty-Five — Light**, **Twenty Twenty-Five — Evening**) across both **Block Defaults** and **Default Templates**, and a unified theme-swatch color picker across all four Gutenberg blocks.
* Upgraded Hub-and-Spoke Multisite synchronization with a transport-agnostic `SyncTransportInterface`, asynchronous `SyncQueue` (Action Scheduler with WP-Cron fallback), and lifecycle mirroring (`trash`/`untrash`/`delete`).
* Replaced legacy Release and Artist meta boxes with tabbed native React workspaces, added Live Preview modals, Cache Management (9 cache plugins), 2-tier Artist/Release routing, and Media Formats multi-format Buy Now modals.
* Retired the legacy `Audio Player Manager` tab and `MDS_RM_*` global class aliases; see `changelog.md` for full details.

= 1.0.0 =
Includes Release and Artist content types, Release Meta / Release Links / Release Player / Artist Discography blocks and shortcodes, theme-aware Default Templates and Block Defaults with Live Preview, Hub-and-Spoke Multisite sync with Virtual Media, CSV import/export, 9-plugin Cache Management, and WordPress Abilities API integration. See the bundled `changelog.md` for the full version history.
