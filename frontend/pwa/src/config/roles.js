/**
 * Roles config / NEXO Institucional
 * Fuente única de verdad para roles, navegación sidebar y operaciones por rol.
 * Autoridad: UX_DESIGN.md — Arquitectura por rol.
 * Dependencias: lucide-react (iconos de navegación).
 */

/**
 * ROLES — deben coincidir exactamente entre Backend y Frontend.
 * Definidos en rolesList.js (módulo base compartido con config/operations.js
 * para evitar importaciones circulares); se re-exportan aquí.
 */
import { ROLES } from './rolesList';
import { OPERATIONS_CATALOG, findCommandByTitle, getOperationsForRole } from './operations';
export { ROLES };
export { getOperationsForRole };

import {
  LayoutDashboard,
  Activity,
  Bell,
  FolderHeart,
  UserPlus,
  Cpu,
  User,
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
  // Las consultas las cubre «Pregúntale a Nodus» (chat NLU);
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
    title: 'Pregúntale a Nodus',
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
 * La fuente de verdad es OPERATIONS_CATALOG (config/operations.js): mismo
 * catálogo que renderiza /operacion — aquí solo se re-exporta para que los
 * consumidores existentes (tests, menús) sigan importando desde roles.
 */
export const OPERATION_COMMANDS = OPERATIONS_CATALOG;

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
 *
 * Las entradas «/operacion?cmd=<título>» son acciones insignia del rol:
 * aterrizan directo en el formulario de esa operación (deep-link), con el
 * icono y título del comando del catálogo — no en la sección genérica.
 * «/casos» vive en el sidebar (sección de gestión), no en la barra.
 */
export const PRIMARY_ACTIONS = {
  [ROLES.RECTOR]:         ['/', '/operacion?cmd=Emergencia', '/chat', '/notificaciones'],
  [ROLES.COORDINADOR]:    ['/', '/operacion?cmd=Cambio de horario', '/chat', '/notificaciones'],
  [ROLES.DOCENTE]:        ['/', '/operacion?cmd=Reportar incidente', '/chat', '/notificaciones'],
  [ROLES.SECRETARIA]:     ['/', '/enrolamiento', '/operacion?cmd=Citar acudiente', '/notificaciones'],
  [ROLES.PORTERO]:        ['/', '/operacion?cmd=Registro manual', '/notificaciones', '/perfil'],
  [ROLES.AUXILIAR]:       ['/', '/operacion?cmd=Reportar daño', '/notificaciones', '/perfil'],
  [ROLES.PSICORIENTADOR]: ['/', '/casos', '/operacion?cmd=Solicitar seguimiento', '/notificaciones'],
};

const resolveNavEntry = (entry) => {
  const [path, query] = entry.split('?');
  const item = SIDEBAR_ITEMS.find((i) => i.path === path);
  if (!item) return null;
  if (!query) return item;
  // Deep-link a una operación concreta — hereda icono/título del catálogo
  // para que la barra diga «Situación crítica», no «Operaciones».
  const cmdTitle = new URLSearchParams(query).get('cmd');
  const cmd = cmdTitle ? findCommandByTitle(cmdTitle) : null;
  return cmd ? { ...item, path: entry, title: cmd.title, icon: cmd.icon } : item;
};

export const getPrimaryActions = (role) => {
  const entries = PRIMARY_ACTIONS[role] || ['/', '/operacion', '/notificaciones', '/perfil'];
  return entries.map(resolveNavEntry).filter(Boolean);
};

export const getSecondaryActions = (role) => {
  // Solo las entradas «planas» excluyen del sidebar: una insignia
  // «/operacion?cmd=…» no debe ocultar el hub /operacion del menú lateral.
  const primaryPaths = (PRIMARY_ACTIONS[role] || []).filter((e) => !e.includes('?'));
  return SIDEBAR_ITEMS.filter((i) => i.roles.includes(role) && !primaryPaths.includes(i.path));
};
