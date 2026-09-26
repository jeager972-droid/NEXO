#!/bin/bash
# =============================================================================
# setup_nexo.sh — Script de setup y build para NEXO Edge en Linux.
# =============================================================================
# RESPONSABILIDAD:
#   Automatiza la instalación de dependencias, la creación de directorios de
#   datos/logs (/var/lib/nexo y /var/log/nexo), la verificación de estructura
#   de proyecto y la compilación con CMake (preset dev-x86).
#
# FLUJO:
#   1. Verifica que el SO sea Linux.
#   2. Detecta el gestor de paquetes (apt-get, dnf, yum).
#   3. Instala dependencias nativas según el gestor.
#   4. Crea directorios /var/lib/nexo y /var/log/nexo.
#   5. Verifica la carpeta "backend/edge".
#   6. Configura y compila con `cmake --preset dev-x86`.
#
# USO:
#   bash setup_nexo.sh
#
# ADVERTENCIAS:
#   - Asume estructura de directorios "backend/edge".
#   - No instala libspdlog ni catch2 de forma explícita (usa repositorios).
#   - Requiere privilegios de root para crear /var/lib/nexo y /var/log/nexo.
# =============================================================================

set -e  # Exit on error

echo "╔════════════════════════════════════════════════════════════════╗"
echo "║                                                              ║"
echo "║        NEXO EDGE - SETUP AND BUILD SCRIPT                    ║"
echo "║                                                              ║"
echo "╚════════════════════════════════════════════════════════════════╝"
echo ""

# Check if running on Linux
if [[ "$OSTYPE" != "linux-gnu"* ]]; then
    echo "❌ ERROR: This script is designed for Linux systems only."
    echo "   Current OS: $OSTYPE"
    exit 1
fi

echo "✅ Linux system detected: $(uname -a)"
echo ""

# Detect package manager
if command -v apt-get &> /dev/null; then
    PKG_MANAGER="apt-get"
    INSTALL_CMD="sudo apt-get update && sudo apt-get install -y"
elif command -v dnf &> /dev/null; then
    PKG_MANAGER="dnf"
    INSTALL_CMD="sudo dnf install -y"
elif command -v yum &> /dev/null; then
    PKG_MANAGER="yum"
    INSTALL_CMD="sudo yum install -y"
else
    echo "❌ ERROR: No supported package manager found (apt-get, dnf, yum)"
    exit 1
fi

echo "📦 Package manager detected: $PKG_MANAGER"
echo ""

# Install dependencies
echo "📥 Installing dependencies..."
if [[ "$PKG_MANAGER" == "apt-get" ]]; then
    sudo apt-get update
    sudo apt-get install -y \
        cmake \
        build-essential \
        libcurl4-openssl-dev \
        libssl-dev \
        libsqlite3-dev \
        libgpiod-dev \
        libspdlog-dev \
        libmosquitto-dev \
        g++ \
        git
elif [[ "$PKG_MANAGER" == "dnf" || "$PKG_MANAGER" == "yum" ]]; then
    sudo $PKG_MANAGER install -y \
        cmake \
        gcc-c++ \
        libcurl-devel \
        openssl-devel \
        sqlite-devel \
        libgpiod-devel \
        spdlog-devel \
        mosquitto-devel \
        git
fi

echo "✅ Dependencies installed successfully"
echo ""

# Create database directory
echo "📁 Creating database directory: /var/lib/nexo"
if [[ ! -d "/var/lib/nexo" ]]; then
    sudo mkdir -p /var/lib/nexo
    sudo chown $USER:$USER /var/lib/nexo
    echo "✅ Database directory created and permissions set"
else
    echo "✅ Database directory already exists"
fi

# Create logs directory
echo "📁 Creating logs directory: /var/log/nexo"
if [[ ! -d "/var/log/nexo" ]]; then
    sudo mkdir -p /var/log/nexo
    sudo chown $USER:$USER /var/log/nexo
    echo "✅ Logs directory created and permissions set"
else
    echo "✅ Logs directory already exists"
fi

# Crear directorio de despliegue /opt/nexo
echo "📁 Creating deployment directory: /opt/nexo"
if [[ ! -d "/opt/nexo" ]]; then
    sudo mkdir -p /opt/nexo
    sudo chown $USER:$USER /opt/nexo
    echo "✅ Deployment directory created"
else
    echo "✅ Deployment directory already exists"
fi

# Instalar systemd service para auto-start y auto-restart
echo "⚙️  Installing systemd service..."
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
if [[ -f "$SCRIPT_DIR/nexo-edge.service" ]]; then
    sudo cp "$SCRIPT_DIR/nexo-edge.service" /etc/systemd/system/nexo-edge.service
    sudo systemctl daemon-reload
    sudo systemctl enable nexo-edge.service
    echo "✅ systemd service installed and enabled (auto-start on boot)"
    echo "   Start with:  sudo systemctl start nexo-edge"
    echo "   Status with: sudo systemctl status nexo-edge"
    echo "   Logs with:   sudo journalctl -u nexo-edge -f"
else
    echo "⚠️  nexo-edge.service not found in $SCRIPT_DIR — skipping systemd setup"
fi

echo ""

# Check if we're in the correct directory
if [[ ! -d "backend/edge" ]]; then
    echo "❌ ERROR: This script must be run from the NEXO project root directory"
    echo "   Expected directory structure: backend/edge/"
    exit 1
fi

echo "✅ Project directory structure verified"
echo ""

# Build the project
echo "🔨 Building NEXO Edge with CMake (dev-x86 preset)..."
cd "backend/edge"

if [[ ! -d "build" ]]; then
    cmake --preset dev-x86
else
    echo "Build directory already exists, reconfiguring..."
    cmake --preset dev-x86
fi

echo ""
echo "🔨 Compiling project..."
cmake --build build --config Debug

echo ""
echo "╔════════════════════════════════════════════════════════════════╗"
echo "║                                                              ║"
echo "║        ✅ NEXO EDGE SETUP AND BUILD COMPLETE                 ║"
echo "║                                                              ║"
echo "╚════════════════════════════════════════════════════════════════╝"
echo ""
echo "📋 Next steps:"
echo "   1. Run the executable: ./build/nexo-edge"
echo "   2. Or test with: sudo ./build/nexo-edge (if GPIO access needed)"
echo ""
echo "📁 Directories created:"
echo "   - /var/lib/nexo (database)"
echo "   - /var/log/nexo (logs)"
echo ""
