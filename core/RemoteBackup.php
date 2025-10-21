<?php
/**
 * Remote Backup Management System
 * Supports multiple remote storage methods
 */

class RemoteBackup {
    private $config;
    private $progress;

    public function __construct($progress = null) {
        $this->config = $this->loadRemoteConfig();
        $this->progress = $progress;
    }

    /**
     * Load remote backup configuration
     */
    private function loadRemoteConfig() {
        $configFile = Config::get('backup_path') . '/remote_config.json';
        if (file_exists($configFile)) {
            return json_decode(file_get_contents($configFile), true);
        }
        return [
            'enabled' => false,
            'method' => 'ssh', // ssh, ftp, s3, rsync
            'keep_local' => true,
            'servers' => []
        ];
    }

    /**
     * Save remote backup configuration
     */
    public function saveConfig($config) {
        $configFile = Config::get('backup_path') . '/remote_config.json';
        return file_put_contents($configFile, json_encode($config, JSON_PRETTY_PRINT));
    }

    /**
     * Transfer backup to remote server
     */
    public function transfer($localFile, $serverId = null) {
        if (!$this->config['enabled']) {
            $this->log("Remote backup is disabled");
            return false;
        }

        // Get server configuration
        $server = $this->getServer($serverId);
        if (!$server) {
            $this->log("No remote server configured");
            return false;
        }

        $method = $server['method'] ?? $this->config['method'];

        switch($method) {
            case 'ssh':
            case 'sftp':
                return $this->transferSSH($localFile, $server);
            case 'ftp':
                return $this->transferFTP($localFile, $server);
            case 'rsync':
                return $this->transferRsync($localFile, $server);
            case 's3':
                return $this->transferS3($localFile, $server);
            default:
                $this->log("Unknown transfer method: $method");
                return false;
        }
    }

    /**
     * Transfer via SSH/SFTP
     */
    private function transferSSH($localFile, $server) {
        $this->log("Starting SSH transfer to {$server['host']}");

        if (!file_exists($localFile)) {
            $this->log("Local file not found: $localFile");
            return false;
        }

        $fileSize = filesize($localFile);
        $fileName = basename($localFile);
        $remotePath = rtrim($server['path'], '/') . '/' . $fileName;

        // Build SCP command
        $scpCommand = $this->buildSCPCommand($localFile, $remotePath, $server);

        // Execute transfer with progress monitoring
        if ($this->progress) {
            return $this->executeWithProgress($scpCommand, $fileSize);
        } else {
            exec($scpCommand . " 2>&1", $output, $returnCode);

            if ($returnCode === 0) {
                $this->log("SSH transfer completed successfully");
                return true;
            } else {
                $this->log("SSH transfer failed: " . implode("\n", $output));
                return false;
            }
        }
    }

    /**
     * Build SCP command with proper authentication
     */
    private function buildSCPCommand($localFile, $remotePath, $server) {
        $sshOptions = "-o StrictHostKeyChecking=no -o ConnectTimeout=10";

        // Add port if specified
        if (!empty($server['port']) && $server['port'] != 22) {
            $sshOptions .= " -P " . escapeshellarg($server['port']);
        }

        // Add identity file if using key authentication
        if (!empty($server['key_file']) && file_exists($server['key_file'])) {
            $sshOptions .= " -i " . escapeshellarg($server['key_file']);
        }

        // Build remote destination
        $remote = sprintf(
            "%s@%s:%s",
            escapeshellarg($server['user']),
            escapeshellarg($server['host']),
            escapeshellarg($remotePath)
        );

        return sprintf(
            "scp %s %s %s",
            $sshOptions,
            escapeshellarg($localFile),
            $remote
        );
    }

