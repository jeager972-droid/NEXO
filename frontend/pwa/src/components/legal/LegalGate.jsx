/**
 * LegalGate / NEXO Institucional — gate legal post-login (SCR-AUTH-03).
 *
 * Tras autenticarse, antes de ver cualquier superficie del sistema (incluido
 * el bot de Nodus contextual), el usuario resuelve dos pasos:
 *   1. Términos y Condiciones: aceptación OBLIGATORIA, persistida en el
 *      servidor (users.terms_version) con respaldo local por dispositivo.
 *      Nodus aparece en este paso y explica por qué importan.
 *   2. Aviso de cookies: tarjeta compacta — aceptar o abrir «Leer más» para
 *      el detalle por categoría.
 *
 * Ambas pantallas tienen «Leer más» para desplegar el texto legal completo.
 */
import { useEffect, useMemo, useRef, useState } from 'react';
import { AnimatePresence, motion } from 'framer-motion';
import { Cookie, FileText, ChevronDown, Check, Lock, LogOut } from 'lucide-react';
import { useAuth } from '../../hooks/useAuth';
import { authApi } from '../../api/auth';
import { Button } from '../ui/Button';
import { NexoAvatar } from '../patterns/NexoChat';
import { NodusGuide } from '../patterns/NodusGuide';
import LogoNexo from '../LogoNexo';
import {
  TERMS_VERSION, TERMS_NOTICE, TERMS_NODUS_SCRIPT,
  COOKIE_NOTICE, COOKIE_CATEGORIES,
  COOKIE_CONSENT_KEY, COOKIE_CONSENT_VERSION, TERMS_FALLBACK_KEY,
} from '../../config/legal';

const EASE = [0.22, 1, 0.36, 1];

const readCookieConsent = () => {
  try {
    const parsed = JSON.parse(localStorage.getItem(COOKIE_CONSENT_KEY));
    if (parsed?.version === COOKIE_CONSENT_VERSION && parsed?.categories) return parsed;
  } catch { /* sin storage */ }
  return null;
};

const readTermsFallback = (userId) => {
  try {
    const parsed = JSON.parse(localStorage.getItem(TERMS_FALLBACK_KEY));
    if (parsed?.user_id === userId && parsed?.version === TERMS_VERSION) return parsed;
  } catch { /* sin storage */ }
  return null;
};

const writeTermsFallback = (userId) => {
  try {
    localStorage.setItem(TERMS_FALLBACK_KEY, JSON.stringify({
      user_id: userId,
      version: TERMS_VERSION,
      at: new Date().toISOString(),
    }));
  } catch { /* sin storage */ }
};

/** Cuerpo legal completo: secciones con párrafos y viñetas. */
const LegalSections = ({ sections }) => (
  <div className="space-y-5">
    {sections.map((s) => (
      <section key={s.title}>
        <h4 className="text-body-sm font-semibold text-[var(--nx-text)]">{s.title}</h4>
        {(s.paragraphs || []).map((p, i) => (
          <p key={i} className="mt-2 text-body-sm leading-relaxed text-[var(--nx-text-muted)]">{p}</p>
        ))}
        {s.bullets && (
          <ul className="mt-2 space-y-1.5">
            {s.bullets.map((b, i) => (
              <li key={i} className="flex gap-2 text-body-sm leading-relaxed text-[var(--nx-text-muted)]">
                <span className="mt-[0.55em] h-1 w-1 shrink-0 rounded-full bg-[var(--nx-accent)]" aria-hidden />
                <span>{b}</span>
              </li>
            ))}
          </ul>
        )}
        {(s.after || []).map((p, i) => (
          <p key={`a${i}`} className="mt-2 text-body-sm leading-relaxed text-[var(--nx-text-muted)]">{p}</p>
        ))}
      </section>
    ))}
  </div>
);

