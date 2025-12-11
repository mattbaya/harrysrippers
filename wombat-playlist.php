<?php
/**
 * WombatPlaylist - Playlist Manager for Harry's Rippers
 * Create and manage playlists from downloaded MP3s
 */

$downloadsDir = __DIR__ . '/downloads';
$downloadsUrl = 'downloads';
$playlistsFile = __DIR__ . '/playlists.json';
$ffprobePath = '/home/harry/bin/ffprobe';

// Get audio duration in seconds using ffprobe
function getAudioDuration($filePath) {
    global $ffprobePath;
    if (!file_exists($filePath)) return 0;

    $cmd = escapeshellcmd($ffprobePath) . ' -v error -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 ' . escapeshellarg($filePath) . ' 2>/dev/null';
    $duration = trim(shell_exec($cmd));
    return floatval($duration);
}

// Format seconds to MM:SS or HH:MM:SS
function formatDuration($seconds) {
    $seconds = intval($seconds);
    $hours = floor($seconds / 3600);
    $mins = floor(($seconds % 3600) / 60);
    $secs = $seconds % 60;

    if ($hours > 0) {
        return sprintf('%d:%02d:%02d', $hours, $mins, $secs);
    }
    return sprintf('%d:%02d', $mins, $secs);
}

// Load playlists
function loadPlaylists() {
    global $playlistsFile;
    if (file_exists($playlistsFile)) {
        return json_decode(file_get_contents($playlistsFile), true) ?: [];
    }
    return [];
}

// Save playlists
function savePlaylists($playlists) {
    global $playlistsFile;
    file_put_contents($playlistsFile, json_encode($playlists, JSON_PRETTY_PRINT));
}

// Read ID3 tags from audio file using ffprobe
function getID3Tags($filePath) {
    $ffprobePath = '/home/harry/bin/ffprobe';
    $cmd = escapeshellarg($ffprobePath) . ' -v quiet -print_format json -show_format ' . escapeshellarg($filePath) . ' 2>/dev/null';

    $output = shell_exec($cmd);
    $data = json_decode($output, true);

    $tags = $data['format']['tags'] ?? [];

    // Normalize tag keys (ID3 tags can be uppercase or lowercase)
    $normalized = [];
    foreach ($tags as $key => $value) {
        $normalized[strtolower($key)] = $value;
    }

    return [
        'artist' => $normalized['artist'] ?? $normalized['album_artist'] ?? '',
        'title' => $normalized['title'] ?? '',
        'album' => $normalized['album'] ?? '',
        'genre' => $normalized['genre'] ?? '',
        'year' => $normalized['date'] ?? $normalized['year'] ?? ''
    ];
}

// Get available MP3 files
function getAvailableFiles() {
    global $downloadsDir, $downloadsUrl;
    $files = [];
    $mp3Files = glob($downloadsDir . '/*.mp3');

    foreach ($mp3Files as $file) {
        $basename = basename($file);
        if (strpos($basename, '_backup') !== false) continue;
        if (strpos($basename, '_waveform') !== false) continue;

        // Read metadata from .meta file if available
        $metaPath = $file . '.meta';
        $meta = file_exists($metaPath) ? json_decode(file_get_contents($metaPath), true) : [];

        // If no artist/title in meta, read ID3 tags from file
        if (empty($meta['artist']) || empty($meta['title'])) {
            $id3 = getID3Tags($file);
            if (empty($meta['artist']) && !empty($id3['artist'])) {
                $meta['artist'] = $id3['artist'];
            }
            if (empty($meta['title']) && !empty($id3['title'])) {
                $meta['title'] = $id3['title'];
            }
            if (empty($meta['album']) && !empty($id3['album'])) {
                $meta['album'] = $id3['album'];
            }
        }

        // Fallback: parse filename if still no title
        if (empty($meta['title'])) {
            $name = pathinfo($basename, PATHINFO_FILENAME);
            // Try to split "Artist - Title" format
            if (strpos($name, ' - ') !== false) {
                list($fileArtist, $fileTitle) = explode(' - ', $name, 2);
                if (empty($meta['artist'])) $meta['artist'] = trim($fileArtist);
                $meta['title'] = trim($fileTitle);
            } else {
                $meta['title'] = $name;
            }
        }

        // Get duration
        $duration = getAudioDuration($file);

        $files[] = [
            'name' => $basename,
            'url' => $downloadsUrl . '/' . rawurlencode($basename),
            'artist' => $meta['artist'] ?? '',
            'title' => $meta['title'] ?? '',
            'album' => $meta['album'] ?? '',
            'size' => filesize($file),
            'modified' => filemtime($file),
            'duration' => $duration,
            'duration_formatted' => formatDuration($duration)
        ];
    }

    usort($files, function($a, $b) {
        return $b['modified'] - $a['modified'];
    });

    return $files;
}

$ffmpegPath = '/home/harry/bin/ffmpeg';

