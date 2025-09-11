<?php
/**
 * Core backup functionality
 * Handles database and storage backups with progress tracking
 */

class BackupManager {
    private $progressFile;
    private $logFile;
    private $startTime;
    private $currentBinaryLogPosition;
    
    public function __construct() {
        $this->progressFile = Config::get('temp_path') . '/backup_progress.json';
        $this->logFile = Config::get('log_path') . '/backup_' . date('Y-m-d') . '.log';
        $this->currentBinaryLogPosition = null;
        
        // Ensure directories exist
        $this->ensureDirectories();
    }
    
    private function ensureDirectories() {
        $dirs = [
            Config::get('backup_path'),
            Config::get('temp_path'),
            Config::get('log_path')
        ];
        
        foreach ($dirs as $dir) {
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
        }
    }
    
    /**
     * Start a backup process
     */
    public function startBackup($type = 'full') {
        $this->startTime = time();
        $backupId = date('Ymd_His');
        
        $this->updateProgress(0, "Iniciando backup $type...");
        $this->log("Starting $type backup - ID: $backupId");
        
        $success = true;
        $backupFiles = [];
        
        try {
            if ($type === 'full' || $type === 'database') {
                $dbFile = $this->backupDatabase($backupId);
                if ($dbFile) {
                    $backupFiles[] = $dbFile;
                } else {
                    $success = false;
                }
            }
            
            if ($type === 'full' || $type === 'storage') {
                $storageFile = $this->backupStorage($backupId);
                if ($storageFile) {
                    $backupFiles[] = $storageFile;
                } else {
                    $success = false;
                }
            }
            
            if ($success) {
                $this->updateProgress(100, "Backup completado exitosamente");
                $this->saveBackupRecord($backupId, $type, $backupFiles);
            } else {
                $this->updateProgress(-1, "Backup completado con errores");
            }
            
        } catch (Exception $e) {
            $this->updateProgress(-1, "Error: " . $e->getMessage());
            $this->log("Error during backup: " . $e->getMessage(), 'ERROR');
            return false;
        }
        
        return $success;
    }
    
    /**
     * Backup database using mysqldump with optimizations for large databases
     */
    private function backupDatabase($backupId) {
        $this->updateProgress(10, "Iniciando backup de base de datos...");
        
        $dbHost = Config::get('db_host', 'localhost');
        $dbPort = Config::get('db_port', '3306');
        $dbName = Config::get('db_name');
        $dbUser = Config::get('db_user');
        $dbPass = Config::get('db_pass');
        
        if (!$dbName || !$dbUser) {
            $this->log("Database configuration missing", 'ERROR');
            return false;
        }
        
        // Check if this is an incremental backup
        $dbSize = Config::getDatabaseSize();
        $hasBaseBackup = $this->hasRecentDatabaseBackup();
        
        // Use incremental if we have a base backup and binary logs are enabled
        if ($hasBaseBackup && Config::isBinaryLoggingEnabled()) {
            $this->log("Database size: {$dbSize}MB - Using incremental backup strategy (binary logs available)");
            return $this->backupDatabaseIncremental($backupId);
        } else {
            $this->log("Database size: {$dbSize}MB - Using full backup strategy (first backup or no binary logs)");
            return $this->backupDatabaseFull($backupId);
        }
    }
    
