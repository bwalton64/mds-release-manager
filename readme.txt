=== MDS Release Manager ===
Contributors: bwalton64
Tags: music, discography, artists, releases, streaming
Requires at least: 6.9
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 1.0.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/old-licenses/gpl-2.0.html

Manage music releases, artist discographies, release metadata, and streaming links in WordPress.

== Description ==

MDS Release Manager adds Release and Artist post types. Organize releases by format and tag, associate releases with artists, and manage UPC/EANs, label information, and streaming links.

Release and Artist pages include default layouts when applicable. Use the Release Meta, Release Links, and Artist Discography blocks or shortcodes to place the same components in your own layouts. CSV import and export help manage larger catalogs.

The default icon source is Font Awesome Free 6.4.0, bundled locally with its [upstream license](https://fontawesome.com/license/free). The [upstream source](https://github.com/FortAwesome/Font-Awesome/tree/6.4.0) is available on GitHub.

== Installation ==

1. Upload the `mds-release-manager` folder to `wp-content/plugins`, or upload the plugin ZIP through Plugins > Add New > Upload Plugin.
2. Activate MDS Release Manager. On Multisite, use Network Admin > Plugins > Network Activate.
3. Configure providers under Releases > Settings. Create an Artist, then add a Release with a unique UPC/EAN and a Release Format.

== Frequently Asked Questions ==

= Do I need Elementor or WooCommerce? =

No. The core release and artist tools work without either plugin. The block editor and shortcodes can display metadata, links, and discographies.

= Which shortcodes are available? =

Use `[release-meta]` for release metadata, `[release-links]` for listening and purchase links, and `[artist-discography]` for the artist's published releases.

= Can I use this on Multisite? =

Yes. Network activate the plugin and use its network settings to configure the hub site. Integration plugins can supply synchronization targets.

== Changelog ==

= 1.0.0 =

Includes release and artist content types, metadata and streaming links, discography, CSV import/export, and WordPress Abilities API integration. See the bundled `changelog.md` for the full version history.
