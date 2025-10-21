<?php
session_start();
require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/auth.php';

header('Content-Type: application/json');

// Only allow POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed', 'success' => false]);
    exit;
}

// Get request data
$input = json_decode(file_get_contents('php://input'), true);

if (!$input || !isset($input['password'])) {
    echo json_encode(['error' => 'Password required', 'success' => false]);
    exit;
}

$password = $input['password'];

if (Auth::login($password)) {
    $_SESSION['authenticated'] = true;
    echo json_encode([
        'success' => true,
        'message' => 'Login successful'
    ]);
} else {
    echo json_encode([
        'error' => 'Invalid password',
        'success' => false
    ]);
}
?>