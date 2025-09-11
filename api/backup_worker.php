<?php
/**
 * Background worker for backup operations
 * This script runs in the background to perform actual backup
 */

require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/BackupManager.php';

// Get backup type from command line argument
$type = $argv[1] ?? 'full';

// Set resource limits for large backups
ini_set('memory_limit', '512M');
ini_set('max_execution_time', 0);
set_time_limit(0);

// Run with low priority
if (function_exists('proc_nice')) {
    proc_nice(19);
}

// Create backup manager instance
$backupManager = new BackupManager();

// Start the backup
try {
    $result = $backupManager->startBackup($type);
    
    if ($result) {
        error_log("Backup completed successfully: $type");
    } else {
        error_log("Backup failed: $type");
    }
} catch (Exception $e) {
    error_log("Backup error: " . $e->getMessage());
}

// Clean up old backups
try {
    $deleted = $backupManager->cleanOldBackups();
    if ($deleted > 0) {
        error_log("Cleaned up $deleted old backups");
    }
} catch (Exception $e) {
    error_log("Cleanup error: " . $e->getMessage());
}