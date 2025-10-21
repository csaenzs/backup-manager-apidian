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

// Handle POST request to start backup
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $type = $input['type'] ?? 'full';
    
    // Validate type
    if (!in_array($type, ['full', 'database', 'storage'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid backup type']);
        exit;
    }
    
    // Check if backup is already running
    $progressFile = Config::get('temp_path') . '/backup_progress.json';
    if (file_exists($progressFile)) {
        $progress = json_decode(file_get_contents($progressFile), true);
        if ($progress && $progress['percentage'] >= 0 && $progress['percentage'] < 100) {
            // Check if it's not a stale progress file (older than 1 hour)
            if (time() - $progress['timestamp'] < 3600) {
                http_response_code(409);
                echo json_encode(['success' => false, 'message' => 'Backup already in progress']);
                exit;
            }
        }
    }
    
    // Start backup in background
    $phpPath = PHP_BINARY ?: '/usr/bin/php';
    $scriptPath = __DIR__ . '/backup_worker_enhanced.php';

    // Use nohup to run in background
    $cmd = sprintf(
        "nohup %s %s %s > /dev/null 2>&1 &",
        escapeshellarg($phpPath),
        escapeshellarg($scriptPath),
        escapeshellarg($type)
    );
    
    exec($cmd);
    
    // Give it a moment to start
    sleep(1);
    
    echo json_encode(['success' => true, 'message' => 'Backup started']);
} else {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
}