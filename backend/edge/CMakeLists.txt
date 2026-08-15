# =============================================================================
# CMakeLists.txt — Configuración de build del backend edge NEXO.
# =============================================================================
# RESPONSABILIDAD:
#   Define el ejecutable `nexo-edge`, sus fuentes, estándar C++20, directorios
#   de salida, dependencias externas (OpenSSL, CURL, SQLite3, spdlog,
#   nlohmann_json, mosquitto, gpiod opcional, ZKTeco opcional) y el target de
#   tests `nexo-tests` con Catch2 v3.
#
# FLUJO DE BUILD:
#   cmake --preset dev-x86      -> configura build/dev
#   cmake --build build/dev     -> compila nexo-edge + nexo-tests
#   ctest --test-dir build/dev  -> ejecuta tests Catch2
#
# DEPENDENCIAS OBLIGATORIAS:
#   - OpenSSL, libcurl, SQLite3, spdlog, nlohmann_json, libmosquitto
# DEPENDENCIAS OPCIONALES:
#   - libgpiod (GPIO RPi4)
#   - libzkfp/libzkfptype (sensor ZKTeco ZK9500)
#
# NOTAS:
#   - nlohmann_json y Catch2 se descargan con FetchContent.
#   - El ejecutable se genera en ${CMAKE_BINARY_DIR}/bin.
# =============================================================================

cmake_minimum_required(VERSION 3.20)
project(nexo-edge VERSION 0.2.0 LANGUAGES CXX)

set(CMAKE_CXX_STANDARD 20)
set(CMAKE_CXX_STANDARD_REQUIRED ON)
set(CMAKE_CXX_EXTENSIONS OFF)
set(CMAKE_EXPORT_COMPILE_COMMANDS ON)

# Output directories
set(CMAKE_RUNTIME_OUTPUT_DIRECTORY ${CMAKE_BINARY_DIR}/bin)
set(CMAKE_LIBRARY_OUTPUT_DIRECTORY ${CMAKE_BINARY_DIR}/lib)
set(CMAKE_ARCHIVE_OUTPUT_DIRECTORY ${CMAKE_BINARY_DIR}/lib)

# ============================================================
# EDGE SOURCE FILES — self-contained under edge/
# After demolition of legacy src/ and include/, all production
# source must live inside edge/. Files marked TODO must be
# recreated here before the edge can compile.
# ============================================================

# Include directories (edge-local)
set(EDGE_INCLUDE_DIR ${CMAKE_CURRENT_SOURCE_DIR}/include)
include_directories(${EDGE_INCLUDE_DIR})

# Main executable — fully self-contained under edge/
add_executable(nexo-edge
    src/main.cpp
    src/utils/ConfigManager.cpp
    src/base_de_datos/sqlite_manager.cpp
    src/base_de_datos/encryption.cpp
    src/base_de_datos/cloud_manager.cpp
    src/hardware/dev_stub/DevStubBiometricSensor.cpp
    src/hardware/dev_stub/DevStubDisplay.cpp
    src/hardware/dev_stub/DevStubNotification.cpp
    src/hardware/dev_stub/DevStubHttpClient.cpp
    src/interoperabilidad/audit_trail.cpp
    src/mqtt/mqtt_command_worker.cpp
    src/hardware/watchdog.cpp
)

target_include_directories(nexo-edge PRIVATE ${EDGE_INCLUDE_DIR})

# Dependencies
set(FEDORA_DEPS_MSG "En Fedora instala las dependencias con:\n   sudo dnf install -y libcurl-devel spdlog-devel mosquitto-devel sqlite-devel openssl-devel")

find_package(OpenSSL REQUIRED)
find_package(CURL)
if(NOT CURL_FOUND)
    message(FATAL_ERROR "curl/curl.h no encontrado.\n${FEDORA_DEPS_MSG}")
endif()
find_package(SQLite3 REQUIRED)
if(NOT TARGET SQLite3::SQLite3)
    add_library(SQLite3::SQLite3 UNKNOWN IMPORTED)
    set_target_properties(SQLite3::SQLite3 PROPERTIES
        INTERFACE_INCLUDE_DIRECTORIES "${SQLite3_INCLUDE_DIRS}"
        IMPORTED_LOCATION "${SQLite3_LIBRARIES}")
