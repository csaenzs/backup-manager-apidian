#!/bin/bash

# Backup Manager - Script de Restauración Incremental
# Uso: ./restore_incremental.sh [backup_date] [target_server]

set -e

# Colores para output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Configuración (editar según tu entorno)
BACKUP_PATH="/var/www/html/backup-manager/backups"
DB_HOST="localhost"
DB_USER="apidian"
DB_PASS="CmX-793744*+"
DB_NAME="apidian"

echo -e "${BLUE}🔄 Backup Manager - Restauración Incremental${NC}"
echo "================================================="

if [ $# -eq 0 ]; then
    echo -e "${YELLOW}Uso: $0 [backup_date] [opcional: target_server]${NC}"
    echo "Ejemplo: $0 20250911"
    echo ""
    echo "Backups disponibles:"
    ls -la ${BACKUP_PATH}/*_full.sql* 2>/dev/null | head -10 || echo "No se encontraron backups completos"
    exit 1
fi

BACKUP_DATE=$1
TARGET_SERVER=${2:-"local"}

# Función para logging
log() {
    echo -e "${GREEN}[$(date '+%Y-%m-%d %H:%M:%S')] $1${NC}"
}

error() {
    echo -e "${RED}[ERROR] $1${NC}"
    exit 1
}

warning() {
    echo -e "${YELLOW}[WARNING] $1${NC}"
}

# Verificar que existe el backup base
FULL_BACKUP=$(find ${BACKUP_PATH} -name "*${BACKUP_DATE}*_full.sql*" | head -1)
if [ -z "$FULL_BACKUP" ]; then
    error "No se encontró backup completo para la fecha: $BACKUP_DATE"
fi

log "Backup completo encontrado: $(basename $FULL_BACKUP)"

# Buscar todos los backups incrementales posteriores
INCREMENTAL_BACKUPS=($(find ${BACKUP_PATH} -name "*incremental.sql*" -newer "$FULL_BACKUP" | sort))

if [ ${#INCREMENTAL_BACKUPS[@]} -eq 0 ]; then
    warning "No se encontraron backups incrementales posteriores"
else
    log "Encontrados ${#INCREMENTAL_BACKUPS[@]} backups incrementales:"
    for backup in "${INCREMENTAL_BACKUPS[@]}"; do
        echo "  - $(basename $backup)"
    done
fi

# Confirmación del usuario
echo ""
echo -e "${YELLOW}⚠️  ADVERTENCIA: Esta operación SOBRESCRIBIRÁ la base de datos actual${NC}"
echo "Base de datos objetivo: $DB_NAME"
echo "Servidor objetivo: $TARGET_SERVER"
echo ""
read -p "¿Deseas continuar? (escribe 'CONFIRMAR' para proceder): " confirmation

if [ "$confirmation" != "CONFIRMAR" ]; then
    echo "Operación cancelada"
    exit 0
fi

# Crear backup de seguridad de la BD actual
log "Creando backup de seguridad de la BD actual..."
SAFETY_BACKUP="${BACKUP_PATH}/safety_backup_$(date +%Y%m%d_%H%M%S).sql"
if [ "$TARGET_SERVER" = "local" ]; then
    mysqldump -h $DB_HOST -u $DB_USER -p$DB_PASS $DB_NAME > "$SAFETY_BACKUP" || error "Error creando backup de seguridad"
    log "Backup de seguridad creado: $(basename $SAFETY_BACKUP)"
fi

# Función para descomprimir si es necesario
decompress_if_needed() {
    local file=$1
    if [[ $file == *.gz ]]; then
        log "Descomprimiendo: $(basename $file)"
        gunzip -c "$file"
    else
        cat "$file"
    fi
}

# Restaurar backup completo
log "🔄 Restaurando backup completo..."
if [ "$TARGET_SERVER" = "local" ]; then
    decompress_if_needed "$FULL_BACKUP" | mysql -h $DB_HOST -u $DB_USER -p$DB_PASS $DB_NAME || error "Error restaurando backup completo"
else
    # Para servidor remoto, solo mostrar comandos
    echo "Ejecutar en servidor destino:"
    echo "mysql -h $DB_HOST -u $DB_USER -p $DB_NAME < $(basename $FULL_BACKUP)"
fi

log "✅ Backup completo restaurado"

# Restaurar backups incrementales en orden
if [ ${#INCREMENTAL_BACKUPS[@]} -gt 0 ]; then
    log "🔄 Aplicando backups incrementales..."
    
    for backup in "${INCREMENTAL_BACKUPS[@]}"; do
        log "Aplicando: $(basename $backup)"
        if [ "$TARGET_SERVER" = "local" ]; then
            decompress_if_needed "$backup" | mysql -h $DB_HOST -u $DB_USER -p$DB_PASS $DB_NAME || error "Error aplicando incremental: $(basename $backup)"
        else
            echo "mysql -h $DB_HOST -u $DB_USER -p $DB_NAME < $(basename $backup)"
        fi
    done
    
    log "✅ Todos los incrementales aplicados"
fi

# Verificación final
if [ "$TARGET_SERVER" = "local" ]; then
    TABLES_COUNT=$(mysql -h $DB_HOST -u $DB_USER -p$DB_PASS $DB_NAME -e "SHOW TABLES;" | wc -l)
    log "✅ Restauración completada. Tablas en la BD: $((TABLES_COUNT-1))"
    
    echo ""
    echo -e "${GREEN}🎉 ¡Restauración exitosa!${NC}"
    echo "Backup de seguridad guardado en: $(basename $SAFETY_BACKUP)"
else
    echo ""
    echo -e "${GREEN}📋 Comandos generados para restauración en servidor remoto${NC}"
fi

echo ""
echo -e "${BLUE}Proceso finalizado$(date '+%Y-%m-%d %H:%M:%S')${NC}"