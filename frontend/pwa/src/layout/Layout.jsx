/**
 * Layout / NEXO Institucional
 * Shell de jornada (P-01). Sidebar + topbar + contenido.
 * Título contextual, notificaciones, estado de red y cuenta.
 */
import { useState, useRef, useEffect, useMemo, useCallback } from 'react';
import { Outlet, useNavigate, useLocation } from 'react-router-dom';
import { motion, AnimatePresence } from 'framer-motion';
import { Menu, Settings, LogOut, ChevronDown, Sun, Moon } from 'lucide-react';
import Sidebar from './Sidebar';
import { useAuth } from '../hooks/useAuth';
import { useTheme } from '../context/ThemeContext';
import { useNotifications } from '../context/NotificationContext';
import { getRoleDisplay, getPrimaryActions, ROLES } from '../config/roles';
import { NOTIF_ACTION_TO_CMD } from '../config/operations';
import { schoolApi } from '../api/school';
import OnboardingFlow from '../pages/onboarding/OnboardingFlow';
import { SystemInactiveScreen } from '../components/patterns/SystemInactiveScreen';
import { NodusGuide } from '../components/patterns/NodusGuide';
import { teacherApi } from '../api/teacher';
import { dashboardApi } from '../api/dashboard';
import { insightPrompt, setPendingPrompt } from '../lib/chatContext';
import { NavLink } from 'react-router-dom';

const getGreeting = () => {
  const h = new Date().getHours();
  if (h < 12) return 'Buenos días';
  if (h < 18) return 'Buenas tardes';
  return 'Buenas noches';
};

