# 🌐 Sistema de Backup Remoto - Versión 2.0

Sistema avanzado de backups remotos con cifrado, verificación y alta confiabilidad.

## 🆕 Novedades Versión 2.0

### Seguridad Mejorada
- ✅ **Cifrado de contraseñas** con AES-256-CBC
- ✅ **Permisos SSH validados** automáticamente
- ✅ **StrictHostKeyChecking mejorado** (accept-new en lugar de no)
- ✅ **Soporte para sshpass** (autenticación SSH con contraseña)

### Confiabilidad
- ✅ **Verificación de checksums** MD5 post-transferencia
- ✅ **Reintentos automáticos** con backoff exponencial (3 intentos por defecto)
- ✅ **Timeouts configurables** (1 hora por defecto)
- ✅ **Manejo de errores detallado** con mensajes específicos

### Rendimiento
- ✅ **Rate limiting** (limitación de ancho de banda)
- ✅ **Estadísticas de transferencia** (velocidad, duración, tamaño)
- ✅ **Logs mejorados** con timestamps y niveles

### Monitoreo
- ✅ **Historial de transferencias** consultable desde la API
- ✅ **Logs estructurados** con niveles (INFO, SUCCESS, WARNING, ERROR)
- ✅ **Última error** accesible para debugging

---

## 📋 Métodos Soportados

### 1. SSH/SCP (Recomendado) 🔐
**Ventajas:**
- Cifrado end-to-end
- Autenticación con clave SSH o contraseña (con sshpass)
- Sin software adicional requerido

**Requisitos:**
- `scp` (incluido en OpenSSH)
- `sshpass` (opcional, para autenticación con contraseña)

**Instalación:**
```bash
# Ubuntu/Debian
apt-get install openssh-client sshpass

# CentOS/RHEL
yum install openssh-clients sshpass
```

### 2. FTP ⚠️
**Advertencias:**
- No cifrado (usar solo en redes seguras)
- Contraseñas en texto plano durante transmisión

**Requisitos:**
- Extensión PHP `ftp`

**Instalación:**
```bash
apt-get install php-ftp
service apache2 restart
```

### 3. Rsync 🚀
**Ventajas:**
- Transferencias incrementales
- Muy eficiente para archivos grandes
- Puede usar SSH como transporte

**Requisitos:**
- `rsync` en cliente y servidor

**Instalación:**
```bash
apt-get install rsync
```

### 4. S3 Compatible ☁️
**Ventajas:**
- Amazon S3, DigitalOcean Spaces, MinIO, etc.
- Alta disponibilidad
- Políticas de retención automáticas

**Requisitos:**
- AWS CLI

**Instalación:**
```bash
apt-get install awscli
```

---

## 🚀 Configuración

### Archivo de Configuración

El archivo se guarda en: `/var/www/html/backup-manager/backups/remote_config.json`

