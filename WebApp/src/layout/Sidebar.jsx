import { NavLink } from 'react-router-dom';
import { useAuth } from '../hooks/useAuth';
import { SIDEBAR_ITEMS } from '../config/roles';
import { LogOut, X, Sun, Moon } from 'lucide-react';
import { cn } from '../utils/cn';
import LogoNexo from '../components/LogoNexo';
import { useTheme } from '../context/ThemeContext';
import { ROLES } from '../config/roles';

const Sidebar = ({ isOpen, toggleSidebar }) => {
  const { user, logout } = useAuth();
  const { darkMode, toggleDarkMode } = useTheme();

  const filteredItems = SIDEBAR_ITEMS.filter(item => 
    item.roles.includes(user?.role)
  );
  const roleLabel = user?.role === ROLES.PSICORIENTADOR
    ? `${user?.role} (pendiente de expansión)`
    : user?.role;

  return (
    <>
      {/* Overlay for mobile */}
      {isOpen && (
        <div 
          className="fixed inset-0 bg-black/60 backdrop-blur-sm z-40 lg:hidden"
          onClick={toggleSidebar}
        />
      )}

      <aside className={cn(
        "fixed inset-y-0 left-0 z-50 w-72 bg-white dark:bg-slate-900 border-r border-gray-100 dark:border-slate-800 transform transition-all duration-300 ease-in-out lg:relative lg:translate-x-0",
        isOpen ? "translate-x-0 shadow-2xl" : "-translate-x-full"
      )}>
        <div className="flex items-center justify-between h-24 px-8 border-b border-gray-50 dark:border-slate-800/50">
          <LogoNexo className="h-10" />
          <button onClick={toggleSidebar} className="lg:hidden text-gray-400 hover:text-gray-600 dark:hover:text-white transition-colors">
            <X size={24} />
          </button>
        </div>

        <div className="flex flex-col h-[calc(100vh-96px)] justify-between py-6">
          <nav className="px-4 space-y-2">
            {filteredItems.map((item) => (
              <NavLink
                key={item.path}
                to={item.path}
                onClick={() => window.innerWidth < 1024 && toggleSidebar()}
                className={({ isActive }) => cn(
                  "flex items-center gap-3 px-5 py-4 rounded-2xl transition-all duration-200 group",
                  isActive 
                    ? "bg-institutional-900 text-white shadow-lg shadow-institutional-900/20 dark:shadow-none" 
                    : "text-gray-500 dark:text-slate-400 hover:bg-institutional-50 dark:hover:bg-slate-800 hover:text-institutional-900 dark:hover:text-white"
                )}
              >
                {({ isActive }) => (
                  <>
                    <item.icon 
                      size={22} 
                      className={cn(
                        "transition-colors", 
                        isActive ? "text-white" : "group-hover:text-institutional-700"
                      )} 
                    />
                    <span className="font-bold tracking-tight">{item.title}</span>
                  </>
                )}
              </NavLink>
            ))}
          </nav>

          <div className="px-6 space-y-6">
            <button
              onClick={toggleDarkMode}
              className="flex items-center gap-3 w-full px-5 py-4 rounded-2xl text-gray-500 dark:text-slate-400 hover:bg-gray-50 dark:hover:bg-slate-800 transition-all border border-transparent hover:border-gray-100 dark:hover:border-slate-700"
            >
              {darkMode ? <Sun size={20} /> : <Moon size={20} />}
              <span className="font-bold text-sm">{darkMode ? 'Modo Claro' : 'Modo Oscuro'}</span>
            </button>

            <div className="p-5 bg-gray-50 dark:bg-slate-800/50 rounded-3xl border border-gray-100 dark:border-slate-800/50">
              <div className="mb-4">
                <p className="text-sm font-black text-gray-900 dark:text-white truncate">{user?.nombre}</p>
                <p className="text-[10px] text-institutional-600 dark:text-institutional-400 font-black uppercase tracking-[0.2em] mt-1">{roleLabel}</p>
              </div>
              <button
                onClick={logout}
                className="flex items-center gap-3 w-full text-red-500 hover:text-red-600 transition-colors py-1"
              >
                <LogOut size={18} />
                <span className="font-bold text-xs">Cerrar Sesión</span>
              </button>
            </div>
          </div>
        </div>
      </aside>
    </>
  );
};

export default Sidebar;
