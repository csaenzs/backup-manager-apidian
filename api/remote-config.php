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
        $config = json_decode(file_get_contents(Config::get('backup_path') . '/remote_config.json'), true);
        if (!$config) {
            $config = [
                'enabled' => false,
                'method' => 'ssh',
                'keep_local' => true,
                'servers' => []
            ];
        }

        echo json_encode([
            'config' => $config,
            'methods' => RemoteBackup::getAvailableMethods()
        ]);
        break;

    case 'POST':
        // Save configuration
        $input = json_decode(file_get_contents('php://input'), true);

        if (isset($input['action'])) {
            switch($input['action']) {
                case 'test':
                    // Test connection
                    $result = $remote->testConnection($input['server']);
                    echo json_encode($result);
                    break;

                case 'save':
                    // Save configuration
                    if ($remote->saveConfig($input['config'])) {
                        echo json_encode(['success' => true, 'message' => 'Configuration saved']);
                    } else {
                        echo json_encode(['success' => false, 'message' => 'Failed to save configuration']);
                    }
                    break;

                default:
                    echo json_encode(['success' => false, 'message' => 'Unknown action']);
            }
        } else {
            echo json_encode(['success' => false, 'message' => 'No action specified']);
        }
        break;

    default:
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
}
?>