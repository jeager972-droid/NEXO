/**
 * Casos Activos page / NEXO Institucional
 * Listado de seguimientos estudiantiles activos, con búsqueda, inicio
 * automático vía query params y apertura de TrackingModal.
 * Escucha evento nexo:tracking-refresh para recargar.
 */
import { useState, useEffect } from 'react';
import { useSearchParams } from 'react-router-dom';
import { trackingApi } from '../api/tracking';
import { TrackingModal } from './TrackingModal';
import { FileText, Search, Activity, UserCheck, CalendarDays, Loader2 } from 'lucide-react';
import { motion, AnimatePresence } from 'framer-motion';

const SectionLabel = ({ title, sub }) => (
  <div className="mb-6">
    <h1 className="text-xl font-semibold" style={{ color: 'var(--nx-text)' }}>{title}</h1>
    {sub && <p className="text-xs font-medium mt-1" style={{ color: 'var(--nx-text-muted)' }}>{sub}</p>}
  </div>
);

export default function Casos() {
  const [searchParams] = useSearchParams();
  const [trackings, setTrackings] = useState([]);
  const [loading, setLoading] = useState(true);
  const [searchQuery, setSearchQuery] = useState('');

  const [trackingModalOpen, setTrackingModalOpen] = useState(false);
  const [selectedTrackingTarget, setSelectedTrackingTarget] = useState(null);
  const [autoStartStudent, setAutoStartStudent] = useState(null);

  const fetchTrackings = async () => {
    setLoading(true);
    try {
      const res = await trackingApi.getActive();
      if (res?.status === 'ok') {
        setTrackings(res.trackings || []);
      }
    } catch (err) {
      console.error(err);
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    fetchTrackings();

    // Escuchar evento de actualización de seguimiento
    const handleRefresh = () => {
      fetchTrackings();
    };

    window.addEventListener('nexo:tracking-refresh', handleRefresh);

    // Verificar si se debe iniciar seguimiento automáticamente desde notificación
    const studentId = searchParams.get('student_id');
    const studentName = searchParams.get('student_name');
    if (studentId) {
      setAutoStartStudent({ id: studentId, name: studentName || 'Estudiante' });
      // Iniciar seguimiento automáticamente
      trackingApi.startTracking(studentId)
        .then(res => {
          if (res.status === 'ok') {
            fetchTrackings(); // Refrescar lista
          }
        })
        .catch(err => console.error('Error iniciando seguimiento automático:', err));
    }

    return () => {
      window.removeEventListener('nexo:tracking-refresh', handleRefresh);
    };
  }, [searchParams]);

  const openTracking = (trackingId, studentName, studentId) => {
    setSelectedTrackingTarget({ trackingId, studentName, studentId });
    setTrackingModalOpen(true);
  };

  const filteredTrackings = searchQuery.trim()
    ? trackings.filter(row => 
        (`${row.last_name} ${row.first_name}`).toLowerCase().includes(searchQuery.toLowerCase())
      )
    : trackings;

  return (
    <div className="max-w-6xl mx-auto pb-10">
      <SectionLabel title="Casos Activos" sub="Gestión de estudiantes en proceso de intervención" />

      <div className="flex flex-col" style={{ backgroundColor: 'var(--nx-surface)', border: '1px solid var(--nx-border)' }}>
        {/* Header & Search */}
        <div className="p-5 flex flex-col md:flex-row md:items-center justify-between gap-4" style={{ borderBottom: '1px solid var(--nx-border)' }}>
          <div className="flex items-center gap-3">
            <div className="w-10 h-10 flex items-center justify-center" style={{ backgroundColor: 'color-mix(in oklch, var(--nx-accent) 10%, transparent)', color: 'var(--nx-accent)' }}>
              <Activity size={20} strokeWidth={2} />
            </div>
            <div>
              <p className="text-sm font-semibold uppercase tracking-wider" style={{ color: 'var(--nx-text)' }}>
                En Proceso
              </p>
              <p className="text-[10px] font-medium mt-0.5" style={{ color: 'var(--nx-text-muted)' }}>
                {filteredTrackings.length} estudiante{filteredTrackings.length !== 1 ? 's' : ''}
              </p>
            </div>
          </div>

          <div className="relative w-full md:w-72">
            <Search size={14} className="absolute left-3 top-1/2 -translate-y-1/2" style={{ color: 'var(--nx-text-muted)' }} />
            <input 
              type="text" 
              placeholder="Buscar estudiante..." 
              value={searchQuery}
              onChange={e => setSearchQuery(e.target.value)}
              className="w-full pl-9 pr-4 py-2.5 text-xs font-medium outline-none transition-colors"
              style={{ backgroundColor: 'var(--nx-surface-subtle)', border: '1px solid var(--nx-border)', color: 'var(--nx-text)' }}
            />
          </div>
        </div>

        {/* List */}
        <div className="p-0">
          {loading ? (
            <div className="flex flex-col items-center justify-center py-20 gap-4">
              <Loader2 size={32} className="animate-spin" style={{ color: 'var(--nx-accent)' }} />
              <p className="text-[10px] font-medium" style={{ color: 'var(--nx-text-muted)' }}>Cargando procesos...</p>
            </div>
          ) : filteredTrackings.length > 0 ? (
            <div className="overflow-x-auto">
              <table className="w-full text-left border-collapse">
                <thead>
                  <tr style={{ backgroundColor: 'var(--nx-surface-subtle)', borderBottom: '1px solid var(--nx-border)' }}>
                    <th className="px-5 py-3 text-[10px] font-semibold uppercase tracking-wider" style={{ color: 'var(--nx-text-muted)' }}>Estudiante</th>
                    <th className="px-5 py-3 text-[10px] font-semibold uppercase tracking-wider" style={{ color: 'var(--nx-text-muted)' }}>Grupo</th>
                    <th className="px-5 py-3 text-[10px] font-semibold uppercase tracking-wider" style={{ color: 'var(--nx-text-muted)' }}>Última Actividad</th>
                    <th className="px-5 py-3 text-[10px] font-semibold uppercase tracking-wider text-right" style={{ color: 'var(--nx-text-muted)' }}>Acción</th>
                  </tr>
                </thead>
                <tbody className="divide-y" style={{ borderColor: 'var(--nx-border)' }}>
                  {filteredTrackings.map((t) => (
                    <tr key={t.tracking_id} className="hover:bg-[var(--nx-surface-subtle)] transition-colors">
                      <td className="px-5 py-4">
                        <p className="text-sm font-semibold" style={{ color: 'var(--nx-text)' }}>{t.last_name} {t.first_name}</p>
                      </td>
                      <td className="px-5 py-4">
                        <p className="text-xs font-medium" style={{ color: 'var(--nx-text-muted)' }}>{t.group_name || '—'}</p>
                      </td>
                      <td className="px-5 py-4">
                        <p className="text-xs font-medium" style={{ color: 'var(--nx-text-muted)' }}>
                          {new Date(t.updated_at).toLocaleString('es-CO', { dateStyle: 'short', timeStyle: 'short' })}
                        </p>
                      </td>
                      <td className="px-5 py-4 text-right">
                        <button 
                          onClick={() => openTracking(t.tracking_id, `${t.last_name} ${t.first_name}`, t.student_id)}
                          className="inline-flex items-center justify-center px-4 py-2 text-[10px] font-semibold uppercase tracking-wider transition-colors"
                          style={{ backgroundColor: 'var(--nx-accent)', color: 'var(--nx-accent-text)' }}
                        >
                          Revisar Proceso
                        </button>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          ) : (
            <div className="flex flex-col items-center justify-center py-20 gap-4">
              <CalendarDays size={32} strokeWidth={1.5} style={{ color: 'var(--nx-text-muted)' }} />
              <div className="text-center">
                <p className="text-xs font-semibold uppercase tracking-wider" style={{ color: 'var(--nx-text-muted)' }}>No hay procesos activos</p>
                <p className="text-xs mt-1" style={{ color: 'var(--nx-text-muted)' }}>
                  {searchQuery ? 'Ningún estudiante coincide con la búsqueda.' : 'Actualmente no hay estudiantes en seguimiento.'}
                </p>
              </div>
            </div>
          )}
        </div>
      </div>

      <AnimatePresence>
        {trackingModalOpen && selectedTrackingTarget && (
          <TrackingModal
            onClose={() => setTrackingModalOpen(false)}
            trackingId={selectedTrackingTarget.trackingId}
            studentName={selectedTrackingTarget.studentName}
            studentId={selectedTrackingTarget.studentId}
            onRefresh={fetchTrackings}
          />
        )}
      </AnimatePresence>
    </div>
  );
}
