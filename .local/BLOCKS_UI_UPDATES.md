# Block UI Updates - Dropdown Selectors Implementation

## Date: November 19, 2026
## Status: ✅ COMPLETE

---

## Changes Summary

All three blocks have been updated to replace text/numeric input fields with searchable dropdown selectors for more intuitive selection of releases and artists.

---

## 1. Release-Meta Block Updates

### What Changed
- **Old**: Text input field for "UPC/EAN Override"
- **New**: Searchable dropdown of available releases

### Files Modified
1. **blocks/release-meta/block.json**
   - Added `releaseId` attribute (integer, default: 0)
   - Kept `upcOverride` for backwards compatibility

2. **blocks/release-meta/index.js**
   - Added release loading via REST API (`/wp-json/wp/v2/release`)
   - Added searchable dropdown with filter
   - Top-level option: "Use Current Release" (inherits from context)
   - Displays release titles only (title_only format)
   - Searchable/filterable input

3. **blocks/release-meta/render.php**
   - Updated to use `releaseId` attribute
   - If `releaseId` is 0, inherits from post context
   - If `releaseId` > 0, uses selected release

### Editor UI
```
Release (Optional) ▼ [Use Current Release]
                    Release One
                    Release Two
                    Release Three

Search releases... [_________________]
```

---

## 2. Release-Links Block Updates

### What Changed
- **Old**: Text input field for "UPC/EAN Override"
- **New**: Searchable dropdown of available releases

### Files Modified
1. **blocks/release-links/block.json**
   - Added `releaseId` attribute (integer, default: 0)
   - Kept `upcOverride` for backwards compatibility

2. **blocks/release-links/index.js**
   - Added release loading via REST API
   - Added searchable dropdown with filter
   - Top-level option: "Use Current Release" (inherits from context)
   - Displays release titles only
   - Searchable/filterable input

3. **blocks/release-links/render.php**
   - Updated to use `releaseId` attribute
   - If `releaseId` is 0, inherits from post context
   - If `releaseId` > 0, uses selected release

### Editor UI
```
Release (Optional) ▼ [Use Current Release]
                    Release One
                    Release Two
                    Release Three

Search releases... [_________________]

[✓] Open links in a new tab
[✓] Show audio player
```

---

## 3. Artist-Discography Block Updates

### What Changed
- **Old**: Text input field for "Artist post ID (optional)"
- **New**: Searchable dropdown of available artists

### Files Modified
1. **blocks/artist-discography/block.json**
   - No changes (artistId attribute already existed)

2. **blocks/artist-discography/index.js**
   - Replaced TextControl with SelectControl
   - Added artist loading via REST API (`/wp-json/wp/v2/artist`)
   - Added searchable dropdown with filter
   - Top-level option: "Use Current Artist" (inherits from context)
   - Displays artist names only (title_only format)
   - Searchable/filterable input

3. **blocks/artist-discography/render.php**
   - Updated logic to properly handle context inheritance
   - If `artistId` is 0, inherits from post context
   - If `artistId` > 0, uses selected artist

### Editor UI
```
Artist (Optional) ▼ [Use Current Artist]
                   Artist One
                   Artist Two
                   Artist Three

Search artists... [_________________]
```

---

## Technical Details

### REST API Endpoints Used
- **Releases**: `/wp-json/wp/v2/release?per_page=100`
- **Artists**: `/wp-json/wp/v2/artist?per_page=100`

### Display Format
- **Title Only** - Shows just the release/artist title
- **Example**: "My Album", "My Artist"

### Search/Filter Behavior
- Real-time filtering as user types
- Case-insensitive matching
- Filters by title/name only

### Context Inheritance
- **Default Option**: "Use Current Release" / "Use Current Artist"
- **Value**: 0 (zero)
- **Behavior**: When set to 0, the block inherits from:
  - Current post context (postId)
  - Query loop context
  - Block context

---

## Backwards Compatibility

✅ All changes are backwards compatible:
- Old `upcOverride` attributes on Release blocks still supported
- Old `artistId` attributes work with new dropdown
- Existing posts with these blocks will continue to work
- New blocks can use the new `releaseId` selector

---

## Testing Instructions

### Setup
1. Activate the plugin
2. Navigate to `/blocks-test-page`

### Test Release-Meta Block
- [ ] Block appears in editor
- [ ] Dropdown shows all releases
- [ ] Default shows "Use Current Release"
- [ ] Selecting a release shows it in dropdown
- [ ] Search/filter works for releases
- [ ] Block renders correctly on frontend

### Test Release-Links Block
- [ ] Block appears in editor
- [ ] Dropdown shows all releases
- [ ] Default shows "Use Current Release"
- [ ] Selecting a release shows it in dropdown
- [ ] Search/filter works for releases
- [ ] Other toggle options (new tab, audio) still work
- [ ] Block renders correctly on frontend

### Test Artist-Discography Block
- [ ] Block appears in editor
- [ ] Dropdown shows all artists
- [ ] Default shows "Use Current Artist"
- [ ] Selecting an artist shows it in dropdown
- [ ] Search/filter works for artists
- [ ] Block renders correctly on frontend

---

## Performance Notes

### Data Loading
- Releases and artists loaded on block mount via REST API
- Limited to 100 items per request (should be sufficient for most sites)
- If you have more than 100 releases/artists, consider implementing pagination

### Client-Side Filtering
- Search/filter happens in React (client-side)
- No additional API calls during search
- Instant search results

---

## Future Enhancements

### Potential Improvements
1. Pagination support for sites with 100+ items
2. Lazy loading for large lists
3. Recently used releases/artists (most used)
4. Custom filtering/sorting options
5. Display format options (title + ID, ID only, etc.)

---

## File Changes Summary

| File | Type | Change |
|------|------|--------|
| release-meta/block.json | Config | Added releaseId attribute |
| release-meta/index.js | JavaScript | Added dropdown + search |
| release-meta/render.php | PHP | Updated to use releaseId |
| release-links/block.json | Config | Added releaseId attribute |
| release-links/index.js | JavaScript | Added dropdown + search |
| release-links/render.php | PHP | Updated to use releaseId |
| artist-discography/index.js | JavaScript | Added dropdown + search |
| artist-discography/render.php | PHP | Updated context handling |

**Total Files Modified**: 8
**New Functionality**: Searchable dropdowns with context inheritance
**Breaking Changes**: None (backwards compatible)

---

## Status

✅ **IMPLEMENTATION COMPLETE**

All three blocks now feature intuitive dropdown selectors instead of text/numeric inputs, with:
- Searchable/filterable options
- Context inheritance support ("Use Current" options)
- Backwards compatibility
- Professional UX

Ready for testing on `/blocks-test-page`

---

## Questions or Issues?

If you notice any issues during testing:
1. Check browser console for errors
2. Check PHP error logs for warnings
3. Verify REST API endpoints are accessible
4. Ensure releases and artists are published (not draft)