/** Botón «Leer más / Ver menos» que despliega el texto legal scrollable. */
const Expandable = ({ notice, categories }) => {
  const [open, setOpen] = useState(false);
  return (
    <div>
      <button
        type="button"
        onClick={() => setOpen((v) => !v)}
        aria-expanded={open}
        className="inline-flex items-center gap-1.5 text-body-sm font-semibold text-[var(--nx-accent)] hover:underline"
      >
        {open ? 'Ver menos' : 'Leer más'}
        <ChevronDown size={15} className={`transition-transform duration-200 ${open ? 'rotate-180' : ''}`} aria-hidden />
      </button>
      <AnimatePresence initial={false}>
        {open && (
          <motion.div
            initial={{ height: 0, opacity: 0 }}
            animate={{ height: 'auto', opacity: 1 }}
            exit={{ height: 0, opacity: 0 }}
            transition={{ duration: 0.25, ease: EASE }}
            className="overflow-hidden"
          >
            <div className="mt-3 max-h-[38vh] overflow-y-auto overscroll-contain rounded-surface border border-[var(--nx-border)] bg-[var(--nx-surface-subtle)] p-4 pr-3">
              {categories && (
                <ul className="mb-4 space-y-2">
                  {categories.map((c) => (
                    <li key={c.id} className="flex items-start gap-3 rounded-control border border-[var(--nx-border)] bg-[var(--nx-surface)] px-3.5 py-2.5">
                      <span className={`mt-0.5 grid h-5 w-5 shrink-0 place-items-center rounded-full ${c.required ? 'bg-[var(--nx-surface-accent)] text-[var(--nx-accent)]' : 'bg-[var(--nx-surface-subtle)] text-[var(--nx-text-muted)] border border-[var(--nx-border)]'}`}>
                        {c.required ? <Lock size={11} aria-hidden /> : <Check size={11} aria-hidden />}
                      </span>
                      <span className="min-w-0">
                        <span className="block text-body-sm font-semibold text-[var(--nx-text)]">{c.label}</span>
                        <span className="block text-caption text-[var(--nx-text-muted)]">{c.description}</span>
                      </span>
                    </li>
                  ))}
                </ul>
              )}
              {notice.preamble && (
                <p className="mb-4 text-body-sm leading-relaxed text-[var(--nx-text)]">{notice.preamble}</p>
              )}
              <LegalSections sections={notice.sections} />
            </div>
          </motion.div>
        )}
      </AnimatePresence>
    </div>
  );
};

/** Cabecera de cada paso: icono en superficie accent + título + resumen. */
const StepHead = ({ icon: Icon, eyebrow, title, children }) => (
  <div className="flex items-start gap-3.5">
    <div className="grid h-11 w-11 shrink-0 place-items-center rounded-surface bg-[var(--nx-surface-accent)] text-[var(--nx-accent)] border border-[var(--nx-border-accent)]">
      <Icon size={21} strokeWidth={1.75} aria-hidden />
    </div>
    <div className="min-w-0">
      {eyebrow && <p className="text-eyebrow uppercase text-[var(--nx-text-muted)]">{eyebrow}</p>}
      <h2 className="text-h3 text-[var(--nx-text)]">{title}</h2>
      {children}
    </div>
  </div>
);

