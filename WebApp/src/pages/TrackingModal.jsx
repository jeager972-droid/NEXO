/**
 * TrackingModal / NEXO Institucional — B-13 Drawer unificado
 * Modal de seguimiento estudiantil: inicio, notas, cierre y riesgo.
 * Usa Drawer de Overlay.jsx, ConfirmDialog, humanizeError.
 */
import { useState, useEffect } from 'react';
import { Send, UserCheck, CalendarDays } from 'lucide-react';
import { AnimatePresence } from 'framer-motion';
import { trackingApi } from '../api/tracking';
import { Surface } from '../components/ui/Surface';
import { Button } from '../components/ui/Button';
import { Input, Textarea } from '../components/ui/Input';
import { EmptyState } from '../components/ui/EmptyState';
import { SkeletonRows } from '../components/ui/Skeleton';
import { Drawer, ConfirmDialog } from '../components/ui/Overlay';
import { RiskBadge } from '../components/patterns/RiskBadge';
import { humanizeError } from '../utils/messages';

const scoreToLevel = (s) => s >= 70 ? 'critico' : s >= 40 ? 'medio' : 'bajo';

export const TrackingModal = ({ trackingId, studentId, studentName, metadata, onClose, onRefresh }) => {
  const [details, setDetails] = useState(null);
  const [notes, setNotes] = useState([]);
  const [loading, setLoading] = useState(true);
  const [activeTrackingId, setActiveTrackingId] = useState(trackingId);
  const [noteText, setNoteText] = useState('');
  const [resolveReason, setResolveReason] = useState('');
  const [isSubmitting, setIsSubmitting] = useState(false);
  const [showResolve, setShowResolve] = useState(false);
  const [showConfirmClose, setShowConfirmClose] = useState(false);
  const [submitStatus, setSubmitStatus] = useState(null);

  useEffect(() => {
    const fetchDetails = async () => {
      setLoading(true);
      try {
        if (!activeTrackingId && studentId) {
          setDetails(null);
          setNotes([]);
          setLoading(false);
          return;
        }
        if (activeTrackingId) {
          const res = await trackingApi.getDetails(activeTrackingId);
          if (res.status === 'ok') {
            setDetails(res.tracking);
            setNotes(res.notes || []);
          }
        }
      } catch (e) {
        console.error(e);
      } finally {
        setLoading(false);
      }
    };
    fetchDetails();
  }, [activeTrackingId, studentId]);

  const handleStartTracking = async () => {
    setIsSubmitting(true);
    try {
      let reason = '';
      if (metadata?.risk_score) {
        reason = `Análisis de riesgo - Score: ${metadata.risk_score}/100`;
      }
      const res = await trackingApi.startTracking(studentId, reason);
      if (res.status === 'ok') {
        setActiveTrackingId(res.tracking_id);
        if (onRefresh) onRefresh();
      } else {
        setSubmitStatus({ type: 'error', message: res.message || 'No se pudo iniciar el seguimiento' });
      }
    } catch (e) {
      setSubmitStatus({ type: 'error', message: humanizeError(e, 'No se pudo iniciar el seguimiento') });
    } finally {
      setIsSubmitting(false);
    }
  };

  const handleAddNote = async () => {
    if (!noteText.trim()) return;
    setIsSubmitting(true);
    try {
      await trackingApi.addNote(activeTrackingId, noteText);
      setNoteText('');
      const res = await trackingApi.getDetails(activeTrackingId);
      if (res.status === 'ok') {
        setDetails(res.tracking);
        setNotes(res.notes || []);
      }
    } catch (e) {
      console.error(e);
    } finally {
      setIsSubmitting(false);
    }
  };

  const confirmResolve = async () => {
    if (!resolveReason.trim()) {
      setSubmitStatus({ type: 'error', message: 'Escribe el motivo de cierre.' });
      return;
    }
    setIsSubmitting(true);
    try {
      const res = await trackingApi.addNote(activeTrackingId, `Motivo de cierre: ${resolveReason}`, 'resuelto');
      if (res?.status === 'ok') {
        if (onRefresh) onRefresh();
        onClose();
      } else {
        setSubmitStatus({ type: 'error', message: res.message || 'No se pudo cerrar' });
      }
    } catch (e) {
      setSubmitStatus({ type: 'error', message: humanizeError(e, 'No se pudo cerrar') });
    } finally {
      setIsSubmitting(false);
    }
  };

  return (
    <>
      <Drawer
        title={studentName || 'Seguimiento'}
        context={metadata?.risk_score ? `Riesgo ${metadata.risk_score}/100` : undefined}
        onClose={onClose}
        size="md"
      >
        <div className="p-6 space-y-6">
          {metadata?.risk_score && (
            <div className="flex items-center gap-3">
              <RiskBadge level={scoreToLevel(metadata.risk_score)} />
            </div>
          )}

          {!activeTrackingId ? (
            <EmptyState
              icon={<UserCheck size={32} className="text-[var(--nx-accent)]" />}
              title="Iniciar seguimiento"
              description="Este estudiante aún no tiene un caso activo. Inicia uno para registrar intervenciones."
              action={<Button onClick={handleStartTracking} loading={isSubmitting}>Iniciar caso</Button>}
            />
          ) : loading ? (
            <SkeletonRows count={3} />
          ) : (
            <>
              <Surface className="p-4 space-y-2">
                <p className="text-label text-[var(--nx-text-muted)] uppercase">Estado</p>
                <p className="text-body text-[var(--nx-text)]">{details?.status === 'active' ? 'Activo' : details?.status || 'Activo'}</p>
                {details?.created_at && <p className="text-caption text-[var(--nx-text-muted)] flex items-center gap-1"><CalendarDays size={12} /> Iniciado {new Date(details.created_at).toLocaleDateString('es-CO')}</p>}
              </Surface>

              <div className="space-y-3">
                <p className="text-label text-[var(--nx-text)]">Notas</p>
                {notes.length === 0 ? (
                  <p className="text-body-sm text-[var(--nx-text-muted)]">Aún no hay notas.</p>
                ) : (
                  <div className="space-y-2">
                    {notes.map((n, i) => (
                      <Surface key={i} className="p-3">
                        <p className="text-body-sm text-[var(--nx-text)]">{n.note}</p>
                        <p className="text-caption text-[var(--nx-text-muted)] mt-1">{n.created_at && new Date(n.created_at).toLocaleString('es-CO')}</p>
                      </Surface>
                    ))}
                  </div>
                )}
                <Textarea
                  label="Agregar nota"
                  value={noteText}
                  onChange={(e) => setNoteText(e.target.value)}
                  placeholder="Escribe una observación..."
                  rows={2}
                />
                <div className="flex justify-end">
                  <Button onClick={handleAddNote} loading={isSubmitting} leftIcon={<Send size={16} />}>Guardar nota</Button>
                </div>
              </div>

              {!showResolve ? (
                <Button variant="secondary" className="w-full" onClick={() => setShowResolve(true)}>Cerrar seguimiento</Button>
              ) : (
                <Surface className="p-4 space-y-3">
                  <Input
                    label="Motivo de cierre"
                    value={resolveReason}
                    onChange={(e) => setResolveReason(e.target.value)}
                    placeholder="Motivo por el que se cierra el caso"
                  />
                  {submitStatus && <p className="text-caption text-[var(--nx-danger)]" role="alert">{submitStatus.message}</p>}
                  <div className="flex gap-3">
                    <Button variant="quiet" className="flex-1" onClick={() => setShowResolve(false)}>Cancelar</Button>
                    <Button variant="danger" className="flex-1" onClick={() => setShowConfirmClose(true)}>Confirmar cierre</Button>
                  </div>
                </Surface>
              )}
            </>
          )}
        </div>
      </Drawer>

      <AnimatePresence>
        {showConfirmClose && (
          <ConfirmDialog
            title="Cerrar caso de seguimiento"
            description={`¿Confirmas el cierre del caso de ${studentName}? Esta acción es irreversible.`}
            confirmLabel="Sí, cerrar caso"
            cancelLabel="No, mantener"
            destructive
            onConfirm={() => { setShowConfirmClose(false); confirmResolve(); }}
            onClose={() => setShowConfirmClose(false)}
          />
        )}
      </AnimatePresence>
    </>
  );
};
