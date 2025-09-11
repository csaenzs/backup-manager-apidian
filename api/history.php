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

$backupManager = new BackupManager();

// Check if requesting specific backup details
if (isset($_GET['id'])) {
    $backupId = $_GET['id'];
    $history = $backupManager->getHistory();
    
    $backup = null;
    foreach ($history as $item) {
        if ($item['id'] === $backupId) {
            $backup = $item;
            break;
        }
    }
    
    if ($backup) {
        echo json_encode(['backup' => $backup]);
    } else {
        http_response_code(404);
        echo json_encode(['error' => 'Backup not found']);
    }
} else {
    // Return full history
    $history = $backupManager->getHistory();
    echo json_encode(['history' => $history]);
}

header('Content-Type: application/json');