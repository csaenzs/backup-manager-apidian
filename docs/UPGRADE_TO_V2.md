# 🚀 Guía de Actualización a Versión 2.0

## Resumen de Cambios

Se ha actualizado el sistema de backups remotos de la versión 1.0 a la 2.0 con mejoras significativas en seguridad, confiabilidad y funcionalidad.

---

## ✅ Mejoras Implementadas

### 🔐 Seguridad

#### 1. Cifrado de Contraseñas (AES-256-CBC)
**Antes:**
```json
{
    "password": "mi_contraseña_visible"
}
```

**Ahora:**
```json
{
    "password": "encrypted:Zm9vYmFyMTIzNDU2Nzg5MGFiY2RlZmdoaWprbG1ub3BxcnN0dXZ3eHl6..."
}
```

**Beneficios:**
- Contraseñas almacenadas de forma segura
- Clave de cifrado en `/backups/.remote_key` con permisos 0600
- Cifrado automático al guardar configuración

#### 2. Validación de Permisos SSH
**Nuevo:**
- Verificación automática de permisos de claves SSH
- Corrección automática a 0600 si es posible
- Advertencia si los permisos son inseguros

**Ejemplo:**
```bash
# El sistema detecta automáticamente:
# -rw-r--r-- (644) ❌ Inseguro
# -rw------- (600) ✅ Correcto
```

#### 3. SSH StrictHostKeyChecking Mejorado
**Antes:**
```bash
-o StrictHostKeyChecking=no  # Vulnerable a MITM
```

**Ahora:**
```bash
-o StrictHostKeyChecking=accept-new  # Más seguro
```

#### 4. Soporte para sshpass
**Nuevo:**
- Autenticación SSH con contraseña (requiere sshpass)
- Detección automática de disponibilidad
- Mensaje claro si no está instalado

---

### 🛡️ Confiabilidad

#### 1. Verificación de Checksums
**Nuevo:**
- Verificación MD5 post-transferencia para SSH y FTP
- Verificación ETag para S3
- Configurable (habilitado por defecto)

**Flujo:**
```
1. Transferir archivo
2. Calcular MD5 local
3. Calcular MD5 remoto
4. Comparar checksums
5. ✅ Éxito o ❌ Reintentar
```

#### 2. Sistema de Reintentos con Backoff Exponencial
**Nuevo:**
- Hasta 3 reintentos por defecto (configurable 1-10)
- Esperas progresivas: 5s, 10s, 20s...
- Logs detallados de cada intento

**Ejemplo de logs:**
```
[INFO] Transfer attempt 1/3 for backup_20250115.tar.gz
[WARNING] Transfer failed on attempt 1: Connection timeout
[INFO] Waiting 5s before retry...
[INFO] Transfer attempt 2/3 for backup_20250115.tar.gz
[SUCCESS] Transfer completed and verified successfully
```

#### 3. Manejo de Errores Mejorado
**Antes:**
```
Error code 1: Unknown error
```

**Ahora:**
```
Authentication failed: Permission denied. Check username, password, or SSH key.
```

**Errores específicos detectados:**
- Permission denied → "Check username, password, or SSH key"
- Connection refused → "Check if SSH service is running"
- Timeout → "Check network connectivity and firewall rules"
- No such directory → "Please create the directory first"
- Host key failed → "Remove old key with: ssh-keygen -R hostname"

---

### ⚙️ Funcionalidad

#### 1. Timeouts Configurables
**Nuevo:**
```json
{
    "timeout": 3600  // 1 hora (60-86400 segundos)
}
```

**Valores recomendados:**
- Archivos < 1GB: 3600s (1h)
- Archivos 1-10GB: 7200s (2h)
- Archivos > 10GB: 14400s (4h)

#### 2. Rate Limiting
**Nuevo (solo Rsync):**
```json
{
    "rate_limit_mbps": 10  // Límite de 10 Mbps
}
```

#### 3. Estadísticas de Transferencia
**Nuevo:**
```
Transfer stats: backup.tar.gz | Size: 125.45MB | Duration: 4.52s | Speed: 27.75MB/s
```

