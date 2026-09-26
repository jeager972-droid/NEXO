# `_cuarentena/` — material retirado, pendiente de veredicto

Nada aquí es referenciado por el sistema vigente. **Nada se borra sin tu
aprobación**: marca qué eliminar y qué rescatar; lo rescatado vuelve a su
lugar o a `docs/`.

Para borrar todo lo aprobado: `git rm -r _cuarentena/` (los contenidos de
`_no_trackeado/` no están en git — desaparecen con `rm -rf`).

## ⚠️ Acción requerida fuera del repo

| Ítem | Motivo |
|---|---|
| `wipe_db_test.php` | Script suelto con una **contraseña PostgreSQL de Railway en texto plano**. Ya está en el historial de git — **rota esa credencial** aunque borres el archivo. |

## Contenido

### `memoria_nexus/` — memoria de trabajo del proyecto (10 archivos)

`NEXUS_*.md` + `NEXO_HYBRID_MODEL_V1.md`. Fueron absorbidos por
`docs/nexus/NEXUS.md` y los README de componente. **Contienen historial de
decisiones (D001–D010), métricas de ciclos y el estado de sesión** — si
borras esto se pierde la continuidad conversacional entre sesiones.
Recomendación: borrar todo menos `NEXUS_LONGRUN_STATE.md`, o aceptar que el
estado futuro lo llevan los READMEs + git log.

### `auditoria/` — 27 reportes históricos de auditoría del chatbot/NLU

Ciclos de auditoría del stack retirado (TF-IDF, fases 1–4, forense DSM,
veredicto NLU). Todo lo vigente se absorbió en `docs/nexus/NEXUS.md`.
Recomendación: borrar. (`AUDITORIA_NLU_VEREDICTO_2026-09-23.md` documenta el
retiro del clasificador — rescatable si quieres conservar la evidencia.)

### `fuentes_absorbidas/` — docs reemplazados por los README de componente

| Archivo | Absorbido por |
|---|---|
| `README_API.md` | `backend/api/README.md` (mapa de endpoints completo y corregido) |
| `README_schema.md` | `sql/README.md` (68 tablas reales; el viejo decía 60) |
| `EDGE.md` | `backend/edge/README.md` |
| `WEBAPP.md` | `frontend/pwa/README.md` |
| `LANDING.md` | `frontend/landing/README.md` |
| `WORKERS.md` | `backend/api/README.md` §workers (nombres/colas reales) |
| `TESTING.md` | `test/README.md` |
| `INFORME_CAPA_SEMANTICA.md`, `NEXUS_CURRENT_ARCHITECTURE.md`, `NEXUS_SEMANTIC_LAYER.md`, `NEXUS_TAXONOMY_MATRIX.md` | `docs/nexus/NEXUS.md` |

Recomendación: borrar (quedaban desactualizados frente al código).

### Raíz de `_cuarentena/`

| Ítem | Motivo |
|---|---|
| `tener_en_cuenta.md` | Doc viejo; su contenido útil (degradación sin Redis) ya está en `backend/api/README.md` §9. |
| `imagenbot.png` | Duplicado byte-exacto de `frontend/pwa/public/imagenbot.png` (mismo md5). Nada lo referencia. |
| `blind_semantic.json` | Set de datos huérfano — solo lo citaba un reporte de auditoría. |
| `generalization_last.json` | Salida del retirado `generalization_eval.py` (stack NLU viejo). |
| `user_archives/` | Directorio runtime legacy (fotos van base64 en BD). Vacío salvo `.gitkeep`. |

### `_no_trackeado/` — residuos que nunca estuvieron en git

| Ítem | Motivo |
|---|---|
| `backend_nlu/` | Restos del servicio Python retirado (solo `__pycache__`). |
| `nlu_runtime/` | Runtime exportado del clasificador retirado. |
| `api_uploads/` | `backend/api/uploads/avatars` vacío, sin referencias en código. |
| `pruebas_pycache/`, `nodo_pycache/` | Cachés `__pycache__` regenerables. |
