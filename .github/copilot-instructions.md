# Copilot Coding Guidance

## Scope and compatibility

- Make focused changes that solve the requested task. Preserve existing behavior, public interfaces, stored data, and WordPress compatibility unless changing them is part of the task.
- Keep the documented minimum requirements at WordPress 6.3 and PHP 7.4 unless a task explicitly changes them.
- Follow the conventions of nearby code and avoid unrelated reformatting. Existing PHP uses WordPress-style tabs, global `MDS_RM_` class names, strict types, and a class autoloader; use these conventions by default when extending the existing architecture.
- A focused architectural refactor may introduce a different organization, including namespaces, when justified by the task. Avoid broad migrations or compatibility changes unless requested.

## WordPress and PHP

- Prefer native WordPress APIs and hooks when they fit the use case. Use appropriate nonce checks, capability checks, unslashing, validation, sanitization, and escaping for request data and output.
- Use the WordPress HTTP API for WordPress plugin HTTP requests. Use prepared `$wpdb` queries or other suitable APIs when native query APIs do not cover the operation, such as custom-table work.
- Use WordPress enqueue APIs for scripts and styles, and the Settings API for settings screens when appropriate; do not rewrite working code solely to adopt a different API.
- Keep refactoring scoped to the task. Use guard clauses when they improve clarity, and remove code only when it is verified to be unused. Do not require conversion of procedural functions to static methods or consolidation of includes when the existing structure is adequate.

## Documentation

- Follow WordPress PHP documentation conventions for documented functions, classes, hooks, and public extension points. Add or update DocBlocks when they clarify or expose behavior; keep `@since` values historically accurate rather than guessing.
- A documentation-only restriction applies only when the user explicitly requests documentation-only changes. Otherwise, make necessary executable-code changes for the task.

## Testing

- Prefer the project's existing tests and tools. Run the smallest relevant checks; do not add a test framework or scaffolding unless the task requires it.
- Use WP-CLI or browser tests only when relevant and available. Do not assume commands run in a LocalWP Site Shell or require Playwright.
- When a test creates data, track the specific IDs it created and clean up only those artifacts. Never delete records by selecting all posts of a post type.
- Keep test credentials out of version control. Reuse existing authentication and environment configuration rather than requiring a particular setup.
- For browser tests, prefer accessible, resilient locators such as role- and label-based selectors.

## WordPress Abilities API

- Apply these guidelines only when adding or changing Abilities API support. The plugin supports WordPress 6.3+; the Abilities API is available from WordPress 6.9, so keep that feature conditional rather than raising the plugin-wide minimum version.
- Prefer the native Abilities API over a custom MCP transport. Register categories and abilities on their corresponding core hooks, use schemas and permission checks appropriate to each ability, and validate/sanitize inputs.
- Expose abilities through MCP metadata only when MCP exposure is intended. Keep capability checks aligned with the data and actions each ability makes available.
