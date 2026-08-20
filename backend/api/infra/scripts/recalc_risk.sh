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

log "Iniciando recálculo de métricas de riesgo (Motor v3.0)..."

# Recalcular para cada escuela activa usando el nuevo motor v3
RESULT=$(psql "$DATABASE_URL" -t -c "
    SELECT COALESCE(SUM(fn_recalculate_school_risk_v3(school_id)), 0)
    FROM schools
    WHERE active = TRUE;
" 2>&1) || {
    log "ERROR: Fallo al ejecutar recálculo v3: $RESULT"
    exit 1
}

STUDENTS_COUNT=$(echo "$RESULT" | xargs)
log "Recálculo v3 completado. Estudiantes procesados: ${STUDENTS_COUNT}"

# También ejecutar el motor legacy para compatibilidad (durante transición)
log "Ejecutando recálculo legacy para compatibilidad..."
RESULT_LEGACY=$(psql "$DATABASE_URL" -t -c "
    SELECT COUNT(*) FROM (
        SELECT fn_recalculate_school_metrics(school_id)
        FROM schools
        WHERE active = TRUE
    ) AS recalc;
" 2>&1) || {
    log "WARN: Recálculo legacy falló (no crítico): $RESULT_LEGACY"
}

log "Recálculo completado."
