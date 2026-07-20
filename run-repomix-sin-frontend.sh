#!/usr/bin/env bash
set -euo pipefail

# ============================================================
# run-repomix-sin-frontend.sh — Proyecto NEXO
# Empaqueta SOLO código fuente del backend (PHP, C++, Python, SQL)
# Excluye TODO el frontend: WebApp, landing, y cualquier frontend.
# ============================================================

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$SCRIPT_DIR"

# --- Detectar repomix ---
REPOMIX_CMD=""
if command -v repomix &>/dev/null; then
    REPOMIX_CMD="repomix"
elif command -v npx &>/dev/null; then
    REPOMIX_CMD="npx repomix"
else
    echo "[ERROR] repomix no está instalado."
    echo "Instálalo con:  npm install -g repomix"
    echo "    o con:      npx repomix ..."
    exit 1
fi

# --- Configuración ---
OUTPUT_FILE="nexo-sin-frontend.xml"

echo "========================================"
echo "  Generando XML SIN Frontend (NEXO)"
echo "  Se empaquetará: PHP, C++, Python, SQL"
echo "  Se excluirá: TODO el frontend (WebApp, landing, etc)"
echo "========================================"

# --- Ejecutar repomix ---
# Excluye todo el frontend explícitamente
$REPOMIX_CMD \
    --output "$OUTPUT_FILE" \
    --style xml \
    --verbose \
    --ignore "WebApp/**,landing/**,backend/alojamiento/frontend/**,backend/api/assets/**" \
    "$SCRIPT_DIR"

echo ""
echo "[OK] Archivo generado con éxito: $OUTPUT_FILE"
echo "[INFO] Tamaño: $(du -h "$OUTPUT_FILE" | cut -f1)"
echo "[INFO] Puedes subir $OUTPUT_FILE a tu IA favorita para analizar el backend puro."
