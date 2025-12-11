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
$maxFileAge = 864000; // Delete files older than 10 days
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
    $waveformPath = $downloadsDir . '/' . pathinfo($fileToDelete, PATHINFO_FILENAME) . '_waveform.png';
    $backupPath = $downloadsDir . '/backups/' . pathinfo($fileToDelete, PATHINFO_FILENAME) . '_backup.mp3';
    if (file_exists($filePath) && is_file($filePath)) {
        unlink($filePath);
        if (file_exists($metaPath)) {
            unlink($metaPath);
        }
        if (file_exists($waveformPath)) {
            unlink($waveformPath);
        }
        if (file_exists($backupPath)) {
            unlink($backupPath);
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
    $oldWaveformPath = $downloadsDir . '/' . pathinfo($oldName, PATHINFO_FILENAME) . '_waveform.png';
    $newWaveformPath = $downloadsDir . '/' . pathinfo($newName, PATHINFO_FILENAME) . '_waveform.png';

    if (file_exists($oldPath) && !file_exists($newPath)) {
        rename($oldPath, $newPath);
        if (file_exists($oldMetaPath)) {
            rename($oldMetaPath, $newMetaPath);
        }
        if (file_exists($oldWaveformPath)) {
            rename($oldWaveformPath, $newWaveformPath);
        }
    }
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

// Handle metadata update
if (isset($_POST['update_metadata']) && !empty($_POST['filename'])) {
    $filename = basename($_POST['filename']);
    $filepath = $downloadsDir . '/' . $filename;
    $metaPath = $filepath . '.meta';

    if (file_exists($filepath) && file_exists($metaPath)) {
        $metaData = json_decode(file_get_contents($metaPath), true);
        if ($metaData) {
            $metaData['artist'] = $_POST['artist'] ?? '';
            $metaData['title'] = $_POST['title'] ?? '';
            $metaData['album'] = $_POST['album'] ?? '';
            file_put_contents($metaPath, json_encode($metaData));

            // Update MP3 file metadata
            $metadata = [
                'artist' => $metaData['artist'],
                'title' => $metaData['title'],
                'album' => $metaData['album']
            ];
            $sourceUrl = $metaData['url'] ?? '';
            updateMp3Metadata($filepath, $metadata, $sourceUrl);
        }
    }
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

// Handle restore original title
if (isset($_POST['restore_title']) && !empty($_POST['filename']) && !empty($_POST['original_title'])) {
    $filename = basename($_POST['filename']);
    $originalTitle = $_POST['original_title'];

    // Sanitize the title for filename
    $newFilename = preg_replace('/[^a-zA-Z0-9_\-\. ]/', '_', $originalTitle);
    $newFilename = preg_replace('/\s+/', '_', $newFilename);

    // Ensure .mp3 extension
    if (!preg_match('/\.mp3$/i', $newFilename)) {
        $newFilename .= '.mp3';
    }

    $oldPath = $downloadsDir . '/' . $filename;
    $newPath = $downloadsDir . '/' . $newFilename;
    $oldMetaPath = $oldPath . '.meta';
    $newMetaPath = $newPath . '.meta';
    $oldWaveformPath = $downloadsDir . '/' . pathinfo($filename, PATHINFO_FILENAME) . '_waveform.png';
    $newWaveformPath = $downloadsDir . '/' . pathinfo($newFilename, PATHINFO_FILENAME) . '_waveform.png';

    if (file_exists($oldPath) && !file_exists($newPath)) {
        rename($oldPath, $newPath);
        if (file_exists($oldMetaPath)) {
            rename($oldMetaPath, $newMetaPath);
        }
        if (file_exists($oldWaveformPath)) {
            rename($oldWaveformPath, $newWaveformPath);
        }
    }
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

// Handle audio trimming
if (isset($_POST['trim_audio']) && !empty($_POST['filename'])) {
    $filename = basename($_POST['filename']);
    $startTime = trim($_POST['start_time']);
    $endTime = trim($_POST['end_time']);

    $filePath = $downloadsDir . '/' . $filename;

    if (file_exists($filePath)) {
        // Parse time strings (supports MM:SS, M:SS, or seconds)
        function parseTimeToSeconds($timeStr) {
            $timeStr = trim($timeStr);
            if (empty($timeStr)) {
                return null;
            }

            // If it contains a colon, parse as MM:SS
            if (strpos($timeStr, ':') !== false) {
                $parts = explode(':', $timeStr);
                if (count($parts) == 2) {
                    return intval($parts[0]) * 60 + intval($parts[1]);
                }
            }

            // Otherwise treat as seconds
            return floatval($timeStr);
        }

        $startSeconds = parseTimeToSeconds($startTime);
        $endSeconds = parseTimeToSeconds($endTime);

        // Read existing metadata from .meta file
        $metaPath = $filePath . '.meta';
        $metaData = file_exists($metaPath) ? json_decode(file_get_contents($metaPath), true) : [];

        // Create temporary output file
        $tempFile = $downloadsDir . '/temp_' . time() . '_' . $filename;

        // Build ffmpeg command
        $ffmpegCommand = sprintf(
            '%s -i %s',
            escapeshellarg($ffmpegPath),
            escapeshellarg($filePath)
        );

        if ($startSeconds !== null) {
            $ffmpegCommand .= sprintf(' -ss %s', escapeshellarg($startSeconds));
        }

        if ($endSeconds !== null) {
            $ffmpegCommand .= sprintf(' -to %s', escapeshellarg($endSeconds));
        }

        $ffmpegCommand .= sprintf(' -map_metadata 0 -c copy %s 2>&1', escapeshellarg($tempFile));

        // Execute trim
        exec($ffmpegCommand, $trimOutput, $trimReturnCode);

        if ($trimReturnCode === 0 && file_exists($tempFile)) {
            // Replace original with trimmed version
            unlink($filePath);
            rename($tempFile, $filePath);

            // Re-apply ID3 tags from meta file
            if (!empty($metaData['artist']) || !empty($metaData['title'])) {
                $metadata = [
                    'artist' => $metaData['artist'] ?? '',
                    'title' => $metaData['title'] ?? '',
                    'album' => $metaData['album'] ?? ''
                ];
                updateMp3Metadata($filePath, $metadata, $metaData['url'] ?? '');
            }

            // Delete old waveform so it will be regenerated
            $waveformPath = $downloadsDir . '/' . pathinfo($filename, PATHINFO_FILENAME) . '_waveform.png';
            if (file_exists($waveformPath)) {
                unlink($waveformPath);
            }
        } else {
            // Clean up temp file if it exists
            if (file_exists($tempFile)) {
                unlink($tempFile);
            }
        }
    }

    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

// Handle audio normalization
if (isset($_POST['normalize_audio']) && !empty($_POST['filename'])) {
    $filename = basename($_POST['filename']);
    $filePath = $downloadsDir . '/' . $filename;

    if (file_exists($filePath)) {
        // Create backup of original file before normalizing
        $backupsDir = $downloadsDir . '/backups';
        if (!is_dir($backupsDir)) {
            mkdir($backupsDir, 0755, true);
        }
        $backupPath = $backupsDir . '/' . pathinfo($filename, PATHINFO_FILENAME) . '_backup.mp3';

        // Only create backup if one doesn't already exist
        if (!file_exists($backupPath)) {
            copy($filePath, $backupPath);
        }

        // Create temporary output file
        $tempFile = $downloadsDir . '/temp_norm_' . time() . '_' . $filename;

        // First, detect the current peak level
        $peakLevel = getAudioPeakLevel($filePath);

        // Calculate gain needed to bring peak to -1dB
        // If peak is -6dB, we need +5dB gain to reach -1dB
        $targetPeak = -1.0;
        $gainNeeded = $targetPeak - $peakLevel;

        // Cap the gain at +20dB to avoid extreme amplification of very quiet files
        $gainNeeded = min($gainNeeded, 20);

        // Read existing metadata from .meta file
        $metaPath = $filePath . '.meta';
        $metaData = file_exists($metaPath) ? json_decode(file_get_contents($metaPath), true) : [];

        // Use ffmpeg with:
        // 1. silenceremove to trim silence from start and end (threshold -50dB, minimum 0.5s)
        // 2. volume filter for peak normalization (bring loudest part to -1dB)
        // 3. limiter to prevent clipping
        // 4. map_metadata to preserve existing tags
        $ffmpegCommand = sprintf(
            '%s -i %s -af "silenceremove=start_periods=1:start_silence=0.5:start_threshold=-50dB:detection=peak,areverse,silenceremove=start_periods=1:start_silence=0.5:start_threshold=-50dB:detection=peak,areverse,volume=%sdB,alimiter=limit=0.95:attack=5:release=50" -map_metadata 0 -ar 44100 -ab 192k %s 2>&1',
            escapeshellarg($ffmpegPath),
            escapeshellarg($filePath),
            $gainNeeded,
            escapeshellarg($tempFile)
        );

        exec($ffmpegCommand, $normOutput, $normReturnCode);

        if ($normReturnCode === 0 && file_exists($tempFile)) {
            // Replace original with normalized version
            unlink($filePath);
            rename($tempFile, $filePath);

            // Re-apply ID3 tags from meta file
            if (!empty($metaData['artist']) || !empty($metaData['title'])) {
                $metadata = [
                    'artist' => $metaData['artist'] ?? '',
                    'title' => $metaData['title'] ?? '',
                    'album' => $metaData['album'] ?? ''
                ];
                updateMp3Metadata($filePath, $metadata, $metaData['url'] ?? '');
            }

            // Delete old waveform so it will be regenerated
            $waveformPath = $downloadsDir . '/' . pathinfo($filename, PATHINFO_FILENAME) . '_waveform.png';
            if (file_exists($waveformPath)) {
                unlink($waveformPath);
            }
        } else {
            // Clean up temp file if it exists
            if (file_exists($tempFile)) {
                unlink($tempFile);
            }
            // If normalization failed, remove the backup we just created
            if (file_exists($backupPath)) {
                unlink($backupPath);
            }
        }
    }

    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

// Handle restore from backup
if (isset($_POST['restore_backup']) && !empty($_POST['filename'])) {
    $filename = basename($_POST['filename']);
    $filePath = $downloadsDir . '/' . $filename;
    $backupPath = $downloadsDir . '/backups/' . pathinfo($filename, PATHINFO_FILENAME) . '_backup.mp3';

    if (file_exists($backupPath)) {
        // Read existing metadata from .meta file
        $metaPath = $filePath . '.meta';
        $metaData = file_exists($metaPath) ? json_decode(file_get_contents($metaPath), true) : [];

        // Replace normalized file with backup
        if (file_exists($filePath)) {
            unlink($filePath);
        }
        rename($backupPath, $filePath);

        // Re-apply ID3 tags from meta file
        if (!empty($metaData['artist']) || !empty($metaData['title'])) {
            $metadata = [
                'artist' => $metaData['artist'] ?? '',
                'title' => $metaData['title'] ?? '',
                'album' => $metaData['album'] ?? ''
            ];
            updateMp3Metadata($filePath, $metadata, $metaData['url'] ?? '');
        }

        // Delete waveform so it will be regenerated
        $waveformPath = $downloadsDir . '/' . pathinfo($filename, PATHINFO_FILENAME) . '_waveform.png';
        if (file_exists($waveformPath)) {
            unlink($waveformPath);
        }
    }

    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

// Handle reprocess all files
if (isset($_POST['reprocess_all'])) {
    $files = glob($downloadsDir . '/*.mp3');
    $logFile = __DIR__ . '/rip_log.json';
    $log = file_exists($logFile) ? json_decode(file_get_contents($logFile), true) : [];

    foreach ($files as $filepath) {
        $basename = basename($filepath);

        // Skip backup files
        if (strpos($basename, '_backup') !== false) {
            continue;
        }

        // Get video title from filename (remove YouTube ID suffix and extension)
        $videoTitle = preg_replace('/-[a-zA-Z0-9_-]{11}\.mp3$/', '', $basename);
        $videoTitle = str_replace('_', ' ', $videoTitle);

        // Read existing meta file if it exists
        $metaPath = $filepath . '.meta';
        $existingMeta = file_exists($metaPath) ? json_decode(file_get_contents($metaPath), true) : [];
        $originalUrl = $existingMeta['url'] ?? '';

        // Parse with AI
        if (!empty($openaiApiKey)) {
            $parsedMetadata = parseVideoTitle($videoTitle, $openaiApiKey, '', '');

            if ($parsedMetadata && !empty($parsedMetadata['artist']) && !empty($parsedMetadata['title'])) {
                // Update MP3 metadata
                updateMp3Metadata($filepath, $parsedMetadata, $originalUrl);

                // Create new filename
                $newFilename = $parsedMetadata['artist'] . ' - ' . $parsedMetadata['title'] . '.mp3';
                $newFilename = preg_replace('/[<>:"\/\\|?*]/', '', $newFilename); // Remove invalid chars
                $newPath = $downloadsDir . '/' . $newFilename;

                // Only rename if different and target doesn't exist
                if ($filepath !== $newPath && !file_exists($newPath)) {
                    // Rename the MP3 file
                    rename($filepath, $newPath);

                    // Move meta file too
                    if (file_exists($metaPath)) {
                        $newMetaPath = $newPath . '.meta';
                        // Update meta content
                        $existingMeta['artist'] = $parsedMetadata['artist'];
                        $existingMeta['title'] = $parsedMetadata['title'];
                        $existingMeta['album'] = $parsedMetadata['album'] ?? '';
                        $existingMeta['summary'] = $parsedMetadata['summary'] ?? '';
                        $existingMeta['lyrics_url'] = $parsedMetadata['lyrics_url'] ?? '';
                        file_put_contents($newMetaPath, json_encode($existingMeta));
                        unlink($metaPath);
                    }

                    // Delete old waveform (will be regenerated)
                    $oldWaveform = $downloadsDir . '/' . pathinfo($basename, PATHINFO_FILENAME) . '_waveform.png';
                    if (file_exists($oldWaveform)) {
                        unlink($oldWaveform);
                    }

                    // Update log entry
                    foreach ($log as &$entry) {
                        if ($entry['filename'] === $basename) {
                            $entry['filename'] = $newFilename;
                            break;
                        }
                    }
                }
            }
        }
    }

    // Save updated log
    file_put_contents($logFile, json_encode($log, JSON_PRETTY_PRINT));

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
            $videoTitle = '';
            $summary = '';
            $lyricsUrl = '';
            $imageUrl = '';
            if (file_exists($metaPath)) {
                $metaData = json_decode(file_get_contents($metaPath), true);
                $originalUrl = isset($metaData['url']) ? $metaData['url'] : '';
                if (isset($metaData['timestamp'])) {
                    $downloadedAt = $metaData['timestamp'];
                }
                $artist = isset($metaData['artist']) ? $metaData['artist'] : '';
                $title = isset($metaData['title']) ? $metaData['title'] : '';
                $album = isset($metaData['album']) ? $metaData['album'] : '';
                $videoTitle = isset($metaData['video_title']) ? $metaData['video_title'] : '';
                $summary = isset($metaData['summary']) ? $metaData['summary'] : '';
                $lyricsUrl = isset($metaData['lyrics_url']) ? $metaData['lyrics_url'] : '';
                $imageUrl = isset($metaData['image_url']) ? $metaData['image_url'] : '';
            }

            // Get duration, waveform, and peak level
            $duration = getMp3Duration($file);
            $durationSeconds = getMp3Duration($file, false);
            $waveformUrl = generateWaveform($file);
            $peakLevel = getAudioPeakLevel($file);

            // Check if backup exists (file has been normalized)
            $backupPath = $downloadsDir . '/backups/' . pathinfo($basename, PATHINFO_FILENAME) . '_backup.mp3';
            $hasBackup = file_exists($backupPath);

            // Check if this track exists in Matt's Archive
            $archiveMatch = searchArchive($artist, $title);

            $availableFiles[] = [
                'name' => $basename,
                'size' => filesize($file),
                'url' => $downloadsUrl . '/' . rawurlencode($basename),
                'original_url' => $originalUrl,
                'downloaded_at' => $downloadedAt,
                'artist' => $artist,
                'title' => $title,
                'album' => $album,
                'video_title' => $videoTitle,
                'summary' => $summary,
                'lyrics_url' => $lyricsUrl,
                'image_url' => $imageUrl,
                'duration' => $duration,
                'duration_seconds' => $durationSeconds,
                'waveform_url' => $waveformUrl,
                'peak_level' => $peakLevel,
                'needs_normalize' => needsNormalization($peakLevel),
                'has_backup' => $hasBackup,
                'archive_match' => $archiveMatch
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

        // First, get video info to extract title and description
        $videoTitle = '';
        $videoDescription = '';

        $infoCommand = sprintf(
            '%s --print title --no-playlist %s 2>/dev/null',
            escapeshellarg($ytDlpPath),
            escapeshellarg($url)
        );
        exec($infoCommand, $infoOutput, $infoReturnCode);
        if ($infoReturnCode === 0 && !empty($infoOutput)) {
            $videoTitle = trim(implode(' ', $infoOutput));
        }

        // Get video description for better artist identification
        $descCommand = sprintf(
            '%s --print description --no-playlist %s 2>/dev/null',
            escapeshellarg($ytDlpPath),
            escapeshellarg($url)
        );
        exec($descCommand, $descOutput, $descReturnCode);
        if ($descReturnCode === 0 && !empty($descOutput)) {
            $videoDescription = trim(implode("\n", $descOutput));
        }

        // Get channel name as fallback artist
        $channelName = '';
        $channelCommand = sprintf(
            '%s --print channel --no-playlist %s 2>/dev/null',
            escapeshellarg($ytDlpPath),
            escapeshellarg($url)
        );
        exec($channelCommand, $channelOutput, $channelReturnCode);
        if ($channelReturnCode === 0 && !empty($channelOutput)) {
            $channelName = trim(implode(' ', $channelOutput));
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
                    $parsedMetadata = parseVideoTitle($videoTitle, $openaiApiKey, $videoDescription, $channelName);
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
                    $metaData['summary'] = $parsedMetadata['summary'] ?? '';
                    $metaData['lyrics_url'] = $parsedMetadata['lyrics_url'] ?? '';
                    $metaData['image_url'] = $parsedMetadata['image_url'] ?? '';
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

function generateWaveform($filepath) {
    global $ffmpegPath, $downloadsDir, $downloadsUrl;

    $basename = basename($filepath);
    $waveformFile = $downloadsDir . '/' . pathinfo($basename, PATHINFO_FILENAME) . '_waveform.png';
    $waveformUrl = $downloadsUrl . '/' . rawurlencode(pathinfo($basename, PATHINFO_FILENAME) . '_waveform.png');

    // Check if waveform already exists
    if (file_exists($waveformFile)) {
        // Add cache-busting parameter based on file modification time
        return $waveformUrl . '?t=' . filemtime($waveformFile);
    }

    // Generate waveform using ffmpeg
    $command = sprintf(
        '%s -i %s -filter_complex "showwavespic=s=800x60:colors=#667eea" -frames:v 1 %s 2>/dev/null',
        escapeshellarg($ffmpegPath),
        escapeshellarg($filepath),
        escapeshellarg($waveformFile)
    );

    exec($command, $output, $returnCode);

    if ($returnCode === 0 && file_exists($waveformFile)) {
        // Add cache-busting parameter based on file modification time
        return $waveformUrl . '?t=' . filemtime($waveformFile);
    }

    return '';
}

function getAudioPeakLevel($filepath) {
    global $ffmpegPath;

    // Use ffmpeg volumedetect to get the max volume
    $command = sprintf(
        '%s -i %s -af "volumedetect" -f null /dev/null 2>&1',
        escapeshellarg($ffmpegPath),
        escapeshellarg($filepath)
    );

    $output = shell_exec($command);

    // Parse max_volume from output (e.g., "max_volume: -10.5 dB")
    if (preg_match('/max_volume:\s*([-\d.]+)\s*dB/', $output, $matches)) {
        return floatval($matches[1]);
    }

    return 0; // Assume normalized if we can't detect
}

function needsNormalization($peakLevel) {
    // If peak is below -3dB, the audio could benefit from normalization
    return $peakLevel < -3.0;
}

function getMp3Duration($filepath, $formatted = true) {
    global $ffmpegPath;
    $ffprobePath = dirname($ffmpegPath) . '/ffprobe';

    $command = sprintf(
        '%s -v error -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 %s 2>/dev/null',
        escapeshellarg($ffprobePath),
        escapeshellarg($filepath)
    );

    $output = shell_exec($command);
    if ($output !== null) {
        $seconds = floatval(trim($output));
        if ($seconds > 0) {
            if (!$formatted) {
                return $seconds;
            }
            $mins = floor($seconds / 60);
            $secs = floor($seconds % 60);
            $hundredths = floor(($seconds - floor($seconds)) * 100);
            return sprintf('%d:%02d:%02d', $mins, $secs, $hundredths);
        }
    }
    return $formatted ? '' : 0;
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

// Function to extract YouTube thumbnail URL from video URL
function getYouTubeThumbnail($url) {
    if (empty($url)) {
        return '';
    }

    // Extract video ID from various YouTube URL formats
    $videoId = '';

    // Pattern 1: youtube.com/watch?v=VIDEO_ID
    if (preg_match('/[?&]v=([^&]+)/', $url, $matches)) {
        $videoId = $matches[1];
    }
    // Pattern 2: youtu.be/VIDEO_ID
    elseif (preg_match('/youtu\.be\/([^?]+)/', $url, $matches)) {
        $videoId = $matches[1];
    }
    // Pattern 3: youtube.com/embed/VIDEO_ID
    elseif (preg_match('/youtube\.com\/embed\/([^?]+)/', $url, $matches)) {
        $videoId = $matches[1];
    }

    if ($videoId) {
        // Use high quality default thumbnail
        return "https://img.youtube.com/vi/{$videoId}/hqdefault.jpg";
    }

    return '';
}

// Function to parse video title using OpenAI API
function parseVideoTitle($videoTitle, $apiKey, $description = '', $channelName = '') {
    if (empty($apiKey)) {
        return null;
    }

    $prompt = "Parse this video information and extract information about the song. Pay special attention to who is ACTUALLY PERFORMING the song (especially for covers). Return ONLY a JSON object with these keys:
- 'artist': The name of the artist/band ACTUALLY PERFORMING (not the original artist if it's a cover)
  * If no artist is mentioned in the title or description, use the YouTube channel name as the artist
  * Clean up the channel name to just be the person's name - remove suffixes like '-songwriter', ' Official', ' Music', ' VEVO', ' - Topic', etc.
  * Example: 'Alan Wagstaff-songwriter' should become 'Alan Wagstaff'
  * Example: 'Taylor Swift Official' should become 'Taylor Swift'
- 'title': Song title
- 'album': Album name (empty string if not mentioned)
- 'summary': A brief 1-2 sentence description of the song (genre, mood, significance, etc.)
- 'lyrics_url': A direct URL to lyrics. Be thorough - construct the URL using the standard format for popular lyrics sites:
  * Genius: https://genius.com/[Artist]-[song-title]-lyrics (use hyphens, lowercase, remove special chars)
  * AZLyrics: https://www.azlyrics.com/lyrics/[artist]/[songtitle].html (remove spaces/special chars, lowercase)
  * Try to provide a URL for any recognizable song, even if you're constructing it from the artist/title format
  * Only leave empty if it's clearly not a real song (instrumentals, sound effects, etc.)

Video title: " . $videoTitle;

    if (!empty($channelName)) {
        $prompt .= "\n\nYouTube channel name: " . $channelName;
    }

    if (!empty($description)) {
        $prompt .= "\n\nVideo description: " . substr($description, 0, 500); // Limit description length
    }

    $data = [
        'model' => 'gpt-4o-mini',
        'messages' => [
            ['role' => 'user', 'content' => $prompt]
        ],
        'temperature' => 0.3,
        'max_tokens' => 400
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
        if (preg_match('/\{[\s\S]*?\}/', $content, $matches)) {
            $parsed = json_decode($matches[0], true);
            if ($parsed) {
                // Ensure all expected keys exist
                return [
                    'artist' => $parsed['artist'] ?? '',
                    'title' => $parsed['title'] ?? '',
                    'album' => $parsed['album'] ?? '',
                    'summary' => $parsed['summary'] ?? '',
                    'lyrics_url' => $parsed['lyrics_url'] ?? '',
                    'image_url' => $parsed['image_url'] ?? ''
                ];
            }
        }
        $parsed = json_decode($content, true);
        if ($parsed) {
            return [
                'artist' => $parsed['artist'] ?? '',
                'title' => $parsed['title'] ?? '',
                'album' => $parsed['album'] ?? '',
                'summary' => $parsed['summary'] ?? '',
                'lyrics_url' => $parsed['lyrics_url'] ?? '',
                'image_url' => $parsed['image_url'] ?? ''
            ];
        }
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

// Function to search Matt's Archive for a matching track
function searchArchive($artist, $title) {
    $indexFile = __DIR__ . '/archive_index.json';

    if (!file_exists($indexFile)) {
        return null;
    }

    static $archiveIndex = null;

    // Load index once per request
    if ($archiveIndex === null) {
        $data = json_decode(file_get_contents($indexFile), true);
        $archiveIndex = $data['files'] ?? [];
    }

    if (empty($archiveIndex) || empty($artist) || empty($title)) {
        return null;
    }

    // Create search key from artist and title
    $searchKey = strtolower($artist . ' ' . $title);
    $searchKey = preg_replace('/[^a-z0-9\s]/', '', $searchKey);
    $searchKey = preg_replace('/\s+/', ' ', trim($searchKey));

    // Also create variations for matching
    $titleOnly = strtolower($title);
    $titleOnly = preg_replace('/[^a-z0-9\s]/', '', $titleOnly);
    $titleOnly = preg_replace('/\s+/', ' ', trim($titleOnly));

    $artistOnly = strtolower($artist);
    $artistOnly = preg_replace('/[^a-z0-9\s]/', '', $artistOnly);
    $artistOnly = preg_replace('/\s+/', ' ', trim($artistOnly));

    foreach ($archiveIndex as $entry) {
        $entryKey = $entry['search_key'] ?? '';
        $entryArtist = strtolower($entry['artist'] ?? '');
        $entryTitle = strtolower($entry['title'] ?? '');

        // Exact match on search key
        if ($searchKey === trim($entryKey)) {
            return $entry;
        }

        // Match on artist and title separately
        if (!empty($entryArtist) && !empty($entryTitle)) {
            $entryArtistClean = preg_replace('/[^a-z0-9\s]/', '', $entryArtist);
            $entryTitleClean = preg_replace('/[^a-z0-9\s]/', '', $entryTitle);

            // Check if artist and title both match
            if (strpos($entryArtistClean, $artistOnly) !== false || strpos($artistOnly, $entryArtistClean) !== false) {
                if (strpos($entryTitleClean, $titleOnly) !== false || strpos($titleOnly, $entryTitleClean) !== false) {
                    return $entry;
                }
            }
        }
    }

    return null;
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

        .nav-tabs {
            display: flex;
            justify-content: center;
            gap: 10px;
            margin-top: 15px;
        }

        .nav-tab {
            color: rgba(255,255,255,0.7);
            text-decoration: none;
            padding: 8px 20px;
            border-radius: 20px;
            font-size: 14px;
            transition: all 0.3s;
            border: 1px solid rgba(255,255,255,0.2);
        }

        .nav-tab:hover {
            background: rgba(255,255,255,0.1);
            color: white;
        }

        .nav-tab.active {
            background: rgba(255,255,255,0.2);
            color: white;
            border-color: rgba(255,255,255,0.4);
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

        .file-item-main {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .file-title-row {
            font-size: 15px;
            font-weight: 600;
            color: #333;
            line-height: 1.3;
        }

        .file-title-row .album {
            color: #999;
            font-size: 12px;
            font-weight: normal;
        }

        .file-content-row {
            display: flex;
            align-items: center;
        }

        .file-summary {
            margin-top: 5px;
            padding-top: 5px;
            padding-left: 0;
            padding-right: 0;
            border-top: 1px solid #e0e0e0;
            font-size: 11px;
            color: #666;
            font-style: italic;
            line-height: 1.4;
            text-align: left;
        }

        .archive-notice {
            margin-top: 5px;
            padding: 4px 8px;
            background: linear-gradient(135deg, #d4edda 0%, #c3e6cb 100%);
            border: 1px solid #28a745;
            border-radius: 4px;
            font-size: 11px;
            color: #155724;
            font-weight: 500;
        }

        .archive-album {
            color: #666;
            font-weight: normal;
            font-style: italic;
        }

        .file-thumbnail {
            width: 108px;
            height: 108px;
            margin-right: 15px;
            border-radius: 8px;
            object-fit: cover;
            flex-shrink: 0;
            background: #f0f0f0;
            border: 1px solid #e1e8ed;
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

        .file-duration {
            color: #667eea;
            font-size: 11px;
            font-weight: 500;
        }

        .waveform-player {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-top: 10px;
            padding: 10px;
            background: #f8f9fa;
            border-radius: 8px;
        }

        .waveform-play-btn {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            width: 40px;
            height: 40px;
            border-radius: 50%;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 16px;
            flex-shrink: 0;
            transition: transform 0.2s, box-shadow 0.2s;
        }

        .waveform-play-btn:hover {
            transform: scale(1.1);
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.4);
        }

        .waveform-container {
            flex: 1;
            height: 60px;
            position: relative;
            cursor: pointer;
            border-radius: 4px;
            overflow: hidden;
            background: #e0e0e0;
        }

        .waveform-image {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .waveform-progress {
            position: absolute;
            top: 0;
            left: 0;
            height: 100%;
            background: rgba(102, 126, 234, 0.3);
            pointer-events: none;
            width: 0%;
            transition: width 0.1s linear;
        }

        .waveform-time {
            font-size: 11px;
            color: #666;
            min-width: 80px;
            text-align: right;
            flex-shrink: 0;
        }

        .file-actions {
            display: flex;
            gap: 8px;
            align-items: center;
            flex-wrap: wrap;
        }

        .file-actions-row {
            display: flex;
            flex-direction: column;
            gap: 4px;
            align-items: flex-start;
        }

        /* Tooltip styling for all action buttons */
        .file-actions [title] {
            position: relative;
        }

        .file-actions [title]:hover::after {
            content: attr(title);
            position: absolute;
            bottom: 100%;
            left: 50%;
            transform: translateX(-50%);
            background: #333;
            color: white;
            padding: 6px 10px;
            border-radius: 4px;
            font-size: 14px;
            font-weight: 500;
            white-space: nowrap;
            z-index: 100;
            margin-bottom: 5px;
        }

        .file-actions [title]:hover::before {
            content: '';
            position: absolute;
            bottom: 100%;
            left: 50%;
            transform: translateX(-50%);
            border: 6px solid transparent;
            border-top-color: #333;
            z-index: 100;
            margin-bottom: -7px;
        }

        .download-btn {
            background: white;
            color: black;
            padding: 6px 12px;
            border-radius: 6px;
            text-decoration: none;
            font-size: 13px;
            font-weight: 600;
            white-space: nowrap;
            transition: all 0.2s;
            border: 2px solid #667eea;
            height: 34px;
            display: inline-flex;
            align-items: center;
            box-sizing: border-box;
        }

        .download-box.not-downloaded .download-btn {
            background: #a5d6a7;
            border-color: #4caf50;
        }

        .download-btn:hover {
            background: #f0f4ff;
            transform: scale(1.05);
        }

        .download-box.not-downloaded .download-btn:hover {
            background: #81c784;
        }

        .download-date {
            font-size: 11px;
            color: #666;
            white-space: nowrap;
            text-align: left;
        }

        .delete-btn {
            background: white;
            color: black;
            width: 34px;
            height: 34px;
            padding: 0;
            border-radius: 6px;
            text-decoration: none;
            font-size: 16px;
            transition: all 0.2s;
            cursor: pointer;
            border: 2px solid #dc3545;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .delete-btn:hover {
            background: #fff5f5;
            transform: scale(1.1);
        }

        .reprocess-btn {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            padding: 6px 12px;
            border-radius: 6px;
            font-size: 12px;
            cursor: pointer;
            transition: all 0.2s;
        }

        .reprocess-btn:hover {
            transform: scale(1.05);
            box-shadow: 0 2px 8px rgba(102, 126, 234, 0.4);
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
            background: white;
            color: black;
            width: 34px;
            height: 34px;
            padding: 0;
            border-radius: 6px;
            text-decoration: none;
            font-size: 16px;
            transition: all 0.2s;
            cursor: pointer;
            border: 2px solid #28a745;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .play-btn:hover {
            background: #f0f8f0;
            transform: scale(1.1);
        }

        .youtube-btn {
            background: white;
            color: black;
            width: 34px;
            height: 34px;
            padding: 0;
            border-radius: 6px;
            text-decoration: none;
            font-size: 16px;
            transition: all 0.2s;
            cursor: pointer;
            border: 2px solid #ff0000;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .youtube-btn svg {
            width: 18px;
            height: 12px;
        }

        .youtube-btn:hover {
            background: #fff5f5;
            transform: scale(1.1);
        }

        .edit-btn {
            background: white;
            color: black;
            width: 34px;
            height: 34px;
            padding: 0;
            border-radius: 6px;
            text-decoration: none;
            font-size: 16px;
            transition: all 0.2s;
            cursor: pointer;
            border: 2px solid #666;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .edit-btn:hover {
            background: #f8f8f8;
            transform: scale(1.1);
        }

        .normalize-btn {
            background: white;
            color: black;
            width: 34px;
            height: 34px;
            padding: 0;
            border-radius: 6px;
            text-decoration: none;
            font-size: 16px;
            transition: all 0.2s;
            cursor: pointer;
            border: 2px solid #666;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .normalize-btn:hover {
            background: #f8f8f8;
            transform: scale(1.1);
        }

        .normalize-btn.recommended {
            background: #a5d6a7;
            border-color: #4caf50;
        }

        .normalize-btn.recommended:hover {
            background: #81c784;
        }

        .normalize-btn.restore {
            background: #fff3e0;
            border-color: #ff9800;
        }

        .normalize-btn.restore:hover {
            background: #ffe0b2;
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

        .about-link {
            color: white;
            text-decoration: none;
            font-size: 13px;
            opacity: 0.9;
            transition: opacity 0.3s;
            cursor: pointer;
            display: inline-block;
            margin-top: 5px;
        }

        .about-link:hover {
            opacity: 1;
            text-decoration: underline;
        }

        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.5);
            overflow: auto;
        }

        .modal.active {
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .loading-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.7);
            z-index: 10000;
            justify-content: center;
            align-items: center;
            flex-direction: column;
        }

        .loading-overlay.active {
            display: flex;
        }

        .loading-spinner {
            width: 50px;
            height: 50px;
            border: 4px solid #ffffff33;
            border-top-color: #667eea;
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }

        .loading-text {
            color: white;
            margin-top: 20px;
            font-size: 18px;
            font-weight: 500;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }

        .modal-content {
            background-color: white;
            border-radius: 15px;
            padding: 30px;
            max-width: 800px;
            width: 100%;
            max-height: 90vh;
            overflow-y: auto;
            position: relative;
            box-shadow: 0 10px 40px rgba(0,0,0,0.3);
        }

        .modal-close {
            position: absolute;
            top: 15px;
            right: 20px;
            font-size: 28px;
            font-weight: bold;
            color: #999;
            cursor: pointer;
            transition: color 0.3s;
        }

        .modal-close:hover {
            color: #333;
        }

        .readme-content h1 {
            color: #333;
            margin-bottom: 15px;
            font-size: 28px;
        }

        .readme-content h2 {
            color: #667eea;
            margin-top: 25px;
            margin-bottom: 12px;
            font-size: 20px;
        }

        .readme-content h3 {
            color: #555;
            margin-top: 20px;
            margin-bottom: 10px;
            font-size: 16px;
        }

        .readme-content p {
            color: #666;
            line-height: 1.6;
            margin-bottom: 12px;
        }

        .readme-content ul {
            color: #666;
            line-height: 1.8;
            margin-bottom: 12px;
            margin-left: 25px;
        }

        .readme-content code {
            background: #f5f5f5;
            padding: 2px 6px;
            border-radius: 3px;
            font-family: monospace;
            font-size: 13px;
        }

        .readme-content pre {
            background: #f5f5f5;
            padding: 15px;
            border-radius: 6px;
            overflow-x: auto;
            margin-bottom: 15px;
        }

        .readme-content pre code {
            background: none;
            padding: 0;
        }

        .readme-content strong {
            color: #333;
        }
    </style>
</head>
<body>
    <div class="main-container">
        <div class="header">
            <h1>🎵 Harry's Rippers</h1>
            <p>Convert videos to MP3 audio files</p>
            <div class="nav-tabs">
                <a href="index.php" class="nav-tab active">MP3 Converter</a>
                <a href="wombat-playlist.php" class="nav-tab">WombatPlaylist</a>
                <a href="#" class="nav-tab" onclick="openAboutModal(); return false;">About</a>
            </div>
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

                    <button type="submit">Convert to MP3 (or press enter)</button>
                </form>

                <div class="info">
                    <strong>Note:</strong> Files are automatically deleted after 10 days. Supports YouTube and many other sites.
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
                <h2 style="display: flex; align-items: center; gap: 15px;">
                    <span>Available Files <span class="file-count">(<?php echo count($availableFiles); ?>)</span></span>
                    <?php if (!empty($availableFiles)): ?>
                    <button onclick="reprocessAll()" class="reprocess-btn" title="Re-parse all files with AI and rename to Artist - Title format">
                        🔄 Reprocess All
                    </button>
                    <?php endif; ?>
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
                                <div class="file-item-main">
                                    <!-- Title Row (full width) -->
                                    <div class="file-title-row">
                                        <?php if (!empty($file['artist']) && !empty($file['title'])): ?>
                                            <strong><?php echo htmlspecialchars($file['artist']); ?></strong> - <?php echo htmlspecialchars($file['title']); ?>
                                            <?php if (!empty($file['album'])): ?>
                                                <span class="album">(<?php echo htmlspecialchars($file['album']); ?>)</span>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <?php echo htmlspecialchars($file['name']); ?>
                                        <?php endif; ?>
                                    </div>

                                    <!-- Content Row (thumbnail + info + buttons) -->
                                    <div class="file-content-row">
                                        <?php
                                            // Use YouTube thumbnail (most reliable)
                                            $thumbnailUrl = getYouTubeThumbnail($file['original_url']);
                                        ?>
                                        <?php if (!empty($thumbnailUrl)): ?>
                                            <img src="<?php echo htmlspecialchars($thumbnailUrl); ?>"
                                                 alt="<?php echo htmlspecialchars($file['artist'] . ' - ' . $file['title']); ?>"
                                                 class="file-thumbnail"
                                                 onerror="this.src='data:image/svg+xml,%3Csvg xmlns=%22http://www.w3.org/2000/svg%22 width=%22108%22 height=%22108%22%3E%3Crect fill=%22%23e0e0e0%22 width=%22108%22 height=%22108%22/%3E%3Ctext x=%2254%22 y=%2254%22 text-anchor=%22middle%22 dominant-baseline=%22middle%22 font-family=%22Arial%22 font-size=%2240%22 fill=%22%23999%22%3E%F0%9F%8E%B5%3C/text%3E%3C/svg%3E'; this.onerror=null;">
                                        <?php else: ?>
                                            <div class="file-thumbnail" style="display: flex; align-items: center; justify-content: center; font-size: 40px;">🎵</div>
                                        <?php endif; ?>
                                        <div class="file-info">
                                            <?php if (!empty($file['video_title'])): ?>
                                                <div style="font-size: 11px; color: #999; margin-bottom: 3px;">
                                                    Original: <?php echo htmlspecialchars($file['video_title']); ?>
                                                </div>
                                            <?php endif; ?>
                                            <?php if (!empty($file['artist']) && !empty($file['title'])): ?>
                                                <div class="file-size" style="font-size: 10px; color: #999;">File: <?php echo htmlspecialchars($file['name']); ?></div>
                                            <?php endif; ?>
                                            <div class="file-size"><?php echo formatFileSize($file['size']); ?></div>
                                            <?php if (!empty($file['duration'])): ?>
                                                <div class="file-duration">Duration: <?php echo htmlspecialchars($file['duration']); ?></div>
                                            <?php endif; ?>
                                            <?php if (!empty($file['summary'])): ?>
                                                <div class="file-summary"><?php echo htmlspecialchars($file['summary']); ?></div>
                                            <?php endif; ?>
                                            <?php if (!empty($file['archive_match'])): ?>
                                                <div class="archive-notice">
                                                    📀 Download an MP3 of this from Matt's Archive
                                                    <?php if (!empty($file['archive_match']['album'])): ?>
                                                        <span class="archive-album">(<?php echo htmlspecialchars($file['archive_match']['album']); ?>)</span>
                                                    <?php endif; ?>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </div><!-- End file-content-row -->

                                    <!-- Buttons Row -->
                                    <div class="file-actions-row">
                                        <div class="file-actions">
                                            <div class="download-box" data-filename="<?php echo htmlspecialchars($file['name'], ENT_QUOTES); ?>">
                                                <a href="<?php echo htmlspecialchars($file['url']); ?>" class="download-btn" download onclick="markAsDownloaded('<?php echo htmlspecialchars($file['name'], ENT_QUOTES); ?>')" title="Download MP3">
                                                    📥 Download
                                                </a>
                                            </div>
                                            <?php if ($file['has_backup']): ?>
                                            <button class="normalize-btn restore"
                                                    onclick="restoreBackup('<?php echo htmlspecialchars($file['name'], ENT_QUOTES); ?>')"
                                                    title="Restore original (undo normalize)">
                                                ↩️
                                            </button>
                                            <?php else: ?>
                                            <button class="normalize-btn<?php echo $file['needs_normalize'] ? ' recommended' : ''; ?>"
                                                    onclick="normalizeAudio('<?php echo htmlspecialchars($file['name'], ENT_QUOTES); ?>')"
                                                    title="Normalize volume<?php echo $file['needs_normalize'] ? ' (Recommended - peak: ' . round($file['peak_level'], 1) . 'dB)' : ' (Peak: ' . round($file['peak_level'], 1) . 'dB)'; ?>">
                                                📊
                                            </button>
                                            <?php endif; ?>
                                            <?php if (!empty($file['original_url'])): ?>
                                                <a href="<?php echo htmlspecialchars($file['original_url']); ?>"
                                                   class="youtube-btn"
                                                   target="_blank"
                                                   title="View original video">
                                                    <svg viewBox="0 0 28 20" fill="none" xmlns="http://www.w3.org/2000/svg">
                                                        <rect width="28" height="20" rx="4" fill="#FF0000"/>
                                                        <path d="M11 6.5V13.5L18 10L11 6.5Z" fill="white"/>
                                                    </svg>
                                                </a>
                                            <?php endif; ?>
                                            <?php if (!empty($file['lyrics_url'])): ?>
                                                <a href="<?php echo htmlspecialchars($file['lyrics_url']); ?>"
                                                   class="edit-btn"
                                                   target="_blank"
                                                   title="View lyrics">
                                                    📝
                                                </a>
                                            <?php endif; ?>
                                            <?php if (!empty($file['artist']) || !empty($file['title'])): ?>
                                                <button class="edit-btn"
                                                        onclick="editMetadata('<?php echo htmlspecialchars($file['name'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars($file['artist'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars($file['title'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars($file['album'], ENT_QUOTES); ?>')"
                                                        title="Edit metadata">
                                                    🏷️
                                                </button>
                                            <?php endif; ?>
                                            <button class="edit-btn"
                                                    onclick="editFilename('<?php echo htmlspecialchars($file['name'], ENT_QUOTES); ?>')"
                                                    title="Rename file">
                                                ✏️
                                            </button>
                                            <button class="edit-btn"
                                                    onclick="trimAudio('<?php echo htmlspecialchars($file['name'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars($file['url'], ENT_QUOTES); ?>')"
                                                    title="Trim audio">
                                                ✂️
                                            </button>
                                            <a href="?delete=<?php echo urlencode($file['name']); ?>"
                                               class="delete-btn"
                                               onclick="return confirm('Delete this file?');"
                                               title="Delete file">
                                                🗑️
                                            </a>
                                        </div>
                                        <div class="download-date" data-filename="<?php echo htmlspecialchars($file['name'], ENT_QUOTES); ?>"></div>
                                    </div>
                                </div><!-- End file-item-main -->

                                <!-- Waveform Player -->
                                <div class="waveform-player" data-audio-url="<?php echo htmlspecialchars($file['url'], ENT_QUOTES); ?>" data-duration="<?php echo $file['duration_seconds']; ?>">
                                    <button class="waveform-play-btn" onclick="toggleWaveformPlay(this)" title="Play/Pause">
                                        ▶
                                    </button>
                                    <div class="waveform-container" onclick="seekWaveform(event, this)">
                                        <?php if (!empty($file['waveform_url'])): ?>
                                            <img src="<?php echo htmlspecialchars($file['waveform_url']); ?>" class="waveform-image" alt="Waveform">
                                        <?php endif; ?>
                                        <div class="waveform-progress"></div>
                                    </div>
                                    <div class="waveform-time">
                                        <span class="current-time">0:00:00</span> / <span class="total-time"><?php echo htmlspecialchars($file['duration']); ?></span>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Track downloaded files in localStorage with timestamps
        function getDownloadedFiles() {
            const stored = localStorage.getItem('downloadedFilesV2');
            return stored ? JSON.parse(stored) : {};
        }

        function markAsDownloaded(filename) {
            const downloaded = getDownloadedFiles();
            downloaded[filename] = Date.now();
            localStorage.setItem('downloadedFilesV2', JSON.stringify(downloaded));

            // Remove the not-downloaded class and update the date display
            const downloadBox = document.querySelector(`.download-box[data-filename="${CSS.escape(filename)}"]`);
            if (downloadBox) {
                downloadBox.classList.remove('not-downloaded');
            }
            const dateEl = document.querySelector(`.download-date[data-filename="${CSS.escape(filename)}"]`);
            if (dateEl) {
                dateEl.textContent = 'Last downloaded: Just now';
            }
        }

        function formatTimeAgo(timestamp) {
            const diff = Math.floor((Date.now() - timestamp) / 1000);

            if (diff < 60) return 'Just now';
            if (diff < 3600) {
                const mins = Math.floor(diff / 60);
                return mins + ' min' + (mins > 1 ? 's' : '') + ' ago';
            }
            if (diff < 86400) {
                const hours = Math.floor(diff / 3600);
                return hours + ' hour' + (hours > 1 ? 's' : '') + ' ago';
            }
            if (diff < 604800) {
                const days = Math.floor(diff / 86400);
                return days + ' day' + (days > 1 ? 's' : '') + ' ago';
            }

            const date = new Date(timestamp);
            const months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
            const hours = date.getHours();
            const ampm = hours >= 12 ? 'pm' : 'am';
            const hour12 = hours % 12 || 12;
            const minutes = date.getMinutes().toString().padStart(2, '0');
            return `${months[date.getMonth()]} ${date.getDate()}, ${date.getFullYear()} ${hour12}:${minutes} ${ampm}`;
        }

        function highlightUndownloadedFiles() {
            const downloaded = getDownloadedFiles();
            const downloadBoxes = document.querySelectorAll('.download-box');
            downloadBoxes.forEach(box => {
                const filename = box.getAttribute('data-filename');
                const dateEl = document.querySelector(`.download-date[data-filename="${CSS.escape(filename)}"]`);

                if (downloaded[filename]) {
                    // File has been downloaded - show when
                    if (dateEl) {
                        dateEl.textContent = 'Last downloaded: ' + formatTimeAgo(downloaded[filename]);
                    }
                } else {
                    // File not downloaded yet - highlight it
                    box.classList.add('not-downloaded');
                    if (dateEl) {
                        dateEl.textContent = 'Not yet downloaded';
                    }
                }
            });
        }

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

            // Highlight files that haven't been downloaded yet
            highlightUndownloadedFiles();
        });

        // Waveform player functionality
        let currentWaveformPlayer = null;
        let waveformAudio = new Audio();
        let waveformUpdateInterval = null;

        function formatDuration(seconds) {
            const mins = Math.floor(seconds / 60);
            const secs = Math.floor(seconds % 60);
            const hundredths = Math.floor((seconds - Math.floor(seconds)) * 100);
            return `${mins}:${secs.toString().padStart(2, '0')}:${hundredths.toString().padStart(2, '0')}`;
        }

        function updateWaveformProgress() {
            if (!currentWaveformPlayer) return;

            const progress = currentWaveformPlayer.querySelector('.waveform-progress');
            const currentTimeEl = currentWaveformPlayer.querySelector('.current-time');
            const duration = parseFloat(currentWaveformPlayer.dataset.duration) || waveformAudio.duration;

            if (duration > 0) {
                const percent = (waveformAudio.currentTime / duration) * 100;
                progress.style.width = percent + '%';
                currentTimeEl.textContent = formatDuration(waveformAudio.currentTime);
            }
        }

        function toggleWaveformPlay(button) {
            const player = button.closest('.waveform-player');
            const audioUrl = player.dataset.audioUrl;

            // If clicking a different player, stop the current one
            if (currentWaveformPlayer && currentWaveformPlayer !== player) {
                stopWaveformPlayer();
            }

            if (waveformAudio.src !== audioUrl || waveformAudio.src === '') {
                // Load new audio
                waveformAudio.src = audioUrl;
                waveformAudio.load();
            }

            if (waveformAudio.paused) {
                // Play
                currentWaveformPlayer = player;
                waveformAudio.play();
                button.textContent = '⏸';

                // Start progress updates
                waveformUpdateInterval = setInterval(updateWaveformProgress, 50);

                // Handle audio end
                waveformAudio.onended = function() {
                    stopWaveformPlayer();
                };
            } else {
                // Pause
                waveformAudio.pause();
                button.textContent = '▶';
                clearInterval(waveformUpdateInterval);
            }
        }

        function stopWaveformPlayer() {
            if (currentWaveformPlayer) {
                const button = currentWaveformPlayer.querySelector('.waveform-play-btn');
                const progress = currentWaveformPlayer.querySelector('.waveform-progress');
                const currentTimeEl = currentWaveformPlayer.querySelector('.current-time');

                button.textContent = '▶';
                progress.style.width = '0%';
                currentTimeEl.textContent = '0:00:00';
            }

            waveformAudio.pause();
            waveformAudio.currentTime = 0;
            clearInterval(waveformUpdateInterval);
            currentWaveformPlayer = null;
        }

        function seekWaveform(event, container) {
            const player = container.closest('.waveform-player');
            const rect = container.getBoundingClientRect();
            const clickX = event.clientX - rect.left;
            const percent = clickX / rect.width;
            const duration = parseFloat(player.dataset.duration) || waveformAudio.duration;

            // If this is a different player, switch to it
            if (currentWaveformPlayer !== player) {
                if (currentWaveformPlayer) {
                    stopWaveformPlayer();
                }
                currentWaveformPlayer = player;
                waveformAudio.src = player.dataset.audioUrl;
                waveformAudio.load();
            }

            waveformAudio.currentTime = percent * duration;

            // Start playing if not already
            if (waveformAudio.paused) {
                const button = player.querySelector('.waveform-play-btn');
                waveformAudio.play();
                button.textContent = '⏸';
                waveformUpdateInterval = setInterval(updateWaveformProgress, 50);

                waveformAudio.onended = function() {
                    stopWaveformPlayer();
                };
            }

            updateWaveformProgress();
        }

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

        // Metadata editing functions
        function editMetadata(filename, artist, title, album) {
            const modal = document.getElementById('metadataModal');
            document.getElementById('metaFilename').value = filename;
            document.getElementById('metaArtist').value = artist;
            document.getElementById('metaTitle').value = title;
            document.getElementById('metaAlbum').value = album;
            modal.classList.add('active');
        }

        function closeMetadataModal() {
            const modal = document.getElementById('metadataModal');
            modal.classList.remove('active');
        }

        function trimAudio(filename, url) {
            const modal = document.getElementById('trimModal');
            const audioPlayer = document.getElementById('trimAudioPlayer');
            const durationSpan = document.getElementById('trimDuration');

            document.getElementById('trimFilename').value = filename;
            audioPlayer.src = url;

            // Update duration when metadata loads
            audioPlayer.addEventListener('loadedmetadata', function() {
                const duration = Math.floor(audioPlayer.duration);
                const minutes = Math.floor(duration / 60);
                const seconds = duration % 60;
                durationSpan.textContent = `${minutes}:${seconds.toString().padStart(2, '0')}`;
            });

            modal.classList.add('active');
        }

        function closeTrimModal() {
            const modal = document.getElementById('trimModal');
            const audioPlayer = document.getElementById('trimAudioPlayer');
            audioPlayer.pause();
            audioPlayer.src = '';
            modal.classList.remove('active');
        }

        function showLoading(message) {
            document.getElementById('loadingText').textContent = message || 'Processing...';
            document.getElementById('loadingOverlay').classList.add('active');
        }

        function reprocessAll() {
            if (confirm('Reprocess all files? This will:\n\n• Re-parse all filenames with AI\n• Update MP3 metadata tags\n• Rename files to "Artist - Title" format\n• Regenerate all waveforms\n\nThis may take a while for many files.')) {
                showLoading('Reprocessing all files... This may take a while.');

                const form = document.createElement('form');
                form.method = 'POST';
                form.action = '';

                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'reprocess_all';
                input.value = '1';
                form.appendChild(input);

                document.body.appendChild(form);
                form.submit();
            }
        }

        function normalizeAudio(filename) {
            if (confirm('Normalize this audio file? This will:\n\n• Trim silence from the beginning and end\n• Maximize volume levels\n• Make quiet audio louder\n\nYou can restore the original later if needed.')) {
                showLoading('Normalizing audio... This may take a moment.');

                const form = document.createElement('form');
                form.method = 'POST';
                form.action = '';

                const normalizeInput = document.createElement('input');
                normalizeInput.type = 'hidden';
                normalizeInput.name = 'normalize_audio';
                normalizeInput.value = '1';
                form.appendChild(normalizeInput);

                const filenameInput = document.createElement('input');
                filenameInput.type = 'hidden';
                filenameInput.name = 'filename';
                filenameInput.value = filename;
                form.appendChild(filenameInput);

                document.body.appendChild(form);
                form.submit();
            }
        }

        function restoreBackup(filename) {
            if (confirm('Restore the original audio file? This will undo the normalization.')) {
                showLoading('Restoring original audio...');

                const form = document.createElement('form');
                form.method = 'POST';
                form.action = '';

                const restoreInput = document.createElement('input');
                restoreInput.type = 'hidden';
                restoreInput.name = 'restore_backup';
                restoreInput.value = '1';
                form.appendChild(restoreInput);

                const filenameInput = document.createElement('input');
                filenameInput.type = 'hidden';
                filenameInput.name = 'filename';
                filenameInput.value = filename;
                form.appendChild(filenameInput);

                document.body.appendChild(form);
                form.submit();
            }
        }

        function restoreOriginalTitle(filename, originalTitle) {
            if (confirm('Restore filename to original title: "' + originalTitle + '"?')) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.action = '';

                const restoreInput = document.createElement('input');
                restoreInput.type = 'hidden';
                restoreInput.name = 'restore_title';
                restoreInput.value = '1';
                form.appendChild(restoreInput);

                const filenameInput = document.createElement('input');
                filenameInput.type = 'hidden';
                filenameInput.name = 'filename';
                filenameInput.value = filename;
                form.appendChild(filenameInput);

                const titleInput = document.createElement('input');
                titleInput.type = 'hidden';
                titleInput.name = 'original_title';
                titleInput.value = originalTitle;
                form.appendChild(titleInput);

                document.body.appendChild(form);
                form.submit();
            }
        }

        // About modal functions
        function openAboutModal() {
            const modal = document.getElementById('aboutModal');
            modal.classList.add('active');
            loadReadme();
        }

        function closeAboutModal() {
            const modal = document.getElementById('aboutModal');
            modal.classList.remove('active');
        }

        function loadReadme() {
            fetch('README.md')
                .then(response => response.text())
                .then(markdown => {
                    const html = parseMarkdown(markdown);
                    document.getElementById('readmeContent').innerHTML = html;
                })
                .catch(error => {
                    document.getElementById('readmeContent').innerHTML = '<p>Error loading README content.</p>';
                });
        }

        // Simple markdown to HTML parser
        function parseMarkdown(markdown) {
            let html = markdown;

            // Headers
            html = html.replace(/^### (.*$)/gim, '<h3>$1</h3>');
            html = html.replace(/^## (.*$)/gim, '<h2>$1</h2>');
            html = html.replace(/^# (.*$)/gim, '<h1>$1</h1>');

            // Bold
            html = html.replace(/\*\*(.*?)\*\*/gim, '<strong>$1</strong>');

            // Code blocks
            html = html.replace(/```([\s\S]*?)```/gim, '<pre><code>$1</code></pre>');

            // Inline code
            html = html.replace(/`([^`]+)`/gim, '<code>$1</code>');

            // Lists
            html = html.replace(/^\- (.*$)/gim, '<li>$1</li>');
            html = html.replace(/(<li>.*<\/li>)/gims, '<ul>$1</ul>');

            // Paragraphs
            html = html.split('\n\n').map(para => {
                if (!para.match(/^<(h|ul|pre|li)/)) {
                    return '<p>' + para + '</p>';
                }
                return para;
            }).join('\n');

            return html;
        }

        // Close modal when clicking outside
        window.onclick = function(event) {
            const aboutModal = document.getElementById('aboutModal');
            const metadataModal = document.getElementById('metadataModal');

            if (event.target == aboutModal) {
                closeAboutModal();
            }
            if (event.target == metadataModal) {
                closeMetadataModal();
            }
        }
    </script>

    <!-- Loading Overlay -->
    <div id="loadingOverlay" class="loading-overlay">
        <div class="loading-spinner"></div>
        <div class="loading-text" id="loadingText">Processing...</div>
    </div>

    <!-- About Modal -->
    <div id="aboutModal" class="modal">
        <div class="modal-content">
            <span class="modal-close" onclick="closeAboutModal()">&times;</span>
            <div id="readmeContent" class="readme-content">
                <p>Loading...</p>
            </div>
        </div>
    </div>

    <!-- Metadata Edit Modal -->
    <div id="metadataModal" class="modal">
        <div class="modal-content">
            <span class="modal-close" onclick="closeMetadataModal()">&times;</span>
            <h2 style="color: #333; margin-bottom: 20px;">Edit Metadata</h2>
            <form method="POST" action="">
                <input type="hidden" name="update_metadata" value="1">
                <input type="hidden" name="filename" id="metaFilename">

                <div class="form-group">
                    <label for="metaArtist">Artist:</label>
                    <input type="text" id="metaArtist" name="artist" style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px;">
                </div>

                <div class="form-group">
                    <label for="metaTitle">Title:</label>
                    <input type="text" id="metaTitle" name="title" style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px;">
                </div>

                <div class="form-group">
                    <label for="metaAlbum">Album:</label>
                    <input type="text" id="metaAlbum" name="album" style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px;">
                </div>

                <button type="submit" style="width: 100%; margin-top: 10px;">Update Metadata</button>
            </form>
        </div>
    </div>

    <!-- Trim Audio Modal -->
    <div id="trimModal" class="modal">
        <div class="modal-content">
            <span class="modal-close" onclick="closeTrimModal()">&times;</span>
            <h2 style="color: #333; margin-bottom: 20px;">✂️ Trim Audio</h2>

            <div style="margin-bottom: 20px;">
                <audio id="trimAudioPlayer" controls style="width: 100%; margin-bottom: 10px;"></audio>
                <div style="font-size: 12px; color: #666;">
                    Current duration: <span id="trimDuration">--:--</span>
                </div>
            </div>

            <form method="POST" action="">
                <input type="hidden" name="trim_audio" value="1">
                <input type="hidden" name="filename" id="trimFilename">

                <div class="form-group">
                    <label for="trimStart">Start Time (seconds or MM:SS):</label>
                    <input type="text" id="trimStart" name="start_time" placeholder="0 or 00:00" style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px;">
                    <div style="font-size: 11px; color: #999; margin-top: 3px;">Example: 5 or 00:05 or 1:30</div>
                </div>

                <div class="form-group">
                    <label for="trimEnd">End Time (seconds or MM:SS, leave empty for end):</label>
                    <input type="text" id="trimEnd" name="end_time" placeholder="Leave empty for end of file" style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px;">
                    <div style="font-size: 11px; color: #999; margin-top: 3px;">Example: 180 or 03:00 or leave empty</div>
                </div>

                <button type="submit" style="width: 100%; margin-top: 10px;">✂️ Trim Audio</button>
            </form>
        </div>
    </div>
</body>
</html>
