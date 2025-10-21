# 📦 Guía de Instalación - Backup Manager

Guía completa para instalar el sistema de backups en un servidor nuevo.

---

## 🚀 Instalación Completamente Automática (Recomendada)

### **Método 1: Instalación desde GitHub (Un solo comando)**

Este método descarga e instala todo automáticamente desde el repositorio GitHub:

```bash
curl -sSL https://raw.githubusercontent.com/csaenzs/backup-manager-apidian/master/install.sh | sudo bash
```

**O si ya descargaste el repositorio:**

```bash
cd /var/www/html/backup-manager
sudo bash install.sh
```

**El script automáticamente:**
- ✅ Verifica e instala dependencias necesarias
- ✅ Clona el repositorio desde GitHub (solo pide usuario/contraseña de GitHub)
- ✅ Auto-detecta la configuración de la API Laravel (lee el archivo .env)
- ✅ Configura Apache en el puerto correcto
- ✅ Crea todos los directorios necesarios
- ✅ Establece permisos correctos
- ✅ Verifica la conexión a MySQL
- ✅ Instala el sistema listo para usar

**Solo necesitas proporcionar:**
- Usuario y contraseña/token de GitHub
- (Opcional) Credenciales MySQL si no se auto-detectan

**Ventajas:**
- 🔥 Instalación en 1-2 minutos
- 🔥 Sin afectar la API existente
- 🔥 Auto-detección de configuración
- 🔥 Respaldo automático de configuración anterior

---

## 🔧 Instalación Manual

### **Paso 1: Copiar archivos al servidor**

```bash
# Subir archivos por SFTP/SCP o clonar desde git
scp -r backup-manager/ usuario@servidor:/var/www/html/

# O clonar repositorio
cd /var/www/html
git clone https://github.com/tuusuario/backup-manager.git
```

### **Paso 2: Crear directorios**

```bash
cd /var/www/html/backup-manager
mkdir -p backups temp logs
chmod 755 backups temp logs
chown -R www-data:www-data backups temp logs
```

### **Paso 3: Configurar base de datos**

**Opción A: Archivo manual**

Copia el ejemplo y edítalo:

```bash
cp config.local.php.example config.local.php
nano config.local.php
```

Edita estos valores:

```php
<?php
$config = [
    // === CONFIGURACIÓN DE BASE DE DATOS ===
    'db_host' => 'localhost',        // ← Cambiar si es remoto
    'db_port' => '3306',             // ← Puerto de MySQL
    'db_name' => 'mi_base_datos',    // ← Nombre de tu BD
    'db_user' => 'usuario_mysql',    // ← Usuario MySQL
    'db_pass' => 'contraseña123',    // ← Contraseña MySQL

    // === RUTAS (normalmente no cambiar) ===
    'backup_path' => '/var/www/html/backup-manager/backups',
    'temp_path' => '/var/www/html/backup-manager/temp',
    'log_path' => '/var/www/html/backup-manager/logs',

    // === OPCIONAL: Archivos de aplicación ===
    'storage_path' => '/var/www/html/mi-app/storage',
    // O null si no hay archivos que respaldar:
    // 'storage_path' => null,

    // === CONFIGURACIÓN ===
    'retention_days' => 30,          // Días que se guardan backups
    'compression' => 'medium',       // low, medium, high

    // === SEGURIDAD ===
    'admin_password' => password_hash('admin123', PASSWORD_DEFAULT),
];

return $config;
?>
```

Guardar y asegurar permisos:

```bash
chmod 600 config.local.php
chown www-data:www-data config.local.php
```

**Opción B: Auto-detección desde Laravel/API**

Si tienes Laravel instalado con un archivo `.env`, el sistema puede auto-detectar:

```bash
# El sistema buscará automáticamente en:
/var/www/html/apidian/.env
/var/www/apidian/.env
/srv/apidian/.env
/opt/apidian/.env
```

Y leerá automáticamente:
```env
DB_HOST=localhost
DB_PORT=3306
DB_DATABASE=nombre_bd
DB_USERNAME=usuario
DB_PASSWORD=contraseña
```

### **Paso 4: Configurar permisos**

```bash
chown -R www-data:www-data /var/www/html/backup-manager
chmod -R 755 /var/www/html/backup-manager
chmod 600 /var/www/html/backup-manager/config.local.php
```

### **Paso 5: Configurar Apache/Nginx**

**Para Apache:**

Crear archivo `/etc/apache2/sites-available/backup-manager.conf`:

```apache
<VirtualHost *:80>
    DocumentRoot /var/www/html

    <Directory /var/www/html/backup-manager>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>

    ErrorLog ${APACHE_LOG_DIR}/backup-manager-error.log
    CustomLog ${APACHE_LOG_DIR}/backup-manager-access.log combined
</VirtualHost>
```

Habilitar:
```bash
sudo a2ensite backup-manager
sudo systemctl reload apache2
```

**Para Nginx:**

Agregar al archivo del sitio:

```nginx
location /backup-manager {
    alias /var/www/html/backup-manager;
    index index.php;

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php7.4-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $request_filename;
    }
}
```

Recargar:
```bash
sudo nginx -t
sudo systemctl reload nginx
```

### **Paso 6: Verificar instalación**

Accede a: `http://tu-servidor/backup-manager/`

Credenciales por defecto:
- Usuario: `admin`
- Contraseña: `admin123`

---