**Estructura completa:**
```json
{
    "enabled": true,
    "method": "ssh",
    "keep_local": true,
    "max_retries": 3,
    "timeout": 3600,
    "verify_checksum": true,
    "rate_limit_mbps": 0,
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

### Parámetros Globales

| Parámetro | Tipo | Rango | Descripción |
|-----------|------|-------|-------------|
| `enabled` | boolean | true/false | Activar/desactivar backups remotos |
| `method` | string | ssh/ftp/rsync/s3 | Método por defecto |
| `keep_local` | boolean | true/false | Mantener copia local tras transferir |
| `max_retries` | integer | 1-10 | Número de reintentos en caso de fallo |
| `timeout` | integer | 60-86400 | Timeout en segundos (default: 3600) |
| `verify_checksum` | boolean | true/false | Verificar integridad post-transferencia |
| `rate_limit_mbps` | integer | 0-10000 | Límite de velocidad en Mbps (0 = sin límite) |

---

## 🔐 Configuración de Seguridad

### 1. Cifrado de Contraseñas

Las contraseñas se cifran automáticamente con AES-256-CBC:

```bash
# Se genera automáticamente una clave de cifrado en:
/var/www/html/backup-manager/backups/.remote_key
```

**Importante:**
- La clave se crea con permisos `0600`
- **No compartir** la clave `.remote_key`
- Respaldar la clave si se migra el sistema

### 2. Autenticación SSH

#### Opción A: Clave SSH (Recomendado)

**Generar nueva clave:**
```bash
ssh-keygen -t rsa -b 4096 -f /root/.ssh/backup_rsa -C "backup@$(hostname)"
```

**Copiar al servidor:**
```bash
ssh-copy-id -i /root/.ssh/backup_rsa.pub usuario@servidor.com
```

**Verificar permisos:**
```bash
chmod 600 /root/.ssh/backup_rsa
chmod 644 /root/.ssh/backup_rsa.pub
```

**Configuración:**
```json
{
    "method": "ssh",
    "host": "servidor.com",
    "port": 22,
    "user": "usuario",
    "key_file": "/root/.ssh/backup_rsa",
    "path": "/backup/destino"
}
```

#### Opción B: Contraseña (Con sshpass)

**Verificar disponibilidad:**
```bash
which sshpass || apt-get install sshpass
```

**Configuración:**
```json
{
    "method": "ssh",
    "host": "servidor.com",
    "port": 22,
    "user": "usuario",
    "password": "tu_contraseña_segura",
    "path": "/backup/destino"
}
```

**Nota:** La contraseña se cifra automáticamente al guardar.

### 3. FTP Seguro

Para FTP, considerar usar FTPS (FTP sobre SSL/TLS):

```bash
# Configurar FTPS en el servidor (vsftpd)
ssl_enable=YES
allow_anon_ssl=NO
force_local_data_ssl=YES
force_local_logins_ssl=YES
ssl_tlsv1=YES
ssl_sslv2=NO
ssl_sslv3=NO
```

### 4. S3 Credenciales

Las credenciales de S3 también se cifran:

```json
{
    "method": "s3",
    "bucket": "mi-bucket-backups",
    "path": "apidian/backups",
    "access_key": "AKIAIOSFODNN7EXAMPLE",
    "secret_key": "wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY",
    "region": "us-east-1",
    "endpoint": ""
}
```

---

## ⚙️ Configuración Avanzada

### Reintentos y Backoff Exponencial

El sistema reintenta automáticamente tras fallos:

```
Intento 1: Inmediato
Intento 2: Espera 5 segundos
Intento 3: Espera 10 segundos
```

**Configurar reintentos:**
```json
{
    "max_retries": 5
}
```

### Verificación de Checksums

Métodos de verificación por tipo de transferencia:

| Método | Verificación |
|--------|--------------|
| SSH | MD5 remoto vía `md5sum` |
| FTP | Descarga parcial y MD5 local |
| Rsync | Integrado en rsync |
| S3 | ETag (MD5 para uploads simples) |

**Desactivar verificación (no recomendado):**
```json
{
    "verify_checksum": false
}
```

### Rate Limiting (Control de Ancho de Banda)

Limitar velocidad de transferencia (solo Rsync):

```json
{
    "rate_limit_mbps": 10
}
```

**Ejemplo:** `10` = 10 Mbps máximo = ~1.25 MB/s

### Timeouts

Ajustar timeout global:

```json
{
    "timeout": 7200
}
```

**Valores recomendados:**
- Archivos < 1GB: 3600 (1 hora)
- Archivos 1-10GB: 7200 (2 horas)
- Archivos > 10GB: 14400 (4 horas)

---

## 🧪 Testing y Validación

### Test de Conexión

Desde la interfaz web:
1. Ir a tab "Remoto"
2. Configurar servidor
3. Click "Test Conexión"

Desde línea de comandos:
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

if ($result['success']) {
    echo "✅ " . $result['message'] . "\n";
} else {
    echo "❌ " . $result['message'] . "\n";
}
?>
```

