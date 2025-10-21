<?php
/**
 * Auto-detection configuration system
 * Detects API configuration without modifying anything
 */

// Configurar zona horaria a Colombia (UTC-5)
date_default_timezone_set('America/Bogota');

class Config {
    private static $config = null;
    private static $configFile = __DIR__ . '/../config.local.php';
    
    public static function init() {
        if (self::$config !== null) {
            return self::$config;
        }
        
        // Try to load existing config
        if (file_exists(self::$configFile)) {
            include self::$configFile;
            if (isset($config)) {
                self::$config = $config;
                return self::$config;
            }
        }
        
        // Auto-detect configuration
        self::$config = self::autoDetect();
        self::saveConfig();
        return self::$config;
    }
    
    private static function autoDetect() {
        $config = [
            'detected_at' => date('Y-m-d H:i:s'),
            'server_name' => gethostname(),
        ];
        
        // Find API installation
        $apiPath = self::findApiPath();
        if ($apiPath) {
            $config['api_path'] = $apiPath;
            $config['storage_path'] = $apiPath . '/storage';
            
            // Read database configuration from .env
            $envPath = $apiPath . '/.env';
            if (file_exists($envPath)) {
                $env = self::parseEnv($envPath);
                $config['db_host'] = $env['DB_HOST'] ?? 'localhost';
                $config['db_port'] = $env['DB_PORT'] ?? '3306';
                $config['db_name'] = $env['DB_DATABASE'] ?? '';
                $config['db_user'] = $env['DB_USERNAME'] ?? '';
                $config['db_pass'] = $env['DB_PASSWORD'] ?? '';
            }
        }
        
        // Set backup paths
        $config['backup_path'] = '/var/www/html/backup-manager/backups';
        $config['temp_path'] = '/var/www/html/backup-manager/temp';
        $config['log_path'] = '/var/www/html/backup-manager/logs';
        
        // Default settings
        $config['retention_days'] = 30;
        $config['compression'] = 'medium';
        $config['admin_password'] = password_hash('admin123', PASSWORD_DEFAULT); // Change this!
        
        return $config;
    }
    
    private static function findApiPath() {
        // Search for Laravel artisan file
        $searchPaths = [
            '/var/www/html/apidian',
            '/var/www/apidian',
            '/srv/apidian',
            '/opt/apidian'
        ];
        
        foreach ($searchPaths as $path) {
            if (file_exists($path . '/artisan')) {
                return $path;
            }
        }
        
        // Try to find using find command
        $result = shell_exec("find /var/www -name 'artisan' -type f 2>/dev/null | head -1");
        if ($result) {
            return dirname(trim($result));
        }
        
        return null;
    }
    
    private static function parseEnv($file) {
        $env = [];
        $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        
        foreach ($lines as $line) {
            if (strpos($line, '=') !== false && strpos($line, '#') !== 0) {
                list($key, $value) = explode('=', $line, 2);
                $key = trim($key);
                $value = trim($value);
                
                // Remove quotes if present
                if ((substr($value, 0, 1) == '"' && substr($value, -1) == '"') ||
                    (substr($value, 0, 1) == "'" && substr($value, -1) == "'")) {
                    $value = substr($value, 1, -1);
                }
                
                $env[$key] = $value;
            }
        }
        
        return $env;
    }
    
    private static function saveConfig() {
        $configContent = "<?php\n\$config = " . var_export(self::$config, true) . ";\n";

        // Get current file owner if file exists
        $maintainOwnership = false;
        if (file_exists(self::$configFile)) {
            $fileInfo = stat(self::$configFile);
            $maintainOwnership = true;
            $currentUid = $fileInfo['uid'];
            $currentGid = $fileInfo['gid'];
        }

        // Write the file
        file_put_contents(self::$configFile, $configContent);

        // Set secure permissions
        chmod(self::$configFile, 0600);

        // Maintain ownership if file existed and we're running as root
        if ($maintainOwnership && posix_getuid() === 0) {
            chown(self::$configFile, $currentUid);
            chgrp(self::$configFile, $currentGid);
        }
    }
    
    public static function get($key, $default = null) {
        if (self::$config === null) {
            self::init();
        }
        return self::$config[$key] ?? $default;
    }
    
    public static function set($key, $value) {
        if (self::$config === null) {
            self::init();
        }
        self::$config[$key] = $value;
        self::saveConfig();
    }
    