#### 4. API de Historial
**Nuevo endpoint:**
```bash
GET /api/remote-config.php?history=1&limit=100
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

#### 5. Validación de Configuración
**Nuevo:**
- Validación automática al guardar
- Mensajes de error específicos por campo
- Rangos validados (max_retries: 1-10, timeout: 60-86400, etc.)

#### 6. Logs Mejorados
**Antes:**
```
Transfer completed
```

**Ahora:**
```
[2025-01-15 14:30:00] [INFO] Starting SSH transfer to backup.ejemplo.com
[2025-01-15 14:30:05] [SUCCESS] SSH transfer completed in 4.52s
[2025-01-15 14:30:05] [INFO] Transfer stats: backup.tar.gz | Size: 125.45MB | Duration: 4.52s | Speed: 27.75MB/s
[2025-01-15 14:30:06] [SUCCESS] Checksum verified: a1b2c3d4e5f6...
```

**Niveles de log:**
- `INFO`: Información general
- `SUCCESS`: Operación exitosa
- `WARNING`: Advertencia
- `ERROR`: Error crítico

---

## 📋 Migración Automática

### ¿Necesito hacer algo?

**No, la migración es automática:**

1. **Primera carga:** El sistema detecta configuración antigua
2. **Cifrado automático:** Las contraseñas se cifran al primer acceso
3. **Configuración extendida:** Se añaden valores por defecto para nuevas opciones

### Proceso de Migración

```
Configuración Antigua (v1.0):
{
    "enabled": true,
    "method": "ssh",
    "servers": [...]
}

⬇️ AUTOMÁTICO ⬇️

Configuración Nueva (v2.0):
{
    "enabled": true,
    "method": "ssh",
    "max_retries": 3,        ← NUEVO
    "timeout": 3600,         ← NUEVO
    "verify_checksum": true, ← NUEVO
    "rate_limit_mbps": 0,    ← NUEVO
    "servers": [...]         ← Contraseñas cifradas
}
```

---

## 🔄 Compatibilidad

### ✅ Compatible hacia atrás
- Configuraciones antiguas siguen funcionando
- Se añaden valores por defecto para nuevas opciones
- No se requiere reconfiguración

### ⚠️ Cambios que requieren atención

#### 1. Autenticación SSH con Contraseña
**Antes:** No funcionaba (scp no acepta contraseñas)

**Ahora:** Requiere `sshpass`

```bash
# Instalar si usas contraseñas SSH:
apt-get install sshpass
```

#### 2. Archivo de Clave de Cifrado
**Nuevo archivo creado:**
```bash
/var/www/html/backup-manager/backups/.remote_key
```

**IMPORTANTE:**
- Respaldar este archivo
- No compartirlo
- Sin este archivo, las contraseñas cifradas no se pueden descifrar

**Backup:**
```bash
tar -czf remote-backup-config.tar.gz \
    /var/www/html/backup-manager/backups/remote_config.json \
    /var/www/html/backup-manager/backups/.remote_key

# Guardar en ubicación segura
chmod 600 remote-backup-config.tar.gz
```

---

## 📊 Comparación de Características

| Característica | v1.0 | v2.0 |
|----------------|------|------|
| **Seguridad** |
| Cifrado de contraseñas | ❌ Texto plano | ✅ AES-256-CBC |
| Validación permisos SSH | ❌ No | ✅ Automática |
| StrictHostKeyChecking | ⚠️ no | ✅ accept-new |
| sshpass support | ❌ No | ✅ Sí |
| **Confiabilidad** |
| Verificación checksums | ❌ No | ✅ MD5/ETag |
| Reintentos automáticos | ❌ No | ✅ 3 intentos |
| Backoff exponencial | ❌ No | ✅ 5s, 10s, 20s |
| Timeouts configurables | ❌ Fijo | ✅ 60-86400s |
| **Funcionalidad** |
| Logs estructurados | ⚠️ Básico | ✅ Niveles |
| Estadísticas transferencia | ❌ No | ✅ Velocidad, duración |
| API de historial | ❌ No | ✅ Sí |
| Rate limiting | ❌ No | ✅ Rsync |
| Validación config | ❌ No | ✅ Completa |
| **Mensajes de Error** |
| Errores genéricos | ⚠️ Error code X | ✅ Mensajes específicos |
| Sugerencias de solución | ❌ No | ✅ Sí |

---

## 🧪 Pruebas Post-Actualización

### 1. Verificar cifrado de contraseñas

```bash
# Ver configuración (las contraseñas deben tener prefijo "encrypted:")
cat /var/www/html/backup-manager/backups/remote_config.json | jq .servers[0].password
```

**Esperado:**
```json
"encrypted:Zm9vYmFy..."
```

### 2. Test de conexión

Desde la interfaz web:
1. Ir a tab "Remoto"
2. Click "Probar Conexión"
3. Verificar mensaje de éxito

### 3. Test de transferencia real

```bash
# Ejecutar backup de prueba
curl -X POST http://localhost/backup-manager/api/backup.php \
  -H "Cookie: PHPSESSID=tu_session" \
  -d "type=database"