    /**
     * Full database backup
     */
    private function backupDatabaseFull($backupId) {
        $this->updateProgress(15, "Backup completo de base de datos...");
        
        $dbHost = Config::get('db_host', 'localhost');
        $dbPort = Config::get('db_port', '3306');
        $dbName = Config::get('db_name');
        $dbUser = Config::get('db_user');
        $dbPass = Config::get('db_pass');
        
        $backupFile = Config::get('backup_path') . "/db_{$backupId}_full.sql";
        $compression = Config::get('compression', 'medium');
        
        // Optimized mysqldump command for large databases
        $cmd = sprintf(
            "mysqldump --single-transaction --quick --lock-tables=false --skip-add-locks " .
            "--routines --triggers --events --hex-blob --default-character-set=utf8mb4 " .
            "--host=%s --port=%s --user=%s %s %s",
            escapeshellarg($dbHost),
            escapeshellarg($dbPort),
            escapeshellarg($dbUser),
            $dbPass ? '--password=' . escapeshellarg($dbPass) : '',
            escapeshellarg($dbName)
        );
        
        // Add progress monitoring with pv if available
        $pvAvailable = trim(shell_exec("which pv 2>/dev/null"));
        if ($pvAvailable) {
            $dbSize = Config::getDatabaseSize();
            $estimatedSize = $dbSize * 1024 * 1024; // Convert MB to bytes
            $progressFile = Config::get('temp_path') . "/db_progress_{$backupId}.txt";
            $cmd .= " | pv -s $estimatedSize -n 2>$progressFile";
        }
        
        // Apply compression
        if ($compression !== 'none') {
            $backupFile .= '.gz';
            $compressionLevel = $compression === 'high' ? '9' : ($compression === 'low' ? '1' : '6');
            
            // Use pigz for parallel compression if available
            $pigzAvailable = trim(shell_exec("which pigz 2>/dev/null"));
            if ($pigzAvailable) {
                $cmd .= " | pigz -$compressionLevel";
            } else {
                $cmd .= " | gzip -$compressionLevel";
            }
        }
        
        $cmd .= " > " . escapeshellarg($backupFile) . " 2>&1";
        
        $this->log("Executing full database dump: $cmd");
        $this->updateProgress(20, "Exportando base de datos completa...");
        
        // Execute backup with progress monitoring
        if ($pvAvailable) {
            $this->executeWithProgress($cmd, 20, 50, "db_progress_{$backupId}.txt");
        } else {
            // Execute without progress monitoring
            $startTime = time();
            exec($cmd, $output, $returnCode);
            $duration = time() - $startTime;
            
            if ($returnCode !== 0) {
                $this->log("Database backup failed: " . implode("\n", $output), 'ERROR');
                if (file_exists($backupFile)) {
                    unlink($backupFile);
                }
                return false;
            }
            $this->updateProgress(50, "Base de datos exportada en {$duration}s");
        }
        
        $this->log("Full database backup completed: $backupFile");
        
        // Get current binary log position for future incremental backups (will be saved in saveBackupRecord)
        if (Config::isBinaryLoggingEnabled()) {
            $this->currentBinaryLogPosition = Config::getBinaryLogPosition();
            if ($this->currentBinaryLogPosition) {
                $this->log("Current binary log position: {$this->currentBinaryLogPosition['file']}:{$this->currentBinaryLogPosition['position']}");
            }
        }
        
        return $backupFile;
    }
    
