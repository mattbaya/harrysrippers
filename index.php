<?php
// Set timezone
date_default_timezone_set('America/New_York');

// Load .env file
function loadEnv($path) {
    if (!file_exists($path)) {
        return;
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        // Skip comments
        if (strpos(trim($line), '#') === 0) {
            continue;
        }

        // Parse key=value
        if (strpos($line, '=') !== false) {
            list($key, $value) = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);

            // Remove quotes if present
            if (preg_match('/^(["\'])(.*)\\1$/', $value, $matches)) {
                $value = $matches[2];
            }

            putenv("$key=$value");
            $_ENV[$key] = $value;
        }
    }
}

loadEnv(__DIR__ . '/.env');

// Configuration
$downloadsDir = __DIR__ . '/downloads';
$downloadsUrl = '/harrysrippers/downloads';
$ytDlpPath = '/home/harry/bin/yt-dlp';
$ffmpegPath = '/home/harry/bin/ffmpeg';
$maxFileAge = 28800; // Delete files older than 8 hours
$logFile = __DIR__ . '/rip_log.json';
$openaiApiKey = getenv('OPENAI_API_KEY');

// Function to add log entry
function addLogEntry($url, $filename, $filesize, $status = 'success') {
    global $logFile;

    $logEntry = [
        'timestamp' => time(),
        'date' => date('Y-m-d H:i:s'),
        'url' => $url,
        'filename' => $filename,
        'filesize' => $filesize,
        'status' => $status
    ];

    $log = [];
    if (file_exists($logFile)) {
        $log = json_decode(file_get_contents($logFile), true);
        if (!is_array($log)) {
            $log = [];
        }
    }

    array_unshift($log, $logEntry);

    // Keep only last 100 entries
    $log = array_slice($log, 0, 100);

    file_put_contents($logFile, json_encode($log, JSON_PRETTY_PRINT));
}

// Function to get log entries
function getLogEntries($limit = 20) {
    global $logFile;

    if (!file_exists($logFile)) {
        return [];
    }

    $log = json_decode(file_get_contents($logFile), true);
    if (!is_array($log)) {
        return [];
    }

    return array_slice($log, 0, $limit);
}

