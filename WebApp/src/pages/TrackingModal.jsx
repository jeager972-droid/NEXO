import { useState, useEffect } from 'react';
import { motion, AnimatePresence } from 'framer-motion';
import { X, Send, Activity, UserCheck, Search, Info } from 'lucide-react';
import { trackingApi } from '../api/tracking';

export const TrackingModal = ({ trackingId, studentId, studentName, metadata, onClose, onRefresh }) => {
  const [details, setDetails] = useState(null);
  const [notes, setNotes] = useState([]);
  const [loading, setLoading] = useState(true);
  const [noteText, setNoteText] = useState('');
  const [isSubmitting, setIsSubmitting] = useState(false);
  const [activeTrackingId, setActiveTrackingId] = useState(trackingId);
  const [resolveReason, setResolveReason] = useState('');
  const [showResolvePrompt, setShowResolvePrompt] = useState(false);

  useEffect(() => {
    const fetchDetails = async () => {
      setLoading(true);
      try {
        if (!activeTrackingId && studentId) {
          // If we don't have a tracking ID yet but we want to start one, wait.
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
      } catch (err) {
        console.error(err);
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
      if (metadata && metadata.risk_score) {
        reason = `Análisis de Riesgo - Score: ${metadata.risk_score}/100, Inasistencias: ${metadata.absence_count || 0}, Llegadas tarde: ${metadata.late_count || 0}`;
      }
      const res = await trackingApi.startTracking(studentId, reason);
      if (res.status === 'ok') {
        setActiveTrackingId(res.tracking_id);
        if (onRefresh) onRefresh();
      }
    } catch (err) {
      console.error(err);
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
      if (onRefresh) onRefresh();
    } catch (err) {
      console.error(err);
    } finally {
      setIsSubmitting(false);
    }
  };

  const handleResolve = async () => {
    setShowResolvePrompt(true);
  };

  const confirmResolve = async () => {
    if (!resolveReason.trim()) {
      alert('Por favor escribe el motivo de cierre del seguimiento.');
      return;
    }
    setIsSubmitting(true);
    try {
      const res = await trackingApi.addNote(activeTrackingId, `Motivo de cierre: ${resolveReason}`, 'resuelto');
      if (res?.status === 'ok') {
        if (onRefresh) onRefresh();
        setShowResolvePrompt(false);
        setResolveReason('');
        onClose();
      } else {
        alert('No se pudo resolver el seguimiento: ' + (res?.message || 'Error del servidor'));
      }
    } catch (err) {
      console.error('Error resolviendo seguimiento:', err);
      alert('Error al resolver el seguimiento: ' + (err.message || 'Error de red'));
    } finally {
      setIsSubmitting(false);
    }
  };

  return (
    <div className="fixed inset-0 z-[60] flex items-center justify-center bg-black/60 backdrop-blur-sm p-4">
      <motion.div 
        initial={{ opacity: 0, scale: 0.95, y: 10 }}
        animate={{ opacity: 1, scale: 1, y: 0 }}
        exit={{ opacity: 0, scale: 0.95, y: 10 }}
        transition={{ duration: 0.2 }}
        className="bg-white dark:bg-slate-900 w-full max-w-xl rounded-lg shadow-2xl flex flex-col overflow-hidden"
        style={{ border: '1px solid #E2E8F0', maxHeight: '90vh' }}
      >
        <div className="flex items-center justify-between p-4 border-b border-slate-100 dark:border-slate-800">
          <div>
            <p className="text-sm font-black uppercase text-slate-800 dark:text-white" style={{ letterSpacing: '0.05em' }}>
              Perfil de Seguimiento
            </p>
            <p className="text-[10px] font-bold text-slate-500 uppercase tracking-widest mt-1">
              {studentName}
            </p>
          </div>
          <button onClick={onClose} className="p-2 text-slate-400 hover:text-slate-700 dark:hover:text-white transition-colors">
            <X size={18} strokeWidth={2} />
          </button>
        </div>

        <div className="flex-1 overflow-y-auto p-5 space-y-6">
          {metadata && metadata.risk_score && (
            <div className="bg-amber-50 dark:bg-amber-900/10 p-4 rounded-lg border border-amber-200 dark:border-amber-800/30">
              <div className="flex items-center gap-2 mb-2">
                <Activity size={16} className="text-amber-600 dark:text-amber-500" />
                <h4 className="text-xs font-bold uppercase tracking-widest text-amber-800 dark:text-amber-500">Análisis Inteligente</h4>
              </div>
              <p className="text-sm text-amber-700 dark:text-amber-600 font-medium">Nivel de Riesgo Detectado: <span className="font-black">{metadata.risk_score} / 100</span></p>
              <ul className="mt-2 space-y-1 text-xs text-amber-600/80 dark:text-amber-700 font-medium">
                <li>• Inasistencias recientes: {metadata.absence_count}</li>
                <li>• Llegadas tarde: {metadata.late_count}</li>
              </ul>
            </div>
          )}

          {!activeTrackingId ? (
            <div className="flex flex-col items-center justify-center py-10 space-y-4">
              <div className="w-16 h-16 rounded-full bg-slate-50 flex items-center justify-center">
                <Search size={28} className="text-slate-300" />
              </div>
              <div className="text-center">
                <p className="text-sm font-bold text-slate-700 dark:text-slate-300">Este estudiante no tiene un seguimiento activo.</p>
                <p className="text-xs text-slate-500 mt-1 max-w-sm mx-auto">Inicia un seguimiento para registrar notas, incidentes y el proceso de acompañamiento.</p>
              </div>
              <button 
                onClick={handleStartTracking}
                disabled={isSubmitting}
                className="mt-4 px-6 py-2.5 bg-[#003366] text-white text-xs font-bold uppercase tracking-widest rounded shadow-md hover:bg-[#002244] transition-colors disabled:opacity-50"
              >
                {isSubmitting ? 'Iniciando...' : 'Empezar Seguimiento'}
              </button>
            </div>
          ) : loading ? (
            <div className="flex justify-center py-10"><Activity className="animate-spin text-slate-400" /></div>
          ) : (
            <div className="space-y-4">
              <div className="flex justify-between items-center">
                <h4 className="text-xs font-bold uppercase tracking-widest text-slate-500">Historial de Notas</h4>
                {details?.status === 'en proceso' && (
                  <button onClick={handleResolve} className="text-[10px] font-bold uppercase tracking-widest text-[#00A67E] hover:underline">
                    Marcar como Resuelto
                  </button>
                )}
              </div>
              
              <div className="space-y-3">
                {notes.length === 0 ? (
                  <p className="text-xs text-slate-400 italic">No hay notas registradas.</p>
                ) : (
                  notes.map((note) => (
                    <div key={note.note_id} className="bg-slate-50 dark:bg-slate-800/50 p-3 rounded border border-slate-100 dark:border-slate-800">
                      <div className="flex justify-between items-start mb-1">
                        <span className="text-[10px] font-bold text-slate-700 dark:text-slate-300">{note.first_name} {note.last_name} ({note.role_name})</span>
                        <span className="text-[9px] text-slate-400 uppercase tracking-widest">{new Date(note.created_at).toLocaleString('es-CO', { timeZone: 'America/Bogota', dateStyle: 'short', timeStyle: 'short' })}</span>
                      </div>
                      <p className="text-xs text-slate-600 dark:text-slate-400 whitespace-pre-wrap">{note.note_text}</p>
                    </div>
                  ))
                )}
              </div>
            </div>
          )}
        </div>

        {activeTrackingId && details?.status === 'en proceso' && (
          <div className="p-4 border-t border-slate-100 dark:border-slate-800 bg-slate-50 dark:bg-slate-900/50 flex gap-2">
            <textarea 
              value={noteText}
              onChange={(e) => setNoteText(e.target.value)}
              placeholder="Escribe una nota sobre el proceso..."
              className="flex-1 text-xs p-2 rounded border border-slate-200 dark:border-slate-700 dark:bg-slate-800 dark:text-white resize-none outline-none focus:border-[#003366]"
              rows={2}
            />
            <button 
              onClick={handleAddNote}
              disabled={!noteText.trim() || isSubmitting}
              className="px-4 bg-[#003366] text-white rounded hover:bg-[#002244] disabled:opacity-50 transition-colors flex items-center justify-center"
            >
              <Send size={16} />
            </button>
          </div>
        )}
        
        {activeTrackingId && details?.status !== 'en proceso' && (
          <div className="p-4 border-t border-slate-100 dark:border-slate-800 bg-emerald-50 dark:bg-emerald-900/10 text-center">
            <p className="text-xs font-bold text-emerald-700 dark:text-emerald-500 uppercase tracking-widest flex items-center justify-center gap-2">
              <UserCheck size={14} /> Seguimiento Resuelto
            </p>
          </div>
        )}
      </motion.div>

      <AnimatePresence>
        {showResolvePrompt && (
          <motion.div
            initial={{ opacity: 0 }}
            animate={{ opacity: 1 }}
            exit={{ opacity: 0 }}
            className="fixed inset-0 z-[70] flex items-center justify-center bg-black/60 backdrop-blur-sm p-4"
          >
            <motion.div
              initial={{ scale: 0.95, opacity: 0 }}
              animate={{ scale: 1, opacity: 1 }}
              exit={{ scale: 0.95, opacity: 0 }}
              className="bg-white dark:bg-slate-900 w-full max-w-md rounded-lg shadow-2xl p-6"
            >
              <h3 className="text-sm font-bold uppercase tracking-widest text-slate-800 dark:text-white mb-4">
                Motivo de Cierre
              </h3>
              <textarea
                value={resolveReason}
                onChange={(e) => setResolveReason(e.target.value)}
                placeholder="Describe el motivo por el cual se cierra el seguimiento..."
                className="w-full text-xs p-3 rounded border border-slate-200 dark:border-slate-700 dark:bg-slate-800 dark:text-white resize-none outline-none focus:border-[#003366]"
                rows={4}
                autoFocus
              />
              <div className="flex gap-3 mt-4">
                <button
                  onClick={() => {
                    setShowResolvePrompt(false);
                    setResolveReason('');
                  }}
                  className="flex-1 px-4 py-2 text-xs font-bold uppercase tracking-wider text-slate-600 border border-slate-300 rounded hover:bg-slate-50 transition-colors"
                >
                  Cancelar
                </button>
                <button
                  onClick={confirmResolve}
                  disabled={isSubmitting || !resolveReason.trim()}
                  className="flex-1 px-4 py-2 text-xs font-bold uppercase tracking-wider text-white bg-[#003366] rounded hover:bg-[#002244] transition-colors disabled:opacity-50"
                >
                  {isSubmitting ? 'Cerrando...' : 'Cerrar Seguimiento'}
                </button>
              </div>
            </motion.div>
          </motion.div>
        )}
      </AnimatePresence>
    </div>
  );
};
