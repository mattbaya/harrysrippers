#!/usr/bin/env php
<?php
/**
 * Index Matt's MP3 Archive
 * Run via cron daily to build a searchable index of the music library
 *
 * Usage: php index_archive.php
 * Cron:  0 3 * * * /usr/bin/php /home/harry/public_html/harrysrippers/index_archive.php
 */

$rclonePath = '/usr/bin/rclone';
$remotePath = 'matts-mp3s:/Music/Music';
$indexFile = __DIR__ . '/archive_index.json';

echo "Starting archive index at " . date('Y-m-d H:i:s') . "\n";

// Get list of all audio files from rclone
$command = sprintf(
    '%s lsf -R --files-only --include "*.mp3" --include "*.m4a" --include "*.flac" --include "*.wav" --include "*.aac" %s 2>&1',
    escapeshellarg($rclonePath),
    escapeshellarg($remotePath)
);

exec($command, $output, $returnCode);

if ($returnCode !== 0) {
    echo "Error running rclone: " . implode("\n", $output) . "\n";
    exit(1);
}

echo "Found " . count($output) . " audio files\n";

$index = [];

foreach ($output as $filePath) {
    $filePath = trim($filePath);
    if (empty($filePath)) continue;

    // Parse the path to extract artist and title
    // Format is usually: Artist/Album/## Title.ext or just filename.ext
    $parts = explode('/', $filePath);
    $filename = end($parts);

    // Remove extension
    $name = preg_replace('/\.(mp3|m4a|flac|wav|aac)$/i', '', $filename);

    // Remove track number prefix (e.g., "01 ", "1-01 ", "01. ", etc.)
    $name = preg_replace('/^[\d\-]+[\s\.\-]+/', '', $name);

    $artist = '';
    $title = $name;
    $album = '';

    // If we have Artist/Album/Track structure
    if (count($parts) >= 3) {
        $artist = $parts[0];
        $album = $parts[1];
    } elseif (count($parts) == 2) {
        $artist = $parts[0];
    }

    // Try to extract artist from filename if it contains " - "
    if (empty($artist) && strpos($name, ' - ') !== false) {
        list($artist, $title) = explode(' - ', $name, 2);
    }

    // Clean up
    $artist = trim($artist);
    $title = trim($title);
    $album = trim($album);

    // Create searchable key (lowercase, remove special chars)
    $searchKey = strtolower($artist . ' ' . $title);
    $searchKey = preg_replace('/[^a-z0-9\s]/', '', $searchKey);
    $searchKey = preg_replace('/\s+/', ' ', $searchKey);

    $index[] = [
        'path' => $filePath,
        'artist' => $artist,
        'title' => $title,
        'album' => $album,
        'search_key' => $searchKey
    ];
}

// Save index
$data = [
    'generated' => date('Y-m-d H:i:s'),
    'count' => count($index),
    'files' => $index
];

file_put_contents($indexFile, json_encode($data, JSON_PRETTY_PRINT));

echo "Index saved to $indexFile with " . count($index) . " entries\n";
echo "Completed at " . date('Y-m-d H:i:s') . "\n";
