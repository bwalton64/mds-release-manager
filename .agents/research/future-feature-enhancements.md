# MDS Metadata — Future Feature Enhancements

Extracted from the UPC/EAN Metadata Research Blueprint. Because `mds-metadata` persists raw provider JSON payloads (`_mds_md_research_snapshot`) and exposes a modular provider registry (`mds_md_register_providers`), these same external APIs unlock high-value future capabilities:

## 1. One-Click Streaming Links Auto-Population (Odesli / Songlink + Provider URLs)
- Take a matched Spotify, Deezer, or Apple Music album URL, pass it through Odesli / Songlink, and automatically populate the **Streaming Platforms** repeater (`streaming_links`) for Spotify, Apple Music, YouTube Music, Tidal, Amazon Music, and Bandcamp in one click.
- *(Note: On-demand Odesli link expansion is already wired in the Research Modal; future work can add automatic post-search enrichment, provider-name normalization against `mds_rm_providers`, and bulk link backfilling.)*

## 2. Tracklist & ISRC Harvesting (Spotify, Deezer, iTunes, MusicBrainz, Discogs)
- The stored raw payloads already contain full tracklists, disc numbers, durations, and ISRCs — enabling a future **Tracklist** tab & Gutenberg block (`mds-rm/release-tracklist`) and per-track audio preview matching.

## 3. High-Res Cover Art Sideloading (Cover Art Archive, iTunes, Deezer, Spotify)
- Pull front/back covers and liner-note booklets directly into the WordPress Media Library (`media_sideload_image()` with strict MIME/size validation) and set the Release Featured Image (`_thumbnail_id`) with one click.

## 4. Artist CPT Profile Enrichment (MusicBrainz, TheAudioDB, Last.fm, Spotify)
- Add a companion **"Research Artist"** modal on the `artist` CPT to pull official website URLs, artist biographies, high-res artist portraits/banners, and MusicBrainz Artist IDs.

## 5. Catalog-Wide Bulk Enrichment & Label Discography Importer
- Batch-enrich existing Releases missing labels, UPCs, or streaming links via a resumable REST queue (mirroring `SyncQueue`), or import an entire artist/label discography from MusicBrainz or Discogs as draft Releases.

## 6. Genre, Mood & Community Tag Suggestions (Last.fm, Discogs Styles, ListenBrainz)
- Auto-suggest `release_tag` taxonomy terms from Discogs `genres`/`styles` and Last.fm top album tags.

## 7. Deferred v1 Field Integrations
- **Featured Image (Cover Art)** sideloading into the WordPress Media Library.
- **Media Format (Buy Now)** mapping from physical pressing formats (`release_media_format` / `buy_now_links`).
- **Centralized Credential Vault (Optional Cross-Plugin Refactor):** Move encrypted provider credential storage into `mds-release-manager` core so `mds-metadata` and `mds-audio-providers` can share credentials cleanly when desired.
