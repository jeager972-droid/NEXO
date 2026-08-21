#!/bin/bash
# NEXO Database Deployment Script
# Ejecuta el schema consolidado contra Supabase/PostgreSQL
#
# Uso:
#   DATABASE_URL="postgresql://..." ./deploy_db.sh
#
# El schema.sql es idempotente (IF NOT EXISTS / ON CONFLICT / DROP ... IF EXISTS)
# por lo que puede ejecutarse múltiples veces sin errores.

set -euo pipefail

DB_URL="${DATABASE_URL:-}"

if [ -z "$DB_URL" ]; then
    echo "ERROR: DATABASE_URL no está configurada."
    exit 1
fi

if ! command -v psql &> /dev/null; then
    echo "ERROR: psql no está instalado o no está en PATH."
    exit 1
fi

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SCHEMA_FILE="$SCRIPT_DIR/sql/schema.sql"

if [ ! -f "$SCHEMA_FILE" ]; then
    echo "ERROR: No se encontró $SCHEMA_FILE"
    exit 1
fi

echo "=========================================="
echo "NEXO Database Deployment"
echo "Target: $DB_URL"
echo "Schema: $SCHEMA_FILE"
echo "Date:   $(date -u '+%Y-%m-%d %H:%M:%S UTC')"
echo "=========================================="
echo ""

echo "[EXEC] Aplicando schema.sql..."
if psql "$DB_URL" -v ON_ERROR_STOP=1 -f "$SCHEMA_FILE" 2>&1 | sed 's/^/  /'; then
    echo "[OK]   Schema aplicado correctamente"
else
    echo "[FAIL] Error aplicando schema"
    exit 1
fi

echo ""
echo "=========================================="
echo "Deployment completado."
echo "=========================================="
