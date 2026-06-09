import { useState, useEffect } from 'react';
import { trackingApi } from '../api/tracking';
import { TrackingModal } from './TrackingModal';
import { FileText, Search, Activity, UserCheck, CalendarDays, Loader2 } from 'lucide-react';
import { motion, AnimatePresence } from 'framer-motion';

const SectionLabel = ({ title, sub }) => (
  <div className="mb-6">
    <h1 className="text-xl font-black text-slate-800 dark:text-white uppercase tracking-wider">{title}</h1>
    {sub && <p className="text-xs font-bold text-slate-400 uppercase tracking-widest mt-1">{sub}</p>}
  </div>
);

export default function Seguimiento() {
  const [trackings, setTrackings] = useState([]);
  const [loading, setLoading] = useState(true);
  const [searchQuery, setSearchQuery] = useState('');
  
  const [trackingModalOpen, setTrackingModalOpen] = useState(false);
  const [selectedTrackingTarget, setSelectedTrackingTarget] = useState(null);

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
  }, []);

  const openTracking = (trackingId, studentName) => {
    setSelectedTrackingTarget({ trackingId, studentName });
    setTrackingModalOpen(true);
  };

  const filteredTrackings = searchQuery.trim()
    ? trackings.filter(row => 
        (`${row.last_name} ${row.first_name}`).toLowerCase().includes(searchQuery.toLowerCase())
      )
    : trackings;

  return (
    <div className="max-w-6xl mx-auto pb-10">
      <SectionLabel title="Seguimiento Estudiantil" sub="Gestión de estudiantes en proceso de intervención" />

      <div className="bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 shadow-sm flex flex-col">
        {/* Header & Search */}
        <div className="p-5 border-b border-slate-100 dark:border-slate-800 flex flex-col md:flex-row md:items-center justify-between gap-4">
          <div className="flex items-center gap-3">
            <div className="w-10 h-10 flex items-center justify-center bg-[#003366]/10 text-[#003366] dark:bg-[#00A67E]/10 dark:text-[#00A67E]">
              <Activity size={20} strokeWidth={2} />
            </div>
            <div>
              <p className="text-sm font-black text-slate-800 dark:text-white uppercase tracking-widest">
                En Proceso
              </p>
              <p className="text-[10px] font-bold text-slate-400 uppercase tracking-widest mt-0.5">
                {filteredTrackings.length} estudiante{filteredTrackings.length !== 1 ? 's' : ''}
              </p>
            </div>
          </div>

          <div className="relative w-full md:w-72">
            <Search size={14} className="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" />
            <input 
              type="text" 
              placeholder="Buscar estudiante..." 
              value={searchQuery}
              onChange={e => setSearchQuery(e.target.value)}
              className="w-full pl-9 pr-4 py-2.5 text-xs font-semibold bg-slate-50 dark:bg-slate-900 dark:text-white outline-none border border-slate-200 dark:border-slate-700 focus:border-[#003366] dark:focus:border-[#00A67E] transition-colors"
            />
          </div>
        </div>

        {/* List */}
        <div className="p-0">
          {loading ? (
            <div className="flex flex-col items-center justify-center py-20 gap-4">
              <Loader2 size={32} className="animate-spin text-[#003366] dark:text-[#00A67E]" />
              <p className="text-[10px] font-bold text-slate-400 uppercase tracking-widest">Cargando procesos...</p>
            </div>
          ) : filteredTrackings.length > 0 ? (
            <div className="overflow-x-auto">
              <table className="w-full text-left border-collapse">
                <thead>
                  <tr className="bg-slate-50 dark:bg-slate-800/50 border-b border-slate-200 dark:border-slate-700">
                    <th className="px-5 py-3 text-[10px] font-bold text-slate-400 uppercase tracking-widest">Estudiante</th>
                    <th className="px-5 py-3 text-[10px] font-bold text-slate-400 uppercase tracking-widest">Grupo</th>
                    <th className="px-5 py-3 text-[10px] font-bold text-slate-400 uppercase tracking-widest">Última Actividad</th>
                    <th className="px-5 py-3 text-[10px] font-bold text-slate-400 uppercase tracking-widest text-right">Acción</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-slate-100 dark:divide-slate-800/50">
                  {filteredTrackings.map((t) => (
                    <tr key={t.tracking_id} className="hover:bg-slate-50 dark:hover:bg-slate-800/30 transition-colors">
                      <td className="px-5 py-4">
                        <p className="text-sm font-bold text-slate-800 dark:text-slate-200">{t.last_name} {t.first_name}</p>
                      </td>
                      <td className="px-5 py-4">
                        <p className="text-xs font-semibold text-slate-600 dark:text-slate-400">{t.group_name || '—'}</p>
                      </td>
                      <td className="px-5 py-4">
                        <p className="text-xs font-semibold text-slate-600 dark:text-slate-400">
                          {new Date(t.updated_at).toLocaleString('es-ES', { dateStyle: 'short', timeStyle: 'short' })}
                        </p>
                      </td>
                      <td className="px-5 py-4 text-right">
                        <button 
                          onClick={() => openTracking(t.tracking_id, `${t.last_name} ${t.first_name}`)}
                          className="inline-flex items-center justify-center px-4 py-2 text-[10px] font-bold uppercase tracking-widest text-white bg-[#003366] hover:bg-[#002244] dark:bg-[#00A67E] dark:hover:bg-[#008F6B] transition-colors shadow-sm"
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
              <CalendarDays size={32} strokeWidth={1.5} className="text-slate-300 dark:text-slate-600" />
              <div className="text-center">
                <p className="text-xs font-bold text-slate-500 uppercase tracking-widest">No hay procesos activos</p>
                <p className="text-xs text-slate-400 mt-1">
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
            isOpen={trackingModalOpen}
            onClose={() => setTrackingModalOpen(false)}
            trackingId={selectedTrackingTarget.trackingId}
            studentName={selectedTrackingTarget.studentName}
            onRefresh={fetchTrackings}
          />
        )}
      </AnimatePresence>
    </div>
  );
}
