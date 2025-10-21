<?php
session_start();
require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/BackupProgress.php';

// Check authentication
if (!Auth::isAuthenticated()) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

// Get current progress from the new system
$progress = BackupProgress::getCurrent();

// If no progress file, check if backup is not running
if (!$progress) {
    $progress = [
        'percentage' => -1,
        'message' => 'No hay backup en progreso',
        'timestamp' => time()
    ];
}

// Add log information if available
$logFile = Config::get('log_path') . '/backup_' . date('Y-m-d') . '.log';
if (file_exists($logFile)) {
    $logs = array_slice(file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES), -5);
    if (!empty($logs)) {
        $progress['log'] = implode("\n", $logs);
    }
}

header('Content-Type: application/json');
echo json_encode(['progress' => $progress]);