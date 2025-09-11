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

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // Get current settings
    $settings = [
        'retention_days' => Config::get('retention_days', 30),
        'compression' => Config::get('compression', 'medium'),
        'backup_destination' => Config::get('backup_path'),
        'notification_email' => Config::get('notification_email', '')
    ];
    
    header('Content-Type: application/json');
    echo json_encode($settings);
    
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Update settings
    $input = json_decode(file_get_contents('php://input'), true);
    
    // Validate and save settings
    if (isset($input['retention_days'])) {
        $days = intval($input['retention_days']);
        if ($days > 0 && $days <= 365) {
            Config::set('retention_days', $days);
        }
    }
    
    if (isset($input['compression'])) {
        $validLevels = ['none', 'low', 'medium', 'high'];
        if (in_array($input['compression'], $validLevels)) {
            Config::set('compression', $input['compression']);
        }
    }
    
    if (isset($input['backup_destination'])) {
        $path = $input['backup_destination'];
        // Ensure path exists and is writable
        if (!is_dir($path)) {
            mkdir($path, 0755, true);
        }
        if (is_writable($path)) {
            Config::set('backup_path', $path);
        }
    }
    
    if (isset($input['notification_email'])) {
        $email = filter_var($input['notification_email'], FILTER_VALIDATE_EMAIL);
        if ($email || empty($input['notification_email'])) {
            Config::set('notification_email', $input['notification_email']);
        }
    }
    
    header('Content-Type: application/json');
    echo json_encode(['success' => true]);
} else {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
}