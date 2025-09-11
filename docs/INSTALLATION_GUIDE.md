# Guía de Instalación del Sistema de Backup Manager

## Requisitos Previos

- Servidor Linux con Apache2
- MariaDB/MySQL instalado
- PHP 7.4+ con extensiones: pdo, pdo_mysql
- Acceso root al servidor
- API PHP existente que requiera backup

## Proceso de Instalación Completa

### 1. Preparación del Servidor

```bash
# Actualizar sistema
apt update && apt upgrade -y

# Instalar dependencias necesarias
apt install apache2 mariadb-server php php-mysql php-cli unzip rsync pigz -y

# Verificar servicios activos
systemctl status apache2
systemctl status mariadb
```

### 2. Configuración de Binary Logs en MariaDB

**PASO CRÍTICO**: Para que funcionen los backups incrementales de base de datos, es necesario habilitar el binary logging.

#### 2.1 Localizar el archivo de configuración de MariaDB

```bash
# Buscar archivos de configuración
find /etc -name "*.cnf" 2>/dev/null | grep mysql

# Los archivos principales suelen ser:
# /etc/mysql/my.cnf (archivo principal)
# /etc/mysql/mariadb.conf.d/50-server.cnf (configuración del servidor)
```

#### 2.2 Editar la configuración del servidor MariaDB

Editar el archivo `/etc/mysql/mariadb.conf.d/50-server.cnf`:

```bash
nano /etc/mysql/mariadb.conf.d/50-server.cnf
```

Agregar estas líneas en la sección `[mysqld]`:

```ini
# Binary logging for incremental backups (added by Backup Manager)
log-bin = mysql-bin
binlog_format = ROW
expire_logs_days = 7
max_binlog_size = 100M
server-id = 1
```

**Explicación de parámetros:**
- `log-bin = mysql-bin`: Habilita binary logging con prefijo mysql-bin
- `binlog_format = ROW`: Formato más seguro para backups incrementales
- `expire_logs_days = 7`: Retiene logs por 7 días (ajustable según necesidades)
- `max_binlog_size = 100M`: Tamaño máximo por archivo de log
- `server-id = 1`: Identificador único del servidor (requerido)

#### 2.3 Reiniciar MariaDB

```bash
# Reiniciar el servicio
systemctl restart mariadb

# Verificar que inició correctamente
systemctl status mariadb

# Verificar que binary logging está habilitado
mysql -u root -p -e "SHOW VARIABLES LIKE 'log_bin';"
# Debería mostrar: log_bin = ON

# Verificar archivos de binary log
mysql -u root -p -e "SHOW BINARY LOGS;"
```

### 3. Configuración de Apache para el Backup Manager

#### 3.1 Crear Virtual Host en puerto 8888

Crear archivo `/etc/apache2/sites-available/backup-manager.conf`:

```apache
<VirtualHost *:8888>
    DocumentRoot /var/www/html/backup-manager
    ServerName backup-manager.local
    
    <Directory /var/www/html/backup-manager>
        AllowOverride All
        Require all granted
    </Directory>
    
    ErrorLog ${APACHE_LOG_DIR}/backup-manager_error.log
    CustomLog ${APACHE_LOG_DIR}/backup-manager_access.log combined
</VirtualHost>
```

#### 3.2 Configurar puerto 8888 en Apache

Editar `/etc/apache2/ports.conf` y agregar:

```apache
Listen 8888
```

#### 3.3 Habilitar sitio y reiniciar Apache

```bash
# Habilitar el sitio
a2ensite backup-manager

# Reiniciar Apache
systemctl restart apache2

# Verificar que está escuchando en 8888
netstat -tlnp | grep :8888
```

### 4. Instalación del Backup Manager

#### 4.1 Descargar e instalar

```bash
# Ir al directorio web
cd /var/www/html

# Clonar o descargar el backup-manager
# git clone [repositorio] backup-manager
# O descompimir desde ZIP

# Establecer permisos correctos
chown -R www-data:www-data backup-manager
chmod -R 755 backup-manager
chmod -R 777 backup-manager/backups
chmod -R 777 backup-manager/logs
```

#### 4.2 Configurar credenciales de base de datos

Editar `/var/www/html/backup-manager/core/config.php` si es necesario:

