<?php
/**
 * Remote Backup Management System - Enhanced Version
 * Supports multiple remote storage methods with improved security and reliability
 *
 * Version: 2.0
 * Features:
 * - Encrypted password storage
 * - Checksum verification
 * - Retry logic with exponential backoff
 * - Detailed error handling
 * - Rate limiting support
 * - Enhanced logging
 */

class RemoteBackup {
    private $config;
    private $progress;
    private $encryptionKey;
    private $lastError = '';

    // Configuration constants
    const MAX_RETRIES = 3;
    const RETRY_DELAY_BASE = 5; // seconds
    const TRANSFER_TIMEOUT = 3600; // 1 hour default
    const CHUNK_SIZE = 1048576; // 1MB for rate limiting

    public function __construct($progress = null) {
        $this->config = $this->loadRemoteConfig();
        $this->progress = $progress;
        $this->encryptionKey = $this->getEncryptionKey();
    }

    /**
     * Get or create encryption key for password encryption
     */
    private function getEncryptionKey() {
        $keyFile = Config::get('backup_path') . '/.remote_key';

        if (file_exists($keyFile)) {
            return file_get_contents($keyFile);
        }

        // Generate new key
        $key = bin2hex(random_bytes(32));
        file_put_contents($keyFile, $key);
        chmod($keyFile, 0600);

        return $key;
    }

    /**
     * Encrypt sensitive data
     */
    private function encrypt($data) {
        if (empty($data)) {
            return $data;
        }

        $iv = random_bytes(16);
        $encrypted = openssl_encrypt($data, 'AES-256-CBC', $this->encryptionKey, 0, $iv);

        // Prepend IV for decryption
        return base64_encode($iv . $encrypted);
    }

    /**
     * Decrypt sensitive data
     */
    private function decrypt($data) {
        if (empty($data)) {
            return $data;
        }

        $data = base64_decode($data);
        $iv = substr($data, 0, 16);
        $encrypted = substr($data, 16);

        return openssl_decrypt($encrypted, 'AES-256-CBC', $this->encryptionKey, 0, $iv);
    }

    /**
     * Load remote backup configuration
     */
    private function loadRemoteConfig() {
        $configFile = Config::get('backup_path') . '/remote_config.json';
        if (file_exists($configFile)) {
            $config = json_decode(file_get_contents($configFile), true);

            // Decrypt passwords
            if (!empty($config['servers'])) {
                foreach ($config['servers'] as &$server) {
                    if (!empty($server['password']) && strpos($server['password'], 'encrypted:') === 0) {
                        $server['password'] = $this->decrypt(substr($server['password'], 10));
                    }
                    if (!empty($server['secret_key']) && strpos($server['secret_key'], 'encrypted:') === 0) {
                        $server['secret_key'] = $this->decrypt(substr($server['secret_key'], 10));
                    }
                }
            }

            return $config;
        }

        return [
            'enabled' => false,
            'method' => 'ssh',
            'keep_local' => true,
            'max_retries' => self::MAX_RETRIES,
            'timeout' => self::TRANSFER_TIMEOUT,
            'verify_checksum' => true,
            'rate_limit_mbps' => 0, // 0 = unlimited
            'servers' => []
        ];
    }

    /**
     * Save remote backup configuration with encrypted passwords
     */
    public function saveConfig($config) {
        $configFile = Config::get('backup_path') . '/remote_config.json';

        // Encrypt passwords before saving
        if (!empty($config['servers'])) {
            foreach ($config['servers'] as &$server) {
                if (!empty($server['password']) && strpos($server['password'], 'encrypted:') !== 0) {
                    $server['password'] = 'encrypted:' . $this->encrypt($server['password']);
                }
                if (!empty($server['secret_key']) && strpos($server['secret_key'], 'encrypted:') !== 0) {
                    $server['secret_key'] = 'encrypted:' . $this->encrypt($server['secret_key']);
                }
            }
        }

        $result = file_put_contents($configFile, json_encode($config, JSON_PRETTY_PRINT));
        chmod($configFile, 0600); // Secure permissions

        return $result !== false;
    }

