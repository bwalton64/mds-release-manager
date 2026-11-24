=== MDS Release Manager ===
Contributors: bwalton64
Tags: music, discography, artists, releases, streaming
Requires at least: 7.0
Tested up to: 7.x
Requires PHP: 8.0
Stable tag: 1.0.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/old-licenses/gpl-2.0.html

Manage music releases, artist discographies, release metadata, and streaming links in WordPress.

== Description ==

MDS Release Manager adds Release and Artist post types. Organize releases by format and tag, associate releases with artists, and manage UPC/EANs, label information, and streaming links.

Release and Artist pages include default layouts when applicable. Use the Release Meta, Release Links, and Artist Discography blocks or shortcodes to place the same components in your own layouts. CSV import and export help manage larger catalogs.

Without a builder such as Elementor, style the automatic page layouts under Releases > Settings > Default Templates. The automatic Release Meta, Release Links, and Artist Discography blocks use the defaults under Releases > Settings. Builder-authored pages and Releases with active routing redirects do not use the corresponding fallback. Existing blocks and shortcodes suppress duplicate automatic components.

The default icon source is Auto mode: if an active Font Awesome plugin is detected, MDS Release Manager defers to it; otherwise it loads Font Awesome Free 6.7.2 from cdnjs with Subresource Integrity (SRI). You can also switch to a theme-provided source or disable Font Awesome entirely under Releases > Settings.

== Installation ==

1. Upload the `mds-release-manager` folder to `wp-content/plugins`, or upload the plugin ZIP through Plugins > Add New > Upload Plugin.
2. Activate MDS Release Manager. On Multisite, use Network Admin > Plugins > Network Activate.
3. Configure providers under Releases > Settings. Create an Artist, then add a Release with a unique UPC/EAN and a Release Format.

== Frequently Asked Questions ==

= Do I need Elementor or WooCommerce? =

No. The core release and artist tools work without either plugin. The block editor and shortcodes can display metadata, links, and discographies.

= Which shortcodes are available? =

Use `[release-meta]` for release metadata, `[release-links]` for listening and purchase links, and `[artist-discography]` for an Artist's published releases. The release shortcodes accept `release_id` or `upc`; a valid Release ID takes precedence. For example:

<code>[release-meta release_id="123" fields="release_title,upc_ean" gap="12px"]</code>

<code>[release-links release_id="123" order="Spotify,Apple Music" show_audio="0" new_tab="0"]</code>

<code>[artist-discography artist_id="5" striped="1" header_background="#222222" cell_padding_top="10px"]</code>

Each shortcode accepts flat attributes for block options and styling. Release Meta and Release Links also accept `_tablet` and `_mobile` style suffixes. See the bundled <code>README.md</code> for the full attribute lists, accepted values, and examples.

= Can I use this on Multisite? =

Yes. Network activate the plugin and use its network settings to configure the hub site. Integration plugins can supply synchronization targets.

== Changelog ==

= 1.0.0 =

Includes release and artist content types, metadata and streaming links, discography, CSV import/export, and WordPress Abilities API integration. See the bundled `changelog.md` for the full version history.
