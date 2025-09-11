<?php
session_start();
require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/auth.php';

header('Content-Type: application/json');

// Check authentication
if (!Auth::isAuthenticated()) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized', 'success' => false]);
    exit;
}

// Only allow POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed', 'success' => false]);
    exit;
}

// Get request data
$input = json_decode(file_get_contents('php://input'), true);

if (!$input || !isset($input['current_password']) || !isset($input['new_password'])) {
    echo json_encode(['error' => 'Missing required fields', 'success' => false]);
    exit;
}

$currentPassword = $input['current_password'];
$newPassword = $input['new_password'];

// Validate current password
if (!Auth::login($currentPassword)) {
    echo json_encode(['error' => 'Contraseña actual incorrecta', 'success' => false]);
    exit;
}

// Validate new password
if (strlen($newPassword) < 8) {
    echo json_encode(['error' => 'La nueva contraseña debe tener al menos 8 caracteres', 'success' => false]);
    exit;
}

if ($newPassword === $currentPassword) {
    echo json_encode(['error' => 'La nueva contraseña debe ser diferente a la actual', 'success' => false]);
    exit;
}

// Additional security checks
if (preg_match('/^(.)\1+$/', $newPassword)) {
    echo json_encode(['error' => 'La contraseña no puede contener solo caracteres repetidos', 'success' => false]);
    exit;
}

$commonPasswords = ['12345678', 'password', 'admin123', '11111111', '00000000', 'password123', 'admin'];
if (in_array(strtolower($newPassword), $commonPasswords)) {
    echo json_encode(['error' => 'Por favor usa una contraseña más segura', 'success' => false]);
    exit;
}

try {
    // Hash the new password
    $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
    
    // Update the configuration
    Config::set('admin_password', $hashedPassword);
    
    // Log the password change for security audit
    $logMessage = date('Y-m-d H:i:s') . " - Password changed from IP: " . ($_SERVER['REMOTE_ADDR'] ?? 'unknown') . "\n";
    file_put_contents(__DIR__ . '/../logs/security.log', $logMessage, FILE_APPEND | LOCK_EX);
    
    // Force logout after password change for security
    session_destroy();
    
    echo json_encode([
        'success' => true,
        'message' => 'Contraseña cambiada exitosamente'
    ]);
    
} catch (Exception $e) {
    error_log("Password change failed: " . $e->getMessage());
    echo json_encode([
        'error' => 'Error interno del servidor',
        'success' => false
    ]);
}
?>