// Handle file deletion
if (isset($_GET['delete']) && !empty($_GET['delete'])) {
    $fileToDelete = basename($_GET['delete']); // Security: only basename
    $filePath = $downloadsDir . '/' . $fileToDelete;
    $metaPath = $filePath . '.meta';
    if (file_exists($filePath) && is_file($filePath)) {
        unlink($filePath);
        if (file_exists($metaPath)) {
            unlink($metaPath);
        }
    }
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

// Handle file renaming
if (isset($_POST['rename']) && !empty($_POST['old_name']) && !empty($_POST['new_name'])) {
    $oldName = basename($_POST['old_name']);
    $newName = basename($_POST['new_name']);

    // Ensure .mp3 extension
    if (!preg_match('/\.mp3$/i', $newName)) {
        $newName .= '.mp3';
    }

    $oldPath = $downloadsDir . '/' . $oldName;
    $newPath = $downloadsDir . '/' . $newName;
    $oldMetaPath = $oldPath . '.meta';
    $newMetaPath = $newPath . '.meta';

    if (file_exists($oldPath) && !file_exists($newPath)) {
        rename($oldPath, $newPath);
        if (file_exists($oldMetaPath)) {
            rename($oldMetaPath, $newMetaPath);
        }
    }
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

// Clean up old files
$files = glob($downloadsDir . '/*.mp3');
if ($files) {
    foreach ($files as $file) {
        if (is_file($file) && time() - filemtime($file) > $maxFileAge) {
            unlink($file);
        }
    }
}

// Get available files for display
$availableFiles = [];
$files = glob($downloadsDir . '/*.mp3');
if ($files) {
    foreach ($files as $file) {
        if (is_file($file)) {
            $basename = basename($file);
            $metaPath = $file . '.meta';
            $originalUrl = '';

            // Read metadata if exists
            $downloadedAt = filemtime($file); // Fallback to file modification time
            $artist = '';
            $title = '';
            $album = '';
            if (file_exists($metaPath)) {
                $metaData = json_decode(file_get_contents($metaPath), true);
                $originalUrl = isset($metaData['url']) ? $metaData['url'] : '';
                if (isset($metaData['timestamp'])) {
                    $downloadedAt = $metaData['timestamp'];
                }
                $artist = isset($metaData['artist']) ? $metaData['artist'] : '';
                $title = isset($metaData['title']) ? $metaData['title'] : '';
                $album = isset($metaData['album']) ? $metaData['album'] : '';
            }

            $availableFiles[] = [
                'name' => $basename,
                'size' => filesize($file),
                'url' => $downloadsUrl . '/' . rawurlencode($basename),
                'original_url' => $originalUrl,
                'downloaded_at' => $downloadedAt,
                'artist' => $artist,
                'title' => $title,
                'album' => $album
            ];
        }
    }
    // Sort by modification time, newest first
    usort($availableFiles, function($a, $b) use ($downloadsDir) {
        return filemtime($downloadsDir . '/' . $b['name']) - filemtime($downloadsDir . '/' . $a['name']);
    });
}

$error = '';
$success = '';
$downloadLink = '';
$filename = '';

// Process form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['url'])) {
    $url = trim($_POST['url']);

    // Basic URL validation
    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        $error = 'Please enter a valid URL';
    } else {
        // Use video title and ID in filename
        $outputTemplate = $downloadsDir . '/%(title)s-%(id)s.%(ext)s';

        // Set PATH to include python3
        putenv('PATH=/home/harry/bin:' . getenv('PATH'));

        // First, get video info to extract title
        $videoTitle = '';
        $infoCommand = sprintf(
            '%s --print title --no-playlist %s 2>&1',
            escapeshellarg($ytDlpPath),
            escapeshellarg($url)
        );
        exec($infoCommand, $infoOutput, $infoReturnCode);
        if ($infoReturnCode === 0 && !empty($infoOutput)) {
            $videoTitle = trim(implode(' ', $infoOutput));
        }

        // Get list of files before download
        $filesBefore = glob($downloadsDir . '/*.mp3');

        // Build yt-dlp command
        $command = sprintf(
            '%s -x --audio-format mp3 --restrict-filenames --no-playlist --output %s %s 2>&1',
            escapeshellarg($ytDlpPath),
            escapeshellarg($outputTemplate),
            escapeshellarg($url)
        );

        // Execute command
        exec($command, $output, $returnCode);

        if ($returnCode === 0) {
            // Find the newly downloaded file
            $filesAfter = glob($downloadsDir . '/*.mp3');
            $newFiles = array_diff($filesAfter, $filesBefore);

            if (!empty($newFiles)) {
                $filepath = reset($newFiles);
                $filename = basename($filepath);
                $downloadLink = $downloadsUrl . '/' . rawurlencode($filename);
                $success = 'Conversion successful!';

                // Parse video title with ChatGPT and update MP3 metadata
                $parsedMetadata = null;
                if (!empty($videoTitle) && !empty($openaiApiKey)) {
                    $parsedMetadata = parseVideoTitle($videoTitle, $openaiApiKey);
                    if ($parsedMetadata) {
                        updateMp3Metadata($filepath, $parsedMetadata, $url);
                    }
                }

                // Save metadata with original URL and parsed info
                $metaPath = $filepath . '.meta';
                $metaData = [
                    'url' => $url,
                    'timestamp' => time(),
                    'video_title' => $videoTitle
                ];
                if ($parsedMetadata) {
                    $metaData['artist'] = $parsedMetadata['artist'] ?? '';
                    $metaData['title'] = $parsedMetadata['title'] ?? '';
                    $metaData['album'] = $parsedMetadata['album'] ?? '';
                }
                file_put_contents($metaPath, json_encode($metaData));

                // Add to log
                addLogEntry($url, $filename, filesize($filepath), 'success');

                // Refresh available files list
                header('Location: ' . $_SERVER['PHP_SELF']);
                exit;
            } else {
                $error = 'File was converted but could not be found. Output: ' . implode("\n", $output);
            }
        } else {
            $error = 'Conversion failed. Error: ' . implode("\n", array_slice($output, -5));
        }
    }
}

