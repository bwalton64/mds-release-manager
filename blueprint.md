# MDS Release Manager - Technical Blueprint
**Version:** 0.9.0+
**Architecture:** Core & Extensible Ecosystem

## Core Philosophy
As of version 0.9.0, the MDS Release Manager has transitioned from a monolithic utility into an extensible platform. To maintain stability across the multisite network, we utilize **Approach A: Independent Feature Plugins**.

Third-party integrations (e.g., WooCommerce, Bandcamp) are no longer hardcoded into the core. Instead, they must be written as standard, lightweight WordPress plugins that hook into the MDS Release Manager's **Hook Registry**.

## 1. Extension Guidelines
Before a feature plugin hooks into MDS, it must verify that the core MDS plugin is active to prevent fatal errors.

**Standard Dependency Check Example:**
```php
function my_feature_is_mds_active() {
    $active_plugins = apply_filters( 'active_plugins', get_option( 'active_plugins' ) );
    return in_array( 'mds-release-manager/mds-release-manager.php', $active_plugins, true ) || 
           ( is_multisite() && array_key_exists( 'mds-release-manager/mds-release-manager.php', get_site_option( 'active_sitewide_plugins', array() ) ) );
}
```

## 2. The Hook Registry
The core plugin provides specific `do_action` and `apply_filters` hooks to safely inject UI components and processing logic.

### A. Site Editor & Custom Post Types
Hooks for extending the Release and Artist CPT interfaces.

*   `do_action( 'mds_rm_register_custom_meta_boxes', WP_Post $post );`
    *   **Context:** Fires inside `add_meta_boxes`. 
    *   **Usage:** Use this to append custom PHP Meta Boxes to the Release or Artist CPT editor screens using standard `add_meta_box()` syntax.
*   `do_action( 'mds_rm_save_custom_meta', int $post_id, array $post_data );`
    *   **Context:** Fires inside the core `save_post` routine immediately after native MDS meta is saved and synchronized.
    *   **Usage:** Use this to safely capture and save your feature plugin's custom `$_POST` data. 

### B. Settings & Configuration
Hooks for extending the Hub Site Settings and Network Settings.

*   `apply_filters( 'mds_rm_settings_audio_fields', string $html );`
    *   **Context:** Fires inside the Audio Preview section of the `Releases -> Settings` page.
    *   **Usage:** Append HTML table rows (`<tr>...</tr>`) to register custom audio player shortcode templates (e.g., Bandcamp settings).
*   `apply_filters( 'mds_rm_network_settings_fields', string $html );`
    *   **Context:** Fires on the Network Admin settings page.
    *   **Usage:** Append HTML table rows to register global toggles or site-mapping logic (e.g., mapping WooCommerce stores).

### C. Frontend Display
Hooks for injecting content into the public-facing templates.

*   `do_action( 'mds_rm_after_release_meta', int $post_id );`
    *   **Context:** Fires below the native streaming links output on the Single Release template.
    *   **Usage:** Echo HTML payloads, audio players, or custom widgets directly into the frontend layout.

## 3. Coding Standards & Security
All feature plugins built for the MDS ecosystem MUST adhere to the following:
1.  **PSR-12 Compliance:** PHP code must be cleanly formatted, spaced, and documented using standard DocBlocks.
2.  **Sanitization (Zero Trust):** Every incoming `$_POST` variable must be routed through `wp_unslash()` followed by `sanitize_text_field()`, `esc_url_raw()`, or `absint()`.
3.  **Output Escaping:** All frontend and backend HTML variables must be wrapped in `esc_html()`, `esc_attr()`, or `wp_kses_post()`.
4.  **Multisite Awareness:** Ensure data saved locally on a Hub site does not inappropriately broadcast to a Spoke site unless explicitly commanded by your feature logic.