    /**
     * Transfer via FTP
     */
    private function transferFTP($localFile, $server) {
        $this->log("Starting FTP transfer to {$server['host']}");

        $conn = ftp_connect($server['host'], $server['port'] ?? 21, 30);
        if (!$conn) {
            $this->log("Failed to connect to FTP server");
            return false;
        }

        // Login
        if (!ftp_login($conn, $server['user'], $server['password'])) {
            $this->log("FTP login failed");
            ftp_close($conn);
            return false;
        }

        // Set passive mode
        ftp_pasv($conn, true);

        // Change to target directory
        if (!empty($server['path'])) {
            ftp_chdir($conn, $server['path']);
        }

        // Upload file
        $fileName = basename($localFile);
        $result = ftp_put($conn, $fileName, $localFile, FTP_BINARY);

        ftp_close($conn);

        if ($result) {
            $this->log("FTP transfer completed successfully");
            return true;
        } else {
            $this->log("FTP transfer failed");
            return false;
        }
    }

    /**
     * Transfer via Rsync
     */
    private function transferRsync($localFile, $server) {
        $this->log("Starting Rsync transfer to {$server['host']}");

        $rsyncOptions = "-avz --progress";

        // Add SSH options if using SSH
        if (!empty($server['ssh']) && $server['ssh']) {
            $sshCmd = "ssh";

            if (!empty($server['port']) && $server['port'] != 22) {
                $sshCmd .= " -p " . escapeshellarg($server['port']);
            }

            if (!empty($server['key_file']) && file_exists($server['key_file'])) {
                $sshCmd .= " -i " . escapeshellarg($server['key_file']);
            }

            $rsyncOptions .= " -e " . escapeshellarg($sshCmd);
        }

        // Build remote destination
        $remotePath = sprintf(
            "%s@%s:%s",
            $server['user'],
            $server['host'],
            rtrim($server['path'], '/') . '/'
        );

        $cmd = sprintf(
            "rsync %s %s %s 2>&1",
            $rsyncOptions,
            escapeshellarg($localFile),
            escapeshellarg($remotePath)
        );

        exec($cmd, $output, $returnCode);

        if ($returnCode === 0) {
            $this->log("Rsync transfer completed successfully");
            return true;
        } else {
            $this->log("Rsync transfer failed: " . implode("\n", $output));
            return false;
        }
    }

    /**
     * Transfer to S3-compatible storage
     */
    private function transferS3($localFile, $server) {
        $this->log("Starting S3 transfer to {$server['bucket']}");

        // Check if AWS CLI is installed
        $awsCmd = trim(shell_exec("which aws 2>/dev/null"));
        if (!$awsCmd) {
            $this->log("AWS CLI not installed. Install with: apt-get install awscli");
            return false;
        }

        // Set credentials
        putenv("AWS_ACCESS_KEY_ID=" . $server['access_key']);
        putenv("AWS_SECRET_ACCESS_KEY=" . $server['secret_key']);

        if (!empty($server['endpoint'])) {
            $endpointUrl = "--endpoint-url " . escapeshellarg($server['endpoint']);
        } else {
            $endpointUrl = "";
        }

        $fileName = basename($localFile);
        $s3Path = "s3://{$server['bucket']}/{$server['path']}/{$fileName}";

        $cmd = sprintf(
            "aws s3 cp %s %s %s --storage-class STANDARD_IA 2>&1",
            escapeshellarg($localFile),
            escapeshellarg($s3Path),
            $endpointUrl
        );

        exec($cmd, $output, $returnCode);

        // Clear environment variables
        putenv("AWS_ACCESS_KEY_ID");
        putenv("AWS_SECRET_ACCESS_KEY");

        if ($returnCode === 0) {
            $this->log("S3 transfer completed successfully");
            return true;
        } else {
            $this->log("S3 transfer failed: " . implode("\n", $output));
            return false;
        }
    }

