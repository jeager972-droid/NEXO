/**
 * =============================================================================
 * test_multi_finger.cpp — Tests Catch2 para multi-huella.
 * =============================================================================
 * Verifica la capa de persistencia del enrolamiento de dos dedos por estudiante:
 *   - estudiante_huellas guarda slot 1 y slot 2 como huella_ids distintos.
 *   - getEstudianteByHuellaID resuelve AMBOS dedos al mismo estudiante
 *     (bidireccional: cualquier dedo identifica).
 *   - getNextHuellaID es global sobre ambas tablas (sin colisiones).
 *   - huellaSlotExists / getHuellaCount / getHuellaIdsByDocumento.
 *   - deleteEstudiante elimina todas las huellas (purga para revocación).
 *   - slots fuera de {1,2} son rechazados.
 *
 * NOTA: Encryption sin clave provisionada retorna "" — el test valida la
 * lógica de slots/ids, no el roundtrip criptográfico (cubierto en test_crypto).
 * =============================================================================
 */

#include <catch2/catch_test_macros.hpp>
#include <cstdio>
#include <vector>
#include <string>

#include "base_de_datos/sqlite_manager.h"

static const char* TEST_DB = "/tmp/nexo_test_multifinger.db";

static void freshDb() {
    std::remove(TEST_DB);
    SqliteManager::getInstance().close();
    SqliteManager::getInstance().initialize(TEST_DB);
}

static Estudiante makeStudent(const std::string& doc, uint32_t huellaId) {
    Estudiante e;
    e.documento = doc;
    e.nombre = "Alumno " + doc;
    e.telefono_acudiente = "3000000000";
    e.huella_id = huellaId;
    e.template_huella.assign(256, 0xAA);
    e.school_id = "";
    return e;
}

TEST_CASE("MultiFinger: estudiante con dos dedos resuelve por ambos ids", "[multifinger]") {
    freshDb();
    auto& db = SqliteManager::getInstance();

    Estudiante est = makeStudent("DOC1", 0);
    REQUIRE(db.saveEstudiante(est));

    uint32_t id1 = db.getNextHuellaID();
    uint32_t id2 = id1 + 1;
    std::vector<uint8_t> tpl1(256, 0x01), tpl2(256, 0x02);
    REQUIRE(db.saveHuella("DOC1", 1, id1, tpl1, ""));
    REQUIRE(db.saveHuella("DOC1", 2, id2, tpl2, ""));

    Estudiante found1, found2;
    REQUIRE(db.getEstudianteByHuellaID(id1, found1));
    REQUIRE(db.getEstudianteByHuellaID(id2, found2));
    REQUIRE(found1.documento == "DOC1");
    REQUIRE(found2.documento == "DOC1");
}

TEST_CASE("MultiFinger: getNextHuellaID no colisiona entre tablas", "[multifinger]") {
    freshDb();
    auto& db = SqliteManager::getInstance();

    Estudiante est = makeStudent("DOC1", 0);
    REQUIRE(db.saveEstudiante(est));

    uint32_t next1 = db.getNextHuellaID();
    std::vector<uint8_t> tpl(256, 0x07);
    REQUIRE(db.saveHuella("DOC1", 1, next1, tpl, ""));
    uint32_t next2 = db.getNextHuellaID();
    REQUIRE(next2 > next1);
    REQUIRE(db.saveHuella("DOC1", 2, next2, tpl, ""));
    uint32_t next3 = db.getNextHuellaID();
    REQUIRE(next3 > next2);
}

TEST_CASE("MultiFinger: slots y conteo", "[multifinger]") {
    freshDb();
    auto& db = SqliteManager::getInstance();

    Estudiante est = makeStudent("DOC2", 0);
    REQUIRE(db.saveEstudiante(est));
    std::vector<uint8_t> tpl(256, 0x0B);

    REQUIRE(db.getHuellaCount("DOC2") == 0);
    REQUIRE(db.saveHuella("DOC2", 1, 100, tpl, ""));
    REQUIRE(db.huellaSlotExists("DOC2", 1));
    REQUIRE_FALSE(db.huellaSlotExists("DOC2", 2));
    REQUIRE(db.getHuellaCount("DOC2") == 1);

    REQUIRE(db.saveHuella("DOC2", 2, 101, tpl, ""));
    REQUIRE(db.getHuellaCount("DOC2") == 2);

    // Slot inválido rechazado
    REQUIRE_FALSE(db.saveHuella("DOC2", 3, 102, tpl, ""));
    REQUIRE_FALSE(db.saveHuella("DOC2", 0, 103, tpl, ""));
    REQUIRE(db.getHuellaCount("DOC2") == 2);
}

TEST_CASE("MultiFinger: re-enrolar un slot existente lo reemplaza", "[multifinger]") {
    freshDb();
    auto& db = SqliteManager::getInstance();
    Estudiante est = makeStudent("DOC3", 0);
    REQUIRE(db.saveEstudiante(est));
    std::vector<uint8_t> tpl(256, 0x0C);

    REQUIRE(db.saveHuella("DOC3", 1, 200, tpl, ""));
    REQUIRE(db.saveHuella("DOC3", 1, 201, tpl, "")); // re-enrol slot 1
    std::vector<uint32_t> ids;
    REQUIRE(db.getHuellaIdsByDocumento("DOC3", ids));
    REQUIRE(ids.size() == 1);
    REQUIRE(ids[0] == 201);
}

TEST_CASE("MultiFinger: deleteEstudiante purga todas las huellas", "[multifinger]") {
    freshDb();
    auto& db = SqliteManager::getInstance();
    Estudiante est = makeStudent("DOC4", 0);
    REQUIRE(db.saveEstudiante(est));
    std::vector<uint8_t> tpl(256, 0x0D);
    REQUIRE(db.saveHuella("DOC4", 1, 300, tpl, ""));
    REQUIRE(db.saveHuella("DOC4", 2, 301, tpl, ""));

    std::vector<uint32_t> ids;
    REQUIRE(db.getHuellaIdsByDocumento("DOC4", ids));
    REQUIRE(ids.size() == 2);

    REQUIRE(db.deleteEstudiante("DOC4"));

    Estudiante gone;
    REQUIRE_FALSE(db.getEstudianteByHuellaID(300, gone));
    REQUIRE_FALSE(db.getEstudianteByHuellaID(301, gone));
    REQUIRE(db.getHuellaCount("DOC4") == 0);
}

TEST_CASE("MultiFinger: identificación por dedo 2 tras borrar dedo 1", "[multifinger]") {
    freshDb();
    auto& db = SqliteManager::getInstance();
    Estudiante est = makeStudent("DOC5", 0);
    REQUIRE(db.saveEstudiante(est));
    std::vector<uint8_t> tpl(256, 0x0E);
    REQUIRE(db.saveHuella("DOC5", 1, 400, tpl, ""));
    REQUIRE(db.saveHuella("DOC5", 2, 401, tpl, ""));

    // Revocar dedo 1 directamente (borrado de la fila de huella)
    sqlite3_exec(db.getDB(), "DELETE FROM estudiante_huellas WHERE documento='DOC5' AND finger_slot=1;", nullptr, nullptr, nullptr);

    Estudiante found;
    REQUIRE_FALSE(db.getEstudianteByHuellaID(400, found)); // dedo 1 ya no resuelve
    REQUIRE(db.getEstudianteByHuellaID(401, found));       // dedo 2 sigue identificando
    REQUIRE(found.documento == "DOC5");
}
