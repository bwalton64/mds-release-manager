# MDS Commerce — AI Enhancement & Automation Audit

**Topic:** `commerce-platforms`  
**Scope:** Evaluating where BYO-LLM APIs and WordPress Abilities / MCP add genuine leverage to `mds-commerce` versus where deterministic PHP/JS code is faster, cheaper, and safer.  
**Date:** 2026-11-26

---

## 1. Executive Verdict

**Keep 85%+ of `mds-commerce` strictly deterministic.** Product linking, UPC/EAN/SKU matching, drift detection, rate-limit backoff, Wix `revision` locking, Shopify GraphQL mutations, Discogs CSV generation, and Multisite syncing require 100% repeatable accuracy—LLMs add latency, cost, and hallucination risk to these tasks.

**AI wins in 3 targeted, optional, human-in-the-loop workflows:**
1. **Platform-Tailored Product Copy & Merch Descriptions:** Turning terse release metadata (title, artist, format, tracklist, release date, label) into platform-appropriate storefront copy (Shopify SEO description + bullet specs, Bandcamp liner-note blurb, Wix product summary) grounded strictly in verified Release fields.
2. **Fuzzy Catalog Reconciliation (Existing Store Onboarding):** When an artist connects an *existing* Shopify, Wix, or Big Cartel store that lacks UPC/GTIN barcodes on older listings (e.g., `"Midnight Echoes - Limited Splatter LP"` vs. Hub Release `"Midnight Echoes"`), a fast/cheap LLM can propose ranked release-to-product match candidates for 1-click human approval.
3. **WordPress Abilities / MCP Agentic Operations:** Exposing safe, schema-validated `mds-commerce/*` Abilities (`meta.mcp.public`) so label managers can ask an external AI assistant (e.g., *"Check which 2026 releases are missing from our Shopify store and prepare draft payloads"*) without giving the agent raw Shopify/Wix API keys.

---

## 2. Use-Case Feasibility Matrix

*Unit economics calculated using verified 2025–2026 API rates: **GPT-4o-mini** (\$0.15/1M input, \$0.60/1M output) and **Gemini 2.5 Flash** (\$0.30/1M input, \$2.50/1M output; 50% off via Batch API).*

| Task | AI vs. Deterministic | Recommended Model / API | Est. Cost / 1,000 Items | Risk & Guardrail | Verdict |
| :--- | :--- | :--- | :--- | :--- | :--- |
| **UPC / EAN / SKU Matching & Drift Detection** | **Deterministic** | Pure PHP (`ProductDrift`) | \$0.00 | Exact string/digit normalization; zero hallucination tolerance | **Keep Deterministic** |
| **Rate Limiting, Retries & GraphQL Cost Pacing** | **Deterministic** | Pure PHP (`RateLimiter`) | \$0.00 | Deterministic header/cost math | **Keep Deterministic** |
| **Discogs CSV / Bandcamp Prep Export** | **Deterministic** | Pure PHP | \$0.00 | Strict column schemas | **Keep Deterministic** |
| **Product Copy & SEO Description Drafting** | **AI (Opt-in)** | BYO-Key: GPT-4o-mini or Gemini 2.5 Flash (~600 in / ~300 out tokens) | **\$0.27** (GPT-4o-mini) / **\$0.93** (Gemini 2.5 Flash) | Grounded strictly on Release JSON; **human-in-the-loop review** in Create Product modal before seeding | **High-ROI Optional Feature (Phase 2)** |
| **Fuzzy Legacy Store Matching (No UPC/SKU)** | **Hybrid** (Deterministic trigram/Levenshtein first → AI tie-breaker) | BYO-Key: GPT-4o-mini / Gemini 2.5 Flash (~800 in / ~150 out tokens) | **\$0.21** (GPT-4o-mini) / **\$0.62** (Gemini 2.5 Flash) | Never auto-link; output confidence score + rationale for admin 1-click confirm | **High-ROI Optional Feature (Phase 2)** |
| **Taxonomy / Tag & Merch Category Normalization** | **AI (Opt-in)** | BYO-Key: GPT-4o-mini / Gemini 2.5 Flash (~400 in / ~100 out tokens) | **\$0.12** (GPT-4o-mini) / **\$0.37** (Gemini 2.5 Flash) | Structured JSON Schema constrained to target store's actual category/collection list | **Medium-ROI Optional Feature (Phase 3)** |
| **Cover Art QA (Resolution / Aspect / Print-Ready Check)** | **Deterministic** (PHP `getimagesize`) | Pure PHP (no vision LLM needed) | \$0.00 | Deterministic pixel/DPI/aspect checks are instant and free | **Keep Deterministic** |
| **Catalog & Drift Management via External AI Agents** | **WordPress Abilities / MCP** | Core `wp_register_ability()` (`meta.mcp.public`) | \$0.00 (plugin cost) | Read-only + Draft-only abilities; capability-gated (`edit_posts`); no destructive publish/delete via MCP | **Build Now (Phase 1–2)** |

