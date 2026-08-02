#!/bin/bash
# =============================================================================
# nexo-reader.sh — Arranque del flujo de trabajo del lector U.are.U 5300.
# =============================================================================
# RESPONSABILIDAD:
#   Un solo comando para dejar el lector operativo en todo el sistema:
#     1. Verifica que el lector U.are.U (VID 05ba) esté conectado.
#     2. Verifica/instala las udev rules del SDK (con --setup, requiere sudo).
#     3. Compila nexo-edge si el binario no existe o hay fuentes más nuevas.
#     4. Lanza nexo-edge con el menú interactivo:
#          1. MODO PERPETUO  -> poner huella -> identifica -> registra asistencia
#                               y la sincroniza a la nube siempre (offline -> cola).
#          3. MODO SECRETARIA -> enrolar/eliminar estudiantes localmente.
#        Además, si mqtt_host está configurado, escucha comandos remotos de la
#        WebApp: ENROLL_REQUEST (enrolar), AUTHORIZE_EXIT (autorizar salida),
#        DELETE_STUDENT, FORCE_SYNC, RELOAD_CONFIG.
#
# USO:
#   ./nexo-reader.sh            # verificar + compilar si hace falta + arrancar
#   ./nexo-reader.sh --setup    # instala udev rules (sudo) y sale
#   ./nexo-reader.sh --build    # fuerza recompilación antes de arrancar
#
# CONFIG:
#   Lee build/dev/bin/config.json (cwd al ejecutar). Ajusta "biometric_sensor",
#   "api_url" y "mqtt_host" según el entorno.
# =============================================================================

set -euo pipefail

EDGE_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BUILD_DIR="$EDGE_DIR/build/dev"
BIN_DIR="$BUILD_DIR/bin"
BIN="$BIN_DIR/nexo-edge"
SDK_REDIST="$EDGE_DIR/sensorvendor/uareu5300/redist"

log()  { echo -e "[nexo-reader] $*"; }
warn() { echo -e "[nexo-reader] \033[33m$*\033[0m"; }
err()  { echo -e "[nexo-reader] \033[31m$*\033[0m" >&2; }

# --- --setup: instalar udev rules ------------------------------------------------
if [[ "${1:-}" == "--setup" ]]; then
    log "Instalando udev rules del SDK U.are.U (requiere sudo)..."
    for rule in 99-dp5k.rules 99-dp4k.rules 99-cm7k.rules 99-touchip.rules; do
        if [[ -f "$SDK_REDIST/$rule" ]]; then
            sudo cp "$SDK_REDIST/$rule" /etc/udev/rules.d/
            log "  instalada $rule"
        fi
    done
    sudo udevadm control --reload-rules
    sudo udevadm trigger
    log "udev rules instaladas y recargadas. Desconecta y reconecta el lector."
    exit 0
fi

# --- 1. Verificar lector ----------------------------------------------------------
if ! lsusb -d 05ba: >/dev/null 2>&1; then
    warn "No se detecta ningún lector DigitalPersona (VID 05ba) conectado."
    warn "nexo-edge arrancará igualmente y reintentará abrir el lector (reconnect)."
else
    log "Lector detectado: $(lsusb -d 05ba: | head -1)"
    # Avisar si el kernel lo tomó como cámara UVC (bloquea al SDK)
    if lsusb -t 2>/dev/null | grep -qi uvcvideo; then
        warn "Se detectó driver uvcvideo activo. Si el lector no abre, ejecuta:"
        warn "  sudo ./nexo-reader.sh --setup   (instala las rules anti-uvcvideo)"
    fi
fi

# --- 2. Compilar si hace falta ----------------------------------------------------
NEEDS_BUILD=0
[[ "${1:-}" == "--build" ]] && NEEDS_BUILD=1
[[ ! -x "$BIN" ]] && NEEDS_BUILD=1
if [[ $NEEDS_BUILD -eq 0 ]] && find "$EDGE_DIR/src" "$EDGE_DIR/include" -name '*.cpp' -o -name '*.h' | xargs -r ls -t 2>/dev/null | head -1 | xargs -r test "$BIN" -ot; then
    NEEDS_BUILD=1
fi

if [[ $NEEDS_BUILD -eq 1 ]]; then
    log "Compilando nexo-edge..."
    if [[ ! -f "$BUILD_DIR/CMakeCache.txt" ]]; then
        cmake -S "$EDGE_DIR" -B "$BUILD_DIR" -DCMAKE_BUILD_TYPE=RelWithDebInfo
    fi
    cmake --build "$BUILD_DIR" --target nexo-edge -j"$(nproc)"
    log "Compilación OK: $BIN"
fi

# --- 3. Config --------------------------------------------------------------------
if [[ ! -f "$BIN_DIR/config.json" ]]; then
    warn "No existe $BIN_DIR/config.json; se crea desde config.example.json."
    cp "$EDGE_DIR/config.example.json" "$BIN_DIR/config.json"
    warn "Edítalo (api_url, device_id, mqtt_host) y vuelve a ejecutar."
fi
SENSOR=$(grep -o '"biometric_sensor"[^,}]*' "$BIN_DIR/config.json" | cut -d'"' -f4 || true)
if [[ "$SENSOR" != "uareu5300" ]]; then
    warn "config.json tiene biometric_sensor='${SENSOR:-dev_stub}'. Ajustando a 'uareu5300'..."
    if grep -q '"biometric_sensor"' "$BIN_DIR/config.json"; then
        sed -i 's/"biometric_sensor"[^,}]*/"biometric_sensor": "uareu5300"/' "$BIN_DIR/config.json"
    else
        sed -i 's/^{/{\n  "biometric_sensor": "uareu5300",/' "$BIN_DIR/config.json"
    fi
fi

# --- 4. Arrancar ------------------------------------------------------------------
log "Arrancando NEXO Edge (sensor: uareu5300). Ctrl+C para salir."
log "Menú: 1=Modo Perpetuo (asistencia)  3=Secretaría (enrolar)  0=Salir"
echo
cd "$BIN_DIR"
exec ./nexo-edge
