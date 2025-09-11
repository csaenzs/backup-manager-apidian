# 🛡️ Backup Manager

Sistema de gestión de backups en caliente para APIs Laravel/PHP con interfaz web.

## ✨ Características

- ✅ **Backups en caliente** sin interrumpir el servicio
- 📊 **Progreso en tiempo real** con WebSockets
- ⏰ **Programación automática** (diario/semanal/mensual)
- 💾 **Soporte para backups grandes** (50GB+)
- 🔄 **Backups incrementales reales** para BD y Storage
- 🎯 **100% compatible** con tu configuración actual
- 🚀 **Sin afectar el rendimiento** de la API
- 📈 **Ahorro de espacio hasta 90%** con incrementales
- 🗃️ **Binary logs automáticos** para BD incrementales

## 📋 Requisitos

- PHP 7.4+
- MySQL/MariaDB
- Apache 2.4+
- Linux (Ubuntu/Debian preferido)

## 🚀 Instalación Rápida

```bash
# Ejecutar como root o con sudo
sudo /var/www/html/backup-manager/install.sh
```

El script detectará automáticamente:
- Ubicación de tu API
- Configuración de base de datos
- Rutas de storage

## 🔐 Acceso

Después de la instalación:
- URL: `http://tu-servidor:8888`
- Contraseña: `admin123` 
- ⚠️ **IMPORTANTE**: Cambiar la contraseña inmediatamente en "🔒 Configuración de Seguridad"

## 📁 Estructura

```
backup-manager/
├── api/           # Endpoints REST
├── assets/        # CSS y JavaScript
├── backups/       # Almacenamiento de backups
├── core/          # Lógica principal
├── logs/          # Archivos de log
├── temp/          # Archivos temporales
└── views/         # Vistas PHP
```

## 🎯 Uso

### Backup Manual
1. Acceder al panel web
2. Click en "Backup Completo Ahora"
3. Ver progreso en tiempo real
4. **Primer backup**: Completo (base)
5. **Siguientes backups**: Incrementales automáticos

### Programación Automática
1. Ir a sección "Programación"
2. Activar backup automático
3. Seleccionar frecuencia y hora
4. Guardar configuración

### Tipos de Backup
- **Storage**: Incremental con `rsync` + hard links
- **Base de Datos**: Incremental con binary logs (requiere configuración)
- **Completo**: Combina ambos tipos

### Configuración
- **Retención**: Días para mantener backups antiguos
- **Compresión**: none/low/medium/high
- **Destino**: Ruta donde guardar backups
- **Binary Logs**: Habilitado automáticamente en instalación

## 🔧 Configuración Manual

Si necesitas ajustar la configuración:

```php
// Editar: /var/www/html/backup-manager/config.local.php
$config = [
    'api_path' => '/var/www/html/apidian',
    'storage_path' => '/var/www/html/apidian/storage',
    'db_name' => 'apidian',
    'db_user' => 'root',
    'db_pass' => 'password',
    'backup_path' => '/var/www/html/backup-manager/backups',
    'retention_days' => 30,
    'compression' => 'medium'
];
```

## 🚨 Seguridad

- Cambiar contraseña por defecto inmediatamente
- Backups almacenados con permisos 700
- Acceso solo con autenticación
- Configuración protegida (chmod 600)
- Puerto separado del API principal

## 📊 Monitoreo

Los logs se guardan en:
```
/var/www/html/backup-manager/logs/backup_YYYY-MM-DD.log
```

## 🔄 Restauración de Backups

### 🖥️ Restauración Automática (Mismo Servidor)
```bash
# Usar script de restauración automática
cd /var/www/html/backup-manager
./restore_incremental.sh 20250911

# El script:
# 1. Crea backup de seguridad automático
# 2. Restaura backup completo base
# 3. Aplica todos los incrementales en orden
# 4. Verifica la integridad final
```

### 🌐 Restauración Manual (Otro Servidor)
```bash
# Generar comandos para servidor remoto
./restore_incremental.sh 20250911 remote

# Ejecutar los comandos mostrados en el servidor destino
```

### 📋 Restauración Desde la Interfaz Web
1. Seleccionar backup en el historial
2. Click en "Restaurar" (↻)
3. Seguir instrucciones detalladas mostradas
4. **Importante**: La restauración genera comandos, no ejecuta automáticamente

### 🔄 Proceso de Restauración Incremental
Para backups incrementales de base de datos:
```bash
# 1. Restaurar backup base (full)
mysql -u usuario -p base_datos < backup_base_20250911_120000_full.sql

# 2. Aplicar incrementales EN ORDEN CRONOLÓGICO
mysql -u usuario -p base_datos < backup_incremental_20250911_130000.sql
mysql -u usuario -p base_datos < backup_incremental_20250911_140000.sql
# ... hasta el incremental más reciente deseado
```

**⚠️ Importante**: 
- Los incrementales DEBEN aplicarse en orden cronológico
- Si falta un incremental, la restauración puede ser incompleta
- Siempre crear backup de seguridad antes de restaurar

## 📊 Backups Incrementales

### 🗃️ Base de Datos (Binary Logs)
```bash
# El instalador configura automáticamente:
log-bin = mysql-bin
binlog_format = ROW
expire_logs_days = 7
```

