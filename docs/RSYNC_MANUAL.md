# 📦 Guía de Rsync Manual para Backups Remotos

Ya que tienes acceso directo al servidor remoto por PuTTY/SSH, puedes hacer copias de seguridad remotas manualmente cuando lo necesites usando `rsync`.

---

## 🚀 Transferencia Manual Rápida

### Comando básico:

```bash
rsync -avz --progress /var/www/html/backup-manager/backups/ root@157.173.104.192:/home/backups/
```

**Explicación:**
- `-a` = archive (preserva permisos, timestamps, etc.)
- `-v` = verbose (muestra detalles)
- `-z` = compress (comprime durante transferencia)
- `--progress` = muestra progreso en tiempo real

---

## 📋 Ejemplos de Uso

### 1. Transferir todos los backups

```bash
rsync -avz --progress \
    /var/www/html/backup-manager/backups/*.tar.gz \
    root@157.173.104.192:/home/backups/
```

### 2. Transferir solo el backup más reciente

```bash
# Encontrar el archivo más reciente
LATEST=$(ls -t /var/www/html/backup-manager/backups/*.tar.gz | head -1)

# Transferirlo
rsync -avz --progress "$LATEST" root@157.173.104.192:/home/backups/
```

### 3. Transferir con eliminación en destino (sync exacto)

```bash
rsync -avz --progress --delete \
    /var/www/html/backup-manager/backups/ \
    root@157.173.104.192:/home/backups/
```

**⚠️ Cuidado:** `--delete` eliminará archivos en el destino que no existan en origen.

### 4. Transferir y verificar con checksum

```bash
rsync -avz --progress --checksum \
    /var/www/html/backup-manager/backups/ \
    root@157.173.104.192:/home/backups/
```

### 5. Dry-run (simular sin transferir)

```bash
rsync -avz --progress --dry-run \
    /var/www/html/backup-manager/backups/ \
    root@157.173.104.192:/home/backups/
```

**Útil para:** Ver qué archivos se transferirían sin hacer cambios reales.

---

## ⚙️ Opciones Avanzadas

### Limitar ancho de banda (10 MB/s)

```bash
rsync -avz --progress --bwlimit=10240 \
    /var/www/html/backup-manager/backups/ \
    root@157.173.104.192:/home/backups/
```

**Nota:** El valor está en KB/s (10240 KB/s = 10 MB/s)

### Excluir archivos temporales

```bash
rsync -avz --progress \
    --exclude '*.tmp' \
    --exclude '*.log' \
    /var/www/html/backup-manager/backups/ \
    root@157.173.104.192:/home/backups/
```

### Transferir solo archivos modificados en las últimas 24 horas

```bash
find /var/www/html/backup-manager/backups/ -name "*.tar.gz" -mtime -1 \
    -exec rsync -avz --progress {} root@157.173.104.192:/home/backups/ \;
```

### Usar puerto SSH no estándar

```bash
rsync -avz --progress -e "ssh -p 2222" \
    /var/www/html/backup-manager/backups/ \
    root@157.173.104.192:/home/backups/
```

---

## 🔐 Configurar Clave SSH (opcional)

Si quieres evitar escribir la contraseña cada vez:

### Paso 1: Generar clave SSH (si no tienes una)

```bash
ssh-keygen -t rsa -b 4096 -f ~/.ssh/id_rsa_backup
```

### Paso 2: Copiar al servidor remoto

```bash
ssh-copy-id -i ~/.ssh/id_rsa_backup.pub root@157.173.104.192
```

### Paso 3: Usar rsync sin contraseña

```bash
rsync -avz --progress -e "ssh -i ~/.ssh/id_rsa_backup" \
    /var/www/html/backup-manager/backups/ \
    root@157.173.104.192:/home/backups/
```

---

## 📅 Automatización con Cron (opcional)

Si quieres programar transferencias automáticas:

### Editar crontab:

```bash
crontab -e
```

### Agregar línea para transferir diariamente a las 4 AM:

```bash
0 4 * * * rsync -az /var/www/html/backup-manager/backups/*.tar.gz root@157.173.104.192:/home/backups/ >> /var/log/rsync-backup.log 2>&1
```

---

## 🔍 Verificar Transferencia

### Listar archivos en servidor remoto:

```bash
ssh root@157.173.104.192 "ls -lh /home/backups/"
```

### Comparar checksums local vs remoto:

