# MDS Release Manager

A comprehensive, extensible WordPress plugin for managing artist releases, discographies, and digital asset distribution. Designed for independent artists, record labels, and music platforms to showcase releases with metadata, streaming links, and customizable templates.

## Overview

**MDS Release Manager** transforms WordPress into a powerful music release management system. It provides native post types for Releases and Artists, advanced metadata handling, responsive content templates, Gutenberg/Elementor integrations, and WordPress Abilities API support for programmatic access.

### What Problem Does It Solve?

- **Scattered Metadata:** Centralizes release information (UPC, format, links) in a structured database
- **Template Limitations:** Provides flexible, builder-friendly templates for release and artist pages
- **Limited Integrations:** Natively supports Gutenberg, Elementor, and shortcodes
- **Multisite Complexity:** Offers network-wide sync and configuration for WordPress Multisite
- **API Access:** Exposes core features via REST API and WordPress Abilities API (MCP tools)

---

## Key Features

### Core Release Management
- ✅ **Custom Post Types:** Native "Release" and "Artist" post types with full WordPress integration
- ✅ **Structured Metadata:** UPC/EAN, format (Single/EP/Album/Digital), artist associations, and release dates
- ✅ **Release Links:** Manage links to streaming platforms (Spotify, Apple Music, Bandcamp, etc.) with per-provider configuration
- ✅ **Discography:** Automatically curated artist discographies with pagination and responsive layouts

### Frontend & Templates
- ✅ **Fallback Templates:** Smart `the_content` fallbacks for Release and Artist pages with responsive imagery
- ✅ **Gutenberg Blocks:** Native Release Meta, Release Links, and Discography blocks with per-field styling
- ✅ **Elementor Widgets:** Companion Elementor widgets and dynamic tags for builder flexibility
- ✅ **Shortcodes:** `[release-meta]`, `[release-links]`, `[artist-discography]` with responsive options
- ✅ **Builder Detection:** Automatically suppresses fallback rendering on builder-authored pages

### Customization & Control
- ✅ **Customizer Controls:** Dedicated theme customizer panel for Release and Artist appearance
- ✅ **Audio Player Support:** Native and HTML5 audio-player settings for release previews
- ✅ **Responsive Design:** Mobile-first styling with device-specific column and cell filters
- ✅ **Artist Routing:** Optional temporary routing to official artist homepages with editor warnings

### Multisite & Network
- ✅ **Network Sync:** Synchronize releases across WordPress Multisite installations
- ✅ **Network Settings:** Centralized hub site and wipe-on-uninstall configuration
- ✅ **Per-Site Control:** Individual site customization with network defaults

### API & Integration
- ✅ **REST API:** Full REST API endpoints for programmatic access to releases and settings
- ✅ **WordPress Abilities API:** WordPress 6.9+ support with 4 native MCP tools for Claude/external clients
- ✅ **Hook Registry:** Extensible action/filter system for safe third-party integration
- ✅ **CSV Import/Export:** Bulk release import/export with format validation

---

## Prerequisites

### System Requirements
- **WordPress:** 6.9+
- **PHP:** 8.0+
- **Theme:** Any WordPress theme (uses fallback rendering as needed)

### Optional Dependencies
- **Gutenberg/Block Editor:** For native block support
- **Elementor:** For Elementor widget integration (Free or Pro)
- **LiteSpeed Cache:** Automatic cache purge on release updates

### Multisite Support
- Fully compatible with WordPress Multisite networks
- Network activation recommended for hub+spoke setups

---

## Installation

### Windows/Cross-Platform Setup

#### Option 1: Manual Installation (Recommended for Development)

1. **Download the Plugin**
   ```bash
   cd C:\Users\[YourName]\Local Sites\[SiteName]\app\public\wp-content\plugins\
   git clone https://github.com/bwalton64/mds-release-manager.git
   cd mds-release-manager
   ```

2. **Verify PHP Requirements**
   ```powershell
   php -v  # Verify PHP 8.0+
   php --ini  # Confirm debugging configuration
   ```

3. **No Build Step Required**
   The plugin has no Composer or npm runtime dependencies. npm dependencies are used only for development smoke tests.

4. **Activate in WordPress**
   - WordPress Admin → Plugins → Find "MDS Release Manager"
   - Click **Activate**
   - For Multisite: Use network activation via Network Admin → Plugins

#### Option 2: Upload via WordPress Admin

1. WordPress Admin → Plugins → Add New
2. Click **Upload Plugin**
3. Select the plugin ZIP file
4. Click **Install Now**
5. Click **Activate**

#### Option 3: Using Local by Flywheel (Recommended for Local Development)

1. **In Local by Flywheel:**
   - Right-click your site → Open site shell
   ```bash
   cd app/public/wp-content/plugins
   git clone https://github.com/bwalton64/mds-release-manager.git
   ```

