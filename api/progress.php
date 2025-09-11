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
$progress = $backupManager->getProgress();

// Get recent log lines
$logs = $backupManager->getLogs(10);
if (!empty($logs)) {
    $progress['log'] = implode("\n", array_slice($logs, -5));
}

header('Content-Type: application/json');
echo json_encode(['progress' => $progress]);