    public static function getDatabaseSize() {
        $dbName = self::get('db_name');
        $dbUser = self::get('db_user');
        $dbPass = self::get('db_pass');
        $dbHost = self::get('db_host', 'localhost');
        
        if (!$dbName || !$dbUser) {
            return 0;
        }
        
        // Try direct PDO connection first for accuracy
        try {
            $dsn = self::getDSN($dbHost, $dbName);
            $pdo = new PDO($dsn, $dbUser, $dbPass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
            ]);
            
            $stmt = $pdo->prepare("
                SELECT ROUND(SUM(data_length + index_length) / 1024 / 1024, 2) AS size 
                FROM information_schema.TABLES 
                WHERE table_schema = ?
            ");
            $stmt->execute([$dbName]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($result && isset($result['size'])) {
                return floatval($result['size']);
            }
        } catch (Exception $e) {
            error_log("Database size query via PDO failed: " . $e->getMessage());
        }
        
        // Fallback to mysql command line
        if ($dbHost === 'localhost') {
            $cmd = sprintf(
                "mysql --socket=/var/run/mysqld/mysqld.sock -u %s %s -e %s -s -N 2>/dev/null",
                escapeshellarg($dbUser),
                $dbPass ? '-p' . escapeshellarg($dbPass) : '',
                escapeshellarg("SELECT ROUND(SUM(data_length + index_length) / 1024 / 1024, 2) AS size FROM information_schema.TABLES WHERE table_schema = '{$dbName}';")
            );
        } else {
            $cmd = sprintf(
                "mysql -h %s -u %s %s -e %s -s -N 2>/dev/null",
                escapeshellarg($dbHost),
                escapeshellarg($dbUser),
                $dbPass ? '-p' . escapeshellarg($dbPass) : '',
                escapeshellarg("SELECT ROUND(SUM(data_length + index_length) / 1024 / 1024, 2) AS size FROM information_schema.TABLES WHERE table_schema = '{$dbName}';")
            );
        }
        
        $size = trim(shell_exec($cmd));
        return $size ? floatval($size) : 0;
    }
    
    public static function getStorageSize() {
        $storagePath = self::get('storage_path');
        if (!$storagePath || !is_dir($storagePath)) {
            return 0;
        }
        
        $cmd = sprintf("du -sm %s 2>/dev/null | cut -f1", escapeshellarg($storagePath));
        $size = shell_exec($cmd);
        return intval($size);
    }
    
    public static function testDatabaseConnection() {
        $dbUser = self::get('db_user');
        $dbPass = self::get('db_pass');
        $dbHost = self::get('db_host', 'localhost');
        $dbName = self::get('db_name');
        
        if (!$dbUser || !$dbName) {
            return false;
        }
        
        // Use socket connection for localhost
        if ($dbHost === 'localhost') {
            $cmd = sprintf(
                "mysql --socket=/var/run/mysqld/mysqld.sock -u %s %s -e 'SELECT 1' %s 2>&1",
                escapeshellarg($dbUser),
                $dbPass ? '-p' . escapeshellarg($dbPass) : '',
                escapeshellarg($dbName)
            );
        } else {
            $cmd = sprintf(
                "mysql -h %s -u %s %s -e 'SELECT 1' %s 2>&1",
                escapeshellarg($dbHost),
                escapeshellarg($dbUser),
                $dbPass ? '-p' . escapeshellarg($dbPass) : '',
                escapeshellarg($dbName)
            );
        }
        
        exec($cmd, $output, $returnCode);
        return $returnCode === 0;
    }
    
    /**
     * Generate correct DSN for PDO connection
     */
    private static function getDSN($dbHost, $dbName) {
        if ($dbHost === 'localhost') {
            return "mysql:unix_socket=/var/run/mysqld/mysqld.sock;dbname={$dbName};charset=utf8mb4";
        } else {
            return "mysql:host={$dbHost};dbname={$dbName};charset=utf8mb4";
        }
    }

    /**
     * Check if binary logging is enabled
     */
    public static function isBinaryLoggingEnabled() {
        try {
            $dbHost = self::get('db_host', 'localhost');
            $dbUser = self::get('db_user');
            $dbPass = self::get('db_pass');
            $dbName = self::get('db_name');
            
            $dsn = self::getDSN($dbHost, $dbName);
            $pdo = new PDO($dsn, $dbUser, $dbPass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
            ]);
            
            $stmt = $pdo->query("SHOW VARIABLES LIKE 'log_bin'");
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            return $result && strtolower($result['Value']) === 'on';
        } catch (Exception $e) {
            error_log("Failed to check binary logging status: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get current binary log position
     */
    public static function getBinaryLogPosition() {
        try {
            $dbHost = self::get('db_host', 'localhost');
            $dbUser = self::get('db_user');
            $dbPass = self::get('db_pass');
            $dbName = self::get('db_name');
            
            $dsn = self::getDSN($dbHost, $dbName);
            $pdo = new PDO($dsn, $dbUser, $dbPass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
            ]);
            
            $stmt = $pdo->query("SHOW MASTER STATUS");
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($result) {
                return [
                    'file' => $result['File'],
                    'position' => $result['Position'],
                    'timestamp' => time()
                ];
            }
        } catch (Exception $e) {
            error_log("Failed to get binary log position: " . $e->getMessage());
        }
        
        return null;
    }
    
    /**
     * Get available binary logs
     */
    public static function getBinaryLogs() {
        try {
            $dbHost = self::get('db_host', 'localhost');
            $dbUser = self::get('db_user');
            $dbPass = self::get('db_pass');
            $dbName = self::get('db_name');
            
            $dsn = self::getDSN($dbHost, $dbName);
            $pdo = new PDO($dsn, $dbUser, $dbPass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
            ]);
            
            $stmt = $pdo->query("SHOW BINARY LOGS");
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log("Failed to get binary logs: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Purge old binary logs before a specific file
     */
    public static function purgeBinaryLogs($beforeFile) {
        try {
            $dbHost = self::get('db_host', 'localhost');
            $dbUser = self::get('db_user');
            $dbPass = self::get('db_pass');
            $dbName = self::get('db_name');
            
            $dsn = self::getDSN($dbHost, $dbName);
            $pdo = new PDO($dsn, $dbUser, $dbPass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
            ]);
            
            $stmt = $pdo->prepare("PURGE BINARY LOGS TO ?");
            return $stmt->execute([$beforeFile]);
        } catch (Exception $e) {
            error_log("Failed to purge binary logs: " . $e->getMessage());
            return false;
        }
    }
}

// Initialize configuration on load
Config::init();