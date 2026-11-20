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
- ✅ **Shortcodes:** `[release-meta]`, `[release-links]`, `[discography]` with responsive options
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
- **WordPress:** 6.3+ (Abilities API: 6.9+)
- **PHP:** 7.4 or higher with strict types support
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
   git clone https://github.com/[your-org]/mds-release-manager.git
   cd mds-release-manager
   ```

2. **Verify PHP Requirements**
   ```powershell
   php -v  # Verify PHP 7.4+
   php --ini  # Confirm debugging configuration
   ```

3. **Install Composer Dependencies (if applicable)**
   ```bash
   composer install
   ```

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
   git clone https://github.com/[your-org]/mds-release-manager.git
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

// Or use the shortcode equivalent:
echo do_shortcode( '[release-meta id="42" show_format="1" show_upc="1"]' );
```

#### Display Release Links with Shortcode

```php
// Display streaming provider links for a release
echo do_shortcode( '[release-links id="42" columns="3" show_audio="1"]' );

// Output example:
// [Spotify] [Apple Music] [Bandcamp]
```

#### Add Discography to an Artist Page

```php
// In Gutenberg or as a shortcode:
echo do_shortcode( '[discography artist_id="5" columns="2" per_page="12"]' );

// Or use the native Discography block in Gutenberg/Elementor
```

#### Retrieve Releases via REST API

```bash
# Get all published releases (paginated)
curl "https://yoursite.com/wp-json/wp/v2/releases?per_page=10"

# Get a single release
curl "https://yoursite.com/wp-json/wp/v2/releases/42"

# Filter by artist
curl "https://yoursite.com/wp-json/wp/v2/releases?artist=5"
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
// Fetch releases using the WordPress REST API
async function getReleases() {
  const response = await fetch('/wp-json/wp/v2/releases?per_page=10');
  const releases = await response.json();
  
  releases.forEach( release => {
    console.log( `${release.title} (${release.upc})` );
  });
}

getReleases();
```

#### Use Customizer to Style Releases

```php
// Customizer → Release Fallback Template → Color Settings

// Or programmatically:
$color = get_theme_mod( 'mds_rm_release_title_color', '#000' );
echo '<h1 style="color: ' . esc_attr( $color ) . '">' . get_the_title() . '</h1>';
```

---

## Project Folder Structure

```
mds-release-manager/
│
├── README.md                           # This file
├── ABILITIES-API.md                    # WordPress Abilities API documentation
├── IMPLEMENTATION-SUMMARY.md           # Technical implementation details
├── changelog.md                        # Version history and release notes
├── license.txt                         # License information
│
├── mds-release-manager.php             # Main plugin file (entry point)
├── uninstall.php                       # Plugin cleanup on uninstall
│
├── includes/                           # Core plugin classes
│   ├── class-mds-rm-abilities.php                     # Abilities API (MCP tools)
│   ├── class-mds-rm-admin-interface.php              # Settings and admin UI
│   ├── class-mds-rm-artist-routing.php               # Artist page routing
│   ├── class-mds-rm-assets.php                       # CSS/JS enqueuing
│   ├── class-mds-rm-builder-detection.php            # Gutenberg/Elementor detection
│   ├── class-mds-rm-core-functions.php               # Shared utilities
│   ├── class-mds-rm-csv-handler.php                  # CSV import/export
│   ├── class-mds-rm-customizer.php                   # WordPress customizer integration
│   ├── class-mds-rm-discography.php                  # Artist discography logic
│   ├── class-mds-rm-elementor-*.php                  # Elementor widgets and tags
│   ├── class-mds-rm-frontend-templates.php           # Content fallback rendering
│   ├── class-mds-rm-gutenberg-blocks.php             # Gutenberg block registration
│   ├── class-mds-rm-network-admin.php                # Multisite network settings
│   ├── class-mds-rm-post-types.php                   # Release/Artist post type registration
│   ├── class-mds-rm-release-links.php                # Provider link management
│   ├── class-mds-rm-release-meta.php                 # Release metadata and taxonomy
│   ├── class-mds-rm-release-model.php                # Release data model and sync
│   ├── class-mds-rm-rest-api.php                     # REST API endpoints
│   ├── class-mds-rm-shortcodes.php                   # Shortcode registration
│   └── class-mds-rm-virtual-media.php                # Media library integration
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
│   ├── admin.css                       # Admin interface styles
│   ├── customizer.css                  # Customizer panel styles
│   ├── fallback.css                    # Release/Artist fallback template styles
│   └── blocks.css                      # Gutenberg/Elementor block styles
│
├── js/                                 # JavaScript directory
│   ├── admin.js                        # Admin interface interactivity
│   ├── blocks.js                       # Gutenberg block registration
│   ├── elementor.js                    # Elementor widget registration
│   └── copy-to-clipboard.js            # Shortcode copy functionality
│
├── .github/                            # GitHub configuration
│   ├── technical-blueprint.md          # Technical architecture documentation
│   └── ...                             # GitHub workflows/actions
│
└── .git/                               # Git repository metadata
```