const Layout = () => {
  const [isSidebarOpen, setSidebarOpen] = useState(false);
  const [profileOpen, setProfileOpen] = useState(false);
  const [scheduleOnboardingRequired, setScheduleOnboardingRequired] = useState(false);
  const [groupsOnboardingRequired, setGroupsOnboardingRequired] = useState(false);
  const [riskOnboardingRequired, setRiskOnboardingRequired] = useState(false);
  const [teacherOnboardingRequired, setTeacherOnboardingRequired] = useState(false);
  const [onboardingLoading, setOnboardingLoading] = useState(true);
  const { user, logout } = useAuth();
  const { darkMode, toggleDarkMode } = useTheme();
  const { notifCount } = useNotifications();
  const navigate = useNavigate();
  const location = useLocation();
  const profileRef = useRef(null);


  useEffect(() => {
    const onClick = (e) => { if (profileRef.current && !profileRef.current.contains(e.target)) setProfileOpen(false); };
    document.addEventListener('mousedown', onClick);
    return () => document.removeEventListener('mousedown', onClick);
  }, []);

  // Onboarding unificado: consulta horarios + grupos + riesgo en paralelo.
  // Si alguna API falla (timeout, 500, red), NO se bloquea al usuario.
  // Solo se muestra el modal de onboarding si la API responde explicitamente
  // que falta configuracion.
  const checkOnboardingRun = useRef(null);
  useEffect(() => {
    if (!user?.id) {
      setOnboardingLoading(false);
      return;
    }

    let cancelled = false;
    setOnboardingLoading(true);
    const run = async () => {
      try {
        const requests = [
          schoolApi.getConfig(),
          schoolApi.getGroupsOnboarding(),
          schoolApi.getRiskConfig(),
        ];
        // El docente tiene su propio onboarding (criterios de aviso)
        if (user?.role === ROLES.DOCENTE) requests.push(teacherApi.getOnboarding());

        const [configResult, groupsResult, riskResult, teacherResult] = await Promise.allSettled(requests);

        if (cancelled) return;

        const config = configResult.status === 'fulfilled' ? configResult.value : null;
        const groupsResp = groupsResult.status === 'fulfilled' ? groupsResult.value : null;
        const riskResp = riskResult.status === 'fulfilled' ? riskResult.value : null;
        const teacherResp = teacherResult?.status === 'fulfilled' ? teacherResult.value : null;

        // Solo bloquear si la API respondio OK y dice que falta configuracion.
        // Si la API fallo (rejected), asumir que no falta (no bloquear).
        setScheduleOnboardingRequired(config ? !config.onboarding_completed : false);
        setGroupsOnboardingRequired(groupsResp ? !!groupsResp.needs_onboarding : false);
        setRiskOnboardingRequired(riskResp ? !!riskResp.needs_onboarding : false);
        // El onboarding docente solo aplica cuando el de la escuela ya está completo
        setTeacherOnboardingRequired(
          teacherResp ? !teacherResp.onboarding_completed : false
        );
      } catch (e) {
        if (!cancelled) {
          // Error inesperado: no bloquear
          setScheduleOnboardingRequired(false);
          setGroupsOnboardingRequired(false);
          setRiskOnboardingRequired(false);
        }
      } finally {
        if (!cancelled) setOnboardingLoading(false);
      }
    };
    checkOnboardingRun.current = run;
    run();

    return () => { cancelled = true; };
  }, [user?.id, user?.role]);

  const roleDisplay = getRoleDisplay(user?.role);
  // Roles operativos (docente, portero, auxiliar): solo barra inferior —
  // no necesitan drawer lateral; toda su navegación cabe en 4 acciones.
  const BOTTOM_ONLY = [ROLES.DOCENTE, ROLES.PORTERO, ROLES.AUXILIAR];
  const bottomOnly = BOTTOM_ONLY.includes(user?.role);
  const initial = user?.nombre?.charAt(0)?.toUpperCase() ?? '?';
  const greeting = useMemo(() => getGreeting(), []);
  const primaryActions = useMemo(() => getPrimaryActions(user?.role), [user?.role]);
  const firstName = user?.nombre?.split(' ')[0] || 'directivo';
  // Todos los roles tienen drawer lateral (allí vive Chat con Nodus);
  // en móvil la barra inferior sigue siendo la navegación principal.

  // Onboarding unificado — pantalla completa guiada por Nodus.
  // Nada del sistema se muestra hasta completar (o quedar pendiente
  // de otro rol). RECTOR/COORDINATOR: jornadas → grupos → riesgo.
  // DOCENTE: criterios de aviso. Otros roles: pantalla de espera.
  // Al terminar el flujo se re-verifica contra el backend — así un
  // coordinador con grupos aún pendientes de rector no se "desbloquea"
  // por arte de la UI; la verdad la da el servidor.
  const refetchOnboarding = () => {
    setOnboardingLoading(true);
    checkOnboardingRun.current?.();
  };
  if (!onboardingLoading) {
    const needsSchedule = scheduleOnboardingRequired;
    const needsGroups = groupsOnboardingRequired;
    const needsRisk = riskOnboardingRequired;
    const isRector = user?.role === ROLES.RECTOR;
    const isCoordinator = user?.role === ROLES.COORDINADOR;
    const isTeacher = user?.role === ROLES.DOCENTE;
    const canConfigure = isRector || isCoordinator;
    const anySchoolMissing = needsSchedule || needsGroups || needsRisk;

    if (anySchoolMissing && canConfigure) {
      return (
        <OnboardingFlow
          role={user?.role}
          missing={{ schedule: needsSchedule, groups: needsGroups, risk: needsRisk }}
          onAllDone={refetchOnboarding}
        />
      );
    }

    // Roles sin potestad de configuración (incluido docente): espera
    // institucional — primero debe completarse la config de la escuela
    if (anySchoolMissing && !canConfigure) {
      return <SystemInactiveScreen roleDisplay={roleDisplay} reason={needsSchedule ? 'schedule' : (needsGroups ? 'groups' : 'risk')} />;
    }

    // Onboarding propio del docente (solo cuando la escuela ya está lista)
    if (isTeacher && teacherOnboardingRequired) {
      return <OnboardingFlow role={user?.role} missing={{}} onAllDone={refetchOnboarding} />;
    }
  }

  return (
    <div className="min-h-screen bg-[var(--nx-canvas)]">
      {/* Sidebar / drawer — solo roles con superficies de administración */}
      {!bottomOnly && <Sidebar isOpen={isSidebarOpen} toggleSidebar={() => setSidebarOpen((v) => !v)} />}

      <div className={`flex flex-col min-w-0 ${bottomOnly ? '' : 'lg:pl-[220px]'}`}>
        {/* Topbar */}
        <header className="sticky top-0 z-30 flex items-center justify-between border-b border-[var(--nx-border)] bg-[var(--nx-surface-subtle)] px-4 lg:px-8" style={{ height: '72px' }}>
          <div className="flex items-center gap-3 min-w-0">
            {!bottomOnly && (
              <button
                onClick={() => setSidebarOpen((v) => !v)}
                className="lg:hidden p-2 rounded-control text-[var(--nx-text-muted)] hover:bg-[var(--nx-surface-subtle)] transition-colors"
                aria-label="Abrir menú"
              >
                <Menu size={20} />
              </button>
            )}
            <div className="min-w-0">
              <h1 className="text-body text-[var(--nx-text)] truncate" style={{ fontWeight: '650', fontSize: '0.9rem' }}>{greeting}, {firstName}</h1>
              <p className="text-caption text-[var(--nx-text-muted)] truncate">{roleDisplay} · {user?.school_name ?? 'NEXO'}</p>
            </div>
          </div>

          <div className="flex items-center gap-2 shrink-0">
            {/* Perfil */}
            <div ref={profileRef} className="relative">
              <button
                onClick={() => setProfileOpen((v) => !v)}
                className="flex items-center gap-2.5 pl-2 pr-1 py-1 rounded-control hover:bg-[var(--nx-surface-subtle)] transition-colors"
              >
                <div className="h-9 w-9 rounded-control bg-[var(--nx-surface-accent)] text-[var(--nx-accent)] border border-[var(--nx-border-accent)] text-sm font-bold flex items-center justify-center overflow-hidden">
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
                      {darkMode ? <Sun size={16} /> : <Moon size={16} />} {darkMode ? 'Modo claro' : 'Modo oscuro'}
                    </button>
                    <div className="border-t border-[var(--nx-border)]" />
                    <button
                      onClick={() => { setProfileOpen(false); logout(); }}
                      className="flex w-full items-center gap-3 px-4 py-3 text-body text-[var(--nx-danger)] hover:bg-[var(--nx-subtle-bg-danger)] transition-colors"
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
        <main className="flex-1 overflow-y-auto p-4 lg:p-8 pb-24">
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

        {/* Bottom action bar — mobile only */}
        <nav className={`fixed bottom-0 left-0 right-0 z-30 border-t border-[var(--nx-border)] bg-[var(--nx-surface)] ${bottomOnly ? '' : 'lg:hidden'}`} aria-label="Acciones principales">
          <div className="flex items-center justify-around px-2 py-2 max-w-content mx-auto">
            {primaryActions.map((item) => {
              const Icon = item.icon;
              // Las insignias llevan query (?cmd=…): el estado activo se
              // compara contra el pathname puro, no contra el deep-link.
              const itemPath = item.path.split('?')[0];
              const isActive = location.pathname === itemPath || (itemPath !== '/' && location.pathname.startsWith(itemPath));
              const showDot = itemPath === '/notificaciones' && notifCount > 0;
              return (
                <NavLink
                  key={item.path}
                  to={item.path}
                  end={item.path === '/'}
                  className={`flex flex-col items-center gap-1 px-3 py-1.5 rounded-control transition-colors duration-fast ${
                    isActive
                      ? 'text-[var(--nx-accent)]'
                      : 'text-[var(--nx-text-muted)] hover:text-[var(--nx-text)]'
                  }`}
                >
                  <div className="relative">
                    <Icon size={22} strokeWidth={1.75} />
                    {showDot && <span className="nx-blink absolute -top-1 -right-1 h-2 w-2 rounded-full bg-[var(--nx-accent)]" />}
                  </div>
                  <span className="text-caption font-medium" style={{ fontSize: '0.5625rem' }}>{item.title}</span>
                </NavLink>
              );
            })}
          </div>
        </nav>
      </div>

      {/* Nodus proactivo — avisa cuando llegan notificaciones nuevas.
          No aparece en /notificaciones (ya estás viéndolas). */}
      <NodusBotAnnouncer />
    </div>
  );
};

