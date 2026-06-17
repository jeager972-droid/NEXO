import { 
  LayoutDashboard, 
  Activity, 
  Bell, 
  FileText, 
  Search, 
  UserPlus,
} from 'lucide-react';
import { ROLES } from './roles';

export const SIDEBAR_ITEMS = [
  {
    title: 'Inicio',
    path: '/',
    icon: LayoutDashboard,
    roles: [ROLES.RECTOR, ROLES.COORDINADOR, ROLES.SECRETARIA, ROLES.PORTERO, ROLES.AUXILIAR, ROLES.DOCENTE]
  },
  {
    title: 'Operación',
    path: '/operacion',
    icon: Activity,
    roles: [ROLES.RECTOR, ROLES.COORDINADOR, ROLES.SECRETARIA, ROLES.PORTERO, ROLES.AUXILIAR, ROLES.DOCENTE]
  },
  {
    title: 'Notificaciones',
    path: '/notificaciones',
    icon: Bell,
    roles: [ROLES.RECTOR, ROLES.COORDINADOR, ROLES.SECRETARIA, ROLES.PORTERO, ROLES.AUXILIAR, ROLES.DOCENTE]
  },
  {
    title: 'Consulta',
    path: '/consulta',
    icon: Search,
    roles: [ROLES.COORDINADOR, ROLES.SECRETARIA, ROLES.DOCENTE, ROLES.PORTERO, ROLES.AUXILIAR]
  },
  {
    title: 'Auditoría',
    path: '/auditoria',
    icon: FileText,
    roles: [ROLES.RECTOR]
  },
  {
    title: 'Enrolamiento',
    path: '/enrolamiento',
    icon: UserPlus,
    roles: [ROLES.SECRETARIA]
  }
];
