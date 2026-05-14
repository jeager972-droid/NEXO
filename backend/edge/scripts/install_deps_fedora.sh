#!/bin/bash
# Install dependencies for NEXO Edge on Fedora/RHEL/CentOS (x86_64)
# Run with: sudo bash scripts/install_deps_fedora.sh

set -e

echo "=== Installing NEXO Edge dependencies (Fedora/RHEL/CentOS) ==="

# Update package list
sudo dnf update -y

# Core build tools
sudo dnf install -y \
    gcc-c++ \
    cmake \
    clang-tools-extra \
    git

# SQLite
sudo dnf install -y \
    sqlite-devel

# Crypto (OpenSSL)
sudo dnf install -y \
    openssl-devel

# HTTP client (libcurl)
sudo dnf install -y \
    libcurl-devel

# GPIO for Raspberry Pi (libgpiod)
# Note: This is for ARM64 Pi. On x86_64 dev, it's safe to install but won't be used.
sudo dnf install -y \
    libgpiod-devel

# Logging (spdlog)
sudo dnf install -y \
    spdlog-devel

# Testing framework (Catch2)
# Catch2 may not be in default repos; use header-only fallback if needed.
sudo dnf install -y catch2-devel || echo "Catch2 not found in dnf, will use header-only fallback"

echo "=== Dependencies installed successfully ==="
echo "Note: Some packages (libgpiod) are for ARM64 Pi and won't be used on x86_64 dev."
