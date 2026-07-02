#!/bin/bash
# create_monthly_partition.sh - Crea partición mensual para biometric_events si no existe
# Uso: ./create_monthly_partition.sh [YYYY-MM]
# Si no se especifica fecha, crea la partición del mes siguiente

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
LOG_FILE="${SCRIPT_DIR}/../logs/partition_creation.log"

mkdir -p "$(dirname "$LOG_FILE")"

log() {
    echo "[$(date -Iseconds)] $1" | tee -a "$LOG_FILE"
}

if [ -z "${DATABASE_URL:-}" ]; then
    if [ -f "${SCRIPT_DIR}/../.env" ]; then
        export $(grep -v '^#' "${SCRIPT_DIR}/../.env" | xargs)
    fi
fi

if [ -z "${DATABASE_URL:-}" ]; then
    log "ERROR: DATABASE_URL no configurada"
    exit 1
fi

# Calcular mes siguiente si no se especifica
if [ -n "${1:-}" ]; then
    TARGET_MONTH="$1"
else
    TARGET_MONTH=$(date -d "+1 month" +%Y-%m)
fi

YEAR="${TARGET_MONTH:0:4}"
MONTH="${TARGET_MONTH:5:2}"

# Calcular inicio y fin del mes
PARTITION_START="${YEAR}-${MONTH}-01"
PARTITION_END=$(date -d "${YEAR}-${MONTH}-01 +1 month" +%Y-%m-%d)

PARTITION_NAME="biometric_events_${YEAR}_${MONTH}"

log "Verificando partición ${PARTITION_NAME} para rango [${PARTITION_START}, ${PARTITION_END})..."

# Verificar si la partición ya existe
EXISTS=$(psql "$DATABASE_URL" -t -c "
    SELECT EXISTS (
        SELECT 1 FROM pg_class c
        JOIN pg_namespace n ON n.oid = c.relnamespace
        WHERE c.relname = '${PARTITION_NAME}'
        AND n.nspname = 'public'
    );
" 2>&1 | xargs)

if [ "$EXISTS" = "t" ]; then
    log "Partición ${PARTITION_NAME} ya existe. No se requiere acción."
    exit 0
fi

log "Creando partición ${PARTITION_NAME}..."

psql "$DATABASE_URL" -c "
    CREATE TABLE IF NOT EXISTS ${PARTITION_NAME} PARTITION OF biometric_events
    FOR VALUES FROM ('${PARTITION_START}') TO ('${PARTITION_END}');
" 2>&1 || {
    log "ERROR: Fallo al crear partición ${PARTITION_NAME}"
    exit 1
}

log "Partición ${PARTITION_NAME} creada exitosamente."