2. **Activate in WordPress:**
   - WordPress Admin → Plugins → Activate "MDS Release Manager"

3. **Enable Debugging (Optional):**
   Add to `wp-config.php`:
   ```php
   define( 'WP_DEBUG', true );
   define( 'WP_DEBUG_LOG', true );
   define( 'WP_DEBUG_DISPLAY', false );
   ```

#### Multisite Installation

```bash
# Network activate for all sites
# WordPress Admin → Network Admin → Plugins → Activate "MDS Release Manager"

# Or via WP-CLI:
wp plugin activate mds-release-manager --network
```

---

## Usage

### Creating a Release (via WordPress Admin)

1. **Navigate:** Dashboard → Releases → Add New
2. **Fill Release Information:**
   - **Title:** Release name (e.g., "Midnight Echo")
   - **UPC/EAN:** Unique product code (required)
   - **Format:** Single, EP, Album, or Digital (required, one only)
   - **Artists:** Associate artists (via post relationship)
   - **Release Date:** Publish date
   - **Cover Image:** Featured image (used in fallback and blocks)

3. **Add Release Links:**
   - Use the Release Links meta box to add streaming provider links
   - Configure provider-specific visibility and display order

4. **Publish Release**
   - Set status to Published
   - Release becomes visible in Discography and REST API

### Creating an Artist (via WordPress Admin)

1. **Navigate:** Dashboard → Artists → Add New
2. **Fill Artist Information:**
   - **Name:** Artist display name
   - **Bio:** Artist biography (rich text)
   - **Contact Info:** Contact details (optional)
   - **Featured Image:** Artist photo/avatar
   - **Official Homepage:** Link to artist's website (optional)

3. **Configure Routing (Optional):**
   - Enable "Route to Official Homepage" if needed
   - Provides temporary redirect to external site

4. **Publish Artist**
   - Releases automatically appear in Artist Discography

### Code Examples

#### Display a Release Block in Gutenberg

```php
// In a Gutenberg template or custom block
// Use the native "Release Meta" block to display:
// - Release title
// - Format, UPC, artist name
// - Per-field styling options

// Or use the shortcode equivalent (UPC/EAN identifies the release):
echo do_shortcode( '[release-meta upc="012345678901" fields="release_title,release_format,upc_ean"]' );
```

#### Display Release Links with Shortcode

```php
// Display streaming provider links for a release
echo do_shortcode( '[release-links upc="012345678901" show_audio="1" new_tab="1"]' );

// Output example:
// [Spotify] [Apple Music] [Bandcamp]
```

#### Add Discography to an Artist Page

```php
// As a shortcode (omit artist_id to use the current Artist):
echo do_shortcode( '[artist-discography artist_id="5"]' );

// Or use the native Artist Discography block in Gutenberg
```

#### Retrieve Releases via REST API

```bash
# Get all published releases (ID, title, and UPC/EAN)
curl "https://yoursite.com/wp-json/mds/v1/releases"

# The Release post type is also exposed via the WordPress core REST API
curl "https://yoursite.com/wp-json/wp/v2/release?per_page=10"
```

#### Use WordPress Abilities API (MCP Tools)

If using Claude or other MCP clients (WordPress 6.9+):

```
User: "Get all releases from the music site"
Claude: Calls mds_rm_get_releases ability
Result: Returns paginated releases with metadata

User: "Update the audio player setting to HTML5"
Claude: Calls mds_rm_save_settings ability
Result: Updates plugin configuration
```

#### Access via REST API (JavaScript)

```javascript
// Fetch releases using the plugin's REST API
async function getReleases() {
  const response = await fetch('/wp-json/mds/v1/releases');
  const releases = await response.json();

  releases.forEach( release => {
    console.log( `${release.title} (${release.upc})` );
  });
}

getReleases();
```

#### Use Customizer to Style Releases

```php
// Customizer → MDS Release Manager → Release Fallback Template

// Or read a fallback style programmatically:
$color = get_theme_mod( 'mds_rm_release_fallback_meta_color', '' );
if ( '' !== $color ) {
	echo '<span style="color: ' . esc_attr( $color ) . '">' . esc_html( get_the_title() ) . '</span>';
}
```

---

## Project Folder Structure

