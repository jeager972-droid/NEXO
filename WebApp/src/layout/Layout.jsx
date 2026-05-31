import { useState, useRef, useEffect } from 'react';
import { Outlet, useNavigate, useLocation } from 'react-router-dom';
import { motion, AnimatePresence } from 'framer-motion';
import Sidebar from './Sidebar';
import { Menu, Bell, LogOut, User, Settings, ChevronDown } from 'lucide-react';
import { useAuth } from '../hooks/useAuth';
import { useTheme } from '../context/ThemeContext';
import { getRoleDisplay } from '../config/roles';

const PAGE_VARIANTS = {
  initial:    { opacity: 0, y: 6 },
  animate:    { opacity: 1, y: 0 },
  exit:       { opacity: 0 },
  transition: { duration: 0.2, ease: [0.25, 0.46, 0.45, 0.94] },
};

const Divider = () => (
  <span
    className="shrink-0"
    style={{ display: 'block', width: '1px', height: '20px', backgroundColor: 'rgba(226,232,240,0.7)' }}
  />
);

const Layout = () => {
  const [isSidebarOpen, setIsSidebarOpen] = useState(false);
  const [profileOpen, setProfileOpen]     = useState(false);
  const { user, logout }                  = useAuth();
  const { darkMode }                      = useTheme();
  const navigate                          = useNavigate();
  const location                          = useLocation();
  const profileRef                        = useRef(null);

  const toggleSidebar = () => setIsSidebarOpen(v => !v);
  const roleDisplay   = getRoleDisplay(user?.role);
  const initial       = user?.nombre?.charAt(0)?.toUpperCase() ?? '?';

  useEffect(() => {
    function handleClickOutside(e) {
      if (profileRef.current && !profileRef.current.contains(e.target)) setProfileOpen(false);
    }
    document.addEventListener('mousedown', handleClickOutside);
    return () => document.removeEventListener('mousedown', handleClickOutside);
  }, []);

  const headerBg     = darkMode ? 'rgba(2,6,23,0.90)'      : 'rgba(250,250,249,0.90)';
  const headerBorder = darkMode ? 'rgba(30,41,59,0.8)'     : 'rgba(226,232,240,0.85)';
  const bodyBg       = darkMode ? '#020617'                : '#FAFAF9';
  const footerBg     = darkMode ? '#070D1B'                : '#FFFFFF';
  const footerBorder = darkMode ? 'rgba(15,23,42,0.8)'     : '#F1F5F9';

  return (
    <div
      className="flex min-h-screen w-full transition-colors duration-300"
      style={{ backgroundColor: bodyBg }}
    >
      <Sidebar isOpen={isSidebarOpen} toggleSidebar={toggleSidebar} />

      <div className="flex-1 flex flex-col min-w-0">

        {/* ── Header — Glassmorphism ── */}
        <header
          className="sticky top-0 z-30 flex items-center justify-between px-5 lg:px-8 shrink-0"
          style={{
            height:              '56px',
            backdropFilter:      'blur(8px)',
            WebkitBackdropFilter:'blur(8px)',
            backgroundColor:     headerBg,
            borderBottom:        `1.5px solid ${headerBorder}`,
          }}
        >
          {/* Left — hamburger + institution identity */}
          <div className="flex items-center gap-3 min-w-0">
            <button
              onClick={toggleSidebar}
              className="lg:hidden shrink-0 p-1.5 text-slate-400 hover:text-slate-600 dark:hover:text-white transition-colors"
            >
              <Menu size={20} strokeWidth={2} />
            </button>

            <div className="min-w-0">
              <p
                className="font-black uppercase truncate leading-none"
                style={{ fontSize: '11px', letterSpacing: '0.02em', color: darkMode ? '#F1F5F9' : '#003366' }}
              >
                {user?.school_name ?? 'Sistema NEXO'}
              </p>
              <p
                className="mt-1 leading-none select-none truncate"
                style={{ fontSize: '9px', fontWeight: 700, letterSpacing: '0.22em', color: '#00A67E', textTransform: 'uppercase' }}
              >
                {roleDisplay}
              </p>
            </div>
          </div>

          {/* Right — live status + bell + user */}
          <div className="flex items-center gap-3 shrink-0">

            {/* Biometric live status */}
            <div className="hidden sm:flex items-center gap-1.5 select-none">
              <span className="block h-1.5 w-1.5 rounded-full animate-pulse-bio" style={{ backgroundColor: '#00A67E' }} />
              <span
                style={{ fontSize: '9px', fontWeight: 700, letterSpacing: '0.2em', color: '#00A67E', textTransform: 'uppercase' }}
              >
                En línea
              </span>
            </div>

            <Divider />

            {/* Notifications */}
            <button
              onClick={() => navigate('/notificaciones')}
              className="relative p-1.5 text-slate-400 hover:text-slate-600 dark:hover:text-slate-200 transition-colors"
              title="Notificaciones"
            >
              <Bell size={18} strokeWidth={2} />
              <span
                className="absolute top-1 right-1 block h-2 w-2 rounded-full"
                style={{ backgroundColor: '#00A67E', boxShadow: '0 0 0 2px ' + (darkMode ? '#020617' : '#FAFAF9') }}
              />
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
                    className="font-bold leading-none truncate max-w-[120px]"
                    style={{ fontSize: '12px', color: darkMode ? '#F1F5F9' : '#1E293B' }}
                  >
                    {user?.nombre}
                  </p>
                  <p
                    className="mt-0.5 leading-none select-none"
                    style={{ fontSize: '9px', fontWeight: 700, letterSpacing: '0.16em', color: '#94A3B8', textTransform: 'uppercase' }}
                  >
                    {roleDisplay}
                  </p>
                </div>
                <div
                  className="flex items-center justify-center w-8 h-8 shrink-0 text-xs font-black text-white overflow-hidden"
                  style={{ backgroundColor: '#003366' }}
                >
                  {user?.profile_photo_url ? (
                    <img src={user.profile_photo_url} alt="" className="w-full h-full object-cover" />
                  ) : (
                    initial
                  )}
                </div>
                <ChevronDown size={12} className="text-slate-400 hidden sm:block" />
              </button>

              {/* Profile dropdown */}
              <AnimatePresence>
                {profileOpen && (
                  <motion.div
                    initial={{ opacity: 0, y: -4, scale: 0.98 }}
                    animate={{ opacity: 1, y: 0, scale: 1 }}
                    exit={{ opacity: 0, y: -4, scale: 0.98 }}
                    transition={{ duration: 0.15 }}
                    className="absolute right-0 top-full mt-2 w-48 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-lg shadow-lg overflow-hidden z-50"
                  >
                    <button
                      onClick={() => { setProfileOpen(false); navigate('/perfil'); }}
                      className="flex items-center gap-2.5 w-full px-4 py-2.5 text-xs font-medium text-slate-600 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-slate-800 transition-colors"
                    >
                      <Settings size={14} strokeWidth={2} />
                      Editar perfil
                    </button>
                    <div className="border-t border-slate-100 dark:border-slate-800" />
                    <button
                      onClick={() => { setProfileOpen(false); logout(); }}
                      className="flex items-center gap-2.5 w-full px-4 py-2.5 text-xs font-medium text-red-600 hover:bg-red-50 dark:hover:bg-red-900/20 transition-colors"
                    >
                      <LogOut size={14} strokeWidth={2} />
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
          style={{ borderTop: `1.5px solid ${footerBorder}`, backgroundColor: footerBg }}
        >
          <p style={{ fontSize: '9px', fontWeight: 700, letterSpacing: '0.2em', color: '#CBD5E1', textTransform: 'uppercase', userSelect: 'none' }}>
            NEXO · Sistema de custodia estudiantil en tiempo real
          </p>
          <p style={{ fontSize: '9px', fontWeight: 700, letterSpacing: '0.2em', color: '#CBD5E1', textTransform: 'uppercase', userSelect: 'none' }}>
            © {new Date().getFullYear()} · Uso Restringido
          </p>
        </footer>
      </div>
    </div>
  );
};

export default Layout;
