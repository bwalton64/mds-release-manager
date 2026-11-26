# MDS Commerce — WordPress 7.x Core Capabilities & Ecosystem Interoperability

**Topic:** `commerce-platforms`  
**Target Environment:** WordPress `7.0+` (Tested up to `7.x`) · PHP `8.0+` · Multisite Hub-and-Spoke · `MDS Release Manager` (`MDS_RM_API_VERSION = 2.0.0+`)  
**Date:** 2026-11-26

---

## 1. Feature Goal & Constraints

`mds-commerce` (evolved from `mds-woocommerce`) will manage connections to multiple local and remote shopping platforms (WooCommerce Local/Remote, Shopify, Wix, Big Cartel, Squarespace, Discogs/Bandcamp prep) from a single WordPress Hub while keeping Spoke sites lightweight and read-only.

**Hard Constraints:**
- **Zero Heavy Composer/npm Bloat:** Avoid bundling giant third-party vendor SDKs (e.g., official Shopify/Wix PHP SDKs with dozens of Guzzle/PSR dependencies that cause dependency hell in WordPress). Use native `wp_remote_request()` with thin, typed PHP 8.0+ client classes.
- **Multisite Hub/Spoke Discipline:** External API credentials and store connections live on the **Hub** (or network site options), never exposed to Spoke sites or frontend HTML. Spokes only receive sanitized public link metadata (`permalink`, `price`, `availability`, `provider`).
- **Section 508 Accessibility:** All admin connection wizards, store cards, drift diff modals, and frontend buy/embed blocks must follow WCAG 2.1 AA with `wp.a11y.speak()` announcements.
- **Strict PHP 8.0+ & WPCS 3.x Compliance:** Dual PHP 8.0 native types + PHPDoc annotations, `LF` line endings, and clean uninstall parity.

---

## 2. Native WordPress Core Capabilities

| Capability | WP Version | Fit | Implementation Notes for `mds-commerce` |
| :--- | :--- | :--- | :--- |
| **HTTP API (`wp_remote_request`)** | Core (all) | **Essential** | Replaces Guzzle/vendor SDKs. Supports GraphQL (`POST` JSON to Shopify), REST (`GET`/`POST`/`PATCH`/`DELETE` to Wix/WC/Big Cartel/Squarespace), custom `User-Agent` (required by Squarespace & Discogs), and response header inspection (`X-RateLimit-*`, `Retry-After`). |
| **PHP Sodium (`sodium_crypto_secretbox`)** | PHP 7.2+ / WP Core | **Essential** | Encrypt stored OAuth tokens and API keys at rest in `wp_sitemeta` / `wp_options` using a key derived from `wp_salt('auth')` (with optional `wp-config.php` constant overrides like `MDS_CM_SHOPIFY_TOKEN_{ID}` for zero-DB secret storage). |
| **Post Meta (`register_post_meta`) + Custom Table Evaluation** | Core 7.0+ | **High** | Keep release links (`_mds_commerce_links`) and artist target stores (`_mds_commerce_target_stores`) in Post Meta so they participate seamlessly in `MDS Release Manager`'s existing Hub-to-Spoke sync and WPML/Polylang config. Store **Store Connections** in a dedicated site/network option (`mds_cm_store_connections`), and only introduce a custom table (`{prefix}mds_cm_sync_log`) if high-volume audit logging (>10k events) is needed later. |
| **Action Scheduler (Detect-and-Use) + WP-Cron / Resumable REST Fallback** | Bundled in WC / Standalone | **High** | Reuse `Action Scheduler` automatically whenever WooCommerce is active (zero extra bundle) for bulk drift checks, webhook processing, and cursor-paged catalog syncs. Fall back cleanly to `MDS Release Manager`'s resumable REST batch pattern (like `POST /artists/{id}/sync-releases`) + WP-Cron when WooCommerce is not installed. |
| **MySQL `GET_LOCK` Mutexes & Transient Cooldowns** | Core `$wpdb` | **High** | Prevent stampede refreshes on `StoreRegistry` and serialize concurrent OAuth token refreshes (e.g., Wix/Bandcamp 1-hour tokens) so two simultaneous requests never invalidate each other's refresh token. |
| **Block Editor & `@wordpress/components` (`Modal`, `TabPanel`, `ComboboxControl`, `DataViews`)** | WP 6.6–7.x | **High** | Use `@wordpress/components` and `@wordpress/dataviews` for the Store Connections manager and Bulk Catalog Drift table, giving a native WordPress 7.x admin experience without custom table frameworks. |
| **Interactivity API & Script Modules (`wp_enqueue_script_module`)** | WP 6.5–7.x | **Medium–High** | Power lightweight frontend Buy Now format-pickers and live stock/price hydration (e.g., fetching cached Shopify/WooCommerce availability without full-page cache busting). |
| **`WP_HTML_Tag_Processor`** | WP 6.2–7.x | **Medium** | Safely inject `data-mds-*` attributes or ARIA labels into rendered block/embed markup (such as Shopify Storefront Web Components or WooCommerce block templates) without brittle regexes. |
| **WordPress Abilities API (`wp_register_ability`)** | WP 7.0+ | **High** | Register structured commerce abilities (with JSON Schema + `meta.mcp.public`) mirroring `MDS\ReleaseManager\Api\Abilities` so AI assistants and external automations can inspect store connections, check drift, and prepare drafts safely. |
| **`wp.a11y.speak()` & Focus Management** | Core | **Essential** | Announce async search results, connection tests, drift sync completions, and rate-limit cooldowns to screen readers in the Release Editor and Store Settings screens. |