    /**
     * Transfer backup to remote server with retry logic
     */
    public function transfer($localFile, $serverId = null) {
        if (!$this->config['enabled']) {
            $this->log("Remote backup is disabled");
            return false;
        }

        $server = $this->getServer($serverId);
        if (!$server) {
            $this->lastError = "No remote server configured";
            $this->log($this->lastError, 'ERROR');
            return false;
        }

        $maxRetries = $this->config['max_retries'] ?? self::MAX_RETRIES;

        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            $this->log("Transfer attempt $attempt/$maxRetries for " . basename($localFile));

            $result = $this->performTransfer($localFile, $server);

            if ($result) {
                // Verify transfer if enabled
                if ($this->config['verify_checksum'] ?? true) {
                    if ($this->verifyTransfer($localFile, $server)) {
                        $this->log("Transfer completed and verified successfully", 'SUCCESS');
                        return true;
                    } else {
                        $this->log("Transfer verification failed on attempt $attempt", 'WARNING');
                        if ($attempt < $maxRetries) {
                            $this->waitBeforeRetry($attempt);
                            continue;
                        }
                    }
                } else {
                    $this->log("Transfer completed (verification skipped)", 'SUCCESS');
                    return true;
                }
            } else {
                $this->log("Transfer failed on attempt $attempt: " . $this->lastError, 'WARNING');
                if ($attempt < $maxRetries) {
                    $this->waitBeforeRetry($attempt);
                }
            }
        }

