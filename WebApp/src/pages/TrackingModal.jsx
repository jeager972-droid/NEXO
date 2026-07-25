/**
 * TrackingModal / NEXO Institucional
 * Modal de seguimiento estudiantil: inicio, notas, cierre y riesgo.
 */
import { useState, useEffect } from 'react';
import { X, Send, Activity, UserCheck, CalendarDays } from 'lucide-react';
import { motion, AnimatePresence } from 'framer-motion';
import { trackingApi } from '../api/tracking';
import { Surface } from '../components/ui/Surface';
import { Button } from '../components/ui/Button';
import { Input, Textarea } from '../components/ui/Input';
import { Badge } from '../components/ui/Badge';
import { EmptyState } from '../components/ui/EmptyState';
import { Skeleton } from '../components/ui/Skeleton';

export const TrackingModal = ({ trackingId, studentId, studentName, metadata, onClose, onRefresh }) => {
  const [details, setDetails] = useState(null);
  const [notes, setNotes] = useState([]);
  const [loading, setLoading] = useState(true);
  const [activeTrackingId, setActiveTrackingId] = useState(trackingId);
  const [noteText, setNoteText] = useState('');
  const [resolveReason, setResolveReason] = useState('');
  const [isSubmitting, setIsSubmitting] = useState(false);
  const [showResolve, setShowResolve] = useState(false);
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
      setSubmitStatus({ type: 'error', message: e.message || 'Error de red' });
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
      setSubmitStatus({ type: 'error', message: e.message || 'Error de red' });
    } finally {
      setIsSubmitting(false);
    }
  };

  const riskScheme = metadata?.risk_score >= 70 ? 'danger' : metadata?.risk_score >= 40 ? 'warning' : 'success';

  return (
    <div className="fixed inset-0 z-[60] flex items-center justify-center p-4 bg-[color-mix(in_oklch,var(--nx-text)_45%,transparent)]">
      <motion.div
        initial={{ opacity: 0, scale: 0.97, y: 8 }}
        animate={{ opacity: 1, scale: 1, y: 0 }}
        exit={{ opacity: 0, scale: 0.97, y: 8 }}
        transition={{ duration: 0.2, ease: [0.22, 1, 0.36, 1] }}
        className="w-full max-w-lg max-h-[90vh] overflow-y-auto rounded-panel border border-[var(--nx-border)] bg-[var(--nx-surface)] shadow-high"
      >
        <div className="flex items-center justify-between border-b border-[var(--nx-border)] px-6 py-4">
          <div className="flex items-center gap-3">
            <div className="flex h-10 w-10 items-center justify-center rounded-control bg-[color-mix(in_oklch,var(--nx-accent)_10%,transparent)]">
              <Activity size={18} className="text-[var(--nx-accent)]" />
            </div>
            <div>
              <p className="text-h3 text-[var(--nx-text)]">{studentName || 'Seguimiento'}</p>
              {metadata?.risk_score && <Badge scheme={riskScheme}>Riesgo {metadata.risk_score}/100</Badge>}
            </div>
          </div>
          <button onClick={onClose} className="p-2 rounded-control hover:bg-[var(--nx-surface-subtle)] text-[var(--nx-text-muted)] hover:text-[var(--nx-text)]">
            <X size={20} />
          </button>
        </div>

        <div className="p-6 space-y-6">
          {!activeTrackingId ? (
            <EmptyState
              icon={<UserCheck size={32} className="text-[var(--nx-accent)]" />}
              title="Iniciar seguimiento"
              description="Este estudiante aún no tiene un caso activo. Inicia uno para registrar intervenciones."
              action={<Button onClick={handleStartTracking} loading={isSubmitting}>Iniciar caso</Button>}
            />
          ) : loading ? (
            <div className="space-y-3">
              <Skeleton className="h-20 w-full" />
              <Skeleton className="h-20 w-full" />
            </div>
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
                  {submitStatus && <p className="text-caption text-[var(--nx-danger)]">{submitStatus.message}</p>}
                  <div className="flex gap-3">
                    <Button variant="quiet" className="flex-1" onClick={() => setShowResolve(false)}>Cancelar</Button>
                    <Button variant="danger" className="flex-1" loading={isSubmitting} onClick={confirmResolve}>Confirmar cierre</Button>
                  </div>
                </Surface>
              )}
            </>
          )}
        </div>
      </motion.div>
    </div>
  );
};