## ✅ Verificación Post-Instalación

### **1. Test de conexión a MySQL**

```bash
cd /var/www/html/backup-manager
php -r "
require 'core/config.php';
\$host = Config::get('db_host');
\$user = Config::get('db_user');
\$pass = Config::get('db_pass');
\$db = Config::get('db_name');
\$port = Config::get('db_port');

\$mysqli = new mysqli(\$host, \$user, \$pass, \$db, \$port);
if (\$mysqli->connect_error) {
    echo '❌ Error: ' . \$mysqli->connect_error . PHP_EOL;
} else {
    echo '✅ Conexión exitosa a MySQL' . PHP_EOL;
    echo 'Base de datos: ' . \$db . PHP_EOL;
}
"
```

### **2. Test de backup manual**

Desde el panel web:
1. Ir a pestaña "💾 Backup"
2. Click "Ejecutar Backup Completo"
3. Verificar que se crea el archivo en `/var/www/html/backup-manager/backups/`

### **3. Verificar logs**

```bash
tail -f /var/www/html/backup-manager/logs/backup_*.log
```

---

## 🔐 Seguridad Post-Instalación

### **1. Cambiar contraseña del panel**

1. Acceder al panel
2. Ir a "🔒 Seguridad"
3. Cambiar contraseña de `admin123` a una segura

### **2. Proteger archivo de configuración**

```bash
chmod 600 /var/www/html/backup-manager/config.local.php
```

### **3. Opcional: Proteger con .htaccess**

Crear `/var/www/html/backup-manager/.htaccess`:

```apache
# Proteger archivos sensibles
<FilesMatch "^(config\.local\.php|\.env)">
    Require all denied
</FilesMatch>

# Solo permitir acceso desde IPs específicas (opcional)
# Require ip 192.168.1.0/24
```

---

## 🗄️ Configuración de Múltiples Bases de Datos

Si necesitas respaldar **más de una base de datos**, edita `config.local.php`:

```php
$config = [
    // Base de datos principal
    'db_host' => 'localhost',
    'db_port' => '3306',
    'db_name' => 'principal',
    'db_user' => 'usuario',
    'db_pass' => 'password',

    // Bases de datos adicionales
    'additional_databases' => [
        [
            'host' => 'localhost',
            'port' => '3306',
            'name' => 'secundaria',
            'user' => 'usuario',
            'pass' => 'password',
        ],
        [
            'host' => '10.0.0.5',  // Servidor remoto
            'port' => '3306',
            'name' => 'otra_bd',
            'user' => 'usuario_remoto',
            'pass' => 'password',
        ],
    ],
];
```

---

## 📋 Requisitos del Sistema

### **Mínimos:**
- PHP 7.2 o superior
- MySQL 5.7 o superior (o MariaDB 10.2+)
- Apache 2.4 o Nginx
- 1 GB RAM
- 10 GB espacio en disco

### **Recomendados:**
- PHP 7.4 o 8.0
- MySQL 8.0
- 2 GB RAM
- 50 GB+ espacio en disco

### **Extensiones PHP requeridas:**
```bash
sudo apt-get install php php-cli php-mysql php-mbstring php-json
```

### **Herramientas del sistema:**
```bash
sudo apt-get install mysql-client tar gzip
```

### **Opcionales (para backup remoto):**
```bash
sudo apt-get install sshpass rsync awscli
```

---

## 🚨 Solución de Problemas

### **Error: "Connection refused" a MySQL**

Verificar que MySQL esté corriendo:
```bash
sudo systemctl status mysql
```

Verificar puerto:
```bash
sudo netstat -tlnp | grep 3306
```

### **Error: "Access denied" en MySQL**

Verificar credenciales:
```bash
mysql -h localhost -u usuario -p nombre_bd
```

Otorgar permisos si es necesario:
```sql
GRANT ALL PRIVILEGES ON nombre_bd.* TO 'usuario'@'localhost' IDENTIFIED BY 'password';
FLUSH PRIVILEGES;
```

### **Error: "Permission denied" al crear backup**

Verificar permisos:
```bash
ls -la /var/www/html/backup-manager/backups
chown -R www-data:www-data /var/www/html/backup-manager/backups
chmod 755 /var/www/html/backup-manager/backups
```

### **Panel muestra "Unauthorized"**

Limpiar cookies del navegador o usar modo incógnito.

---

## 📚 Próximos Pasos

Después de instalar:

1. ✅ **Cambiar contraseña** del panel
2. ✅ **Hacer backup de prueba** manual
3. ✅ **Programar backups automáticos** (tab Programación)
4. ✅ **Configurar zona horaria** (install.sh lo hace automáticamente)
5. ✅ **Verificar logs** regularmente
6. ✅ **Probar restauración** de un backup

---

## 🔄 Migración desde Otro Servidor

Si estás migrando la configuración:

```bash
# En servidor antiguo
tar -czf backup-manager-config.tar.gz config.local.php backups/ logs/

# En servidor nuevo
tar -xzf backup-manager-config.tar.gz -C /var/www/html/backup-manager/
chown -R www-data:www-data /var/www/html/backup-manager
```

---

## 📞 Soporte

- **Documentación:** `/var/www/html/backup-manager/docs/`
- **Logs:** `/var/www/html/backup-manager/logs/`
- **Archivo de configuración:** `/var/www/html/backup-manager/config.local.php`

---

**Última actualización:** 2025-10-21