// Nodus proactivo — el canal de novedades. Cola en orden de prioridad,
// una burbuja a la vez (NodusGuide avanza por clic):
//   1. notificaciones nuevas ("Llegaron N" + Revisar / Marcar revisadas)
//   2. novedad del motor de insights (una vez por sesión por tipo)
//   3. guía contextual: primeras 3 visitas a cada sección
// «Mostrado» se marca cuando el mensaje se renderiza (onStepChange), no
// cuando entra a la cola — una notificación prioritaria no quema el
// presupuesto del hint ni marca el insight como visto sin haberlo visto.
const PAGE_HINTS = {
  '/': 'Este es tu tablero — las tarjetas cuentan la jornada en vivo y yo leo abajo lo que necesita tu decisión.',
  '/operacion': 'Cada tarjeta es un comando real — elige una y te guío en el formulario. Nada se envía sin confirmar.',
  '/consulta': 'Elige un módulo y filtra — todo lo que ves es verificable con fecha y origen.',
  '/casos': 'Cada ficha es un caso abierto con origen y responsable — el historial guarda también lo que responde el acudiente.',
  '/dispositivos': 'Desde aquí reasignas nodos sin tocar llaves — lo crítico siempre se hace en el punto físico.',
  '/config': 'Tu cuenta y lo institucional viven aquí — «Editar con Nodus» reabre la configuración para actualizarla.',
};
const HINT_LIMIT = 3;
const hintKey = (path) => `nx:hint:${path}`;

