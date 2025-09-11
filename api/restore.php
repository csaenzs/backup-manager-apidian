<?php
session_start();
require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/auth.php';

header('Content-Type: application/json');

// Check authentication
if (!Auth::isAuthenticated()) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized', 'status' => 'error']);
    exit;
}

// Only allow POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed', 'status' => 'error']);
    exit;
}

// Get request data
$input = json_decode(file_get_contents('php://input'), true);

if (!$input || !isset($input['id'])) {
    echo json_encode(['error' => 'Missing backup ID', 'status' => 'error']);
    exit;
}

$backupId = $input['id'];

// Load backup history to find the backup
$historyFile = Config::get('backup_path') . '/history.json';
if (!file_exists($historyFile)) {
    echo json_encode(['error' => 'No backup history found', 'status' => 'error']);
    exit;
}

$history = json_decode(file_get_contents($historyFile), true);
if (!$history) {
    echo json_encode(['error' => 'Invalid backup history', 'status' => 'error']);
    exit;
}

// Find the backup
$backup = null;
foreach ($history as $item) {
    if ($item['id'] === $backupId) {
        $backup = $item;
        break;
    }
}

if (!$backup) {
    echo json_encode(['error' => 'Backup not found', 'status' => 'error']);
    exit;
}

// Generate restore instructions based on backup type
$instructions = generateRestoreInstructions($backup);

echo json_encode([
    'status' => 'ready',
    'message' => 'Backup preparado para restauración',
    'instructions' => $instructions,
    'backup' => $backup
]);

/**
 * Generate restore instructions based on backup type
 */
function generateRestoreInstructions($backup) {
    $dbUser = Config::get('db_user');
    $dbName = Config::get('db_name');
    $backupPath = Config::get('backup_path');
    
    $instructions = "# INSTRUCCIONES DE RESTAURACIÓN\n";
    $instructions .= "# Backup ID: {$backup['id']}\n";
    $instructions .= "# Fecha: {$backup['date']}\n";
    $instructions .= "# Tipo: {$backup['type']}\n\n";
    
    // Safety backup first
    $instructions .= "# 1. CREAR BACKUP DE SEGURIDAD (MUY IMPORTANTE)\n";
    $instructions .= "mysqldump -u {$dbUser} -p {$dbName} > backup_seguridad_$(date +%Y%m%d_%H%M%S).sql\n\n";
    
    if ($backup['type'] === 'database' || $backup['type'] === 'full') {
        if (isset($backup['strategy']) && $backup['strategy'] === 'incremental') {
            // Incremental backup - need to find base backup and all incrementals
            $instructions .= "# 2. RESTAURACIÓN INCREMENTAL\n";
            $instructions .= "# ADVERTENCIA: Necesitas el backup base Y todos los incrementales en orden\n\n";
            
            $instructions .= "# Opción A: Usar script automático (RECOMENDADO)\n";
            $instructions .= "cd /var/www/html/backup-manager\n";
            $instructions .= "./restore_incremental.sh " . substr($backup['id'], 0, 8) . "\n\n";
            
            $instructions .= "# Opción B: Manual\n";
            $instructions .= "# 2a. Restaurar backup base completo (buscar el full más reciente antes de este incremental)\n";
            $instructions .= "# mysql -u {$dbUser} -p {$dbName} < {$backupPath}/backup_YYYYMMDD_HHMMSS_full.sql\n\n";
            
            $instructions .= "# 2b. Aplicar TODOS los incrementales en orden cronológico hasta este:\n";
            foreach ($backup['files'] as $file) {
                if (file_exists($file)) {
                    $filename = basename($file);
                    if (strpos($filename, '.gz') !== false) {
                        $instructions .= "gunzip -c {$file} | mysql -u {$dbUser} -p {$dbName}\n";
                    } else {
                        $instructions .= "mysql -u {$dbUser} -p {$dbName} < {$file}\n";
                    }
                }
            }
        } else {
            // Full backup
            $instructions .= "# 2. RESTAURACIÓN COMPLETA\n";
            foreach ($backup['files'] as $file) {
                if (file_exists($file)) {
                    $filename = basename($file);
                    if (strpos($filename, '.gz') !== false) {
                        $instructions .= "gunzip -c {$file} | mysql -u {$dbUser} -p {$dbName}\n";
                    } else {
                        $instructions .= "mysql -u {$dbUser} -p {$dbName} < {$file}\n";
                    }
                }
            }
        }
    } else if ($backup['type'] === 'storage') {
        $instructions .= "# 2. RESTAURACIÓN DE STORAGE\n";
        $storagePath = Config::get('storage_path');
        
        foreach ($backup['files'] as $file) {
            if (file_exists($file)) {
                if (strpos($file, '.tar.gz') !== false) {
                    $instructions .= "tar -xzf {$file} -C " . dirname($storagePath) . "\n";
                } else if (strpos($file, '.tar') !== false) {
                    $instructions .= "tar -xf {$file} -C " . dirname($storagePath) . "\n";
                }
            }
        }
    } else if ($backup['type'] === 'full') {
        $instructions .= "# 2. RESTAURACIÓN COMPLETA (DB + STORAGE)\n";
        
        // Database files
        $instructions .= "# Restaurar base de datos:\n";
        foreach ($backup['files'] as $file) {
            if (strpos($file, '_db_') !== false || strpos($file, 'database') !== false) {
                if (strpos($file, '.gz') !== false) {
                    $instructions .= "gunzip -c {$file} | mysql -u {$dbUser} -p {$dbName}\n";
                } else {
                    $instructions .= "mysql -u {$dbUser} -p {$dbName} < {$file}\n";
                }
            }
        }
        
        $instructions .= "\n# Restaurar storage:\n";
        $storagePath = Config::get('storage_path');
        foreach ($backup['files'] as $file) {
            if (strpos($file, '_storage_') !== false || strpos($file, 'storage') !== false) {
                if (strpos($file, '.tar.gz') !== false) {
                    $instructions .= "tar -xzf {$file} -C " . dirname($storagePath) . "\n";
                } else if (strpos($file, '.tar') !== false) {
                    $instructions .= "tar -xf {$file} -C " . dirname($storagePath) . "\n";
                }
            }
        }
    }
    
    $instructions .= "\n# 3. VERIFICAR RESTAURACIÓN\n";
    $instructions .= "mysql -u {$dbUser} -p {$dbName} -e 'SHOW TABLES;'\n";
    if ($backup['type'] !== 'database') {
        $storagePath = Config::get('storage_path');
        $instructions .= "ls -la {$storagePath}\n";
    }
    
    $instructions .= "\n# IMPORTANTE:\n";
    $instructions .= "# - Ejecutar estos comandos en el servidor donde quieres restaurar\n";
    $instructions .= "# - Asegúrate de tener permisos de escritura\n";
    $instructions .= "# - El backup de seguridad te permite revertir si algo sale mal\n";
    
    return $instructions;
}
?>