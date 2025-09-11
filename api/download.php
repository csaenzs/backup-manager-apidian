<?php
session_start();
require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/BackupManager.php';

// Check authentication
if (!Auth::isAuthenticated()) {
    http_response_code(401);
    die('Unauthorized');
}

$backupId = $_GET['id'] ?? '';

if (empty($backupId)) {
    http_response_code(400);
    die('Invalid backup ID');
}

// Log download attempt
error_log("Download attempt for backup ID: $backupId");

// Get backup info from history
$backupManager = new BackupManager();
$history = $backupManager->getHistory();

$backupInfo = null;
foreach ($history as $backup) {
    if ($backup['id'] === $backupId) {
        $backupInfo = $backup;
        break;
    }
}

if (!$backupInfo) {
    http_response_code(404);
    error_log("Backup not found in history: $backupId");
    die('Backup not found');
}

error_log("Backup found: " . json_encode($backupInfo));

// Check if all files exist
$missingFiles = [];
foreach ($backupInfo['files'] as $file) {
    if (!file_exists($file)) {
        $missingFiles[] = $file;
    }
}

if (!empty($missingFiles)) {
    http_response_code(404);
    error_log("Missing backup files for $backupId: " . implode(', ', $missingFiles));
    die('Backup files not found: ' . implode(', ', array_map('basename', $missingFiles)));
}

// For multiple files, create a tar archive
if (count($backupInfo['files']) > 1) {
    $tempFile = tempnam(Config::get('temp_path'), 'download_');
    $tarFile = $tempFile . '.tar';
    
    $files = array_map('escapeshellarg', $backupInfo['files']);
    $cmd = "tar -cf " . escapeshellarg($tarFile) . " " . implode(' ', $files);
    exec($cmd);
    
    header('Content-Type: application/x-tar');
    header('Content-Disposition: attachment; filename="backup_' . $backupId . '.tar"');
    header('Content-Length: ' . filesize($tarFile));
    
    readfile($tarFile);
    unlink($tarFile);
    unlink($tempFile);
} else {
    // Single file download
    $file = $backupInfo['files'][0];
    
    if (!file_exists($file)) {
        http_response_code(404);
        die('File not found');
    }
    
    $filename = basename($file);
    $mimeType = mime_content_type($file);
    
    header('Content-Type: ' . $mimeType);
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . filesize($file));
    
    readfile($file);
}