```bash
# Local
md5sum /var/www/html/backup-manager/backups/backup_20250121.tar.gz

# Remoto
ssh root@157.173.104.192 "md5sum /home/backups/backup_20250121.tar.gz"
```

**Deben coincidir** para confirmar integridad.

---

## 📊 Ver Estadísticas de Transferencia

```bash
rsync -avz --progress --stats \
    /var/www/html/backup-manager/backups/ \
    root@157.173.104.192:/home/backups/
```

Mostrará:
- Número de archivos transferidos
- Tamaño total
- Velocidad promedio
- Tiempo total

---

## 🛠️ Script de Backup Completo

Crea un script `backup-to-remote.sh`:

```bash
#!/bin/bash

# Configuración
LOCAL_DIR="/var/www/html/backup-manager/backups"
REMOTE_USER="root"
REMOTE_HOST="157.173.104.192"
REMOTE_DIR="/home/backups"
LOG_FILE="/var/log/backup-remote.log"

# Fecha
DATE=$(date '+%Y-%m-%d %H:%M:%S')

echo "[$DATE] Iniciando transferencia de backups..." >> "$LOG_FILE"

# Transferir
rsync -avz --progress \
    "$LOCAL_DIR/" \
    "$REMOTE_USER@$REMOTE_HOST:$REMOTE_DIR/" \
    >> "$LOG_FILE" 2>&1

if [ $? -eq 0 ]; then
    echo "[$DATE] Transferencia completada exitosamente" >> "$LOG_FILE"
else
    echo "[$DATE] ERROR: Transferencia falló" >> "$LOG_FILE"
fi

# Verificar espacio en destino
REMOTE_SPACE=$(ssh "$REMOTE_USER@$REMOTE_HOST" "df -h $REMOTE_DIR | tail -1")
echo "[$DATE] Espacio remoto: $REMOTE_SPACE" >> "$LOG_FILE"
```

### Hacerlo ejecutable y usar:

```bash
chmod +x backup-to-remote.sh
./backup-to-remote.sh
```

---

## 🧹 Limpieza de Backups Antiguos en Servidor Remoto

### Eliminar backups mayores a 30 días:

```bash
ssh root@157.173.104.192 "find /home/backups -name '*.tar.gz' -mtime +30 -delete"
```

### Ver qué se eliminaría (dry-run):

```bash
ssh root@157.173.104.192 "find /home/backups -name '*.tar.gz' -mtime +30 -ls"
```

---

## 📝 Tips y Mejores Prácticas

1. **Verificar espacio antes de transferir:**
   ```bash
   ssh root@157.173.104.192 "df -h /home"
   ```

2. **Transferir en horarios de bajo tráfico** (madrugada)

3. **Usar compresión** (`-z`) solo si la red es lenta. Si tienes buena conexión, omítela para ir más rápido.

4. **Mantener logs** de transferencias para auditoría

5. **Hacer dry-run** primero con comandos nuevos

6. **Verificar checksums** periódicamente

7. **No usar `--delete`** a menos que entiendas bien qué hace

8. **Monitorear transferencias grandes** con `--progress` y `--stats`

---

## ⚠️ Solución de Problemas

### "Permission denied"
```bash
# Verificar permisos en destino
ssh root@157.173.104.192 "ls -ld /home/backups"

# Crear directorio si no existe
ssh root@157.173.104.192 "mkdir -p /home/backups && chmod 755 /home/backups"
```

### "Connection refused"
```bash
# Verificar SSH funciona
ssh root@157.173.104.192 "echo OK"
```

### "No space left on device"
```bash
# Verificar espacio
ssh root@157.173.104.192 "df -h"

# Limpiar backups antiguos
ssh root@157.173.104.192 "find /home/backups -name '*.tar.gz' -mtime +7 -delete"
```

---

## 🔄 Restaurar desde Servidor Remoto

Si necesitas traer un backup del servidor remoto:

```bash
# Listar backups remotos
ssh root@157.173.104.192 "ls -lh /home/backups/"

# Descargar un backup específico
rsync -avz --progress \
    root@157.173.104.192:/home/backups/backup_20250121.tar.gz \
    /var/www/html/backup-manager/backups/

# O descargar todos
rsync -avz --progress \
    root@157.173.104.192:/home/backups/ \
    /var/www/html/backup-manager/backups/
```

---

## 📚 Recursos Adicionales

- **Manual de rsync:** `man rsync`
- **Ejemplos:** `rsync --help`
- **Logs del sistema:** `/var/log/rsync-backup.log`

---

**Última actualización:** 2025-10-21