endif()
find_package(spdlog)
if(NOT spdlog_FOUND)
    message(FATAL_ERROR "spdlog no encontrado.\n${FEDORA_DEPS_MSG}")
endif()
find_package(PkgConfig REQUIRED)
pkg_check_modules(MOSQUITTO libmosquitto)
if(NOT MOSQUITTO_FOUND)
    message(FATAL_ERROR "libmosquitto no encontrado.\n${FEDORA_DEPS_MSG}")
endif()
# nlohmann/json
include(FetchContent)
FetchContent_Declare(
    nlohmann_json
    GIT_REPOSITORY https://github.com/nlohmann/json.git
    GIT_TAG v3.11.3
)
FetchContent_MakeAvailable(nlohmann_json)

# FIX: Buscar y enlazar librería de ZKTeco
find_library(ZKFP_LIB NAMES zkfp zkfptype PATHS ${CMAKE_CURRENT_SOURCE_DIR}/lib /usr/local/lib /usr/lib)
find_path(ZKFP_INCLUDE_DIR NAMES libzkfp.h libzkfptype.h PATHS ${CMAKE_CURRENT_SOURCE_DIR}/lib ${CMAKE_CURRENT_SOURCE_DIR}/include)
if(ZKFP_LIB AND ZKFP_INCLUDE_DIR)
    message(STATUS "ZKTeco SDK found: lib=${ZKFP_LIB} include=${ZKFP_INCLUDE_DIR}")
    target_sources(nexo-edge PRIVATE src/hardware/real/Zk9500BiometricSensor.cpp)
    target_include_directories(nexo-edge PRIVATE ${ZKFP_INCLUDE_DIR})
    target_link_libraries(nexo-edge PRIVATE ${ZKFP_LIB})
else()
    message(WARNING "ZKTeco SDK no encontrado. Se usa stub para createZk9500BiometricSensor.")
    target_sources(nexo-edge PRIVATE src/hardware/real/Zk9500BiometricSensor_stub.cpp)
endif()

# U.are.U SDK (bundled inside edge/sensorvendor)
if(CMAKE_SYSTEM_PROCESSOR MATCHES "aarch64|arm64")
    set(UAREU_ARCH "arm64")
elseif(CMAKE_SYSTEM_PROCESSOR MATCHES "arm")
    set(UAREU_ARCH "arm")
else()
    set(UAREU_ARCH "x64")
endif()
set(UAREU_SDK_ROOT "${CMAKE_CURRENT_SOURCE_DIR}/sensorvendor/uareu5300")
set(UAREU_SDK_LIB_DIR "${UAREU_SDK_ROOT}/Linux/lib/${UAREU_ARCH}")
set(UAREU_SDK_LIBS
    ${UAREU_SDK_LIB_DIR}/libdpfpdd.so
    ${UAREU_SDK_LIB_DIR}/libdpfj.so
    ${UAREU_SDK_LIB_DIR}/libdpfpdd5000.so
    ${UAREU_SDK_LIB_DIR}/libdpfpdd_4k.so
    ${UAREU_SDK_LIB_DIR}/libdpfpdd7k.so
    ${UAREU_SDK_LIB_DIR}/libdpfpdd_ptapi.so
    ${UAREU_SDK_LIB_DIR}/libdpfr6.so
    ${UAREU_SDK_LIB_DIR}/libdpfr7.so
)
if(EXISTS ${UAREU_SDK_LIB_DIR}/libdpfpdd.so AND EXISTS ${UAREU_SDK_LIB_DIR}/libdpfj.so)
    message(STATUS "U.are.U SDK found for ${UAREU_ARCH}")
    target_sources(nexo-edge PRIVATE src/hardware/real/UareU5300BiometricSensor.cpp)
    target_include_directories(nexo-edge PRIVATE ${UAREU_SDK_ROOT}/Include)
    target_link_libraries(nexo-edge PRIVATE ${UAREU_SDK_LIBS} ${CMAKE_DL_LIBS})
    get_filename_component(UAREU_SDK_LIB_DIR_ABS "${UAREU_SDK_LIB_DIR}" ABSOLUTE)
    file(RELATIVE_PATH UAREU_RPATH_REL
        "${CMAKE_RUNTIME_OUTPUT_DIRECTORY}"
        "${UAREU_SDK_LIB_DIR_ABS}")
    set_property(TARGET nexo-edge APPEND PROPERTY BUILD_RPATH   "$ORIGIN/${UAREU_RPATH_REL}")
    set_property(TARGET nexo-edge APPEND PROPERTY INSTALL_RPATH "$ORIGIN/${UAREU_RPATH_REL}")