---

## 3. Ecosystem Interoperability & Collision Avoidance

| Ecosystem Plugin / Standard | Risk of Collision | Recommended Strategy |
| :--- | :--- | :--- |
| **WooCommerce (HPOS & Block Templates)** | Direct `wp_postmeta` SQL queries break under High-Performance Order Storage (HPOS) or miss `wc_product_meta_lookup`. | Always use WooCommerce CRUD (`wc_get_product()`, `$product->save()`, `WC_Product_Simple`, `wc_update_product_lookup_tables_column()`) inside `WooCommerceLocal`—never raw SQL writes to product/order tables. |
| **Yoast SEO / Rank Math (Schema.org JSON-LD)** | Emitting duplicate standalone `Product` or `MusicAlbum` JSON-LD scripts causes Google Rich Results validation conflicts. | Detect active SEO plugins and hook `wpseo_schema_graph_pieces` / `rank_math/json_ld` to enrich `MusicAlbum` with verified `offers` (`Offer` price, currency, availability, storefront URL). Emit minimal standalone JSON-LD only when no SEO plugin is active (behind a filter). |
| **ShopWP / Shopify Buy Button Plugins** | Duplicate Shopify JS SDK scripts loaded on the same page can clash on global `window.ShopifyBuy`. | Prefer **Shopify Storefront Web Components** (native custom elements) or conditionally enqueue the Storefront script only when an MDS Shopify embed block is rendered on the page, checking `wp_script_is()`. |
| **SureCart / Easy Digital Downloads** | Both manage their own checkout pages and shortcodes. | Treat them as optional local adapters (Tier 3) that link existing products and surface Buy Now URLs/blocks rather than overriding their checkout templates. |
| **Caching Plugins (W3 Total Cache, WP Rocket, Redis/Memcached)** | Stale Buy Now links or stock badges after a sync or link change. | Continue routing all cache purges through `do_action( 'mds_rm_purge_cache', $context, $args )` so `MDS Release Manager`'s centralized cache adapters purge cleanly. |
| **WPML / Polylang** | Serialized link arrays or translated store labels can be overwritten during sync. | Declare `_mds_commerce_links` and `_mds_commerce_target_stores` with explicit `copy` / `ignore` rules in `wpml-config.xml`, and keep transient keys locale-aware via `Config::transient_key()`. |

---

## 4. Recommended Native Architecture

