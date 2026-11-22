---
trigger: always_on
description: Centralized project variables, prefixes, namespace, and LocalWP path for this plugin.
---

# Project Configuration

All global rules in `~/.gemini/config/rules/` resolve their plugin-specific tokens from this table:

| Variable                   | Value                   |
| :------------------------- | :---------------------- |
| **Plugin Name**            | `{{PLUGIN_NAME}}`       |
| **Plugin Slug / Folder**   | `{{PLUGIN_SLUG}}`       |
| **Text Domain**            | `{{TEXT_DOMAIN}}`       |
| **PHP Root Namespace**     | `{{PHP_NAMESPACE}}`     |
| **Global Class Prefix**    | `{{CLASS_PREFIX}}`      |
| **Function / Hook Prefix** | `{{FUNC_PREFIX}}`       |
| **Constant Prefix**        | `{{CONST_PREFIX}}`      |
| **Minimum WordPress**      | `7.0`                   |
| **Tested Up To WordPress** | `7.x`                   |
| **Minimum PHP**            | `8.0`                   |
| **LocalWP Site Path**      | `{{LOCALWP_SITE_PATH}}` |
