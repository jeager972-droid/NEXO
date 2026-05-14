#!/bin/bash
# Install dependencies for NEXO Edge on Debian/Ubuntu (x86_64)
# Run with: sudo bash scripts/install_deps_debian.sh

set -e

echo "=== Installing NEXO Edge dependencies (Debian/Ubuntu) ==="

# Update package list
sudo apt-get update

# Core build tools
sudo apt-get install -y \
    build-essential \
    cmake \
    clang-format \
    clang-tidy \
    git

# SQLite
sudo apt-get install -y \
    libsqlite3-dev

# Crypto (OpenSSL)
sudo apt-get install -y \
    libssl-dev

# HTTP client (libcurl)
sudo apt-get install -y \
    libcurl4-openssl-dev

# GPIO for Raspberry Pi (libgpiod)
# Note: This is for ARM64 Pi. On x86_64 dev, it's safe to install but won't be used.
sudo apt-get install -y \
    libgpiod-dev

# Logging (spdlog)
sudo apt-get install -y \
    libspdlog-dev

# Testing framework (Catch2)
# Catch2 v3 is available in newer distros. Fallback to header-only if needed.
sudo apt-get install -y \
    catch2 || echo "Catch2 not found in apt, will use header-only fallback"

echo "=== Dependencies installed successfully ==="
echo "Note: Some packages (libgpiod) are for ARM64 Pi and won't be used on x86_64 dev."
