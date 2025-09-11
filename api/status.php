<?php
session_start();
require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/auth.php';

// Check authentication
if (!Auth::isAuthenticated()) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

// Get system status
$status = [
    'storage_size' => Config::getStorageSize() * 1024 * 1024, // Convert MB to bytes
    'db_size' => Config::getDatabaseSize() * 1024 * 1024, // Convert MB to bytes
    'last_backup' => null,
    'next_backup' => null
];

// Get last backup from history
$historyFile = Config::get('backup_path') . '/history.json';
if (file_exists($historyFile)) {
    $history = json_decode(file_get_contents($historyFile), true);
    if (!empty($history)) {
        $status['last_backup'] = $history[0];
    }
}

// Check for scheduled backup
$cronFile = '/tmp/backup_manager_cron.txt';
if (file_exists($cronFile)) {
    $cronData = json_decode(file_get_contents($cronFile), true);
    if ($cronData && $cronData['enabled']) {
        // Calculate next run time
        $hour = explode(':', $cronData['time'])[0];
        $minute = explode(':', $cronData['time'])[1];
        
        $next = new DateTime();
        $next->setTime($hour, $minute, 0);
        
        if ($next <= new DateTime()) {
            $next->modify('+1 day');
        }
        
        $status['next_backup'] = $next->format('Y-m-d H:i:s');
    }
}

header('Content-Type: application/json');
echo json_encode($status);