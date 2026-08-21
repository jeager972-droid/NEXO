/**
 * SystemInactiveScreen — Pantalla de bloqueo para roles que no pueden
 * completar el onboarding pendiente (horarios o grupos).
 * El rector/coordinador debe completar la configuración antes de que
 * el sistema funcione para los demás roles.
 */
import { GraduationCap, Clock, CalendarClock, Shield } from 'lucide-react';
import { motion } from 'framer-motion';
import LogoNexo from '../LogoNexo';

const EASE = [0.22, 1, 0.36, 1];

export const SystemInactiveScreen = ({ roleDisplay, reason = 'groups' }) => {
  const isSchedule = reason === 'schedule';
  const isRisk = reason === 'risk';
  const Icon = isSchedule ? CalendarClock : (isRisk ? Shield : GraduationCap);
  const title = 'El sistema está en configuración';
  const description = isSchedule
    ? 'El rector o coordinador de tu institución está configurando los horarios institucionales. Una vez complete la configuración, podrás acceder a todas las funciones de NEXO.'
    : isRisk
    ? 'El rector de tu institución está configurando el Motor de Análisis de Riesgo Pedagógico. Esta configuración es obligatoria para que el sistema pueda operar. Una vez complete la configuración, podrás acceder a todas las funciones de NEXO.'
    : 'El rector de tu institución está configurando los grupos académicos para este año electivo. Una vez complete la configuración, podrás acceder a todas las funciones de NEXO.';

  return (
    <div className="flex min-h-screen flex-col items-center justify-center bg-[var(--nx-canvas)] p-6">
      <motion.div
        initial={{ opacity: 0, y: 12 }}
        animate={{ opacity: 1, y: 0 }}
        transition={{ duration: 0.3, ease: EASE }}
        className="w-full max-w-md text-center"
      >
        <div className="mb-6 flex justify-center">
          <LogoNexo className="h-16" useImage />
        </div>
        <div className="mb-5 grid h-16 w-16 mx-auto place-items-center rounded-surface bg-[var(--nx-surface-subtle)] text-[var(--nx-text-muted)]">
          <Icon size={28} strokeWidth={1.75} />
        </div>
        <h1 className="text-h2 text-[var(--nx-text)] mb-3">{title}</h1>
        <p className="text-body text-[var(--nx-text-muted)] leading-relaxed mb-6">
          {description}
        </p>
        <div className="flex items-center justify-center gap-2 text-caption text-[var(--nx-text-muted)]">
          <Clock size={14} />
          <span>Intenta nuevamente más tarde</span>
        </div>
        {roleDisplay && (
          <p className="mt-6 text-caption text-[var(--nx-text-muted)]">
            Sesión iniciada como <span className="font-medium text-[var(--nx-text)]">{roleDisplay}</span>
          </p>
        )}
      </motion.div>
    </div>
  );
};

export default SystemInactiveScreen;