    /**
     * Execute transfer with progress monitoring
     */
    private function executeWithProgress($command, $fileSize) {
        $descriptors = [
            0 => ["pipe", "r"],
            1 => ["pipe", "w"],
            2 => ["pipe", "w"]
        ];

        $process = proc_open($command, $descriptors, $pipes);

        if (!is_resource($process)) {
            $this->log("Failed to start transfer process");
            return false;
        }

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $output = '';
        $error = '';

        while (!feof($pipes[1]) || !feof($pipes[2])) {
            $read = [$pipes[1], $pipes[2]];
            $write = null;
            $except = null;

            if (stream_select($read, $write, $except, 0, 200000)) {
                foreach ($read as $pipe) {
                    $line = fgets($pipe);
                    if ($pipe === $pipes[1]) {
                        $output .= $line;
                    } else {
                        $error .= $line;

                        // Parse progress from stderr (common for scp/rsync)
                        if (preg_match('/(\d+)%/', $line, $matches)) {
                            $percent = intval($matches[1]);
                            if ($this->progress) {
                                $this->progress->updateStep($percent, "Transferring to remote: {$percent}%");
                            }
                        }
                    }
                }
            }
        }

        fclose($pipes[0]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $returnCode = proc_close($process);

        if ($returnCode === 0) {
            $this->log("Transfer completed successfully");
            return true;
        } else {
            $this->log("Transfer failed: $error");
            return false;
        }
    }

    /**
     * Get server configuration
     */
    private function getServer($serverId = null) {
        if (empty($this->config['servers'])) {
            return null;
        }

        if ($serverId !== null) {
            foreach ($this->config['servers'] as $server) {
                if ($server['id'] === $serverId) {
                    return $server;
                }
            }
        }

        // Return first active server
        foreach ($this->config['servers'] as $server) {
            if (!isset($server['active']) || $server['active']) {
                return $server;
            }
        }

        return null;
    }

    /**
     * Test remote connection
     */
    public function testConnection($server) {
        switch($server['method']) {
            case 'ssh':
                return $this->testSSH($server);
            case 'ftp':
                return $this->testFTP($server);
            case 'rsync':
                return $this->testRsync($server);
            case 's3':
                return $this->testS3($server);
            default:
                return ['success' => false, 'message' => 'Unknown method'];
        }
    }

    /**
     * Test SSH connection
     */
    private function testSSH($server) {
        $sshOptions = "-o StrictHostKeyChecking=no -o ConnectTimeout=5 -o BatchMode=yes";

        if (!empty($server['port']) && $server['port'] != 22) {
            $sshOptions .= " -p " . escapeshellarg($server['port']);
        }

        if (!empty($server['key_file']) && file_exists($server['key_file'])) {
            $sshOptions .= " -i " . escapeshellarg($server['key_file']);
        }

        $cmd = sprintf(
            "ssh %s %s@%s 'echo OK' 2>&1",
            $sshOptions,
            escapeshellarg($server['user']),
            escapeshellarg($server['host'])
        );

        exec($cmd, $output, $returnCode);

        if ($returnCode === 0) {
            return ['success' => true, 'message' => 'SSH connection successful'];
        } else {
            return ['success' => false, 'message' => implode("\n", $output)];
        }
    }

    /**
     * Test FTP connection
     */
    private function testFTP($server) {
        $conn = @ftp_connect($server['host'], $server['port'] ?? 21, 10);
        if (!$conn) {
            return ['success' => false, 'message' => 'Failed to connect to FTP server'];
        }

        if (!@ftp_login($conn, $server['user'], $server['password'])) {
            ftp_close($conn);
            return ['success' => false, 'message' => 'FTP login failed'];
        }

        ftp_close($conn);
        return ['success' => true, 'message' => 'FTP connection successful'];
    }

    /**
     * Test Rsync
     */
    private function testRsync($server) {
        // For rsync over SSH, test SSH connection
        if (!empty($server['ssh']) && $server['ssh']) {
            return $this->testSSH($server);
        }

        // For rsync daemon, test connection
        $cmd = sprintf(
            "rsync --list-only %s@%s:: 2>&1",
            escapeshellarg($server['user']),
            escapeshellarg($server['host'])
        );

        exec($cmd, $output, $returnCode);

        if ($returnCode === 0) {
            return ['success' => true, 'message' => 'Rsync connection successful'];
        } else {
            return ['success' => false, 'message' => 'Rsync connection failed'];
        }
    }

    /**
     * Test S3 connection
     */
    private function testS3($server) {
        $awsCmd = trim(shell_exec("which aws 2>/dev/null"));
        if (!$awsCmd) {
            return ['success' => false, 'message' => 'AWS CLI not installed'];
        }

        putenv("AWS_ACCESS_KEY_ID=" . $server['access_key']);
        putenv("AWS_SECRET_ACCESS_KEY=" . $server['secret_key']);

        $endpointUrl = !empty($server['endpoint']) ? "--endpoint-url " . escapeshellarg($server['endpoint']) : "";

        $cmd = sprintf(
            "aws s3 ls s3://%s %s 2>&1",
            escapeshellarg($server['bucket']),
            $endpointUrl
        );

        exec($cmd, $output, $returnCode);

        putenv("AWS_ACCESS_KEY_ID");
        putenv("AWS_SECRET_ACCESS_KEY");

        if ($returnCode === 0) {
            return ['success' => true, 'message' => 'S3 connection successful'];
        } else {
            return ['success' => false, 'message' => implode("\n", $output)];
        }
    }

    /**
     * Clean up old remote backups
     */
    public function cleanupRemote($server, $retentionDays = 30) {
        // Implementation depends on the method
        switch($server['method']) {
            case 'ssh':
                return $this->cleanupSSH($server, $retentionDays);
            case 's3':
                return $this->cleanupS3($server, $retentionDays);
            default:
                $this->log("Cleanup not implemented for method: {$server['method']}");
                return false;
        }
    }

    /**
     * Clean up old backups via SSH
     */
    private function cleanupSSH($server, $retentionDays) {
        $cmd = sprintf(
            "ssh %s@%s 'find %s -name \"*.gz\" -mtime +%d -delete' 2>&1",
            escapeshellarg($server['user']),
            escapeshellarg($server['host']),
            escapeshellarg($server['path']),
            $retentionDays
        );

        exec($cmd, $output, $returnCode);

        return $returnCode === 0;
    }

    /**
     * Clean up old S3 backups
     */
    private function cleanupS3($server, $retentionDays) {
        // S3 lifecycle policies are preferred for automatic cleanup
        $this->log("S3 cleanup should be configured using lifecycle policies");
        return true;
    }

    /**
     * Log message
     */
    private function log($message, $level = 'INFO') {
        $logFile = Config::get('log_path') . '/remote_backup.log';
        $logMessage = date('Y-m-d H:i:s') . " [$level] $message\n";
        file_put_contents($logFile, $logMessage, FILE_APPEND | LOCK_EX);
    }

    /**
     * Get available transfer methods
     */
    public static function getAvailableMethods() {
        $methods = [];

        // Check SSH/SCP
        if (trim(shell_exec("which scp 2>/dev/null"))) {
            $methods[] = ['id' => 'ssh', 'name' => 'SSH/SCP', 'available' => true];
        }

        // Check FTP support
        if (function_exists('ftp_connect')) {
            $methods[] = ['id' => 'ftp', 'name' => 'FTP', 'available' => true];
        }

        // Check Rsync
        if (trim(shell_exec("which rsync 2>/dev/null"))) {
            $methods[] = ['id' => 'rsync', 'name' => 'Rsync', 'available' => true];
        }

        // Check AWS CLI
        if (trim(shell_exec("which aws 2>/dev/null"))) {
            $methods[] = ['id' => 's3', 'name' => 'S3/Compatible', 'available' => true];
        } else {
            $methods[] = ['id' => 's3', 'name' => 'S3 (AWS CLI required)', 'available' => false];
        }

        return $methods;
    }
}
?>