# NEXO Edge — Linux Native C++20

> **Status:** Etapa 1 completa — Infraestructura lista para arquitectura.

## Propósito

Nodo edge de producción NEXO para **Raspberry Pi 4 Model B** con sensor biométrico **ZKTeco ZK9500**.
C++20 nativo Linux. Cero dependencias Arduino/ESP32.

**Stack técnico:**
- C++20
- CMake
- spdlog (logging estructurado)
- Catch2 v3 (tests)
- OpenSSL (AES-256-GCM)
- libcurl (HTTPS client)
- libgpiod (GPIO para Raspberry Pi)
- SQLite3 (persistencia local)

## Dependencias

### Debian/Ubuntu
```bash
sudo bash scripts/install_deps_debian.sh
```

### Fedora/RHEL/CentOS
```bash
sudo bash scripts/install_deps_fedora.sh
```

## Compilación

### Desarrollo (x86_64)
```bash
cmake --preset dev-x86
cmake --build --preset dev-x86
./build/dev/bin/nexo-edge
```

### Release (x86_64)
```bash
cmake --preset release-x86
cmake --build --preset release-x86
./build/release/bin/nexo-edge
```

### Cross-compile para Raspberry Pi 4 (ARM64)
```bash
# Requiere toolchain configurado en cmake/arm64-pi4-toolchain.cmake
cmake --preset cross-arm64-pi4
cmake --build --preset cross-arm64-pi4
```

## Estructura del proyecto

```
edge/
├── CMakeLists.txt
├── CMakePresets.json
├── .clang-format
├── .clang-tidy
├── include/nexo/           ← Headers públicos
│   ├── core/               ← Lógica pura
│   ├── platform/           ← Interfaces (HAL)
│   ├── persistence/        ← SQLite
│   ├── crypto/             ← AES-256-GCM
│   └── net/                ← HTTP client
├── src/
│   ├── core/               ← Implementación lógica pura
│   ├── platform/
│   │   ├── linux_real/     ← Impl reales para Pi 4
│   │   └── dev_stub/       ← Impl stubs para PC dev
│   ├── persistence/
│   ├── crypto/
│   ├── net/
│   └── main.cpp            ← Entry point Linux
├── tests/                  ← Catch2 v3
└── scripts/
    ├── install_deps_debian.sh
    └── install_deps_fedora.sh
```

## Estado actual

- [x] Etapa 1.0: Preparación segura (esqueleto edge/, CMake)
- [x] Etapa 1.1: Capa de plataforma (HAL interfaces puras)
- [x] Etapa 1.2: Logger spdlog (consola + archivo rotado)
- [x] Etapa 1.3: SQLite Linux + prepared statements
- [x] Etapa 1.4: Cripto OpenSSL AES-256-GCM
- [x] Etapa 1.5: Cliente HTTP libcurl con TLS estricto
- [x] Etapa 1.6: Sensor biométrico ZK9500 con RAII
- [x] Etapa 1.7: Periféricos GPIO/I2C (libgpiod + stubs)
- [x] Etapa 1.8: Crypto payload + CloudManager Linux
- [x] Etapa 1.9: main.cpp Linux (signal handling + poll())
- [x] Etapa 1.10: Demolición controlada de código Arduino
- [x] Tarea 13: Infraestructura de Pruebas (Catch2)
- [x] Tarea 14: Gestión de Configuración (ConfigManager JSON)
- [x] Tarea 15: Estandarización de Errores (NexoResult)

## Arquitectura de producción

- **Hilo principal:** Biometría (ZK9500) → SQLite → Display/Notificación
- **Hilo secundario:** SyncWorker → push asíncrono a Cloud (nunca bloquea biometría)
- **Shutdown:** SIGINT/SIGTERM → poll() non-blocking → drain sync → exit limpio
- **Hardware target:** Raspberry Pi 4 Model B (ARM64) + ZKTeco ZK9500 (USB)

## Verificación

```bash
cmake --preset dev-x86
cmake --build --preset dev-x86
./build/dev/bin/nexo-edge
```