else()
    message(WARNING "U.are.U SDK no encontrado en ${UAREU_SDK_LIB_DIR}. El sensor UareU5300 no compilará.")
    target_sources(nexo-edge PRIVATE src/hardware/real/UareU5300BiometricSensor_stub.cpp)
endif()

target_link_libraries(nexo-edge PRIVATE
    OpenSSL::SSL OpenSSL::Crypto
    CURL::libcurl
    SQLite3::SQLite3
    spdlog::spdlog
    nlohmann_json::nlohmann_json
    ${MOSQUITTO_LIBRARIES}
)


target_include_directories(nexo-edge PRIVATE ${MOSQUITTO_INCLUDE_DIRS})

# libgpiod — optional, only available on RPi4
find_package(gpiod QUIET)
if(gpiod_FOUND)
    target_link_libraries(nexo-edge PRIVATE gpiod::gpiod)
    target_compile_definitions(nexo-edge PRIVATE HAS_GPIOD=1)
endif()

target_compile_features(nexo-edge PRIVATE cxx_std_20)

# Compiler warnings
if(CMAKE_CXX_COMPILER_ID MATCHES "GNU|Clang")
    target_compile_options(nexo-edge PRIVATE -Wall -Wextra -Wpedantic)
endif()

# ============================================================
# TESTING WITH CATCH2
# ============================================================
option(BUILD_TESTING "Build tests" ON)
if(BUILD_TESTING)
    enable_testing()

    FetchContent_Declare(
        Catch2
        GIT_REPOSITORY https://github.com/catchorg/Catch2.git
        GIT_TAG v3.5.2
    )
    FetchContent_MakeAvailable(Catch2)

    add_executable(nexo-tests
        tests/test_sqlite.cpp
        tests/test_crypto.cpp
        tests/test_config_manager.cpp
        tests/test_nexo_result.cpp
        tests/test_dev_stub_sensor.cpp
        tests/test_cloud_manager.cpp
        tests/test_mqtt_command_worker.cpp
        tests/test_audit_trail.cpp
        tests/test_watchdog.cpp
        # Implementation files needed by tests
        src/utils/ConfigManager.cpp
        src/base_de_datos/sqlite_manager.cpp
        src/base_de_datos/encryption.cpp
        src/base_de_datos/cloud_manager.cpp
        src/hardware/dev_stub/DevStubBiometricSensor.cpp
        src/hardware/dev_stub/DevStubHttpClient.cpp
        src/interoperabilidad/audit_trail.cpp
        src/mqtt/mqtt_command_worker.cpp
        src/hardware/watchdog.cpp
    )

    target_include_directories(nexo-tests PRIVATE ${EDGE_INCLUDE_DIR} ${MOSQUITTO_INCLUDE_DIRS})

    target_link_libraries(nexo-tests PRIVATE
        Catch2::Catch2WithMain
        OpenSSL::SSL OpenSSL::Crypto
        SQLite3::SQLite3
        spdlog::spdlog
        nlohmann_json::nlohmann_json
        CURL::libcurl
        ${MOSQUITTO_LIBRARIES}
    )

    target_compile_features(nexo-tests PRIVATE cxx_std_20)

    if(CMAKE_CXX_COMPILER_ID MATCHES "GNU|Clang")
        target_compile_options(nexo-tests PRIVATE -Wall -Wextra -Wpedantic)
    endif()

    include(CTest)
    include(Catch)
    catch_discover_tests(nexo-tests)
endif()
