/**
 * Layout / NEXO Institucional
 * Shell autenticado: sidebar + topbar (CMP-031) + contenido + status bar.
 * Sin glassmorphism. Tokens OKLCH. Búsqueda global para todos los roles.
 */
import { useState, useRef, useEffect } from 'react';
import { Outlet, useNavigate, useLocation } from 'react-router-dom';
import { motion, AnimatePresence } from 'framer-motion';
import Sidebar from './Sidebar';
import { Menu, Bell, LogOut, Settings, ChevronDown, Search, X, Command } from 'lucide-react';
import { useAuth } from '../hooks/useAuth';
import { useTheme } from '../context/ThemeContext';
import { getRoleDisplay, SIDEBAR_ITEMS, ROLES, OPERATION_COMMANDS } from '../config/roles';
import { notificationsApi } from '../api/notifications';
import {
  Calendar, ShieldCheck, AlertOctagon, Wrench, Send, Bus, Clock, UserCheck, ShieldAlert,
  Users, Activity, FileText,
} from 'lucide-react';

/* ── Audit subdivisions for search catalog ── */
const AUDIT_SUBDIVISIONS = [
  { title: 'Inasistencias', path: '/auditoria', icon: Users, roles: [ROLES.RECTOR], keywords: ['asistencia','inasistencia','falta'] },
  { title: 'Llegadas tarde', path: '/auditoria', icon: Clock, roles: [ROLES.RECTOR], keywords: ['asistencia','tarde','retardo'] },
  { title: 'Evasión interna', path: '/auditoria', icon: ShieldAlert, roles: [ROLES.RECTOR], keywords: ['asistencia','evasion','fuga'] },
  { title: 'Intentos salón incorrecto', path: '/auditoria', icon: Search, roles: [ROLES.RECTOR], keywords: ['disciplina','salon','aula'] },
  { title: 'Spam biométrico', path: '/auditoria', icon: Activity, roles: [ROLES.RECTOR], keywords: ['disciplina','spam','biometrico','intentos'] },
  { title: 'Reporte disciplinario', path: '/auditoria', icon: FileText, roles: [ROLES.RECTOR], keywords: ['disciplina','reporte','conducta'] },
  { title: 'Salidas clase', path: '/auditoria', icon: Bus, roles: [ROLES.RECTOR], keywords: ['permisos','salida','clase'] },
  { title: 'Salidas colegio', path: '/auditoria', icon: ShieldCheck, roles: [ROLES.RECTOR], keywords: ['permisos','salida','colegio'] },
  { title: 'Salidas pedagógicas', path: '/auditoria', icon: Bus, roles: [ROLES.RECTOR], keywords: ['permisos','salida','pedagogica','excursion'] },
  { title: 'Retornos pendientes', path: '/auditoria', icon: Clock, roles: [ROLES.RECTOR], keywords: ['permisos','retorno','pendiente'] },
  { title: 'Historial permisos', path: '/auditoria', icon: FileText, roles: [ROLES.RECTOR], keywords: ['permisos','historial'] },
  { title: 'Permisos emitidos', path: '/auditoria', icon: UserCheck, roles: [ROLES.RECTOR], keywords: ['docente','permisos','emitidos'] },
  { title: 'Alertas SOS emitidas', path: '/auditoria', icon: AlertOctagon, roles: [ROLES.RECTOR], keywords: ['sos','alerta','emergencia'] },
];

const PARENT_MODULES = [
  { title: 'Asistencia', path: '/auditoria', icon: Users, roles: [ROLES.RECTOR], keywords: ['asistencia','inasistencia','llegadas','evasion'] },
  { title: 'Disciplina', path: '/auditoria', icon: ShieldAlert, roles: [ROLES.RECTOR], keywords: ['disciplina','incidente','salon','biometrico','reporte'] },
  { title: 'Permisos y Salidas', path: '/auditoria', icon: Bus, roles: [ROLES.RECTOR], keywords: ['permisos','salidas','retornos','historial'] },
  { title: 'Actividad Docente', path: '/auditoria', icon: Clock, roles: [ROLES.RECTOR], keywords: ['docente','permisos','clases'] },
  { title: 'Alertas', path: '/auditoria', icon: AlertOctagon, roles: [ROLES.RECTOR], keywords: ['alertas','sos','emergencia'] },
];

