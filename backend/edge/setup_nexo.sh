#!/bin/bash
# NEXO Edge Setup and Build Script
# This script installs dependencies and builds the NEXO Edge project for Linux

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

echo ""

# Check if we're in the correct directory
if [[ ! -d "Logica de negocio/edge" ]]; then
    echo "❌ ERROR: This script must be run from the NEXO project root directory"
    echo "   Expected directory structure: Logica de negocio/edge/"
    exit 1
fi

echo "✅ Project directory structure verified"
echo ""

# Build the project
echo "🔨 Building NEXO Edge with CMake (dev-x86 preset)..."
cd "Logica de negocio/edge"

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