### Key Directory Descriptions

- **includes/**: PHP classes using the `class-mds-rm-*.php` naming convention and WordPress PHP coding style
- **templates/**: Reusable PHP templates for admin screens and frontend rendering
- **css/**: Responsive stylesheets with mobile-first design
- **js/**: Vanilla JavaScript (no jQuery dependency) for interactive features
- **.github/**: Technical documentation and CI/CD configuration

---

## Contributing

We welcome contributions! Follow these guidelines to maintain code quality and consistency.

### Getting Started

1. **Fork the Repository**
   ```bash
   git clone https://github.com/[your-username]/mds-release-manager.git
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
// Add custom meta box
add_action( 'mds_rm_register_custom_meta_boxes', function( $post_type ) {
    if ( 'release' === $post_type ) {
        add_meta_box( ... );
    }
});

// Save custom metadata
add_action( 'mds_rm_save_custom_meta', function( $post_id, $post_type, $data ) {
    if ( 'release' === $post_type ) {
        update_post_meta( $post_id, 'custom_field', $data['custom_field'] );
    }
}, 10, 3 );

// Extend settings fields
add_filter( 'mds_rm_settings_audio_fields', function( $fields ) {
    $fields['my_custom_field'] = [
        'label'    => __( 'My Custom Option', 'mds-release-manager' ),
        'type'     => 'select',
        'options'  => [ ... ],
    ];
    return $fields;
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

- Check [ABILITIES-API.md](/wp-content/plugins/mds-release-manager/ABILITIES-API.md) for API details
- Review [IMPLEMENTATION-SUMMARY.md](/wp-content/plugins/mds-release-manager/IMPLEMENTATION-SUMMARY.md) for technical specs
- See [changelog.md](/wp-content/plugins/mds-release-manager/changelog.md) for version history

---

## License

MDS Release Manager is licensed under the [INSERT LICENSE HERE - MIT, GPL-2.0+, or custom]. See [license.txt](/wp-content/plugins/mds-release-manager/license.txt) for details.

© 2026 Walton International Business Group. All rights reserved.

---

## Support

- **Documentation:** See [ABILITIES-API.md](/wp-content/plugins/mds-release-manager/ABILITIES-API.md) and [IMPLEMENTATION-SUMMARY.md](/wp-content/plugins/mds-release-manager/IMPLEMENTATION-SUMMARY.md)
- **Issues:** Report via [GitHub Issues](https://github.com/[your-org]/mds-release-manager/issues)
- **Discussions:** Ask in [GitHub Discussions](https://github.com/[your-org]/mds-release-manager/discussions)
- **Email:** [INSERT SUPPORT EMAIL HERE]

---

## Changelog

For detailed version history, see [changelog.md](/wp-content/plugins/mds-release-manager/changelog.md).

**Current Version:** 1.0.0  
**Last Updated:** November 19, 2026