```text
mds-commerce/
├── mds-commerce.php                     # Bootstrap, MDS_CM_* constants, mds_rm_loaded hook
├── uninstall.php                        # Full multisite option/transient cleanup (preserves products & links)
├── src/
│   ├── Config.php                       # Option/meta/transient keys, REST namespaces
│   ├── Plugin.php                       # Service wiring, legacy mds-woocommerce migration check
│   ├── Contracts/
│   │   ├── CommerceProviderInterface.php
│   │   ├── EmbeddableProviderInterface.php
│   │   └── ExportPrepProviderInterface.php
│   ├── Model/
│   │   ├── StoreKey.php                 # Immutable value object ("shopify:abc123", "wc_local:3")
│   │   ├── StoreConnection.php          # Typed store config + capability flags
│   │   ├── NormalizedProduct.php        # Unified DTO (remote_id, title, sku, upc, price, status, url, image)
│   │   └── DriftReport.php              # Field-level diff between Hub Release and Remote Product
│   ├── Security/
│   │   └── CredentialVault.php          # libsodium encrypt/decrypt + wp-config.php constant override
│   ├── Registry/
│   │   ├── ProviderManager.php          # Registers built-in + third-party filterable providers
│   │   └── StoreRegistry.php            # Combines auto-detected local WC stores + saved remote connections
│   ├── Sync/
│   │   ├── DriftEngine.php              # Provider-agnostic drift comparison & selective sync
│   │   ├── RateLimiter.php              # Per-store transient cooldown & Retry-After/GraphQL cost pacing
│   │   └── UpgradeRoutine.php           # Zero-loss migration from mds-woocommerce meta/options
│   ├── Providers/
│   │   ├── WooCommerceLocal/            # Migrated from mds-woocommerce (Multisite + Single Product Injection)
│   │   ├── WooCommerceRemote/           # WC REST API v3 client
│   │   ├── Shopify/                     # Admin GraphQL API client + Storefront Web Component embed
│   │   ├── Wix/                         # Catalog V3 REST client (Tier 2)
│   │   ├── BigCartel/                   # JSON:API v1 client (Tier 2)
│   │   └── Prep/                        # Bandcamp Prep Pack & Discogs CSV Exporter (Tier 2)
│   ├── Integration/
│   │   ├── BuyNowBridge.php             # Filters mds_rm_buy_now_links with synced storefront URLs
│   │   └── SchemaBridge.php             # Enriches Yoast/Rank Math MusicAlbum Offer schema
│   ├── Admin/                           # Store Connections UI, Release Store Cards, Artist Target Stores
│   ├── Rest/                            # mds-commerce/v1 (+ legacy mds-wc/v1 shims)
│   └── Api/
│       └── Abilities.php                # WordPress 7.x Abilities API + MCP registration
```

### Key Native Hook Extensions
- `mds_cm_register_providers` — Allows third-party or private label plugins to register custom `CommerceProviderInterface` implementations without modifying `mds-commerce`.
- `mds_cm_release_payload` — Filters the normalized release payload before seeding a draft product on any provider.
- `mds_cm_user_can_manage_store` — Extends the existing `mds_wc_user_can_manage_spoke_product` two-layer permission check to all store types.

---

## 5. Build-vs-Reuse Decisions & Open Questions

### Build vs. Reuse
- **Reuse `MDS Release Manager` Core Infrastructure:** Do not duplicate artist/release pickers, Multisite Hub/Spoke detection, cache purging, or GitHub updater wiring—consume `MDS\ReleaseManager\Api\ExtensionApi`.
- **Reuse `Action Scheduler` (when present):** Never bundle a second copy; detect `function_exists( 'as_enqueue_async_action' )` and fall back to resumable REST batches.
- **Build Thin HTTP Clients (No Vendor SDKs):** Shopify GraphQL, Wix V3, and Big Cartel each only need ~5–8 endpoints (test auth, search products, get product, create draft, update fields, upload/attach image). Thin `wp_remote_request()` clients stay under ~300 LOC each with zero Composer conflicts.

### Open Questions
1. **Credential Storage Preference:** Should the Store Connections UI support **both** encrypted database storage (`sodium_crypto_secretbox`) and `wp-config.php` constants (recommended for agencies/labels), or constants-only for high-security setups?
2. **Webhook Receiver Endpoint:** Do you want `mds-commerce` to expose an inbound webhook endpoint (`POST /mds-commerce/v1/webhooks/{provider}/{store_id}`) for real-time Shopify/Big Cartel stock/URL updates, or start with on-demand + scheduled polling (simpler for LocalWP/firewalled hubs)?