    /**
     * Incremental database backup using binary logs
     */
    private function backupDatabaseIncremental($backupId) {
        $this->updateProgress(15, "Backup incremental de base de datos...");
        
        // Check if binary logging is enabled
        if (!Config::isBinaryLoggingEnabled()) {
            $this->log("Binary logging not enabled, falling back to full backup", 'WARNING');
            return $this->backupDatabaseFull($backupId);
        }
        
        // Get last backup position
        $lastPosition = $this->getLastBinaryLogPosition();
        if (!$lastPosition) {
            $this->log("No previous backup position found, creating base backup", 'INFO');
            return $this->backupDatabaseFull($backupId);
        }
        
        $this->updateProgress(20, "Extrayendo binary logs desde última posición...");
        
        // Create incremental backup using mysqlbinlog
        $backupFile = Config::get('backup_path') . "/db_{$backupId}_incremental.sql";
        $compression = Config::get('compression', 'medium');
        
        $dbHost = Config::get('db_host', 'localhost');
        $dbPort = Config::get('db_port', '3306');
        $dbUser = Config::get('db_user');
        $dbPass = Config::get('db_pass');
        $dbName = Config::get('db_name');
        
        // Build mysqlbinlog command for incremental backup
        $cmd = sprintf(
            "mysqlbinlog --read-from-remote-server --host=%s --port=%s --user=%s %s " .
            "--start-position=%s --database=%s %s",
            escapeshellarg($dbHost),
            escapeshellarg($dbPort),
            escapeshellarg($dbUser),
            $dbPass ? '--password=' . escapeshellarg($dbPass) : '',
            escapeshellarg($lastPosition['position']),
            escapeshellarg($dbName),
            escapeshellarg($lastPosition['file'])
        );
        
        // Get current position to save for next incremental
        $currentPosition = Config::getBinaryLogPosition();
        if (!$currentPosition) {
            $this->log("Could not get current binary log position", 'ERROR');
            return $this->backupDatabaseFull($backupId);
        }
        
        // If no new changes since last backup
        if ($currentPosition['position'] <= $lastPosition['position'] && 
            $currentPosition['file'] === $lastPosition['file']) {
            $this->log("No changes detected since last backup", 'INFO');
            $this->updateProgress(100, "No hay cambios en la base de datos");
            
            // Create empty incremental backup file
            file_put_contents($backupFile, "-- No changes since last backup at " . date('Y-m-d H:i:s') . "\n");
            
            // Save position anyway
            $this->saveBinaryLogPosition($backupId, $currentPosition);
            return $backupFile;
        }
        
        $this->updateProgress(30, "Procesando cambios incrementales...");
        
        // Include all binary logs between last position and current
        $binaryLogs = Config::getBinaryLogs();
        $logsToProcess = $this->getLogsBetweenPositions($binaryLogs, $lastPosition, $currentPosition);
        
        if (empty($logsToProcess)) {
            $this->log("No binary logs found between positions", 'WARNING');
            return $this->backupDatabaseFull($backupId);
        }
        
        // Process each binary log
        $allChanges = [];
        $progress = 30;
        $progressStep = 40 / count($logsToProcess);
        
        foreach ($logsToProcess as $logFile) {
            $this->updateProgress($progress, "Procesando {$logFile}...");
            
            $startPos = ($logFile === $lastPosition['file']) ? $lastPosition['position'] : 4; // 4 is the minimum position
            $endPos = ($logFile === $currentPosition['file']) ? $currentPosition['position'] : 0;
            
            $logCmd = sprintf(
                "mysqlbinlog --read-from-remote-server --host=%s --port=%s --user=%s %s " .
                "--start-position=%s %s --database=%s %s",
                escapeshellarg($dbHost),
                escapeshellarg($dbPort),
                escapeshellarg($dbUser),
                $dbPass ? '--password=' . escapeshellarg($dbPass) : '',
                escapeshellarg($startPos),
                $endPos > 0 ? '--stop-position=' . escapeshellarg($endPos) : '',
                escapeshellarg($dbName),
                escapeshellarg($logFile)
            );
            
            $output = shell_exec($logCmd . ' 2>&1');
            if ($output) {
                $allChanges[] = $output;
            }
            
            $progress += $progressStep;
        }
        
        $this->updateProgress(75, "Generando archivo de backup incremental...");
        
        // Combine all changes into a single file
        $incrementalSql = "-- Incremental backup from " . date('Y-m-d H:i:s', $lastPosition['timestamp']) . 
                         " to " . date('Y-m-d H:i:s') . "\n";
        $incrementalSql .= "-- From position {$lastPosition['file']}:{$lastPosition['position']} ";
        $incrementalSql .= "to {$currentPosition['file']}:{$currentPosition['position']}\n\n";
        $incrementalSql .= implode("\n", $allChanges);
        
        // Apply compression if needed
        if ($compression !== 'none') {
            $backupFile .= '.gz';
            $compressionLevel = $compression === 'high' ? '9' : ($compression === 'low' ? '1' : '6');
            
            // Use pigz for parallel compression if available
            $pigzAvailable = trim(shell_exec("which pigz 2>/dev/null"));
            if ($pigzAvailable) {
                $compressed = shell_exec("echo " . escapeshellarg($incrementalSql) . " | pigz -$compressionLevel");
            } else {
                $compressed = gzcompress($incrementalSql, $compressionLevel);
            }
            file_put_contents($backupFile, $compressed);
        } else {
            file_put_contents($backupFile, $incrementalSql);
        }
        
        $this->updateProgress(90, "Guardando posición actual...");
        
        // Save current position for next incremental backup
        $this->saveBinaryLogPosition($backupId, $currentPosition);
        
        $this->updateProgress(95, "Backup incremental de BD completado");
        $this->log("Incremental database backup completed: $backupFile");
        
        // Clean up old binary logs (optional)
        $this->cleanupOldBinaryLogs();
        
        return $backupFile;
    }
    