---

## 3. Guardrails, Privacy & Zero-Key Fallback Architecture

1. **Zero-Key Fallback (100% Functional Without AI):**
   - `mds-commerce` must never require an LLM API key. When no key is configured, the Create Product modal uses deterministic templates (Release Excerpt + formatted metadata table), and legacy store matching uses deterministic UPC → SKU → normalized title similarity.
2. **Strict Grounding & Structured Outputs:**
   - Every AI prompt receives *only* the verified Hub Release JSON (title, artist, release date, label, formats, tracklist, existing bio/excerpt) and target store constraints.
   - Enforce JSON Schema response formatting (`response_format` / `responseSchema`) so malformed outputs fail gracefully to deterministic templates.
3. **Human-in-the-Loop Auto-Diff Review:**
   - AI-generated descriptions, tags, or fuzzy match suggestions are **never** written silently to a live store. They populate editable preview fields or a side-by-side diff modal that requires an explicit admin click to apply.
4. **Privacy & Secret Isolation:**
   - Never send store OAuth tokens, customer/order PII, private financial data, or internal site URLs to any LLM provider.
   - Require explicit opt-in consent in Settings before enabling outbound LLM calls, and support `wp-config.php` constants (`MDS_CM_AI_API_KEY`) alongside encrypted option storage.
   - Reuse any shared BYO-AI client/filter if already configured in sibling plugins (e.g., `mds-metadata` or a shared `mds_ai_generate_text` filter) so users only enter their API key once across the MDS suite.

---

## 4. WordPress Abilities / MCP Opportunities

Following the pattern established in `MDS\ReleaseManager\Api\Abilities`, `mds-commerce` should register safe, audited abilities via `wp_register_ability()` with `meta.mcp.public = true`:

| Ability Name | Mode | Capability Gate | Purpose |
| :--- | :--- | :--- | :--- |
| `mds-commerce/list-stores` | Read-only | `edit_posts` | Returns configured store connections (provider, label, capabilities—never credentials) and artist assignments. |
| `mds-commerce/get-release-links` | Read-only | `edit_posts` | Returns all linked storefront products and live Buy Now URLs for a given Release ID or UPC. |
| `mds-commerce/check-drift` | Read-only | `edit_posts` | Runs deterministic drift comparison between a Release and its linked store products, returning field-level diffs. |
| `mds-commerce/prepare-draft-payload` | Read-only / Preview | `edit_posts` | Generates a validated, provider-ready draft product payload (and optional Bandcamp/Discogs export) for human review. |
| `mds-commerce/seed-draft-product` | Write (Draft Only) | `edit_post` + store access | Creates a **Draft** (never auto-published) product on an assigned store, returning the admin edit URL for human verification. |

*Deliberately omitted from MCP:* Bulk live price edits, product deletion, and credential modification remain strictly admin-UI-only actions.

---

## 5. Recommended Phased Rollout & Open Questions

1. **Phase 1 (Launch `mds-commerce`):** Zero LLM dependencies. Ship deterministic adapters + read-only `mds-commerce/*` WordPress Abilities for MCP.
2. **Phase 2 (Optional BYO-LLM Assist):** Add opt-in "Draft Copy with AI" button in the Create Product modal and "AI-Assisted Match" in the Legacy Store Importer using a shared MDS BYO-key adapter (Gemini Flash / OpenAI mini).
3. **Phase 3 (Cross-Suite AI Alignment):** Unify BYO-LLM key settings across `mds-release-manager`, `mds-metadata`, and `mds-commerce` via a shared filter or `ExtensionApi` helper.

### Open Questions
1. **Shared AI Configuration:** Do you already have (or plan to add) a centralized BYO-LLM key setting in `mds-release-manager` or `mds-metadata` that `mds-commerce` should hook into rather than adding its own API key field?
2. **MCP Write Scope:** Are you comfortable exposing `mds-commerce/seed-draft-product` (Draft-only) over MCP, or should MCP abilities in `mds-commerce` stay strictly read-only at launch?
