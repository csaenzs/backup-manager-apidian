# 🌐 Configuración de Backup Remoto

El sistema soporta múltiples métodos para transferir backups a servidores remotos.

## 📋 Métodos Soportados

### 1. SSH/SCP (Recomendado)
- ✅ Seguro y cifrado
- ✅ Autenticación con clave SSH
- ✅ No requiere software adicional

### 2. FTP
- ⚠️ No cifrado (usar solo en redes seguras)
- ✅ Compatible con la mayoría de servidores
- ✅ Simple de configurar

### 3. Rsync
- ✅ Transferencias incrementales
- ✅ Muy eficiente para archivos grandes
- ✅ Puede usar SSH para seguridad

### 4. S3 Compatible
- ✅ Amazon S3, DigitalOcean Spaces, MinIO
- ✅ Alta disponibilidad
- ⚠️ Requiere AWS CLI instalado

## 🚀 Configuración Rápida

### Opción 1: Script Interactivo
```bash
php /var/www/html/backup-manager/setup-remote.php
```

### Opción 2: Configuración Manual

Crear archivo `/var/www/html/backup-manager/backups/remote_config.json`:

#### Ejemplo SSH con Clave
```json
{
    "enabled": true,
    "method": "ssh",
    "keep_local": true,
    "servers": [
        {
            "id": "ssh_main",
            "method": "ssh",
            "active": true,
            "host": "backup.ejemplo.com",
            "port": 22,
            "user": "backup_user",
            "key_file": "/root/.ssh/id_rsa",
            "path": "/backups/apidian"
        }
    ]
}
```

#### Ejemplo SSH con Contraseña
```json
{
    "enabled": true,
    "method": "ssh",
    "keep_local": false,
    "servers": [
        {
            "id": "ssh_pass",
            "method": "ssh",
            "active": true,
            "host": "192.168.1.100",
            "port": 22,
            "user": "root",
            "password": "tu_contraseña",
            "path": "/var/backups"
        }
    ]
}
```

#### Ejemplo FTP
```json
{
    "enabled": true,
    "method": "ftp",
    "keep_local": true,
    "servers": [
        {
            "id": "ftp_main",
            "method": "ftp",
            "active": true,
            "host": "ftp.ejemplo.com",
            "port": 21,
            "user": "ftp_user",
            "password": "ftp_password",
            "path": "/public_html/backups"
        }
    ]
}
```

#### Ejemplo Rsync sobre SSH
```json
{
    "enabled": true,
    "method": "rsync",
    "keep_local": true,
    "servers": [
        {
            "id": "rsync_ssh",
            "method": "rsync",
            "active": true,
            "ssh": true,
            "host": "backup.ejemplo.com",
            "port": 22,
            "user": "backup_user",
            "key_file": "/root/.ssh/id_rsa",
            "path": "/backup/apidian"
        }
    ]
}
```

#### Ejemplo S3 (AWS)
```json
{
    "enabled": true,
    "method": "s3",
    "keep_local": false,
    "servers": [
        {
            "id": "s3_aws",
            "method": "s3",
            "active": true,
            "bucket": "mi-bucket-backups",
            "path": "apidian/backups",
            "access_key": "AKIAIOSFODNN7EXAMPLE",
            "secret_key": "wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY",
            "region": "us-east-1"
        }
    ]
}
```

#### Ejemplo S3 Compatible (DigitalOcean Spaces)
```json
{
    "enabled": true,
    "method": "s3",
    "keep_local": true,
    "servers": [
        {
            "id": "s3_spaces",
            "method": "s3",
            "active": true,
            "endpoint": "https://nyc3.digitaloceanspaces.com",
            "bucket": "mi-space",
            "path": "backups",
            "access_key": "DO_ACCESS_KEY",
            "secret_key": "DO_SECRET_KEY",
            "region": "nyc3"
        }
    ]
}
```

## 🔐 Configuración de Autenticación SSH