const SEARCH_CATALOG = [...SIDEBAR_ITEMS, ...OPERATION_COMMANDS, ...AUDIT_SUBDIVISIONS, ...PARENT_MODULES];

const GlobalSearchResults = ({ query, userRole, onSelect }) => {
  if (!query.trim()) {
    return (
      <div className="px-3 py-4 text-xs text-center" style={{ color: 'var(--nx-text-muted)' }}>
        Escribe el nombre de un módulo, comando o sección (ej: <span className="font-semibold" style={{ color: 'var(--nx-text)' }}>Asistencia</span>, <span className="font-semibold" style={{ color: 'var(--nx-text)' }}>Citar acudiente</span>)
      </div>
    );
  }
  const q = query.trim().toLowerCase();
  const results = SEARCH_CATALOG.filter(item => {
    if (!item.roles.includes(userRole)) return false;
    const text = item.title.toLowerCase();
    const path = item.path.replace('/', '').toLowerCase();
    const kw = (item.keywords || []).join(' ').toLowerCase();
    return text.includes(q) || path.includes(q) || kw.includes(q);
  });
  if (results.length === 0) {
    return <div className="px-3 py-4 text-xs text-center" style={{ color: 'var(--nx-text-muted)' }}>Sin resultados</div>;
  }
  return (
    <div className="max-h-60 overflow-auto">
      {results.map((item, i) => (
        <button
          key={`${item.path}-${item.title}-${i}`}
          onClick={() => onSelect(item)}
          className="flex items-center gap-3 w-full px-3 py-2.5 text-left transition-colors hover:bg-[var(--nx-surface-subtle)]"
        >
          <item.icon size={16} strokeWidth={1.75} className="shrink-0" style={{ color: 'var(--nx-text-muted)' }} />
          <span className="text-sm font-medium" style={{ color: 'var(--nx-text)' }}>{item.title}</span>
          <span className="ml-auto text-[10px] font-mono" style={{ color: 'var(--nx-text-muted)' }}>{item.path}</span>
        </button>
      ))}
    </div>
  );
};

const PAGE_VARIANTS = {
  initial:    { opacity: 0, y: 6 },
  animate:    { opacity: 1, y: 0 },
  exit:       { opacity: 0 },
  transition: { duration: 0.2, ease: [0.22, 1, 0.36, 1] },
};

const Divider = () => (
  <span
    className="shrink-0"
    style={{ display: 'block', width: '1px', height: '20px', backgroundColor: 'var(--nx-border)' }}
  />
);

