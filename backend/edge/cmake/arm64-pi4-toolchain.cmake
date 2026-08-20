# =============================================================================
# arm64-pi4-toolchain.cmake — Toolchain para cross-compilación ARM64 (RPi 4).
# =============================================================================
# RESPONSABILIDAD:
#   Define el compilador, sysroot y flags para compilar nexo-edge desde x86_64
#   hacia ARM64 (aarch64) para Raspberry Pi 4.
#
# USO:
#   cmake --preset cross-arm64-pi4
#   cmake --build --preset cross-arm64-pi4
#
# REQUISITOS:
#   - Instalar el cross-compiler: sudo apt-get install gcc-aarch64-linux-gnu g++-aarch64-linux-gnu
#   - O usar Docker buildx: docker buildx build --platform linux/arm64
# =============================================================================

set(CMAKE_SYSTEM_NAME Linux)
set(CMAKE_SYSTEM_PROCESSOR aarch64)

# Cross-compiler (instalado via gcc-aarch64-linux-gnu)
set(CMAKE_C_COMPILER   aarch64-linux-gnu-gcc)
set(CMAKE_CXX_COMPILER aarch64-linux-gnu-g++)

# Sysroot opcional (descomentar y ajustar si se usa un sysroot de RPi)
# set(CMAKE_SYSROOT /path/to/rpi4-sysroot)
# set(CMAKE_FIND_ROOT_PATH ${CMAKE_SYSROOT})

# Search paths: solo buscar libs/includes en el sysroot, no en el host
set(CMAKE_FIND_ROOT_PATH_MODE_PROGRAM NEVER)
set(CMAKE_FIND_ROOT_PATH_MODE_LIBRARY ONLY)
set(CMAKE_FIND_ROOT_PATH_MODE_INCLUDE ONLY)
set(CMAKE_FIND_ROOT_PATH_MODE_PACKAGE ONLY)

# Flags para ARM64 Cortex-A72 (RPi 4)
set(CMAKE_CXX_FLAGS "${CMAKE_CXX_FLAGS} -mcpu=cortex-a72 -mtune=cortex-a72")
set(CMAKE_C_FLAGS   "${CMAKE_C_FLAGS}   -mcpu=cortex-a72 -mtune=cortex-a72")
