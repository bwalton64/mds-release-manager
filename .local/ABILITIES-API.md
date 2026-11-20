# MDS Release Manager - WordPress Abilities API Integration

## Overview

This document describes the WordPress Abilities API integration for MDS Release Manager (WordPress 6.9+). The plugin exposes core features as native MCP (Model Context Protocol) tools that are automatically discoverable by the official WordPress MCP Adapter.

## Architecture

### Implementation

- **Class:** `MDS_RM_Abilities` (`includes/class-mds-rm-abilities.php`)
- **Hooks:**
  - `wp_abilities_api_categories_init` — Registers the custom ability category
  - `wp_abilities_api_init` — Registers all abilities
- **Graceful Fallback:** All hooks check `function_exists( 'wp_register_ability' )` before registering

### Naming Convention

All abilities use the `mds_rm_` prefix following the plugin's naming standards:
- `mds_rm_get_releases`
- `mds_rm_save_settings`
- `mds_rm_save_network_settings`
- `mds_rm_sync_network`

## Registered Abilities

### 1. Get Releases

**ID:** `mds_rm_get_releases`

Retrieve published artist releases with metadata.

#### Input Schema

```json
{
  "type": "object",
  "properties": {
    "per_page": {
      "type": "integer",
      "description": "Number of releases per page (1-100)",
      "default": 10,
      "minimum": 1,
      "maximum": 100
    },
    "page": {
      "type": "integer",
      "description": "Page number for pagination",
      "default": 1,
      "minimum": 1
    }
  }
}
```

#### Output Schema

```json
{
  "type": "object",
  "properties": {
    "releases": {
      "type": "array",
      "items": {
        "type": "object",
        "properties": {
          "id": { "type": "integer", "description": "Release post ID" },
          "title": { "type": "string", "description": "Release title" },
          "upc": { "type": "string", "description": "UPC/EAN identifier" },
          "format": { "type": "string", "description": "Release format (Single, EP, Album, Digital)" }
        }
      }
    },
    "total": { "type": "integer", "description": "Total number of releases" },
    "pages": { "type": "integer", "description": "Total number of pages" }
  }
}
```

#### Permissions

- Required capability: `read`
- Public read access supported

#### Example Response

```json
{
  "releases": [
    {
      "id": 42,
      "title": "Night Drive",
      "upc": "885136900257",
      "format": "Album"
    },
    {
      "id": 41,
      "title": "Summer Single",
      "upc": "885136900264",
      "format": "Single"
    }
  ],
  "total": 2,
  "pages": 1
}
```

---

### 2. Save Settings

**ID:** `mds_rm_save_settings`

Update plugin-level settings including providers, audio players, and feature toggles.

#### Input Schema

```json
{
  "type": "object",
  "properties": {
    "providers": {
      "type": "object",
      "description": "Release providers configuration",
      "properties": {
        "name": {
          "type": "array",
          "items": { "type": "string" },
          "description": "Provider names"
        },
        "icon": {
          "type": "array",
          "items": { "type": "string" },
          "description": "Provider icon classes or URLs"
        },
        "color": {
          "type": "array",
          "items": { "type": "string" },
          "description": "Provider brand colors"
        }
      }
    },
    "audio_players": {
      "type": "array",
      "items": { "type": "string" },
      "description": "Enabled audio player slugs (e.g., 'html5', 'bandcamp')"
    },
    "global_redirect": {
      "type": "boolean",
      "description": "Enable global artist homepage redirect"
    },
    "fa_support": {
      "type": "string",
      "description": "Font Awesome support setting",
      "enum": ["enabled", "disabled"]
    }
  }
}
```

#### Output Schema

```json
{
  "type": "object",
  "properties": {
    "success": { "type": "boolean", "description": "Whether settings were saved successfully" },
    "message": { "type": "string", "description": "Response message" }
  }
}
```

#### Permissions

- Required capability: `manage_options`
- Restricted to administrators only

#### Example Request

```json
{
  "providers": {
    "name": ["Spotify", "Apple Music", "Amazon Music"],
    "icon": ["fab fa-spotify", "fab fa-apple", "fab fa-amazon"],
    "color": ["#1DB954", "#FB5340", "#FF9900"]
  },
  "audio_players": ["html5"],
  "global_redirect": false,
  "fa_support": "enabled"
}
```

#### Example Response

```json
{
  "success": true,
  "message": "Settings saved successfully"
}
```

---

### 3. Save Network Settings

**ID:** `mds_rm_save_network_settings`

Update network-wide settings for multisite installations.

#### Input Schema

```json
{
  "type": "object",
  "properties": {
    "master_blog_id": {
      "type": "integer",
      "description": "Master hub site blog ID",
      "minimum": 1
    },
    "wipe_on_uninstall": {
      "type": "boolean",
      "description": "Whether to delete all plugin data on uninstall"
    }
  }
}
```

#### Output Schema

```json
{
  "type": "object",
  "properties": {
    "success": { "type": "boolean", "description": "Whether settings were saved successfully" },
    "message": { "type": "string", "description": "Response message" }
  }
}
```

#### Permissions

- Required capability: `manage_network_options`
- Restricted to network administrators only

