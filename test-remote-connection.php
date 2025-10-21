#!/usr/bin/env php
<?php
/**
 * Script de prueba para conexión remota
 * Uso: php test-remote-connection.php
 */

echo "\n";
echo "=====================================\n";
echo "   TEST DE CONEXIÓN REMOTA          \n";
echo "=====================================\n\n";

// Solicitar datos
echo "Host/IP: ";
$host = trim(fgets(STDIN));

echo "Puerto (default 22): ";
$port = trim(fgets(STDIN));
$port = empty($port) ? 22 : intval($port);

echo "Usuario: ";
$user = trim(fgets(STDIN));

echo "Contraseña: ";
system('stty -echo');
$password = trim(fgets(STDIN));
system('stty echo');
echo "\n\n";

echo "📡 Probando conexión...\n\n";

// Test 1: Verificar conectividad
echo "1️⃣  Verificando conectividad al puerto SSH...\n";
$cmd = "timeout 5 nc -zv $host $port 2>&1";
exec($cmd, $output, $returnCode);

if ($returnCode === 0) {
    echo "   ✅ Puerto SSH accesible\n\n";
} else {
    echo "   ❌ No se puede conectar al puerto SSH\n";
    echo "   Error: " . implode("\n", $output) . "\n";
    exit(1);
}

// Test 2: Verificar sshpass
echo "2️⃣  Verificando sshpass...\n";
$sshpass = trim(shell_exec("which sshpass 2>/dev/null"));

if (empty($sshpass)) {
    echo "   ❌ sshpass no está instalado\n";
    echo "   Instalar con: apt-get install sshpass\n";
    exit(1);
} else {
    echo "   ✅ sshpass instalado: $sshpass\n\n";
}

// Test 3: Probar autenticación
echo "3️⃣  Probando autenticación SSH con contraseña...\n";

$cmd = sprintf(
    "SSHPASS=%s sshpass -e ssh -o StrictHostKeyChecking=no -o ConnectTimeout=10 %s@%s -p %d 'echo OK' 2>&1",
    escapeshellarg($password),
    escapeshellarg($user),
    escapeshellarg($host),
    $port
);

exec($cmd, $output, $returnCode);

if ($returnCode === 0) {
    echo "   ✅ AUTENTICACIÓN EXITOSA\n";
    echo "   El servidor respondió: " . implode("\n", $output) . "\n\n";

    echo "=====================================\n";
    echo "✅ TODAS LAS PRUEBAS PASARON\n";
    echo "=====================================\n\n";
    echo "Ahora puedes configurar el backup remoto desde la interfaz web.\n\n";

} else {
    echo "   ❌ AUTENTICACIÓN FALLÓ\n\n";
    echo "Salida del comando:\n";
    echo "-------------------\n";
    echo implode("\n", $output) . "\n";
    echo "-------------------\n\n";

    echo "Posibles causas:\n";
    echo "1. Contraseña incorrecta\n";
    echo "2. Usuario no existe o no tiene acceso SSH\n";
    echo "3. Servidor no permite autenticación con contraseña\n";
    echo "4. Firewall bloqueando la conexión\n\n";

    echo "Verifica:\n";
    echo "- Que la contraseña sea correcta\n";
    echo "- Que el usuario '$user' exista en el servidor remoto\n";
    echo "- Que /etc/ssh/sshd_config en el servidor remoto tenga:\n";
    echo "  PasswordAuthentication yes\n\n";
}

// Test 4: Verificar acceso al directorio
if ($returnCode === 0) {
    echo "4️⃣  Verificando acceso al directorio /home...\n";

    $cmd = sprintf(
        "SSHPASS=%s sshpass -e ssh -o StrictHostKeyChecking=no %s@%s -p %d 'ls -la /home' 2>&1",
        escapeshellarg($password),
        escapeshellarg($user),
        escapeshellarg($host),
        $port
    );

    exec($cmd, $dirOutput, $dirReturn);

    if ($dirReturn === 0) {
        echo "   ✅ Acceso al directorio /home confirmado\n";
        echo "   Contenido:\n";
        foreach (array_slice($dirOutput, 0, 5) as $line) {
            echo "   " . $line . "\n";
        }
        echo "\n";
    } else {
        echo "   ⚠️  No se pudo acceder al directorio /home\n";
        echo "   Considera usar otro directorio como /var/backups\n\n";
    }
}

echo "\n";
?>
