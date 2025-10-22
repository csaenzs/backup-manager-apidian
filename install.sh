#!/bin/bash
#
# Script de instalación automatizada para Backup Manager
# Descarga desde GitHub y configura automáticamente
# Uso: curl -sSL https://raw.githubusercontent.com/csaenzs/backup-manager-apidian/master/install.sh | sudo bash
#

set -e  # Exit on error

echo "========================================="
echo "  INSTALACIÓN AUTOMÁTICA"
echo "  BACKUP MANAGER - APIDIAN"
echo "========================================="
echo ""

# Colores para output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Función para mensajes
log_info() {
    echo -e "${GREEN}✓${NC} $1"
}

log_warn() {
    echo -e "${YELLOW}⚠${NC} $1"
}

log_error() {
    echo -e "${RED}✗${NC} $1"
}

# Verificar que se ejecuta como root
if [ "$EUID" -ne 0 ]; then
   log_error "Este script debe ejecutarse como root"
   echo "Ejecuta: sudo bash install.sh"
   exit 1
fi

# Variables de configuración
GITHUB_REPO="https://github.com/csaenzs/backup-manager-apidian.git"
INSTALL_BASE="/var/www/html"
INSTALL_DIR="$INSTALL_BASE/backup-manager"
TEMP_DIR="/tmp/backup-manager-install-$$"

# Verificar dependencias del sistema
echo ""
echo "=== VERIFICANDO DEPENDENCIAS ==="
MISSING_DEPS=0

check_command() {
    if ! command -v $1 >/dev/null 2>&1; then
        log_error "$1 no está instalado"
        MISSING_DEPS=1
    else
        log_info "$1 encontrado"
    fi
}

check_command php
check_command mysqldump
check_command git
check_command apache2ctl

if [ $MISSING_DEPS -eq 1 ]; then
    echo ""
    log_error "Faltan dependencias. Instalando..."
    apt-get update -qq
    apt-get install -y php php-cli php-mysql php-mbstring php-json mysql-client git apache2 > /dev/null 2>&1
    log_info "Dependencias instaladas"
fi

# Verificar si Apache está corriendo
if ! systemctl is-active --quiet apache2; then
    log_warn "Apache no está corriendo. Iniciando..."
    systemctl start apache2
    systemctl enable apache2
fi

# Detectar si hay terminal interactivo
# Verificar si stdin es realmente interactivo (no un pipe)
if [ -t 0 ]; then
    INTERACTIVE=1
else
    INTERACTIVE=0
    log_warn "Modo no interactivo detectado (ejecutado desde curl/pipe)"
fi

# Verificar si stdin realmente funciona intentando acceder al terminal
if [ $INTERACTIVE -eq 1 ]; then
    # Verificar que /dev/tty existe y es accesible
    if [ ! -c /dev/tty ] || ! : < /dev/tty 2>/dev/null; then
        INTERACTIVE=0
        log_warn "Stdin no disponible para lectura interactiva (ejecutado desde pipe)"
    fi
fi

# Limpiar instalación anterior si existe
if [ -d "$INSTALL_DIR" ]; then
    log_warn "Instalación existente encontrada en $INSTALL_DIR"

    if [ $INTERACTIVE -eq 1 ]; then
        read -p "¿Deseas respaldar la configuración existente? (s/N): " BACKUP_CONFIG
        if [[ "$BACKUP_CONFIG" =~ ^[Ss]$ ]]; then
            BACKUP_FILE="/tmp/backup-manager-config-$(date +%Y%m%d-%H%M%S).tar.gz"
            tar -czf "$BACKUP_FILE" -C "$INSTALL_DIR" config.local.php backups/ logs/ 2>/dev/null || true
            log_info "Configuración respaldada en: $BACKUP_FILE"
        fi

        read -p "¿Continuar con la instalación? Esto eliminará los archivos actuales (s/N): " CONTINUE
        if [[ ! "$CONTINUE" =~ ^[Ss]$ ]]; then
            log_error "Instalación cancelada"
            exit 1
        fi
    else
        # Modo automático: respaldar siempre
        BACKUP_FILE="/tmp/backup-manager-config-$(date +%Y%m%d-%H%M%S).tar.gz"
        tar -czf "$BACKUP_FILE" -C "$INSTALL_DIR" config.local.php backups/ logs/ 2>/dev/null || true
        log_info "Configuración respaldada automáticamente en: $BACKUP_FILE"
        log_warn "Continuando instalación en modo automático..."
        sleep 2
    fi

    rm -rf "$INSTALL_DIR"
