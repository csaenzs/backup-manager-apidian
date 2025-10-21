#!/bin/bash
#
# Script de instalación para Backup Manager
# Uso: bash install.sh
#

echo "========================================="
echo "  INSTALACIÓN DE BACKUP MANAGER"
echo "========================================="
echo ""

# Verificar que se ejecuta como root
if [ "$EUID" -ne 0 ]; then
   echo "Error: Este script debe ejecutarse como root"
   echo "Ejecuta: sudo bash install.sh"
   exit 1
fi

# Obtener directorio actual
INSTALL_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
echo "Directorio de instalación: $INSTALL_DIR"
echo ""

echo "Creando directorios..."
mkdir -p "$INSTALL_DIR/backups"
mkdir -p "$INSTALL_DIR/temp"
mkdir -p "$INSTALL_DIR/logs"
chown -R www-data:www-data "$INSTALL_DIR/backups" "$INSTALL_DIR/temp" "$INSTALL_DIR/logs"

echo "Verificando dependencias..."
command -v php >/dev/null 2>&1 || { echo "PHP no instalado"; exit 1; }
command -v mysqldump >/dev/null 2>&1 || { echo "mysqldump no instalado"; exit 1; }
echo "✓ Dependencias OK"
echo ""

# Configurar base de datos
if [ ! -f "$INSTALL_DIR/config.local.php" ]; then
    echo "=== CONFIGURACIÓN DE BASE DE DATOS ==="
    read -p "Host MySQL [localhost]: " DB_HOST
    DB_HOST=${DB_HOST:-localhost}
    
    read -p "Puerto MySQL [3306]: " DB_PORT
    DB_PORT=${DB_PORT:-3306}
    
    read -p "Nombre de base de datos: " DB_NAME
    read -p "Usuario MySQL: " DB_USER
    read -s -p "Contraseña MySQL: " DB_PASS
    echo ""
    
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
    echo "✓ Configuración creada"
fi

echo ""
echo "========================================="
echo "  ✓ INSTALACIÓN COMPLETADA"
echo "========================================="
echo "Panel: http://$(hostname -I | awk '{print $1}')/backup-manager/"
echo "Usuario: admin"
echo "Contraseña: admin123"
echo ""
echo "Archivos:"
echo "  Config: $INSTALL_DIR/config.local.php"
echo "  Backups: $INSTALL_DIR/backups/"
echo "  Logs: $INSTALL_DIR/logs/"
echo "========================================="
