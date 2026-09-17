#!/bin/sh
# =============================================================================
# nexo-edge-run.sh — Wrapper de arranque con recuperación OTA (Bloque D).
# =============================================================================
# El binario `nexo-edge` se auto-actualiza con swap atómico:
#   nexo-edge.new → nexo-edge, copia de seguridad en nexo-edge.bak y bandera
#   nexo-edge.pending mientras la actualización espera confirmación.
#
# Si el binario nuevo muere antes de arrancar (crash previo a OtaManager::onBoot
# — por ejemplo una librería faltante), este wrapper detecta la bandera
# `.pending` + salida anómala y restaura `.bak` automáticamente.
#
# Uso (systemd):
#   ExecStart=/opt/nexo/nexo-edge-run.sh
# =============================================================================

BIN="${NEXO_BIN:-/opt/nexo/nexo-edge}"
PENDING="$BIN.pending"
BACKUP="$BIN.bak"
FAILS=0
MAX_FAILS=3

while true; do
    "$BIN"
    RC=$?

    if [ -f "$PENDING" ]; then
        if [ "$RC" -ne 0 ]; then
            FAILS=$((FAILS + 1))
            echo "[nexo-edge-run] binario actualizado murió (rc=$RC, intento $FAILS)" >&2
            if [ "$FAILS" -ge "$MAX_FAILS" ] && [ -f "$BACKUP" ]; then
                echo "[nexo-edge-run] restaurando $BACKUP tras $FAILS fallos" >&2
                mv -f "$BACKUP" "$BIN"
                rm -f "$PENDING"
                FAILS=0
            fi
        else
            # Salida limpia con actualización pendiente: el propio proceso decide
            # (pending_confirm → APPLIED borra la bandera; boot-loop → ROLLED_BACK)
            FAILS=0
        fi
    else
        FAILS=0
    fi

    sleep 3
done
