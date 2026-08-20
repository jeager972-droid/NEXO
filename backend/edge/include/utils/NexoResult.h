#pragma once

#include <string>
#include <optional>

/**
 * =============================================================================
 * NexoResult.h — Monada de resultado y enumeración de errores del edge.
 * =============================================================================
 * RESPONSABILIDAD:
 *   Define NexoError (errores típicos del edge) y la plantilla NexoResult<T>
 *   que encapsula un valor opcional, un código de error y un mensaje. Soporta
 *   el especializado NexoResult<void> para operaciones sin retorno de dato.
 */
enum class NexoError {
    None,
    NotInitialized,
    SensorError,
    BadQuality,
    NoMatch,
    Timeout,
    DatabaseError,
    NetworkError,
    CryptoError,
    InvalidInput,
    PermissionDenied,
    Cancelled,  // FIX C6: Captura cancelada por cancelCapture()
    Unknown
};

inline std::string toString(NexoError e) {
    switch (e) {
        case NexoError::None:             return "None";
        case NexoError::NotInitialized:   return "NotInitialized";
        case NexoError::SensorError:      return "SensorError";
        case NexoError::BadQuality:       return "BadQuality";
        case NexoError::NoMatch:          return "NoMatch";
        case NexoError::Timeout:          return "Timeout";
        case NexoError::DatabaseError:    return "DatabaseError";
        case NexoError::NetworkError:     return "NetworkError";
        case NexoError::CryptoError:      return "CryptoError";
        case NexoError::InvalidInput:     return "InvalidInput";
        case NexoError::PermissionDenied: return "PermissionDenied";
        case NexoError::Cancelled:        return "Cancelled";
        case NexoError::Unknown:          return "Unknown";
    }
    return "Unknown";
}

template <typename T>
struct NexoResult {
    NexoError error = NexoError::None;
    std::string message;
    std::optional<T> value;

    explicit operator bool() const { return error == NexoError::None; }

    static NexoResult success(T val) {
        return {NexoError::None, "", std::move(val)};
    }
    static NexoResult fail(NexoError err, std::string msg = "") {
        return {err, std::move(msg), std::nullopt};
    }
};

template <>
struct NexoResult<void> {
    NexoError error = NexoError::None;
    std::string message;

    explicit operator bool() const { return error == NexoError::None; }

    static NexoResult success() {
        return {NexoError::None, ""};
    }
    static NexoResult fail(NexoError err, std::string msg = "") {
        return {err, std::move(msg)};
    }
};
