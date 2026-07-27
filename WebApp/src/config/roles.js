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
  Search,
  UserPlus,
  BarChart2,
  ShieldCheck,
  User,
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
    title: 'Casos Activos',
    path: '/casos',
    icon: FolderHeart,
    roles: [ROLES.RECTOR, ROLES.COORDINADOR, ROLES.PSICORIENTADOR],
  },
  {
    title: 'Consultas',
    path: '/consulta',
    icon: Search,
    roles: [ROLES.RECTOR, ROLES.COORDINADOR, ROLES.SECRETARIA, ROLES.DOCENTE, ROLES.PSICORIENTADOR],
  },
  {
    title: 'Informes',
    path: '/informes',
    icon: BarChart2,
    roles: [ROLES.RECTOR],
  },
  {
    title: 'Enrolamiento',
    path: '/enrolamiento',
    icon: UserPlus,
    roles: [ROLES.SECRETARIA],
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
import {
  Calendar, AlertOctagon, Wrench, Send, Bus, Clock, UserCheck, ShieldAlert, FileText,
} from 'lucide-react';

export const OPERATION_COMMANDS = [
  // Citar acudiente — rector, coordinador, docente, psicoorientador
  { id: 'citacion', title: 'Citar acudiente', icon: Calendar, roles: [ROLES.RECTOR, ROLES.COORDINADOR, ROLES.DOCENTE, ROLES.PSICORIENTADOR] },
  // Autorizar salida — rector, coordinador
  { id: 'salida', title: 'Autorizar salida', icon: ShieldCheck, roles: [ROLES.RECTOR, ROLES.COORDINADOR] },
  // SOS — todos los institucionales
  { id: 'sos', title: 'SOS', icon: AlertOctagon, roles: ALL_ROLES },
  // Generar permiso — docente, coordinador, rector
  { id: 'permiso', title: 'Generar permiso', icon: UserCheck, roles: [ROLES.DOCENTE, ROLES.COORDINADOR, ROLES.RECTOR] },
  // Mandar solicitud — todos
  { id: 'solicitud', title: 'Mandar solicitud', icon: Send, roles: ALL_ROLES },
  // Reportar incidente — docente, psicoorientador
  { id: 'incidente', title: 'Reportar incidente', icon: ShieldAlert, roles: [ROLES.DOCENTE, ROLES.PSICORIENTADOR] },
  // Reportar daño — portero, auxiliar
  { id: 'dano', title: 'Reportar daño', icon: Wrench, roles: [ROLES.PORTERO, ROLES.AUXILIAR] },
  // Solicitar seguimiento / Caso — coordinador, rector
  { id: 'seguimiento', title: 'Solicitar seguimiento', icon: FileText, roles: [ROLES.COORDINADOR, ROLES.RECTOR] },
  // Salida pedagógica — coordinador, rector
  { id: 'salida_pedagogica', title: 'Salida pedagógica', icon: Bus, roles: [ROLES.COORDINADOR, ROLES.RECTOR] },
  // Cambio de horario — coordinador, rector
  { id: 'cambio_horario', title: 'Cambio de horario', icon: Clock, roles: [ROLES.COORDINADOR, ROLES.RECTOR] },
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