# Verificar logs
tail -f /var/www/html/backup-manager/logs/remote_backup.log
```

### 4. Verificar checksums

```bash
# Buscar en logs
grep "Checksum verified" /var/www/html/backup-manager/logs/remote_backup.log
```

**Esperado:**
```
[2025-01-15 14:30:06] [SUCCESS] Checksum verified: a1b2c3d4e5f6...
```

---

## 🔧 Solución de Problemas Post-Actualización

### Problema: "sshpass not found" al usar contraseña SSH

**Solución:**
```bash
apt-get update
apt-get install sshpass
```

### Problema: Contraseñas no se descifran correctamente

**Causa:** Archivo `.remote_key` perdido o corrupto

**Solución:**
```bash
# Si tienes backup:
cp backup/.remote_key /var/www/html/backup-manager/backups/

# Si NO tienes backup, necesitas reconfigurar:
rm /var/www/html/backup-manager/backups/remote_config.json
# Configurar de nuevo desde la interfaz web
```

### Problema: "Checksum mismatch" repetidamente

**Solución:**
```bash
# Desactivar temporalmente verificación
# En remote_config.json:
{
    "verify_checksum": false
}

# Luego investigar:
# 1. Verificar integridad de filesystem local
# 2. Verificar integridad de filesystem remoto
# 3. Revisar errores de red en logs
```

---

## 📈 Nuevas Opciones de Configuración

### Configuración Mínima (igual que v1.0)
```json
{
    "enabled": true,
    "method": "ssh",
    "keep_local": true,
    "servers": [...]
}
```

### Configuración Completa (nueva en v2.0)
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
            "id": "server1",
            "method": "ssh",
            "active": true,
            "host": "backup.com",
            "port": 22,
            "user": "backup",
            "key_file": "/root/.ssh/id_rsa",
            "path": "/backups"
        }
    ]
}
```

---

## 🎯 Mejores Prácticas Post-Actualización

1. **Backup de configuración:**
   ```bash
   cd /var/www/html/backup-manager/backups
   cp remote_config.json remote_config.json.backup
   cp .remote_key .remote_key.backup
   ```

2. **Habilitar verificación de checksums:**
   ```json
   {"verify_checksum": true}
   ```

3. **Configurar reintentos apropiados:**
   ```json
   {"max_retries": 3}
   ```

4. **Ajustar timeout según tamaño de backups:**
   ```json
   {"timeout": 7200}  // Para backups grandes
   ```

5. **Monitorear logs regularmente:**
   ```bash
   tail -f /var/www/html/backup-manager/logs/remote_backup.log
   ```

---

## 📚 Recursos

- **Documentación completa:** `docs/REMOTE_BACKUP.md`
- **Ejemplos de configuración:** Sección "Ejemplos Completos" en documentación
- **API Reference:** Sección "Referencia de API" en documentación
- **Solución de problemas:** Sección "Solución de Problemas" en documentación

---

## ✨ Beneficios Inmediatos

Después de la actualización, obtienes:

1. ✅ **Seguridad mejorada** - Contraseñas cifradas automáticamente
2. ✅ **Mayor confiabilidad** - Verificación de integridad de archivos
3. ✅ **Recuperación automática** - Reintentos en caso de fallo
4. ✅ **Mejor debugging** - Logs detallados con mensajes claros
5. ✅ **Monitoreo mejorado** - Estadísticas y historial de transferencias
6. ✅ **Compatibilidad total** - Funciona con configuración existente

---

**Fecha de actualización:** 2025-01-15
**Versión:** 2.0
**Autor:** Sistema de Backup Manager
