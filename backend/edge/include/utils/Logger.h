#pragma once

#include <spdlog/spdlog.h>
#include <spdlog/sinks/stdout_color_sinks.h>
#include <spdlog/sinks/rotating_file_sink.h>
#include <memory>
#include <string>

#define LOG_TRACE(...)    if(spdlog::default_logger()) spdlog::trace(__VA_ARGS__)
#define LOG_DEBUG(...)    if(spdlog::default_logger()) spdlog::debug(__VA_ARGS__)
#define LOG_INFO(...)     if(spdlog::default_logger()) spdlog::info(__VA_ARGS__)
#define LOG_WARN(...)     if(spdlog::default_logger()) spdlog::warn(__VA_ARGS__)
#define LOG_ERROR(...)    if(spdlog::default_logger()) spdlog::error(__VA_ARGS__)
#define LOG_CRITICAL(...) if(spdlog::default_logger()) spdlog::critical(__VA_ARGS__)

/**
 * =============================================================================
 * Logger.h — Inicialización del logger del edge usando spdlog.
 * =============================================================================
 * RESPONSABILIDAD:
 *   Configura el logger por defecto de spdlog con salida a consola (color) y
 *   archivo rotativo (5 MB x 3 archivos). Soporta niveles trace/debug/info/
 *   warn/error. Los macros LOG_* verifican que el logger esté inicializado.
 */
class Logger {
public:
    static void initialize(const std::string& logPath = "nexo-edge.log",
                           const std::string& level = "info") {
        try {
            auto console_sink = std::make_shared<spdlog::sinks::stdout_color_sink_mt>();
            auto file_sink = std::make_shared<spdlog::sinks::rotating_file_sink_mt>(
                logPath, 5 * 1024 * 1024, 3);

            auto logger = std::make_shared<spdlog::logger>(
                "nexo", spdlog::sinks_init_list{console_sink, file_sink});

            logger->set_pattern("[%Y-%m-%d %H:%M:%S.%e] [%^%l%$] [%s:%#] %v");
            logger->flush_on(spdlog::level::warn);

            if (level == "trace") logger->set_level(spdlog::level::trace);
            else if (level == "debug") logger->set_level(spdlog::level::debug);
            else if (level == "warn") logger->set_level(spdlog::level::warn);
            else if (level == "error") logger->set_level(spdlog::level::err);
            else logger->set_level(spdlog::level::info);

            spdlog::set_default_logger(logger);
        } catch (const spdlog::spdlog_ex& ex) {
            fprintf(stderr, "Logger init failed: %s\n", ex.what());
        }
    }

    static void shutdown() {
        spdlog::shutdown();
    }
};
