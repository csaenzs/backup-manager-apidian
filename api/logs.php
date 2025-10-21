<?php
session_start();
require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/auth.php';

// Check authentication
if (!Auth::isAuthenticated()) {
    http_response_code(401);
    echo "Unauthorized";
    exit;
}

$logType = $_GET['type'] ?? 'backup';
$logDate = $_GET['date'] ?? date('Y-m-d');
$download = isset($_GET['download']);

$logPath = Config::get('log_path', '/var/www/html/backup-manager/logs');
$logContent = '';

// Determine which log file to read
switch($logType) {
    case 'backup':
        $logFile = $logPath . '/backup_' . $logDate . '.log';
        break;
    case 'error':
        $logFile = '/var/log/apache2/backup-manager-error.log';
        break;
    case 'security':
        $logFile = $logPath . '/security.log';
        break;
    case 'remote':
        $logFile = $logPath . '/remote_backup.log';
        break;
    case 'all':
        // Combine all logs
        $files = [
            $logPath . '/backup_' . $logDate . '.log',
            $logPath . '/security.log',
            $logPath . '/remote_backup.log'
        ];
        foreach($files as $file) {
            if (file_exists($file)) {
                $logContent .= "\n=== " . basename($file) . " ===\n";
                $logContent .= file_get_contents($file);
            }
        }
        break;
    default:
        $logFile = $logPath . '/backup_' . $logDate . '.log';
}

// Read the log file if not already read
if (empty($logContent) && isset($logFile) && file_exists($logFile)) {
    $logContent = file_get_contents($logFile);

    // Limit size for display (last 100 lines)
    if (!$download) {
        $lines = explode("\n", $logContent);
        $lines = array_slice($lines, -100);
        $logContent = implode("\n", $lines);
    }
} elseif (empty($logContent)) {
    $logContent = "No hay logs disponibles para esta fecha/tipo.";
}

// Handle download
if ($download) {
    header('Content-Type: text/plain');
    header('Content-Disposition: attachment; filename="' . $logType . '_' . $logDate . '.log"');
    echo $logContent;
    exit;
}

// Return content for display
header('Content-Type: text/plain');
echo $logContent;
?>