cmake_minimum_required(VERSION 3.16)
project(uareu5300_validation LANGUAGES CXX)
set(CMAKE_CXX_STANDARD 17)
set(CMAKE_CXX_STANDARD_REQUIRED ON)

if(CMAKE_SYSTEM_PROCESSOR MATCHES "aarch64|arm64")
    set(UAREU_ARCH "arm64")
elseif(CMAKE_SYSTEM_PROCESSOR MATCHES "arm")
    set(UAREU_ARCH "arm")
else()
    set(UAREU_ARCH "x64")
endif()

set(UAREU_SDK_ROOT "${CMAKE_CURRENT_SOURCE_DIR}/../../sensorvendor/uareu5300")
set(UAREU_SDK_INCLUDE_DIR "${UAREU_SDK_ROOT}/Include")
set(UAREU_SDK_LIB_DIR     "${UAREU_SDK_ROOT}/Linux/lib/${UAREU_ARCH}")
get_filename_component(UAREU_SDK_LIB_DIR_ABS "${UAREU_SDK_LIB_DIR}" ABSOLUTE)

include_directories(${UAREU_SDK_INCLUDE_DIR})
link_directories(${UAREU_SDK_LIB_DIR_ABS})

set(CMAKE_RUNTIME_OUTPUT_DIRECTORY ${CMAKE_CURRENT_BINARY_DIR})
file(RELATIVE_PATH UAREU_RPATH_REL
    "${CMAKE_RUNTIME_OUTPUT_DIRECTORY}"
    "${UAREU_SDK_LIB_DIR_ABS}")
set(CMAKE_BUILD_RPATH   "$ORIGIN/${UAREU_RPATH_REL}")
set(CMAKE_INSTALL_RPATH "$ORIGIN/${UAREU_RPATH_REL}")

add_executable(uareu5300_validation main.cpp)
set(UAREU_SDK_LIBS
    ${UAREU_SDK_LIB_DIR_ABS}/libdpfpdd.so
    ${UAREU_SDK_LIB_DIR_ABS}/libdpfj.so
    ${UAREU_SDK_LIB_DIR_ABS}/libdpfpdd5000.so
    ${UAREU_SDK_LIB_DIR_ABS}/libdpfpdd_4k.so
    ${UAREU_SDK_LIB_DIR_ABS}/libdpfpdd7k.so
    ${UAREU_SDK_LIB_DIR_ABS}/libdpfpdd_ptapi.so
    ${UAREU_SDK_LIB_DIR_ABS}/libdpfr6.so
    ${UAREU_SDK_LIB_DIR_ABS}/libdpfr7.so
)
target_link_libraries(uareu5300_validation ${UAREU_SDK_LIBS} pthread dl)

install(TARGETS uareu5300_validation DESTINATION bin)
