/**
 * SCR-CAS-01 Casos Activos (Seguimiento) — DEC-IA-01
 * Lista de estudiantes en seguimiento con búsqueda y apertura de TrackingDrawer.
 */
import { useState, useEffect, useMemo } from 'react';
import { useSearchParams } from 'react-router-dom';
import { Search, CalendarDays, Sparkles, AlertTriangle } from 'lucide-react';
import { AnimatePresence } from 'framer-motion';
import { trackingApi } from '../api/tracking';
import { TrackingModal } from './TrackingModal';
import { Surface } from '../components/ui/Surface';
import { Input } from '../components/ui/Input';
import { Card } from '../components/ui/Card';
import { EmptyState } from '../components/ui/EmptyState';
import { SkeletonRows } from '../components/ui/Skeleton';
import { formatGroupName } from '../utils/groupFormat';

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

  const filtered = useMemo(() => {
    if (!searchQuery.trim()) return trackings;
    return trackings.filter((row) => `${row.last_name || ''} ${row.first_name || ''}`.toLowerCase().includes(searchQuery.toLowerCase()));
  }, [trackings, searchQuery]);

  const openTracking = (trackingId, studentName, studentId) => {
    setSelected({ trackingId, studentName, studentId });
    setModalOpen(true);
  };

  return (
    <div className="space-y-6">
      <div className="border-b border-[var(--nx-border)] pb-3">
        <div className="border-l-2 border-[var(--nx-accent)] pl-3">
          <p className="text-label text-[var(--nx-text)]">Casos activos</p>
        </div>
      </div>

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
            icon={<Sparkles size={32} className="text-[var(--nx-success)]" />}
            title="No hay nada para mostrar."
            description={searchQuery ? 'Ningún estudiante coincide con la búsqueda.' : 'No hay estudiantes en seguimiento en este momento.'}
          />
        </Surface>
      ) : (
        <>
          <p className="text-body-sm text-[var(--nx-text-muted)]">
            Se encontraron {filtered.length} resultado{filtered.length !== 1 ? 's' : ''}
          </p>
          <div className="grid grid-cols-1 md:grid-cols-2 gap-4 bg-[var(--nx-surface-warning)]/30 p-4 rounded-surface border border-[var(--nx-warning)]">
            {filtered.map((row) => (
              <Card key={row.tracking_id || row.student_id} asAction tone="warning" className="border-[var(--nx-warning)] bg-[var(--nx-surface-warning)]" onClick={() => openTracking(row.tracking_id, `${row.last_name} ${row.first_name}`, row.student_id)}>
                <div className="flex items-center justify-between">
                  <div className="min-w-0 flex-1">
                    <p className="text-h3 text-[var(--nx-text)] truncate" style={{ fontWeight: 620 }}>{row.last_name} {row.first_name}</p>
                    <p className="text-body-sm text-[var(--nx-text-muted)] mt-0.5">{formatGroupName(row.group_name) || 'Sin grupo'}</p>
                  </div>
                  <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-control bg-[var(--nx-icon-bg-warning)] text-[color-mix(in_oklch,var(--nx-warning)_72%,var(--nx-icon-mix))]">
                    <AlertTriangle size={20} />
                  </div>
                </div>
                <div className="mt-4 flex items-center gap-2 text-caption text-[var(--nx-text-muted)]">
                  {row.created_at && <span className="flex items-center gap-1"><CalendarDays size={12} /> {new Date(row.created_at).toLocaleDateString('es-CO')}</span>}
                </div>
              </Card>
            ))}
          </div>
        </>
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
