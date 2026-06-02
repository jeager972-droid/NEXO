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
    title: 'Consulta',
    path: '/consulta',
    icon: Search,
    roles: [ROLES.SUPER_RECTOR, ROLES.RECTOR, ROLES.COORDINADOR, ROLES.SECRETARIA, ROLES.DOCENTE, ROLES.PORTERO, ROLES.AUXILIAR, ROLES.PSICORIENTADOR]
  },
  {
    title: 'Auditoría',
    path: '/auditoria',
    icon: FileText,
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
