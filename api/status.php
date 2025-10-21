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

try {
    // Get system status
    $status = [
        'storage_size' => Config::getStorageSize() * 1024 * 1024, // Convert MB to bytes
        'db_size' => Config::getDatabaseSize() * 1024 * 1024, // Convert MB to bytes
        'last_backup' => null,
        'next_backup' => null,
        'disk_space' => getDiskSpace(),
        'backup_count' => getBackupCount(),
        'total_backup_size' => getTotalBackupSize()
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
    $scheduleFile = Config::get('backup_path') . '/schedule.json';
    if (file_exists($scheduleFile)) {
        $scheduleData = json_decode(file_get_contents($scheduleFile), true);
        if ($scheduleData && isset($scheduleData['enabled']) && $scheduleData['enabled']) {
            // Calculate next run time
            $hour = explode(':', $scheduleData['time'])[0] ?? 3;
            $minute = explode(':', $scheduleData['time'])[1] ?? 0;

            $next = new DateTime();
            $next->setTime($hour, $minute, 0);

            if ($next <= new DateTime()) {
                $next->modify('+1 day');
            }

            $status['next_backup'] = $next->format('Y-m-d H:i:s');
        }
    }

    // Add success flag
    $status['success'] = true;

} catch (Exception $e) {
    error_log("Status API error: " . $e->getMessage());
    $status = [
        'success' => false,
        'error' => 'Error loading status',
        'storage_size' => 0,
        'db_size' => 0,
        'disk_space' => getDiskSpace(),
        'last_backup' => null
    ];
}

header('Content-Type: application/json');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');
echo json_encode($status);

/**
 * Get disk space information
 */
function getDiskSpace() {
    $path = '/';

    try {
        $total = disk_total_space($path);
        $free = disk_free_space($path);
        $used = $total - $free;

        return [
            'total' => $total,
            'free' => $free,
            'used' => $used,
            'percent' => round(($used / $total) * 100, 1)
        ];
    } catch (Exception $e) {
        return [
            'total' => 0,
            'free' => 0,
            'used' => 0,
            'percent' => 0
        ];
    }
}

/**
 * Get total number of backups
 */
function getBackupCount() {
    $backupPath = Config::get('backup_path');
    if (!$backupPath || !is_dir($backupPath)) {
        return 0;
    }

    $files = glob($backupPath . '/*.{sql,gz,tar,zip}', GLOB_BRACE);
    return count($files);
}

/**
 * Get total size of all backups
 */
function getTotalBackupSize() {
    $backupPath = Config::get('backup_path');
    if (!$backupPath || !is_dir($backupPath)) {
        return 0;
    }

    $totalSize = 0;
    $files = glob($backupPath . '/*.{sql,gz,tar,zip}', GLOB_BRACE);

    foreach ($files as $file) {
        if (file_exists($file)) {
            $totalSize += filesize($file);
        }
    }

    return $totalSize;
}
?>