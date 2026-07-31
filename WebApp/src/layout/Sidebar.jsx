/**
 * Sidebar / NEXO Institucional
 * Navegación lateral CMP-030. 240 px en escritorio, panel deslizante en compact.
 * Filtrada por rol, con tema y perfil en la base.
 */
import { useState, useEffect } from 'react';
import { NavLink } from 'react-router-dom';
import { useAuth } from '../hooks/useAuth';
import { useTheme } from '../context/ThemeContext';
import { getRoleDisplay, getSecondaryActions, SIDEBAR_ITEMS } from '../config/roles';
import { LogOut, Sun, Moon, X } from 'lucide-react';
import { motion, AnimatePresence } from 'framer-motion';
import LogoNexo from '../components/LogoNexo';
import { notificationsApi } from '../api/notifications';

const NavItem = ({ item, onClick, showNotifDot }) => {
  const Icon = item.icon;
  return (
    <NavLink
      to={item.path}
      end={item.path === '/'}
      onClick={onClick}
      className={({ isActive }) =>
        `group flex items-center gap-3 px-4 py-3 mx-3 rounded-control text-body font-medium transition-all duration-fast ` +
        (isActive
          ? 'bg-[color-mix(in_oklch,var(--nx-accent)_var(--nx-subtle-mix),transparent)] text-[var(--nx-accent)]'
          : 'text-[var(--nx-text-muted)] hover:bg-[var(--nx-surface-subtle)] hover:text-[var(--nx-text)]')
      }
    >
      <Icon size={20} strokeWidth={1.75} className="shrink-0" />
      <span className="truncate">{item.title}</span>
      {showNotifDot && (
        <span className="nx-blink ml-auto h-2 w-2 rounded-full bg-[var(--nx-accent)]" />
      )}
    </NavLink>
  );
};

const Sidebar = ({ isOpen, toggleSidebar }) => {
  const { user, logout } = useAuth();
  const { darkMode, toggleDarkMode } = useTheme();
  const secondaryItems = getSecondaryActions(user?.role);
  const allItems = SIDEBAR_ITEMS.filter((i) => i.roles.includes(user?.role));
  const roleDisplay = getRoleDisplay(user?.role);
  const initial = user?.nombre?.charAt(0)?.toUpperCase() ?? '?';
  const [notifCount, setNotifCount] = useState(0);

  useEffect(() => {
    const poll = () => {
      notificationsApi.getAll().then((data) => {
        const arr = Array.isArray(data) ? data : [];
        setNotifCount(arr.length);
      }).catch(() => {});
    };
    poll();
    const id = setInterval(poll, 60000);
    const handler = (e) => {
      const count = e.detail?.count ?? 0;
      setNotifCount(count > 0 ? count : 0);
    };
    window.addEventListener('nexo:notif-count', handler);
    return () => { clearInterval(id); window.removeEventListener('nexo:notif-count', handler); };
  }, []);

  const closeMobile = () => { if (window.innerWidth < 1024) toggleSidebar(); };

  return (
    <>
      <AnimatePresence>
        {isOpen && (
          <motion.div
            initial={{ opacity: 0 }} animate={{ opacity: 1 }} exit={{ opacity: 0 }}
            transition={{ duration: 0.2 }}
            onClick={toggleSidebar}
            className="fixed inset-0 z-40 bg-[color-mix(in_oklch,var(--nx-text)_45%,transparent)] lg:hidden"
          />
        )}
      </AnimatePresence>

      <aside
        className={`
          fixed inset-y-0 left-0 z-50 flex flex-col bg-[var(--nx-surface)] border-r border-[var(--nx-border)]
          transition-transform duration-200 ease-out
          ${isOpen ? 'translate-x-0' : '-translate-x-full'} lg:translate-x-0
        `}
        style={{ width: 'var(--nx-sidebar)' }}
        aria-label="Navegación principal"
      >
        {/* Logo */}
        <div className="flex items-center justify-between h-16 px-5 border-b border-[var(--nx-border)]">
          <LogoNexo className="h-8" />
          <button onClick={toggleSidebar} className="lg:hidden p-1 text-[var(--nx-text-muted)]" aria-label="Cerrar menú">
            <X size={20} />
          </button>
        </div>

        {/* Navigation */}
        <nav className="flex-1 overflow-y-auto py-4 space-y-1" aria-label="Módulos principales">
          {/* Mobile: only secondary actions (primary are in bottom bar) */}
          <div className="lg:hidden">
            {secondaryItems.map((item) => (
              <NavItem
                key={item.path}
                item={item}
                onClick={closeMobile}
                showNotifDot={item.path === '/notificaciones' && notifCount > 0}
              />
            ))}
          </div>
          {/* Desktop: all items */}
          <div className="hidden lg:block">
            {allItems.map((item) => (
              <NavItem
                key={item.path}
                item={item}
                onClick={closeMobile}
                showNotifDot={item.path === '/notificaciones' && notifCount > 0}
              />
            ))}
          </div>
        </nav>

        {/* Footer: theme + user */}
        <div className="p-4 border-t border-[var(--nx-border)] space-y-3">
          <button
            onClick={toggleDarkMode}
            className="flex items-center gap-3 w-full px-4 py-2.5 rounded-control text-body text-[var(--nx-text-muted)] hover:bg-[var(--nx-surface-subtle)] transition-colors duration-fast"
          >
            {darkMode ? <Sun size={18} /> : <Moon size={18} />}
            <span>{darkMode ? 'Modo claro' : 'Modo oscuro'}</span>
          </button>

          <div className="flex items-center gap-3 rounded-control border border-[var(--nx-border)] bg-[var(--nx-surface-subtle)] p-3">
            <div className="flex h-10 w-10 items-center justify-center rounded-control bg-[var(--nx-accent)] text-[var(--nx-accent-text)] text-sm font-bold shrink-0">
              {user?.profile_photo_url ? <img src={user.profile_photo_url} alt="" className="h-full w-full object-cover" /> : initial}
            </div>
            <div className="min-w-0 flex-1">
              <p className="text-body-sm font-medium text-[var(--nx-text)] truncate">{user?.nombre}</p>
              <p className="text-caption text-[var(--nx-success)] truncate">{roleDisplay}</p>
            </div>
            <button
              onClick={logout}
              title="Cerrar sesión"
              className="p-2 text-[var(--nx-text-muted)] hover:text-[var(--nx-danger)] rounded-control hover:bg-[var(--nx-surface)] transition-colors duration-fast"
            >
              <LogOut size={18} />
            </button>
          </div>
        </div>
      </aside>
    </>
  );
};

export default Sidebar;
