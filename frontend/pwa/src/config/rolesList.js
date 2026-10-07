/**
 * CFG-ROL-00 — Identificadores de rol (backend role_name).
 * Módulo base sin dependencias: lo importan config/roles.js (menús,
 * capacidades) y config/operations.js (catálogo de operaciones) sin
 * crear ciclos de importación.
 */
export const ROLES = {
  RECTOR: 'RECTOR',
  COORDINADOR: 'COORDINATOR',
  SECRETARIA: 'SECRETARY',
  DOCENTE: 'TEACHER',
  PSICORIENTADOR: 'COUNSELOR',
  PORTERO: 'SECURITY',
  AUXILIAR: 'AUXILIARY',
};
