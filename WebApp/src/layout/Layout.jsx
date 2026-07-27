/**
 * Layout / NEXO Institucional
 * Shell de jornada (P-01). Sidebar + topbar + contenido.
 * Título contextual, notificaciones, estado de red y cuenta.
 */
import { useState, useRef, useEffect, useMemo } from 'react';
import { Outlet, useNavigate, useLocation } from 'react-router-dom';
import { motion, AnimatePresence } from 'framer-motion';
import { Menu, Bell, Settings, LogOut, ChevronDown } from 'lucide-react';
import Sidebar from './Sidebar';
import { useAuth } from '../hooks/useAuth';
import { useTheme } from '../context/ThemeContext';
import { SIDEBAR_ITEMS, getRoleDisplay } from '../config/roles';
import { notificationsApi } from '../api/notifications';
import { StatusDot } from '../components/patterns/StatusDot';

const pageTitle = (pathname) => {
  const item = SIDEBAR_ITEMS.find((i) => pathname === i.path || pathname.startsWith(i.path + '/'));
  if (item) return item.title;
  if (pathname.startsWith('/casos')) return 'Casos Activos';
  return 'NEXO';
};

const Layout = () => {
  const [isSidebarOpen, setSidebarOpen] = useState(false);
  const [profileOpen, setProfileOpen] = useState(false);
  const [notifCount, setNotifCount] = useState(0);
  const [online, setOnline] = useState(navigator.onLine);
  const { user, logout } = useAuth();
  const { darkMode, toggleDarkMode } = useTheme();
  const navigate = useNavigate();
  const location = useLocation();
  const profileRef = useRef(null);

  useEffect(() => {
    const on = () => setOnline(true);
    const off = () => setOnline(false);
    window.addEventListener('online', on);
    window.addEventListener('offline', off);
    return () => { window.removeEventListener('online', on); window.removeEventListener('offline', off); };
  }, []);

  useEffect(() => {
    const poll = () => {
      notificationsApi.getAll().then((data) => {
        const arr = Array.isArray(data) ? data : [];
        setNotifCount(arr.length);
      }).catch(() => {});
    };
    poll();
    const id = setInterval(poll, 60000);
    return () => clearInterval(id);
  }, []);

  useEffect(() => {
    const handler = (e) => {
      const count = e.detail?.count ?? 0;
      setNotifCount(count);
    };
    window.addEventListener('nexo:notif-count', handler);
    return () => window.removeEventListener('nexo:notif-count', handler);
  }, []);

  useEffect(() => {
    const onClick = (e) => { if (profileRef.current && !profileRef.current.contains(e.target)) setProfileOpen(false); };
    document.addEventListener('mousedown', onClick);
    return () => document.removeEventListener('mousedown', onClick);
  }, []);

  const title = useMemo(() => pageTitle(location.pathname), [location.pathname]);
  const roleDisplay = getRoleDisplay(user?.role);
  const initial = user?.nombre?.charAt(0)?.toUpperCase() ?? '?';

  return (
    <div className="min-h-screen bg-[var(--nx-canvas)]">
      <Sidebar isOpen={isSidebarOpen} toggleSidebar={() => setSidebarOpen((v) => !v)} />

      <div className="flex flex-col min-w-0 lg:pl-[var(--nx-sidebar)]">
        {/* Topbar */}
        <header className="sticky top-0 z-30 flex h-16 items-center justify-between border-b border-[var(--nx-border)] bg-[var(--nx-surface)] px-4 lg:px-8">
          <div className="flex items-center gap-3 min-w-0">
            <button
              onClick={() => setSidebarOpen((v) => !v)}
              className="lg:hidden p-2 rounded-control text-[var(--nx-text-muted)] hover:bg-[var(--nx-surface-subtle)] transition-colors"
              aria-label="Abrir menú"
            >
              <Menu size={20} />
            </button>
            <div className="min-w-0">
              <h1 className="text-h3 text-[var(--nx-text)] truncate">{title}</h1>
              <p className="text-caption text-[var(--nx-text-muted)] truncate">{user?.school_name ?? 'Sistema NEXO'} · {roleDisplay}</p>
            </div>
          </div>

          <div className="flex items-center gap-2 shrink-0">
            {/* Estado de red */}
            <div className="hidden sm:flex items-center gap-2 px-3 py-1.5 rounded-full border border-[var(--nx-border)] bg-[var(--nx-surface-subtle)]">
              <StatusDot scheme={online ? 'success' : 'warning'} pulse={online} />
              <span className="text-caption text-[var(--nx-text-muted)]">{online ? 'En línea' : 'Sin conexión'}</span>
            </div>

            {/* Notificaciones */}
            <button
              onClick={() => navigate('/notificaciones')}
              className="relative p-2.5 rounded-control text-[var(--nx-text-muted)] hover:bg-[var(--nx-surface-subtle)] hover:text-[var(--nx-text)] transition-colors"
              aria-label="Notificaciones"
            >
              <Bell size={20} />
              {notifCount > 0 && (
                <span className="absolute top-1.5 right-1.5 h-2.5 w-2.5 rounded-full bg-[var(--nx-accent)] border-2 border-[var(--nx-surface)]" />
              )}
            </button>

            {/* Perfil */}
            <div ref={profileRef} className="relative">
              <button
                onClick={() => setProfileOpen((v) => !v)}
                className="flex items-center gap-2.5 pl-2 pr-1 py-1 rounded-control hover:bg-[var(--nx-surface-subtle)] transition-colors"
              >
                <div className="h-9 w-9 rounded-control bg-[var(--nx-accent)] text-[var(--nx-accent-text)] text-sm font-bold flex items-center justify-center overflow-hidden">
                  {user?.profile_photo_url ? <img src={user.profile_photo_url} alt="" className="h-full w-full object-cover" /> : initial}
                </div>
                <ChevronDown size={14} className="hidden sm:block text-[var(--nx-text-muted)]" />
              </button>

              <AnimatePresence>
                {profileOpen && (
                  <motion.div
                    initial={{ opacity: 0, y: -4, scale: 0.98 }}
                    animate={{ opacity: 1, y: 0, scale: 1 }}
                    exit={{ opacity: 0, y: -4, scale: 0.98 }}
                    transition={{ duration: 0.15, ease: [0.22, 1, 0.36, 1] }}
                    className="absolute right-0 top-full mt-2 w-52 rounded-surface border border-[var(--nx-border)] bg-[var(--nx-surface)] shadow-high overflow-hidden z-50"
                  >
                    <button
                      onClick={() => { setProfileOpen(false); navigate('/perfil'); }}
                      className="flex w-full items-center gap-3 px-4 py-3 text-body text-[var(--nx-text)] hover:bg-[var(--nx-surface-subtle)] transition-colors"
                    >
                      <Settings size={16} /> Editar perfil
                    </button>
                    <button
                      onClick={() => { setProfileOpen(false); toggleDarkMode(); }}
                      className="flex w-full items-center gap-3 px-4 py-3 text-body text-[var(--nx-text)] hover:bg-[var(--nx-surface-subtle)] transition-colors"
                    >
                      {darkMode ? 'Modo claro' : 'Modo oscuro'}
                    </button>
                    <div className="border-t border-[var(--nx-border)]" />
                    <button
                      onClick={() => { setProfileOpen(false); logout(); }}
                      className="flex w-full items-center gap-3 px-4 py-3 text-body text-[var(--nx-danger)] hover:bg-[color-mix(in_oklch,var(--nx-danger)_6%,transparent)] transition-colors"
                    >
                      <LogOut size={16} /> Cerrar sesión
                    </button>
                  </motion.div>
                )}
              </AnimatePresence>
            </div>
          </div>
        </header>

        {/* Main */}
        <main className="flex-1 overflow-y-auto p-4 lg:p-8">
          <div className="mx-auto max-w-content">
            <AnimatePresence mode="wait" initial={false}>
              <motion.div
                key={location.pathname}
                initial={{ opacity: 0, y: 6 }}
                animate={{ opacity: 1, y: 0 }}
                exit={{ opacity: 0 }}
                transition={{ duration: 0.2, ease: [0.22, 1, 0.36, 1] }}
              >
                <Outlet />
              </motion.div>
            </AnimatePresence>
          </div>
        </main>
      </div>
    </div>
  );
};

export default Layout;
