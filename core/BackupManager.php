<?php
/**
 * Core backup functionality
 * Handles database and storage backups with progress tracking
 */

class BackupManager {
    private $progressFile;
    private $logFile;
    private $startTime;
    
    public function __construct() {
        $this->progressFile = Config::get('temp_path') . '/backup_progress.json';
        $this->logFile = Config::get('log_path') . '/backup_' . date('Y-m-d') . '.log';
        
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
     * Backup database using mysqldump
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
        
        $backupFile = Config::get('backup_path') . "/db_{$backupId}.sql";
        $compression = Config::get('compression', 'medium');
        
        // Build mysqldump command for hot backup (no locks)
        $cmd = sprintf(
            "mysqldump --single-transaction --quick --lock-tables=false --skip-add-locks " .
            "--host=%s --port=%s --user=%s %s %s 2>&1",
            escapeshellarg($dbHost),
            escapeshellarg($dbPort),
            escapeshellarg($dbUser),
            $dbPass ? '--password=' . escapeshellarg($dbPass) : '',
            escapeshellarg($dbName)
        );
        
        // Add progress monitoring with pv if available
        $pvAvailable = shell_exec("which pv 2>/dev/null");
        if ($pvAvailable) {
            $dbSize = Config::getDatabaseSize();
            $estimatedSize = $dbSize * 1024 * 1024; // Convert MB to bytes
            $cmd .= " | pv -s $estimatedSize -n 2>" . Config::get('temp_path') . "/db_progress.txt";
        }
        
        // Apply compression if needed
        if ($compression !== 'none') {
            $backupFile .= '.gz';
            $compressionLevel = $compression === 'high' ? '9' : ($compression === 'low' ? '1' : '6');
            $cmd .= " | gzip -$compressionLevel";
        }
        
        $cmd .= " > " . escapeshellarg($backupFile);
        
        $this->log("Executing: mysqldump for database $dbName");
        $this->updateProgress(20, "Exportando base de datos...");
        
        // Execute backup with progress monitoring
        if ($pvAvailable) {
            $this->executeWithProgress($cmd, 20, 50, 'db_progress.txt');
        } else {
            exec($cmd, $output, $returnCode);
            if ($returnCode !== 0) {
                $this->log("Database backup failed: " . implode("\n", $output), 'ERROR');
                unlink($backupFile);
                return false;
            }
            $this->updateProgress(50, "Base de datos exportada");
        }
        
        $this->log("Database backup completed: $backupFile");
        return $backupFile;
    }
    
    /**
     * Backup storage directory using rsync
     */
    private function backupStorage($backupId) {
        $this->updateProgress(55, "Iniciando backup de storage...");
        
        $storagePath = Config::get('storage_path');
        if (!$storagePath || !is_dir($storagePath)) {
            $this->log("Storage path not found: $storagePath", 'ERROR');
            return false;
        }
        
        $backupFile = Config::get('backup_path') . "/storage_{$backupId}.tar";
        $compression = Config::get('compression', 'medium');
        
        // Use rsync for incremental-like behavior with tar
        $excludes = "--exclude='*/cache/*' --exclude='*/tmp/*' --exclude='*/temp/*'";
        
        if ($compression !== 'none') {
            $backupFile .= '.gz';
            $compressionFlag = $compression === 'high' ? 'z9' : ($compression === 'low' ? 'z1' : 'z6');
            $tarCmd = "czf";
        } else {
            $tarCmd = "cf";
        }
        
        // Create tar with progress
        $cmd = sprintf(
            "cd %s && tar %s %s %s --checkpoint=1000 --checkpoint-action=exec='echo %%u' . 2>&1 | " .
            "while read line; do echo \$line > %s/storage_progress.txt; done",
            escapeshellarg(dirname($storagePath)),
            $tarCmd,
            escapeshellarg($backupFile),
            $excludes,
            Config::get('temp_path')
        );
        
        $this->log("Creating storage backup: $backupFile");
        $this->updateProgress(60, "Comprimiendo archivos de storage...");
        
        // Execute with monitoring
        $this->executeStorageBackup($cmd, $storagePath, $backupFile);
        
        $this->updateProgress(95, "Storage respaldado");
        $this->log("Storage backup completed: $backupFile");
        
        return $backupFile;
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
     * Save backup record to history
     */
    private function saveBackupRecord($backupId, $type, $files) {
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
            'duration' => time() - $this->startTime,
            'status' => 'completed'
        ];
        
        array_unshift($history, $record);
        
        // Keep only last 100 records
        $history = array_slice($history, 0, 100);
        
        file_put_contents($historyFile, json_encode($history, JSON_PRETTY_PRINT));
    }
    
    /**
     * Get backup history
     */
    public function getHistory($limit = 50) {
        $historyFile = Config::get('backup_path') . '/history.json';
        
        if (!file_exists($historyFile)) {
            return [];
        }
        
        $history = json_decode(file_get_contents($historyFile), true) ?: [];
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