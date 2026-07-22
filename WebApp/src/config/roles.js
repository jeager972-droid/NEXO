/**
 * Roles config / NEXO Institucional
 * Responsabilidad: Fuente única de verdad para roles del sistema, navegación sidebar
 * (SIDEBAR_ITEMS) y mapeo a etiquetas legibles (ROLE_DISPLAY/getRoleDisplay).
 * Debe mantenerse sincronizada con los roles del backend.
 * Dependencias: lucide-react (iconos de navegación).
 */
/**
 * FUENTE DE VERDAD ÚNICA PARA ROLES DEL SISTEMA
 * Estos valores deben coincidir exactamente entre Backend y Frontend.
 */
export const ROLES = {
  SUPER_RECTOR: 'SUPER_RECTOR',
  RECTOR: 'RECTOR',
  COORDINADOR: 'COORDINADOR',
  DOCENTE: 'DOCENTE',
  SECRETARIA: 'SECRETARIA',
  PORTERO: 'PORTERO',
  AUXILIAR: 'AUXILIAR',
  PSICORIENTADOR: 'PSICORIENTADOR'
};

/**
 * SIDEBAR_ITEMS centralizado para el sistema de navegación.
 */
import { 
  LayoutDashboard, 
  Activity, 
  Bell, 
  FileText, 
  Search, 
  UserPlus,
  BarChart2,
} from 'lucide-react';

export const SIDEBAR_ITEMS = [
  {
    title: 'Inicio',
    path: '/',
    icon: LayoutDashboard,
    roles: [ROLES.SUPER_RECTOR, ROLES.RECTOR, ROLES.COORDINADOR, ROLES.SECRETARIA, ROLES.PORTERO, ROLES.AUXILIAR, ROLES.DOCENTE, ROLES.PSICORIENTADOR]
  },
  {
    title: 'Operación',
    path: '/operacion',
    icon: Activity,
    roles: [ROLES.SUPER_RECTOR, ROLES.RECTOR, ROLES.COORDINADOR, ROLES.SECRETARIA, ROLES.PORTERO, ROLES.AUXILIAR, ROLES.DOCENTE, ROLES.PSICORIENTADOR]
  },
  {
    title: 'Notificaciones',
    path: '/notificaciones',
    icon: Bell,
    roles: [ROLES.SUPER_RECTOR, ROLES.RECTOR, ROLES.COORDINADOR, ROLES.SECRETARIA, ROLES.PORTERO, ROLES.AUXILIAR, ROLES.DOCENTE, ROLES.PSICORIENTADOR]
  },
  {
    title: 'Seguimiento',
    path: '/seguimiento',
    icon: FileText,
    roles: [ROLES.COORDINADOR, ROLES.RECTOR, ROLES.SUPER_RECTOR, ROLES.PSICORIENTADOR]
  },
  {
    title: 'Consulta',
    path: '/consulta',
    icon: Search,
    roles: [ROLES.COORDINADOR, ROLES.SECRETARIA, ROLES.DOCENTE, ROLES.PSICORIENTADOR]
  },
  {
    title: 'Auditoría',
    path: '/auditoria',
    icon: FileText,
    roles: [ROLES.SUPER_RECTOR, ROLES.RECTOR]
  },
  {
    title: 'Informes',
    path: '/informes',
    icon: BarChart2,
    roles: [ROLES.SUPER_RECTOR, ROLES.RECTOR]
  },
  {
    title: 'Enrolamiento',
    path: '/enrolamiento',
    icon: UserPlus,
    roles: [ROLES.SECRETARIA]
  }
];

export const ROLE_DISPLAY = {
  [ROLES.SUPER_RECTOR]:  'Admin',
  [ROLES.RECTOR]:        'Rector',
  [ROLES.COORDINADOR]:   'Coordinador',
  [ROLES.DOCENTE]:       'Docente',
  [ROLES.SECRETARIA]:    'Secretaria',
  [ROLES.PORTERO]:       'Portero',
  [ROLES.AUXILIAR]:      'Auxiliar',
  [ROLES.PSICORIENTADOR]: 'Psicorientador',
};

export const getRoleDisplay = (role) => ROLE_DISPLAY[role] ?? role;