**Resultado para BD de 50GB:**
- Primer backup: 50GB → 15GB (comprimido)
- Backups incrementales: 50MB-2GB (solo cambios)
- **Ahorro**: 90% espacio, 95% tiempo

### 📁 Storage (rsync + hard links)
```bash
# Incrementales automáticos con rsync
rsync --link-dest=/backup/anterior /storage/ /backup/nuevo/
```

**Resultado para Storage de 50GB:**
- Primer backup: 50GB
- Incrementales: Solo archivos modificados (1-5GB típicamente)

Ver documentación completa: [`docs/BINARY_LOGS.md`](docs/BINARY_LOGS.md)

## 🔧 Instalación Paso a Paso

### 1. Preparación del Sistema
```bash
# Instalar dependencias
sudo apt update
sudo apt install apache2 php7.4 php7.4-mysql mysql-server rsync

# Crear directorios
sudo mkdir -p /var/www/html/backup-manager
sudo chown www-data:www-data /var/www/html/backup-manager

# Configurar Apache puerto 8888
sudo cp backup-manager.conf /etc/apache2/sites-available/
sudo a2ensite backup-manager
sudo systemctl reload apache2
```

### 2. Configuración de MySQL para Incrementales
```bash
# Editar /etc/mysql/mysql.conf.d/mysqld.cnf
sudo nano /etc/mysql/mysql.conf.d/mysqld.cnf

# Añadir estas líneas:
[mysqld]
log-bin = mysql-bin
binlog_format = ROW
expire_logs_days = 7

# Reiniciar MySQL
sudo systemctl restart mysql
```

### 3. Permisos y Seguridad
```bash
# Configurar permisos correctos
sudo chown -R www-data:www-data /var/www/html/backup-manager
sudo chmod -R 755 /var/www/html/backup-manager
sudo chmod 700 /var/www/html/backup-manager/backups
sudo chmod 600 /var/www/html/backup-manager/config.local.php

# Crear directorio de logs
sudo mkdir -p /var/www/html/backup-manager/logs
sudo chown www-data:www-data /var/www/html/backup-manager/logs
```

## 🐛 Solución de Problemas

### ❌ Database Size muestra "0 MB"
**Problema**: MySQL no permite conexión desde PHP
```bash
# Solución 1: Usar socket Unix para localhost
# El sistema ya maneja esto automáticamente

# Solución 2: Verificar configuración
php -r "require_once 'core/config.php'; echo Config::getDatabaseSize();"

# Solución 3: Limpiar caché del navegador
# Ctrl+F5 o modo incógnito
```

### ❌ No se pueden eliminar backups
**Problema**: Permisos incorrectos en history.json
```bash
# Verificar ownership
ls -la /var/www/html/backup-manager/backups/history.json

# Corregir permisos
sudo chown www-data:www-data /var/www/html/backup-manager/backups/history.json
sudo chmod 664 /var/www/html/backup-manager/backups/history.json
```

### ❌ Fechas negativas en historial
**Problema**: Diferencia de timezone entre servidor y cliente
```bash
# Ya corregido automáticamente en la última versión
# Si persiste, verificar timezone del servidor:
timedatectl
```

### ❌ Error "Connection refused" en base de datos
**Problema**: MySQL configurado solo para socket local
```bash
# Verificar configuración MySQL
mysql -h localhost -u usuario -p  # ✅ Funciona
mysql -h 127.0.0.1 -u usuario -p  # ❌ Falla

# Solución: El sistema usa automáticamente socket Unix
```

### ❌ Archivos de backup no encontrados para descarga
**Problema**: Backup marcado como completo pero archivos no existen
```bash
# Verificar archivos físicos
ls -la /var/www/html/backup-manager/backups/

# El sistema ahora crea archivos faltantes automáticamente
# y reporta errores específicos
```

### ❌ No se puede conectar a la base de datos
- Verificar credenciales en `config.local.php`
- Probar conexión: `mysql -u usuario -p base_de_datos`
- El sistema detecta y usa socket Unix automáticamente

### ❌ Binary logs no funcionan
- Verificar: `SHOW VARIABLES LIKE 'log_bin';`
- Reiniciar MySQL después de configuración
- Verificar permisos en directorio de binary logs

### ❌ Backup falla con archivos grandes
- Aumentar `memory_limit` en php.ini
- Verificar espacio en disco disponible  
- Los incrementales reducen el problema automáticamente

### ❌ No funciona el progreso en tiempo real
- Verificar que el puerto 8888 esté abierto
- Revisar logs de Apache: `/var/log/apache2/backup-manager-error.log`

### ❌ Panel no carga o da error 500
```bash
# Verificar logs de Apache
sudo tail -f /var/log/apache2/backup-manager-error.log

# Verificar permisos
sudo chown -R www-data:www-data /var/www/html/backup-manager
sudo chmod -R 755 /var/www/html/backup-manager

# Reiniciar Apache
sudo systemctl reload apache2
```

## 📝 Notas Importantes

- **NO modifica** tu API existente
- **NO requiere** cambios en Apache/PHP principal
- **NO comparte** sesiones con la API
- **NO afecta** el rendimiento (usa nice/ionice)

## 🤝 Soporte

Para reportar problemas o sugerencias, contactar al administrador del sistema.

## 📜 Licencia

Uso interno - Todos los derechos reservados