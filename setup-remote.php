<?php
/**
 * Quick setup script for remote backup configuration
 * Run: php setup-remote.php
 */

require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/RemoteBackup.php';

echo "\n";
echo "==============================================\n";
echo "     CONFIGURACIÓN DE BACKUP REMOTO          \n";
echo "==============================================\n\n";

// Check available methods
echo "Métodos disponibles:\n";
$methods = RemoteBackup::getAvailableMethods();
foreach ($methods as $method) {
    $status = $method['available'] ? '✓' : '✗';
    echo "  [$status] {$method['name']} ({$method['id']})\n";
}
echo "\n";

// Get user input
echo "Selecciona el método de transferencia:\n";
echo "  1. SSH/SCP (recomendado)\n";
echo "  2. FTP\n";
echo "  3. Rsync\n";
echo "  4. S3 Compatible\n";
echo "  5. Cancelar\n\n";
echo "Opción: ";
$option = trim(fgets(STDIN));

$config = [
    'enabled' => true,
    'keep_local' => true,
    'servers' => []
];

switch($option) {
    case '1':
        $server = setupSSH();
        break;
    case '2':
        $server = setupFTP();
        break;
    case '3':
        $server = setupRsync();
        break;
    case '4':
        $server = setupS3();
        break;
    case '5':
        echo "Configuración cancelada.\n";
        exit;
    default:
        echo "Opción inválida.\n";
        exit;
}

// Test connection
echo "\n¿Deseas probar la conexión? (s/n): ";
$test = strtolower(trim(fgets(STDIN)));

if ($test === 's' || $test === 'si') {
    echo "Probando conexión...\n";
    $remote = new RemoteBackup();
    $result = $remote->testConnection($server);

    if ($result['success']) {
        echo "✅ " . $result['message'] . "\n";
    } else {
        echo "❌ " . $result['message'] . "\n";
        echo "\n¿Guardar configuración de todos modos? (s/n): ";
        $save = strtolower(trim(fgets(STDIN)));
        if ($save !== 's' && $save !== 'si') {
            echo "Configuración cancelada.\n";
            exit;
        }
    }
}

// Keep local copies?
echo "\n¿Mantener copias locales después de transferir? (s/n): ";
$keepLocal = strtolower(trim(fgets(STDIN)));
$config['keep_local'] = ($keepLocal === 's' || $keepLocal === 'si');

// Save configuration
$config['method'] = $server['method'];
$config['servers'][] = $server;

$configFile = Config::get('backup_path') . '/remote_config.json';
if (file_put_contents($configFile, json_encode($config, JSON_PRETTY_PRINT))) {
    echo "\n✅ Configuración guardada exitosamente.\n";
    echo "📁 Archivo: $configFile\n";

    if (!$config['keep_local']) {
        echo "⚠️  ADVERTENCIA: Los backups locales se eliminarán después de transferir.\n";
    }
} else {
    echo "\n❌ Error al guardar la configuración.\n";
}

echo "\n";

// Functions for setup
function setupSSH() {
    echo "\n--- Configuración SSH/SCP ---\n\n";

    $server = [
        'id' => uniqid('ssh_'),
        'method' => 'ssh',
        'active' => true
    ];

    echo "Host/IP del servidor: ";
    $server['host'] = trim(fgets(STDIN));

    echo "Puerto SSH (default 22): ";
    $port = trim(fgets(STDIN));
    $server['port'] = $port ?: 22;

    echo "Usuario SSH: ";
    $server['user'] = trim(fgets(STDIN));

    echo "Método de autenticación:\n";
    echo "  1. Contraseña\n";
    echo "  2. Clave SSH\n";
    echo "Opción: ";
    $authMethod = trim(fgets(STDIN));

    if ($authMethod === '2') {
        echo "Ruta al archivo de clave privada (ej: /root/.ssh/id_rsa): ";
        $server['key_file'] = trim(fgets(STDIN));

        if (!file_exists($server['key_file'])) {
            echo "⚠️  Advertencia: El archivo de clave no existe.\n";
        }
    } else {
        echo "Contraseña (se guardará en texto plano - considera usar claves SSH): ";
        system('stty -echo');
        $server['password'] = trim(fgets(STDIN));
        system('stty echo');
        echo "\n";
    }

    echo "Directorio remoto para backups (ej: /backup/apidian): ";
    $server['path'] = trim(fgets(STDIN));

    return $server;
}

function setupFTP() {
    echo "\n--- Configuración FTP ---\n\n";

    $server = [
        'id' => uniqid('ftp_'),
        'method' => 'ftp',
        'active' => true
    ];

    echo "Host FTP: ";
    $server['host'] = trim(fgets(STDIN));

    echo "Puerto FTP (default 21): ";
    $port = trim(fgets(STDIN));
    $server['port'] = $port ?: 21;

    echo "Usuario FTP: ";
    $server['user'] = trim(fgets(STDIN));

    echo "Contraseña FTP: ";
    system('stty -echo');
    $server['password'] = trim(fgets(STDIN));
    system('stty echo');
    echo "\n";

    echo "Directorio remoto (dejar vacío para raíz): ";
    $server['path'] = trim(fgets(STDIN));

    return $server;
}

function setupRsync() {
    echo "\n--- Configuración Rsync ---\n\n";

    $server = [
        'id' => uniqid('rsync_'),
        'method' => 'rsync',
        'active' => true
    ];

    echo "¿Usar Rsync sobre SSH? (s/n): ";
    $useSSH = strtolower(trim(fgets(STDIN)));
    $server['ssh'] = ($useSSH === 's' || $useSSH === 'si');

    echo "Host del servidor: ";
    $server['host'] = trim(fgets(STDIN));

    if ($server['ssh']) {
        echo "Puerto SSH (default 22): ";
        $port = trim(fgets(STDIN));
        $server['port'] = $port ?: 22;

        echo "¿Usar clave SSH? (s/n): ";
        $useKey = strtolower(trim(fgets(STDIN)));

        if ($useKey === 's' || $useKey === 'si') {
            echo "Ruta al archivo de clave privada: ";
            $server['key_file'] = trim(fgets(STDIN));
        }
    }

    echo "Usuario: ";
    $server['user'] = trim(fgets(STDIN));

    echo "Directorio remoto: ";
    $server['path'] = trim(fgets(STDIN));

    return $server;
}

function setupS3() {
    echo "\n--- Configuración S3 Compatible ---\n\n";

    $server = [
        'id' => uniqid('s3_'),
        'method' => 's3',
        'active' => true
    ];

    echo "¿Usar AWS S3 o compatible? (aws/compatible): ";
    $type = strtolower(trim(fgets(STDIN)));

    if ($type === 'compatible') {
        echo "Endpoint URL (ej: https://s3.example.com): ";
        $server['endpoint'] = trim(fgets(STDIN));
    }

    echo "Nombre del bucket: ";
    $server['bucket'] = trim(fgets(STDIN));

    echo "Directorio dentro del bucket (ej: backups/apidian): ";
    $server['path'] = trim(fgets(STDIN));

    echo "Access Key ID: ";
    $server['access_key'] = trim(fgets(STDIN));

    echo "Secret Access Key: ";
    system('stty -echo');
    $server['secret_key'] = trim(fgets(STDIN));
    system('stty echo');
    echo "\n";

    echo "Región (default us-east-1): ";
    $region = trim(fgets(STDIN));
    $server['region'] = $region ?: 'us-east-1';

    return $server;
}

echo "Configuración completa. Puedes hacer un backup de prueba para verificar.\n";
?>