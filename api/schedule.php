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

$cronFile = '/tmp/backup_manager_cron.txt';

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
    $time = $input['time'] ?? '01:00';
    
    // Save schedule configuration
    $schedule = [
        'enabled' => $enabled,
        'frequency' => $frequency,
        'time' => $time
    ];
    
    file_put_contents($cronFile, json_encode($schedule));
    
    // Update crontab
    if ($enabled) {
        list($hour, $minute) = explode(':', $time);
        $hour = intval($hour);
        $minute = intval($minute);
        
        // Build cron expression based on frequency
        switch ($frequency) {
            case 'daily':
                $cronExpr = "$minute $hour * * *";
                break;
            case 'weekly':
                $cronExpr = "$minute $hour * * 0"; // Sunday
                break;
            case 'monthly':
                $cronExpr = "$minute $hour 1 * *"; // First day of month
                break;
            default:
                $cronExpr = "$minute $hour * * *";
        }
        
        $phpPath = PHP_BINARY ?: '/usr/bin/php';
        $scriptPath = __DIR__ . '/backup_worker.php';
        $cronJob = "$cronExpr $phpPath $scriptPath full > /dev/null 2>&1";
        
        // Get current crontab
        exec('crontab -l 2>/dev/null', $currentCron);
        
        // Remove any existing backup-manager entries
        $newCron = array_filter($currentCron, function($line) {
            return !strpos($line, 'backup_worker.php');
        });
        
        // Add new entry
        $newCron[] = "# Backup Manager - Automatic Backup";
        $newCron[] = $cronJob;
        
        // Write new crontab
        $tempFile = tempnam('/tmp', 'cron');
        file_put_contents($tempFile, implode("\n", $newCron) . "\n");
        exec("crontab $tempFile 2>&1", $output, $returnCode);
        unlink($tempFile);
        
        if ($returnCode !== 0) {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Failed to update crontab']);
            exit;
        }
    } else {
        // Remove from crontab
        exec('crontab -l 2>/dev/null', $currentCron);
        $newCron = array_filter($currentCron, function($line) {
            return !strpos($line, 'backup_worker.php') && !strpos($line, 'Backup Manager');
        });
        
        $tempFile = tempnam('/tmp', 'cron');
        file_put_contents($tempFile, implode("\n", $newCron) . "\n");
        exec("crontab $tempFile 2>&1");
        unlink($tempFile);
    }
    
    header('Content-Type: application/json');
    echo json_encode(['success' => true]);
} else {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
}