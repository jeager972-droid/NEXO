/**
 * SCR-CAS-01 Casos Activos (Seguimiento) — DEC-IA-01
 * Lista de estudiantes en seguimiento con búsqueda y apertura de TrackingDrawer.
 * Usa PageHeader, RiskBadge, SkeletonRows, Drawer unificado.
 */
import { useState, useEffect } from 'react';
import { useSearchParams } from 'react-router-dom';
import { FileText, Search, Activity, CalendarDays } from 'lucide-react';
import { AnimatePresence } from 'framer-motion';
import { trackingApi } from '../api/tracking';
import { TrackingModal } from './TrackingModal';
import { Surface, PageHeader } from '../components/ui/Surface';
import { Input } from '../components/ui/Input';
import { Card } from '../components/ui/Card';
import { EmptyState } from '../components/ui/EmptyState';
import { SkeletonRows } from '../components/ui/Skeleton';
import { RiskBadge } from '../components/patterns/RiskBadge';

export default function Casos() {
  const [searchParams] = useSearchParams();
  const [trackings, setTrackings] = useState([]);
  const [loading, setLoading] = useState(true);
  const [searchQuery, setSearchQuery] = useState('');
  const [modalOpen, setModalOpen] = useState(false);
  const [selected, setSelected] = useState(null);

  const fetchTrackings = async () => {
    setLoading(true);
    try {
      const res = await trackingApi.getActive();
      setTrackings(res?.status === 'ok' ? res.trackings || [] : []);
    } catch (e) {
      console.error(e);
      setTrackings([]);
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    fetchTrackings();
    const handler = () => fetchTrackings();
    window.addEventListener('nexo:tracking-refresh', handler);

    const studentId = searchParams.get('student_id');
    if (studentId) {
      trackingApi.startTracking(studentId)
        .then((res) => { if (res.status === 'ok') fetchTrackings(); })
        .catch((err) => console.error(err));
    }

    return () => window.removeEventListener('nexo:tracking-refresh', handler);
  }, [searchParams]);

  const filtered = searchQuery.trim()
    ? trackings.filter((row) => `${row.last_name || ''} ${row.first_name || ''}`.toLowerCase().includes(searchQuery.toLowerCase()))
    : trackings;

  const openTracking = (trackingId, studentName, studentId) => {
    setSelected({ trackingId, studentName, studentId });
    setModalOpen(true);
  };

  return (
    <div className="space-y-8">
      <PageHeader
        eyebrow="Intervención estudiantil"
        title="Casos Activos"
        subtitle="Estudiantes en proceso de intervención"
      />

      <Input
        placeholder="Buscar estudiante…"
        value={searchQuery}
        onChange={(e) => setSearchQuery(e.target.value)}
        leftIcon={<Search size={16} className="text-[var(--nx-text-muted)]" />}
      />

      {loading ? (
        <Surface><SkeletonRows count={4} /></Surface>
      ) : filtered.length === 0 ? (
        <Surface>
          <EmptyState
            icon={<FileText size={32} className="text-[var(--nx-border)]" />}
            title="No hay casos activos"
            description={searchQuery ? 'Ningún estudiante coincide con tu búsqueda.' : 'No hay estudiantes en seguimiento en este momento.'}
          />
        </Surface>
      ) : (
        <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
          {filtered.map((row) => (
            <Card key={row.tracking_id || row.student_id} asAction onClick={() => openTracking(row.tracking_id, `${row.last_name} ${row.first_name}`, row.student_id)}>
              <div className="flex items-start justify-between">
                <div>
                  <p className="text-h3 text-[var(--nx-text)]">{row.last_name} {row.first_name}</p>
                  <p className="text-body-sm text-[var(--nx-text-muted)]">{row.group_name || 'Sin grupo'}</p>
                </div>
                <RiskBadge level={row.risk_level || row.risk_score} />
              </div>
              <div className="mt-4 flex items-center gap-4 text-caption text-[var(--nx-text-muted)]">
                <span className="flex items-center gap-1"><Activity size={12} /> {row.status || 'Activo'}</span>
                {row.created_at && <span className="flex items-center gap-1"><CalendarDays size={12} /> {new Date(row.created_at).toLocaleDateString('es-CO')}</span>}
              </div>
            </Card>
          ))}
        </div>
      )}

      <AnimatePresence>
        {modalOpen && selected && (
          <TrackingModal
            trackingId={selected.trackingId}
            studentId={selected.studentId}
            studentName={selected.studentName}
            onClose={() => setModalOpen(false)}
            onRefresh={fetchTrackings}
          />
        )}
      </AnimatePresence>
    </div>
  );
}
