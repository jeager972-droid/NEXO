/**
 * =============================================================================
 * test_sqlite.cpp — Tests de SQLite con prepared statements (Catch2 v3).
 * =============================================================================
 * RESPONSABILIDAD:
 *   Verifica operaciones básicas de SQLite: apertura/creación de base de datos,
 *   inserción y lectura con prepared statements, y correcta finalización de
 *   recursos (sqlite3_finalize) incluso ante sentencias inválidas. Usa un
 *   archivo temporal /tmp/nexo_test.db limpiado entre tests.
 *
 * DEPENDENCIAS:
 *   - Catch2 v3
 *   - SQLite3
 *   - filesystem (C++17/20)
 */

#include <catch2/catch_test_macros.hpp>
#include <catch2/catch_session.hpp>
#include <sqlite3.h>
#include <filesystem>
#include <string>
#include <cstring>

// Test database path
static const char* TEST_DB_PATH = "/tmp/nexo_test.db";

// Helper to clean up test database
void cleanupTestDB() {
    std::filesystem::remove(TEST_DB_PATH);
}

TEST_CASE("SQLite Database Creation", "[sqlite]") {
    cleanupTestDB();
    
    sqlite3* db = nullptr;
    int rc = sqlite3_open(TEST_DB_PATH, &db);
    
    REQUIRE(rc == SQLITE_OK);
    REQUIRE(db != nullptr);
    
    // Create a test table
    const char* sql = "CREATE TABLE test_table (id INTEGER PRIMARY KEY, name TEXT);";
    rc = sqlite3_exec(db, sql, nullptr, nullptr, nullptr);
    
    REQUIRE(rc == SQLITE_OK);
    
    sqlite3_close(db);
    
    // Verify database file exists
    REQUIRE(std::filesystem::exists(TEST_DB_PATH));
    
    cleanupTestDB();
}

TEST_CASE("SQLite Prepared Statements - Insert", "[sqlite]") {
    cleanupTestDB();
    
    sqlite3* db = nullptr;
    int rc = sqlite3_open(TEST_DB_PATH, &db);
    REQUIRE(rc == SQLITE_OK);
    
    // Create test table
    const char* create_sql = "CREATE TABLE estudiantes (id INTEGER PRIMARY KEY, documento TEXT, nombre TEXT);";
    rc = sqlite3_exec(db, create_sql, nullptr, nullptr, nullptr);
    REQUIRE(rc == SQLITE_OK);
    
    // Insert using prepared statement
    const char* insert_sql = "INSERT INTO estudiantes (documento, nombre) VALUES (?, ?);";
    sqlite3_stmt* stmt = nullptr;
    
    rc = sqlite3_prepare_v2(db, insert_sql, -1, &stmt, nullptr);
    REQUIRE(rc == SQLITE_OK);
    
    sqlite3_bind_text(stmt, 1, "123456", -1, SQLITE_TRANSIENT);
    sqlite3_bind_text(stmt, 2, "Test Student", -1, SQLITE_TRANSIENT);
    
    rc = sqlite3_step(stmt);
    REQUIRE(rc == SQLITE_DONE);
    
    sqlite3_finalize(stmt);
    sqlite3_close(db);
    
    cleanupTestDB();
}

TEST_CASE("SQLite Prepared Statements - Read", "[sqlite]") {
    cleanupTestDB();
    
    sqlite3* db = nullptr;
    int rc = sqlite3_open(TEST_DB_PATH, &db);
    REQUIRE(rc == SQLITE_OK);
    
    // Create and populate test table
    const char* create_sql = "CREATE TABLE estudiantes (id INTEGER PRIMARY KEY, documento TEXT, nombre TEXT);";
    rc = sqlite3_exec(db, create_sql, nullptr, nullptr, nullptr);
    REQUIRE(rc == SQLITE_OK);
    
    const char* insert_sql = "INSERT INTO estudiantes (documento, nombre) VALUES (?, ?);";
    sqlite3_stmt* insert_stmt = nullptr;
    
    rc = sqlite3_prepare_v2(db, insert_sql, -1, &insert_stmt, nullptr);
    REQUIRE(rc == SQLITE_OK);
    
    sqlite3_bind_text(insert_stmt, 1, "123456", -1, SQLITE_TRANSIENT);
    sqlite3_bind_text(insert_stmt, 2, "Test Student", -1, SQLITE_TRANSIENT);
    
    rc = sqlite3_step(insert_stmt);
    REQUIRE(rc == SQLITE_DONE);
    
    sqlite3_finalize(insert_stmt);
    
    // Read using prepared statement
    const char* select_sql = "SELECT documento, nombre FROM estudiantes WHERE documento = ?;";
    sqlite3_stmt* select_stmt = nullptr;
    
    rc = sqlite3_prepare_v2(db, select_sql, -1, &select_stmt, nullptr);
    REQUIRE(rc == SQLITE_OK);
    
    sqlite3_bind_text(select_stmt, 1, "123456", -1, SQLITE_TRANSIENT);
    
    rc = sqlite3_step(select_stmt);
    REQUIRE(rc == SQLITE_ROW);
    
    const unsigned char* doc = sqlite3_column_text(select_stmt, 0);
    const unsigned char* name = sqlite3_column_text(select_stmt, 1);
    
    REQUIRE(strcmp(reinterpret_cast<const char*>(doc), "123456") == 0);
    REQUIRE(strcmp(reinterpret_cast<const char*>(name), "Test Student") == 0);
    
    sqlite3_finalize(select_stmt);
    sqlite3_close(db);
    
    cleanupTestDB();
}

TEST_CASE("SQLite Memory Leak Prevention - sqlite3_finalize", "[sqlite]") {
    cleanupTestDB();
    
    sqlite3* db = nullptr;
    int rc = sqlite3_open(TEST_DB_PATH, &db);
    REQUIRE(rc == SQLITE_OK);
    
    // Create test table
    const char* create_sql = "CREATE TABLE test_table (id INTEGER PRIMARY KEY, value TEXT);";
    rc = sqlite3_exec(db, create_sql, nullptr, nullptr, nullptr);
    REQUIRE(rc == SQLITE_OK);
    
    // Test that sqlite3_finalize is called even when prepare fails
    sqlite3_stmt* stmt = nullptr;
    const char* invalid_sql = "INSERT INTO nonexistent_table VALUES (1);";
    
    rc = sqlite3_prepare_v2(db, invalid_sql, -1, &stmt, nullptr);
    REQUIRE(rc != SQLITE_OK);
    
    // stmt should be nullptr after failed prepare
    REQUIRE(stmt == nullptr);
    
    // sqlite3_finalize should not crash with nullptr
    if (stmt) {
        sqlite3_finalize(stmt);
    }
    
    // Test successful prepare and finalize
    const char* valid_sql = "INSERT INTO test_table (value) VALUES (?);";
    rc = sqlite3_prepare_v2(db, valid_sql, -1, &stmt, nullptr);
    REQUIRE(rc == SQLITE_OK);
    REQUIRE(stmt != nullptr);
    
    sqlite3_bind_text(stmt, 1, "test", -1, SQLITE_TRANSIENT);
    rc = sqlite3_step(stmt);
    REQUIRE(rc == SQLITE_DONE);
    
    sqlite3_finalize(stmt);
    
    sqlite3_close(db);
    
    cleanupTestDB();
}
