# WordPress Core & Ecosystem Capability Scout — Centralized Secrets Management

> **Workspace Scope:** `mds-release-manager` (Free Core neutral primitive) + `mds-audio-providers`, `mds-metadata`, `mds-commerce` (Paid Extensions)  
> **Target Compatibility:** WordPress `7.0+` (`Tested up to: 7.x`), PHP `8.0+`, Single-Site & Multisite Hub-and-Spoke  
> **Date:** 2026-11-26

---

## Feature Goal & Constraints

Replace four separate, inconsistent credential-storage implementations across the MDS plugin suite with a **single, centralized, zero-dependency, cryptographically modern Secrets Management layer** that:

1. **Respects Free Core vs. Paid Tier Boundaries (`mds-product-tiers.md`):** `mds-release-manager` Core owns ONLY a 100% neutral, service-agnostic `SecretVault` API, `ExtensionApi` bridge, and optional **Secrets / Credentials** UI host. Core never hardcodes paid plugin slugs, DSP names, or upsells—paid plugins register their own secret schemas dynamically via filters.
2. **Eliminates Duplicate Keys Across Sibling Plugins:** Today, `spotify` and `musicfetch` credentials are configured separately in both `mds-audio-providers` and `mds-metadata`, and GitHub updater tokens are scattered across constants. A shared namespace allows entering a service's Client ID & Secret **once** and securely sharing it across any active MDS plugin.
3. **Protects Secrets Against Database Leaks, Staging Clones, and Cross-Plugin Replay:** Never stores secrets in plain text, supports environment variables (`getenv()` / `$_ENV`) and `wp-config.php` constants, binds ciphertexts via AEAD Associated Data (AAD), supports zero-downtime key rotation, and never leaks raw secrets to REST, MCP/Abilities, logs, or HTML.
4. **Works Seamlessly on Single-Site & Multisite:** Supports Network-wide (`site_option`) credentials for shared label APIs (e.g., Spotify, Apple Music, Gemini) with optional per-site (`option`) overrides for site-specific stores (e.g., WooCommerce/Shopify keys on individual Spoke stores).

---

## Native WordPress Core & PHP 8.0+ Capabilities

| Capability | WP / PHP Version | Fit | Notes & Architectural Verdict |
| :--- | :--- | :---: | :--- |
| **PHP `libsodium` AEAD (`sodium_crypto_aead_xchacha20poly1305_ietf_*`)** | PHP `7.2+` / bundled in PHP `8.0+` | **Excellent** | 256-bit key, **192-bit (24-byte) random nonce** (safe for unlimited `random_bytes()` calls with zero collision risk), constant-time software execution, and **Additional Authenticated Data (AAD)** to bind ciphertext to `namespace|key|scope|blog_id`. Preferred primary cipher. |
| **PHP OpenSSL `aes-256-gcm`** | PHP `7.1+` | **Strong Fallback** | FIPS-friendly AEAD fallback (12-byte random IV + 16-byte tag + AAD) only when `libsodium` is disabled by a host. |
| **WordPress Core Secrets API (`wp_get_secret()` / `wp_set_secret()` / `wp-content/secrets.php`)** | Emerging WP `7.x` | **Adopt via Detect-and-Delegate** | When present (`function_exists( 'wp_get_secret' )`), delegate encrypted storage/retrieval directly to Core's native Secrets API (automatically inheriting any host `wp-content/secrets.php` HSM/KMS drop-in!). |
| **WordPress 7.0 Connectors API (`Settings > Connectors`)** | WP `7.0+` | **Avoid for DB Storage** | UI-submitted keys are stored in **plain text** in `wp_options` by default. Do **not** store MDS secrets in Connectors DB options; however, our resolution cascade can optionally read standard `ENV` / `wp-config.php` constants (e.g., `OPENAI_API_KEY`, `GEMINI_API_KEY`) as a read-only fallback. |
| **Environment Variables (`getenv()` / `$_ENV`) & `wp-config.php` Constants** | Native PHP / WP | **Essential (Highest Priority)** | Zero-DB-exposure path compatible with Docker, Kubernetes, Doppler CLI, Infisical CLI, AWS Parameter Store/Secrets Manager agents, and LocalWP. |
| **Dedicated Master Key (`MDS_RM_SECRETS_KEY` / `WP_SECRETS_KEY`) + `wp_salt()` Fallback** | Native WP | **Essential** | Prefer an explicit 32-byte base64 master key in `wp-config.php` / `ENV` (with `_OLD` key-ring support for rotation). Fall back to `hash_hkdf( 'sha256', wp_salt('auth') . '|' . wp_salt('secure_auth'), 32, 'mds-secret-vault-v1' )` so zero-config installs still encrypt at rest out of the box—with an admin health notice recommending a dedicated `MDS_RM_SECRETS_KEY`. |
| **Multisite `get_site_option()` vs. `get_option()`** | Native WP Multisite | **Essential** | Enables a 2-tier vault: **Network Vault** (`mds_rm_network_secrets`, manageable only by `manage_network_options`) + **Site Vault** (`mds_rm_site_secrets`, manageable by `manage_options`), with explicit per-schema scoping (`network_or_site`, `site_only`, `network_only`). |
| **`@wordpress/components` + `SlotFill` / `TabPanel` + `wp.a11y.speak()`** | WP `7.0+` | **Excellent** | Powers a unified **Secrets & Credentials** admin screen (or tab under **Releases → Settings** / **Network Admin**) with masked inputs, source badges (`ENV`, `Constant`, `WP Secrets`, `Network Vault`, `Site Vault`), live **Test Connection** buttons, and a **Key Rotation** action—all Section 508 compliant. |
| **WordPress Abilities API (`wp_register_ability`)** | WP `7.0+` | **Status-Only (Never Raw Secrets)** | Expose ONLY a read-only `mds/secrets-status` ability returning `{ configured, source, masked, rotated_at }`—**never** expose raw secret values via Abilities, MCP, or REST `GET`. |
| **Site Health (`site_status_tests`)** | Native WP | **High Value** | Register a Site Health check warning if `libsodium` is missing, if secrets fall back to `wp_salt()` instead of a dedicated `MDS_RM_SECRETS_KEY`, or if a legacy key needs rotation. |

