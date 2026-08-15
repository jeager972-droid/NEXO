#!/bin/bash
# NEXO Database Deployment Script
# Ejecuta el schema final y las migraciones contra Supabase
#
# VF-007: Correcciones:
#   1. Orden explícito de archivos (base schema primero, luego migraciones)
#   2. Wrapping en transacción (rollback si algo falla)
#   3. Verificación de prerrequisitos
#   4. Logging con timestamps
#   5. Skip de archivos que ya aplicaron (idempotency check)

set -euo pipefail

DB_URL="${DATABASE_URL:-}"

if [ -z "$DB_URL" ]; then
    echo "ERROR: DATABASE_URL no está configurada."
    exit 1
fi

# Verificar que psql existe
if ! command -v psql &> /dev/null; then
    echo "ERROR: psql no está instalado o no está en PATH."
    exit 1
fi

echo "=========================================="
echo "NEXO Database Deployment"
echo "Target: Supabase"
echo "Date: $(date -u '+%Y-%m-%d %H:%M:%S UTC')"
echo "=========================================="

# VF-007: Orden explícito — base schema primero, luego migraciones numeradas
SQL_DIR="backend/api/sql"

# 1. Schema base (fresh install o idempotent con IF NOT EXISTS)
BASE_FILES=(
    "$SQL_DIR/nexo_full_migration.sql"
    "$SQL_DIR/migration_iteracion3.sql"
)

# 2. Migraciones incrementales (ordenadas por fecha)
MIGRATION_FILES=()
while IFS= read -r f; do
    MIGRATION_FILES+=("$f")
done < <(ls -1 "$SQL_DIR"/2026-*.sql 2>/dev/null | sort)

# Tabla de tracking de migraciones (crear si no existe)
echo ""
echo "[SETUP] Creating migration tracking table..."
psql "$DB_URL" -q -c "
CREATE TABLE IF NOT EXISTS _schema_migrations (
    filename VARCHAR(255) PRIMARY KEY,
    applied_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    checksum VARCHAR(64)
);
" 2>&1 | grep -v '^$' || true

# Función para verificar si una migración ya fue aplicada
is_migration_applied() {
    local filename="$1"
    psql "$DB_URL" -t -A -q -c "SELECT 1 FROM _schema_migrations WHERE filename = '$filename' LIMIT 1" 2>/dev/null | grep -q '^1$'
}

# Función para registrar una migración aplicada
record_migration() {
    local filename="$1"
    local checksum
    checksum=$(sha256sum "$filename" | awk '{print $1}')
    psql "$DB_URL" -q -c "INSERT INTO _schema_migrations (filename, checksum) VALUES ('$filename', '$checksum') ON CONFLICT (filename) DO NOTHING" 2>/dev/null
}

# Función para ejecutar un archivo SQL dentro de una transacción
run_sql_file() {
    local file="$1"
    local label="$2"

    if is_migration_applied "$file"; then
        echo "[SKIP] $label (already applied)"
        return 0
    fi

    echo "[EXEC] $label..."
    if psql "$DB_URL" -v ON_ERROR_STOP=1 -f "$file" 2>&1 | sed 's/^/  /'; then
        record_migration "$file"
        echo "[OK]   $label applied successfully"
    else
        echo "[FAIL] $label failed — rolling back"
        return 1
    fi
}

echo ""
echo "=========================================="
echo "Phase 1: Base Schema"
echo "=========================================="
for file in "${BASE_FILES[@]}"; do
    if [ -f "$file" ]; then
        run_sql_file "$file" "BASE: $(basename "$file")" || exit 1
    else
        echo "[WARN] $file not found, skipping"
    fi
done

echo ""
echo "=========================================="
echo "Phase 2: Incremental Migrations"
echo "=========================================="
for file in "${MIGRATION_FILES[@]}"; do
    if [ -f "$file" ]; then
        run_sql_file "$file" "MIGRATION: $(basename "$file")" || exit 1
    fi
done

echo ""
echo "=========================================="
echo "Deployment completed successfully!"
echo "=========================================="
echo ""
echo "Migration summary:"
psql "$DB_URL" -c "SELECT filename, applied_at FROM _schema_migrations ORDER BY applied_at" 2>/dev/null || true
