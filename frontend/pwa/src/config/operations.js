/**
 * CFG-OPS-01 — Catálogo único de operaciones / NEXO Institucional
 *
 * Fuente de verdad para:
 *   - Operation.jsx (grid de comandos + formulario)
 *   - config/roles.js (módulos y acciones primarias por rol)
 *   - deep-links del chat (?cmd=<title>) — los títulos deben coincidir con
 *     chatOperationCmd() del backend (case/diacritic-insensitive).
 *
 * NO duplicar esta lista en otros archivos.
 */
import {
  ShieldCheck, ShieldAlert,
  Clock, Bus, Calendar, Wrench, UserCheck,
  FileText, Siren,
  GitMerge, Maximize2, PenLine,
} from 'lucide-react';
import { ROLES } from './rolesList';

export const OPERATIONS_CATALOG = [
  // ── Azul (accent) — acciones informativas/neutrales ──
  { id: 'citar',       title: 'Citar acudiente',      icon: Calendar,   roles: [ROLES.RECTOR, ROLES.COORDINADOR, ROLES.DOCENTE, ROLES.PSICORIENTADOR, ROLES.SECRETARIA], fields: ['grade', 'group', 'student', 'date', 'time', 'message'], tone: 'accent', desc: 'Agenda llamada o visita del acudiente' },
  { id: 'seguimiento', title: 'Solicitar seguimiento', icon: FileText,  roles: [ROLES.RECTOR, ROLES.COORDINADOR, ROLES.DOCENTE, ROLES.PSICORIENTADOR], fields: ['grade', 'group', 'student', 'dependency', 'assignee', 'reason'], tone: 'accent', desc: 'Abre un caso de seguimiento' },
  { id: 'fusionar_bloque', title: 'Fusionar bloque',  icon: GitMerge,   roles: [ROLES.DOCENTE], fields: ['grade', 'group'], tone: 'accent', desc: 'Añade otro bloque a tus clases' },
  // ── Naranja (warning) — acciones de advertencia/precaución ──
  { id: 'autorizar',   title: 'Autorizar salida',     icon: ShieldCheck,roles: [ROLES.RECTOR, ROLES.COORDINADOR], fields: ['grade', 'group', 'student', 'reason'], tone: 'warning', desc: 'Salida anticipada del estudiante' },
  { id: 'daño',        title: 'Reportar daño',        icon: Wrench,     roles: [ROLES.AUXILIAR, ROLES.PORTERO, ROLES.RECTOR, ROLES.COORDINADOR], fields: ['location', 'description'], tone: 'warning', desc: 'Novedad en infraestructura' },
  // horario/pedagogica mueven la jornada y avisan a todos los acudientes —
  // alcance exclusivo de coordinación y rectoría.
  { id: 'pedagogica',  title: 'Salida pedagógica',    icon: Bus,        roles: [ROLES.RECTOR, ROLES.COORDINADOR], fields: ['grade', 'group', 'reason'], tone: 'warning', desc: 'Autoriza la salida de todo el grupo' },
  { id: 'horario',     title: 'Cambio de horario',    icon: Clock,      roles: [ROLES.RECTOR, ROLES.COORDINADOR], fields: ['grade', 'group', 'reason', 'time'], warning: 'Este comando avisará a todos los padres de familia del grupo elegido.', tone: 'warning', desc: 'Avisa el nuevo horario a los padres' },
  { id: 'permiso',     title: 'Generar permiso',      icon: UserCheck,  roles: [ROLES.DOCENTE, ROLES.COORDINADOR, ROLES.RECTOR], fields: ['grade', 'group', 'student', 'reason', 'timeStart', 'timeEnd'], tone: 'warning', desc: 'Salida del salón con tiempo límite' },
  // extender_bloque afecta toda la jornada (ventanas de registro globales) —
  // por eso solo coordinación/rectoría; el docente fusiona sus bloques.
  { id: 'extender_bloque', title: 'Extender bloque',  icon: Maximize2,  roles: [ROLES.RECTOR, ROLES.COORDINADOR], fields: ['grade', 'group'], tone: 'warning', desc: 'Prolonga la clase en curso' },
  { id: 'registro_manual', title: 'Registro manual',  icon: PenLine,    roles: [ROLES.DOCENTE, ROLES.COORDINADOR, ROLES.SECRETARIA, ROLES.PORTERO, ROLES.RECTOR], fields: ['grade', 'group', 'student', 'reason'], tone: 'warning', desc: 'Marcar entrada sin huella' },
  // ── Rojo (danger) — acciones críticas/emergencias ──
  { id: 'situacion_critica', title: 'Emergencia',     icon: Siren,      roles: Object.values(ROLES), fields: ['location', 'message'], tone: 'danger', desc: 'Aviso inmediato' },
  { id: 'incidente',   title: 'Reportar incidente',   icon: ShieldAlert,roles: [ROLES.DOCENTE, ROLES.PSICORIENTADOR, ROLES.RECTOR, ROLES.COORDINADOR], fields: ['grade', 'group', 'student', 'location', 'message', 'targets'], tone: 'danger', desc: 'Novedad que se debe notificar' },
];

/** Normaliza para comparar títulos de comandos (deep-links ?cmd=). */
export const normalizeCmdTitle = (s) =>
  String(s || '').normalize('NFD').replace(/[̀-ͯ]/g, '').trim().toLowerCase();

/** Comando por título — tolera diferencias de mayúsculas/tildes del chat. */
export const findCommandByTitle = (title) =>
  OPERATIONS_CATALOG.find((c) => normalizeCmdTitle(c.title) === normalizeCmdTitle(title)) || null;

/** Operaciones disponibles para un rol. */
export const getOperationsForRole = (role) =>
  OPERATIONS_CATALOG.filter((c) => role && c.roles.includes(role));

/** Títulos de operaciones de un rol — alimenta descs de módulos en roles.js. */
export const getOperationTitlesForRole = (role) =>
  getOperationsForRole(role).map((c) => c.title);

/** Ids que usan grupo como alcance principal (para chips del chat con &group=). */
export const GROUP_SCOPE_OPS = new Set(['horario', 'pedagogica', 'fusionar_bloque', 'extender_bloque']);

/**
 * metadata_json.action (notificación) → comando de operación.
 * Chips «Revisar» del anunciador aterrizan en el formulario precargado
 * cuando la novedad corresponde a una operación ejecutable.
 */
export const NOTIF_ACTION_TO_CMD = {
  iniciar_seguimiento: 'Solicitar seguimiento',
  citacion_confirmada: 'Citar acudiente',
  reagendar_motivo: 'Citar acudiente',
  permiso: 'Generar permiso',
  autorizar_salida: 'Autorizar salida',
  incidente: 'Reportar incidente',
  'daño': 'Reportar daño',
  pedagogica: 'Salida pedagógica',
  horario: 'Cambio de horario',
  situacion_critica: 'Emergencia',
  sos:               'Emergencia',
};
