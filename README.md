# 🛡️ Backup Manager

Sistema de gestión de backups en caliente para APIs Laravel/PHP con interfaz web.

## ✨ Características

- ✅ **Backups en caliente** sin interrumpir el servicio
- 📊 **Progreso en tiempo real** con WebSockets
- ⏰ **Programación automática** (diario/semanal/mensual)
- 💾 **Soporte para backups grandes** (50GB+)
- 🔄 **Backups incrementales** para optimizar espacio
- 🎯 **100% compatible** con tu configuración actual
- 🚀 **Sin afectar el rendimiento** de la API

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

### Programación Automática
1. Ir a sección "Programación"
2. Activar backup automático
3. Seleccionar frecuencia y hora
4. Guardar configuración

### Configuración
- **Retención**: Días para mantener backups antiguos
- **Compresión**: none/low/medium/high
- **Destino**: Ruta donde guardar backups

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

## 🐛 Solución de Problemas

### No se puede conectar a la base de datos
- Verificar credenciales en `config.local.php`
- Probar conexión: `mysql -u usuario -p base_de_datos`

### Backup falla con archivos grandes
- Aumentar `memory_limit` en php.ini
- Verificar espacio en disco disponible

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