```
mds-release-manager/
│
├── README.md                           # This file
├── changelog.md                        # Version history and release notes
├── mds-release-manager.php             # Main plugin file (entry point)
├── uninstall.php                       # Plugin cleanup on uninstall
│
├── includes/                           # Core plugin classes (SPL-autoloaded)
│   ├── class-mds-rm-plugin.php                     # Plugin bootstrap
│   ├── admin/
│   │   ├── class-mds-rm-admin-interface.php        # Settings and admin UI
│   │   ├── class-mds-rm-csv-handler.php             # CSV import/export
│   │   └── class-mds-rm-network-admin.php           # Multisite network settings
│   ├── core/
│   │   ├── class-mds-rm-builder-detection.php      # Page-builder detection
│   │   ├── class-mds-rm-logger.php                 # Debug logging
│   │   ├── class-mds-rm-post-types.php             # Release/Artist post type registration
│   │   ├── class-mds-rm-providers.php              # Streaming provider defaults
│   │   ├── class-mds-rm-release-model.php          # Release data model, validation, and sync
│   │   ├── class-mds-rm-sync.php                   # Network synchronization and cache purge
│   │   └── class-mds-rm-template-loader.php        # Templates and editable assets
│   ├── frontend/
│   │   ├── class-mds-rm-artist-routing.php          # Artist homepage routing
│   │   ├── class-mds-rm-assets.php                  # CSS/JS enqueuing
│   │   ├── class-mds-rm-customizer.php              # WordPress Customizer integration
│   │   ├── class-mds-rm-discography.php             # Artist discography logic
│   │   ├── class-mds-rm-frontend-templates.php      # Content fallback rendering
│   │   └── class-mds-rm-shortcodes.php              # Shortcode registration
│   ├── blocks/                         # Block registration and Release renderers
│   ├── api/                            # REST, Abilities, and virtual media
│   └── integrations/
│       └── elementor/                   # Widgets and dynamic tags
│
├── templates/                          # PHP template files
│   ├── artist-fallback.php             # Artist page fallback template
│   ├── artist-meta-box.php             # Artist edit screen meta box
│   ├── csv-page.php                    # CSV import/export admin interface
│   ├── network-settings-page.php       # Multisite network settings
│   ├── release-fallback.php            # Release page fallback template
│   ├── release-meta-box.php            # Release edit screen meta box
│   └── settings-page.php               # Plugin settings page
│
├── css/                                # Stylesheet directory
│   ├── admin-styles.css                # Admin interface styles
│   ├── artist-fallback.css             # Artist fallback template styles
│   ├── frontend.css                    # General frontend styles
│   ├── release-fallback.css            # Release fallback template styles
│   ├── release-links.css               # Release Links block/shortcode styles
│   └── release-meta.css                # Release Meta block/shortcode styles
│
├── js/                                 # JavaScript directory
│   ├── blocks.js                       # Gutenberg block editor registration
│   ├── meta-boxes.js                   # Meta box interactivity and shortcode copy-to-clipboard
│   ├── release-taxonomies.js           # Native tag editor integration for releases
│   └── settings.js                     # Settings page tab navigation
│
├── .github/                            # Project documentation and CI configuration
│   ├── copilot-instructions.md         # Contributor/AI coding guidance
│   └── technical-blueprint.md          # Technical architecture documentation
│
└── .local/                             # Internal implementation notes (not end-user docs)
    ├── ABILITIES-API.md                # WordPress Abilities API reference
    ├── IMPLEMENTATION-SUMMARY.md       # Abilities API implementation summary
    └── ...                             # Historical refactoring and audit reports
```

### Key Directory Descriptions