### Generar Clave SSH (si no tienes una)
```bash
ssh-keygen -t rsa -b 4096 -f /root/.ssh/backup_rsa
```

### Copiar Clave al Servidor Remoto
```bash
ssh-copy-id -i /root/.ssh/backup_rsa.pub usuario@servidor.com
```

### Probar Conexión
```bash
ssh -i /root/.ssh/backup_rsa usuario@servidor.com
```

## 🧪 Probar Configuración

### Test Manual
```bash
# Test SSH
ssh usuario@servidor.com "echo 'Conexión exitosa'"

# Test SCP
echo "test" > /tmp/test.txt
scp /tmp/test.txt usuario@servidor.com:/ruta/destino/

# Test Rsync
rsync -avz --dry-run /var/www/html/backup-manager/backups/ usuario@servidor.com:/backup/
```

### Test desde el Sistema
```php
<?php
require_once '/var/www/html/backup-manager/core/config.php';
require_once '/var/www/html/backup-manager/core/RemoteBackup.php';

$remote = new RemoteBackup();
$server = [
    'method' => 'ssh',
    'host' => 'tu_servidor.com',
    'user' => 'tu_usuario',
    'key_file' => '/root/.ssh/id_rsa',
    'path' => '/backup'
];

$result = $remote->testConnection($server);
echo $result['success'] ? "✅ Conexión exitosa\n" : "❌ Error: {$result['message']}\n";
?>
```

## 📊 Opciones de Configuración

| Opción | Descripción | Valores |
|--------|-------------|---------|
| `enabled` | Activar backup remoto | true/false |
| `keep_local` | Mantener copia local después de transferir | true/false |
| `method` | Método de transferencia por defecto | ssh/ftp/rsync/s3 |

## 🔄 Flujo de Trabajo

1. **Backup Local**: Se crea el backup en el servidor local
2. **Transferencia**: Si está configurado, se transfiere al servidor remoto
3. **Limpieza**: Si `keep_local` = false, se elimina la copia local
4. **Verificación**: Se registra en los logs el resultado

## 📝 Logs

Los logs de transferencia remota se guardan en:
```
/var/www/html/backup-manager/logs/remote_backup.log
```

## ⚠️ Consideraciones de Seguridad

1. **SSH con Clave**: Siempre preferir sobre contraseña
2. **Permisos**: La clave SSH debe tener permisos 600
3. **FTP**: Usar solo en redes seguras o con FTPS
4. **Contraseñas**: Se guardan en texto plano en el JSON
5. **Firewall**: Asegurar que los puertos estén abiertos

## 🚀 Instalación de Dependencias

### Para S3
```bash
# Ubuntu/Debian
apt-get install awscli

# CentOS/RHEL
yum install aws-cli
```

### Para Rsync
```bash
# Ubuntu/Debian
apt-get install rsync

# CentOS/RHEL
yum install rsync
```

## 💡 Tips

1. **Múltiples Servidores**: Puedes configurar varios servidores, el sistema usará el primero activo
2. **Backup Incremental**: Rsync es ideal para backups incrementales grandes
3. **Compresión**: Los archivos ya están comprimidos, no es necesaria compresión adicional en transferencia
4. **Ancho de Banda**: Considera programar transferencias en horarios de bajo tráfico
5. **Retención**: Configura políticas de retención en el servidor remoto también

## 🆘 Solución de Problemas

### SSH: "Permission denied"
- Verificar permisos de la clave: `chmod 600 /ruta/clave`
- Verificar usuario correcto
- Verificar que la clave pública esté en `authorized_keys`

### FTP: "Connection refused"
- Verificar puerto correcto
- Verificar firewall
- Probar modo pasivo/activo

### S3: "Access Denied"
- Verificar credenciales
- Verificar permisos del bucket
- Verificar región correcta

### Rsync: "Connection timed out"
- Verificar conectividad de red
- Verificar puerto SSH si usa SSH
- Verificar que rsync esté instalado en ambos servidores