# Security Implementation Guidelines
## MDS Release Manager WordPress Plugin

This document provides guidelines for maintaining and extending the security improvements made during the refactoring.

---

## Table of Contents
1. [Input Validation Checklist](#input-validation-checklist)
2. [Type Hints Best Practices](#type-hints-best-practices)
3. [Common Sanitizers Reference](#common-sanitizers-reference)
4. [Testing Security Changes](#testing-security-changes)
5. [Code Review Checklist](#code-review-checklist)

---

## Input Validation Checklist

When handling user input from forms or query strings, follow this checklist:

### For Every `$_POST` / `$_GET` Access:

- [ ] **Check existence** with `isset()`
- [ ] **Type check** before processing:
  - `is_string()` for single string values
  - `is_array()` for arrays
  - `is_int()` or `is_numeric()` for numbers
  - `ctype_digit()` for digit strings
  - `is_scalar()` for scalars
- [ ] **Unslash** with `wp_unslash()`
- [ ] **Sanitize** with appropriate function:
  - `sanitize_text_field()` - general text
  - `sanitize_email()` - email addresses
  - `esc_url_raw()` - URLs being saved
  - `absint()` - integers/IDs
  - `floatval()` - floats
  - `sanitize_key()` - array keys (rarely used for input)
  - `wp_json_encode()` - JSON data
- [ ] **Escape** when outputting:
  - `esc_html()` - plain text in HTML
  - `esc_attr()` - in HTML attributes
  - `esc_url()` - in links
  - `wp_kses_post()` - trusted markup

### Template Code:

```php
// ✅ CORRECT PATTERN
if ( isset( $_POST['field_name'] ) && is_string( $_POST['field_name'] ) ) {
    $field_value = sanitize_text_field( wp_unslash( $_POST['field_name'] ) );
    // Use $field_value safely
}

// ✅ CORRECT PATTERN FOR ARRAYS
if ( isset( $_POST['array_field'] ) && is_array( $_POST['array_field'] ) ) {
    $array_values = array_map( 'sanitize_text_field', wp_unslash( $_POST['array_field'] ) );
    // Use $array_values safely
}

// ✅ CORRECT PATTERN FOR INTEGERS
if ( isset( $_POST['id_field'] ) && is_scalar( $_POST['id_field'] ) ) {
    $id = absint( wp_unslash( $_POST['id_field'] ) );
    // Use $id safely
}
```

---

## Type Hints Best Practices

### Always Add Type Hints

```php
// ❌ OLD STYLE (avoid)
public static function process_data( $data ) {
    return $data;
}

// ✅ NEW STYLE (required)
public static function process_data( array<string, mixed> $data ): array<string, mixed> {
    return $data;
}
```

### Common Type Hint Patterns

```php
// Nullable types
public function get_option( string $key ): ?string {
    return get_option( $key );
}

// Union types (PHP 8.0+)
public function parse_id( string|int $id ): int {
    return (int) $id;
}

// Array types
public function process_items( array<string> $items ): array<string> {
    // ...
}

// Return false on error
public function find_post( int $id ): WP_Post|false {
    // ...
}

// Void return
public function cleanup(): void {
    // ...
}
```

### Generic Array Type Documentation

```php
/**
 * Process configuration.
 *
 * @param array<string, int> $config Configuration mapping.
 * @param array<int, string> $items List of items.
 * @param array<string, mixed> $mixed_data Mixed data array.
 * @return array<string, bool> Result mapping.
 */
public function configure( array $config, array $items, array $mixed_data ): array {
    // ...
}
```

---

## Common Sanitizers Reference

### Text Input
```php
// General text (most common)
$text = sanitize_text_field( wp_unslash( $_POST['text'] ) );

// HTML allowed (editor content)
$html = wp_kses_post( wp_unslash( $_POST['html'] ) );

// File path
$path = sanitize_file_name( wp_unslash( $_POST['filename'] ) );
```

### Specific Data Types
```php
// Email
$email = sanitize_email( wp_unslash( $_POST['email'] ) );

// URL being saved
$url = esc_url_raw( wp_unslash( $_POST['url'] ) );

// Numeric ID
$id = absint( wp_unslash( $_POST['id'] ) );

// Float value
$float = floatval( wp_unslash( $_POST['float'] ) );

// Boolean
$bool = ! empty( $_POST['checkbox'] );

// JSON
$json = json_decode( wp_unslash( $_POST['json'] ), true );
```

### Arrays
```php
// Array of text values
$items = array_map( 'sanitize_text_field', wp_unslash( $_POST['items'] ) );

// Array of integers
$ids = array_map( 'absint', wp_unslash( $_POST['ids'] ) );

// Array of URLs
$urls = array_map( 'esc_url_raw', wp_unslash( $_POST['urls'] ) );

// Array of mixed values
$data = array_map(
    fn( $item ) => is_numeric( $item ) ? absint( $item ) : sanitize_text_field( $item ),
    wp_unslash( $_POST['data'] )
);
```

### Nonce Verification
```php
// Get and sanitize nonce
$nonce = isset( $_POST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ) : '';

// Verify nonce
if ( ! wp_verify_nonce( $nonce, 'action_name' ) ) {
    wp_die( 'Security check failed' );
}

// Alternative one-liner
if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'action_name' ) ) {
    wp_die( 'Security check failed' );
}
```

---

## Testing Security Changes

### Unit Testing

```php
// Test sanitization
public function test_post_sanitization() {
    $_POST['test'] = '<script>alert("xss")</script>';
    $sanitized = sanitize_text_field( wp_unslash( $_POST['test'] ) );
    $this->assertNotContains( '<script>', $sanitized );
}

// Test type checking
public function test_type_checking() {
    $_POST['id'] = 'not_an_id';
    $id = isset( $_POST['id'] ) && is_string( $_POST['id'] ) && ctype_digit( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
    $this->assertEquals( 0, $id );
}

// Test nonce verification
public function test_nonce_verification() {
    $_POST['_wpnonce'] = 'invalid_nonce';
    $_POST['action'] = 'test_action';
    $nonce = sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) );
    $valid = wp_verify_nonce( $nonce, 'test_action' );
    $this->assertFalse( $valid );
}
```

### Manual Testing

1. **Inject Test Payloads**
   - `<script>alert('xss')</script>`
   - `'; DROP TABLE posts; --`
   - `../../../etc/passwd`
   - `" onload="alert('xss')"`

2. **Verify Behavior**
   - Check error logs for blocked attempts
   - Verify data not processed
   - Check response is not exposing details

3. **Use Security Tools**
   - OWASP ZAP Scanner
   - Burp Suite Community
   - WPScan for WordPress vulnerabilities

---

## Code Review Checklist

When reviewing code changes, verify:

### Input Handling
- [ ] All `$_POST` and `$_GET` accesses are type-checked
- [ ] `wp_unslash()` is called before sanitization
- [ ] Appropriate sanitizer function used for data type
- [ ] No direct use of `$_REQUEST` superglobal
- [ ] No direct database queries without `$wpdb->prepare()`

### Nonce & Capability Verification
- [ ] `wp_verify_nonce()` or `check_admin_referer()` called
- [ ] `current_user_can()` checks present
- [ ] Capability matched to action (e.g., `manage_options` for admin settings)

### Output Escaping
- [ ] All dynamic content in HTML escaped with `esc_html()`
- [ ] Attributes escaped with `esc_attr()`
- [ ] URLs escaped with `esc_url()` or `esc_url_raw()`
- [ ] HTML from editor escaped with `wp_kses_post()`

### Type Hints
- [ ] All function parameters have type hints
- [ ] All function return types declared
- [ ] Complex types documented (e.g., `array<string, mixed>`)
- [ ] Parameter types include nullability where applicable

### Error Handling
- [ ] `wp_die()` called with escaped messages
- [ ] Error messages don't expose system details
- [ ] No unhandled exceptions logged to client

### Database Operations
- [ ] Uses WordPress meta functions when possible
- [ ] SQL queries use `$wpdb->prepare()`
- [ ] Placeholders are `%d`, `%s`, or `%f`
- [ ] No string concatenation in queries

---

## Security Incident Response

If a security vulnerability is discovered:

1. **Document the issue**
   ```php
   // SECURITY: [Description of vulnerability]
   // Vulnerable code here
   // Fix: [Description of fix]
   ```

2. **Create a patch**
   - Apply all three: type checking, sanitization, verification
   - Add inline documentation
   - Create unit test

3. **Test the fix**
   - Run existing tests
   - Add regression test
   - Manual security testing

4. **Deploy carefully**
   - Create a release branch
   - Test on staging environment
   - Document breaking changes (if any)

---

## Common Security Mistakes to Avoid

### ❌ Never Do This

```php
// Don't: Trust user input directly
$name = $_POST['name'];

// Don't: Use wrong sanitizer for data type
$url = sanitize_text_field( $_POST['url'] ); // Breaks URLs!

// Don't: Sanitize and then verify nonce
wp_verify_nonce( sanitize_key( $_POST['nonce'] ), 'action' ); // Wrong sanitizer

// Don't: Use extract() with user input
extract( $_POST ); // Dangerous!

// Don't: Use serialize() for user data
$data = serialize( $_POST ); // Can be exploited

// Don't: SQL concatenation
$wpdb->query( "SELECT * FROM posts WHERE ID = " . $_POST['id'] ); // SQL injection!

// Don't: Echo without escaping
echo $_POST['message']; // XSS vulnerability!

// Don't: Store unescaped data
update_post_meta( $id, 'value', $_POST['data'] ); // Store escaped!

// Don't: Type check after casting
$id = (int) $_POST['id']; // Should check BEFORE casting

// Don't: Mix sanitization in conditionals
if ( isset( $_POST['x'] ) && $val = sanitize_text_field( $_POST['x'] ) ) {
    // Confusing and hard to debug
}
```

### ✅ Always Do This

```php
// Do: Check, type, unslash, sanitize
if ( isset( $_POST['name'] ) && is_string( $_POST['name'] ) ) {
    $name = sanitize_text_field( wp_unslash( $_POST['name'] ) );
}

// Do: Use correct sanitizer
if ( isset( $_POST['url'] ) && is_string( $_POST['url'] ) ) {
    $url = esc_url_raw( wp_unslash( $_POST['url'] ) );
}

// Do: Sanitize nonce with right function
$nonce = isset( $_POST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ) : '';
if ( ! wp_verify_nonce( $nonce, 'action' ) ) {
    // Handle error
}

// Do: Use WordPress functions
$posts = get_posts( array( 'post__in' => $ids ) );

// Do: Escape output
echo esc_html( $message );

// Do: Store safely
update_post_meta( $id, 'value', sanitize_text_field( wp_unslash( $_POST['data'] ) ) );

// Do: Check types early
if ( isset( $_POST['id'] ) && is_scalar( $_POST['id'] ) ) {
    $id = absint( wp_unslash( $_POST['id'] ) );
}

// Do: Separate concerns
$raw_input = $_POST['input'] ?? '';
$unslashed = wp_unslash( $raw_input );
$sanitized = sanitize_text_field( $unslashed );
```

---

## Resources

- [WordPress Plugin Security](https://developer.wordpress.org/plugins/security/)
- [Data Validation](https://developer.wordpress.org/plugins/security/data-validation/)
- [Sanitizing Input](https://developer.wordpress.org/plugins/security/sanitizing-input/)
- [Escaping Output](https://developer.wordpress.org/plugins/security/escaping-output/)
- [OWASP Top 10](https://owasp.org/www-project-top-ten/)
- [PHP Type Declarations](https://www.php.net/manual/en/language.types.declarations.php)

---

## Questions?

Refer to the accompanying documentation:
- `SECURITY_REFACTORING_SUMMARY.md` - Overview of changes
- `CODE_CHANGES_BEFORE_AFTER.md` - Detailed before/after examples