### Test Manual SSH

```bash
# Test básico
ssh usuario@servidor.com "echo OK"

# Test con clave específica
ssh -i /root/.ssh/backup_rsa usuario@servidor.com "echo OK"

# Test de escritura
ssh usuario@servidor.com "touch /backup/test.txt && rm /backup/test.txt"

# Test SCP
echo "test" > /tmp/test.txt
scp -i /root/.ssh/backup_rsa /tmp/test.txt usuario@servidor.com:/backup/
ssh usuario@servidor.com "cat /backup/test.txt && rm /backup/test.txt"
```

### Test Manual FTP

```bash
# Test con lftp
lftp -u usuario,password -e "ls; bye" ftp://servidor.com

# Test con curl
curl -u usuario:password ftp://servidor.com/
```

### Test Manual S3

```bash
# Configurar credenciales
export AWS_ACCESS_KEY_ID="tu_access_key"
export AWS_SECRET_ACCESS_KEY="tu_secret_key"

# Listar bucket
aws s3 ls s3://tu-bucket/

# Subir archivo de prueba
echo "test" > /tmp/test.txt
aws s3 cp /tmp/test.txt s3://tu-bucket/test.txt
aws s3 rm s3://tu-bucket/test.txt
```

---

## 📊 Monitoreo y Logs

### Logs de Transferencia

**Ubicación:**
```bash
/var/www/html/backup-manager/logs/remote_backup.log
```

**Formato:**
```
[2025-01-15 14:30:00] [INFO] Starting SSH transfer to backup.ejemplo.com
[2025-01-15 14:30:05] [SUCCESS] SSH transfer completed in 4.52s
[2025-01-15 14:30:05] [INFO] Transfer stats: backup_20250115_143000.tar.gz | Size: 125.45MB | Duration: 4.52s | Speed: 27.75MB/s
[2025-01-15 14:30:06] [SUCCESS] Checksum verified: a1b2c3d4e5f6...
```

**Niveles de log:**
- `INFO`: Información general
- `SUCCESS`: Operación exitosa
- `WARNING`: Advertencia (no crítico)
- `ERROR`: Error crítico

### Ver Logs

```bash
# Últimas 50 líneas
tail -n 50 /var/www/html/backup-manager/logs/remote_backup.log

# Seguir en tiempo real
tail -f /var/www/html/backup-manager/logs/remote_backup.log

# Filtrar errores
grep "\[ERROR\]" /var/www/html/backup-manager/logs/remote_backup.log

# Estadísticas de transferencia
grep "Transfer stats" /var/www/html/backup-manager/logs/remote_backup.log | tail -20
```

### API de Historial

Consultar historial desde API:

```bash
# Obtener últimas 100 transferencias
curl -X GET "http://localhost/backup-manager/api/remote-config.php?history=1&limit=100" \
  -H "Cookie: PHPSESSID=tu_session_id" | jq .
```

**Respuesta:**
```json
{
    "history": [
        {
            "timestamp": "2025-01-15 14:30:00",
            "level": "SUCCESS",
            "message": "Transfer completed and verified successfully"
        }
    ]
}
```

---

## 🔧 Solución de Problemas

### SSH: "Permission denied"

**Causas comunes:**
1. Contraseña incorrecta
2. Clave SSH no autorizada
3. Permisos incorrectos

**Soluciones:**
```bash
# Verificar clave en servidor
ssh usuario@servidor "cat ~/.ssh/authorized_keys"

# Verificar permisos locales
ls -la /root/.ssh/backup_rsa
# Debe ser: -rw------- (600)

# Corregir permisos
chmod 600 /root/.ssh/backup_rsa

# Test detallado
ssh -vvv -i /root/.ssh/backup_rsa usuario@servidor
```