function formatFileSize($bytes) {
    if ($bytes >= 1073741824) {
        return number_format($bytes / 1073741824, 2) . ' GB';
    } elseif ($bytes >= 1048576) {
        return number_format($bytes / 1048576, 2) . ' MB';
    } elseif ($bytes >= 1024) {
        return number_format($bytes / 1024, 2) . ' KB';
    } else {
        return $bytes . ' bytes';
    }
}

function formatTimeAgo($timestamp) {
    $diff = time() - $timestamp;

    if ($diff < 60) {
        return 'Just now';
    } elseif ($diff < 3600) {
        $mins = floor($diff / 60);
        return $mins . ' min' . ($mins > 1 ? 's' : '') . ' ago';
    } elseif ($diff < 86400) {
        $hours = floor($diff / 3600);
        return $hours . ' hour' . ($hours > 1 ? 's' : '') . ' ago';
    } elseif ($diff < 604800) {
        $days = floor($diff / 86400);
        return $days . ' day' . ($days > 1 ? 's' : '') . ' ago';
    } else {
        return date('M j, Y g:i a', $timestamp);
    }
}

// Function to parse video title using OpenAI API
function parseVideoTitle($videoTitle, $apiKey) {
    if (empty($apiKey)) {
        return null;
    }

    $prompt = "Parse this video title and extract the artist name, song title, and album name (if mentioned). Return ONLY a JSON object with keys 'artist', 'title', and 'album'. If album is not mentioned, use an empty string. Video title: " . $videoTitle;

    $data = [
        'model' => 'gpt-4o-mini',
        'messages' => [
            ['role' => 'user', 'content' => $prompt]
        ],
        'temperature' => 0.3,
        'max_tokens' => 200
    ];

    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $apiKey
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) {
        return null;
    }

    $result = json_decode($response, true);
    if (isset($result['choices'][0]['message']['content'])) {
        $content = $result['choices'][0]['message']['content'];
        // Extract JSON from the response (handle markdown code blocks)
        if (preg_match('/\{[^}]+\}/', $content, $matches)) {
            return json_decode($matches[0], true);
        }
        return json_decode($content, true);
    }

    return null;
}