---

## Ecosystem Interoperability & Collision Avoidance

### Current Workspace Fragmentation (Live Audit)

| Plugin | Current Class | Option Key | Cipher / Key Derivation | Gaps Found |
| :--- | :--- | :--- | :--- | :--- |
| **`mds-release-manager`** | `GitHubUpdater` only | None (constants only) | Plain `MDS_RM_GITHUB_TOKEN` | No encrypted vault or shared primitive for extensions. |
| **`mds-audio-providers`** | `MDS\AudioProviders\Providers\Credentials` | `mds_ap_credentials` | OpenSSL `aes-256-gcm` / `cbc+hmac` / `b64:` via `sha256(auth\|secure_auth)` | No `libsodium`, no AAD, no `ENV` support, no key rotation, duplicates Spotify & Musicfetch keys. |
| **`mds-metadata`** | `MDS\Metadata\Core\CredentialStore` + `ImportColumnMapper` | `mds_md_credentials` | Separate store + direct constant checks | Duplicates Spotify & Musicfetch keys; inconsistent with `mds-audio-providers` and `mds-commerce`. |
| **`mds-commerce`** | `MDS\Commerce\Core\CredentialVault` | Store-connection meta/options | `sodium_crypto_secretbox` (`mds_sodium_v1:`) + `aes-256-gcm` fallback via `wp_salt('auth')` | Best cipher so far, but isolated to `mds-commerce`, no AAD, no key rotation, no `ENV` cascade. |

### Zero-Collision & Clean-Core Design Rules

1. **No Heavy Third-Party Composer Crypto Libraries:** Skip `paragonie/halite` and `defuse/php-encryption`—PHP `8.0+` bundles `libsodium` natively, avoiding Composer dependency conflicts across multiple active plugins in the same WordPress process.
2. **Transparent Legacy Auto-Migration (Zero Breaking Changes):** Each extension (`mds-audio-providers`, `mds-metadata`, `mds-commerce`) keeps its existing public wrapper class (`Credentials`, `CredentialStore`, `CredentialVault`) as a thin adapter that delegates to `MDS\ReleaseManager\Api\ExtensionApi::secrets()` when available, automatically migrating any legacy encrypted values on first read and falling back cleanly if run standalone.
3. **Enterprise Drop-In Hook (`mds_rm_resolve_secret`):** Expose a neutral filter before local DB lookup so agencies/hosts using AWS Secrets Manager, HashiCorp Vault, Doppler, or Infisical can plug in a 20-line mu-plugin adapter without touching plugin code.

---

## Recommended Native Architecture

- **Core Owner (`mds-release-manager`):**
  - `MDS\ReleaseManager\Core\Security\SecretVault` (pure crypto + resolution cascade + key-ring rotation + audit metadata)
  - `MDS\ReleaseManager\Core\Security\SecretRegistry` (neutral schema registry via `mds_rm_register_secret_schemas` filter—extensions declare `{ namespace, label, fields[], scope, env_map, const_map, test_callback }`)
  - `MDS\ReleaseManager\Api\ExtensionApi` façade (`get_secret()`, `set_secret()`, `delete_secret()`, `get_secret_status()`, `register_secret_schema()`)
  - Unified **Credentials** workspace inside **Releases → Settings → Credentials** (and **Network Admin → Releases → Credentials** on Multisite), rendered ONLY when at least one schema is registered (preserving Core's 100% Clean-Core / zero-nag policy!).
- **Cipher Envelope Format:**
  - `mds_v2:<kid>:xchacha:<base64(nonce24 || ciphertext+tag16)>` (primary, `sodium_crypto_aead_xchacha20poly1305_ietf_encrypt` with AAD = `"{namespace}|{field}|{scope}|{blog_id}"`)
  - `mds_v2:<kid>:aesgcm:<base64(iv12 || tag16 || ciphertext)>` (fallback when `libsodium` is unavailable, with identical AAD)
- **Write-on-Read Key Rotation:** When `MDS_RM_SECRETS_KEY_OLD` (or previous key-ring entry) successfully decrypts a secret, `SecretVault` transparently re-encrypts it with the current key (`kid`) and persists the upgraded envelope—plus an explicit **"Re-encrypt All Secrets"** button for immediate rotation after updating `wp-config.php`.

---

## Build-vs-Reuse Decisions & Open Questions

- **Build (in `mds-release-manager` Core):** Lightweight, zero-dependency `SecretVault` + `SecretRegistry` (~350–450 LOC) built on PHP 8.0+ native `libsodium` XChaCha20-Poly1305 + HKDF + AAD, with automatic detection/delegation to WordPress Core's Secrets API when available.
- **Reuse:** Native `libsodium`, native `getenv()`/`$_ENV` for external SaaS vaults (Doppler/Infisical/Vault Agent), and existing Core `ExtensionApi` + `@wordpress/components` primitives.
- **Open Questions:** See the consolidated decision checklist in [secret-vault-feasibility.md](secret-vault-feasibility.md).