const Layout = () => {
  const [isSidebarOpen, setIsSidebarOpen] = useState(false);
  const [profileOpen, setProfileOpen]     = useState(false);
  const [searchOpen, setSearchOpen]       = useState(false);
  const [searchQuery, setSearchQuery]     = useState('');
  const [notifCount, setNotifCount]       = useState(0);
  const { user, logout }                  = useAuth();
  const navigate                          = useNavigate();
  const location                          = useLocation();
  const profileRef                        = useRef(null);
  const searchRef                         = useRef(null);

  useEffect(() => {
    const pollNotifs = () => {
      notificationsApi.getAll()
        .then(data => {
          const count = Array.isArray(data) ? data.length : 0;
          const lastSeenCount = parseInt(sessionStorage.getItem('nexo:notif-last-count') || '0', 10);
          setNotifCount(count);
          if (count > lastSeenCount) {
            sessionStorage.setItem('nexo:notif-last-count', count.toString());
          }
        })
        .catch(() => {});
    };

    let interval = null;
    const start = () => {
      pollNotifs();
      interval = setInterval(pollNotifs, 60000);
    };
    const stop = () => {
      if (interval) clearInterval(interval);
      interval = null;
    };

    const handleVisibility = () => {
      if (document.hidden) { stop(); } else { start(); }
    };

    start();
    document.addEventListener('visibilitychange', handleVisibility);
    return () => {
      stop();
      document.removeEventListener('visibilitychange', handleVisibility);
    };
  }, []);

  useEffect(() => {
    const handler = (e) => {
      const count = e.detail?.count ?? 0;
      setNotifCount(count);
      sessionStorage.setItem('nexo:notif-last-count', count.toString());
    };
    window.addEventListener('nexo:notif-count', handler);
    return () => window.removeEventListener('nexo:notif-count', handler);
  }, []);

  const toggleSidebar = () => setIsSidebarOpen(v => !v);
  const roleDisplay   = getRoleDisplay(user?.role);
  const initial       = user?.nombre?.charAt(0)?.toUpperCase() ?? '?';

  useEffect(() => {
    function handleClickOutside(e) {
      if (profileRef.current && !profileRef.current.contains(e.target)) setProfileOpen(false);
      if (searchRef.current && !searchRef.current.contains(e.target)) setSearchOpen(false);
    }
    document.addEventListener('mousedown', handleClickOutside);
    return () => document.removeEventListener('mousedown', handleClickOutside);
  }, []);

  useEffect(() => {
    const handler = (e) => {
      if ((e.metaKey || e.ctrlKey) && e.key === 'k') {
        e.preventDefault();
        setSearchOpen(v => !v);
      }
      if (e.key === 'Escape') setSearchOpen(false);
    };
    document.addEventListener('keydown', handler);
    return () => document.removeEventListener('keydown', handler);
  }, []);

  return (
    <div className="flex min-h-screen w-full transition-colors duration-200" style={{ backgroundColor: 'var(--nx-canvas)' }}>
      <Sidebar isOpen={isSidebarOpen} toggleSidebar={toggleSidebar} />

      <div className="flex-1 flex flex-col min-w-0">

        {/* ── Header — CMP-031 Topbar, sin glassmorphism ── */}
        <header
          className="sticky top-0 z-30 flex items-center justify-between px-5 lg:px-8 shrink-0"
          style={{
            height: '56px',
            backgroundColor: 'var(--nx-surface)',
            borderBottom: '1px solid var(--nx-border)',
          }}
        >
          {/* Left — hamburger + institution identity */}
          <div className="flex items-center gap-3 min-w-0">
            <button
              onClick={toggleSidebar}
              className="lg:hidden shrink-0 p-1.5 transition-colors"
              style={{ color: 'var(--nx-text-muted)' }}
              aria-label="Abrir menú"
            >
              <Menu size={20} strokeWidth={1.75} />
            </button>

            <div className="min-w-0">
              <p
                className="font-semibold truncate leading-none"
                style={{ fontSize: '14px', color: 'var(--nx-text)' }}
              >
                {user?.school_name ?? 'Sistema NEXO'}
              </p>
              <p
                className="mt-1 leading-none select-none truncate"
                style={{ fontSize: '11px', fontWeight: 500, color: 'var(--nx-success)' }}
              >
                {roleDisplay}
              </p>
            </div>
          </div>

          {/* Center — global search */}
          <div className="hidden md:flex flex-1 justify-center px-4 max-w-md" ref={searchRef}>
            <button
              onClick={() => setSearchOpen(true)}
              className="flex items-center gap-2 w-full px-3 py-1.5 text-xs transition-colors"
              style={{
                color: 'var(--nx-text-muted)',
                backgroundColor: 'var(--nx-surface-subtle)',
                border: '1px solid var(--nx-border)',
                borderRadius: 'var(--nx-radius-control)',
              }}
            >
              <Search size={13} strokeWidth={1.75} />
              <span className="flex-1 text-left">Buscar módulo…</span>
              <span className="hidden lg:inline-flex items-center gap-0.5 text-[10px] font-semibold" style={{ color: 'var(--nx-text-muted)' }}>
                <Command size={10} strokeWidth={2} />K
              </span>
            </button>

            <AnimatePresence>
              {searchOpen && (
                <motion.div
                  initial={{ opacity: 0, y: -8, scale: 0.98 }}
                  animate={{ opacity: 1, y: 0, scale: 1 }}
                  exit={{ opacity: 0, y: -8, scale: 0.98 }}
                  transition={{ duration: 0.15, ease: [0.22, 1, 0.36, 1] }}
                  className="absolute top-full mt-2 left-1/2 -translate-x-1/2 w-full max-w-md z-50 overflow-hidden"
                  style={{
                    backgroundColor: 'var(--nx-surface)',
                    border: '1px solid var(--nx-border)',
                    borderRadius: 'var(--nx-radius-surface)',
                    boxShadow: 'var(--nx-shadow-high)',
                  }}
                >
                  <div className="flex items-center gap-2 px-3 py-2" style={{ borderBottom: '1px solid var(--nx-border)' }}>
                    <Search size={14} style={{ color: 'var(--nx-text-muted)' }} />
                    <input
                      autoFocus
                      type="text"
                      value={searchQuery}
                      onChange={e => setSearchQuery(e.target.value)}
                      placeholder="Escribe nombre del módulo o función…"
                      className="flex-1 text-sm bg-transparent outline-none placeholder:text-[var(--nx-text-muted)]"
                      style={{ color: 'var(--nx-text)' }}
                    />
                    {searchQuery && (
                      <button onClick={() => setSearchQuery('')} style={{ color: 'var(--nx-text-muted)' }}>
                        <X size={14} />
                      </button>
                    )}
                  </div>
                  <GlobalSearchResults
                    query={searchQuery}
                    userRole={user?.role}
                    onSelect={(item) => {
                      setSearchOpen(false);
                      setSearchQuery('');
                      if (item.path === '/auditoria' && AUDIT_SUBDIVISIONS.some(s => s.title === item.title)) {
                        navigate(`/auditoria?sub=${encodeURIComponent(item.title)}`);
                      } else if (item.path === '/operacion' && OPERATION_COMMANDS.some(c => c.title === item.title)) {
                        navigate(`/operacion?cmd=${encodeURIComponent(item.title)}`);
                      } else {
                        navigate(item.path);
                      }
                    }}
                  />
                </motion.div>
              )}
            </AnimatePresence>
          </div>

          {/* Right — live status + bell + user */}
          <div className="flex items-center gap-3 shrink-0">

            {/* Online status */}
            <div className="hidden sm:flex items-center gap-1.5 select-none">
              <span className="block h-1.5 w-1.5 rounded-full animate-pulse-bio" style={{ backgroundColor: 'var(--nx-success)' }} />
              <span
                style={{ fontSize: '11px', fontWeight: 500, color: 'var(--nx-success)' }}
              >
                En línea
              </span>
            </div>

            <Divider />

            {/* Notifications */}
            <button
              onClick={() => navigate('/notificaciones')}
              className="relative p-1.5 transition-colors"
              style={{ color: 'var(--nx-text-muted)' }}
              title="Notificaciones"
            >
              <Bell size={18} strokeWidth={1.75} />
              {notifCount > 0 && (
                <span
                  className="absolute top-1 right-1 block h-2 w-2 rounded-full"
                  style={{ backgroundColor: 'var(--nx-success)', boxShadow: '0 0 0 2px var(--nx-surface)' }}
                />
              )}
            </button>

            <Divider />

            {/* User identity */}
            <div className="relative" ref={profileRef}>
              <button
                onClick={() => setProfileOpen(v => !v)}
                className="flex items-center gap-2.5 focus:outline-none"
              >
                <div className="hidden sm:block text-right">
                  <p
                    className="font-medium leading-none truncate max-w-[120px]"
                    style={{ fontSize: '12px', color: 'var(--nx-text)' }}
                  >
                    {user?.nombre}
                  </p>
                  <p
                    className="mt-0.5 leading-none select-none"
                    style={{ fontSize: '11px', fontWeight: 500, color: 'var(--nx-text-muted)' }}
                  >
                    {roleDisplay}
                  </p>
                </div>
                <div
                  className="flex items-center justify-center w-8 h-8 shrink-0 text-xs font-semibold overflow-hidden"
                  style={{ backgroundColor: 'var(--nx-accent)', color: 'var(--nx-accent-text)', borderRadius: 'var(--nx-radius-control)' }}
                >
                  {user?.profile_photo_url ? (
                    <img src={user.profile_photo_url} alt="" className="w-full h-full object-cover" />
                  ) : (
                    initial
                  )}
                </div>
                <ChevronDown size={12} className="hidden sm:block" style={{ color: 'var(--nx-text-muted)' }} />
              </button>

              {/* Profile dropdown */}
              <AnimatePresence>
                {profileOpen && (
                  <motion.div
                    initial={{ opacity: 0, y: -4, scale: 0.98 }}
                    animate={{ opacity: 1, y: 0, scale: 1 }}
                    exit={{ opacity: 0, y: -4, scale: 0.98 }}
                    transition={{ duration: 0.15, ease: [0.22, 1, 0.36, 1] }}
                    className="absolute right-0 top-full mt-2 w-48 overflow-hidden z-50"
                    style={{
                      backgroundColor: 'var(--nx-surface)',
                      border: '1px solid var(--nx-border)',
                      borderRadius: 'var(--nx-radius-surface)',
                      boxShadow: 'var(--nx-shadow-high)',
                    }}
                  >
                    <button
                      onClick={() => { setProfileOpen(false); navigate('/perfil'); }}
                      className="flex items-center gap-2.5 w-full px-4 py-2.5 text-xs font-medium transition-colors hover:bg-[var(--nx-surface-subtle)]"
                      style={{ color: 'var(--nx-text)' }}
                    >
                      <Settings size={14} strokeWidth={1.75} />
                      Editar perfil
                    </button>
                    <div style={{ borderTop: '1px solid var(--nx-border)' }} />
                    <button
                      onClick={() => { setProfileOpen(false); logout(); }}
                      className="flex items-center gap-2.5 w-full px-4 py-2.5 text-xs font-medium transition-colors hover:bg-[var(--nx-surface-subtle)]"
                      style={{ color: 'var(--nx-danger)' }}
                    >
                      <LogOut size={14} strokeWidth={1.75} />
                      Cerrar sesión
                    </button>
                  </motion.div>
                )}
              </AnimatePresence>
            </div>
          </div>
        </header>

        {/* ── Page content with route transitions ── */}
        <main className="flex-1 overflow-y-auto p-5 lg:p-8">
          <div className="max-w-7xl mx-auto">
            <AnimatePresence mode="wait" initial={false}>
              <motion.div
                key={location.pathname}
                initial={PAGE_VARIANTS.initial}
                animate={PAGE_VARIANTS.animate}
                exit={PAGE_VARIANTS.exit}
                transition={PAGE_VARIANTS.transition}
              >
                <Outlet />
              </motion.div>
            </AnimatePresence>
          </div>
        </main>

        {/* ── Status bar footer ── */}
        <footer
          className="shrink-0 flex items-center justify-between px-8 py-2"
          style={{ borderTop: '1px solid var(--nx-border)', backgroundColor: 'var(--nx-surface)' }}
        >
          <p style={{ fontSize: '11px', fontWeight: 500, color: 'var(--nx-text-muted)', userSelect: 'none' }}>
            NEXO · Sistema de custodia estudiantil en tiempo real
          </p>
          <p style={{ fontSize: '11px', fontWeight: 500, color: 'var(--nx-text-muted)', userSelect: 'none' }}>
            © {new Date().getFullYear()} · Uso Restringido
          </p>
        </footer>
      </div>
    </div>
  );
};

export default Layout;
