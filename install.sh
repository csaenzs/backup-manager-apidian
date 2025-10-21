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

# Solicitar credenciales de GitHub
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

# Limpiar instalación anterior si existe
if [ -d "$INSTALL_DIR" ]; then
    log_warn "Instalación existente encontrada en $INSTALL_DIR"
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
    rm -rf "$INSTALL_DIR"
fi

# Clonar repositorio
echo ""
echo "=== DESCARGANDO DESDE GITHUB ==="
log_info "Clonando repositorio..."

if git clone "$GITHUB_URL" "$TEMP_DIR" > /dev/null 2>&1; then
    log_info "Repositorio clonado exitosamente"
else
    log_error "Error al clonar repositorio. Verifica tus credenciales."
    rm -rf "$TEMP_DIR"
    exit 1
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

    if [ -n "$DB_NAME" ] && [ -n "$DB_USER" ]; then
        log_info "Configuración de base de datos detectada:"
        echo "  Host: $DB_HOST"
        echo "  Puerto: $DB_PORT"
        echo "  Base de datos: $DB_NAME"
        echo "  Usuario: $DB_USER"

        AUTO_CONFIG=1
    else
        log_warn "Configuración incompleta en .env"
        AUTO_CONFIG=0
    fi
else
    log_warn "No se pudo detectar configuración automáticamente"
    AUTO_CONFIG=0
fi

# Si no se pudo auto-detectar, solicitar manualmente
if [ $AUTO_CONFIG -eq 0 ]; then
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
fi

# Crear config.local.php
echo ""
echo "=== CREANDO CONFIGURACIÓN ==="

cat > "$INSTALL_DIR/config.local.php" <<EOF
<?php
\$config = [
    'server_name' => '$(hostname)',
    'detected_at' => '$(date '+%Y-%m-%d %H:%M:%S')',
    'db_host' => '$DB_HOST',
    'db_port' => '$DB_PORT',
    'db_name' => '$DB_NAME',
    'db_user' => '$DB_USER',
    'db_pass' => '$DB_PASS',
    'backup_path' => '$INSTALL_DIR/backups',
    'temp_path' => '$INSTALL_DIR/temp',
    'log_path' => '$INSTALL_DIR/logs',
    'retention_days' => 30,
    'compression' => 'medium',
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

# Intentar detectar el puerto actual si ya existe configuración
CURRENT_PORT=81
if ss -tlnp 2>/dev/null | grep -q ":81 "; then
    log_info "Puerto 81 en uso (configuración actual)"
    CURRENT_PORT=81
elif ss -tlnp 2>/dev/null | grep -q ":8080 "; then
    CURRENT_PORT=8081
    log_info "Usando puerto alternativo: $CURRENT_PORT"
else
    CURRENT_PORT=81
    log_info "Usando puerto: $CURRENT_PORT"
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

# Verificar si el puerto está configurado en Apache
if ! grep -q "^Listen $CURRENT_PORT" /etc/apache2/ports.conf; then
    echo "Listen $CURRENT_PORT" >> /etc/apache2/ports.conf
    log_info "Puerto $CURRENT_PORT agregado a Apache"
fi

# Habilitar sitio
a2ensite backup-manager > /dev/null 2>&1 || true
a2enmod rewrite > /dev/null 2>&1 || true

# Recargar Apache
systemctl reload apache2 2>/dev/null || systemctl restart apache2

log_info "Apache configurado correctamente"

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
