# Copilot Coding & Style Rules

## Coding Standards (PSR-12 Without Namespaces)
- Strictly follow PSR-12 formatting (4-space indentation, opening braces for classes and methods on new lines, explicit visibility on all properties and methods, and `declare(strict_types=1);` at the top of files).
- **DO NOT use PHP namespaces.** Leave one blank line after `declare(strict_types=1);` as required by PSR-12 for non-namespaced files.
- Prefix all classes, interfaces, traits, global functions, and constants with `MDS_RM_`.

## Native WordPress APIs & Hooks First
- **Never reinvent the wheel.** Replace custom PHP solutions with native WordPress hooks and APIs:
  - Replace raw `$_POST` / `$_GET` form handlers with `admin_post_{action}` or `wp_ajax_{action}` hooks and `check_admin_referer()`.
  - Replace raw cURL or `file_get_contents()` calls with the WordPress HTTP API (`wp_remote_get()`, `wp_remote_post()`).
  - Replace raw `$wpdb` SQL queries with `WP_Query`, `get_posts()`, `get_option()`, or `update_post_meta()` wherever possible.
  - Replace hardcoded `<script>` and `<link>` HTML tags with `wp_enqueue_script()` and `wp_enqueue_style()` hooked to `wp_enqueue_scripts` or `admin_enqueue_scripts`.
  - Replace custom settings forms with the WordPress Settings API (`register_setting()`, `add_settings_section()`, `add_settings_field()`).
  - Replace custom cron/scheduling logic with `wp_schedule_event()` and native cron hooks.

## Include Consolidation & Simplification
- Consolidate scattered `require` / `include` statements into a single `spl_autoload_register` function in the main plugin file mapped to `MDS_RM_*` class prefixes.
- Convert loose procedural functions into static methods inside cohesive utility classes so they can be autoloaded.
- Simplify nested `if/else` blocks using early returns (guard clauses) and delete dead code.

# WordPress Documentation Standards
- Follow the official WordPress PHP Documentation Standards for all DocBlocks.
- Every function and class method must have a DocBlock immediately preceding it.
- Include a concise one-line summary ending with a period.
- Add a `@since` tag (use `1.0.0` or the current plugin version if missing).
- Document all parameters using `@param type $var Description.` with aligned spacing.
- Document return values using `@return type Description.` (omit `@return` for `void` functions unless required by local PHPCS rules).
- Do not modify any executable PHP logic; only add or update DocBlock comments.
# WordPress Native MCP & Abilities API Guidelines
- Target WordPress 6.9+ and PHP 8.0+.
- Do NOT write a custom MCP server, SSE handler, or JSON-RPC transport.
- Implement MCP support natively by registering capabilities via the WordPress core Abilities API (`wp_register_ability()` and `wp_register_ability_category()`).
- Hook ability category registration to `wp_abilities_api_categories_init` and ability registration to `wp_abilities_api_init`.
- Define strict JSON Schema arrays for `input_schema` and `output_schema`.
- Always include a `permission_callback` using `current_user_can()` and sanitize all inputs.
- Set `'meta' => [ 'show_in_rest' => true, 'mcp' => [ 'public' => true ] ]` so the official WordPress MCP Adapter plugin exposes the ability as an MCP tool.