### SSH: "sshpass not found"

```bash
# Instalar sshpass
apt-get update
apt-get install sshpass

# Verificar instalación
which sshpass
sshpass -V
```

### SSH: "Host key verification failed"

```bash
# Opción 1: Agregar host a known_hosts
ssh-keyscan -H servidor.com >> ~/.ssh/known_hosts

# Opción 2: Limpiar clave antigua
ssh-keygen -R servidor.com
```

### FTP: "Connection refused"

**Verificar:**
```bash
# Test conexión
telnet servidor.com 21

# Verificar firewall local
iptables -L -n | grep 21

# Verificar servicio FTP en servidor
ssh servidor.com "systemctl status vsftpd"
```

### FTP: "Login failed"

```bash
# Test credenciales
lftp -u usuario,password -e "ls; bye" servidor.com

# Verificar desde interfaz
# Las contraseñas se cifran automáticamente al guardar
```

### S3: "Access Denied"

**Verificar:**
1. Credenciales correctas (Access Key y Secret Key)
2. Permisos del bucket (debe permitir PutObject)
3. Región correcta

```bash
# Test AWS CLI
aws s3 ls s3://tu-bucket/

# Ver configuración
aws configure list

# Test con credenciales específicas
AWS_ACCESS_KEY_ID=xxx AWS_SECRET_ACCESS_KEY=yyy aws s3 ls s3://tu-bucket/
```

### S3: "AWS CLI not found"

```bash
# Ubuntu/Debian
apt-get install awscli

# Verificar instalación
aws --version

# Configurar (opcional si usas credenciales en JSON)
aws configure
```

### Rsync: "Connection timed out"

**Verificar:**
```bash
# Test conectividad
ping -c 3 servidor.com

# Test puerto SSH
telnet servidor.com 22

# Test rsync manual
rsync -avz --dry-run /tmp/test.txt usuario@servidor.com:/backup/
```

### Verificación: "Checksum mismatch"

**Causas:**
1. Corrupción durante transferencia
2. Archivo modificado remotamente
3. Error en sistema de archivos

**Acciones:**
- El sistema **reintenta automáticamente**
- Verificar integridad del sistema de archivos local y remoto
- Revisar logs para errores de red

### Transfer: "Timeout"

**Soluciones:**
```json
{
    "timeout": 7200,
    "rate_limit_mbps": 0
}
```

**Verificar:**
- Velocidad de red entre servidores
- Tamaño del archivo
- Carga del servidor

---

## 📈 Optimización de Rendimiento

### 1. Rate Limiting Inteligente

Para no saturar la red en horarios críticos:

```json
{
    "rate_limit_mbps": 10
}
```

### 2. Compresión

Los backups ya están comprimidos (.tar.gz), pero para Rsync:

```bash
# Rsync usa compresión por defecto con -z
# No se necesita compresión adicional
```

### 3. Transferencias Programadas

Ejecutar backups en horarios de bajo tráfico:

```bash
# Programar para las 3 AM
0 3 * * * cd /var/www/html/backup-manager && php api/backup.php full
```

### 4. Paralelización

Para múltiples servidores (futuro):

```json
{
    "servers": [
        {"id": "server1", "active": true},
        {"id": "server2", "active": true}
    ]
}
```

*Nota: La paralelización de servidores se implementará en una futura versión.*

---

## 🔄 Mantenimiento

### Limpieza de Backups Antiguos

#### Limpieza Remota SSH

```php
<?php
require_once 'core/RemoteBackup.php';

$remote = new RemoteBackup();
$server = [/* configuración */];

// Eliminar backups > 30 días
$remote->cleanupRemote($server, 30);
?>
```

**Desde línea de comandos:**
```bash
# Manual (cuidado!)
ssh usuario@servidor "find /backup -name '*.gz' -mtime +30 -delete"

# Con confirmación
ssh usuario@servidor "find /backup -name '*.gz' -mtime +30 -ls"
# Revisar lista, luego:
ssh usuario@servidor "find /backup -name '*.gz' -mtime +30 -delete"
```

