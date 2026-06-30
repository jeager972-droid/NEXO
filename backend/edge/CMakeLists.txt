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
find_package(OpenSSL REQUIRED)
find_package(CURL REQUIRED)
find_package(SQLite3 REQUIRED)
find_package(spdlog REQUIRED)
find_package(PkgConfig REQUIRED)
pkg_check_modules(MOSQUITTO REQUIRED libmosquitto)
if(NOT MOSQUITTO_FOUND)
    find_library(MOSQUITTO_LIB NAMES mosquitto)
    if(NOT MOSQUITTO_LIB)
        message(FATAL_ERROR "libmosquitto no encontrado. Instala: apt-get install libmosquitto-dev")
    endif()
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
find_library(ZKFP_LIB NAMES zkfp zkfptype)
if(NOT ZKFP_LIB)
    message(WARNING "Librería ZKTeco no encontrada. El build puede fallar.")
endif()

target_link_libraries(nexo-edge PRIVATE
    OpenSSL::SSL OpenSSL::Crypto
    CURL::libcurl
    SQLite::SQLite3
    spdlog::spdlog
    nlohmann_json::nlohmann_json
    ${MOSQUITTO_LIBRARIES}
    ${MOSQUITTO_LIB}
)

if(ZKFP_LIB)
    target_link_libraries(nexo-edge PRIVATE ${ZKFP_LIB})
endif()

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
    )

    target_include_directories(nexo-tests PRIVATE ${EDGE_INCLUDE_DIR})

    target_link_libraries(nexo-tests PRIVATE
        Catch2::Catch2WithMain
        OpenSSL::SSL OpenSSL::Crypto
        SQLite::SQLite3
        spdlog::spdlog
        nlohmann_json::nlohmann_json
    )

    target_compile_features(nexo-tests PRIVATE cxx_std_20)

    if(CMAKE_CXX_COMPILER_ID MATCHES "GNU|Clang")
        target_compile_options(nexo-tests PRIVATE -Wall -Wextra -Wpedantic)
    endif()

    include(CTest)
    include(Catch)
    catch_discover_tests(nexo-tests)
endif()
