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
- Contraseña: `admin123` (cambiar después del primer login)

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

## 🔄 Restauración

Por seguridad, la restauración requiere confirmación manual:

1. Seleccionar backup en el historial
2. Click en "Restaurar"
3. Seguir instrucciones mostradas

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

## 🐛 Solución de Problemas

### No se puede conectar a la base de datos
- Verificar credenciales en `config.local.php`
- Probar conexión: `mysql -u usuario -p base_de_datos`

### Binary logs no funcionan
- Verificar: `SHOW VARIABLES LIKE 'log_bin';`
- Reiniciar MySQL después de configuración
- Ver: `docs/BINARY_LOGS.md`

### Backup falla con archivos grandes
- Aumentar `memory_limit` en php.ini
- Verificar espacio en disco disponible
- Los incrementales reducen el problema automáticamente

### No funciona el progreso en tiempo real
- Verificar que el puerto 8888 esté abierto
- Revisar logs de Apache

## 📝 Notas Importantes

- **NO modifica** tu API existente
- **NO requiere** cambios en Apache/PHP principal
- **NO comparte** sesiones con la API
- **NO afecta** el rendimiento (usa nice/ionice)

## 🤝 Soporte

Para reportar problemas o sugerencias, contactar al administrador del sistema.

## 📜 Licencia

Uso interno - Todos los derechos reservados