    /**
     * Check if there's a recent database backup (within 24 hours)
     */
    private function hasRecentDatabaseBackup() {
        $historyFile = Config::get('backup_path') . '/history.json';
        
        if (!file_exists($historyFile)) {
            return false;
        }
        
        $history = json_decode(file_get_contents($historyFile), true);
        if (empty($history)) {
            return false;
        }
        
        $yesterday = time() - 86400; // 24 hours ago
        
        foreach ($history as $backup) {
            $backupTime = strtotime($backup['date']);
            if ($backupTime > $yesterday && 
                ($backup['type'] === 'full' || $backup['type'] === 'database')) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Backup storage directory using incremental rsync strategy
     */
    private function backupStorage($backupId) {
        $this->updateProgress(55, "Iniciando backup de storage...");
        
        $storagePath = Config::get('storage_path');
        if (!$storagePath || !is_dir($storagePath)) {
            $this->log("Storage path not found: $storagePath", 'ERROR');
            return false;
        }
        
        $backupPath = Config::get('backup_path');
        $incrementalDir = $backupPath . '/incremental';
        $currentBackup = $incrementalDir . "/storage_{$backupId}";
        
        // Create incremental directory structure
        if (!is_dir($incrementalDir)) {
            mkdir($incrementalDir, 0755, true);
        }
        
        // Find the last backup for incremental linking
        $lastBackup = $this->getLastStorageBackup();
        
        $this->updateProgress(60, "Sincronizando archivos (modo incremental)...");
        
        // Build rsync command for incremental backup
        $excludes = "--exclude='*/cache/*' --exclude='*/tmp/*' --exclude='*/temp/*' --exclude='*/sessions/*'";
        $rsyncOptions = "-avh --delete --stats --human-readable";
        
        if ($lastBackup) {
            // Incremental backup using hard links
            $rsyncOptions .= " --link-dest=" . escapeshellarg($lastBackup);
            $this->log("Creating incremental backup based on: $lastBackup");
            $this->updateProgress(65, "Backup incremental - solo archivos nuevos/modificados...");
        } else {
            $this->log("Creating first full backup");
            $this->updateProgress(65, "Primer backup completo...");
        }
        
        $cmd = sprintf(
            "rsync %s %s %s/ %s/ 2>&1",
            $rsyncOptions,
            $excludes,
            escapeshellarg($storagePath),
            escapeshellarg($currentBackup)
        );
        
        $this->log("Executing rsync: $cmd");
        
        // Execute rsync with progress monitoring
        $output = [];
        $startTime = time();
        exec($cmd, $output, $returnCode);
        $duration = time() - $startTime;
        
        if ($returnCode !== 0) {
            $this->log("Rsync failed with code $returnCode: " . implode("\n", $output), 'ERROR');
            return false;
        }
        
        // Parse rsync stats for better logging
        $stats = $this->parseRsyncStats($output);
        $this->log("Rsync completed - Files transferred: {$stats['transferred']}, Total size: {$stats['total_size']}, Duration: {$duration}s");
        
        $this->updateProgress(90, "Creando archivo comprimido...");
        
        // Create compressed archive of the incremental backup
        $compression = Config::get('compression', 'medium');
        $archiveFile = $backupPath . "/storage_{$backupId}";
        
        if ($compression !== 'none') {
            $archiveFile .= '.tar.gz';
            $compressionLevel = $compression === 'high' ? '9' : ($compression === 'low' ? '1' : '6');
            $tarCmd = "tar -czf " . escapeshellarg($archiveFile) . " -C " . escapeshellarg($incrementalDir) . " storage_{$backupId}";
        } else {
            $archiveFile .= '.tar';
            $tarCmd = "tar -cf " . escapeshellarg($archiveFile) . " -C " . escapeshellarg($incrementalDir) . " storage_{$backupId}";
        }
        
        exec($tarCmd, $tarOutput, $tarReturn);
        
        if ($tarReturn !== 0) {
            $this->log("Archive creation failed", 'ERROR');
            return false;
        }
        
        $this->updateProgress(95, "Storage respaldado incrementalmente");
        $this->log("Storage backup completed: $archiveFile (incremental)");
        
        return $archiveFile;
    }
    
    /**
     * Get the last storage backup directory for incremental linking
     */
    private function getLastStorageBackup() {
        $incrementalDir = Config::get('backup_path') . '/incremental';
        
        if (!is_dir($incrementalDir)) {
            return null;
        }
        
        $backups = glob($incrementalDir . '/storage_*', GLOB_ONLYDIR);
        
        if (empty($backups)) {
            return null;
        }
        
        // Sort by modification time (newest first)
        usort($backups, function($a, $b) {
            return filemtime($b) - filemtime($a);
        });
        
        return $backups[0];
    }
    
    /**
     * Parse rsync statistics from output
     */
    private function parseRsyncStats($output) {
        $stats = ['transferred' => 0, 'total_size' => '0'];
        
        foreach ($output as $line) {
            if (preg_match('/Number of files transferred: (\d+)/', $line, $matches)) {
                $stats['transferred'] = $matches[1];
            }
            if (preg_match('/Total file size: ([\d,]+)/', $line, $matches)) {
                $stats['total_size'] = $matches[1];
            }
        }
        
        return $stats;
    }
    
    /**
     * Execute storage backup with progress monitoring
     */
    private function executeStorageBackup($cmd, $sourcePath, $backupFile) {
        // Get total files count for progress calculation
        $totalFiles = intval(shell_exec("find " . escapeshellarg($sourcePath) . " -type f | wc -l"));
        
        // Start backup process in background
        $descriptors = [
            0 => ["pipe", "r"],
            1 => ["pipe", "w"],
            2 => ["pipe", "w"]
        ];
        
        $process = proc_open($cmd, $descriptors, $pipes);
        
        if (is_resource($process)) {
            // Close input pipe
            fclose($pipes[0]);
            
            $processedFiles = 0;
            while (!feof($pipes[1])) {
                $line = fgets($pipes[1]);
                if ($line) {
                    $processedFiles++;
                    $progress = min(90, 60 + ($processedFiles / $totalFiles * 30));
                    $this->updateProgress($progress, "Procesando archivo $processedFiles de ~$totalFiles");
                }
            }
            
            fclose($pipes[1]);
            fclose($pipes[2]);
            
            $returnCode = proc_close($process);
            
            if ($returnCode !== 0) {
                $this->log("Storage backup failed", 'ERROR');
                if (file_exists($backupFile)) {
                    unlink($backupFile);
                }
                return false;
            }
        }
        
        return true;
    }
    
    /**
     * Execute command with progress monitoring
     */
    private function executeWithProgress($cmd, $startProgress, $endProgress, $progressFile) {
        $fullProgressFile = Config::get('temp_path') . '/' . $progressFile;
        
        // Start command in background
        exec($cmd . " &");
        
        // Monitor progress
        $lastProgress = 0;
        while (true) {
            if (file_exists($fullProgressFile)) {
                $progress = intval(file_get_contents($fullProgressFile));
                if ($progress !== $lastProgress) {
                    $actualProgress = $startProgress + (($endProgress - $startProgress) * $progress / 100);
                    $this->updateProgress($actualProgress, "Progreso: $progress%");
                    $lastProgress = $progress;
                }
            }
            
            // Check if process completed
            exec("pgrep mysqldump", $output);
            if (empty($output)) {
                break;
            }
            
            sleep(1);
        }
        
        // Clean up progress file
        if (file_exists($fullProgressFile)) {
            unlink($fullProgressFile);
        }
    }
    
    /**
     * Update progress information
     */
    private function updateProgress($percentage, $message) {
        $progress = [
            'percentage' => $percentage,
            'message' => $message,
            'timestamp' => time(),
            'elapsed' => time() - $this->startTime
        ];
        
        file_put_contents($this->progressFile, json_encode($progress));
        $this->log("Progress: $percentage% - $message");
    }
    
    /**
     * Get current progress
     */
    public function getProgress() {
        if (file_exists($this->progressFile)) {
            return json_decode(file_get_contents($this->progressFile), true);
        }
        return null;
    }
    
    /**
     * Save backup record to history with detailed information
     */
    private function saveBackupRecord($backupId, $type, $files) {
        $historyFile = Config::get('backup_path') . '/history.json';
        
        $history = [];
        if (file_exists($historyFile)) {
            $history = json_decode(file_get_contents($historyFile), true) ?: [];
        }
        
        $totalSize = 0;
        $backupDetails = [];
        
        // Analyze each backup file for detailed info
        foreach ($files as $file) {
            if (file_exists($file)) {
                $fileSize = filesize($file);
                $totalSize += $fileSize;
                
                $filename = basename($file);
                $backupType = 'unknown';
                $isIncremental = false;
                
                // Determine backup type and if it's incremental
                if (strpos($filename, 'db_') === 0) {
                    $backupType = 'database';
                    $isIncremental = strpos($filename, '_full') === false;
                } elseif (strpos($filename, 'storage_') === 0) {
                    $backupType = 'storage';
                    $isIncremental = $this->isStorageIncremental($backupId);
                }
                
                $backupDetails[] = [
                    'type' => $backupType,
                    'file' => $file,
                    'size' => $fileSize,
                    'incremental' => $isIncremental,
                    'compressed' => $this->isCompressed($file)
                ];
            }
        }
        
        // Determine overall backup strategy
        $strategy = $this->determineBackupStrategy($backupDetails);
        $incrementalInfo = $this->getIncrementalInfo($backupDetails);
        
        $record = [
            'id' => $backupId,
            'date' => date('Y-m-d H:i:s'),
            'type' => $type,
            'strategy' => $strategy, // 'full', 'incremental', 'mixed'
            'files' => $files,
            'details' => $backupDetails,
            'size' => $totalSize,
            'size_formatted' => $this->formatBytes($totalSize),
            'duration' => time() - $this->startTime,
            'incremental_info' => $incrementalInfo,
            'status' => 'completed',
            'server' => Config::get('server_name', gethostname()),
            'binary_log_position' => $this->currentBinaryLogPosition
        ];
        
        array_unshift($history, $record);
        
        // Keep only last 100 records
        $history = array_slice($history, 0, 100);
        
        file_put_contents($historyFile, json_encode($history, JSON_PRETTY_PRINT));
        
        $this->log("Backup record saved: {$record['strategy']} backup, {$record['size_formatted']}, {$record['duration']}s");
    }
    
    /**
     * Check if a storage backup is incremental
     */
    private function isStorageIncremental($backupId) {
        $incrementalDir = Config::get('backup_path') . '/incremental';
        return is_dir($incrementalDir . "/storage_{$backupId}");
    }
    
    /**
     * Check if a file is compressed
     */
    private function isCompressed($file) {
        $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        return in_array($extension, ['gz', 'bz2', 'xz', 'zip']);
    }
    
    /**
     * Determine overall backup strategy
     */
    private function determineBackupStrategy($details) {
        $hasIncremental = false;
        $hasFull = false;
        
        foreach ($details as $detail) {
            if ($detail['incremental']) {
                $hasIncremental = true;
            } else {
                $hasFull = true;
            }
        }
        
        if ($hasIncremental && $hasFull) {
            return 'mixed';
        } elseif ($hasIncremental) {
            return 'incremental';
        } else {
            return 'full';
        }
    }
    
    /**
     * Get incremental backup information
     */
    private function getIncrementalInfo($details) {
        $info = [
            'total_files' => 0,
            'transferred_files' => 0,
            'space_saved' => 0
        ];
        
        foreach ($details as $detail) {
            if ($detail['incremental'] && $detail['type'] === 'storage') {
                // This would be populated from rsync stats
                // For now, estimate based on typical incremental savings
                $info['space_saved'] = $detail['size'] * 0.7; // Estimate 70% space savings
            }
        }
        
        return $info;
    }
    
    /**
     * Format bytes to human readable
     */
    private function formatBytes($bytes, $precision = 2) {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        
        for ($i = 0; $bytes > 1024; $i++) {
            $bytes /= 1024;
        }
        
        return round($bytes, $precision) . ' ' . $units[$i];
    }
    
    /**
     * Get the last binary log position from backup history
     */
    private function getLastBinaryLogPosition() {
        $historyFile = Config::get('backup_path') . '/history.json';
        
        if (!file_exists($historyFile)) {
            return null;
        }
        
        $history = json_decode(file_get_contents($historyFile), true);
        if (empty($history)) {
            return null;
        }
        
        // Look for the most recent database backup with binary log position
        foreach ($history as $backup) {
            if (($backup['type'] === 'full' || $backup['type'] === 'database') && 
                isset($backup['binary_log_position']) && 
                $backup['binary_log_position'] !== null) {
                return $backup['binary_log_position'];
            }
        }
        
        return null;
    }
    
    /**
     * Save binary log position for future incremental backups
     */
    private function saveBinaryLogPosition($backupId, $position) {
        // Store the position to be saved later in saveBackupRecord
        $this->currentBinaryLogPosition = $position;
        $this->log("Saved binary log position for incremental backups: {$position['file']}:{$position['position']}");
    }
    
    /**
     * Get binary logs between two positions
     */
    private function getLogsBetweenPositions($allLogs, $startPosition, $endPosition) {
        $logsToProcess = [];
        $startFound = false;
        
        foreach ($allLogs as $log) {
            $logFile = $log['Log_name'];
            
            // If we haven't found the start log yet
            if (!$startFound) {
                if ($logFile === $startPosition['file']) {
                    $startFound = true;
                    $logsToProcess[] = $logFile;
                }
                continue;
            }
            
            // Add logs until we reach the end log
            if ($logFile === $endPosition['file']) {
                $logsToProcess[] = $logFile;
                break;
            }
            
            $logsToProcess[] = $logFile;
        }
        
        return $logsToProcess;
    }
    
    /**
     * Clean up old binary logs to save space
     */
    private function cleanupOldBinaryLogs() {
        $retentionDays = Config::get('retention_days', 30);
        $cutoffTime = time() - ($retentionDays * 86400);
        
        $binaryLogs = Config::getBinaryLogs();
        
        foreach ($binaryLogs as $log) {
            $logFile = $log['Log_name'];
            $logSize = $log['File_size'];
            
            // Don't purge logs that are too recent or currently in use
            if (isset($log['Modified']) && strtotime($log['Modified']) > $cutoffTime) {
                continue;
            }
            
            // Purge old binary logs (be very careful with this)
            // Only purge if we have a recent full backup
            $recentFullBackup = $this->hasRecentDatabaseBackup();
            if ($recentFullBackup) {
                $this->log("Considering purge of old binary log: $logFile (size: $logSize bytes)");
                // Actual purging would be done here with Config::purgeBinaryLogs()
                // For safety, we'll log it but not auto-purge unless explicitly configured
            }
        }
    }
    
    /**
     * Get backup history ordered by date (newest first)
     */
    public function getHistory($limit = 50) {
        $historyFile = Config::get('backup_path') . '/history.json';
        
        if (!file_exists($historyFile)) {
            return [];
        }
        
        $history = json_decode(file_get_contents($historyFile), true) ?: [];
        
        // Sort by date in descending order (newest first)
        usort($history, function($a, $b) {
            $dateA = isset($a['date']) ? strtotime($a['date']) : 0;
            $dateB = isset($b['date']) ? strtotime($b['date']) : 0;
            return $dateB - $dateA; // Descending order
        });
        
        return array_slice($history, 0, $limit);
    }
    
    /**
     * Delete old backups based on retention policy
     */
    public function cleanOldBackups() {
        $retentionDays = Config::get('retention_days', 30);
        $cutoffTime = time() - ($retentionDays * 86400);
        
        $backupPath = Config::get('backup_path');
        $files = glob($backupPath . '/*.{sql,tar,gz}', GLOB_BRACE);
        
        $deletedCount = 0;
        foreach ($files as $file) {
            if (filemtime($file) < $cutoffTime) {
                unlink($file);
                $deletedCount++;
                $this->log("Deleted old backup: " . basename($file));
            }
        }
        
        return $deletedCount;
    }
    
    /**
     * Restore a backup
     */
    public function restoreBackup($backupId, $type = 'full') {
        $this->log("Starting restore for backup $backupId ($type)");
        
        // This is a dangerous operation - implement with caution
        // For now, just return the files that would be restored
        
        $history = $this->getHistory();
        foreach ($history as $record) {
            if ($record['id'] === $backupId) {
                return [
                    'status' => 'ready',
                    'files' => $record['files'],
                    'warning' => 'Restore functionality must be manually confirmed for safety'
                ];
            }
        }
        
        return ['status' => 'error', 'message' => 'Backup not found'];
    }
    
    /**
     * Write to log file
     */
    private function log($message, $level = 'INFO') {
        $logEntry = sprintf(
            "[%s] [%s] %s\n",
            date('Y-m-d H:i:s'),
            $level,
            $message
        );
        
        file_put_contents($this->logFile, $logEntry, FILE_APPEND);
    }
    
    /**
     * Get recent logs
     */
    public function getLogs($lines = 100) {
        if (!file_exists($this->logFile)) {
            return [];
        }
        
        $logs = file($this->logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        return array_slice($logs, -$lines);
    }
}