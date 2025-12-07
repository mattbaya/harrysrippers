# Harry's Rippers 🎵

A web-based MP3 converter that downloads videos from YouTube and other platforms, converts them to MP3 format, and automatically adds intelligent metadata using AI.

## Features

### 🎬 Video to MP3 Conversion
- Convert videos from YouTube and many other platforms to MP3 audio
- Simple paste-and-convert interface
- Support for Enter key to submit

### 🤖 AI-Powered Metadata
- Automatically parses video titles using ChatGPT (GPT-4o-mini)
- Extracts artist name, song title, and album information
- Embeds metadata directly into MP3 ID3 tags
- Stores source URL in MP3 comment field

### 📁 File Management
- View all downloaded files with rich metadata display
- Shows **Artist - Title (Album)** when available
- Download timestamp tracking with "time ago" formatting
- Built-in audio player for instant playback
- Rename files directly in the interface
- One-click file deletion
- Link to original video source

### 🔒 Security & Privacy
- API keys stored in `.env` file (not committed to git)
- Automatic file cleanup after 8 hours
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

## How It Works

1. User submits a video URL
2. System fetches video title using yt-dlp
3. yt-dlp downloads and converts video to MP3
4. ChatGPT parses the title to extract metadata
5. ffmpeg embeds the metadata into the MP3 file
6. File is ready for download with proper tags

## Credits

Built with ❤️ using Claude Code, with a little help from Matthew Baya (matt@baya.net)