// Handle AJAX actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    $playlists = loadPlaylists();

    switch ($_POST['action']) {
        case 'save_recording':
            $name = trim($_POST['name'] ?? '');
            if (empty($name)) {
                echo json_encode(['success' => false, 'error' => 'Name required']);
                exit;
            }

            if (!isset($_FILES['audio']) || $_FILES['audio']['error'] !== UPLOAD_ERR_OK) {
                echo json_encode(['success' => false, 'error' => 'No audio file received']);
                exit;
            }

            // Clean filename
            $safeName = preg_replace('/[^a-zA-Z0-9_\-\s]/', '', $name);
            $safeName = trim($safeName);
            if (empty($safeName)) $safeName = 'Recording_' . time();

            $outputFilename = $safeName . '.mp3';
            $outputPath = $downloadsDir . '/' . $outputFilename;

            // Ensure unique filename
            $counter = 1;
            while (file_exists($outputPath)) {
                $outputFilename = $safeName . '_' . $counter . '.mp3';
                $outputPath = $downloadsDir . '/' . $outputFilename;
                $counter++;
            }

            $tempWebm = $_FILES['audio']['tmp_name'];
            $tempMp3 = sys_get_temp_dir() . '/voice_' . uniqid() . '.mp3';

            // Convert webm to mp3 first
            $cmd = escapeshellcmd($ffmpegPath) . ' -i ' . escapeshellarg($tempWebm) .
                   ' -vn -ar 44100 -ac 1 -b:a 128k ' .
                   escapeshellarg($tempMp3) . ' 2>&1';
            exec($cmd, $output, $returnCode);

            if ($returnCode !== 0 || !file_exists($tempMp3)) {
                echo json_encode(['success' => false, 'error' => 'Conversion failed']);
                exit;
            }

            // Normalize the voice recording and add ID3 tags
            $todayDate = date('Y-m-d');
            $normCmd = escapeshellcmd($ffmpegPath) . ' -i ' . escapeshellarg($tempMp3) .
                       ' -af loudnorm=I=-16:TP=-1.5:LRA=11 -ar 44100 -ac 1 -b:a 128k ' .
                       ' -metadata artist=' . escapeshellarg('Voice Recording') .
                       ' -metadata title=' . escapeshellarg($name) .
                       ' -metadata album=' . escapeshellarg($todayDate) .
                       ' -metadata genre=' . escapeshellarg('Voice Recording') .
                       ' ' . escapeshellarg($outputPath) . ' 2>&1';
            exec($normCmd, $normOutput, $normReturnCode);

            @unlink($tempMp3);

            if ($normReturnCode !== 0 || !file_exists($outputPath)) {
                echo json_encode(['success' => false, 'error' => 'Normalization failed']);
                exit;
            }

            // Create meta file
            $meta = [
                'artist' => 'Voice Recording',
                'title' => $name,
                'album' => $todayDate,
                'summary' => 'Voice recording created in WombatPlaylist',
                'recorded' => date('Y-m-d H:i:s')
            ];
            file_put_contents($outputPath . '.meta', json_encode($meta, JSON_PRETTY_PRINT));

            echo json_encode(['success' => true, 'filename' => $outputFilename]);
            exit;

        case 'upload_track':
            if (!isset($_FILES['audio']) || $_FILES['audio']['error'] !== UPLOAD_ERR_OK) {
                echo json_encode(['success' => false, 'error' => 'No file received']);
                exit;
            }

            $originalName = $_FILES['audio']['name'];
            $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
            $allowedExts = ['mp3', 'm4a', 'wav', 'ogg', 'webm'];

            if (!in_array($ext, $allowedExts)) {
                echo json_encode(['success' => false, 'error' => 'Invalid file type']);
                exit;
            }

            // Clean filename
            $baseName = pathinfo($originalName, PATHINFO_FILENAME);
            $safeName = preg_replace('/[^a-zA-Z0-9_\-\s]/', '', $baseName);
            $safeName = trim($safeName) ?: 'Upload_' . time();

            $outputFilename = $safeName . '.mp3';
            $outputPath = $downloadsDir . '/' . $outputFilename;

            // Ensure unique filename
            $counter = 1;
            while (file_exists($outputPath)) {
                $outputFilename = $safeName . '_' . $counter . '.mp3';
                $outputPath = $downloadsDir . '/' . $outputFilename;
                $counter++;
            }

            $tempFile = $_FILES['audio']['tmp_name'];

            // Convert to mp3 if not already (also normalizes format)
            $cmd = escapeshellcmd($ffmpegPath) . ' -i ' . escapeshellarg($tempFile) .
                   ' -vn -ar 44100 -ac 2 -b:a 192k ' .
                   escapeshellarg($outputPath) . ' 2>&1';
            exec($cmd, $output, $returnCode);

            if ($returnCode !== 0 || !file_exists($outputPath)) {
                echo json_encode(['success' => false, 'error' => 'Conversion failed']);
                exit;
            }

            // Try to extract title from filename (Artist - Title format)
            $artist = '';
            $title = $safeName;
            if (strpos($safeName, ' - ') !== false) {
                list($artist, $title) = explode(' - ', $safeName, 2);
            }

            // Create meta file
            $meta = [
                'artist' => trim($artist),
                'title' => trim($title),
                'album' => '',
                'summary' => 'Uploaded track',
                'uploaded' => date('Y-m-d H:i:s'),
                'original_filename' => $originalName
            ];
            file_put_contents($outputPath . '.meta', json_encode($meta, JSON_PRETTY_PRINT));

            // Copy to rclone remote and add to index
            $rcloneResult = null;
            $rclonePath = 'matts-mp3s:Music/Uploads/' . $outputFilename;
            $rcloneCmd = 'rclone copy ' . escapeshellarg($outputPath) . ' ' .
                        escapeshellarg('matts-mp3s:Music/Uploads/') . ' 2>&1';
            exec($rcloneCmd, $rcloneOutput, $rcloneReturnCode);

            if ($rcloneReturnCode === 0) {
                // Add to archive index
                $indexFile = __DIR__ . '/archive_index.json';
                if (file_exists($indexFile)) {
                    $indexData = json_decode(file_get_contents($indexFile), true);
                    $indexData['files'][] = [
                        'path' => 'Uploads/' . $outputFilename,
                        'artist' => $meta['artist'],
                        'title' => $meta['title'],
                        'album' => '',
                        'search_key' => strtolower($meta['artist'] . ' ' . $meta['title'])
                    ];
                    $indexData['count'] = count($indexData['files']);
                    file_put_contents($indexFile, json_encode($indexData, JSON_PRETTY_PRINT));
                }
                $rcloneResult = 'Copied to Matt\'s Archive';
            }

            echo json_encode([
                'success' => true,
                'filename' => $outputFilename,
                'rclone' => $rcloneResult
            ]);
            exit;

        case 'check_silence':
            // Check playlist tracks for silence periods > 5 seconds
            $playlistId = $_POST['playlist_id'] ?? '';
            if (!isset($playlists[$playlistId])) {
                echo json_encode(['success' => false, 'error' => 'Playlist not found']);
                exit;
            }

            $playlist = $playlists[$playlistId];
            $silenceWarnings = [];

            foreach ($playlist['tracks'] as $index => $track) {
                $filePath = $downloadsDir . '/' . $track['filename'];
                if (!file_exists($filePath)) continue;

                // Use ffmpeg silencedetect to find silence periods
                $detectCmd = escapeshellcmd($ffmpegPath) . ' -i ' . escapeshellarg($filePath) .
                            ' -af silencedetect=noise=-50dB:d=5 -f null - 2>&1';
                $detectOutput = shell_exec($detectCmd);

                // Parse silence_start and silence_end from output
                preg_match_all('/silence_start: ([\d.]+)/', $detectOutput, $starts);
                preg_match_all('/silence_duration: ([\d.]+)/', $detectOutput, $durations);

                if (!empty($starts[1])) {
                    foreach ($starts[1] as $i => $start) {
                        $duration = floatval($durations[1][$i] ?? 0);
                        if ($duration >= 5) {
                            $silenceWarnings[] = [
                                'track' => $index + 1,
                                'filename' => $track['filename'],
                                'start' => floatval($start),
                                'duration' => $duration
                            ];
                        }
                    }
                }
            }

            echo json_encode([
                'success' => true,
                'has_silence' => !empty($silenceWarnings),
                'warnings' => $silenceWarnings
            ]);
            exit;

        case 'edit_normalize':
            $filename = $_POST['filename'] ?? '';
            $filePath = $downloadsDir . '/' . $filename;
            if (!file_exists($filePath)) {
                echo json_encode(['success' => false, 'error' => 'File not found']);
                exit;
            }

            // Create backup
            $backupDir = $downloadsDir . '/backups';
            if (!is_dir($backupDir)) mkdir($backupDir, 0755, true);
            $backupPath = $backupDir . '/' . $filename . '.backup';
            if (!file_exists($backupPath)) {
                copy($filePath, $backupPath);
            }

            // Get peak level
            $peakCmd = escapeshellcmd($ffmpegPath) . ' -i ' . escapeshellarg($filePath) . ' -af "volumedetect" -f null - 2>&1';
            $peakOutput = shell_exec($peakCmd);
            preg_match('/max_volume: ([-\d.]+) dB/', $peakOutput, $matches);
            $maxVol = floatval($matches[1] ?? 0);
            $gainNeeded = min(-1 - $maxVol, 20);

            $tempFile = sys_get_temp_dir() . '/norm_' . uniqid() . '.mp3';
            $normCmd = escapeshellcmd($ffmpegPath) . ' -i ' . escapeshellarg($filePath) .
                       ' -af "silenceremove=start_periods=1:start_silence=1.0:start_threshold=-50dB:stop_silence=1.0:detection=peak,areverse,silenceremove=start_periods=1:start_silence=1.0:start_threshold=-50dB:stop_silence=1.0:detection=peak,areverse,volume=' . $gainNeeded . 'dB,alimiter=limit=0.95:attack=5:release=50" -ar 44100 -ab 192k ' .
                       escapeshellarg($tempFile) . ' 2>&1';
            exec($normCmd, $output, $returnCode);

            if ($returnCode === 0 && file_exists($tempFile)) {
                unlink($filePath);
                rename($tempFile, $filePath);
                echo json_encode(['success' => true]);
            } else {
                echo json_encode(['success' => false, 'error' => 'Normalization failed']);
            }
            exit;

        case 'edit_trim':
            $filename = $_POST['filename'] ?? '';
            $filePath = $downloadsDir . '/' . $filename;
            if (!file_exists($filePath)) {
                echo json_encode(['success' => false, 'error' => 'File not found']);
                exit;
            }

            // Create backup
            $backupDir = $downloadsDir . '/backups';
            if (!is_dir($backupDir)) mkdir($backupDir, 0755, true);
            $backupPath = $backupDir . '/' . $filename . '.backup';
            if (!file_exists($backupPath)) {
                copy($filePath, $backupPath);
            }

            $tempFile = sys_get_temp_dir() . '/trim_' . uniqid() . '.mp3';
            $trimCmd = escapeshellcmd($ffmpegPath) . ' -i ' . escapeshellarg($filePath) .
                       ' -af "silenceremove=start_periods=1:start_silence=1.0:start_threshold=-50dB:stop_silence=1.0:detection=peak,areverse,silenceremove=start_periods=1:start_silence=1.0:start_threshold=-50dB:stop_silence=1.0:detection=peak,areverse" -ar 44100 -ab 192k ' .
                       escapeshellarg($tempFile) . ' 2>&1';
            exec($trimCmd, $output, $returnCode);

            if ($returnCode === 0 && file_exists($tempFile)) {
                unlink($filePath);
                rename($tempFile, $filePath);
                echo json_encode(['success' => true]);
            } else {
                echo json_encode(['success' => false, 'error' => 'Trim failed']);
            }
            exit;

        case 'edit_restore':
            $filename = $_POST['filename'] ?? '';
            $filePath = $downloadsDir . '/' . $filename;
            $backupPath = $downloadsDir . '/backups/' . $filename . '.backup';

            if (!file_exists($backupPath)) {
                echo json_encode(['success' => false, 'error' => 'No backup available']);
                exit;
            }

            copy($backupPath, $filePath);
            echo json_encode(['success' => true]);
            exit;

        case 'edit_delete':
            $filename = $_POST['filename'] ?? '';
            $filePath = $downloadsDir . '/' . $filename;
            $metaPath = $filePath . '.meta';
            $waveformPath = preg_replace('/\.mp3$/', '_waveform.png', $filePath);
            $backupPath = $downloadsDir . '/backups/' . $filename . '.backup';

            if (file_exists($filePath)) unlink($filePath);
            if (file_exists($metaPath)) unlink($metaPath);
            if (file_exists($waveformPath)) unlink($waveformPath);
            if (file_exists($backupPath)) unlink($backupPath);

            echo json_encode(['success' => true]);
            exit;

        case 'add_music_bed':
            $voiceFile = $_POST['voice_file'] ?? '';
            $bedFile = $_POST['bed_file'] ?? '';
            $voicePath = $downloadsDir . '/' . $voiceFile;
            $bedPath = $downloadsDir . '/' . $bedFile;

            if (!file_exists($voicePath) || !file_exists($bedPath)) {
                echo json_encode(['success' => false, 'error' => 'Files not found']);
                exit;
            }

            // Create backup of voice file
            $backupDir = $downloadsDir . '/backups';
            if (!is_dir($backupDir)) mkdir($backupDir, 0755, true);
            $backupPath = $backupDir . '/' . $voiceFile . '.backup';
            if (!file_exists($backupPath)) {
                copy($voicePath, $backupPath);
            }

            // Get duration of voice file
            $voiceDuration = getAudioDuration($voicePath);

            $tempFile = sys_get_temp_dir() . '/bed_' . uniqid() . '.mp3';

            // Mix: voice at 100% + music bed at 20%, trim bed to voice length
            $mixCmd = escapeshellcmd($ffmpegPath) .
                      ' -i ' . escapeshellarg($voicePath) .
                      ' -i ' . escapeshellarg($bedPath) .
                      ' -filter_complex "[1:a]volume=0.2,atrim=0:' . $voiceDuration . ',apad=whole_dur=' . $voiceDuration . '[bed];[0:a][bed]amix=inputs=2:duration=first:dropout_transition=2[out]" ' .
                      '-map "[out]" -ar 44100 -ab 192k ' .
                      escapeshellarg($tempFile) . ' 2>&1';

            exec($mixCmd, $output, $returnCode);

            if ($returnCode === 0 && file_exists($tempFile)) {
                unlink($voicePath);
                rename($tempFile, $voicePath);
                echo json_encode(['success' => true]);
            } else {
                echo json_encode(['success' => false, 'error' => 'Mix failed: ' . implode("\n", $output)]);
            }
            exit;

        case 'search_archive':
            $query = strtolower(trim($_POST['query'] ?? ''));
            if (strlen($query) < 2) {
                echo json_encode(['success' => false, 'error' => 'Query too short']);
                exit;
            }

            $indexFile = __DIR__ . '/archive_index.json';
            if (!file_exists($indexFile)) {
                echo json_encode(['success' => false, 'error' => 'Archive index not found']);
                exit;
            }

            // Normalize function - strips special chars for flexible matching
            $normalize = function($str) {
                // Remove apostrophes/quotes completely (don't replace with space)
                $str = preg_replace('/[\'\"]+/', '', $str);
                // Replace other special chars with spaces
                $str = preg_replace('/[_\-\.\/\\\\&\(\)\[\]\{\},;:!?]+/', ' ', $str);
                // Collapse multiple spaces
                $str = preg_replace('/\s+/', ' ', $str);
                return strtolower(trim($str));
            };

            $queryNormalized = $normalize($query);

            $indexData = json_decode(file_get_contents($indexFile), true);
            $results = [];
            $seen = []; // For deduplication

            foreach ($indexData['files'] ?? [] as $entry) {
                // Build comprehensive search text from all fields
                $searchText = $normalize(
                    ($entry['artist'] ?? '') . ' ' .
                    ($entry['title'] ?? '') . ' ' .
                    ($entry['album'] ?? '') . ' ' .
                    ($entry['path'] ?? '')
                );

                if (strpos($searchText, $queryNormalized) !== false) {
                    // Deduplicate by artist+title combo
                    $dedupKey = strtolower(($entry['artist'] ?? '') . '|' . ($entry['title'] ?? ''));
                    if (!isset($seen[$dedupKey])) {
                        $seen[$dedupKey] = true;
                        $results[] = $entry;
                    }
                }
            }

            // Sort by artist (case-insensitive), then by title
            usort($results, function($a, $b) {
                $artistCmp = strcasecmp($a['artist'] ?? '', $b['artist'] ?? '');
                if ($artistCmp !== 0) return $artistCmp;
                return strcasecmp($a['title'] ?? '', $b['title'] ?? '');
            });

            // Limit to 50 results max
            $results = array_slice($results, 0, 50);

            echo json_encode(['success' => true, 'results' => $results]);
            exit;

        case 'download_from_archive':
            $path = $_POST['path'] ?? '';
            if (empty($path)) {
                echo json_encode(['success' => false, 'error' => 'Path required']);
                exit;
            }

            $filename = basename($path);
            $outputPath = $downloadsDir . '/' . $filename;

            // Ensure unique filename
            $counter = 1;
            $baseName = pathinfo($filename, PATHINFO_FILENAME);
            $ext = pathinfo($filename, PATHINFO_EXTENSION);
            while (file_exists($outputPath)) {
                $filename = $baseName . '_' . $counter . '.' . $ext;
                $outputPath = $downloadsDir . '/' . $filename;
                $counter++;
            }

            // Download from rclone
            $rcloneCmd = 'rclone copy ' . escapeshellarg('matts-mp3s:Music/' . $path) . ' ' .
                        escapeshellarg($downloadsDir) . ' 2>&1';
            exec($rcloneCmd, $rcloneOutput, $rcloneReturnCode);

            // Handle rename if needed
            $downloadedPath = $downloadsDir . '/' . basename($path);
            if ($downloadedPath !== $outputPath && file_exists($downloadedPath)) {
                rename($downloadedPath, $outputPath);
            }

            if (file_exists($outputPath)) {
                // Try to extract artist/title from filename
                $name = pathinfo($filename, PATHINFO_FILENAME);
                $artist = '';
                $title = $name;
                if (strpos($name, ' - ') !== false) {
                    list($artist, $title) = explode(' - ', $name, 2);
                }

                // Create meta file
                $meta = [
                    'artist' => trim($artist),
                    'title' => trim($title),
                    'album' => '',
                    'summary' => 'Downloaded from Matt\'s Archive',
                    'source' => $path,
                    'downloaded' => date('Y-m-d H:i:s')
                ];
                file_put_contents($outputPath . '.meta', json_encode($meta, JSON_PRETTY_PRINT));

                echo json_encode(['success' => true, 'filename' => $filename]);
            } else {
                echo json_encode(['success' => false, 'error' => 'Download failed']);
            }
            exit;

        case 'search_youtube':
            $query = trim($_POST['query'] ?? '');
            if (strlen($query) < 2) {
                echo json_encode(['success' => false, 'error' => 'Query too short']);
                exit;
            }

            // Use yt-dlp to search YouTube
            $ytdlpPath = '/home/harry/bin/yt-dlp';
            $searchTerm = 'ytsearch10:' . $query;
            $searchCmd = escapeshellarg($ytdlpPath) . ' ' . escapeshellarg($searchTerm) . ' --flat-playlist --dump-json 2>&1';

            $output = [];
            exec($searchCmd, $output, $returnCode);

            $results = [];
            $seen = []; // For deduplication
            foreach ($output as $line) {
                $data = json_decode($line, true);
                if ($data && isset($data['id'])) {
                    // Deduplicate by video ID
                    if (isset($seen[$data['id']])) continue;
                    $seen[$data['id']] = true;

                    $duration = '';
                    if (isset($data['duration'])) {
                        $mins = floor($data['duration'] / 60);
                        $secs = $data['duration'] % 60;
                        $duration = sprintf('%d:%02d', $mins, $secs);
                    }

                    $results[] = [
                        'title' => $data['title'] ?? 'Unknown',
                        'channel' => $data['channel'] ?? $data['uploader'] ?? '',
                        'url' => 'https://www.youtube.com/watch?v=' . $data['id'],
                        'duration' => $duration
                    ];

                    if (count($results) >= 10) break;
                }
            }

            // Sort by channel name (like sorting by artist)
            usort($results, function($a, $b) {
                return strcasecmp($a['channel'] ?? '', $b['channel'] ?? '');
            });

            echo json_encode(['success' => true, 'results' => $results]);
            exit;

        case 'import_m3u':
            if (!isset($_FILES['m3u_file']) || $_FILES['m3u_file']['error'] !== UPLOAD_ERR_OK) {
                echo json_encode(['success' => false, 'error' => 'No file uploaded']);
                exit;
            }

            $content = file_get_contents($_FILES['m3u_file']['tmp_name']);
            $lines = preg_split('/\r?\n/', $content);

            $playlistName = 'Imported Playlist';
            $tracks = [];
            $currentInfo = null;

            foreach ($lines as $line) {
                $line = trim($line);
                if (empty($line)) continue;

                if (strpos($line, '#PLAYLIST:') === 0) {
                    $playlistName = trim(substr($line, 10));
                } elseif (strpos($line, '#EXTINF:') === 0) {
                    // Parse #EXTINF:duration,Artist - Title
                    $info = substr($line, 8);
                    if (($commaPos = strpos($info, ',')) !== false) {
                        $currentInfo = trim(substr($info, $commaPos + 1));
                    }
                } elseif (strpos($line, '#') !== 0) {
                    // This is a file path or URL
                    $filename = basename(urldecode($line));
                    // Remove query strings
                    if (($qPos = strpos($filename, '?')) !== false) {
                        $filename = substr($filename, 0, $qPos);
                    }

                    $tracks[] = [
                        'filename' => $filename,
                        'info' => $currentInfo,
                        'original_path' => $line,
                        'exists' => file_exists($downloadsDir . '/' . $filename)
                    ];
                    $currentInfo = null;
                }
            }

            // Check for similar files in downloads (fuzzy match)
            $availableFiles = glob($downloadsDir . '/*.mp3');
            $availableNames = array_map('basename', $availableFiles);

            foreach ($tracks as &$track) {
                if (!$track['exists']) {
                    // Try to find similar file
                    $searchName = strtolower(pathinfo($track['filename'], PATHINFO_FILENAME));
                    foreach ($availableNames as $available) {
                        $availableName = strtolower(pathinfo($available, PATHINFO_FILENAME));
                        if (strpos($availableName, $searchName) !== false ||
                            strpos($searchName, $availableName) !== false ||
                            similar_text($searchName, $availableName) / max(strlen($searchName), strlen($availableName)) > 0.7) {
                            $track['suggested'] = $available;
                            break;
                        }
                    }
                }
            }
            unset($track);

            echo json_encode([
                'success' => true,
                'playlist_name' => $playlistName,
                'tracks' => $tracks,
                'total' => count($tracks),
                'found' => count(array_filter($tracks, fn($t) => $t['exists'])),
                'missing' => count(array_filter($tracks, fn($t) => !$t['exists']))
            ]);
            exit;

        case 'create_from_import':
            $name = trim($_POST['name'] ?? '');
            $tracksJson = $_POST['tracks'] ?? '[]';
            $tracks = json_decode($tracksJson, true);

            if (empty($name)) {
                echo json_encode(['success' => false, 'error' => 'Name required']);
                exit;
            }

            $id = uniqid('pl_');
            $playlistTracks = [];
            foreach ($tracks as $track) {
                if (!empty($track['filename']) && file_exists($downloadsDir . '/' . $track['filename'])) {
                    $playlistTracks[] = [
                        'filename' => $track['filename'],
                        'added' => time()
                    ];
                }
            }

            $playlists[$id] = [
                'id' => $id,
                'name' => $name,
                'description' => 'Imported from M3U',
                'tracks' => $playlistTracks,
                'created' => time(),
                'modified' => time()
            ];
            savePlaylists($playlists);
            echo json_encode(['success' => true, 'id' => $id, 'track_count' => count($playlistTracks)]);
            exit;

        case 'create_playlist':
            $name = trim($_POST['name'] ?? '');
            if (empty($name)) {
                echo json_encode(['success' => false, 'error' => 'Name required']);
                exit;
            }
            $id = uniqid('pl_');
            $playlists[$id] = [
                'id' => $id,
                'name' => $name,
                'description' => trim($_POST['description'] ?? ''),
                'tracks' => [],
                'created' => time(),
                'modified' => time()
            ];
            savePlaylists($playlists);
            echo json_encode(['success' => true, 'id' => $id]);
            exit;

        case 'delete_playlist':
            $id = $_POST['id'] ?? '';
            if (isset($playlists[$id])) {
                unset($playlists[$id]);
                savePlaylists($playlists);
                echo json_encode(['success' => true]);
            } else {
                echo json_encode(['success' => false, 'error' => 'Playlist not found']);
            }
            exit;

        case 'add_track':
            $playlistId = $_POST['playlist_id'] ?? '';
            $filename = $_POST['filename'] ?? '';
            if (!isset($playlists[$playlistId])) {
                echo json_encode(['success' => false, 'error' => 'Playlist not found']);
                exit;
            }
            // Check if already in playlist
            foreach ($playlists[$playlistId]['tracks'] as $track) {
                if ($track['filename'] === $filename) {
                    echo json_encode(['success' => false, 'error' => 'Track already in playlist']);
                    exit;
                }
            }
            $playlists[$playlistId]['tracks'][] = [
                'filename' => $filename,
                'added' => time()
            ];
            $playlists[$playlistId]['modified'] = time();
            savePlaylists($playlists);
            echo json_encode(['success' => true]);
            exit;

        case 'remove_track':
            $playlistId = $_POST['playlist_id'] ?? '';
            $filename = $_POST['filename'] ?? '';
            if (!isset($playlists[$playlistId])) {
                echo json_encode(['success' => false, 'error' => 'Playlist not found']);
                exit;
            }
            $playlists[$playlistId]['tracks'] = array_values(array_filter(
                $playlists[$playlistId]['tracks'],
                fn($t) => $t['filename'] !== $filename
            ));
            $playlists[$playlistId]['modified'] = time();
            savePlaylists($playlists);
            echo json_encode(['success' => true]);
            exit;

        case 'reorder_tracks':
            $playlistId = $_POST['playlist_id'] ?? '';
            $order = json_decode($_POST['order'] ?? '[]', true);
            if (!isset($playlists[$playlistId])) {
                echo json_encode(['success' => false, 'error' => 'Playlist not found']);
                exit;
            }
            // Reorder tracks based on provided order
            $tracksByName = [];
            foreach ($playlists[$playlistId]['tracks'] as $track) {
                $tracksByName[$track['filename']] = $track;
            }
            $newTracks = [];
            foreach ($order as $filename) {
                if (isset($tracksByName[$filename])) {
                    $newTracks[] = $tracksByName[$filename];
                }
            }
            $playlists[$playlistId]['tracks'] = $newTracks;
            $playlists[$playlistId]['modified'] = time();
            savePlaylists($playlists);
            echo json_encode(['success' => true]);
            exit;

        case 'get_playlist':
            $playlistId = $_POST['playlist_id'] ?? '';
            if (!isset($playlists[$playlistId])) {
                echo json_encode(['success' => false, 'error' => 'Playlist not found']);
                exit;
            }
            echo json_encode(['success' => true, 'playlist' => $playlists[$playlistId]]);
            exit;

        case 'merge_playlist':
            $playlistId = $_POST['playlist_id'] ?? '';
            $trimSilence = ($_POST['trim_silence'] ?? '') === '1';

            if (!isset($playlists[$playlistId])) {
                echo json_encode(['success' => false, 'error' => 'Playlist not found']);
                exit;
            }

            $playlist = $playlists[$playlistId];
            if (empty($playlist['tracks'])) {
                echo json_encode(['success' => false, 'error' => 'Playlist has no tracks']);
                exit;
            }

            // Verify all files exist
            $inputFiles = [];
            foreach ($playlist['tracks'] as $track) {
                $filePath = $downloadsDir . '/' . $track['filename'];
                if (!file_exists($filePath)) {
                    echo json_encode(['success' => false, 'error' => 'Track not found: ' . $track['filename']]);
                    exit;
                }
                $inputFiles[] = $filePath;
            }

            // Create output filename
            $safeName = preg_replace('/[^a-zA-Z0-9_\-\s]/', '', $playlist['name']);
            $safeName = trim($safeName) ?: 'Merged_Playlist';
            $outputFilename = $safeName . ' (Full Mix).mp3';
            $outputPath = $downloadsDir . '/' . $outputFilename;

            // Ensure unique filename
            $counter = 1;
            while (file_exists($outputPath)) {
                $outputFilename = $safeName . ' (Full Mix) ' . $counter . '.mp3';
                $outputPath = $downloadsDir . '/' . $outputFilename;
                $counter++;
            }

            // Create concat list file
            $concatListFile = sys_get_temp_dir() . '/concat_' . uniqid() . '.txt';
            $concatContent = '';
            foreach ($inputFiles as $file) {
                $concatContent .= "file " . escapeshellarg($file) . "\n";
            }
            file_put_contents($concatListFile, $concatContent);

            // First pass: concatenate all files
            $tempConcat = sys_get_temp_dir() . '/concat_' . uniqid() . '.mp3';
            $cmd1 = escapeshellcmd($ffmpegPath) . ' -f concat -safe 0 -i ' . escapeshellarg($concatListFile) .
                    ' -c copy ' . escapeshellarg($tempConcat) . ' 2>&1';
            exec($cmd1, $output1, $returnCode1);

            if ($returnCode1 !== 0 || !file_exists($tempConcat)) {
                @unlink($concatListFile);
                echo json_encode(['success' => false, 'error' => 'Concatenation failed']);
                exit;
            }

            // Second pass: normalize audio using loudnorm (two-pass for best results)
            // First, analyze the audio
            $analyzeCmd = escapeshellcmd($ffmpegPath) . ' -i ' . escapeshellarg($tempConcat) .
                          ' -af loudnorm=I=-16:TP=-1.5:LRA=11:print_format=json -f null - 2>&1';
            $analyzeOutput = shell_exec($analyzeCmd);

            // Extract loudnorm stats from output
            preg_match('/\{[^}]+\}/s', $analyzeOutput, $matches);
            $loudnormStats = null;
            if (!empty($matches[0])) {
                $loudnormStats = json_decode($matches[0], true);
            }

            // Build audio filter chain
            $silenceFilter = $trimSilence ? 'silenceremove=stop_periods=-1:stop_duration=5:stop_threshold=-50dB,' : '';

            // Apply normalization (and optionally remove silence > 5 seconds)
            if ($loudnormStats) {
                // Two-pass normalization with measured values
                $normCmd = escapeshellcmd($ffmpegPath) . ' -i ' . escapeshellarg($tempConcat) .
                           ' -af "' . $silenceFilter . 'loudnorm=I=-16:TP=-1.5:LRA=11:' .
                           'measured_I=' . ($loudnormStats['input_i'] ?? '-24') . ':' .
                           'measured_TP=' . ($loudnormStats['input_tp'] ?? '-2') . ':' .
                           'measured_LRA=' . ($loudnormStats['input_lra'] ?? '7') . ':' .
                           'measured_thresh=' . ($loudnormStats['input_thresh'] ?? '-34') . ':' .
                           'offset=' . ($loudnormStats['target_offset'] ?? '0') . ':linear=true" ' .
                           '-ar 44100 -b:a 192k ' . escapeshellarg($outputPath) . ' 2>&1';
            } else {
                // Single-pass normalization as fallback
                $normCmd = escapeshellcmd($ffmpegPath) . ' -i ' . escapeshellarg($tempConcat) .
                           ' -af "' . $silenceFilter . 'loudnorm=I=-16:TP=-1.5:LRA=11" -ar 44100 -b:a 192k ' .
                           escapeshellarg($outputPath) . ' 2>&1';
            }

            exec($normCmd, $normOutput, $normReturnCode);

            // Cleanup temp files
            @unlink($concatListFile);
            @unlink($tempConcat);

            if ($normReturnCode !== 0 || !file_exists($outputPath)) {
                echo json_encode(['success' => false, 'error' => 'Normalization failed']);
                exit;
            }

            // Calculate total duration
            $totalDuration = 0;
            foreach ($inputFiles as $file) {
                $totalDuration += getAudioDuration($file);
            }

            // Create meta file for merged playlist
            $trackList = array_map(function($t) { return $t['filename']; }, $playlist['tracks']);
            $meta = [
                'artist' => 'Various Artists',
                'title' => $playlist['name'] . ' (Full Mix)',
                'album' => 'WombatPlaylist Merge',
                'summary' => 'Merged playlist containing ' . count($playlist['tracks']) . ' tracks: ' . implode(', ', $trackList),
                'merged' => date('Y-m-d H:i:s'),
                'source_playlist' => $playlist['name'],
                'track_count' => count($playlist['tracks'])
            ];
            file_put_contents($outputPath . '.meta', json_encode($meta, JSON_PRETTY_PRINT));

            echo json_encode([
                'success' => true,
                'filename' => $outputFilename,
                'duration' => formatDuration($totalDuration),
                'track_count' => count($playlist['tracks'])
            ]);
            exit;
    }
}