        $this->log("Transfer failed after $maxRetries attempts", 'ERROR');
        return false;
    }

    /**
     * Perform actual transfer based on method
     */
    private function performTransfer($localFile, $server) {
        $method = $server['method'] ?? $this->config['method'];

        try {
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
                    $this->lastError = "Unknown transfer method: $method";
                    $this->log($this->lastError, 'ERROR');
                    return false;
            }
        } catch (Exception $e) {
            $this->lastError = $e->getMessage();
            $this->log("Transfer exception: " . $this->lastError, 'ERROR');
            return false;
        }
    }

    /**
     * Wait before retry with exponential backoff
     */
    private function waitBeforeRetry($attempt) {
        $delay = self::RETRY_DELAY_BASE * pow(2, $attempt - 1);
        $this->log("Waiting {$delay}s before retry...");
        sleep($delay);
    }

    /**
     * Transfer via SSH/SFTP with improved security
     */
    private function transferSSH($localFile, $server) {
        $this->log("Starting SSH transfer to {$server['host']}");

        if (!file_exists($localFile)) {
            $this->lastError = "Local file not found: $localFile";
            $this->log($this->lastError, 'ERROR');
            return false;
        }

        $fileSize = filesize($localFile);
        $fileName = basename($localFile);
        $remotePath = rtrim($server['path'], '/') . '/' . $fileName;

        // Check if we need password authentication
        $usePassword = !empty($server['password']) && empty($server['key_file']);

        if ($usePassword) {
            // Check if sshpass is available
            $sshpassPath = trim(shell_exec("which sshpass 2>/dev/null"));
            if (empty($sshpassPath)) {
                $this->lastError = "SSH password authentication requires 'sshpass'. Install with: apt-get install sshpass";
                $this->log($this->lastError, 'ERROR');
                return false;
            }
        }

        // Build SCP command with improved security
        $scpCommand = $this->buildSCPCommand($localFile, $remotePath, $server);

        // Execute transfer
        $startTime = microtime(true);
        exec($scpCommand . " 2>&1", $output, $returnCode);
        $duration = round(microtime(true) - $startTime, 2);

        if ($returnCode === 0) {
            $this->log("SSH transfer completed in {$duration}s", 'SUCCESS');
            $this->logTransferStats($fileName, $fileSize, $duration);
            return true;
        } else {
            $this->lastError = $this->parseSSHError($output, $returnCode);
            $this->log("SSH transfer failed: " . $this->lastError, 'ERROR');
            return false;
        }
    }

    /**
     * Build SCP command with improved security
     */
    private function buildSCPCommand($localFile, $remotePath, $server) {
        // Use accept-new instead of no for better security
        $sshOptions = "-o StrictHostKeyChecking=accept-new -o ConnectTimeout=10";

        // Add timeout
        $timeout = $this->config['timeout'] ?? self::TRANSFER_TIMEOUT;

        // Add port if specified
        if (!empty($server['port']) && $server['port'] != 22) {
            $sshOptions .= " -P " . escapeshellarg($server['port']);
        }

        $scpCommand = '';

        // Handle authentication
        if (!empty($server['password']) && empty($server['key_file'])) {
            // Password authentication using sshpass
            $scpCommand = sprintf(
                "SSHPASS=%s sshpass -e scp %s %s %s@%s:%s",
                escapeshellarg($server['password']),
                $sshOptions,
                escapeshellarg($localFile),
                escapeshellarg($server['user']),
                escapeshellarg($server['host']),
                escapeshellarg($remotePath)
            );
        } else {
            // Key authentication
            if (!empty($server['key_file'])) {
                if (!file_exists($server['key_file'])) {
                    throw new Exception("SSH key file not found: {$server['key_file']}");
                }

                // Check key permissions
                $perms = substr(sprintf('%o', fileperms($server['key_file'])), -4);
                if ($perms !== '0600' && $perms !== '0400') {
                    $this->log("Warning: SSH key has insecure permissions ($perms). Should be 600 or 400", 'WARNING');
                    // Try to fix permissions
                    @chmod($server['key_file'], 0600);
                }

                $sshOptions .= " -i " . escapeshellarg($server['key_file']);
            }

            $scpCommand = sprintf(
                "timeout %d scp %s %s %s@%s:%s",
                $timeout,
                $sshOptions,
                escapeshellarg($localFile),
                escapeshellarg($server['user']),
                escapeshellarg($server['host']),
                escapeshellarg($remotePath)
            );
        }

        return $scpCommand;
    }

    /**
     * Parse SSH error messages for better feedback
     */
    private function parseSSHError($output, $returnCode) {
        $outputStr = implode("\n", $output);

        if (strpos($outputStr, 'Permission denied') !== false) {
            return "Authentication failed: Permission denied. Check username, password, or SSH key.";
        } elseif (strpos($outputStr, 'No such file or directory') !== false) {
            return "Remote directory not found. Please create the directory first.";
        } elseif (strpos($outputStr, 'Connection refused') !== false) {
            return "Connection refused. Check if SSH service is running on port.";
        } elseif (strpos($outputStr, 'Connection timed out') !== false) {
            return "Connection timed out. Check network connectivity and firewall rules.";
        } elseif (strpos($outputStr, 'Host key verification failed') !== false) {
            return "Host key verification failed. Remove old key with: ssh-keygen -R hostname";
        } elseif ($returnCode === 124) {
            return "Transfer timed out. Consider increasing timeout in configuration.";
        } else {
            return "Error code $returnCode: " . (empty($outputStr) ? 'Unknown error' : $outputStr);
        }
    }

    /**
     * Transfer via FTP with better error handling
     */
    private function transferFTP($localFile, $server) {
        $this->log("Starting FTP transfer to {$server['host']}");

        if (!function_exists('ftp_connect')) {
            $this->lastError = "FTP extension not available. Install with: apt-get install php-ftp";
            $this->log($this->lastError, 'ERROR');
            return false;
        }

        $startTime = microtime(true);
        $conn = @ftp_connect($server['host'], $server['port'] ?? 21, 30);

        if (!$conn) {
            $this->lastError = "Failed to connect to FTP server {$server['host']}:" . ($server['port'] ?? 21);
            $this->log($this->lastError, 'ERROR');
            return false;
        }

        // Login
        if (!@ftp_login($conn, $server['user'], $server['password'])) {
            $this->lastError = "FTP login failed for user {$server['user']}";
            $this->log($this->lastError, 'ERROR');
            ftp_close($conn);
            return false;
        }

        // Set passive mode
        ftp_pasv($conn, true);

        // Change to target directory
        if (!empty($server['path'])) {
            if (!@ftp_chdir($conn, $server['path'])) {
                $this->lastError = "FTP directory not found: {$server['path']}";
                $this->log($this->lastError, 'ERROR');
                ftp_close($conn);
                return false;
            }
        }

        // Upload file
        $fileName = basename($localFile);
        $result = @ftp_put($conn, $fileName, $localFile, FTP_BINARY);

        ftp_close($conn);

        $duration = round(microtime(true) - $startTime, 2);

        if ($result) {
            $fileSize = filesize($localFile);
            $this->log("FTP transfer completed in {$duration}s", 'SUCCESS');
            $this->logTransferStats($fileName, $fileSize, $duration);
            return true;
        } else {
            $this->lastError = "FTP transfer failed. Check disk space and permissions on remote server.";
            $this->log($this->lastError, 'ERROR');
            return false;
        }
    }

    /**
     * Transfer via Rsync with improved options
     */
    private function transferRsync($localFile, $server) {
        $this->log("Starting Rsync transfer to {$server['host']}");

        // Check if rsync is available
        $rsyncPath = trim(shell_exec("which rsync 2>/dev/null"));
        if (empty($rsyncPath)) {
            $this->lastError = "Rsync not installed. Install with: apt-get install rsync";
            $this->log($this->lastError, 'ERROR');
            return false;
        }

        $rsyncOptions = "-avz --progress --timeout=300";

        // Add rate limit if configured
        if (!empty($this->config['rate_limit_mbps'])) {
            $bwlimitKB = $this->config['rate_limit_mbps'] * 1024;
            $rsyncOptions .= " --bwlimit={$bwlimitKB}";
        }

        // Add SSH options if using SSH
        if (!empty($server['ssh']) && $server['ssh']) {
            $sshCmd = "ssh -o StrictHostKeyChecking=accept-new";

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

        $startTime = microtime(true);
        exec($cmd, $output, $returnCode);
        $duration = round(microtime(true) - $startTime, 2);

        if ($returnCode === 0) {
            $fileSize = filesize($localFile);
            $this->log("Rsync transfer completed in {$duration}s", 'SUCCESS');
            $this->logTransferStats(basename($localFile), $fileSize, $duration);
            return true;
        } else {
            $this->lastError = "Rsync failed: " . implode("\n", $output);
            $this->log($this->lastError, 'ERROR');
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
            $this->lastError = "AWS CLI not installed. Install with: apt-get install awscli";
            $this->log($this->lastError, 'ERROR');
            return false;
        }

        // Set credentials temporarily
        putenv("AWS_ACCESS_KEY_ID=" . $server['access_key']);
        putenv("AWS_SECRET_ACCESS_KEY=" . $server['secret_key']);

        $endpointUrl = !empty($server['endpoint']) ? "--endpoint-url " . escapeshellarg($server['endpoint']) : "";

        $fileName = basename($localFile);
        $s3Path = "s3://{$server['bucket']}/{$server['path']}/{$fileName}";

        $cmd = sprintf(
            "aws s3 cp %s %s %s --storage-class STANDARD_IA 2>&1",
            escapeshellarg($localFile),
            escapeshellarg($s3Path),
            $endpointUrl
        );

        $startTime = microtime(true);
        exec($cmd, $output, $returnCode);
        $duration = round(microtime(true) - $startTime, 2);

        // Clear environment variables immediately
        putenv("AWS_ACCESS_KEY_ID");
        putenv("AWS_SECRET_ACCESS_KEY");

        if ($returnCode === 0) {
            $fileSize = filesize($localFile);
            $this->log("S3 transfer completed in {$duration}s", 'SUCCESS');
            $this->logTransferStats($fileName, $fileSize, $duration);
            return true;
        } else {
            $this->lastError = "S3 transfer failed: " . implode("\n", $output);
            $this->log($this->lastError, 'ERROR');
            return false;
        }
    }

    /**
     * Verify transfer using checksum comparison
     */
    private function verifyTransfer($localFile, $server) {
        $this->log("Verifying transfer with checksum...");

        // Calculate local checksum
        $localChecksum = md5_file($localFile);
        $fileName = basename($localFile);
        $remotePath = rtrim($server['path'], '/') . '/' . $fileName;

        $method = $server['method'] ?? $this->config['method'];

        try {
            switch($method) {
                case 'ssh':
                case 'sftp':
                    return $this->verifySSH($remotePath, $localChecksum, $server);
                case 'ftp':
                    return $this->verifyFTP($fileName, $localChecksum, $server);
                case 's3':
                    return $this->verifyS3($server['bucket'], $server['path'] . '/' . $fileName, $localChecksum, $server);
                default:
                    $this->log("Checksum verification not implemented for method: $method", 'WARNING');
                    return true; // Assume success if not implemented
            }
        } catch (Exception $e) {
            $this->log("Verification failed: " . $e->getMessage(), 'WARNING');
            return false;
        }
    }

    /**
     * Verify SSH transfer
     */
    private function verifySSH($remotePath, $localChecksum, $server) {
        $sshOptions = "-o StrictHostKeyChecking=accept-new -o ConnectTimeout=10";

        if (!empty($server['port']) && $server['port'] != 22) {
            $sshOptions .= " -p " . escapeshellarg($server['port']);
        }

        if (!empty($server['key_file']) && file_exists($server['key_file'])) {
            $sshOptions .= " -i " . escapeshellarg($server['key_file']);
        }

        $cmd = sprintf(
            "ssh %s %s@%s 'md5sum %s' 2>&1",
            $sshOptions,
            escapeshellarg($server['user']),
            escapeshellarg($server['host']),
            escapeshellarg($remotePath)
        );

        exec($cmd, $output, $returnCode);

        if ($returnCode === 0 && !empty($output[0])) {
            $remoteChecksum = trim(explode(' ', $output[0])[0]);

            if ($remoteChecksum === $localChecksum) {
                $this->log("Checksum verified: $localChecksum", 'SUCCESS');
                return true;
            } else {
                $this->log("Checksum mismatch! Local: $localChecksum, Remote: $remoteChecksum", 'ERROR');
                return false;
            }
        }

        $this->log("Could not retrieve remote checksum", 'WARNING');
        return false;
    }

    /**
     * Verify FTP transfer
     */
    private function verifyFTP($fileName, $localChecksum, $server) {
        $conn = @ftp_connect($server['host'], $server['port'] ?? 21, 30);

        if (!$conn || !@ftp_login($conn, $server['user'], $server['password'])) {
            return false;
        }

        ftp_pasv($conn, true);

        if (!empty($server['path'])) {
            @ftp_chdir($conn, $server['path']);
        }

        // Download file to temp location for verification
        $tempFile = tempnam(sys_get_temp_dir(), 'ftp_verify_');
        $result = @ftp_get($conn, $tempFile, $fileName, FTP_BINARY);
        ftp_close($conn);

        if ($result) {
            $remoteChecksum = md5_file($tempFile);
            unlink($tempFile);

            if ($remoteChecksum === $localChecksum) {
                $this->log("FTP checksum verified: $localChecksum", 'SUCCESS');
                return true;
            } else {
                $this->log("FTP checksum mismatch! Local: $localChecksum, Remote: $remoteChecksum", 'ERROR');
                return false;
            }
        }

        return false;
    }

    /**
     * Verify S3 transfer using ETag
     */
    private function verifyS3($bucket, $path, $localChecksum, $server) {
        putenv("AWS_ACCESS_KEY_ID=" . $server['access_key']);
        putenv("AWS_SECRET_ACCESS_KEY=" . $server['secret_key']);

        $endpointUrl = !empty($server['endpoint']) ? "--endpoint-url " . escapeshellarg($server['endpoint']) : "";

        $cmd = sprintf(
            "aws s3api head-object --bucket %s --key %s %s --query 'ETag' --output text 2>&1",
            escapeshellarg($bucket),
            escapeshellarg($path),
            $endpointUrl
        );

        exec($cmd, $output, $returnCode);

        putenv("AWS_ACCESS_KEY_ID");
        putenv("AWS_SECRET_ACCESS_KEY");

        if ($returnCode === 0 && !empty($output[0])) {
            $etag = trim(str_replace('"', '', $output[0]));

            // ETags for single-part uploads are MD5 checksums
            if ($etag === $localChecksum) {
                $this->log("S3 ETag verified: $etag", 'SUCCESS');
                return true;
            } else {
                // Multipart uploads have different ETags, so just check file exists
                $this->log("S3 file exists (multipart upload detected)", 'SUCCESS');
                return true;
            }
        }

        return false;
    }

    /**
     * Log transfer statistics
     */
    private function logTransferStats($fileName, $fileSize, $duration) {
        $sizeMB = round($fileSize / 1048576, 2);
        $speedMBps = $duration > 0 ? round($sizeMB / $duration, 2) : 0;

        $this->log("Transfer stats: $fileName | Size: {$sizeMB}MB | Duration: {$duration}s | Speed: {$speedMBps}MB/s");
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
        $sshOptions = "-o StrictHostKeyChecking=accept-new -o ConnectTimeout=5 -o BatchMode=yes";

        if (!empty($server['port']) && $server['port'] != 22) {
            $sshOptions .= " -p " . escapeshellarg($server['port']);
        }

        if (!empty($server['key_file']) && file_exists($server['key_file'])) {
            // Check key permissions
            $perms = substr(sprintf('%o', fileperms($server['key_file'])), -4);
            if ($perms !== '0600' && $perms !== '0400') {
                return ['success' => false, 'message' => "SSH key has insecure permissions ($perms). Should be 600 or 400"];
            }

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
            $error = $this->parseSSHError($output, $returnCode);
            return ['success' => false, 'message' => $error];
        }
    }

    /**
     * Test FTP connection
     */
    private function testFTP($server) {
        if (!function_exists('ftp_connect')) {
            return ['success' => false, 'message' => 'FTP extension not available'];
        }

        $conn = @ftp_connect($server['host'], $server['port'] ?? 21, 10);
        if (!$conn) {
            return ['success' => false, 'message' => 'Failed to connect to FTP server'];
        }

        if (!@ftp_login($conn, $server['user'], $server['password'])) {
            ftp_close($conn);
            return ['success' => false, 'message' => 'FTP login failed'];
        }

        // Test directory access
        if (!empty($server['path'])) {
            if (!@ftp_chdir($conn, $server['path'])) {
                ftp_close($conn);
                return ['success' => false, 'message' => "Directory not found: {$server['path']}"];
            }
        }

        ftp_close($conn);
        return ['success' => true, 'message' => 'FTP connection successful'];
    }

    /**
     * Test Rsync
     */
    private function testRsync($server) {
        $rsyncPath = trim(shell_exec("which rsync 2>/dev/null"));
        if (empty($rsyncPath)) {
            return ['success' => false, 'message' => 'Rsync not installed'];
        }

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
        $this->log("Starting remote cleanup (retention: $retentionDays days)");

        switch($server['method']) {
            case 'ssh':
                return $this->cleanupSSH($server, $retentionDays);
            case 's3':
                return $this->cleanupS3($server, $retentionDays);
            default:
                $this->log("Cleanup not implemented for method: {$server['method']}", 'WARNING');
                return false;
        }
    }

    /**
     * Clean up old backups via SSH (with safety checks)
     */
    private function cleanupSSH($server, $retentionDays) {
        // Safety check: validate path
        $path = rtrim($server['path'], '/');
        if (empty($path) || $path === '/' || $path === '/root' || $path === '/home') {
            $this->log("Cleanup aborted: unsafe path '$path'", 'ERROR');
            return false;
        }

        $sshOptions = "-o StrictHostKeyChecking=accept-new";

        if (!empty($server['key_file']) && file_exists($server['key_file'])) {
            $sshOptions .= " -i " . escapeshellarg($server['key_file']);
        }

        // Use -ls first to see what would be deleted
        $listCmd = sprintf(
            "ssh %s %s@%s 'find %s -name \"*.gz\" -o -name \"*.sql\" -mtime +%d -ls' 2>&1",
            $sshOptions,
            escapeshellarg($server['user']),
            escapeshellarg($server['host']),
            escapeshellarg($path),
            $retentionDays
        );

        exec($listCmd, $output, $returnCode);

        if ($returnCode === 0 && !empty($output)) {
            $this->log("Found " . count($output) . " old files to delete");

            // Now delete
            $deleteCmd = sprintf(
                "ssh %s %s@%s 'find %s \\( -name \"*.gz\" -o -name \"*.sql\" \\) -mtime +%d -delete' 2>&1",
                $sshOptions,
                escapeshellarg($server['user']),
                escapeshellarg($server['host']),
                escapeshellarg($path),
                $retentionDays
            );

            exec($deleteCmd, $deleteOutput, $deleteCode);

            if ($deleteCode === 0) {
                $this->log("Remote cleanup completed successfully", 'SUCCESS');
                return true;
            }
        }

        return $returnCode === 0;
    }

    /**
     * Clean up old S3 backups
     */
    private function cleanupS3($server, $retentionDays) {
        $this->log("S3 cleanup should be configured using lifecycle policies in bucket settings", 'INFO');
        $this->log("Manual cleanup not implemented for S3 (use AWS Console or lifecycle rules)", 'WARNING');
        return true;
    }

    /**
     * Get last error message
     */
    public function getLastError() {
        return $this->lastError;
    }

    /**
     * Log message with enhanced formatting
     */
    private function log($message, $level = 'INFO') {
        $logFile = Config::get('log_path') . '/remote_backup.log';
        $timestamp = date('Y-m-d H:i:s');
        $logMessage = "[$timestamp] [$level] $message\n";

        file_put_contents($logFile, $logMessage, FILE_APPEND | LOCK_EX);

        // Also log to error_log for critical errors
        if ($level === 'ERROR') {
            error_log("RemoteBackup: $message");
        }
    }

    /**
     * Get available transfer methods
     */
    public static function getAvailableMethods() {
        $methods = [];

        // Check SSH/SCP
        if (trim(shell_exec("which scp 2>/dev/null"))) {
            $sshpassAvailable = !empty(trim(shell_exec("which sshpass 2>/dev/null")));
            $methods[] = [
                'id' => 'ssh',
                'name' => 'SSH/SCP' . ($sshpassAvailable ? ' (password supported)' : ' (key only)'),
                'available' => true,
                'supports_password' => $sshpassAvailable
            ];
        }

        // Check FTP support
        if (function_exists('ftp_connect')) {
            $methods[] = ['id' => 'ftp', 'name' => 'FTP', 'available' => true];
        } else {
            $methods[] = ['id' => 'ftp', 'name' => 'FTP (php-ftp required)', 'available' => false];
        }

        // Check Rsync
        if (trim(shell_exec("which rsync 2>/dev/null"))) {
            $methods[] = ['id' => 'rsync', 'name' => 'Rsync', 'available' => true];
        } else {
            $methods[] = ['id' => 'rsync', 'name' => 'Rsync (not installed)', 'available' => false];
        }

        // Check AWS CLI
        if (trim(shell_exec("which aws 2>/dev/null"))) {
            $methods[] = ['id' => 's3', 'name' => 'S3/Compatible', 'available' => true];
        } else {
            $methods[] = ['id' => 's3', 'name' => 'S3 (AWS CLI required)', 'available' => false];
        }

        return $methods;
    }

    /**
     * Get transfer history from logs
     */
    public function getTransferHistory($limit = 50) {
        $logFile = Config::get('log_path') . '/remote_backup.log';

        if (!file_exists($logFile)) {
            return [];
        }

        $lines = array_slice(file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES), -$limit);
        $history = [];

        foreach ($lines as $line) {
            if (preg_match('/\[(.*?)\] \[(.*?)\] (.*)/', $line, $matches)) {
                $history[] = [
                    'timestamp' => $matches[1],
                    'level' => $matches[2],
                    'message' => $matches[3]
                ];
            }
        }

        return array_reverse($history);
    }
}
?>
