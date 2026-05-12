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
    roles: ['Rector', 'Coordinador', 'Secretaria', 'Portero', 'Auxiliar', 'Docente']
  },
  {
    title: 'Operación',
    path: '/operacion',
    icon: Activity,
    roles: ['Rector', 'Coordinador', 'Secretaria', 'Portero', 'Auxiliar', 'Docente']
  },
  {
    title: 'Notificaciones',
    path: '/notificaciones',
    icon: Bell,
    roles: ['Rector', 'Coordinador', 'Secretaria', 'Portero', 'Auxiliar', 'Docente']
  },
  {
    title: 'Consulta',
    path: '/consulta',
    icon: Search,
    roles: ['Rector', 'Coordinador', 'Secretaria', 'Docente', 'Portero', 'Auxiliar', 'Administrador']
  },
  {
    title: 'Auditoría',
    path: '/auditoria',
    icon: FileText,
    roles: ['Rector']
  },
  {
    title: 'Enrolamiento',
    path: '/enrolamiento',
    icon: UserPlus,
    roles: ['Secretaria']
  }
];
