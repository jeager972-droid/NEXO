import { useState } from 'react';
import { Outlet, useNavigate } from 'react-router-dom';
import Sidebar from './Sidebar';
import { Menu, Bell, Sun, Moon } from 'lucide-react';
import { useAuth } from '../hooks/useAuth';
import { useTheme } from '../context/ThemeContext';

import { ROLES } from '../config/roles';

const Layout = () => {
  const [isSidebarOpen, setIsSidebarOpen] = useState(false);
  const { user } = useAuth();
  const { darkMode, toggleDarkMode } = useTheme();
  const navigate = useNavigate();

  const toggleSidebar = () => setIsSidebarOpen(!isSidebarOpen);
  const roleLabel = user?.role === ROLES.PSICORIENTADOR
    ? `${user?.role} (pendiente de expansión)`
    : user?.role;

  return (
    <div className="flex min-h-screen bg-gray-50 dark:bg-slate-950 transition-colors duration-300 w-full">
      <Sidebar isOpen={isSidebarOpen} toggleSidebar={toggleSidebar} />

      <div className="flex-1 flex flex-col min-w-0">
        <header className="h-24 bg-white dark:bg-slate-900 border-b border-gray-100 dark:border-slate-800 flex items-center justify-between px-6 lg:px-12 sticky top-0 z-30 transition-colors">
          <button 
            onClick={toggleSidebar}
            className="p-3 rounded-2xl text-gray-400 hover:bg-gray-50 dark:hover:bg-slate-800 lg:hidden transition-colors"
          >
            <Menu size={24} />
          </button>

          <div className="flex-1 lg:flex-none">
            <h1 className="text-2xl font-black text-gray-900 dark:text-white lg:ml-0 ml-4 tracking-tighter uppercase">
              {user?.role === ROLES.SECRETARIA ? 'Tareas Pendientes' : (user?.school_name ? `Panel de ${user.school_name}` : `Panel de ${roleLabel}`)}
            </h1>
          </div>

          <div className="flex items-center gap-4 lg:gap-8">
            <button 
              onClick={() => navigate('/notificaciones')}
              className="p-3 rounded-2xl text-gray-400 hover:bg-gray-50 dark:hover:bg-slate-800 relative transition-all hover:scale-105 active:scale-95"
            >
              <Bell size={24} />
              <span className="absolute top-3.5 right-3.5 w-2.5 h-2.5 bg-institutional-400 rounded-full border-2 border-white dark:border-slate-900"></span>
            </button>
            
            <div className="hidden sm:flex items-center gap-5 border-l border-gray-100 dark:border-slate-800 pl-8">
              <div className="text-right">
                <p className="text-sm font-black text-gray-900 dark:text-white leading-none tracking-tight">{user?.nombre}</p>
                <p className="text-[10px] font-black text-institutional-600 dark:text-institutional-400 mt-2 uppercase tracking-[0.2em]">{roleLabel}</p>
              </div>
              <div className="w-12 h-12 rounded-2xl bg-institutional-900 text-white flex items-center justify-center font-black text-base shadow-lg shadow-institutional-900/20">
                {user?.nombre?.charAt(0)}
              </div>
            </div>
          </div>
        </header>

        <main className="flex-1 p-6 lg:p-12 overflow-y-auto">
          <div className="max-w-6xl mx-auto">
            <Outlet />
          </div>
        </main>

        <footer className="py-6 px-12 bg-white dark:bg-slate-900 border-t border-gray-100 dark:border-slate-800 text-center text-sm text-gray-400 transition-colors">
          © {new Date().getFullYear()} Institución Educativa - Sistema de Gestión Biométrica
        </footer>
      </div>
    </div>
  );
};

export default Layout;