#### Example Request

```json
{
  "master_blog_id": 1,
  "wipe_on_uninstall": false
}
```

#### Example Response

```json
{
  "success": true,
  "message": "Network settings saved successfully"
}
```

---

### 4. Sync Network Data

**ID:** `mds_rm_sync_network`

Synchronize releases and artists across all network sites.

#### Input Schema

```json
{
  "type": "object",
  "properties": {}
}
```

No input parameters required.

#### Output Schema

```json
{
  "type": "object",
  "properties": {
    "success": { "type": "boolean", "description": "Whether sync completed successfully" },
    "message": { "type": "string", "description": "Sync result message with counts" },
    "artists_synced": { "type": "integer", "description": "Number of artists synchronized" },
    "releases_synced": { "type": "integer", "description": "Number of releases synchronized" },
    "errors": {
      "type": "array",
      "items": { "type": "string" },
      "description": "Any errors that occurred during sync"
    }
  }
}
```

#### Permissions

- Required capability: `manage_network_options`
- Must be run from master hub site
- Restricted to network administrators only

#### Example Response (Success)

```json
{
  "success": true,
  "message": "Successfully synced 42 releases and 12 artists to spoke sites.",
  "artists_synced": 12,
  "releases_synced": 42,
  "errors": []
}
```

#### Example Response (Partial Failure)

```json
{
  "success": false,
  "message": "Successfully synced 42 releases and 12 artists to spoke sites.",
  "artists_synced": 12,
  "releases_synced": 42,
  "errors": [
    "Release 101: Network error on spoke site 3"
  ]
}
```

---

## MCP Adapter Discovery

All registered abilities include the metadata flag `meta.mcp.public = true`, which allows the official WordPress MCP Adapter to automatically discover and register them as native tools.

### Discovery Process

1. The WordPress MCP Adapter queries the WordPress Abilities API
2. Filters abilities by `meta.mcp.public = true`
3. Extracts input/output schemas from JSON Schema definitions
4. Registers each ability as a native MCP tool
5. Tools become available in MCP clients (Claude, etc.)

### Schema Compliance

All abilities provide complete JSON Schema v7 definitions:

- **Type:** object (for all inputs/outputs)
- **Properties:** Fully defined with types and descriptions
- **Descriptions:** Human-readable, translatable strings
- **Constraints:** Minimum/maximum values, enums, default values
- **No hidden fields:** All inputs and outputs fully schema-specified

---

## Security & Permission Model

### Capability Checks

Each ability enforces strict WordPress capability checks:

| Ability | Capability | Function |
|---------|-----------|----------|
| `mds_rm_get_releases` | `read` | `check_read_permission()` |
| `mds_rm_save_settings` | `manage_options` | `check_manage_options_permission()` |
| `mds_rm_save_network_settings` | `manage_network_options` | `check_manage_network_options_permission()` |
| `mds_rm_sync_network` | `manage_network_options` | `check_manage_network_options_permission()` |

### Input Sanitization

All inputs follow WordPress security standards:

- Text fields: `wp_unslash()` → `sanitize_text_field()`
- URLs: `wp_unslash()` → `esc_url_raw()`
- Numbers: `absint()`
- Arrays: Iterated and sanitized item-by-item

### Output Escaping

Output schemas are always trusted (not user-controlled), so no escaping is needed. However:
- Stored metadata is sanitized on write
- Database queries use proper `prepare()` via WordPress APIs
- No raw SQL or unvalidated input is ever executed

---

## Backward Compatibility

The Abilities API is only available on WordPress 6.9+. The plugin includes a graceful fallback:

```php
public static function register_hooks() {
    if ( ! function_exists( 'wp_register_ability' ) ) {
        return;
    }
    // ... continue with registration
}
```

**On older WordPress versions:** The Abilities API simply doesn't register. The plugin continues to function normally via REST API endpoints.

---

## Extension & Customization

### Adding New Abilities

To add a new ability:

1. Create a new `wp_register_ability()` call in `MDS_RM_Abilities::register_abilities()`
2. Provide complete `input_schema` and `output_schema`
3. Implement a callback function (e.g., `ability_new_feature()`)
4. Add strict `permission_callback` function
5. Set `meta.mcp.public = true` for MCP discovery
6. Document in this file and `technical-blueprint.md`

### Filtering Abilities

Third-party integrations can filter ability registration via:

```php
apply_filters( 'mds_rm_ability_registration', $ability_config, $ability_id );
```

(This would need to be implemented in future versions if needed.)

---

## Related Documentation

- **Technical Blueprint:** See `.github/technical-blueprint.md` for complete architecture
- **REST API:** See `includes/class-mds-rm-rest-api.php` for traditional REST endpoints
- **WordPress Abilities API:** https://developer.wordpress.org/plugins/capabilities/

---

## Changelog

### Version 0.9.0+ (Unreleased)
- Initial WordPress Abilities API integration
- Four core abilities: Get Releases, Save Settings, Save Network Settings, Sync Network
- MCP public metadata for automatic discovery
- Complete JSON Schema definitions for all inputs/outputs
- Graceful fallback for WordPress < 6.9
