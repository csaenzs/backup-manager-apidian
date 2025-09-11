<?php
session_start();
require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/BackupManager.php';

// Check authentication
if (!Auth::isAuthenticated()) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$backupId = $input['id'] ?? '';

if (empty($backupId)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid backup ID']);
    exit;
}

// Get backup info from history
$historyFile = Config::get('backup_path') . '/history.json';
$history = [];

if (file_exists($historyFile)) {
    $history = json_decode(file_get_contents($historyFile), true) ?: [];
}

$found = false;
$newHistory = [];

foreach ($history as $backup) {
    if ($backup['id'] === $backupId) {
        $found = true;
        // Delete the actual files
        foreach ($backup['files'] as $file) {
            if (file_exists($file)) {
                unlink($file);
            }
        }
    } else {
        $newHistory[] = $backup;
    }
}

if (!$found) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Backup not found']);
    exit;
}

// Save updated history
file_put_contents($historyFile, json_encode($newHistory, JSON_PRETTY_PRINT));

header('Content-Type: application/json');
echo json_encode(['success' => true, 'message' => 'Backup deleted successfully']);