```php
// Verificar que la configuración apunte a tu API
private static function detectApiPath() {
    $possiblePaths = [
        '/var/www/html/apidian',  // Tu API actual
        '/var/www/html/api',
        // Agregar otras rutas según necesidad
    ];
    // ...
}
```

### 5. Verificación de la Instalación

#### 5.1 Probar acceso web

```bash
# Acceder a la interfaz web
# http://tu-servidor:8888
# Usuario: admin
# Contraseña: admin123
```

#### 5.2 Verificar detección de configuración

La aplicación debería mostrar:
- Tamaño de base de datos detectado (ej: 163.23 MB)
- Tamaño de storage detectado
- Historial de backups vacío inicialmente

#### 5.3 Probar backup manual

1. Hacer clic en "Database Backup"
2. Verificar que el progreso se actualiza en tiempo real
3. Revisar logs en `/var/www/html/backup-manager/logs/`

### 6. Configuración de Backups Automáticos (Opcional)

#### 6.1 Configurar crontab

```bash
# Editar crontab para www-data
sudo crontab -u www-data -e

# Agregar tareas automáticas (ejemplos):
# Backup diario de base de datos a las 2:00 AM
0 2 * * * /usr/bin/php /var/www/html/backup-manager/scripts/backup_database.php

# Backup semanal completo los domingos a las 3:00 AM
0 3 * * 0 /usr/bin/php /var/www/html/backup-manager/scripts/backup_full.php
```

### 7. Configuraciones de Seguridad Recomendadas

#### 7.1 Firewall

```bash
# Permitir puerto 8888 solo desde IPs específicas
ufw allow from [IP_AUTORIZADA] to any port 8888
```

#### 7.2 Cambiar credenciales por defecto

Editar `/var/www/html/backup-manager/index.php`:

```php
// Cambiar usuario y contraseña por defecto
if ($_POST['username'] === 'tu_nuevo_usuario' && $_POST['password'] === 'tu_nueva_contraseña') {
```

### 8. Solución de Problemas Comunes

#### 8.1 Binary logs no funcionan

```bash
# Verificar configuración
mysql -u root -p -e "SHOW VARIABLES LIKE '%log_bin%';"

# Verificar permisos del directorio de datos MySQL
ls -la /var/lib/mysql/mysql-bin.*
```

#### 8.2 Dashboard muestra 0 MB para base de datos

```bash
# Verificar conectividad PHP-MySQL
php -m | grep mysql

# Probar conexión manual
mysql -h localhost -u [usuario] -p [base_datos] -e "SELECT 1;"
```

#### 8.3 Permisos de archivos

```bash
# Restablecer permisos correctos
chown -R www-data:www-data /var/www/html/backup-manager
chmod -R 755 /var/www/html/backup-manager
chmod -R 777 /var/www/html/backup-manager/backups
chmod -R 777 /var/www/html/backup-manager/logs
```

## Notas Importantes

1. **Binary Logs son esenciales**: Sin binary logging habilitado, solo se harán backups completos
2. **Espacio en disco**: Los binary logs pueden crecer rápidamente. Ajustar `expire_logs_days` según necesidades
3. **Rendimiento**: Los binary logs tienen un impacto mínimo en el rendimiento pero consumen espacio adicional
4. **Seguridad**: Cambiar credenciales por defecto antes de poner en producción
5. **Monitoreo**: Revisar regularmente los logs en `/var/www/html/backup-manager/logs/`

## Archivos de Configuración Modificados

- `/etc/mysql/mariadb.conf.d/50-server.cnf` - Binary logging habilitado
- `/etc/apache2/sites-available/backup-manager.conf` - Virtual host
- `/etc/apache2/ports.conf` - Puerto 8888 agregado

## Comandos de Verificación Post-Instalación

```bash
# Verificar servicios
systemctl status apache2 mariadb

# Verificar binary logs
mysql -u root -p -e "SHOW BINARY LOGS;"

# Verificar puertos
netstat -tlnp | grep -E "(80|8888|3306)"

# Verificar logs de la aplicación
tail -f /var/www/html/backup-manager/logs/backup_$(date +%Y-%m-%d).log
```

Con esta guía, cualquier servidor nuevo debería quedar completamente funcional siguiendo estos pasos paso a paso.