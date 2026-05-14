#!/bin/bash
# recalc_risk.sh - Recalcula métricas de riesgo para todas las escuelas activas
# Uso: ./recalc_risk.sh
# También puede ejecutarse manualmente o mediante cron:
#   0 2 * * * /path/to/recalc_risk.sh >> /var/log/nexo_recalc.log 2>&1

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
LOG_FILE="${SCRIPT_DIR}/../logs/recalc_risk.log"

mkdir -p "$(dirname "$LOG_FILE")"

log() {
    echo "[$(date -Iseconds)] $1" | tee -a "$LOG_FILE"
}

if [ -z "${DATABASE_URL:-}" ]; then
    # Intentar cargar desde .env si existe
    if [ -f "${SCRIPT_DIR}/../.env" ]; then
        export $(grep -v '^#' "${SCRIPT_DIR}/../.env" | xargs)
    fi
fi

if [ -z "${DATABASE_URL:-}" ]; then
    log "ERROR: DATABASE_URL no configurada"
    exit 1
fi

log "Iniciando recálculo de métricas de riesgo..."

# Recalcular para cada escuela activa
RESULT=$(psql "$DATABASE_URL" -t -c "
    SELECT COUNT(*) FROM (
        SELECT fn_recalculate_school_metrics(school_id)
        FROM schools
        WHERE active = TRUE
    ) AS recalc;
" 2>&1) || {
    log "ERROR: Fallo al ejecutar recálculo: $RESULT"
    exit 1
}

SCHOOLS_COUNT=$(echo "$RESULT" | xargs)
log "Recálculo completado. Escuelas procesadas: ${SCHOOLS_COUNT}"
