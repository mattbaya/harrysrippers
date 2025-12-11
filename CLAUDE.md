# CLAUDE.md - Project Guide for Claude Code

## Project Overview

Harry's Rippers is a PHP web application for converting YouTube videos to MP3 files with automatic AI-powered metadata extraction.

## Key Files

- `index.php` - The entire application (single-file PHP app with embedded HTML/CSS/JS)
- `.env` - Contains `OPENAI_API_KEY` (not in git)
- `downloads/` - MP3 files and their `.meta` JSON sidecar files
- `rip_log.json` - JSON log of recent rips (last 100 entries)

## Architecture

### Single-File Application
Everything is in `index.php`:
- **Lines 1-618**: PHP backend (config, handlers, utility functions)
- **Lines 619-1151**: HTML structure and CSS styles
- **Lines 1151-end**: HTML templates and JavaScript

### Data Storage

**MP3 Files**: Stored in `downloads/` with pattern `{title}-{video_id}.mp3`

**Metadata Files**: Each MP3 has a `.meta` JSON sidecar file:
```json
{
  "url": "https://youtube.com/watch?v=...",
  "timestamp": 1234567890,
  "video_title": "Original Video Title",
  "artist": "Artist Name",
  "title": "Song Title",
  "album": "Album Name",
  "summary": "AI-generated description",
  "lyrics_url": "https://genius.com/..."
}
```

**Download Tracking**: User download history is stored in browser localStorage under key `downloadedFilesV2` as a JSON object mapping filenames to timestamps.

## Key Dependencies

- `yt-dlp` at `/home/harry/bin/yt-dlp` - Video downloading
- `ffmpeg` at `/home/harry/bin/ffmpeg` - Audio conversion and metadata embedding
- OpenAI API (GPT-4o-mini) - Metadata parsing

## Common Tasks

### Adding a New File Action Button
1. Add CSS for the button style (around line 890-940)
2. Add the button HTML in the file list loop (around line 1300-1340)
3. Add JavaScript handler function (around line 1460-1550)
4. If POST handler needed, add PHP handler at top of file (around line 90-260)

### Modifying Metadata Parsing
The OpenAI prompt is in the `parseVideoTitle()` function (around line 496-576). Modify the prompt to change what information is extracted.

### Updating the UI Layout
- CSS styles: lines 626-1150
- File list HTML: lines 1244-1365
- Modals: lines 1600-1680

## Important Patterns

### Form Handlers
POST handlers redirect after processing to prevent form resubmission:
```php
if (isset($_POST['action'])) {
    // Process...
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}
```

### File Operations
Always use `basename()` on user input for security:
```php
$filename = basename($_POST['filename']);
$filepath = $downloadsDir . '/' . $filename;
```

### Metadata Updates
When updating metadata, update both the `.meta` file AND the MP3 ID3 tags:
```php
file_put_contents($metaPath, json_encode($metaData));
updateMp3Metadata($filepath, $metadata, $sourceUrl);
```

## Testing

No formal test suite. Test manually by:
1. Converting a YouTube video
2. Checking metadata appears correctly
3. Testing each action button (play, edit, trim, download, delete)
4. Verifying file cleanup after edits

## Notes

- Files auto-delete after 10 days (`$maxFileAge = 864000`)
- Download button tracking uses localStorage (per-browser, not synced)
- Thumbnails are fetched from YouTube's img.youtube.com CDN
