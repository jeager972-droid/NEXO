# Nexus — mapa persistente de arquitectura

Fecha de auditoría: 2026-09-22. Base: ee12db93aa24bb2e068aceae4111c239b73e8bad.
Estado: AUDITORÍA EN CURSO; las descripciones deben contrastarse con código y ejecución.

## Anclas localizadas

| Capa | Fuente real | Estado |
|---|---|---|
| Entrada y handlers | backend/api/routes/chat.php | Pendiente de trazar flujo actual |
| NLU y estado conversacional | backend/api/lib/nexus_nlu.php | Pendiente de auditar funciones |
| IR, registry, planner, executors, presentación | backend/api/lib/nexus_semantic.php | Implementación existente; no reconstruir |
| NLU Python | backend/nlu/{service,preprocess,intent_semantics,train}.py | Pendiente de comparar runtime/fallback |
| Runtime exportado | backend/api/nlu_runtime | Pendiente de inspección |
| Pruebas conversacionales | test/harness_turn.php, test/chat_forensic_harness.php | Pendiente de verificar aislamiento |
| Inventario existente | test/ecosystem_capability_inventory.php | Pendiente de ejecutar |
| UI | PWA | Pendiente de localizar integración actual |

## Evidencia histórica

- docs/nexus/NEXUS_CURRENT_ARCHITECTURE.md describe contexto de navegador y una rama antigua; no asumir que representa HEAD.
- docs/nexus/NEXUS_SEMANTIC_LAYER.md y docs/nexus/INFORME_CAPA_SEMANTICA.md existen; contrastarlos con las funciones actuales.

## Pendientes de inventario

Tablas, claves, relaciones, scopes, endpoints, servicios, consultas, vistas, políticas, handlers y capacidades de frontend.
Conteos medidos antes de restringir integración: 132 tablas y 91 FK en public de la BD local nexo_test; inventario desplegado informa 24 archivos de rutas, 53 handlers y 39 capabilities (12 executors semánticos, 27 delegadas). El esquema fuente se audita aparte.

## Flujo actual verificado en fuente

POST /chat/message autentica, limita 60 turnos/10 min/usuario, carga _ds del historial y clasifica.
Hay DOS constructores de composición duplicados: parts del NLU (chat.php:318+) y cláusulas del texto (569+).
Ambos construyen referencias al step 0; nxPlanValidate no verifica dependencias ni executors declarados.
La ruta navega resultados antes del gate semántico/legacy; hay que comprobar reautorización de memoria.
chatBuildDs mantiene last_result, cursor, person, current, previous y seis objetos materializados.
El ID se calcula con count(objects)+1, por lo que se recicla al alcanzar el límite.
Python service.py reordena parts por dominio/confianza: orden discursivo y dependencias no están garantizados.

## Entorno verificable

PHP host 8.5.10, Python 3.14.7, Node 22.23.1; PHPUnit/Vitest y dependencias Python existentes.
NLU Python local iniciado en 127.0.0.1:8096, sin acceso a BD.
Bind mount Docker bloqueado por permisos. El usuario eligió SOLO PRUEBAS LOCALES: no modificar controles ni contenedores; no usar resultados desplegados como validación del código modificado.
