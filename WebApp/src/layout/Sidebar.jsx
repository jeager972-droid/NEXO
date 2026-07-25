/**
 * Sidebar / NEXO Institucional
 * Navegación lateral — CMP-030. Filtrada por rol, 240px en wide, panel en compact.
 * Diseño: NEXO Quiet Operations — OKLCH tokens, sin glassmorphism.
 */
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
    style={{ fontSize: '11px', fontWeight: 600, letterSpacing: '0.04em', color: 'var(--nx-text-muted)' }}
  >
    {children}
  </p>
);

const NavItem = ({ item, onNavigate }) => (
  <NavLink to={item.path} end={item.path === '/'} onClick={onNavigate} className="block group">
    {({ isActive }) => (
      <span
        className="flex items-center gap-3 py-2.5 pr-4 text-sm font-medium transition-colors duration-200"
        style={{
          paddingLeft: '12px',
          borderLeft: isActive ? '3px solid var(--nx-accent)' : '3px solid transparent',
          color: isActive ? 'var(--nx-accent)' : undefined,
          backgroundColor: isActive ? 'color-mix(in oklch, var(--nx-accent) 8%, transparent)' : undefined,
        }}
      >
        <item.icon
          size={20}
          strokeWidth={isActive ? 2.25 : 1.75}
          style={{ color: isActive ? 'var(--nx-accent)' : undefined }}
          className={!isActive ? 'text-[var(--nx-text-muted)] group-hover:text-[var(--nx-text)] transition-colors' : ''}
        />
        <span className={isActive ? '' : 'text-[var(--nx-text-muted)] group-hover:text-[var(--nx-text)] transition-colors'}>
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
            style={{ backgroundColor: 'color-mix(in oklch, var(--nx-text) 45%, transparent)' }}
            onClick={toggleSidebar}
          />
        )}
      </AnimatePresence>

      <aside
        className={`
          fixed inset-y-0 left-0 z-50 flex flex-col
          transform transition-transform duration-200 ease-out
          lg:relative lg:translate-x-0
          ${isOpen ? 'translate-x-0' : '-translate-x-full'}
        `}
        style={{
          width: '240px',
          backgroundColor: 'var(--nx-surface)',
          borderRight: '1px solid var(--nx-border)',
        }}
      >
        {/* Logo strip — 56px synced with header */}
        <div
          className="flex items-center justify-between px-4 shrink-0"
          style={{ height: '56px', borderBottom: '1px solid var(--nx-border)' }}
        >
          <LogoNexo className="h-7" />
          <button
            onClick={toggleSidebar}
            className="lg:hidden transition-colors p-1"
            style={{ color: 'var(--nx-text-muted)' }}
            aria-label="Cerrar menú"
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

        {/* Bottom panel — tema + perfil */}
        <div className="shrink-0 p-3 space-y-1" style={{ borderTop: '1px solid var(--nx-border)' }}>
          <button
            onClick={toggleDarkMode}
            className="flex items-center gap-2.5 w-full px-3 py-2 text-xs font-medium transition-colors hover:bg-[var(--nx-surface-subtle)]"
            style={{ color: 'var(--nx-text-muted)' }}
          >
            {darkMode ? <Sun size={16} strokeWidth={1.75} /> : <Moon size={16} strokeWidth={1.75} />}
            <span>{darkMode ? 'Modo claro' : 'Modo oscuro'}</span>
          </button>

          {/* User identity card */}
          <div
            className="flex items-center gap-2.5 px-3 py-2.5 mt-1"
            style={{
              backgroundColor: 'color-mix(in oklch, var(--nx-accent) 4%, transparent)',
              border: '1px solid var(--nx-border)',
              borderRadius: 'var(--nx-radius-control)',
            }}
          >
            <div
              className="shrink-0 flex items-center justify-center w-8 h-8 text-xs font-bold"
              style={{ backgroundColor: 'var(--nx-accent)', color: 'var(--nx-accent-text)', borderRadius: 'var(--nx-radius-control)' }}
            >
              {user?.profile_photo_url ? (
                <img src={user.profile_photo_url} alt="" className="w-full h-full object-cover" style={{ borderRadius: 'var(--nx-radius-control)' }} />
              ) : (
                initial
              )}
            </div>
            <div className="flex-1 min-w-0">
              <p className="text-xs font-semibold truncate leading-none" style={{ color: 'var(--nx-text)' }}>
                {user?.nombre}
              </p>
              <p
                className="mt-0.5 truncate leading-none"
                style={{ fontSize: '11px', fontWeight: 500, color: 'var(--nx-success)' }}
              >
                {roleDisplay}
              </p>
            </div>
            <button
              onClick={logout}
              title="Cerrar sesión"
              className="shrink-0 p-1 transition-colors"
              style={{ color: 'var(--nx-text-muted)' }}
            >
              <LogOut size={14} strokeWidth={1.75} />
            </button>
          </div>
        </div>
      </aside>
    </>
  );
};

export default Sidebar;
