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

// Get available MP3 files
function getAvailableFiles() {
    global $downloadsDir, $downloadsUrl;
    $files = [];
    $mp3Files = glob($downloadsDir . '/*.mp3');

    foreach ($mp3Files as $file) {
        $basename = basename($file);
        if (strpos($basename, '_backup') !== false) continue;
        if (strpos($basename, '_waveform') !== false) continue;

        // Read metadata if available
        $metaPath = $file . '.meta';
        $meta = file_exists($metaPath) ? json_decode(file_get_contents($metaPath), true) : [];

        // Get duration
        $duration = getAudioDuration($file);

        $files[] = [
            'name' => $basename,
            'url' => $downloadsUrl . '/' . rawurlencode($basename),
            'artist' => $meta['artist'] ?? '',
            'title' => $meta['title'] ?? '',
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

            // Convert webm to mp3 using ffmpeg
            $cmd = escapeshellcmd($ffmpegPath) . ' -i ' . escapeshellarg($tempWebm) .
                   ' -vn -ar 44100 -ac 1 -b:a 128k ' .
                   escapeshellarg($outputPath) . ' 2>&1';

            exec($cmd, $output, $returnCode);

            if ($returnCode !== 0 || !file_exists($outputPath)) {
                echo json_encode(['success' => false, 'error' => 'Conversion failed: ' . implode("\n", $output)]);
                exit;
            }

            // Create meta file
            $meta = [
                'artist' => 'Voice Recording',
                'title' => $name,
                'album' => '',
                'summary' => 'Voice recording created in WombatPlaylist',
                'recorded' => date('Y-m-d H:i:s')
            ];
            file_put_contents($outputPath . '.meta', json_encode($meta, JSON_PRETTY_PRINT));

            echo json_encode(['success' => true, 'filename' => $outputFilename]);
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

            // Apply normalization
            if ($loudnormStats) {
                // Two-pass normalization with measured values
                $normCmd = escapeshellcmd($ffmpegPath) . ' -i ' . escapeshellarg($tempConcat) .
                           ' -af loudnorm=I=-16:TP=-1.5:LRA=11:' .
                           'measured_I=' . ($loudnormStats['input_i'] ?? '-24') . ':' .
                           'measured_TP=' . ($loudnormStats['input_tp'] ?? '-2') . ':' .
                           'measured_LRA=' . ($loudnormStats['input_lra'] ?? '7') . ':' .
                           'measured_thresh=' . ($loudnormStats['input_thresh'] ?? '-34') . ':' .
                           'offset=' . ($loudnormStats['target_offset'] ?? '0') . ':linear=true ' .
                           '-ar 44100 -b:a 192k ' . escapeshellarg($outputPath) . ' 2>&1';
            } else {
                // Single-pass normalization as fallback
                $normCmd = escapeshellcmd($ffmpegPath) . ' -i ' . escapeshellarg($tempConcat) .
                           ' -af loudnorm=I=-16:TP=-1.5:LRA=11 -ar 44100 -b:a 192k ' .
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
                <a href="#" onclick="toggleRecorder(); return false;">Record Intro</a>
            </nav>
        </header>

        <div class="main-content">
            <!-- Playlists Panel -->
            <div class="panel">
                <h2>Playlists</h2>
                <button class="btn btn-primary" style="width: 100%; margin-bottom: 15px;" onclick="showCreateModal()">
                    + Create Playlist
                </button>
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
                <h2>Available MP3s</h2>
                <div class="available-files" id="availableFiles">
                    <?php if (empty($availableFiles)): ?>
                        <div class="empty-state">
                            <div class="icon">🎵</div>
                            <p>No MP3 files available</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($availableFiles as $file): ?>
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
                                <button class="btn btn-small btn-success add-to-playlist" onclick="addToPlaylist('<?= htmlspecialchars($file['name'], ENT_QUOTES) ?>')">+ Add</button>
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

    <!-- Recorder Modal -->
    <div class="modal" id="recorderModal">
        <div class="modal-content" style="width: 500px;">
            <h3>🎙️ Record Song Introduction</h3>
            <p style="color: #888; margin-bottom: 20px; font-size: 14px;">Record a voice introduction for your playlist tracks</p>

            <input type="text" id="recordingName" placeholder="Recording name (e.g., 'Intro - My Favorite Song')" required>

            <div id="recorderUI" style="text-align: center; padding: 20px;">
                <div id="recordingStatus" style="margin-bottom: 15px; font-size: 14px; color: #888;">
                    Click the button to start recording
                </div>

                <div id="recordingTimer" style="font-size: 36px; font-weight: bold; margin-bottom: 20px; color: #667eea; display: none;">
                    0:00
                </div>

                <div id="recordingWaveform" style="height: 60px; background: rgba(255,255,255,0.05); border-radius: 8px; margin-bottom: 20px; display: none; overflow: hidden;">
                    <canvas id="waveformCanvas" style="width: 100%; height: 100%;"></canvas>
                </div>

                <button id="recordBtn" class="btn btn-primary" style="width: 80px; height: 80px; border-radius: 50%; font-size: 24px;" onclick="toggleRecording()">
                    🎤
                </button>

                <div id="recordingControls" style="margin-top: 20px; display: none;">
                    <audio id="recordingPreview" controls style="width: 100%; margin-bottom: 15px;"></audio>
                    <div style="display: flex; gap: 10px; justify-content: center;">
                        <button class="btn btn-outline" onclick="discardRecording()">Discard</button>
                        <button class="btn btn-success" onclick="saveRecording()">Save to Library</button>
                    </div>
                </div>
            </div>

            <div class="modal-actions" style="margin-top: 20px;">
                <button class="btn btn-outline" onclick="hideRecorderModal()">Close</button>
            </div>
        </div>
    </div>

    <audio id="audioPlayer"></audio>

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
            btn.innerHTML = '⏳ Creating...';

            try {
                const response = await fetch('wombat-playlist.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                    body: 'action=merge_playlist&playlist_id=' + encodeURIComponent(currentPlaylistId)
                });

                const data = await response.json();

                if (data.success) {
                    alert(`Created "${data.filename}"\n\n${data.track_count} tracks merged\nTotal duration: ${data.duration}\n\nThe file has been normalized for consistent volume.`);
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

        function toggleRecorder() {
            document.getElementById('recorderModal').classList.add('active');
            document.getElementById('recordingName').focus();
        }

        function hideRecorderModal() {
            document.getElementById('recorderModal').classList.remove('active');
            if (isRecording) {
                stopRecording();
            }
            resetRecorder();
        }

        function resetRecorder() {
            document.getElementById('recordingName').value = '';
            document.getElementById('recordingStatus').textContent = 'Click the button to start recording';
            document.getElementById('recordingTimer').style.display = 'none';
            document.getElementById('recordingWaveform').style.display = 'none';
            document.getElementById('recordingControls').style.display = 'none';
            document.getElementById('recordBtn').textContent = '🎤';
            document.getElementById('recordBtn').style.background = 'linear-gradient(135deg, #667eea 0%, #764ba2 100%)';
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
            try {
                const stream = await navigator.mediaDevices.getUserMedia({ audio: true });

                // Set up audio context for visualization
                audioContext = new (window.AudioContext || window.webkitAudioContext)();
                analyser = audioContext.createAnalyser();
                const source = audioContext.createMediaStreamSource(stream);
                source.connect(analyser);
                analyser.fftSize = 256;

                // Set up media recorder
                mediaRecorder = new MediaRecorder(stream);
                audioChunks = [];

                mediaRecorder.ondataavailable = (e) => {
                    audioChunks.push(e.data);
                };

                mediaRecorder.onstop = () => {
                    recordingBlob = new Blob(audioChunks, { type: 'audio/webm' });
                    const audioUrl = URL.createObjectURL(recordingBlob);
                    document.getElementById('recordingPreview').src = audioUrl;
                    document.getElementById('recordingControls').style.display = 'block';
                    document.getElementById('recordingStatus').textContent = 'Recording complete! Preview and save.';

                    // Stop all tracks
                    stream.getTracks().forEach(track => track.stop());
                };

                mediaRecorder.start();
                isRecording = true;
                recordingStartTime = Date.now();

                // Update UI
                document.getElementById('recordBtn').textContent = '⏹';
                document.getElementById('recordBtn').style.background = '#dc3545';
                document.getElementById('recordingStatus').textContent = 'Recording... Click to stop';
                document.getElementById('recordingTimer').style.display = 'block';
                document.getElementById('recordingWaveform').style.display = 'block';

                // Start timer
                timerInterval = setInterval(updateTimer, 100);

                // Start visualization
                drawWaveform();

            } catch (err) {
                console.error('Error accessing microphone:', err);
                alert('Could not access microphone. Please ensure you have granted permission.');
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

        // Close recorder modal on backdrop click
        document.getElementById('recorderModal').addEventListener('click', function(e) {
            if (e.target === this) hideRecorderModal();
        });
    </script>
</body>
</html>
