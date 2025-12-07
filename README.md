# Harry's Rippers 🎵

A web-based MP3 converter that downloads videos from YouTube and other platforms, converts them to MP3 format, and automatically adds intelligent metadata using AI. The application intelligently parses video titles to extract artist, song, and album information, then embeds this metadata directly into the MP3 files for proper organization in your music library.

## Features

### 🎬 Video to MP3 Conversion
- **Multi-Platform Support**: Convert videos from YouTube and many other platforms to MP3 audio
- **Simple Interface**: Paste a URL and click "Convert" (or press Enter)
- **High-Quality Audio**: Downloads and converts to MP3 format using yt-dlp and ffmpeg
- **Automatic Processing**: One-click conversion from video to tagged MP3 file

### 🤖 AI-Powered Metadata Intelligence

The application uses OpenAI's GPT-4o-mini to automatically parse video information and extract meaningful metadata. This is one of the most powerful features of Harry's Rippers.

**How It Works:**
1. When you submit a video URL, the system fetches:
   - The video's **title** (e.g., "Ripple (Grateful Dead) feat. Bill Kreutzmann | Playing For Change")
   - The video's **description** (first 500 characters for context)
2. Both the title and description are sent to ChatGPT with a specialized prompt asking it to parse and extract:
   - **Artist name** - Who is ACTUALLY PERFORMING (crucial for covers - e.g., "Playing For Change" not "Grateful Dead")
   - **Song title** (e.g., "Ripple")
   - **Album name** if mentioned (or empty if not applicable)
   - **Song summary** - A brief 1-2 sentence description of the song (genre, mood, historical significance)
   - **Lyrics link** - Direct URL to lyrics on sites like Genius or AZLyrics (only if certain of the exact URL)
3. ChatGPT analyzes both title and description to accurately identify the actual performer (especially important for cover songs)
4. The application uses ffmpeg to embed metadata (artist/title/album/URL) into the MP3 file's ID3 tags
5. Additional information (summary, lyrics link) is stored in the .meta file for display
6. Video thumbnails are extracted from YouTube and displayed automatically

**What This Means:**
- Your downloaded MP3 files will show up correctly in iTunes, Spotify, and other music players
- Files are automatically organized with proper artist/song/album information
- Each song displays an AI-generated summary describing the song
- Direct access to lyrics via clickable link (when available)
- No manual tagging or searching required - it's all done automatically

### 📁 Advanced File Management

#### Display & Organization
- **Multi-Level Display**: Each file shows comprehensive information:
  1. **Video Thumbnail**: 1.5-inch square thumbnail from YouTube video (reliable and always available)
  2. **Original YouTube Title**: The raw title from the video (in small gray text)
  3. **Parsed Metadata**: Clean display as "**Artist** - Title (Album)"
  4. **Filename**: The actual file stored on disk
  5. **AI-Generated Summary**: Full-width description of the song in italics below action buttons (genre, mood, significance)
  6. **Lyrics Link**: Clickable link to lyrics on reputable sites (when available)
- **Timestamp Tracking**: Shows when each file was downloaded with smart formatting:
  - "Just now" for files downloaded < 1 minute ago
  - "5 mins ago" for recent downloads
  - "2 hours ago" for today's downloads
  - "3 days ago" for this week
  - Full date "Dec 7, 2025 3:45 pm" for older files
- **File Size Display**: Shows file size in appropriate units (KB, MB, GB)

#### Interactive Controls
Each file has multiple action buttons:

- **▶️ Play**: Built-in audio player for instant playback without downloading
- **▶ View Original**: Link to the original YouTube/video source
- **🏷️ Edit Metadata**: Manually edit artist, title, and album information
  - Opens a modal with input fields pre-filled with current metadata
  - Updates both the .meta file and the MP3's ID3 tags
  - Perfect for fixing AI parsing errors or adding missing information
- **↩️ Restore Original Title**: Rename the file back to the original YouTube title
  - Useful if you want the filename to match the video title exactly
  - Sanitizes the title to be filesystem-safe
  - Preserves all metadata in the .meta file
- **✏️ Rename**: Change the filename to anything you want
- **✂️ Trim Audio**: Cut unwanted sections from the beginning or end of the audio
  - Opens a modal with audio player and time inputs
  - Supports both MM:SS format and seconds (e.g., "1:30" or "90")
  - Uses ffmpeg to precisely trim the audio file
  - Replaces the original file with the trimmed version
  - Perfect for removing intros, outros, or unwanted sounds
- **📥 Download**: Download the MP3 file to your computer
- **🗑️ Delete**: Remove the file from the server

### 🔧 Metadata Management System

Harry's Rippers uses a dual-metadata system to ensure your files are properly tagged:

