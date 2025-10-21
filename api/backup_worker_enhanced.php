<?php
/**
 * Enhanced background worker for backup operations with real progress tracking
 */

require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/BackupManager.php';
require_once __DIR__ . '/../core/BackupProgress.php';
require_once __DIR__ . '/../core/RemoteBackup.php';

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

// Create progress tracker
$progress = new BackupProgress($type);

// Log start
error_log("Starting enhanced backup: $type");

try {
    $backupId = date('Ymd_His');
    $backupFiles = [];
    $success = true;

    // Step 1: Initialization
    $progress->startStep('initialization');
    sleep(1); // Allow initialization to show

    if ($type === 'full' || $type === 'database') {
        // Step 2: Calculate database size
        $progress->startStep('db_size_calc');
        $dbSize = Config::getDatabaseSize();
        error_log("Database size: {$dbSize}MB");

        // Step 3: Export database
        $progress->startStep('db_export');
        $dbFile = performDatabaseBackup($backupId, $progress);
        if ($dbFile) {
            $backupFiles[] = $dbFile;
        } else {
            $success = false;
        }
    }

    if ($type === 'full' || $type === 'storage') {
        // Calculate storage
        $progress->startStep('storage_calc');
        $storagePath = Config::get('storage_path');

        if ($storagePath && is_dir($storagePath)) {
            $totalFiles = intval(shell_exec("find " . escapeshellarg($storagePath) . " -type f | wc -l"));
            error_log("Storage files count: $totalFiles");

            // Copy storage
            $progress->startStep('storage_copy');
            $storageFile = performStorageBackup($backupId, $progress, $totalFiles);
            if ($storageFile) {
                $backupFiles[] = $storageFile;
            }
        }
    }

    // Cleanup
    $progress->startStep('cleanup');
    cleanupOldBackups();

    // Finalize
    $progress->startStep('finalize');
    if ($success && !empty($backupFiles)) {
        saveBackupRecord($backupId, $type, $backupFiles);

        // Transfer to remote if configured
        transferToRemote($backupFiles, $progress);
    }

    // Complete
    $progress->complete($success);
    error_log("Backup completed: $type - Success: " . ($success ? 'yes' : 'no'));

} catch (Exception $e) {
    error_log("Backup error: " . $e->getMessage());
    $progress->complete(false, "Error: " . $e->getMessage());
}

/**
 * Perform database backup with progress tracking
 */
function performDatabaseBackup($backupId, $progress) {
    $dbHost = Config::get('db_host', 'localhost');
    $dbPort = Config::get('db_port', '3306');
    $dbName = Config::get('db_name');
    $dbUser = Config::get('db_user');
    $dbPass = Config::get('db_pass');

    $backupFile = Config::get('backup_path') . "/db_{$backupId}_full.sql";
    $compression = Config::get('compression', 'medium');

    // Build mysqldump command
    $cmd = sprintf(
        "mysqldump --single-transaction --quick --lock-tables=false --skip-add-locks " .
        "--routines --triggers --events --hex-blob --default-character-set=utf8mb4 " .
        "--host=%s --port=%s --user=%s %s %s 2>&1",
        escapeshellarg($dbHost),
        escapeshellarg($dbPort),
        escapeshellarg($dbUser),
        $dbPass ? '--password=' . escapeshellarg($dbPass) : '',
        escapeshellarg($dbName)
    );

    // Get database size for progress calculation
    $dbSize = Config::getDatabaseSize();
    $estimatedSize = $dbSize * 1024 * 1024; // MB to bytes

    // Use popen to read output in real-time
    $handle = popen($cmd, 'r');
    if (!$handle) {
        error_log("Failed to start mysqldump");
        return false;
    }

    $tempFile = $backupFile . '.tmp';
    $outHandle = fopen($tempFile, 'w');
    if (!$outHandle) {
        pclose($handle);
        return false;
    }

    $bytesWritten = 0;
    $lastProgress = 0;
    $buffer = '';

    while (!feof($handle)) {
        $chunk = fread($handle, 8192); // Read in 8KB chunks
        if ($chunk === false) break;

        fwrite($outHandle, $chunk);
        $bytesWritten += strlen($chunk);

        // Update progress based on bytes written
        if ($estimatedSize > 0) {
            $currentProgress = min(90, ($bytesWritten / $estimatedSize) * 100);
            if ($currentProgress - $lastProgress > 1) { // Update every 1%
                $progress->updateStep($currentProgress,
                    sprintf("Exportando BD: %s / ~%s",
                        formatBytes($bytesWritten),
                        formatBytes($estimatedSize)));
                $lastProgress = $currentProgress;
            }
        }
    }

    fclose($outHandle);
    $returnCode = pclose($handle);

    if ($returnCode !== 0) {
        error_log("mysqldump failed with code: $returnCode");
        unlink($tempFile);
        return false;
    }

    // Compress if needed
    if ($compression !== 'none') {
        $progress->startStep('db_compress');
        $compressedFile = $backupFile . '.gz';
        $compressionLevel = $compression === 'high' ? '9' : ($compression === 'low' ? '1' : '6');

        // Check for pigz (parallel gzip)
        $pigzAvailable = trim(shell_exec("which pigz 2>/dev/null"));

        if ($pigzAvailable) {
            $cmd = "pigz -$compressionLevel < " . escapeshellarg($tempFile) . " > " . escapeshellarg($compressedFile);
        } else {
            $cmd = "gzip -$compressionLevel < " . escapeshellarg($tempFile) . " > " . escapeshellarg($compressedFile);
        }

        exec($cmd, $output, $returnCode);

        if ($returnCode === 0) {
            unlink($tempFile);
            return $compressedFile;
        } else {
            error_log("Compression failed");
            rename($tempFile, $backupFile);
            return $backupFile;
        }
    } else {
        rename($tempFile, $backupFile);
        return $backupFile;
    }
}

