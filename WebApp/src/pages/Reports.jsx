/**
 * Reports page / NEXO Institucional
 * Responsabilidad: Generación/exportación de reportes institucionales: selección de rango
 * de fechas, previsualización y descarga en CSV con mapeo a nombres/grupos reales.
 * Dependencias: React, lucide-react, reportsApi.
 */
import { useEffect, useState } from 'react';
import { 
  FileSpreadsheet, 
  Calendar, 
  Download, 
  Filter,
} from 'lucide-react';
import { reportsApi } from '../api/reports';

const Reports = () => {
  const [startDate, setStartDate] = useState('');
  const [endDate, setEndDate] = useState('');
  const [isDownloading, setIsDownloading] = useState(false);
  const [rows, setRows] = useState([]);
  const [downloadStatus, setDownloadStatus] = useState('');

  useEffect(() => {
    const loadPreview = async () => {
      try {
        const data = await reportsApi.getReport();
        setRows(Array.isArray(data) ? data : []);
      } catch (error) {
        console.error('Error cargando vista previa de reportes', error);
      }
    };
    loadPreview();
  }, []);

  const handleDownload = async () => {
    if (!startDate || !endDate) return;
    setIsDownloading(true);
    setDownloadStatus('');
    try {
      // BUG-05 FIX: pasar las fechas seleccionadas al endpoint
      const data = await reportsApi.exportReport(startDate, endDate);
      const reportRows = Array.isArray(data) ? data : [];

      if (reportRows.length === 0) {
        setDownloadStatus('No hay datos disponibles para exportar.');
        return;
      }

      // Generar CSV real con BOM para compatibilidad con Excel
      const headers = ['Fecha', 'Estudiante', 'Hora', 'Tipo de evento'];
        const csvRows = [
          headers.join(';'),
          ...reportRows.map(r => [
            `"${r.date ?? ''}"`,
            // BUG-06 FIX: usar nombre real del estudiante
            `"${r.student_name || ((r.last_name || '') + ' ' + (r.first_name || '')).trim() || r.student_id || ''}"`,
            `"${r.time ?? ''}"`,
            `"${r.event_type ?? ''}"`,
          ].join(';')),
        ];
      const csvContent = '\uFEFF' + csvRows.join('\n');
      const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
      const url = URL.createObjectURL(blob);
      const a = document.createElement('a');
      a.href = url;
      a.download = `nexo-asistencia-${startDate}-${endDate}.csv`;
      document.body.appendChild(a);
      a.click();
      document.body.removeChild(a);
      URL.revokeObjectURL(url);
      setDownloadStatus(`${reportRows.length} registros exportados.`);
    } catch (error) {
      setDownloadStatus('No fue posible generar el informe.');
    } finally {
      setIsDownloading(false);
    }
  };

  return (
    <div className="space-y-8">
      <div className="flex flex-col md:flex-row md:items-center justify-between gap-4">
        <div>
          <h2 className="text-2xl font-bold" style={{ color: 'var(--nx-text)' }}>Informes y Estadísticas</h2>
          <p style={{ color: 'var(--nx-text-muted)' }}>Genera reportes detallados de asistencia y actividad biométrica.</p>
        </div>
        <div className="flex items-center gap-2 text-sm font-medium px-4 py-2 rounded-full" style={{ color: 'var(--nx-accent)', backgroundColor: 'color-mix(in oklch, var(--nx-accent) 8%, transparent)', border: '1px solid color-mix(in oklch, var(--nx-accent) 15%, transparent)' }}>
          <FileSpreadsheet size={16} />
          <span>Formato Excel (.xlsx)</span>
        </div>
        {downloadStatus && (
          <p className="text-xs font-semibold mt-2" style={{ color: 'var(--nx-text-muted)' }}>{downloadStatus}</p>
        )}
      </div>

      <div className="p-6 rounded-2xl" style={{ backgroundColor: 'var(--nx-surface)', border: '1px solid var(--nx-border)' }}>
        <div className="flex items-center gap-2 font-bold mb-6" style={{ color: 'var(--nx-text)' }}>
          <Filter size={20} style={{ color: 'var(--nx-accent)' }} />
          <h3>Filtros de Exportación</h3>
        </div>

        <div className="grid grid-cols-1 md:grid-cols-3 gap-6 items-end">
          <div className="space-y-2">
            <label className="text-sm font-semibold" style={{ color: 'var(--nx-text)' }}>Fecha Inicial</label>
            <div className="relative">
              <Calendar className="absolute left-3 top-1/2 -translate-y-1/2" size={18} style={{ color: 'var(--nx-text-muted)' }} />
              <input
                type="date"
                className="w-full pl-10 pr-4 py-2.5 rounded-lg focus:ring-2 outline-none"
                style={{ border: '1px solid var(--nx-border)', '--tw-ring-color': 'var(--nx-accent)' }}
                value={startDate}
                onChange={(e) => setStartDate(e.target.value)}
              />
            </div>
          </div>

          <div className="space-y-2">
            <label className="text-sm font-semibold" style={{ color: 'var(--nx-text)' }}>Fecha Final</label>
            <div className="relative">
              <Calendar className="absolute left-3 top-1/2 -translate-y-1/2" size={18} style={{ color: 'var(--nx-text-muted)' }} />
              <input
                type="date"
                className="w-full pl-10 pr-4 py-2.5 rounded-lg focus:ring-2 outline-none"
                style={{ border: '1px solid var(--nx-border)', '--tw-ring-color': 'var(--nx-accent)' }}
                value={endDate}
                onChange={(e) => setEndDate(e.target.value)}
              />
            </div>
          </div>

          <button 
            onClick={handleDownload}
            disabled={!startDate || !endDate || isDownloading}
            className="hover:opacity-90 disabled:opacity-50 disabled:cursor-not-allowed text-white font-bold py-2.5 px-6 rounded-lg transition-all flex items-center justify-center gap-2"
            style={{ backgroundColor: 'var(--nx-accent)' }}
          >
            {isDownloading ? (
              <div className="h-5 w-5 border-2 border-white/30 border-t-white rounded-full animate-spin" />
            ) : (
              <>
                <Download size={20} />
                <span>Exportar CSV para Excel</span>
              </>
            )}
          </button>
        </div>
      </div>

      <div className="rounded-2xl overflow-hidden" style={{ backgroundColor: 'var(--nx-surface)', border: '1px solid var(--nx-border)' }}>
        <div className="px-6 py-4 flex items-center justify-between" style={{ borderBottom: '1px solid var(--nx-border)', backgroundColor: 'var(--nx-surface-subtle)' }}>
          <h3 className="font-bold" style={{ color: 'var(--nx-text)' }}>Vista Previa de Datos</h3>
          <span className="text-xs font-bold uppercase tracking-widest" style={{ color: 'var(--nx-text-muted)' }}>Últimos registros</span>
        </div>
        <div className="overflow-x-auto">
          <table className="w-full text-left border-collapse">
            <thead>
              <tr style={{ borderBottom: '1px solid var(--nx-border)' }}>
                <th className="px-6 py-4 text-xs font-bold uppercase tracking-wider" style={{ color: 'var(--nx-text-muted)' }}>Fecha</th>
                <th className="px-6 py-4 text-xs font-bold uppercase tracking-wider" style={{ color: 'var(--nx-text-muted)' }}>Estudiante</th>
                <th className="px-6 py-4 text-xs font-bold uppercase tracking-wider" style={{ color: 'var(--nx-text-muted)' }}>Grado</th>
                <th className="px-6 py-4 text-xs font-bold uppercase tracking-wider" style={{ color: 'var(--nx-text-muted)' }}>Hora</th>
                <th className="px-6 py-4 text-xs font-bold uppercase tracking-wider" style={{ color: 'var(--nx-text-muted)' }}>Estado</th>
              </tr>
            </thead>
            <tbody className="divide-y" style={{ borderColor: 'var(--nx-border)' }}>
              {rows.length > 0 ? rows.map((row) => (
                <tr key={`${row.student_id}-${row.time}-${row.date}`} className="hover:bg-[var(--nx-surface-subtle)] transition-colors">
                  <td className="px-6 py-4 text-sm" style={{ color: 'var(--nx-text-muted)' }}>{row.date}</td>
                  {/* BUG-06 FIX: mostrar nombre real del estudiante */}
                  <td className="px-6 py-4 text-sm font-medium" style={{ color: 'var(--nx-text)' }}>
                    {row.student_name || `${row.last_name || ''} ${row.first_name || ''}`.trim() || row.student_id || '—'}
                  </td>
                  {/* BUG-06 FIX: mostrar grado/grupo real */}
                  <td className="px-6 py-4 text-sm" style={{ color: 'var(--nx-text-muted)' }}>{row.grade || row.group_name || '—'}</td>
                  <td className="px-6 py-4 text-sm" style={{ color: 'var(--nx-text-muted)' }}>{row.time}</td>
                  <td className="px-6 py-4">
                    <span className="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium" style={{ backgroundColor: 'color-mix(in oklch, var(--nx-success) 15%, transparent)', color: 'var(--nx-success)' }}>
                      {row.event_type}
                    </span>
                  </td>
                </tr>
              )) : (
                <tr>
                  <td colSpan={5} className="px-6 py-10 text-center text-sm" style={{ color: 'var(--nx-text-muted)' }}>Sin datos disponibles</td>
                </tr>
              )}
            </tbody>
          </table>
        </div>
      </div>
    </div>
  );
};

export default Reports;
