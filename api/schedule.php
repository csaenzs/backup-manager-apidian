<?php
session_start();
require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/auth.php';

// Check authentication
if (!Auth::isAuthenticated()) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$cronFile = Config::get('backup_path') . '/schedule_config.json';

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // Get current schedule
    $schedule = ['enabled' => false];

    if (file_exists($cronFile)) {
        $schedule = json_decode(file_get_contents($cronFile), true) ?: ['enabled' => false];
    }

    header('Content-Type: application/json');
    echo json_encode($schedule);

} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Update schedule
    $input = json_decode(file_get_contents('php://input'), true);

    $enabled = $input['enabled'] ?? false;
    $frequency = $input['frequency'] ?? 'daily';
    $time = $input['time'] ?? '03:00';
    $type = $input['type'] ?? 'full';

    // Save schedule configuration
    $schedule = [
        'enabled' => $enabled,
        'frequency' => $frequency,
        'time' => $time,
        'type' => $type,
        'updated_at' => date('Y-m-d H:i:s')
    ];

    if (!file_put_contents($cronFile, json_encode($schedule, JSON_PRETTY_PRINT))) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'No se pudo guardar la configuración']);
        exit;
    }
    
    // Update crontab
    $cronCommand = '';
    $message = '';

    if ($enabled) {
        list($hour, $minute) = explode(':', $time);
        $hour = intval($hour);
        $minute = intval($minute);

        // Build cron expression based on frequency
        switch ($frequency) {
            case 'daily':
                $cronExpr = "$minute $hour * * *";
                $freqText = "diariamente";
                break;
            case 'weekly':
                $cronExpr = "$minute $hour * * 0"; // Sunday
                $freqText = "semanalmente (domingos)";
                break;
            case 'monthly':
                $cronExpr = "$minute $hour 1 * *"; // First day of month
                $freqText = "mensualmente (día 1)";
                break;
            default:
                $cronExpr = "$minute $hour * * *";
                $freqText = "diariamente";
        }

        $phpPath = PHP_BINARY ?: '/usr/bin/php';
        $scriptPath = __DIR__ . '/backup_worker_enhanced.php';
        $logPath = dirname(__DIR__) . '/logs/cron_backup.log';

        // Usar el tipo seleccionado (full o incremental)
        $cronJob = "$cronExpr $phpPath $scriptPath $type >> $logPath 2>&1";
        $cronCommand = $cronJob;

        // Get current crontab
        exec('crontab -l 2>/dev/null', $currentCron, $returnCode);

        // Si no hay crontab, inicializar array vacío
        if ($returnCode !== 0) {
            $currentCron = [];
        }

        // Remove any existing backup-manager entries
        $newCron = array_filter($currentCron, function($line) {
            return strpos($line, 'backup_worker') === false && strpos($line, 'Backup Manager') === false;
        });

        // Add new entry
        $newCron[] = "# Backup Manager - Automatic Backup ($freqText a las $time)";
        $newCron[] = $cronJob;

        // Write new crontab
        $tempFile = tempnam('/tmp', 'cron');
        file_put_contents($tempFile, implode("\n", $newCron) . "\n");
        exec("crontab $tempFile 2>&1", $output, $cronReturnCode);
        unlink($tempFile);

        if ($cronReturnCode !== 0) {
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'message' => 'Error al actualizar crontab: ' . implode("\n", $output)
            ]);
            exit;
        }

        $message = "Backup $freqText programado para las $time (tipo: $type)";

    } else {
        // Remove from crontab
        exec('crontab -l 2>/dev/null', $currentCron, $returnCode);

        if ($returnCode === 0) {
            $newCron = array_filter($currentCron, function($line) {
                return strpos($line, 'backup_worker') === false && strpos($line, 'Backup Manager') === false;
            });

            if (count($newCron) > 0) {
                $tempFile = tempnam('/tmp', 'cron');
                file_put_contents($tempFile, implode("\n", $newCron) . "\n");
                exec("crontab $tempFile 2>&1");
                unlink($tempFile);
            } else {
                // Si no quedan entradas, limpiar crontab
                exec("crontab -r 2>&1");
            }
        }

        $message = "Backups automáticos deshabilitados";
    }

    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'message' => $message,
        'cron_command' => $cronCommand,
        'schedule' => $schedule
    ]);
} else {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
}