const LegalGate = ({ user, children }) => {
  const { logout, setUser } = useAuth();
  const [cookieConsent, setCookieConsent] = useState(readCookieConsent);
  const [saving, setSaving] = useState(false);

  const serverAccepted = user?.terms_accepted === true || user?.terms_version === TERMS_VERSION;
  const fallbackAccepted = useMemo(() => !!readTermsFallback(user?.id), [user?.id]);
  const [termsAccepted, setTermsAccepted] = useState(serverAccepted || fallbackAccepted);
  // Espejo en ref: el listener del evento no puede depender del estado sin
  // re-suscribirse en cada render.
  const acceptedRef = useRef(termsAccepted);
  const syncAttempts = useRef(0);
  useEffect(() => { acceptedRef.current = termsAccepted; }, [termsAccepted]);

  // Si el respaldo local ya aceptó pero el servidor no lo sabe (despliegue
  // previo al endpoint), sincroniza la aceptación en segundo plano.
  useEffect(() => {
    if (!serverAccepted && fallbackAccepted && user?.id) {
      authApi.acceptTerms(TERMS_VERSION).catch(() => { /* mejor esfuerzo */ });
    }
  }, [serverAccepted, fallbackAccepted, user?.id]);

  // El backend responde 428 terms_required si la versión vigente rota o el
  // consentimiento falta: el gate vuelve a aparecer sin esperar relogin.
  // Guarda anti-loop (móvil): si ya aceptamos en esta sesión o hay respaldo
  // local, un 428 es desincronización del servidor — se reintenta el registro
  // en silencio (hasta 3 veces) en lugar de reabrir el gate en bucle.
  useEffect(() => {
    const onTermsRequired = () => {
      if ((acceptedRef.current || readTermsFallback(user?.id)) && syncAttempts.current < 3) {
        syncAttempts.current += 1;
        authApi.acceptTerms(TERMS_VERSION).catch(() => { /* mejor esfuerzo */ });
        return;
      }
      setTermsAccepted(false);
      if (user) setUser?.({ ...user, terms_accepted: false });
    };
    window.addEventListener('nexo:terms-required', onTermsRequired);
    return () => window.removeEventListener('nexo:terms-required', onTermsRequired);
  }, [user, setUser]);

  // Términos primero (obligatorio), cookies después como tarjeta compacta.
  const step = !termsAccepted ? 'terms' : (!cookieConsent ? 'cookies' : null);
  if (!step) return children;

  const saveCookieConsent = (categories) => {
    const data = { version: COOKIE_CONSENT_VERSION, timestamp: new Date().toISOString(), categories };
    try { localStorage.setItem(COOKIE_CONSENT_KEY, JSON.stringify(data)); } catch { /* sin storage */ }
    window.dispatchEvent(new CustomEvent('nexo:cookie-consent', { detail: data }));
    setCookieConsent(data);
  };

  const acceptTerms = async () => {
    setSaving(true);
    try {
      await authApi.acceptTerms(TERMS_VERSION);
    } catch {
      // El backend puede no tener la columna/endpoint desplegado aún — el
      // respaldo local evita bloquear al usuario y se reintenta al próximo login.
    }
    writeTermsFallback(user.id);
    syncAttempts.current = 0;
    setUser?.({ ...user, terms_version: TERMS_VERSION, terms_accepted: true });
    setTermsAccepted(true);
    setSaving(false);
  };

  return (
    <div className="fixed inset-0 z-[60] flex min-h-screen flex-col items-center justify-center overflow-y-auto bg-[var(--nx-canvas)] p-4">
      <motion.div
        key={step}
        initial={{ opacity: 0, y: 8 }}
        animate={{ opacity: 1, y: 0 }}
        transition={{ duration: 0.25, ease: EASE }}
        className="w-full max-w-[560px] space-y-6 py-6"
      >
        <div className="flex flex-col items-center gap-1">
          <LogoNexo className="h-16" useImage />
          <p className="text-caption text-[var(--nx-text-muted)]">
            Paso {step === 'terms' ? '1' : '2'} de 2 — antes de continuar
          </p>
        </div>

        {step === 'cookies' && (
          <div className="mx-auto w-full max-w-[420px] rounded-panel border border-[var(--nx-border)] bg-[var(--nx-surface)] p-5 shadow-medium">
            <StepHead icon={Cookie} title={COOKIE_NOTICE.title}>
              <p className="mt-2 text-body-sm leading-relaxed text-[var(--nx-text-muted)]">{COOKIE_NOTICE.summary}</p>
            </StepHead>

            <div className="mt-4">
              <Expandable notice={COOKIE_NOTICE} categories={Object.values(COOKIE_CATEGORIES)} />
            </div>

            <div className="mt-5 flex flex-col gap-2">
              <Button size="lg" block onClick={() => saveCookieConsent({ necessary: true, preferences: true, analytics: true, marketing: false })}>
                Aceptar todas
              </Button>
              <button
                type="button"
                onClick={() => saveCookieConsent({ necessary: true, preferences: false, analytics: false, marketing: false })}
                className="text-body-sm font-medium text-[var(--nx-text-muted)] hover:text-[var(--nx-text)]"
              >
                Solo necesarias
              </button>
            </div>
          </div>
        )}

        {step === 'terms' && (
          <div className="rounded-panel border border-[var(--nx-border)] bg-[var(--nx-surface)] p-6 shadow-medium lg:p-8">
            {/* Nodus explica — burbuja inline (además del bot flotante) */}
            <div className="flex items-start gap-3">
              <NexoAvatar size={44} />
              <div className="rounded-surface rounded-tl-xs border border-[var(--nx-border-accent)] bg-[var(--nx-subtle-bg-accent)] px-4 py-3">
                <p className="text-caption font-semibold tracking-wide text-[var(--nx-accent)]">NODUS</p>
                <p className="mt-0.5 text-body-sm leading-relaxed text-[var(--nx-text)]">
                  Este acuerdo protege tu trabajo, a tu institución y los datos de los estudiantes. Solo toma un momento.
                </p>
              </div>
            </div>

            <div className="mt-5">
              <StepHead icon={FileText} eyebrow={`Versión ${TERMS_VERSION} · vigente`} title={TERMS_NOTICE.title}>
                <ul className="mt-3 space-y-1.5">
                  {TERMS_NOTICE.summaryBullets.map((b, i) => (
                    <li key={i} className="flex gap-2 text-body-sm leading-relaxed text-[var(--nx-text-muted)]">
                      <Check size={14} className="mt-1 shrink-0 text-[var(--nx-accent)]" aria-hidden />
                      <span>{b}</span>
                    </li>
                  ))}
                </ul>
              </StepHead>
            </div>

            <div className="mt-4">
              <Expandable notice={TERMS_NOTICE} />
            </div>

            <div className="mt-6 flex flex-col gap-2.5">
              <Button size="lg" block onClick={acceptTerms} loading={saving} loadingLabel="Registrando…" data-nx-autofocus>
                Aceptar y continuar
              </Button>
              <button
                type="button"
                onClick={logout}
                className="inline-flex items-center justify-center gap-1.5 text-body-sm font-medium text-[var(--nx-text-muted)] hover:text-[var(--nx-danger)]"
              >
                <LogOut size={14} aria-hidden /> No acepto — cerrar sesión
              </button>
            </div>
            <p className="mt-3 text-center text-caption text-[var(--nx-text-muted)]">
              La aceptación es obligatoria y queda registrada con fecha en tu cuenta.
            </p>
          </div>
        )}
      </motion.div>

      {/* Nodus flotante: aparece en el paso de términos y explica brevemente */}
      {step === 'terms' && <NodusGuide script={TERMS_NODUS_SCRIPT} active />}
    </div>
  );
};

export default LegalGate;