- **includes/**: PHP classes using the `class-mds-rm-*.php` naming convention and WordPress PHP coding style
- **templates/**: Reusable PHP templates for admin screens and frontend rendering
- **css/**: Responsive stylesheets with mobile-first design
- **js/**: Block editor scripts (WordPress packages) and admin scripts (jQuery bundled with WordPress)
- **.github/**: Technical documentation and contributor guidance
- **.local/**: Historical implementation summaries and audit reports kept for reference

---

## Contributing

We welcome contributions! Follow these guidelines to maintain code quality and consistency.

### Getting Started

1. **Fork the Repository**
   ```bash
   git clone https://github.com/bwalton64/mds-release-manager.git
   cd mds-release-manager
   ```

2. **Create a Feature Branch**
   ```bash
   git checkout -b feature/my-feature-name
   ```

3. **Make Your Changes**
   - Follow the existing WordPress PHP formatting conventions (tabs and nearby code style)
   - Maintain strict type declarations (`declare(strict_types=1)`)
   - Add inline comments only when clarification is needed
   - Use the `MDS_RM_` class prefix for all new classes

### Code Standards

#### PHP Formatting
```php
<?php
declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class MDS_RM_Feature_Name {
	public static function register_hooks(): void {
		add_action( 'hook_name', array( static::class, 'method_name' ) );
	}
}
```

#### Naming Conventions
- **Classes:** `MDS_RM_Feature_Name` (PascalCase with `MDS_RM_` prefix)
- **Files:** `class-mds-rm-feature-name.php` (lowercase with hyphens)
- **Functions:** `mds_rm_function_name()` (snake_case with prefix)
- **Hooks:** `mds_rm_hook_name` or `mds_rm_filter_name` (snake_case with prefix)

#### Security & Sanitization
- Always use `wp_unslash()` on `$_POST`, `$_GET`, `$_REQUEST` data
- Sanitize with `sanitize_text_field()`, `absint()`, `esc_url_raw()` as appropriate
- Validate nonces before processing forms
- Use prepared statements for custom database queries (via WordPress APIs)
- Escape output with `esc_html()`, `esc_attr()`, `esc_url()`

#### Documentation
- Document public APIs, hooks, and non-obvious behavior with PHPDoc
- Include historically accurate `@since` version tags
- Provide parameter and return type hints
- Document complex logic with inline comments (sparingly)

### Testing Your Changes

1. **Local Testing**
   ```bash
   # Activate the plugin in WordPress
   wp plugin activate mds-release-manager
   
   # Test in WordPress Admin and frontend
   # Use wp-cli for quick tests:
   wp post list --post_type=release
   wp post list --post_type=artist
   ```

2. **Multisite Testing**
   ```bash
   # For network changes, test with network activation
   wp plugin activate mds-release-manager --network
   ```

3. **Code Quality**
   ```bash
   # Verify PHP syntax
   php -l includes/class-mds-rm-*.php
   ```

### Commit Messages

Follow conventional commit format:

```
feat: Add new release metadata field
^--^  ^------------------------------
|     |
|     +-> Description (lowercase, present tense)
|
+-------> Type: feat, fix, refactor, docs, test, chore, perf
```

**Types:**
- `feat` — New feature
- `fix` — Bug fix
- `refactor` — Code restructuring (no functionality change)
- `docs` — Documentation updates
- `test` — Test additions or changes
- `chore` — Build config, deps, or tooling
- `perf` — Performance improvements

**Example Commits:**
```bash
git commit -m "feat: Add WordPress Abilities API integration"
git commit -m "fix: Correct release format validation logic"
git commit -m "docs: Update README with installation steps"
```

### Submitting Pull Requests

1. **Push Your Branch**
   ```bash
   git push origin feature/my-feature-name
   ```

2. **Create a Pull Request**
   - Provide clear title and description
   - Link related issues with `Closes #123`
   - Include testing steps in PR description

3. **PR Template**
   ```markdown
   ## Description
   Brief description of changes
   
   ## Changes
   - Change 1
   - Change 2
   
   ## Testing
   Steps to verify the changes work correctly
   
   ## Checklist
   - [ ] Code follows existing WordPress PHP formatting conventions
   - [ ] No breaking changes
   - [ ] Updated documentation
   - [ ] Tested on Windows/macOS/Linux
   ```

### Extension Development

To extend MDS Release Manager, use the Hook Registry:

```php
// Add a custom meta box (receives the WP_Post being edited)
add_action( 'mds_rm_register_custom_meta_boxes', function( $post ) {
    if ( 'release' === $post->post_type ) {
        add_meta_box( /* ... */ );
    }
});

// Save custom metadata (receives the post ID and the raw, slashed $_POST array)
add_action( 'mds_rm_save_custom_meta', function( $post_id, $raw_post ) {
    $value = sanitize_text_field( wp_unslash( $raw_post['my_custom_field'] ?? '' ) );
    update_post_meta( $post_id, 'my_custom_field', $value );
}, 10, 2 );

// Append custom fields to the audio settings section (HTML string filter)
add_filter( 'mds_rm_settings_audio_fields', function( $html ) {
    $html .= '<p><label>' . esc_html__( 'My Custom Option', 'mds-release-manager' ) . '</label></p>';
    return $html;
});
```

### Reporting Issues

When reporting bugs, include:
- WordPress version
- PHP version
- Plugin version
- Steps to reproduce
- Expected vs. actual behavior
- Screenshots or error logs

### Questions?

- Check [.local/ABILITIES-API.md](./.local/ABILITIES-API.md) for Abilities API details
- Review [.github/technical-blueprint.md](./.github/technical-blueprint.md) for architecture and technical specs
- See [changelog.md](./changelog.md) for version history

---

## License

© 2026 Walton International Business Group. MDS Release Manager is licensed under the GNU General Public License, version 2 or (at your option) any later version. See [LICENSE.txt](./LICENSE.txt) for the license text.

---

## Support

- **Documentation:** See [.github/technical-blueprint.md](./.github/technical-blueprint.md) and [.local/ABILITIES-API.md](./.local/ABILITIES-API.md)
- **Issues:** Report via [GitHub Issues](https://github.com/bwalton64/mds-release-manager/issues)
- **Discussions:** Ask in [GitHub Discussions](https://github.com/bwalton64/mds-release-manager/discussions)

---

## Changelog

For detailed version history, see [changelog.md](./changelog.md).

**Current Version:** 1.0.0  
**Last Updated:** November 20, 2026
