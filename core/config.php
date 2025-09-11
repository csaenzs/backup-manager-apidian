<?php
/**
 * Auto-detection configuration system
 * Detects API configuration without modifying anything
 */

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
        file_put_contents(self::$configFile, $configContent);
        chmod(self::$configFile, 0600); // Secure the config file
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
        
        $cmd = sprintf(
            "mysql -h %s -u %s %s -e \"SELECT ROUND(SUM(data_length + index_length) / 1024 / 1024, 2) AS size FROM information_schema.TABLES WHERE table_schema = '%s';\" -s -N 2>/dev/null",
            escapeshellarg($dbHost),
            escapeshellarg($dbUser),
            $dbPass ? '-p' . escapeshellarg($dbPass) : '',
            $dbName
        );
        
        $size = shell_exec($cmd);
        return floatval($size);
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
        
        $cmd = sprintf(
            "mysql -h %s -u %s %s -e 'SELECT 1' %s 2>&1",
            escapeshellarg($dbHost),
            escapeshellarg($dbUser),
            $dbPass ? '-p' . escapeshellarg($dbPass) : '',
            escapeshellarg($dbName)
        );
        
        exec($cmd, $output, $returnCode);
        return $returnCode === 0;
    }
}

// Initialize configuration on load
Config::init();