#!/usr/bin/env bash
set -euo pipefail

# ============================================================
# run-repomix-puro.sh — Proyecto NEXO
# Empaqueta SOLO tus ~30,047 líneas de código fuente 100% puro.
# Excluye cualquier bundle autogenerado (assets de vite, etc).
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
OUTPUT_FILE="nexo-codigo-puro.xml"

echo "========================================"
echo "  Generando XML de Código Puro (NEXO)"
echo "  Se empaquetarán solo tus propias líneas."
echo "========================================"

# --- Ejecutar repomix ---
# Lee el .repomixignore donde están TODAS las exclusiones configuradas
# NO usamos --ignore ni --include para que .repomixignore tenga control total
$REPOMIX_CMD \
    --output "$OUTPUT_FILE" \
    --style xml \
    --verbose \
    "$SCRIPT_DIR"

echo ""
echo "[OK] Archivo generado con éxito: $OUTPUT_FILE"
echo "[INFO] Tamaño: $(du -h "$OUTPUT_FILE" | cut -f1)"
echo "[INFO] Puedes subir $OUTPUT_FILE a tu IA favorita para analizar el proyecto puro."