/**
 * Perform storage backup with progress tracking
 */
function performStorageBackup($backupId, $progress, $totalFiles) {
    $storagePath = Config::get('storage_path');
    $backupPath = Config::get('backup_path') . "/storage_{$backupId}";

    // Create backup directory
    if (!mkdir($backupPath, 0755, true)) {
        error_log("Failed to create storage backup directory");
        return false;
    }

    // Use rsync for efficient copying
    $cmd = sprintf(
        "rsync -av --progress --stats %s/ %s/ 2>&1",
        escapeshellarg($storagePath),
        escapeshellarg($backupPath)
    );

    $handle = popen($cmd, 'r');
    if (!$handle) {
        error_log("Failed to start rsync");
        return false;
    }

    $filesProcessed = 0;
    while (!feof($handle)) {
        $line = fgets($handle);
        if ($line === false) break;

        // Parse rsync progress output
        if (strpos($line, 'to-check=') !== false) {
            preg_match('/to-check=(\d+)\/(\d+)/', $line, $matches);
            if (!empty($matches)) {
                $remaining = intval($matches[1]);
                $total = intval($matches[2]);
                $filesProcessed = $total - $remaining;

                $progressPercent = ($filesProcessed / $total) * 100;
                $progress->updateStep($progressPercent,
                    sprintf("Copiando archivos: %d / %d", $filesProcessed, $total));
            }
        }
    }

    $returnCode = pclose($handle);

    if ($returnCode !== 0) {
        error_log("rsync failed with code: $returnCode");
        return false;
    }

    // Compress storage
    $progress->startStep('storage_compress');
    $archiveFile = Config::get('backup_path') . "/storage_{$backupId}.tar.gz";

    $cmd = sprintf(
        "tar -czf %s -C %s . 2>&1",
        escapeshellarg($archiveFile),
        escapeshellarg($backupPath)
    );

    exec($cmd, $output, $returnCode);

    if ($returnCode === 0) {
        // Remove temporary directory
        exec("rm -rf " . escapeshellarg($backupPath));
        return $archiveFile;
    } else {
        error_log("Storage compression failed");
        return false;
    }
}

/**
 * Clean up old backups
 */
function cleanupOldBackups() {
    $retentionDays = Config::get('retention_days', 30);
    $backupPath = Config::get('backup_path');

    if (!$backupPath || !is_dir($backupPath)) {
        return;
    }

    $cutoffTime = time() - ($retentionDays * 86400);
    $deleted = 0;

    $files = glob($backupPath . '/*');
    foreach ($files as $file) {
        if (is_file($file) && filemtime($file) < $cutoffTime) {
            unlink($file);
            $deleted++;
        }
    }

    if ($deleted > 0) {
        error_log("Cleaned up $deleted old backup files");
    }
}

/**
 * Save backup record
 */
function saveBackupRecord($backupId, $type, $files) {
    $historyFile = Config::get('backup_path') . '/history.json';

    $history = [];
    if (file_exists($historyFile)) {
        $history = json_decode(file_get_contents($historyFile), true) ?: [];
    }

    $totalSize = 0;
    foreach ($files as $file) {
        if (file_exists($file)) {
            $totalSize += filesize($file);
        }
    }

    $record = [
        'id' => $backupId,
        'date' => date('Y-m-d H:i:s'),
        'type' => $type,
        'files' => $files,
        'size' => $totalSize,
        'size_formatted' => formatBytes($totalSize)
    ];

    array_unshift($history, $record);

    // Keep only last 100 records
    $history = array_slice($history, 0, 100);

    file_put_contents($historyFile, json_encode($history, JSON_PRETTY_PRINT));
}

/**
 * Transfer backups to remote server
 */
function transferToRemote($backupFiles, $progress) {
    try {
        $remote = new RemoteBackup($progress);

        // Check if remote backup is enabled
        $configFile = Config::get('backup_path') . '/remote_config.json';
        if (!file_exists($configFile)) {
            return;
        }

        $config = json_decode(file_get_contents($configFile), true);
        if (!$config || !$config['enabled']) {
            return;
        }

        error_log("Starting remote transfer for " . count($backupFiles) . " files");
        $progress->update($progress->calculatePercentage(), "Transferring to remote server...");

        foreach ($backupFiles as $file) {
            if (file_exists($file)) {
                $fileName = basename($file);
                error_log("Transferring $fileName to remote server");

                $result = $remote->transfer($file);

                if ($result) {
                    error_log("Successfully transferred $fileName");

                    // Delete local file if configured
                    if (isset($config['keep_local']) && !$config['keep_local']) {
                        unlink($file);
                        error_log("Deleted local file: $fileName");
                    }
                } else {
                    error_log("Failed to transfer $fileName");
                }
            }
        }

        error_log("Remote transfer completed");
    } catch (Exception $e) {
        error_log("Remote transfer error: " . $e->getMessage());
    }
}

/**
 * Format bytes to human readable
 */
function formatBytes($bytes, $precision = 2) {
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];

    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);

    $bytes /= pow(1024, $pow);

    return round($bytes, $precision) . ' ' . $units[$pow];
}
?>