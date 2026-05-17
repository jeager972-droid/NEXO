import { NavLink } from 'react-router-dom';
import { useAuth } from '../hooks/useAuth';
import { SIDEBAR_ITEMS, getRoleDisplay } from '../config/roles';
import { LogOut, Sun, Moon, X } from 'lucide-react';
import { motion, AnimatePresence } from 'framer-motion';
import LogoNexo from '../components/LogoNexo';
import { useTheme } from '../context/ThemeContext';

const SectionLabel = ({ children }) => (
  <p
    className="px-4 mb-1 mt-5 first:mt-0 select-none"
    style={{ fontSize: '9px', fontWeight: 700, letterSpacing: '0.25em', color: '#94A3B8', textTransform: 'uppercase' }}
  >
    {children}
  </p>
);

const NavItem = ({ item, onNavigate }) => (
  <NavLink to={item.path} end={item.path === '/'} onClick={onNavigate} className="block group">
    {({ isActive }) => (
      <span
        className="flex items-center gap-3 py-2.5 pr-4 text-sm font-semibold transition-colors duration-200"
        style={{
          paddingLeft: '13px',
          borderLeft: isActive ? '3px solid #003366' : '3px solid transparent',
          color:           isActive ? '#003366' : undefined,
          backgroundColor: isActive ? 'rgba(0,51,102,0.05)' : undefined,
        }}
      >
        <item.icon
          size={20}
          strokeWidth={isActive ? 2.5 : 2}
          style={{ color: isActive ? '#003366' : undefined }}
          className={!isActive ? 'text-slate-400 dark:text-slate-500 group-hover:text-slate-700 dark:group-hover:text-slate-200 transition-colors' : ''}
        />
        <span className={isActive ? '' : 'text-slate-500 dark:text-slate-400 group-hover:text-slate-800 dark:group-hover:text-slate-200'}>
          {item.title}
        </span>
      </span>
    )}
  </NavLink>
);

const Sidebar = ({ isOpen, toggleSidebar }) => {
  const { user, logout } = useAuth();
  const { darkMode, toggleDarkMode } = useTheme();

  const filteredItems = SIDEBAR_ITEMS.filter(item => item.roles.includes(user?.role));
  const roleDisplay   = getRoleDisplay(user?.role);
  const initial       = user?.nombre?.charAt(0)?.toUpperCase() ?? '?';
  const closeOnMobile = () => { if (window.innerWidth < 1024) toggleSidebar(); };

  return (
    <>
      <AnimatePresence>
        {isOpen && (
          <motion.div
            key="overlay"
            initial={{ opacity: 0 }}
            animate={{ opacity: 1 }}
            exit={{ opacity: 0 }}
            transition={{ duration: 0.2 }}
            className="fixed inset-0 z-40 lg:hidden"
            style={{ backgroundColor: 'rgba(2,6,23,0.45)', backdropFilter: 'blur(2px)' }}
            onClick={toggleSidebar}
          />
        )}
      </AnimatePresence>

      <aside
        className={`
          fixed inset-y-0 left-0 z-50 flex flex-col
          bg-white dark:bg-slate-900
          transform transition-transform duration-300
          lg:relative lg:translate-x-0
          ${isOpen ? 'translate-x-0' : '-translate-x-full'}
        `}
        style={{ width: '220px', borderRight: '1.5px solid #E2E8F0' }}
      >
        {/* Logo strip — height synced with header (56px) */}
        <div
          className="flex items-center justify-between px-4 shrink-0"
          style={{ height: '56px', borderBottom: '1.5px solid #F1F5F9' }}
        >
          <LogoNexo className="h-7" />
          <button
            onClick={toggleSidebar}
            className="lg:hidden text-slate-400 hover:text-slate-600 dark:hover:text-white transition-colors p-1"
          >
            <X size={17} strokeWidth={2} />
          </button>
        </div>

        {/* Navigation */}
        <nav className="flex-1 overflow-y-auto py-3" style={{ scrollbarWidth: 'none' }}>
          <SectionLabel>Módulos</SectionLabel>
          {filteredItems.map(item => (
            <NavItem key={item.path} item={item} onNavigate={closeOnMobile} />
          ))}
        </nav>

        {/* Bottom panel */}
        <div className="shrink-0 p-3 space-y-1" style={{ borderTop: '1.5px solid #F1F5F9' }}>
          <button
            onClick={toggleDarkMode}
            className="flex items-center gap-2.5 w-full px-3 py-2 text-xs font-medium text-slate-500 dark:text-slate-400 hover:text-slate-800 dark:hover:text-white hover:bg-slate-50 dark:hover:bg-white/5 transition-colors"
          >
            {darkMode ? <Sun size={16} strokeWidth={2} /> : <Moon size={16} strokeWidth={2} />}
            <span>{darkMode ? 'Modo Claro' : 'Modo Oscuro'}</span>
          </button>

          {/* User identity card */}
          <div
            className="flex items-center gap-2.5 px-3 py-2.5 mt-1"
            style={{ backgroundColor: 'rgba(0,51,102,0.04)', border: '1.5px solid #E2E8F0' }}
          >
            <div
              className="shrink-0 flex items-center justify-center w-8 h-8 text-xs font-black text-white"
              style={{ backgroundColor: '#003366' }}
            >
              {initial}
            </div>
            <div className="flex-1 min-w-0">
              <p className="text-xs font-bold text-slate-800 dark:text-white truncate leading-none">
                {user?.nombre}
              </p>
              <p
                className="mt-0.5 truncate leading-none"
                style={{ fontSize: '9px', fontWeight: 700, letterSpacing: '0.12em', color: '#00A67E', textTransform: 'uppercase' }}
              >
                {roleDisplay}
              </p>
            </div>
            <button
              onClick={logout}
              title="Cerrar sesión"
              className="shrink-0 p-1 text-slate-300 dark:text-slate-600 hover:text-red-500 dark:hover:text-red-400 transition-colors"
            >
              <LogOut size={14} strokeWidth={2} />
            </button>
          </div>
        </div>
      </aside>
    </>
  );
};

export default Sidebar;
