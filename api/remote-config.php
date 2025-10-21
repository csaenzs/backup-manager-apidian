<?php
session_start();
require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/RemoteBackup.php';

header('Content-Type: application/json');

// Check authentication
if (!Auth::isAuthenticated()) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$remote = new RemoteBackup();

// Handle different request methods
switch ($_SERVER['REQUEST_METHOD']) {
    case 'GET':
        // Get configuration and available methods
        $configFile = Config::get('backup_path') . '/remote_config.json';

        if (file_exists($configFile)) {
            $config = json_decode(file_get_contents($configFile), true);
        } else {
            $config = [
                'enabled' => false,
                'method' => 'ssh',
                'keep_local' => true,
                'max_retries' => 3,
                'timeout' => 3600,
                'verify_checksum' => true,
                'rate_limit_mbps' => 0,
                'servers' => []
            ];
        }

        // Get transfer history
        $history = [];
        if (isset($_GET['history'])) {
            $limit = isset($_GET['limit']) ? intval($_GET['limit']) : 50;
            $history = $remote->getTransferHistory($limit);
        }

        $response = [
            'config' => $config,
            'methods' => RemoteBackup::getAvailableMethods(),
            'features' => [
                'encryption' => true,
                'checksum_verification' => true,
                'retry_logic' => true,
                'rate_limiting' => true
            ]
        ];

        if (!empty($history)) {
            $response['history'] = $history;
        }

        echo json_encode($response);
        break;

    case 'POST':
        // Save configuration
        $input = json_decode(file_get_contents('php://input'), true);

        if (isset($input['action'])) {
            switch($input['action']) {
                case 'test':
                    // Test connection
                    if (!isset($input['server'])) {
                        echo json_encode(['success' => false, 'message' => 'No server data provided']);
                        break;
                    }

                    $result = $remote->testConnection($input['server']);
                    echo json_encode($result);
                    break;

                case 'save':
                    // Save configuration
                    if (!isset($input['config'])) {
                        echo json_encode(['success' => false, 'message' => 'No configuration data provided']);
                        break;
                    }

                    // Validate configuration
                    $errors = validateRemoteConfig($input['config']);
                    if (!empty($errors)) {
                        echo json_encode([
                            'success' => false,
                            'message' => 'Configuration validation failed',
                            'errors' => $errors
                        ]);
                        break;
                    }

                    if ($remote->saveConfig($input['config'])) {
                        echo json_encode([
                            'success' => true,
                            'message' => 'Configuration saved successfully with encrypted passwords'
                        ]);
                    } else {
                        echo json_encode(['success' => false, 'message' => 'Failed to save configuration']);
                    }
                    break;

                case 'history':
                    // Get transfer history
                    $limit = isset($input['limit']) ? intval($input['limit']) : 50;
                    $history = $remote->getTransferHistory($limit);
                    echo json_encode([
                        'success' => true,
                        'history' => $history
                    ]);
                    break;

                case 'cleanup':
                    // Clean up remote backups
                    if (!isset($input['server']) || !isset($input['retention_days'])) {
                        echo json_encode(['success' => false, 'message' => 'Missing parameters']);
                        break;
                    }

                    $result = $remote->cleanupRemote($input['server'], intval($input['retention_days']));
                    echo json_encode([
                        'success' => $result,
                        'message' => $result ? 'Cleanup completed' : 'Cleanup failed: ' . $remote->getLastError()
                    ]);
                    break;

                default:
                    echo json_encode(['success' => false, 'message' => 'Unknown action: ' . $input['action']]);
            }
        } else {
            echo json_encode(['success' => false, 'message' => 'No action specified']);
        }
        break;

    default:
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
}

/**
 * Validate remote configuration
 */
function validateRemoteConfig($config) {
    $errors = [];

    // Validate basic structure
    if (!is_array($config)) {
        $errors[] = 'Configuration must be an array';
        return $errors;
    }

    // Validate enabled flag
    if (isset($config['enabled']) && !is_bool($config['enabled'])) {
        $errors[] = 'enabled must be a boolean';
    }

    // Validate max_retries
    if (isset($config['max_retries'])) {
        $retries = intval($config['max_retries']);
        if ($retries < 1 || $retries > 10) {
            $errors[] = 'max_retries must be between 1 and 10';
        }
    }

    // Validate timeout
    if (isset($config['timeout'])) {
        $timeout = intval($config['timeout']);
        if ($timeout < 60 || $timeout > 86400) {
            $errors[] = 'timeout must be between 60 and 86400 seconds';
        }
    }

    // Validate rate_limit
    if (isset($config['rate_limit_mbps'])) {
        $rateLimit = intval($config['rate_limit_mbps']);
        if ($rateLimit < 0 || $rateLimit > 10000) {
            $errors[] = 'rate_limit_mbps must be between 0 and 10000';
        }
    }

    // Validate servers
    if (!empty($config['servers'])) {
        if (!is_array($config['servers'])) {
            $errors[] = 'servers must be an array';
        } else {
            foreach ($config['servers'] as $index => $server) {
                $serverErrors = validateServer($server, $index);
                $errors = array_merge($errors, $serverErrors);
            }
        }
    }

    return $errors;
}

/**
 * Validate individual server configuration
 */
function validateServer($server, $index) {
    $errors = [];
    $prefix = "Server $index: ";

    // Required fields
    if (empty($server['method'])) {
        $errors[] = $prefix . 'method is required';
    } elseif (!in_array($server['method'], ['ssh', 'sftp', 'ftp', 'rsync', 's3'])) {
        $errors[] = $prefix . 'invalid method';
    }

    // Validate based on method
    switch($server['method']) {
        case 'ssh':
        case 'sftp':
        case 'rsync':
            if (empty($server['host'])) {
                $errors[] = $prefix . 'host is required';
            }
            if (empty($server['user'])) {
                $errors[] = $prefix . 'user is required';
            }
            if (empty($server['path'])) {
                $errors[] = $prefix . 'path is required';
            }
            break;

        case 'ftp':
            if (empty($server['host'])) {
                $errors[] = $prefix . 'host is required';
            }
            if (empty($server['user'])) {
                $errors[] = $prefix . 'user is required';
            }
            if (empty($server['password'])) {
                $errors[] = $prefix . 'password is required for FTP';
            }
            break;

        case 's3':
            if (empty($server['bucket'])) {
                $errors[] = $prefix . 'bucket is required';
            }
            if (empty($server['access_key'])) {
                $errors[] = $prefix . 'access_key is required';
            }
            if (empty($server['secret_key'])) {
                $errors[] = $prefix . 'secret_key is required';
            }
            break;
    }

    return $errors;
}
?>