fi

# Clonar repositorio
echo ""
echo "=== DESCARGANDO DESDE GITHUB ==="
log_info "Clonando repositorio..."

# Intentar clonar repositorio público primero
GITHUB_URL="$GITHUB_REPO"

if git clone "$GITHUB_URL" "$TEMP_DIR" > /dev/null 2>&1; then
    log_info "Repositorio clonado exitosamente"
else
    # Si falla y es modo interactivo, pedir credenciales
    if [ $INTERACTIVE -eq 1 ]; then
        log_warn "No se pudo clonar como repositorio público"
        echo ""
        echo "=== CREDENCIALES DE GITHUB ==="
        echo "Repositorio: $GITHUB_REPO"
        read -p "Usuario de GitHub: " GITHUB_USER
        read -sp "Contraseña/Token de GitHub: " GITHUB_PASS
        echo ""

        if [ -z "$GITHUB_USER" ] || [ -z "$GITHUB_PASS" ]; then
            log_error "Usuario y contraseña son requeridos"
            exit 1
        fi

        # Crear URL con credenciales
        GITHUB_URL="https://${GITHUB_USER}:${GITHUB_PASS}@github.com/csaenzs/backup-manager-apidian.git"

        if git clone "$GITHUB_URL" "$TEMP_DIR" > /dev/null 2>&1; then
            log_info "Repositorio clonado exitosamente"
        else
            log_error "Error al clonar repositorio. Verifica tus credenciales."
            rm -rf "$TEMP_DIR"
            exit 1
        fi
    else
        log_error "Error al clonar repositorio."
        log_error "Si el repositorio es privado, ejecuta el script localmente:"
        log_error "  git clone https://github.com/csaenzs/backup-manager-apidian.git /var/www/html/backup-manager"
        log_error "  cd /var/www/html/backup-manager"
        log_error "  sudo bash install.sh"
        rm -rf "$TEMP_DIR"
        exit 1
    fi
fi