const readHintCount = (path) => {
  try { return parseInt(localStorage.getItem(hintKey(path)) || '0', 10); } catch { return HINT_LIMIT; }
};

const readShownInsights = () => {
  try { return new Set(JSON.parse(sessionStorage.getItem('nx:shown-insights') || '[]')); }
  catch { return new Set(); }
};

// Mensajes descartados en esta sesión — si el usuario quita la burbuja,
// el mismo texto no vuelve a aparecer al cambiar de sección.
const readDismissed = () => {
  try { return new Set(JSON.parse(sessionStorage.getItem('nx:bot-dismissed') || '[]')); }
  catch { return new Set(); }
};
const writeDismissed = (set) => {
  try { sessionStorage.setItem('nx:bot-dismissed', JSON.stringify([...set])); } catch { /* sin storage */ }
};

export const NodusBotAnnouncer = () => {
  const { notifCount, notifications, markAllRead } = useNotifications();
  const navigate = useNavigate();
  const location = useLocation();
  const [insights, setInsights] = useState(null);
  const [dismissedMsgs, setDismissedMsgs] = useState(readDismissed);
  const [hintCount, setHintCount] = useState(() => readHintCount(location.pathname));
  const [shownInsights, setShownInsights] = useState(readShownInsights);
  // El insight se "fija" al mostrarse: marcarlo como visto no debe sacarlo
  // de la cola en el mismo render (parpadearía y nadie lo lee). Solo se
  // suelta cuando el usuario lo descarta o cuando ya no viene del motor.
  const [pinnedInsight, setPinnedInsight] = useState(null);

  // Marcado de «mostrado» — estable (useCallback + refs): NodusGuide lo
  // llama desde un efecto dependiente de su identidad; una función nueva
  // por render dispararía el marcado en bucle. markedRef deduplica: un
  // mensaje se marca una vez por mount aunque se renderice dos veces.
  const scriptRef = useRef([]);
  const markedRef = useRef(new Set());
  const markShown = useCallback((mk) => {
    if (!mk || markedRef.current.has(mk)) return;
    markedRef.current.add(mk);
    if (mk.startsWith('hint:')) {
      const p = mk.slice(5);
      const next = readHintCount(p) + 1;
      try { localStorage.setItem(hintKey(p), String(next)); } catch { /* sin storage */ }
      setHintCount(next);
    } else if (mk.startsWith('insight:')) {
      const kind = mk.slice(8);
      setPinnedInsight(kind);
      setShownInsights((prev) => {
        const next = new Set(prev);
        next.add(kind);
        try { sessionStorage.setItem('nx:shown-insights', JSON.stringify([...next])); } catch { /* sin storage */ }
        return next;
      });
    }
  }, []);

  const dismissMsgs = (texts) => {
    // Descartar un hint/insight también cuenta como «visto»: si no, el
    // mismo contenido se re-anunciaría en la próxima sesión.
    (texts || []).forEach((t) => { if (t?.startsWith?.('hint:') || t?.startsWith?.('insight:')) markShown(t); });
    setDismissedMsgs((prev) => {
      const next = new Set(prev);
      (texts || []).forEach((t) => next.add(t));
      writeDismissed(next);
      return next;
    });
  };

  const path = location.pathname;
  const onNotifPage = path === '/notificaciones';
  // En /chat ya estás hablando con Nodus — un segundo bot flotante sobre el
  // lienzo sería redundante (misma razón por la que no aparece en /notificaciones).
  const onChatPage = path === '/chat';
  const quietPage = onNotifPage || onChatPage;

  // Motor de insights — una vez por sesión, para la lectura proactiva
  useEffect(() => {
    let alive = true;
    dashboardApi.getInsights()
      .then((res) => { if (alive) setInsights(res?.data?.insights || []); })
      .catch(() => { if (alive) setInsights([]); });
    return () => { alive = false; };
  }, []);

  // Guía contextual: contador por sección, persiste entre sesiones.
  // Solo se consume cuando el hint realmente se muestra (ver onStepShown).
  useEffect(() => { setHintCount(readHintCount(path)); }, [path]);
  const showHint = !quietPage && !!PAGE_HINTS[path] && hintCount < HINT_LIMIT;

  // Novedad del motor: el insight fijado si sigue vigente; si no, el
  // primero que aún no se ha mostrado esta sesión.
  const proactiveInsight = useMemo(() => {
    if (quietPage || !insights?.length) return null;
    if (pinnedInsight && !dismissedMsgs.has(`insight:${pinnedInsight}`)) {
      const cur = insights.find((i) => i.kind === pinnedInsight);
      if (cur) return cur;
    }
    return insights.find((ins) => !shownInsights.has(ins.kind)) ?? null;
  }, [quietPage, insights, shownInsights, pinnedInsight, dismissedMsgs]);

  const script = useMemo(() => {
    // Hasta que llega la primera respuesta de insights la cola está vacía:
    // sin el panorama completo, el hint/la novedad saldrían «a medias» — se
    // mostrarían y marcarían antes de que una novedad prioritaria pudiera
    // antecederlos. Cuando insights resuelve (éxito o error → []) se arma.
    if (insights === null) return [];
    const msgs = [];
    // Prioridad 1: notificaciones nuevas.
    // La llave es el id de la no-leída más reciente (la lista ya viene
    // ordenada DESC): si llegan nuevas, el evento cambia → el bot vuelve a
    // avisar aunque el anterior se haya descartado. Mismo evento → no repite.
    if (notifCount > 0 && !quietPage) {
      const unread = (notifications || []).filter((n) => !n.read);
      const latestUnread = unread[0]?.id ?? unread[0]?.notification_id ?? notifCount;
      const key = `notif:${latestUnread}`;
      // Una sola no leída que mapea a una operación → «Revisar» abre el
      // formulario con el contexto pre-llenado; si no, la bandeja.
      let dest = '/notificaciones';
      if (unread.length === 1) {
        try {
          const meta = JSON.parse(unread[0].metadata_json || 'null');
          const cmd = meta?.action ? NOTIF_ACTION_TO_CMD[meta.action] : null;
          if (cmd) {
            const p = new URLSearchParams({ cmd });
            if (meta.student_id) p.set('student', meta.student_id);
            if (meta.group_name) p.set('group', meta.group_name);
            const reason = meta.reason || meta.motivo || meta.message || '';
            if (reason) p.set('reason', String(reason).slice(0, 300));
            if (meta.dependency) p.set('dependency', meta.dependency);
            dest = `/operacion?${p.toString()}`;
          }
        } catch { /* metadata no JSON → bandeja */ }
      }
      msgs.push({
        text: `Llegaron <b>${notifCount} notificaci${notifCount === 1 ? 'ón' : 'ones'}</b> nuevas — revisa las que necesitan decisión.`,
        dismissKey: key,
        chips: [
          { label: 'Revisar', action: () => navigate(dest) },
          // Ignorar = marcar leídas en el servidor: si no, siguen contando
          // como no leídas y el aviso reaparece en la próxima sesión.
          { label: 'Marcar revisadas', action: () => { markAllRead(); dismissMsgs([key]); } },
        ],
      });
    }
    // Prioridad 2: novedad del motor de insights
    if (proactiveInsight) {
      msgs.push({
        text: `<b>${proactiveInsight.title}</b> — ${proactiveInsight.body}`,
        dismissKey: `insight:${proactiveInsight.kind}`,
        markKey: `insight:${proactiveInsight.kind}`,
        chips: proactiveInsight.action ? [{
          label: proactiveInsight.action.label,
          action: () => {
            if (proactiveInsight.action.target === 'consulta') {
              setPendingPrompt(insightPrompt(proactiveInsight));
              navigate('/chat');
            } else {
              navigate(`/${proactiveInsight.action.target}`);
            }
          },
        }] : undefined,
      });
    }
    // Prioridad 3: guía contextual (primeras N visitas a la sección)
    if (showHint) {
      msgs.push({ text: PAGE_HINTS[path], dismissKey: `hint:${path}`, markKey: `hint:${path}` });
    }
    // Un evento descartado no reaparece en esta sesión
    return msgs.filter((m) => !dismissedMsgs.has(m.dismissKey || m.text));
  }, [notifCount, notifications, quietPage, insights, proactiveInsight, showHint, path, navigate, dismissedMsgs, markAllRead]);

  scriptRef.current = script;
  const onStepShown = useCallback((idx) => {
    markShown(scriptRef.current[idx]?.markKey);
  }, [markShown]);

  return <NodusGuide script={script} active={script.length > 0} onDismiss={dismissMsgs} onStepChange={onStepShown} />;
};

export default Layout;