#### Limpieza S3

Usar **Lifecycle Policies** en AWS S3:

```json
{
    "Rules": [
        {
            "Id": "DeleteOldBackups",
            "Status": "Enabled",
            "Prefix": "backups/",
            "Expiration": {
                "Days": 30
            }
        }
    ]
}
```

### Rotación de Logs

```bash
# Agregar a logrotate
cat > /etc/logrotate.d/backup-manager <<EOF
/var/www/html/backup-manager/logs/remote_backup.log {
    daily
    rotate 30
    compress
    delaycompress
    missingok
    notifempty
    create 0640 www-data www-data
}
EOF
```

### Backup de Configuración

```bash
# Respaldar configuración y clave de cifrado
tar -czf remote-backup-config.tar.gz \
    /var/www/html/backup-manager/backups/remote_config.json \
    /var/www/html/backup-manager/backups/.remote_key

# Guardar en ubicación segura
chmod 600 remote-backup-config.tar.gz
```

---

## 🎯 Ejemplos Completos

### Ejemplo 1: SSH con Clave (Producción)

```json
{
    "enabled": true,
    "method": "ssh",
    "keep_local": false,
    "max_retries": 3,
    "timeout": 3600,
    "verify_checksum": true,
    "rate_limit_mbps": 0,
    "servers": [
        {
            "id": "production_backup",
            "method": "ssh",
            "active": true,
            "host": "backup.empresa.com",
            "port": 22,
            "user": "backup_user",
            "key_file": "/root/.ssh/backup_production_rsa",
            "path": "/mnt/backups/apidian/production"
        }
    ]
}
```

### Ejemplo 2: FTP Simple (Testing)

```json
{
    "enabled": true,
    "method": "ftp",
    "keep_local": true,
    "max_retries": 2,
    "timeout": 1800,
    "verify_checksum": true,
    "servers": [
        {
            "id": "ftp_testing",
            "method": "ftp",
            "active": true,
            "host": "ftp.test.local",
            "port": 21,
            "user": "ftpuser",
            "password": "encrypted:base64encodedpassword",
            "path": "/backups"
        }
    ]
}
```

### Ejemplo 3: DigitalOcean Spaces

```json
{
    "enabled": true,
    "method": "s3",
    "keep_local": false,
    "max_retries": 5,
    "timeout": 7200,
    "verify_checksum": true,
    "servers": [
        {
            "id": "do_spaces",
            "method": "s3",
            "active": true,
            "endpoint": "https://nyc3.digitaloceanspaces.com",
            "bucket": "empresa-backups",
            "path": "apidian/databases",
            "access_key": "encrypted:base64encodedkey",
            "secret_key": "encrypted:base64encodedsecret",
            "region": "nyc3"
        }
    ]
}
```

### Ejemplo 4: Rsync sobre SSH con Rate Limiting

```json
{
    "enabled": true,
    "method": "rsync",
    "keep_local": true,
    "max_retries": 3,
    "timeout": 14400,
    "verify_checksum": false,
    "rate_limit_mbps": 20,
    "servers": [
        {
            "id": "rsync_incremental",
            "method": "rsync",
            "active": true,
            "ssh": true,
            "host": "backup-nas.local",
            "port": 22,
            "user": "backup",
            "key_file": "/root/.ssh/id_rsa",
            "path": "/volume1/backups/apidian"
        }
    ]
}
```

---

## 📚 Referencia de API

### POST /api/remote-config.php

**Acciones disponibles:**

#### 1. save - Guardar configuración
```json
{
    "action": "save",
    "config": {
        "enabled": true,
        "method": "ssh",
        "servers": [...]
    }
}
```

**Respuesta:**
```json
{
    "success": true,
    "message": "Configuration saved successfully with encrypted passwords"
}
```