**1. .meta Files (Internal)**
- Each MP3 has a corresponding `.meta` file (e.g., `song.mp3.meta`)
- Stores JSON data including:
  ```json
  {
    "url": "https://youtube.com/watch?v=...",
    "timestamp": 1234567890,
    "video_title": "Original Video Title",
    "artist": "Artist Name",
    "title": "Song Title",
    "album": "Album Name"
  }
  ```
- Used by the application to display rich information
- Preserved during file renames

**2. MP3 ID3 Tags (Embedded)**
- Metadata is embedded directly into the MP3 file using ffmpeg
- Standard ID3v2 tags are set:
  - `artist`: Artist name
  - `title`: Song title
  - `album`: Album name
  - `comment`: Original source URL
- These tags work with any music player (iTunes, VLC, Spotify, etc.)
- Travel with the file even after download

**Why Both?**
- .meta files allow the web interface to show rich information quickly
- ID3 tags ensure the file is properly tagged for use anywhere
- When you edit metadata, both are updated simultaneously

### 🔒 Security & Privacy
- API keys stored in `.env` file (not committed to git)
- Automatic file cleanup after 10 days
- Files stored securely on server

### 📊 Activity Logging
- Track recent conversions with timestamps
- View file sizes and source URLs
- Keep history of last 100 conversions

## Technology Stack

- **Backend**: PHP
- **Video Download**: yt-dlp
- **Audio Processing**: ffmpeg
- **AI Integration**: OpenAI API (GPT-4o-mini)
- **Frontend**: Vanilla JavaScript, CSS3
- **Version Control**: Git/GitHub

## Setup

1. Clone the repository
2. Create a `.env` file with your OpenAI API key:
   ```
   OPENAI_API_KEY=your-api-key-here
   ```
3. Ensure `yt-dlp` and `ffmpeg` are installed and accessible
4. Configure paths in `index.php` if needed
5. Deploy to web server with PHP support

## File Structure

- `index.php` - Main application file
- `.env` - Environment variables (API keys)
- `downloads/` - Temporary storage for MP3 files
- `rip_log.json` - Activity log
- `.gitignore` - Excludes sensitive and temporary files

## How It Works: Complete Process Flow

### Initial Download Process

1. **User Input**: User pastes a video URL and clicks "Convert" (or presses Enter)

2. **Video Information Gathering**:
   - yt-dlp connects to the video platform
   - Extracts the video title (e.g., "Queen - Bohemian Rhapsody (Official Video)")
   - Title is stored for later processing

3. **Download & Conversion**:
   - yt-dlp downloads the video
   - Extracts audio track
   - Converts to MP3 format using ffmpeg
   - Saves to `downloads/` directory with filename pattern: `{title}-{video_id}.mp3`

4. **AI Metadata Parsing**:
   - Video title is sent to OpenAI's GPT-4o-mini API
   - Specialized prompt asks ChatGPT to parse the title into structured data
   - ChatGPT returns JSON: `{"artist": "Queen", "title": "Bohemian Rhapsody", "album": ""}`
   - If parsing fails or API is unavailable, file is still saved (just without parsed metadata)

5. **Metadata Embedding**:
   - ffmpeg updates the MP3 file's ID3 tags with:
     - Artist, Title, Album from ChatGPT
     - Comment field with original video URL
   - Creates `.meta` file with complete information:
     - Original URL
     - Download timestamp
     - Video title
     - Parsed artist/title/album

6. **Display**:
   - File appears in the "Available Files" list
   - Shows original title, parsed metadata, and filename
   - All action buttons become available

### Metadata Editing Process

When you click the 🏷️ **Edit Metadata** button:

1. Modal opens with form pre-filled with current artist, title, and album
2. You can modify any field
3. On submit:
   - `.meta` file is updated with new values
   - ffmpeg re-processes the MP3 file to update ID3 tags
   - Page refreshes to show updated information
4. The changes are permanent and embedded in the file

### Restore Original Title Process

When you click the ↩️ **Restore Original Title** button:

1. Confirmation dialog shows the original video title
2. On confirmation:
   - Original title is sanitized for filesystem safety:
     - Special characters replaced with underscores
     - Spaces replaced with underscores
     - `.mp3` extension ensured
   - File is renamed on disk
   - `.meta` file is also renamed to match
   - All metadata is preserved
3. Page refreshes showing the renamed file

### File Lifecycle

```
1. Video URL submitted
   ↓
2. Download + Convert to MP3
   ↓
3. AI parses title → metadata
   ↓
4. Embed metadata in MP3 (ID3 tags)
   ↓
5. Create .meta file
   ↓
6. File available for:
   - Play/Stream
   - Download
   - Rename
   - Edit metadata
   - Restore title
   - Delete
   - View summary
   - Access lyrics
   ↓
7. After 10 days: Automatic cleanup
   (Files and .meta files deleted)
```

## Credits

Built with ❤️ using Claude Code, with a little help from Matthew Baya (matt@baya.net)
