/**
 * Roles config / NEXO Institucional
 * Fuente única de verdad para roles, navegación sidebar y operaciones por rol.
 * Autoridad: UX_DESIGN.md — Arquitectura por rol.
 * Dependencias: lucide-react (iconos de navegación).
 */

/**
 * ROLES — deben coincidir exactamente entre Backend y Frontend.
 */
export const ROLES = {
  RECTOR: 'RECTOR',
  COORDINADOR: 'COORDINATOR',
  DOCENTE: 'TEACHER',
  SECRETARIA: 'SECRETARY',
  PORTERO: 'SECURITY',
  AUXILIAR: 'AUXILIARY',
  PSICORIENTADOR: 'COUNSELOR'
};

import {
  LayoutDashboard,
  Activity,
  Bell,
  FolderHeart,
  UserPlus,
  ShieldCheck,
  Cpu,
  User,
  ShieldAlert,
  Calendar,
  Wrench,
  Send,
  Bus,
  Clock,
  UserCheck,
  FileText,
  Siren,
  MessageCircle,
} from 'lucide-react';

const ALL_ROLES = Object.values(ROLES);

/**
 * SIDEBAR_ITEMS — Navegación lateral por rol.
 * Autoridad: UX_DESIGN.md §Arquitectura por rol + 05_INFORMATION_ARCHITECTURE.md §4.
 */
export const SIDEBAR_ITEMS = [
  {
    title: 'Inicio',
    path: '/',
    icon: LayoutDashboard,
    roles: ALL_ROLES,
  },
  {
    title: 'Operaciones',
    path: '/operacion',
    icon: Activity,
    roles: ALL_ROLES,
  },
  {
    title: 'Notificaciones',
    path: '/notificaciones',
    icon: Bell,
    roles: ALL_ROLES,
  },
  {
    title: 'Seguimientos',
    path: '/casos',
    icon: FolderHeart,
    roles: [ROLES.RECTOR, ROLES.COORDINADOR, ROLES.PSICORIENTADOR],
  },
  // Las consultas las cubre «Pregúntale a Nexus» (chat NLU);
  // /consulta existe como redirect a /chat.

  {
    title: 'Enrolamiento',
    path: '/enrolamiento',
    icon: UserPlus,
    roles: [ROLES.SECRETARIA],
  },
  {
    title: 'Sensores',
    path: '/dispositivos',
    icon: Cpu,
    roles: [ROLES.RECTOR],
  },
  {
    title: 'Pregúntale a Nexus',
    path: '/chat',
    icon: MessageCircle,
    roles: ALL_ROLES,
  },
  {
    title: 'Perfil',
    path: '/perfil',
    icon: User,
    roles: ALL_ROLES,
  },
];

/**
 * OPERATION_COMMANDS — Operaciones disponibles por rol.
 * Autoridad: UX_DESIGN.md §Operaciones por rol.
 * Cada comando mapea a un flujo FLOW-OPS-* y endpoint /operations/execute.
 */

export const OPERATION_COMMANDS = [
  // Citar acudiente — rector, coordinador, docente, psicoorientador
  { id: 'citacion', title: 'Citar acudiente', icon: Calendar, roles: [ROLES.RECTOR, ROLES.COORDINADOR, ROLES.DOCENTE, ROLES.PSICORIENTADOR] },
  // Autorizar salida — rector, coordinador
  { id: 'salida', title: 'Autorizar salida', icon: ShieldCheck, roles: [ROLES.RECTOR, ROLES.COORDINADOR] },
  // Situación Crítica — todos los roles, avisa a rector y coordinador
  { id: 'situacion_critica', title: 'Situación Crítica', icon: Siren, roles: ALL_ROLES },
  // Generar permiso — docente, coordinador, rector
  { id: 'permiso', title: 'Generar permiso', icon: UserCheck, roles: [ROLES.DOCENTE, ROLES.COORDINADOR, ROLES.RECTOR] },
  // Mandar solicitud — todos
  { id: 'solicitud', title: 'Mandar solicitud', icon: Send, roles: ALL_ROLES },
  // Reportar incidente — docente, psicoorientador, rector, coordinador
  { id: 'incidente', title: 'Reportar incidente', icon: ShieldAlert, roles: [ROLES.DOCENTE, ROLES.PSICORIENTADOR, ROLES.RECTOR, ROLES.COORDINADOR] },
  // Reportar daño — portero, auxiliar, rector, coordinador
  { id: 'dano', title: 'Reportar daño', icon: Wrench, roles: [ROLES.PORTERO, ROLES.AUXILIAR, ROLES.RECTOR, ROLES.COORDINADOR] },
  // Solicitar seguimiento / Caso — coordinador, rector, docente, psicoorientador
  { id: 'seguimiento', title: 'Solicitar seguimiento', icon: FileText, roles: [ROLES.COORDINADOR, ROLES.RECTOR, ROLES.DOCENTE, ROLES.PSICORIENTADOR] },
  // Salida pedagógica — coordinador, rector, docente
  { id: 'salida_pedagogica', title: 'Salida pedagógica', icon: Bus, roles: [ROLES.COORDINADOR, ROLES.RECTOR, ROLES.DOCENTE] },
  // Cambio de horario — coordinador, rector, docente
  { id: 'cambio_horario', title: 'Cambio de horario', icon: Clock, roles: [ROLES.COORDINADOR, ROLES.RECTOR, ROLES.DOCENTE] },
];

/**
 * getOperationsForRole — filtra operaciones por rol.
 */
export const getOperationsForRole = (role) =>
  OPERATION_COMMANDS.filter(cmd => cmd.roles.includes(role));

export const ROLE_DISPLAY = {
  [ROLES.RECTOR]:         'Rector',
  [ROLES.COORDINADOR]:    'Coordinador',
  [ROLES.DOCENTE]:        'Docente',
  [ROLES.SECRETARIA]:     'Secretaria',
  [ROLES.PORTERO]:        'Portero',
  [ROLES.AUXILIAR]:       'Auxiliar',
  [ROLES.PSICORIENTADOR]: 'Psicorientador',
};

export const getRoleDisplay = (role) => ROLE_DISPLAY[role] ?? role;

/**
 * PRIMARY_ACTIONS — 4 acciones más importantes/usadas por rol para la barra inferior.
 * Las demás quedan en el sidebar vertical.
 */
export const PRIMARY_ACTIONS = {
  [ROLES.RECTOR]:         ['/', '/operacion', '/casos', '/notificaciones'],
  [ROLES.COORDINADOR]:    ['/', '/operacion', '/casos', '/notificaciones'],
  [ROLES.DOCENTE]:        ['/', '/operacion', '/chat', '/notificaciones'],
  [ROLES.SECRETARIA]:     ['/', '/chat', '/enrolamiento', '/notificaciones'],
  [ROLES.PORTERO]:        ['/', '/operacion', '/notificaciones', '/perfil'],
  [ROLES.AUXILIAR]:       ['/', '/operacion', '/notificaciones', '/perfil'],
  [ROLES.PSICORIENTADOR]: ['/', '/chat', '/casos', '/notificaciones'],
};

export const getPrimaryActions = (role) => {
  const paths = PRIMARY_ACTIONS[role] || ['/', '/operacion', '/notificaciones', '/perfil'];
  return paths.map((p) => SIDEBAR_ITEMS.find((i) => i.path === p)).filter(Boolean);
};

export const getSecondaryActions = (role) => {
  const primaryPaths = PRIMARY_ACTIONS[role] || [];
  return SIDEBAR_ITEMS.filter((i) => i.roles.includes(role) && !primaryPaths.includes(i.path));
};