# Mover archivos al directorio de instalación
mkdir -p "$INSTALL_DIR"
mv "$TEMP_DIR"/* "$INSTALL_DIR/" 2>/dev/null || true
mv "$TEMP_DIR"/.* "$INSTALL_DIR/" 2>/dev/null || true
rm -rf "$TEMP_DIR"

log_info "Archivos instalados en: $INSTALL_DIR"

# Crear directorios necesarios
echo ""
echo "=== CONFIGURANDO DIRECTORIOS ==="
mkdir -p "$INSTALL_DIR/backups"
mkdir -p "$INSTALL_DIR/temp"
mkdir -p "$INSTALL_DIR/logs"
chown -R www-data:www-data "$INSTALL_DIR"
chmod 755 "$INSTALL_DIR/backups" "$INSTALL_DIR/temp" "$INSTALL_DIR/logs"
log_info "Directorios creados con permisos correctos"

# Auto-detectar configuración de Laravel API
echo ""
echo "=== AUTO-DETECTANDO CONFIGURACIÓN ==="

API_ENV_FILE=""
SEARCH_PATHS=(
    "/var/www/html/apidian/.env"
    "/var/www/apidian/.env"
    "/srv/apidian/.env"
    "/opt/apidian/.env"
)

for env_path in "${SEARCH_PATHS[@]}"; do
    if [ -f "$env_path" ]; then
        API_ENV_FILE="$env_path"
        log_info "Archivo .env encontrado: $env_path"
        break
    fi
done

if [ -z "$API_ENV_FILE" ]; then
    log_warn "No se encontró archivo .env de Laravel"
    log_warn "Buscando en toda la estructura de /var/www..."
    API_ENV_FILE=$(find /var/www -name ".env" -type f 2>/dev/null | grep -E "(apidian|laravel|api)" | head -1)

    if [ -n "$API_ENV_FILE" ]; then
        log_info ".env encontrado en: $API_ENV_FILE"
    fi
fi

# Extraer configuración de .env
if [ -n "$API_ENV_FILE" ] && [ -f "$API_ENV_FILE" ]; then
    DB_HOST=$(grep "^DB_HOST=" "$API_ENV_FILE" | cut -d '=' -f2 | tr -d '"' | tr -d "'")
    DB_PORT=$(grep "^DB_PORT=" "$API_ENV_FILE" | cut -d '=' -f2 | tr -d '"' | tr -d "'")
    DB_NAME=$(grep "^DB_DATABASE=" "$API_ENV_FILE" | cut -d '=' -f2 | tr -d '"' | tr -d "'")
    DB_USER=$(grep "^DB_USERNAME=" "$API_ENV_FILE" | cut -d '=' -f2 | tr -d '"' | tr -d "'")
    DB_PASS=$(grep "^DB_PASSWORD=" "$API_ENV_FILE" | cut -d '=' -f2 | tr -d '"' | tr -d "'")

    # Valores por defecto
    DB_HOST=${DB_HOST:-localhost}
    DB_PORT=${DB_PORT:-3306}

    # Convertir 127.0.0.1 a localhost para usar socket Unix
    if [ "$DB_HOST" = "127.0.0.1" ]; then
        log_info "Convirtiendo DB_HOST de 127.0.0.1 a localhost (usará socket Unix)"
        DB_HOST="localhost"
    fi

    # Detectar ruta del API y storage
    API_PATH=$(dirname "$API_ENV_FILE")
    STORAGE_PATH="$API_PATH/storage"

    if [ -n "$DB_NAME" ] && [ -n "$DB_USER" ]; then
        log_info "Configuración de base de datos detectada:"
        echo "  Host: $DB_HOST"
        echo "  Puerto: $DB_PORT"
        echo "  Base de datos: $DB_NAME"
        echo "  Usuario: $DB_USER"

        log_info "Rutas del API detectadas:"
        echo "  API: $API_PATH"
        echo "  Storage: $STORAGE_PATH"

        AUTO_CONFIG=1
    else
        log_warn "Configuración incompleta en .env"
        AUTO_CONFIG=0
    fi
else
    log_warn "No se pudo detectar configuración automáticamente"
    AUTO_CONFIG=0
    API_PATH=""
    STORAGE_PATH=""
fi

# Si no se pudo auto-detectar, solicitar manualmente
if [ $AUTO_CONFIG -eq 0 ]; then
    if [ $INTERACTIVE -eq 1 ]; then
        echo ""
        echo "=== CONFIGURACIÓN MANUAL DE BASE DE DATOS ==="
        read -p "Host MySQL [localhost]: " DB_HOST
        DB_HOST=${DB_HOST:-localhost}

        read -p "Puerto MySQL [3306]: " DB_PORT
        DB_PORT=${DB_PORT:-3306}

        read -p "Nombre de base de datos: " DB_NAME
        read -p "Usuario MySQL: " DB_USER
        read -sp "Contraseña MySQL: " DB_PASS
        echo ""
    else
        log_error "No se pudo auto-detectar la configuración de base de datos"
        log_error "Para instalación automática, asegúrate de tener un archivo .env"
        log_error "Ejecuta el script manualmente para configurar:"
        log_error "  cd /var/www/html/backup-manager"
        log_error "  sudo bash install.sh"
        exit 1
    fi
fi

# Crear config.local.php
echo ""
echo "=== CREANDO CONFIGURACIÓN ==="

# Preparar configuración de rutas del API
if [ -n "$API_PATH" ]; then
    API_CONFIG="    'api_path' => '$API_PATH',
    'storage_path' => '$STORAGE_PATH',"
else
    API_CONFIG="    // 'api_path' => '/var/www/html/apidian',
    // 'storage_path' => '/var/www/html/apidian/storage',"
fi

cat > "$INSTALL_DIR/config.local.php" <<EOF
<?php
\$config = [
    'server_name' => '$(hostname)',
    'detected_at' => '$(date '+%Y-%m-%d %H:%M:%S')',

    // Rutas del API
$API_CONFIG

    // Configuración de base de datos
    'db_host' => '$DB_HOST',
    'db_port' => '$DB_PORT',
    'db_name' => '$DB_NAME',
    'db_user' => '$DB_USER',
    'db_pass' => '$DB_PASS',

    // Rutas del backup manager
    'backup_path' => '$INSTALL_DIR/backups',
    'temp_path' => '$INSTALL_DIR/temp',
    'log_path' => '$INSTALL_DIR/logs',

    // Configuración de backups
    'retention_days' => 30,
    'compression' => 'medium',

    // Seguridad
    'admin_password' => password_hash('admin123', PASSWORD_DEFAULT),
];
return \$config;
?>
EOF

chmod 600 "$INSTALL_DIR/config.local.php"
chown www-data:www-data "$INSTALL_DIR/config.local.php"
log_info "Archivo de configuración creado"

# Probar conexión a la base de datos
echo ""
echo "=== VERIFICANDO CONEXIÓN A BASE DE DATOS ==="
TEST_RESULT=$(mysql -h "$DB_HOST" -P "$DB_PORT" -u "$DB_USER" -p"$DB_PASS" -e "SELECT 1" "$DB_NAME" 2>&1)
if [ $? -eq 0 ]; then
    log_info "Conexión a MySQL exitosa"
else
    log_error "No se pudo conectar a MySQL:"
    echo "$TEST_RESULT"
    log_warn "La instalación continuará, pero verifica la configuración"
fi

# Detectar puerto disponible
echo ""
echo "=== CONFIGURANDO SERVIDOR WEB ==="

# Buscar un puerto disponible (evitar 80, 81, 82 que pueden estar en uso por el API)
# Probar puertos: 8080, 8081, 8888, 9090
PORTS_TO_TRY=(8080 8081 8888 9090)
CURRENT_PORT=""

for port in "${PORTS_TO_TRY[@]}"; do
    if ! ss -tlnp 2>/dev/null | grep -q ":$port "; then
        CURRENT_PORT=$port
        log_info "Puerto disponible encontrado: $CURRENT_PORT"
        break
    else
        log_warn "Puerto $port en uso, probando siguiente..."
    fi
done

# Si no encontró ningún puerto disponible, usar 8080 de todas formas
if [ -z "$CURRENT_PORT" ]; then
    CURRENT_PORT=8080
    log_warn "No se encontró puerto libre, usando $CURRENT_PORT (puede requerir detener otro servicio)"
fi

# Configurar Apache
APACHE_CONF="/etc/apache2/sites-available/backup-manager.conf"

cat > "$APACHE_CONF" <<EOF
<VirtualHost *:$CURRENT_PORT>
    ServerName backup-manager
    DocumentRoot $INSTALL_DIR

    <Directory $INSTALL_DIR>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>

    ErrorLog \${APACHE_LOG_DIR}/backup-manager-error.log
    CustomLog \${APACHE_LOG_DIR}/backup-manager-access.log combined
</VirtualHost>
EOF

# Verificar si el puerto está configurado en Apache ports.conf
if ! grep -q "^Listen $CURRENT_PORT" /etc/apache2/ports.conf; then
    echo "Listen $CURRENT_PORT" >> /etc/apache2/ports.conf
    log_info "Puerto $CURRENT_PORT agregado a /etc/apache2/ports.conf"
else
    log_info "Puerto $CURRENT_PORT ya configurado en Apache"
fi

# Habilitar sitio y módulos necesarios
a2ensite backup-manager > /dev/null 2>&1 || true
a2enmod rewrite > /dev/null 2>&1 || true
log_info "Sitio backup-manager habilitado"

# Verificar configuración de Apache
if apache2ctl configtest 2>&1 | grep -q "Syntax OK"; then
    log_info "Configuración de Apache válida"
else
    log_error "Error en configuración de Apache, ejecutando configtest:"
    apache2ctl configtest
fi

# Reiniciar Apache para aplicar cambios
systemctl restart apache2
log_info "Apache reiniciado correctamente"

# Obtener IP del servidor
SERVER_IP=$(hostname -I | awk '{print $1}')

# Instalación completada
echo ""
echo "========================================="
echo "  ✓ INSTALACIÓN COMPLETADA"
echo "========================================="
echo ""
log_info "Backup Manager instalado exitosamente"
echo ""
echo "Acceso al panel:"
echo "  URL: http://$SERVER_IP:$CURRENT_PORT/"
echo "  Usuario: admin"
echo "  Contraseña: admin123"
echo ""
echo "Archivos:"
echo "  Instalación: $INSTALL_DIR"
echo "  Config: $INSTALL_DIR/config.local.php"
echo "  Backups: $INSTALL_DIR/backups/"
echo "  Logs: $INSTALL_DIR/logs/"
echo ""
echo "Base de datos:"
echo "  Host: $DB_HOST:$DB_PORT"
echo "  Database: $DB_NAME"
echo ""
log_warn "IMPORTANTE: Cambia la contraseña de admin123 desde el panel"
echo ""
echo "========================================="

# Mostrar logs en caso de error
if [ -f "$INSTALL_DIR/logs/backup_"*".log" ]; then
    echo ""
    echo "Últimas líneas del log:"
    tail -5 "$INSTALL_DIR/logs/backup_"*.log 2>/dev/null | head -10
fi
