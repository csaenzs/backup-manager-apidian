# 🚀 Instalación Rápida - Backup Manager

Guía para instalar el Backup Manager en servidores nuevos con un solo comando.

---

## ⚡ Instalación Automática (1 Comando)

### Requisitos previos

El servidor debe tener:
- ✅ Ubuntu/Debian Linux
- ✅ Apache instalado y corriendo
- ✅ MySQL/MariaDB instalado
- ✅ PHP 7.2+ instalado
- ✅ Laravel API instalado con archivo `.env`

### Comando de Instalación

```bash
curl -sSL https://raw.githubusercontent.com/csaenzs/backup-manager-apidian/master/install.sh | sudo bash
```

**¡Eso es todo!** El script se encarga de todo automáticamente.

---

## 📋 ¿Qué hace el script automáticamente?

El script de instalación detecta y configura todo sin intervención:

### 1. Verifica dependencias
```
✓ php encontrado
✓ mysqldump encontrado
✓ git encontrado
✓ apache2ctl encontrado
```

### 2. Clona el repositorio desde GitHub
```
✓ Repositorio clonado exitosamente
✓ Archivos instalados en: /var/www/html/backup-manager
```

### 3. Auto-detecta la configuración
```
✓ Archivo .env encontrado: /var/www/html/apidian/.env
✓ Convirtiendo DB_HOST de 127.0.0.1 a localhost (usará socket Unix)
✓ Configuración de base de datos detectada:
  Host: localhost
  Puerto: 3306
  Base de datos: apidian
  Usuario: apidian
✓ Rutas del API detectadas:
  API: /var/www/html/apidian
  Storage: /var/www/html/apidian/storage
```

### 4. Configura Apache en puerto disponible
```
✓ Puerto disponible encontrado: 8080
✓ Puerto 8080 agregado a /etc/apache2/ports.conf
✓ Sitio backup-manager habilitado
✓ Configuración de Apache válida
✓ Apache reiniciado correctamente
```

### 5. Instalación completada
```
=========================================
  ✓ INSTALACIÓN COMPLETADA
=========================================

✓ Backup Manager instalado exitosamente

Acceso al panel:
  URL: http://10.150.0.2:8080/
  Usuario: admin
  Contraseña: admin123

Archivos:
  Instalación: /var/www/html/backup-manager
  Config: /var/www/html/backup-manager/config.local.php
  Backups: /var/www/html/backup-manager/backups/
  Logs: /var/www/html/backup-manager/logs/

Base de datos:
  Host: localhost:3306
  Database: apidian

⚠ IMPORTANTE: Cambia la contraseña de admin123 desde el panel
```

---

## 🔧 Configuración Automática

El script automáticamente detecta y configura:

### Base de Datos
- **Lee el archivo `.env`** de Laravel/API
- **Extrae credenciales** (DB_HOST, DB_PORT, DB_DATABASE, DB_USERNAME, DB_PASSWORD)
- **Convierte 127.0.0.1 a localhost** para usar socket Unix (más rápido)
- **Verifica conexión** a MySQL antes de continuar

### Rutas del API
- **Detecta la ruta del API** donde está el archivo `.env`
- **Configura storage_path** automáticamente (`api_path/storage`)
- **Lista para backups** de base de datos + archivos

### Puerto de Apache
- **Busca puerto disponible** automáticamente
- **Evita puertos en uso** (80, 81, 82)
- **Prioridad de puertos**: 8080 → 8081 → 8888 → 9090
- **Configura VirtualHost** en el puerto seleccionado
- **Agrega puerto** a `/etc/apache2/ports.conf`
- **Reinicia Apache** para aplicar cambios

---

## 🌐 Acceso después de la instalación

### 1. Encontrar la IP del servidor

```bash
hostname -I
```

Ejemplo de salida: `10.150.0.2`

### 2. Acceder al panel

```
URL: http://[IP_SERVIDOR]:[PUERTO]/
Usuario: admin
Contraseña: admin123
```

Ejemplo: `http://10.150.0.2:8080/`

### 3. Cambiar contraseña (IMPORTANTE)

1. Ir a pestaña **🔒 Seguridad**
2. Ingresar nueva contraseña
3. Guardar cambios

---

## 🔥 Servidores en Google Cloud Platform

Si el servidor está en GCP, el script te recordará:

```
⚠ SERVIDOR EN GOOGLE CLOUD PLATFORM DETECTADO
⚠ Debes abrir el puerto 8080 en el firewall de GCP:

  1. Ve a: https://console.cloud.google.com/networking/firewalls
  2. Crea una regla de firewall:
     - Nombre: allow-backup-manager
     - Destinos: Todas las instancias
     - Filtro de IP de origen: 0.0.0.0/0
     - Protocolos y puertos: tcp:8080
     - Acción: Permitir
```

---

## 🛠️ Solución de Problemas

### ❌ Error: "No se pudo auto-detectar la configuración"

**Causa:** No se encontró el archivo `.env` de Laravel

**Solución:**
```bash
# Verificar que existe el .env
ls -la /var/www/html/apidian/.env

# Si está en otra ubicación, ejecutar manualmente:
cd /var/www/html
git clone https://github.com/csaenzs/backup-manager-apidian.git backup-manager
cd backup-manager
sudo bash install.sh
# El script pedirá la configuración manualmente
```

### ❌ No puedo acceder al panel desde el navegador

**Verificar que Apache está escuchando:**
```bash
sudo ss -tlnp | grep apache2
```

**Debe mostrar algo como:**
```
LISTEN  *:8080  *:*  apache2
```

**Si estás en GCP, abre el puerto en el firewall:**
- Ve a Console de GCP → VPC Network → Firewall
- Crea regla permitiendo el puerto mostrado en la instalación

### ❌ Error de conexión a MySQL

**Verificar credenciales:**
```bash
# Ver configuración detectada
sudo cat /var/www/html/backup-manager/config.local.php | grep db_

# Probar conexión manual
mysql -h localhost -u usuario -p nombre_bd
```

---

## 📂 Archivos Importantes

Después de la instalación:

```
/var/www/html/backup-manager/
├── config.local.php          # Configuración (credenciales MySQL, rutas)
├── backups/                  # Backups generados aquí
│   ├── db_*.sql.gz          # Backups de base de datos
│   ├── storage_*.tar.gz     # Backups de archivos
│   └── history.json         # Historial de backups
├── logs/                     # Logs del sistema
│   └── backup_*.log
└── install.sh               # Script de instalación

/etc/apache2/
├── sites-available/
│   └── backup-manager.conf  # Configuración de Apache
└── ports.conf               # Puertos de Apache (8080 agregado aquí)
```

---

## 🔄 Reinstalación

Si necesitas reinstalar en el mismo servidor:

```bash
curl -sSL https://raw.githubusercontent.com/csaenzs/backup-manager-apidian/master/install.sh | sudo bash
```

El script:
- ✅ Detecta instalación anterior
- ✅ Crea backup de configuración en `/tmp/backup-manager-config-[fecha].tar.gz`
- ✅ Pregunta si deseas continuar (modo interactivo)
- ✅ Instala la nueva versión

---

## 📊 Primer Backup

Después de instalar, prueba el sistema:

### Desde el Panel Web

1. Acceder a `http://IP:PUERTO/`
2. Login: `admin` / `admin123`
3. Ir a pestaña **💾 Backup**
4. Click en **"Ejecutar Backup Completo"**
5. Ver progreso en tiempo real

### Desde Línea de Comandos

```bash
# Backup completo (base de datos + archivos)
sudo php /var/www/html/backup-manager/api/backup_worker_enhanced.php full

# Backup incremental
sudo php /var/www/html/backup-manager/api/backup_worker_enhanced.php incremental

# Ver logs
tail -f /var/www/html/backup-manager/logs/backup_*.log
```

---

## ⏰ Programar Backups Automáticos

1. Ir a pestaña **⏰ Programación**
2. Activar programación
3. Configurar horario:
   - Diario a las 2 AM: `0 2 * * *`
   - Cada 6 horas: `0 */6 * * *`
4. Seleccionar tipo: **Incremental** (recomendado)
5. Guardar

---

## 📞 Soporte

- **Documentación completa:** `/var/www/html/backup-manager/docs/INSTALACION.md`
- **Logs del sistema:** `/var/www/html/backup-manager/logs/`
- **Configuración:** `/var/www/html/backup-manager/config.local.php`

---

**✅ ¡Instalación lista en menos de 2 minutos!**

**Última actualización:** 2025-10-22