#### 2. test - Test de conexión
```json
{
    "action": "test",
    "server": {
        "method": "ssh",
        "host": "servidor.com",
        "user": "usuario",
        "key_file": "/root/.ssh/id_rsa"
    }
}
```

**Respuesta:**
```json
{
    "success": true,
    "message": "SSH connection successful"
}
```

#### 3. history - Obtener historial
```json
{
    "action": "history",
    "limit": 50
}
```

**Respuesta:**
```json
{
    "success": true,
    "history": [
        {
            "timestamp": "2025-01-15 14:30:00",
            "level": "SUCCESS",
            "message": "Transfer completed"
        }
    ]
}
```

#### 4. cleanup - Limpiar backups remotos
```json
{
    "action": "cleanup",
    "server": {...},
    "retention_days": 30
}
```

**Respuesta:**
```json
{
    "success": true,
    "message": "Cleanup completed"
}
```

---

## 🔒 Seguridad: Checklist

Antes de poner en producción:

- [ ] Contraseñas cifradas (automático)
- [ ] Permisos de claves SSH: `600`
- [ ] Archivo `.remote_key` respaldado
- [ ] Test de conexión exitoso
- [ ] Test de transferencia exitoso
- [ ] Verificación de checksums habilitada
- [ ] Logs monitoreados
- [ ] Firewall configurado (puertos abiertos)
- [ ] Directorio remoto con espacio suficiente
- [ ] Política de retención definida
- [ ] Backup de configuración guardado

---

## 📞 Soporte

### Logs de Debug

```bash
# Ver todos los eventos
cat /var/www/html/backup-manager/logs/remote_backup.log

# Solo errores
grep ERROR /var/www/html/backup-manager/logs/remote_backup.log

# Última transferencia
tail -n 20 /var/www/html/backup-manager/logs/remote_backup.log

# Estadísticas
grep "Transfer stats" /var/www/html/backup-manager/logs/remote_backup.log
```

### Información del Sistema

```bash
# Versiones instaladas
echo "OpenSSH: $(ssh -V 2>&1)"
echo "sshpass: $(sshpass -V 2>&1 | head -1)"
echo "rsync: $(rsync --version | head -1)"
echo "AWS CLI: $(aws --version 2>&1)"
echo "PHP FTP: $(php -m | grep ftp)"

# Métodos disponibles
curl -s http://localhost/backup-manager/api/remote-config.php | jq .methods
```

---

## 📝 Changelog

### Version 2.0 (2025-01-15)
- ✅ Cifrado de contraseñas con AES-256-CBC
- ✅ Verificación de checksums MD5
- ✅ Sistema de reintentos con backoff exponencial
- ✅ Soporte para sshpass
- ✅ Mejor seguridad SSH
- ✅ Rate limiting para rsync
- ✅ Logs mejorados con niveles
- ✅ API de historial
- ✅ Validación de configuración
- ✅ Manejo de errores detallado
- ✅ Estadísticas de transferencia

### Version 1.0 (2025-01-10)
- Soporte básico SSH/FTP/Rsync/S3
- Configuración JSON
- Test de conexión
- Integración con backup worker

---

## 💡 Tips y Mejores Prácticas

1. **Usar claves SSH en producción**, nunca contraseñas
2. **Habilitar verificación de checksums** siempre que sea posible
3. **Configurar rate limiting** en redes lentas
4. **Monitorear logs regularmente**
5. **Probar restauración** de backups remotos periódicamente
6. **Mantener múltiples destinos** de backup (3-2-1 rule)
7. **Configurar alertas** para fallos de transferencia
8. **Documentar configuración** específica de tu entorno
9. **Respaldar** el archivo `.remote_key`
10. **Revisar permisos** de archivos y directorios regularmente

---

**Documentación actualizada:** 2025-01-15
**Versión del sistema:** 2.0