// Export M3U playlist
if (isset($_GET['export']) && isset($_GET['id'])) {
    $playlists = loadPlaylists();
    $id = $_GET['id'];
    if (isset($playlists[$id])) {
        $playlist = $playlists[$id];
        header('Content-Type: audio/x-mpegurl');
        header('Content-Disposition: attachment; filename="' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $playlist['name']) . '.m3u"');
        echo "#EXTM3U\n";
        echo "#PLAYLIST:" . $playlist['name'] . "\n";
        foreach ($playlist['tracks'] as $track) {
            $filePath = $downloadsDir . '/' . $track['filename'];
            if (file_exists($filePath)) {
                $meta = [];
                $metaPath = $filePath . '.meta';
                if (file_exists($metaPath)) {
                    $meta = json_decode(file_get_contents($metaPath), true) ?: [];
                }
                $title = !empty($meta['artist']) && !empty($meta['title'])
                    ? $meta['artist'] . ' - ' . $meta['title']
                    : $track['filename'];
                echo "#EXTINF:-1," . $title . "\n";
                echo $downloadsUrl . '/' . rawurlencode($track['filename']) . "\n";
            }
        }
        exit;
    }
}

$playlists = loadPlaylists();
$availableFiles = getAvailableFiles();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>WombatPlaylist - Harry's Rippers</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, sans-serif;
            background: linear-gradient(135deg, #1a1a2e 0%, #16213e 100%);
            min-height: 100vh;
            color: #fff;
        }

        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 20px;
        }

        header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 1px solid rgba(255,255,255,0.1);
        }

        header h1 {
            font-size: 28px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .nav-tabs {
            display: flex;
            gap: 10px;
        }

        .nav-tabs a {
            color: #aaa;
            text-decoration: none;
            padding: 10px 20px;
            border-radius: 8px;
            transition: all 0.3s;
        }

        .nav-tabs a:hover, .nav-tabs a.active {
            background: rgba(102, 126, 234, 0.2);
            color: #667eea;
        }

        .main-content {
            display: grid;
            grid-template-columns: 350px 1fr 350px;
            gap: 20px;
        }

        .panel {
            background: rgba(255,255,255,0.05);
            border-radius: 15px;
            padding: 20px;
            backdrop-filter: blur(10px);
        }

        .panel h2 {
            font-size: 18px;
            margin-bottom: 15px;
            color: #667eea;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .playlist-list {
            display: flex;
            flex-direction: column;
            gap: 10px;
            max-height: 500px;
            overflow-y: auto;
        }

        .playlist-item {
            background: rgba(255,255,255,0.05);
            padding: 15px;
            border-radius: 10px;
            cursor: pointer;
            transition: all 0.3s;
            border: 2px solid transparent;
        }

        .playlist-item:hover {
            background: rgba(102, 126, 234, 0.1);
        }

        .playlist-item.active {
            border-color: #667eea;
            background: rgba(102, 126, 234, 0.2);
        }

        .playlist-item h3 {
            font-size: 16px;
            margin-bottom: 5px;
        }

        .playlist-item .meta {
            font-size: 12px;
            color: #888;
        }

        .playlist-actions {
            display: flex;
            gap: 5px;
            margin-top: 10px;
        }

        .btn {
            padding: 8px 16px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-size: 14px;
            transition: all 0.3s;
        }

        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(102, 126, 234, 0.4);
        }

        .btn-small {
            padding: 5px 10px;
            font-size: 12px;
        }

        .btn-danger {
            background: #dc3545;
            color: white;
        }

        .btn-success {
            background: #28a745;
            color: white;
        }

        .btn-outline {
            background: transparent;
            border: 1px solid #667eea;
            color: #667eea;
        }

        .track-list {
            display: flex;
            flex-direction: column;
            gap: 8px;
            max-height: 600px;
            overflow-y: auto;
        }

        .track-item {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 12px;
            background: rgba(255,255,255,0.05);
            border-radius: 8px;
            transition: all 0.3s;
        }

        .track-item:hover {
            background: rgba(255,255,255,0.1);
        }

        .track-item.playing {
            background: rgba(102, 126, 234, 0.3);
            border-left: 3px solid #667eea;
        }

        .track-item .drag-handle {
            cursor: grab;
            color: #666;
            padding: 5px;
        }

        .track-item .track-info {
            flex: 1;
            min-width: 0;
        }

        .track-item .track-title {
            font-size: 14px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .track-item .track-artist {
            font-size: 12px;
            color: #888;
        }

        .track-item .track-actions {
            display: flex;
            gap: 5px;
        }

        .track-item .track-actions button {
            background: rgba(255,255,255,0.1);
            border: none;
            color: #aaa;
            padding: 5px 8px;
            border-radius: 5px;
            cursor: pointer;
            font-size: 12px;
        }

        .track-item .track-actions button:hover {
            background: rgba(255,255,255,0.2);
            color: #fff;
        }

        .available-files {
            max-height: 600px;
            overflow-y: auto;
        }

        .available-file {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 10px;
            background: rgba(255,255,255,0.03);
            border-radius: 8px;
            margin-bottom: 8px;
            font-size: 13px;
        }

        .available-file .file-info {
            flex: 1;
            min-width: 0;
            margin-right: 10px;
        }

        .available-file .file-name {
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .available-file .file-meta {
            font-size: 11px;
            color: #666;
        }

        .player-bar {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            background: rgba(26, 26, 46, 0.95);
            backdrop-filter: blur(10px);
            padding: 15px 30px;
            display: flex;
            align-items: center;
            gap: 20px;
            border-top: 1px solid rgba(255,255,255,0.1);
        }

        .player-bar .now-playing {
            flex: 1;
            min-width: 0;
        }

        .player-bar .now-playing .title {
            font-weight: 500;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .player-bar .now-playing .artist {
            font-size: 12px;
            color: #888;
        }

        .player-bar .controls {
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .player-bar .controls button {
            background: none;
            border: none;
            color: #fff;
            font-size: 20px;
            cursor: pointer;
            padding: 10px;
            border-radius: 50%;
            transition: all 0.3s;
        }

        .player-bar .controls button:hover {
            background: rgba(255,255,255,0.1);
        }

        .player-bar .controls .play-btn {
            background: #667eea;
            font-size: 24px;
        }

        .player-bar .progress-container {
            flex: 2;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .player-bar .progress-bar {
            flex: 1;
            height: 6px;
            background: rgba(255,255,255,0.2);
            border-radius: 3px;
            cursor: pointer;
            position: relative;
        }

        .player-bar .progress-bar .progress {
            height: 100%;
            background: #667eea;
            border-radius: 3px;
            width: 0%;
        }

        .player-bar .time {
            font-size: 12px;
            color: #888;
            min-width: 45px;
        }

        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.8);
            z-index: 1000;
            align-items: center;
            justify-content: center;
        }

        .modal.active {
            display: flex;
        }

        .modal-content {
            background: #1a1a2e;
            padding: 30px;
            border-radius: 15px;
            width: 400px;
            max-width: 90%;
        }

        .modal-content h3 {
            margin-bottom: 20px;
            color: #667eea;
        }

        .modal-content input, .modal-content textarea {
            width: 100%;
            padding: 12px;
            margin-bottom: 15px;
            border: 1px solid rgba(255,255,255,0.1);
            border-radius: 8px;
            background: rgba(255,255,255,0.05);
            color: #fff;
            font-size: 14px;
        }

        .modal-content input:focus, .modal-content textarea:focus {
            outline: none;
            border-color: #667eea;
        }

        .modal-actions {
            display: flex;
            gap: 10px;
            justify-content: flex-end;
        }

        .empty-state {
            text-align: center;
            padding: 40px 20px;
            color: #666;
        }

        .empty-state .icon {
            font-size: 48px;
            margin-bottom: 15px;
        }

        .sortable-ghost {
            opacity: 0.4;
        }

        @media (max-width: 1200px) {
            .main-content {
                grid-template-columns: 1fr;
            }
        }
    </style>
    <script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.0/Sortable.min.js"></script>
</head>
<body>
    <div class="container">
        <header>
            <h1>WombatPlaylist</h1>
            <nav class="nav-tabs">
                <a href="index.php">MP3 Converter</a>
                <a href="wombat-playlist.php" class="active">Playlists</a>
            </nav>
        </header>

        <div class="main-content">
            <!-- Playlists Panel -->
            <div class="panel">
                <h2>Playlists</h2>
                <div style="display: flex; gap: 10px; margin-bottom: 15px;">
                    <button class="btn btn-primary" style="flex: 1;" onclick="showCreateModal()">
                        + Create
                    </button>
                    <button class="btn btn-outline" style="flex: 1;" onclick="document.getElementById('importM3uFile').click()">
                        📥 Import
                    </button>
                </div>
                <input type="file" id="importM3uFile" accept=".m3u,.m3u8" style="display: none;" onchange="importM3u(this.files[0])">
                <div class="playlist-list" id="playlistList">
                    <?php if (empty($playlists)): ?>
                        <div class="empty-state">
                            <div class="icon">📝</div>
                            <p>No playlists yet</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($playlists as $playlist): ?>
                            <div class="playlist-item" data-id="<?= htmlspecialchars($playlist['id']) ?>" onclick="selectPlaylist('<?= htmlspecialchars($playlist['id']) ?>')">
                                <h3><?= htmlspecialchars($playlist['name']) ?></h3>
                                <div class="meta">
                                    <?= count($playlist['tracks']) ?> tracks
                                </div>
                                <div class="playlist-actions">
                                    <button class="btn btn-small btn-success" onclick="event.stopPropagation(); playPlaylist('<?= htmlspecialchars($playlist['id']) ?>')">▶ Play</button>
                                    <a href="?export=m3u&id=<?= htmlspecialchars($playlist['id']) ?>" class="btn btn-small btn-outline" onclick="event.stopPropagation()">Export</a>
                                    <button class="btn btn-small btn-danger" onclick="event.stopPropagation(); deletePlaylist('<?= htmlspecialchars($playlist['id']) ?>')">Delete</button>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Current Playlist Tracks -->
            <div class="panel">
                <h2>Playlist Tracks</h2>
                <div id="currentPlaylistTracks">
                    <div class="empty-state">
                        <div class="icon">👈</div>
                        <p>Select a playlist to view tracks</p>
                    </div>
                </div>
            </div>

            <!-- Available Files -->
            <div class="panel">
                <h2>Track Library</h2>

                <!-- Record Voice Section -->
                <div class="section-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
                    <span style="font-size: 13px; color: #667eea; font-weight: 500;">🎤 Record Voice</span>
                </div>
                <div id="inlineRecorder" style="background: rgba(102, 126, 234, 0.1); border-radius: 10px; padding: 15px; margin-bottom: 15px;">
                    <input type="text" id="recordingName" placeholder="Recording name..." style="width: 100%; padding: 8px; margin-bottom: 10px; background: rgba(255,255,255,0.1); border: 1px solid rgba(255,255,255,0.2); border-radius: 5px; color: #fff; font-size: 13px;">
                    <div style="display: flex; gap: 10px; align-items: center;">
                        <button id="recordBtn" class="btn btn-primary btn-small" onclick="toggleRecording()" style="flex-shrink: 0;">
                            🎤 Record
                        </button>
                        <div id="recordingTimer" style="color: #667eea; font-weight: bold; display: none;">0:00</div>
                        <canvas id="waveformCanvas" style="flex: 1; height: 30px; display: none; background: rgba(0,0,0,0.2); border-radius: 4px;"></canvas>
                    </div>
                    <div id="recordingControls" style="margin-top: 10px; display: none;">
                        <audio id="recordingPreview" controls style="width: 100%; height: 32px; margin-bottom: 8px;"></audio>
                        <div style="display: flex; gap: 8px;">
                            <button class="btn btn-small btn-outline" onclick="discardRecording()">Discard</button>
                            <button class="btn btn-small btn-success" onclick="saveRecording()">Save</button>
                        </div>
                    </div>
                </div>

                <!-- Upload Section -->
                <div class="section-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
                    <span style="font-size: 13px; color: #28a745; font-weight: 500;">📁 Upload Track</span>
                </div>
                <div style="margin-bottom: 10px;">
                    <input type="file" id="uploadFile" accept=".mp3,.m4a,.wav,.ogg,.webm" style="display: none;" onchange="uploadTrack()">
                    <button class="btn btn-small btn-outline" onclick="document.getElementById('uploadFile').click()" style="width: 100%;">
                        Choose File to Upload
                    </button>
                    <div id="uploadProgress" style="display: none; margin-top: 8px; font-size: 12px; color: #888;"></div>
                </div>

                <!-- Search Matt's Archive -->
                <div class="section-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
                    <span style="font-size: 13px; color: #e83e8c; font-weight: 500;">🔍 Search Matt's Archive</span>
                </div>
                <div style="margin-bottom: 10px;">
                    <div style="display: flex; gap: 5px;">
                        <input type="text" id="archiveSearch" placeholder="Search artist or title..." style="flex: 1; padding: 8px; background: rgba(255,255,255,0.1); border: 1px solid rgba(255,255,255,0.2); border-radius: 5px; color: #fff; font-size: 13px;">
                        <button class="btn btn-small btn-primary" onclick="searchArchive()">Search</button>
                    </div>
                    <div id="archiveResults" style="display: none; margin-top: 10px; max-height: 150px; overflow-y: auto;"></div>
                </div>

                <!-- Search YouTube -->
                <div class="section-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
                    <span style="font-size: 13px; color: #ff0000; font-weight: 500;">▶️ Search YouTube</span>
                </div>
                <div style="margin-bottom: 15px;">
                    <div style="display: flex; gap: 5px;">
                        <input type="text" id="youtubeSearch" placeholder="Search for a song..." style="flex: 1; padding: 8px; background: rgba(255,255,255,0.1); border: 1px solid rgba(255,255,255,0.2); border-radius: 5px; color: #fff; font-size: 13px;">
                        <button class="btn btn-small" style="background: #ff0000;" onclick="searchYouTube()">Search</button>
                    </div>
                    <div id="youtubeResults" style="display: none; margin-top: 10px; max-height: 150px; overflow-y: auto;"></div>
                </div>

                <?php
                // Separate voice recordings from music tracks
                $voiceTracks = array_filter($availableFiles, fn($f) => ($f['artist'] ?? '') === 'Voice Recording');
                $musicTracks = array_filter($availableFiles, fn($f) => ($f['artist'] ?? '') !== 'Voice Recording');
                ?>

                <!-- Voice Recordings Section -->
                <?php if (!empty($voiceTracks)): ?>
                <div class="section-header" style="margin-bottom: 10px; padding-top: 10px; border-top: 1px solid rgba(255,255,255,0.1);">
                    <span style="font-size: 13px; color: #ffc107; font-weight: 500;">🎙️ Voice Recordings</span>
                </div>
                <div class="available-files" style="max-height: 150px; margin-bottom: 15px;">
                    <?php foreach ($voiceTracks as $file): ?>
                        <div class="available-file" data-filename="<?= htmlspecialchars($file['name']) ?>">
                            <div class="file-info">
                                <div class="file-name"><?= htmlspecialchars($file['title'] ?: $file['name']) ?></div>
                                <div class="file-meta">
                                    <span style="color: #ffc107;"><?= $file['duration_formatted'] ?></span>
                                </div>
                            </div>
                            <div style="display: flex; gap: 5px;">
                                <button class="btn btn-small" style="background: rgba(255,255,255,0.1); padding: 5px 8px;" onclick="showEditMenu('<?= htmlspecialchars($file['name'], ENT_QUOTES) ?>', true)" title="Edit">⚙️</button>
                                <button class="btn btn-small btn-success add-to-playlist" onclick="addToPlaylist('<?= htmlspecialchars($file['name'], ENT_QUOTES) ?>')">+ Add</button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>

                <!-- Music Tracks Section -->
                <div class="section-header" style="margin-bottom: 10px; <?= !empty($voiceTracks) ? '' : 'padding-top: 10px; border-top: 1px solid rgba(255,255,255,0.1);' ?>">
                    <span style="font-size: 13px; color: #17a2b8; font-weight: 500;">🎵 Music Tracks</span>
                </div>
                <div class="available-files" id="availableFiles">
                    <?php if (empty($musicTracks)): ?>
                        <div class="empty-state" style="padding: 20px;">
                            <p style="color: #666; font-size: 13px;">No music files available</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($musicTracks as $file): ?>
                            <div class="available-file" data-filename="<?= htmlspecialchars($file['name']) ?>">
                                <div class="file-info">
                                    <div class="file-name">
                                        <?php if ($file['artist'] && $file['title']): ?>
                                            <?= htmlspecialchars($file['artist'] . ' - ' . $file['title']) ?>
                                        <?php else: ?>
                                            <?= htmlspecialchars($file['name']) ?>
                                        <?php endif; ?>
                                    </div>
                                    <div class="file-meta">
                                        <span style="color: #667eea;"><?= $file['duration_formatted'] ?></span>
                                        <span style="margin-left: 8px;"><?= round($file['size'] / 1048576, 1) ?> MB</span>
                                    </div>
                                </div>
                                <div style="display: flex; gap: 5px;">
                                    <button class="btn btn-small" style="background: rgba(255,255,255,0.1); padding: 5px 8px;" onclick="showEditMenu('<?= htmlspecialchars($file['name'], ENT_QUOTES) ?>', false)" title="Edit">⚙️</button>
                                    <button class="btn btn-small btn-success add-to-playlist" onclick="addToPlaylist('<?= htmlspecialchars($file['name'], ENT_QUOTES) ?>')">+ Add</button>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Player Bar -->
    <div class="player-bar" id="playerBar" style="display: none;">
        <div class="now-playing">
            <div class="title" id="nowPlayingTitle">-</div>
            <div class="artist" id="nowPlayingArtist">-</div>
        </div>
        <div class="controls">
            <button onclick="playPrevious()">⏮</button>
            <button class="play-btn" id="playPauseBtn" onclick="togglePlayPause()">▶</button>
            <button onclick="playNext()">⏭</button>
        </div>
        <div class="progress-container">
            <span class="time" id="currentTime">0:00</span>
            <div class="progress-bar" onclick="seekTo(event)">
                <div class="progress" id="progressBar"></div>
            </div>
            <span class="time" id="totalTime">0:00</span>
        </div>
    </div>

    <!-- Create Playlist Modal -->
    <div class="modal" id="createModal">
        <div class="modal-content">
            <h3>Create New Playlist</h3>
            <input type="text" id="playlistName" placeholder="Playlist name" required>
            <textarea id="playlistDescription" placeholder="Description (optional)" rows="3"></textarea>
            <div class="modal-actions">
                <button class="btn btn-outline" onclick="hideCreateModal()">Cancel</button>
                <button class="btn btn-primary" onclick="createPlaylist()">Create</button>
            </div>
        </div>
    </div>

    <!-- Import Playlist Modal -->
    <div class="modal" id="importModal">
        <div class="modal-content" style="width: 600px; max-height: 80vh; overflow-y: auto;">
            <h3>📥 Import Playlist</h3>
            <p id="importPlaylistName" style="color: #667eea; margin-bottom: 15px;"></p>

            <div id="importSummary" style="margin-bottom: 15px; padding: 10px; background: rgba(255,255,255,0.05); border-radius: 5px;">
                <span id="importFound" style="color: #28a745;"></span>
                <span id="importMissing" style="color: #ffc107; margin-left: 15px;"></span>
            </div>

            <div id="importTrackList" style="max-height: 300px; overflow-y: auto;"></div>

            <div class="modal-actions" style="margin-top: 15px;">
                <button class="btn btn-outline" onclick="hideImportModal()">Cancel</button>
                <button class="btn btn-primary" onclick="finalizeImport()" id="finalizeImportBtn">Import Playlist</button>
            </div>
        </div>
    </div>

    <audio id="audioPlayer"></audio>

    <!-- Edit Track Modal -->
    <div class="modal" id="editModal">
        <div class="modal-content" style="width: 450px;">
            <h3>⚙️ Edit Track</h3>
            <p id="editTrackName" style="color: #888; margin-bottom: 20px; font-size: 14px; word-break: break-all;"></p>

            <div id="editOptions" style="display: flex; flex-direction: column; gap: 10px;">
                <button class="btn btn-outline" onclick="editNormalize()" style="text-align: left; padding: 12px 15px;">
                    🔊 <strong>Normalize</strong>
                    <span style="display: block; font-size: 12px; color: #888; margin-top: 3px;">Balance volume levels and trim silence</span>
                </button>
                <button class="btn btn-outline" onclick="editTrim()" style="text-align: left; padding: 12px 15px;">
                    ✂️ <strong>Trim Silence</strong>
                    <span style="display: block; font-size: 12px; color: #888; margin-top: 3px;">Remove silence from beginning and end</span>
                </button>
                <button class="btn btn-outline" onclick="editRestore()" style="text-align: left; padding: 12px 15px;">
                    ↩️ <strong>Restore Original</strong>
                    <span style="display: block; font-size: 12px; color: #888; margin-top: 3px;">Restore from backup if available</span>
                </button>
                <button class="btn btn-outline" onclick="editDelete()" style="text-align: left; padding: 12px 15px; border-color: #dc3545; color: #dc3545;">
                    🗑️ <strong>Delete Track</strong>
                    <span style="display: block; font-size: 12px; color: #888; margin-top: 3px;">Permanently remove this file</span>
                </button>
            </div>

            <!-- Voice-only: Add Music Bed -->
            <div id="voiceBedOption" style="display: none; margin-top: 15px; padding-top: 15px; border-top: 1px solid rgba(255,255,255,0.1);">
                <h4 style="color: #ffc107; margin-bottom: 10px; font-size: 14px;">🎵 Add Instrumental Bed</h4>
                <p style="font-size: 12px; color: #888; margin-bottom: 10px;">Mix background music at 20% volume under your voice recording</p>
                <select id="bedTrackSelect" style="width: 100%; padding: 10px; background: rgba(255,255,255,0.1); border: 1px solid rgba(255,255,255,0.2); border-radius: 5px; color: #fff; font-size: 13px; margin-bottom: 10px;">
                    <option value="">-- Select a music track --</option>
                    <?php foreach ($musicTracks as $file): ?>
                        <option value="<?= htmlspecialchars($file['name'], ENT_QUOTES) ?>">
                            <?= htmlspecialchars(($file['artist'] ? $file['artist'] . ' - ' : '') . $file['title']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <button class="btn btn-small btn-success" onclick="addMusicBed()" style="width: 100%;">Add Music Bed</button>
            </div>

            <div class="modal-actions" style="margin-top: 20px;">
                <button class="btn btn-outline" onclick="hideEditModal()">Close</button>
            </div>
        </div>
    </div>

    <script>
        // Global state
        let currentPlaylistId = null;
        let currentPlaylist = null;
        let currentTrackIndex = -1;
        let availableFilesData = <?= json_encode($availableFiles) ?>;

        const audioPlayer = document.getElementById('audioPlayer');

        // Audio player events
        audioPlayer.addEventListener('timeupdate', updateProgress);
        audioPlayer.addEventListener('ended', playNext);
        audioPlayer.addEventListener('loadedmetadata', () => {
            document.getElementById('totalTime').textContent = formatTime(audioPlayer.duration);
        });

        function formatTime(seconds) {
            if (isNaN(seconds)) return '0:00';
            const mins = Math.floor(seconds / 60);
            const secs = Math.floor(seconds % 60);
            return mins + ':' + String(secs).padStart(2, '0');
        }

        function updateProgress() {
            const progress = (audioPlayer.currentTime / audioPlayer.duration) * 100;
            document.getElementById('progressBar').style.width = progress + '%';
            document.getElementById('currentTime').textContent = formatTime(audioPlayer.currentTime);
        }

        function seekTo(e) {
            const bar = e.currentTarget;
            const rect = bar.getBoundingClientRect();
            const percent = (e.clientX - rect.left) / rect.width;
            audioPlayer.currentTime = percent * audioPlayer.duration;
        }

        function togglePlayPause() {
            if (audioPlayer.paused) {
                audioPlayer.play();
                document.getElementById('playPauseBtn').textContent = '⏸';
            } else {
                audioPlayer.pause();
                document.getElementById('playPauseBtn').textContent = '▶';
            }
        }

        function playTrack(index) {
            if (!currentPlaylist || index < 0 || index >= currentPlaylist.tracks.length) return;

            currentTrackIndex = index;
            const track = currentPlaylist.tracks[index];
            const fileData = availableFilesData.find(f => f.name === track.filename);

            audioPlayer.src = 'downloads/' + encodeURIComponent(track.filename);
            audioPlayer.play();

            document.getElementById('playerBar').style.display = 'flex';
            document.getElementById('playPauseBtn').textContent = '⏸';

            if (fileData) {
                document.getElementById('nowPlayingTitle').textContent = fileData.title || track.filename;
                document.getElementById('nowPlayingArtist').textContent = fileData.artist || '';
            } else {
                document.getElementById('nowPlayingTitle').textContent = track.filename;
                document.getElementById('nowPlayingArtist').textContent = '';
            }

            // Highlight current track
            document.querySelectorAll('.track-item').forEach((el, i) => {
                el.classList.toggle('playing', i === index);
            });
        }

        function playNext() {
            if (currentPlaylist && currentTrackIndex < currentPlaylist.tracks.length - 1) {
                playTrack(currentTrackIndex + 1);
            }
        }

        function playPrevious() {
            if (currentTrackIndex > 0) {
                playTrack(currentTrackIndex - 1);
            }
        }

        function selectPlaylist(id) {
            currentPlaylistId = id;

            // Update UI
            document.querySelectorAll('.playlist-item').forEach(el => {
                el.classList.toggle('active', el.dataset.id === id);
            });

            // Load playlist tracks
            fetch('wombat-playlist.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'action=get_playlist&playlist_id=' + encodeURIComponent(id)
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    currentPlaylist = data.playlist;
                    renderPlaylistTracks();
                }
            });
        }

        function renderPlaylistTracks() {
            const container = document.getElementById('currentPlaylistTracks');

            if (!currentPlaylist || currentPlaylist.tracks.length === 0) {
                container.innerHTML = `
                    <div class="empty-state">
                        <div class="icon">🎵</div>
                        <p>No tracks in this playlist</p>
                        <p style="font-size: 12px;">Add tracks from the right panel</p>
                    </div>
                `;
                return;
            }

            // Calculate total duration
            let totalDuration = 0;
            currentPlaylist.tracks.forEach(track => {
                const fileData = availableFilesData.find(f => f.name === track.filename);
                if (fileData && fileData.duration) {
                    totalDuration += fileData.duration;
                }
            });

            let html = `
                <div class="playlist-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px; padding-bottom: 10px; border-bottom: 1px solid rgba(255,255,255,0.1);">
                    <div>
                        <span style="color: #888; font-size: 13px;">${currentPlaylist.tracks.length} tracks</span>
                        <span style="color: #667eea; font-size: 13px; margin-left: 10px;">Total: ${formatDuration(totalDuration)}</span>
                    </div>
                    <button class="btn btn-primary btn-small" onclick="mergePlaylist()" id="mergeBtn">
                        🎚️ Create Full Mix
                    </button>
                </div>
            `;

            html += '<div class="track-list" id="trackList">';
            currentPlaylist.tracks.forEach((track, index) => {
                const fileData = availableFilesData.find(f => f.name === track.filename);
                const title = fileData?.title || track.filename;
                const artist = fileData?.artist || '';
                const duration = fileData?.duration_formatted || '--:--';

                html += `
                    <div class="track-item" data-filename="${escapeHtml(track.filename)}" data-index="${index}">
                        <div class="drag-handle">☰</div>
                        <div class="track-info" onclick="playTrack(${index})">
                            <div class="track-title">${escapeHtml(title)}</div>
                            ${artist ? `<div class="track-artist">${escapeHtml(artist)}</div>` : ''}
                        </div>
                        <div class="track-duration" style="color: #888; font-size: 12px; min-width: 45px; text-align: right;">
                            ${duration}
                        </div>
                        <div class="track-actions">
                            <button onclick="playTrack(${index})">▶</button>
                            <button onclick="removeTrack('${escapeHtml(track.filename)}')">✕</button>
                        </div>
                    </div>
                `;
            });
            html += '</div>';

            container.innerHTML = html;

            // Initialize sortable
            new Sortable(document.getElementById('trackList'), {
                animation: 150,
                handle: '.drag-handle',
                onEnd: saveTrackOrder
            });
        }

        function formatDuration(seconds) {
            if (!seconds || isNaN(seconds)) return '0:00';
            seconds = Math.floor(seconds);
            const hours = Math.floor(seconds / 3600);
            const mins = Math.floor((seconds % 3600) / 60);
            const secs = seconds % 60;
            if (hours > 0) {
                return `${hours}:${String(mins).padStart(2, '0')}:${String(secs).padStart(2, '0')}`;
            }
            return `${mins}:${String(secs).padStart(2, '0')}`;
        }

        async function mergePlaylist() {
            if (!currentPlaylistId || !currentPlaylist) {
                alert('No playlist selected');
                return;
            }

            if (currentPlaylist.tracks.length === 0) {
                alert('Playlist has no tracks');
                return;
            }

            const btn = document.getElementById('mergeBtn');
            const originalText = btn.innerHTML;
            btn.disabled = true;
            btn.innerHTML = '⏳ Checking...';

            try {
                // First, check for long silence periods
                const checkResponse = await fetch('wombat-playlist.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                    body: 'action=check_silence&playlist_id=' + encodeURIComponent(currentPlaylistId)
                });

                const checkData = await checkResponse.json();
                let trimSilence = false;

                if (checkData.success && checkData.has_silence) {
                    // Build warning message
                    let warning = 'Silence detected (> 5 seconds) in the following tracks:\n\n';
                    checkData.warnings.forEach(w => {
                        warning += `• Track ${w.track}: ${Math.round(w.duration)}s silence at ${Math.round(w.start)}s\n`;
                    });
                    warning += '\nWould you like to trim out these long silences?';

                    trimSilence = confirm(warning);
                }

                // Now merge the playlist
                btn.innerHTML = '⏳ Creating...';

                const response = await fetch('wombat-playlist.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                    body: 'action=merge_playlist&playlist_id=' + encodeURIComponent(currentPlaylistId) +
                          '&trim_silence=' + (trimSilence ? '1' : '0')
                });

                const data = await response.json();

                if (data.success) {
                    let msg = `Created "${data.filename}"\n\n${data.track_count} tracks merged\nTotal duration: ${data.duration}`;
                    if (trimSilence) {
                        msg += '\n\nLong silences have been trimmed.';
                    }
                    msg += '\n\nThe file has been normalized for consistent volume.';
                    alert(msg);
                    location.reload(); // Refresh to show new file
                } else {
                    alert('Error: ' + (data.error || 'Failed to merge playlist'));
                }
            } catch (err) {
                console.error('Merge error:', err);
                alert('Error merging playlist');
            } finally {
                btn.disabled = false;
                btn.innerHTML = originalText;
            }
        }

        function saveTrackOrder() {
            const tracks = document.querySelectorAll('#trackList .track-item');
            const order = Array.from(tracks).map(t => t.dataset.filename);

            fetch('wombat-playlist.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'action=reorder_tracks&playlist_id=' + encodeURIComponent(currentPlaylistId) + '&order=' + encodeURIComponent(JSON.stringify(order))
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    // Refresh playlist data
                    selectPlaylist(currentPlaylistId);
                }
            });
        }

        function addToPlaylist(filename) {
            if (!currentPlaylistId) {
                alert('Please select a playlist first');
                return;
            }

            fetch('wombat-playlist.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'action=add_track&playlist_id=' + encodeURIComponent(currentPlaylistId) + '&filename=' + encodeURIComponent(filename)
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    selectPlaylist(currentPlaylistId);
                } else {
                    alert(data.error || 'Failed to add track');
                }
            });
        }

        function removeTrack(filename) {
            if (!confirm('Remove this track from the playlist?')) return;

            fetch('wombat-playlist.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'action=remove_track&playlist_id=' + encodeURIComponent(currentPlaylistId) + '&filename=' + encodeURIComponent(filename)
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    selectPlaylist(currentPlaylistId);
                }
            });
        }

        function showCreateModal() {
            document.getElementById('createModal').classList.add('active');
            document.getElementById('playlistName').focus();
        }

        function hideCreateModal() {
            document.getElementById('createModal').classList.remove('active');
            document.getElementById('playlistName').value = '';
            document.getElementById('playlistDescription').value = '';
        }

        function createPlaylist() {
            const name = document.getElementById('playlistName').value.trim();
            const description = document.getElementById('playlistDescription').value.trim();

            if (!name) {
                alert('Please enter a playlist name');
                return;
            }

            fetch('wombat-playlist.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'action=create_playlist&name=' + encodeURIComponent(name) + '&description=' + encodeURIComponent(description)
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    location.reload();
                } else {
                    alert(data.error || 'Failed to create playlist');
                }
            });
        }

        function deletePlaylist(id) {
            if (!confirm('Delete this playlist? This cannot be undone.')) return;

            fetch('wombat-playlist.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'action=delete_playlist&id=' + encodeURIComponent(id)
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    location.reload();
                }
            });
        }

        // ====== M3U IMPORT FUNCTIONALITY ======
        let importData = null;

        async function importM3u(file) {
            if (!file) return;

            const formData = new FormData();
            formData.append('action', 'import_m3u');
            formData.append('m3u_file', file);

            try {
                const response = await fetch('wombat-playlist.php', {
                    method: 'POST',
                    body: formData
                });
                const data = await response.json();

                if (data.success) {
                    importData = data;
                    showImportModal(data);
                } else {
                    alert('Error: ' + (data.error || 'Import failed'));
                }
            } catch (err) {
                alert('Import failed: ' + err.message);
            }

            // Reset file input
            document.getElementById('importM3uFile').value = '';
        }

        function showImportModal(data) {
            document.getElementById('importPlaylistName').textContent = data.playlist_name;
            document.getElementById('importFound').textContent = '✓ ' + data.found + ' tracks found';
            document.getElementById('importMissing').textContent = data.missing > 0 ? '⚠ ' + data.missing + ' missing' : '';

            let html = '';
            data.tracks.forEach((track, index) => {
                const display = track.info || track.filename;
                if (track.exists) {
                    html += `
                        <div class="import-track" data-index="${index}" style="padding: 8px; margin-bottom: 5px; background: rgba(40, 167, 69, 0.1); border-radius: 5px; border-left: 3px solid #28a745;">
                            <div style="font-size: 13px;">✓ ${escapeHtml(display)}</div>
                            <div style="font-size: 11px; color: #666;">${escapeHtml(track.filename)}</div>
                        </div>
                    `;
                } else if (track.suggested) {
                    html += `
                        <div class="import-track" data-index="${index}" style="padding: 8px; margin-bottom: 5px; background: rgba(255, 193, 7, 0.1); border-radius: 5px; border-left: 3px solid #ffc107;">
                            <div style="font-size: 13px;">⚠ ${escapeHtml(display)}</div>
                            <div style="font-size: 11px; color: #888;">Missing: ${escapeHtml(track.filename)}</div>
                            <div style="font-size: 11px; color: #28a745; margin-top: 3px;">
                                Similar found: <a href="#" onclick="useSuggested(${index}, '${escapeHtml(track.suggested)}'); return false;">${escapeHtml(track.suggested)}</a>
                            </div>
                        </div>
                    `;
                } else {
                    html += `
                        <div class="import-track" data-index="${index}" style="padding: 8px; margin-bottom: 5px; background: rgba(220, 53, 69, 0.1); border-radius: 5px; border-left: 3px solid #dc3545;">
                            <div style="font-size: 13px;">✗ ${escapeHtml(display)}</div>
                            <div style="font-size: 11px; color: #888;">Missing: ${escapeHtml(track.filename)}</div>
                            <div style="display: flex; gap: 5px; margin-top: 5px;">
                                <input type="text" id="search_${index}" placeholder="Search..." style="flex: 1; padding: 4px 8px; font-size: 11px; background: rgba(255,255,255,0.1); border: 1px solid rgba(255,255,255,0.2); border-radius: 3px; color: #fff;">
                                <button class="btn btn-small btn-outline" onclick="searchForMissing(${index}, 'archive')" style="padding: 4px 8px; font-size: 10px;">Archive</button>
                                <button class="btn btn-small btn-outline" onclick="searchForMissing(${index}, 'youtube')" style="padding: 4px 8px; font-size: 10px;">YouTube</button>
                            </div>
                            <div id="searchResults_${index}" style="display: none; margin-top: 5px; max-height: 100px; overflow-y: auto;"></div>
                        </div>
                    `;
                }
            });

            document.getElementById('importTrackList').innerHTML = html;
            document.getElementById('importModal').classList.add('active');
        }

        function hideImportModal() {
            document.getElementById('importModal').classList.remove('active');
            importData = null;
        }

        function useSuggested(index, filename) {
            if (importData && importData.tracks[index]) {
                importData.tracks[index].filename = filename;
                importData.tracks[index].exists = true;
                importData.found++;
                importData.missing--;
                showImportModal(importData);
            }
        }

        async function searchForMissing(index, source) {
            const searchInput = document.getElementById('search_' + index);
            const query = searchInput.value.trim() || (importData.tracks[index].info || importData.tracks[index].filename);
            const resultsDiv = document.getElementById('searchResults_' + index);

            resultsDiv.style.display = 'block';
            resultsDiv.innerHTML = '<div style="color: #888; font-size: 11px;">Searching...</div>';

            const action = source === 'archive' ? 'search_archive' : 'search_youtube';

            try {
                const response = await fetch('wombat-playlist.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                    body: 'action=' + action + '&query=' + encodeURIComponent(query)
                });
                const data = await response.json();

                if (data.success && data.results.length > 0) {
                    let html = '';
                    data.results.slice(0, 5).forEach(result => {
                        if (source === 'archive') {
                            const display = (result.artist ? result.artist + ' - ' : '') + result.title;
                            html += `<div style="padding: 4px; cursor: pointer; font-size: 11px;" onmouseover="this.style.background='rgba(255,255,255,0.1)'" onmouseout="this.style.background=''" onclick="downloadForImport(${index}, 'archive', '${escapeHtml(result.path)}')">${escapeHtml(display)}</div>`;
                        } else {
                            html += `<div style="padding: 4px; cursor: pointer; font-size: 11px;" onmouseover="this.style.background='rgba(255,255,255,0.1)'" onmouseout="this.style.background=''" onclick="downloadForImport(${index}, 'youtube', '${escapeHtml(result.url)}')">${escapeHtml(result.title)} (${result.duration || ''})</div>`;
                        }
                    });
                    resultsDiv.innerHTML = html;
                } else {
                    resultsDiv.innerHTML = '<div style="color: #888; font-size: 11px;">No results found</div>';
                }
            } catch (err) {
                resultsDiv.innerHTML = '<div style="color: #dc3545; font-size: 11px;">Search failed</div>';
            }
        }

        async function downloadForImport(index, source, pathOrUrl) {
            const resultsDiv = document.getElementById('searchResults_' + index);
            resultsDiv.innerHTML = '<div style="color: #888; font-size: 11px;">Downloading...</div>';

            if (source === 'archive') {
                try {
                    const response = await fetch('wombat-playlist.php', {
                        method: 'POST',
                        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                        body: 'action=download_from_archive&path=' + encodeURIComponent(pathOrUrl)
                    });
                    const data = await response.json();

                    if (data.success) {
                        importData.tracks[index].filename = data.filename;
                        importData.tracks[index].exists = true;
                        importData.found++;
                        importData.missing--;
                        showImportModal(importData);
                    } else {
                        resultsDiv.innerHTML = '<div style="color: #dc3545; font-size: 11px;">Download failed</div>';
                    }
                } catch (err) {
                    resultsDiv.innerHTML = '<div style="color: #dc3545; font-size: 11px;">Download failed</div>';
                }
            } else {
                // For YouTube, open the converter in a new tab
                window.open('index.php?url=' + encodeURIComponent(pathOrUrl), '_blank');
                resultsDiv.innerHTML = '<div style="color: #ffc107; font-size: 11px;">Converting in new tab. After download, reload this page.</div>';
            }
        }

        async function finalizeImport() {
            if (!importData) return;

            const tracks = importData.tracks.filter(t => t.exists).map(t => ({ filename: t.filename }));

            if (tracks.length === 0) {
                alert('No tracks available to import');
                return;
            }

            try {
                const response = await fetch('wombat-playlist.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                    body: 'action=create_from_import&name=' + encodeURIComponent(importData.playlist_name) +
                          '&tracks=' + encodeURIComponent(JSON.stringify(tracks))
                });
                const data = await response.json();

                if (data.success) {
                    alert('Imported playlist with ' + data.track_count + ' tracks');
                    location.reload();
                } else {
                    alert('Error: ' + (data.error || 'Import failed'));
                }
            } catch (err) {
                alert('Import failed');
            }
        }

        function playPlaylist(id) {
            // Select and load the playlist, then start playing from track 0
            selectPlaylist(id);

            // Wait for playlist to load, then start playback
            setTimeout(() => {
                if (currentPlaylist && currentPlaylist.tracks.length > 0) {
                    playTrack(0);
                } else {
                    alert('This playlist has no tracks');
                }
            }, 300);
        }

        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        // Close modal on backdrop click
        document.getElementById('createModal').addEventListener('click', function(e) {
            if (e.target === this) hideCreateModal();
        });

        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            if (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA') return;

            if (e.code === 'Space') {
                e.preventDefault();
                togglePlayPause();
            }
        });

        // ====== UPLOAD FUNCTIONALITY ======
        async function uploadTrack() {
            const fileInput = document.getElementById('uploadFile');
            const file = fileInput.files[0];
            if (!file) return;

            const progress = document.getElementById('uploadProgress');
            progress.style.display = 'block';
            progress.textContent = 'Uploading and converting...';

            const formData = new FormData();
            formData.append('action', 'upload_track');
            formData.append('audio', file);

            try {
                const response = await fetch('wombat-playlist.php', {
                    method: 'POST',
                    body: formData
                });

                const data = await response.json();

                if (data.success) {
                    let msg = 'Uploaded: ' + data.filename;
                    if (data.rclone) msg += '\n' + data.rclone;
                    alert(msg);
                    location.reload();
                } else {
                    alert('Error: ' + (data.error || 'Upload failed'));
                }
            } catch (err) {
                console.error('Upload error:', err);
                alert('Upload failed');
            } finally {
                progress.style.display = 'none';
                fileInput.value = '';
            }
        }

        // ====== EDIT TRACK FUNCTIONALITY ======
        let editingFilename = '';
        let editingIsVoice = false;

        function showEditMenu(filename, isVoice) {
            editingFilename = filename;
            editingIsVoice = isVoice;
            document.getElementById('editTrackName').textContent = filename;
            document.getElementById('voiceBedOption').style.display = isVoice ? 'block' : 'none';
            document.getElementById('editModal').classList.add('active');
        }

        function hideEditModal() {
            document.getElementById('editModal').classList.remove('active');
            editingFilename = '';
        }

        async function editNormalize() {
            if (!editingFilename) return;
            if (!confirm('Normalize this track? This will balance volume and trim silence.')) return;

            hideEditModal();
            try {
                const response = await fetch('wombat-playlist.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                    body: 'action=edit_normalize&filename=' + encodeURIComponent(editingFilename)
                });
                const data = await response.json();
                if (data.success) {
                    alert('Track normalized successfully');
                    location.reload();
                } else {
                    alert('Error: ' + (data.error || 'Normalization failed'));
                }
            } catch (err) {
                alert('Error normalizing track');
            }
        }

        async function editTrim() {
            if (!editingFilename) return;
            if (!confirm('Trim silence from this track?')) return;

            hideEditModal();
            try {
                const response = await fetch('wombat-playlist.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                    body: 'action=edit_trim&filename=' + encodeURIComponent(editingFilename)
                });
                const data = await response.json();
                if (data.success) {
                    alert('Silence trimmed successfully');
                    location.reload();
                } else {
                    alert('Error: ' + (data.error || 'Trim failed'));
                }
            } catch (err) {
                alert('Error trimming track');
            }
        }

        async function editRestore() {
            if (!editingFilename) return;
            if (!confirm('Restore original version of this track?')) return;

            hideEditModal();
            try {
                const response = await fetch('wombat-playlist.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                    body: 'action=edit_restore&filename=' + encodeURIComponent(editingFilename)
                });
                const data = await response.json();
                if (data.success) {
                    alert('Original restored successfully');
                    location.reload();
                } else {
                    alert('Error: ' + (data.error || 'Restore failed'));
                }
            } catch (err) {
                alert('Error restoring track');
            }
        }

        async function editDelete() {
            if (!editingFilename) return;
            if (!confirm('DELETE this track permanently?\n\n' + editingFilename + '\n\nThis cannot be undone!')) return;

            hideEditModal();
            try {
                const response = await fetch('wombat-playlist.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                    body: 'action=edit_delete&filename=' + encodeURIComponent(editingFilename)
                });
                const data = await response.json();
                if (data.success) {
                    alert('Track deleted');
                    location.reload();
                } else {
                    alert('Error: ' + (data.error || 'Delete failed'));
                }
            } catch (err) {
                alert('Error deleting track');
            }
        }

        async function addMusicBed() {
            const bedTrack = document.getElementById('bedTrackSelect').value;
            if (!bedTrack) {
                alert('Please select a music track');
                return;
            }
            if (!confirm('Add music bed to this voice recording?\n\nThe music will play at 20% volume under your voice.')) return;

            hideEditModal();
            try {
                const response = await fetch('wombat-playlist.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                    body: 'action=add_music_bed&voice_file=' + encodeURIComponent(editingFilename) + '&bed_file=' + encodeURIComponent(bedTrack)
                });
                const data = await response.json();
                if (data.success) {
                    alert('Music bed added successfully');
                    location.reload();
                } else {
                    alert('Error: ' + (data.error || 'Mix failed'));
                }
            } catch (err) {
                alert('Error adding music bed');
            }
        }

        // Close edit modal on backdrop click
        document.getElementById('editModal').addEventListener('click', function(e) {
            if (e.target === this) hideEditModal();
        });

        // ====== ARCHIVE SEARCH FUNCTIONALITY ======
        async function searchArchive() {
            const query = document.getElementById('archiveSearch').value.trim();
            if (query.length < 2) {
                alert('Please enter at least 2 characters');
                return;
            }

            const resultsDiv = document.getElementById('archiveResults');
            resultsDiv.style.display = 'block';
            resultsDiv.innerHTML = '<div style="color: #888; padding: 10px;">Searching...</div>';

            try {
                const response = await fetch('wombat-playlist.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                    body: 'action=search_archive&query=' + encodeURIComponent(query)
                });
                const data = await response.json();

                if (data.success && data.results.length > 0) {
                    let html = '';
                    data.results.forEach(result => {
                        const display = (result.artist ? result.artist + ' - ' : '') + result.title;
                        html += `
                            <div style="display: flex; justify-content: space-between; align-items: center; padding: 8px; background: rgba(255,255,255,0.05); border-radius: 5px; margin-bottom: 5px;">
                                <div style="flex: 1; overflow: hidden;">
                                    <div style="font-size: 13px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">${escapeHtml(display)}</div>
                                    <div style="font-size: 11px; color: #666;">${escapeHtml(result.album || '')}</div>
                                </div>
                                <button class="btn btn-small btn-success" onclick="downloadFromArchive('${escapeHtml(result.path)}')" style="flex-shrink: 0; margin-left: 10px;">Download</button>
                            </div>
                        `;
                    });
                    resultsDiv.innerHTML = html;
                } else if (data.success) {
                    resultsDiv.innerHTML = '<div style="color: #888; padding: 10px;">No results found</div>';
                } else {
                    resultsDiv.innerHTML = '<div style="color: #dc3545; padding: 10px;">Error: ' + (data.error || 'Search failed') + '</div>';
                }
            } catch (err) {
                resultsDiv.innerHTML = '<div style="color: #dc3545; padding: 10px;">Search failed</div>';
            }
        }

        async function downloadFromArchive(path) {
            if (!confirm('Download this track from Matt\'s Archive?')) return;

            const resultsDiv = document.getElementById('archiveResults');
            resultsDiv.innerHTML = '<div style="color: #888; padding: 10px;">Downloading...</div>';

            try {
                const response = await fetch('wombat-playlist.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                    body: 'action=download_from_archive&path=' + encodeURIComponent(path)
                });
                const data = await response.json();

                if (data.success) {
                    alert('Downloaded: ' + data.filename);
                    location.reload();
                } else {
                    alert('Error: ' + (data.error || 'Download failed'));
                    resultsDiv.innerHTML = '';
                    resultsDiv.style.display = 'none';
                }
            } catch (err) {
                alert('Download failed');
            }
        }

        // Search on Enter key
        document.getElementById('archiveSearch').addEventListener('keypress', function(e) {
            if (e.key === 'Enter') searchArchive();
        });

        // ====== YOUTUBE SEARCH FUNCTIONALITY ======
        async function searchYouTube() {
            const query = document.getElementById('youtubeSearch').value.trim();
            if (query.length < 2) {
                alert('Please enter at least 2 characters');
                return;
            }

            const resultsDiv = document.getElementById('youtubeResults');
            resultsDiv.style.display = 'block';
            resultsDiv.innerHTML = '<div style="color: #888; padding: 10px;">Searching YouTube...</div>';

            try {
                const response = await fetch('wombat-playlist.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                    body: 'action=search_youtube&query=' + encodeURIComponent(query)
                });
                const data = await response.json();

                if (data.success && data.results.length > 0) {
                    let html = '';
                    data.results.forEach(result => {
                        html += `
                            <div style="display: flex; justify-content: space-between; align-items: center; padding: 8px; background: rgba(255,255,255,0.05); border-radius: 5px; margin-bottom: 5px;">
                                <div style="flex: 1; overflow: hidden;">
                                    <div style="font-size: 13px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">${escapeHtml(result.title)}</div>
                                    <div style="font-size: 11px; color: #666;">${escapeHtml(result.channel)} • ${result.duration || ''}</div>
                                </div>
                                <button class="btn btn-small btn-primary" onclick="downloadFromYouTube('${escapeHtml(result.url)}')" style="flex-shrink: 0; margin-left: 10px;">Get MP3</button>
                            </div>
                        `;
                    });
                    resultsDiv.innerHTML = html;
                } else if (data.success) {
                    resultsDiv.innerHTML = '<div style="color: #888; padding: 10px;">No results found</div>';
                } else {
                    resultsDiv.innerHTML = '<div style="color: #dc3545; padding: 10px;">Error: ' + (data.error || 'Search failed') + '</div>';
                }
            } catch (err) {
                resultsDiv.innerHTML = '<div style="color: #dc3545; padding: 10px;">Search failed</div>';
            }
        }

        function downloadFromYouTube(url) {
            // Open MP3 Converter with the URL pre-filled
            window.location.href = 'index.php?url=' + encodeURIComponent(url);
        }

        // YouTube search on Enter key
        document.getElementById('youtubeSearch').addEventListener('keypress', function(e) {
            if (e.key === 'Enter') searchYouTube();
        });

        // ====== RECORDER FUNCTIONALITY ======
        let mediaRecorder = null;
        let audioChunks = [];
        let recordingBlob = null;
        let isRecording = false;
        let recordingStartTime = null;
        let timerInterval = null;
        let audioContext = null;
        let analyser = null;
        let animationFrame = null;
        let currentStream = null;

        function resetRecorder() {
            document.getElementById('recordingName').value = '';
            document.getElementById('recordingTimer').style.display = 'none';
            document.getElementById('waveformCanvas').style.display = 'none';
            document.getElementById('recordingControls').style.display = 'none';
            document.getElementById('recordBtn').innerHTML = '🎤 Record';
            audioChunks = [];
            recordingBlob = null;
        }

        async function toggleRecording() {
            if (!isRecording) {
                await startRecording();
            } else {
                stopRecording();
            }
        }

        async function startRecording() {
            const name = document.getElementById('recordingName').value.trim();
            if (!name) {
                alert('Please enter a name for the recording first');
                document.getElementById('recordingName').focus();
                return;
            }

            try {
                currentStream = await navigator.mediaDevices.getUserMedia({ audio: true });

                // Set up audio context for visualization
                audioContext = new (window.AudioContext || window.webkitAudioContext)();
                analyser = audioContext.createAnalyser();
                const source = audioContext.createMediaStreamSource(currentStream);
                source.connect(analyser);
                analyser.fftSize = 256;

                // Set up media recorder
                mediaRecorder = new MediaRecorder(currentStream);
                audioChunks = [];

                mediaRecorder.ondataavailable = (e) => {
                    audioChunks.push(e.data);
                };

                mediaRecorder.onstop = () => {
                    recordingBlob = new Blob(audioChunks, { type: 'audio/webm' });
                    const audioUrl = URL.createObjectURL(recordingBlob);
                    document.getElementById('recordingPreview').src = audioUrl;
                    document.getElementById('recordingControls').style.display = 'block';
                };

                mediaRecorder.start();
                isRecording = true;
                recordingStartTime = Date.now();

                // Update UI
                document.getElementById('recordBtn').innerHTML = '⏹ Stop';
                document.getElementById('recordingTimer').style.display = 'inline';
                document.getElementById('waveformCanvas').style.display = 'block';

                // Start timer
                timerInterval = setInterval(updateTimer, 100);

                // Start visualization
                drawWaveform();

            } catch (err) {
                console.error('Error accessing microphone:', err);
                alert('Could not access microphone. Please grant permission.');
            }
        }

        function stopRecording() {
            if (mediaRecorder && mediaRecorder.state !== 'inactive') {
                mediaRecorder.stop();
            }
            isRecording = false;

            // Stop timer
            if (timerInterval) {
                clearInterval(timerInterval);
                timerInterval = null;
            }

            // Stop visualization
            if (animationFrame) {
                cancelAnimationFrame(animationFrame);
                animationFrame = null;
            }

            // Stop stream
            if (currentStream) {
                currentStream.getTracks().forEach(track => track.stop());
                currentStream = null;
            }

            // Close audio context
            if (audioContext) {
                audioContext.close();
                audioContext = null;
            }

            document.getElementById('recordBtn').textContent = '🎤';
            document.getElementById('recordBtn').style.background = 'linear-gradient(135deg, #667eea 0%, #764ba2 100%)';
        }

        function updateTimer() {
            const elapsed = Date.now() - recordingStartTime;
            const seconds = Math.floor(elapsed / 1000);
            const mins = Math.floor(seconds / 60);
            const secs = seconds % 60;
            document.getElementById('recordingTimer').textContent = mins + ':' + String(secs).padStart(2, '0');
        }

        function drawWaveform() {
            if (!analyser) return;

            const canvas = document.getElementById('waveformCanvas');
            const ctx = canvas.getContext('2d');
            const bufferLength = analyser.frequencyBinCount;
            const dataArray = new Uint8Array(bufferLength);

            canvas.width = canvas.offsetWidth;
            canvas.height = canvas.offsetHeight;

            function draw() {
                if (!isRecording) return;

                animationFrame = requestAnimationFrame(draw);
                analyser.getByteFrequencyData(dataArray);

                ctx.fillStyle = 'rgba(26, 26, 46, 0.3)';
                ctx.fillRect(0, 0, canvas.width, canvas.height);

                const barWidth = (canvas.width / bufferLength) * 2.5;
                let x = 0;

                for (let i = 0; i < bufferLength; i++) {
                    const barHeight = (dataArray[i] / 255) * canvas.height;

                    const gradient = ctx.createLinearGradient(0, canvas.height, 0, canvas.height - barHeight);
                    gradient.addColorStop(0, '#667eea');
                    gradient.addColorStop(1, '#764ba2');

                    ctx.fillStyle = gradient;
                    ctx.fillRect(x, canvas.height - barHeight, barWidth, barHeight);

                    x += barWidth + 1;
                }
            }

            draw();
        }

        function discardRecording() {
            resetRecorder();
            document.getElementById('recordingStatus').textContent = 'Click the button to start recording';
        }

        async function saveRecording() {
            const name = document.getElementById('recordingName').value.trim();
            if (!name) {
                alert('Please enter a name for the recording');
                return;
            }

            if (!recordingBlob) {
                alert('No recording to save');
                return;
            }

            // Convert to FormData and send to server
            const formData = new FormData();
            formData.append('action', 'save_recording');
            formData.append('name', name);
            formData.append('audio', recordingBlob, 'recording.webm');

            try {
                document.getElementById('recordingStatus').textContent = 'Saving...';

                const response = await fetch('wombat-playlist.php', {
                    method: 'POST',
                    body: formData
                });

                const data = await response.json();

                if (data.success) {
                    alert('Recording saved as: ' + data.filename);
                    hideRecorderModal();
                    location.reload(); // Refresh to show new file
                } else {
                    alert('Error saving: ' + (data.error || 'Unknown error'));
                }
            } catch (err) {
                console.error('Save error:', err);
                alert('Error saving recording');
            }
        }

    </script>
</body>
</html>
