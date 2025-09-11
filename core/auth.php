<?php
/**
 * Simple authentication system
 */

class Auth {
    public static function login($password) {
        $storedPassword = Config::get('admin_password');
        
        // For first time setup, accept 'admin123' or check against stored hash
        if ($password === 'admin123' && !file_exists(__DIR__ . '/../config.local.php')) {
            return true;
        }
        
        return password_verify($password, $storedPassword);
    }
    
    public static function logout() {
        session_destroy();
        header('Location: index.php');
        exit;
    }
    
    public static function changePassword($newPassword) {
        $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
        Config::set('admin_password', $hashedPassword);
        return true;
    }
    
    public static function isAuthenticated() {
        return isset($_SESSION['authenticated']) && $_SESSION['authenticated'] === true;
    }
}

// Handle logout
if (isset($_GET['logout'])) {
    Auth::logout();
}