// Function to update MP3 metadata using ffmpeg
function updateMp3Metadata($filepath, $metadata, $sourceUrl = '') {
    global $ffmpegPath;

    if (empty($metadata)) {
        return false;
    }

    $tempFile = $filepath . '.temp.mp3';
    $artist = isset($metadata['artist']) ? $metadata['artist'] : '';
    $title = isset($metadata['title']) ? $metadata['title'] : '';
    $album = isset($metadata['album']) ? $metadata['album'] : '';

    // Build ffmpeg command to update metadata
    $command = sprintf(
        '%s -i %s -metadata artist=%s -metadata title=%s -metadata album=%s -metadata comment=%s -codec copy %s 2>&1',
        escapeshellarg($ffmpegPath),
        escapeshellarg($filepath),
        escapeshellarg($artist),
        escapeshellarg($title),
        escapeshellarg($album),
        escapeshellarg($sourceUrl),
        escapeshellarg($tempFile)
    );

    exec($command, $output, $returnCode);

    if ($returnCode === 0 && file_exists($tempFile)) {
        // Replace original file with updated file
        unlink($filepath);
        rename($tempFile, $filepath);
        return true;
    }

    // Clean up temp file if it exists
    if (file_exists($tempFile)) {
        unlink($tempFile);
    }

    return false;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Harry's Rippers - MP3 Converter</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 15px;
            font-size: 14px;
        }

        .main-container {
            max-width: 1600px;
            margin: 0 auto;
        }

        .header {
            text-align: center;
            color: white;
            margin-bottom: 20px;
        }

        .header h1 {
            font-size: 32px;
            margin-bottom: 5px;
            text-shadow: 2px 2px 4px rgba(0,0,0,0.3);
        }

        .header p {
            font-size: 14px;
            opacity: 0.9;
        }

        .columns {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
            align-items: start;
        }

        @media (max-width: 1200px) {
            .columns {
                grid-template-columns: 1fr;
            }
        }

        .panel {
            background: white;
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.3);
            padding: 20px;
        }

        .panel h2 {
            color: #333;
            margin-bottom: 15px;
            font-size: 18px;
            border-bottom: 2px solid #667eea;
            padding-bottom: 8px;
        }

        .form-group {
            margin-bottom: 15px;
        }

        label {
            display: block;
            margin-bottom: 6px;
            color: #333;
            font-weight: 500;
            font-size: 13px;
        }

        input[type="text"] {
            width: 100%;
            padding: 10px 12px;
            border: 2px solid #e1e8ed;
            border-radius: 6px;
            font-size: 14px;
            transition: border-color 0.3s;
        }

        input[type="text"]:focus {
            outline: none;
            border-color: #667eea;
        }

        button {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 10px 25px;
            border: none;
            border-radius: 6px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            width: 100%;
            transition: transform 0.2s, box-shadow 0.2s;
        }

        button:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 15px rgba(102, 126, 234, 0.4);
        }

        button:active {
            transform: translateY(0);
        }

        .alert {
            padding: 12px 15px;
            border-radius: 6px;
            margin-bottom: 15px;
            font-size: 13px;
        }

        .alert-error {
            background: #fee;
            border: 1px solid #fcc;
            color: #c00;
        }

        .alert-success {
            background: #efe;
            border: 1px solid #cfc;
            color: #070;
        }

        .info {
            background: #e7f3ff;
            border-left: 3px solid #2196F3;
            padding: 10px 12px;
            margin-top: 15px;
            border-radius: 4px;
            font-size: 12px;
            color: #333;
        }

        .file-list {
            max-height: 600px;
            overflow-y: auto;
        }

        .file-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px;
            border-bottom: 1px solid #e1e8ed;
            transition: background 0.2s;
        }

        .file-item:hover {
            background: #f8f9fa;
        }

        .file-item:last-child {
            border-bottom: none;
        }

        .file-info {
            flex: 1;
            margin-right: 12px;
            min-width: 0;
        }

        .file-name {
            color: #333;
            font-weight: 500;
            word-break: break-word;
            margin-bottom: 4px;
            font-size: 13px;
        }

        .file-size {
            color: #666;
            font-size: 11px;
        }

        .file-actions {
            display: flex;
            gap: 8px;
            align-items: center;
        }

        .download-btn {
            background: #667eea;
            color: white;
            padding: 6px 15px;
            border-radius: 5px;
            text-decoration: none;
            font-size: 12px;
            font-weight: 600;
            white-space: nowrap;
            transition: background 0.3s;
        }

        .download-btn:hover {
            background: #764ba2;
        }

        .delete-btn {
            background: #dc3545;
            color: white;
            padding: 6px 10px;
            border-radius: 5px;
            text-decoration: none;
            font-size: 16px;
            transition: background 0.3s;
            cursor: pointer;
            border: none;
        }

        .delete-btn:hover {
            background: #c82333;
        }

        .no-files {
            text-align: center;
            color: #999;
            padding: 30px 15px;
            font-style: italic;
            font-size: 13px;
        }

        .file-count {
            color: #667eea;
            font-size: 13px;
            font-weight: normal;
        }

        .play-btn {
            background: #28a745;
            color: white;
            padding: 6px 10px;
            border-radius: 5px;
            text-decoration: none;
            font-size: 16px;
            transition: background 0.3s;
            cursor: pointer;
            border: none;
        }

        .play-btn:hover {
            background: #218838;
        }

        .youtube-btn {
            background: #ff0000;
            color: white;
            padding: 6px 10px;
            border-radius: 5px;
            text-decoration: none;
            font-size: 16px;
            transition: background 0.3s;
            cursor: pointer;
            border: none;
        }

        .youtube-btn:hover {
            background: #cc0000;
        }

        .edit-btn {
            background: #ffc107;
            color: white;
            padding: 6px 10px;
            border-radius: 5px;
            text-decoration: none;
            font-size: 16px;
            transition: background 0.3s;
            cursor: pointer;
            border: none;
        }

        .edit-btn:hover {
            background: #e0a800;
        }

        .audio-player {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 15px;
            display: none;
        }

        .audio-player.active {
            display: block;
        }

        .audio-player audio {
            width: 100%;
            margin-top: 8px;
        }

        .now-playing {
            font-size: 12px;
            color: #666;
            margin-bottom: 5px;
        }

        .now-playing strong {
            color: #333;
        }

        .log-section {
            margin-top: 20px;
        }

        .log-section h3 {
            font-size: 16px;
            color: #333;
            margin-bottom: 10px;
            border-bottom: 2px solid #667eea;
            padding-bottom: 5px;
        }

        .log-list {
            max-height: 300px;
            overflow-y: auto;
            background: #f8f9fa;
            border-radius: 8px;
            padding: 10px;
        }

        .log-entry {
            padding: 8px;
            border-bottom: 1px solid #e1e8ed;
            font-size: 11px;
        }

        .log-entry:last-child {
            border-bottom: none;
        }

        .log-date {
            color: #999;
            font-size: 10px;
            margin-bottom: 3px;
        }

        .log-filename {
            color: #333;
            font-weight: 500;
            margin-bottom: 2px;
            word-break: break-word;
        }

        .log-url {
            color: #667eea;
            font-size: 10px;
            text-decoration: none;
            display: block;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .log-url:hover {
            text-decoration: underline;
        }

        .log-size {
            color: #666;
            font-size: 10px;
        }
    </style>
</head>
<body>
    <div class="main-container">
        <div class="header">
            <h1>🎵 Harry's Rippers</h1>
            <p>Convert videos to MP3 audio files</p>
        </div>

        <div class="columns">
            <!-- Left Column: Conversion Tool -->
            <div class="panel">
                <h2>Convert Video</h2>

                <?php if ($error): ?>
                    <div class="alert alert-error">
                        <strong>Error:</strong> <?php echo htmlspecialchars($error); ?>
                    </div>
                <?php endif; ?>

                <?php if ($success): ?>
                    <div class="alert alert-success">
                        <strong>Success:</strong> <?php echo htmlspecialchars($success); ?>
                    </div>
                <?php endif; ?>

                <form method="POST" action="">
                    <div class="form-group">
                        <label for="url">Video URL:</label>
                        <input
                            type="text"
                            id="url"
                            name="url"
                            placeholder="https://www.youtube.com/watch?v=..."
                            required
                            value="<?php echo isset($_POST['url']) ? htmlspecialchars($_POST['url']) : ''; ?>"
                        >
                    </div>

                    <button type="submit">Convert to MP3</button>
                </form>

                <div class="info">
                    <strong>Note:</strong> Files are automatically deleted after 8 hours. Supports YouTube and many other sites.
                </div>

                <!-- Rip Log -->
                <div class="log-section">
                    <h3>Recent Rips</h3>
                    <div class="log-list">
                        <?php
                        $logEntries = getLogEntries(20);
                        if (empty($logEntries)):
                        ?>
                            <div class="log-entry" style="text-align: center; color: #999;">
                                No rips yet
                            </div>
                        <?php else: ?>
                            <?php foreach ($logEntries as $entry): ?>
                                <div class="log-entry">
                                    <div class="log-date"><?php echo htmlspecialchars($entry['date']); ?></div>
                                    <div class="log-filename"><?php echo htmlspecialchars($entry['filename']); ?></div>
                                    <a href="<?php echo htmlspecialchars($entry['url']); ?>" class="log-url" target="_blank" title="<?php echo htmlspecialchars($entry['url']); ?>">
                                        <?php echo htmlspecialchars($entry['url']); ?>
                                    </a>
                                    <div class="log-size"><?php echo formatFileSize($entry['filesize']); ?></div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Right Column: File List -->
            <div class="panel">
                <h2>
                    Available Files
                    <span class="file-count">(<?php echo count($availableFiles); ?>)</span>
                </h2>

                <!-- Audio Player -->
                <div class="audio-player" id="audioPlayer">
                    <div class="now-playing">
                        <strong>Now Playing:</strong> <span id="nowPlayingName">-</span>
                    </div>
                    <audio id="audioElement" controls></audio>
                </div>

                <div class="file-list">
                    <?php if (empty($availableFiles)): ?>
                        <div class="no-files">
                            No files available yet. Convert a video to get started!
                        </div>
                    <?php else: ?>
                        <?php foreach ($availableFiles as $file): ?>
                            <div class="file-item">
                                <div class="file-info">
                                    <?php if (!empty($file['artist']) && !empty($file['title'])): ?>
                                        <div class="file-name">
                                            <strong><?php echo htmlspecialchars($file['artist']); ?></strong> - <?php echo htmlspecialchars($file['title']); ?>
                                            <?php if (!empty($file['album'])): ?>
                                                <span style="color: #999; font-size: 11px;">(<?php echo htmlspecialchars($file['album']); ?>)</span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="file-size" style="font-size: 10px; color: #999;"><?php echo htmlspecialchars($file['name']); ?></div>
                                    <?php else: ?>
                                        <div class="file-name"><?php echo htmlspecialchars($file['name']); ?></div>
                                    <?php endif; ?>
                                    <div class="file-size"><?php echo formatFileSize($file['size']); ?> • Downloaded <?php echo formatTimeAgo($file['downloaded_at']); ?></div>
                                </div>
                                <div class="file-actions">
                                    <button class="play-btn"
                                            onclick="playAudio('<?php echo htmlspecialchars($file['url'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars($file['name'], ENT_QUOTES); ?>')"
                                            title="Play audio">
                                        ▶️
                                    </button>
                                    <?php if (!empty($file['original_url'])): ?>
                                        <a href="<?php echo htmlspecialchars($file['original_url']); ?>"
                                           class="youtube-btn"
                                           target="_blank"
                                           title="View original video">
                                            ▶
                                        </a>
                                    <?php endif; ?>
                                    <button class="edit-btn"
                                            onclick="editFilename('<?php echo htmlspecialchars($file['name'], ENT_QUOTES); ?>')"
                                            title="Rename file">
                                        ✏️
                                    </button>
                                    <a href="<?php echo htmlspecialchars($file['url']); ?>" class="download-btn" download>
                                        📥 Download
                                    </a>
                                    <a href="?delete=<?php echo urlencode($file['name']); ?>"
                                       class="delete-btn"
                                       onclick="return confirm('Delete this file?');"
                                       title="Delete file">
                                        🗑️
                                    </a>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Allow Enter key to submit the form
        document.addEventListener('DOMContentLoaded', function() {
            const urlInput = document.getElementById('url');
            if (urlInput) {
                urlInput.addEventListener('keypress', function(event) {
                    if (event.key === 'Enter') {
                        event.preventDefault();
                        this.form.submit();
                    }
                });
            }
        });

        function playAudio(url, filename) {
            const player = document.getElementById('audioPlayer');
            const audio = document.getElementById('audioElement');
            const nowPlaying = document.getElementById('nowPlayingName');

            // Show player
            player.classList.add('active');

            // Update now playing text
            nowPlaying.textContent = filename;

            // Load and play audio
            audio.src = url;
            audio.load();
            audio.play();

            // Scroll to player
            player.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }

        function editFilename(oldName) {
            // Remove .mp3 extension for editing
            const nameWithoutExt = oldName.replace(/\.mp3$/i, '');
            const newName = prompt('Enter new filename (without .mp3):', nameWithoutExt);

            if (newName && newName.trim() !== '' && newName !== nameWithoutExt) {
                // Create a form and submit it
                const form = document.createElement('form');
                form.method = 'POST';
                form.action = '';

                const renameInput = document.createElement('input');
                renameInput.type = 'hidden';
                renameInput.name = 'rename';
                renameInput.value = '1';
                form.appendChild(renameInput);

                const oldNameInput = document.createElement('input');
                oldNameInput.type = 'hidden';
                oldNameInput.name = 'old_name';
                oldNameInput.value = oldName;
                form.appendChild(oldNameInput);

                const newNameInput = document.createElement('input');
                newNameInput.type = 'hidden';
                newNameInput.name = 'new_name';
                newNameInput.value = newName.trim();
                form.appendChild(newNameInput);

                document.body.appendChild(form);
                form.submit();
            }
        }
    </script>
</body>
</html>
