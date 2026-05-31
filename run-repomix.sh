#!/usr/bin/env bash
set -euo pipefail

# ============================================================
# run-repomix.sh — Proyecto NEXO
# Empaqueta solo el código fuente y archivos auditables,
# respetando .repomixignore.
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
OUTPUT_FILE="repomix-output.xml"
# Si quieres forzar un formato distinto, cámbialo aquí:
# FORMAT="xml"   # xml | markdown | plain

echo "========================================"
echo "  NEXO Repomix Audit Pack"
echo "  Directorio: $SCRIPT_DIR"
echo "  Comando:    $REPOMIX_CMD"
echo "========================================"

# --- Ejecutar repomix ---
# .repomixignore se lee automáticamente por repomix (no necesita flag)
$REPOMIX_CMD \
    --output "$OUTPUT_FILE" \
    --style xml \
    "$SCRIPT_DIR"

echo ""
echo "[OK] Archivo generado: $OUTPUT_FILE"
echo "[INFO] Tamaño: $(du -h "$OUTPUT_FILE" | cut -f1)"
