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
      const data = await reportsApi.getReport();
      const reportRows = Array.isArray(data) ? data : [];

      if (reportRows.length === 0) {
        setDownloadStatus('No hay datos disponibles para exportar.');
        return;
      }

      // Generar CSV real con BOM para compatibilidad con Excel
      const headers = ['Fecha', 'Estudiante', 'Hora', 'Tipo de evento'];
      const csvRows = [
        headers.join(','),
        ...reportRows.map(r => [
          `"${r.date ?? ''}"`,
          `"${r.student_id ?? ''}"`,
          `"${r.time ?? ''}"`,
          `"${r.event_type ?? ''}"`,
        ].join(',')),
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
          <h2 className="text-2xl font-bold text-gray-900">Informes y Estadísticas</h2>
          <p className="text-gray-500">Genera reportes detallados de asistencia y actividad biométrica.</p>
        </div>
        <div className="flex items-center gap-2 text-sm font-medium text-institutional-700 bg-institutional-50 px-4 py-2 rounded-full border border-institutional-100">
          <FileSpreadsheet size={16} />
          <span>Formato Excel (.xlsx)</span>
        </div>
        {downloadStatus && (
          <p className="text-xs font-semibold text-gray-500 mt-2">{downloadStatus}</p>
        )}
      </div>

      <div className="bg-white p-6 rounded-2xl shadow-sm border border-gray-100">
        <div className="flex items-center gap-2 text-gray-800 font-bold mb-6">
          <Filter size={20} className="text-institutional-600" />
          <h3>Filtros de Exportación</h3>
        </div>

        <div className="grid grid-cols-1 md:grid-cols-3 gap-6 items-end">
          <div className="space-y-2">
            <label className="text-sm font-semibold text-gray-700">Fecha Inicial</label>
            <div className="relative">
              <Calendar className="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400" size={18} />
              <input 
                type="date" 
                className="w-full pl-10 pr-4 py-2.5 border border-gray-200 rounded-lg focus:ring-2 focus:ring-institutional-500 outline-none"
                value={startDate}
                onChange={(e) => setStartDate(e.target.value)}
              />
            </div>
          </div>

          <div className="space-y-2">
            <label className="text-sm font-semibold text-gray-700">Fecha Final</label>
            <div className="relative">
              <Calendar className="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400" size={18} />
              <input 
                type="date" 
                className="w-full pl-10 pr-4 py-2.5 border border-gray-200 rounded-lg focus:ring-2 focus:ring-institutional-500 outline-none"
                value={endDate}
                onChange={(e) => setEndDate(e.target.value)}
              />
            </div>
          </div>

          <button 
            onClick={handleDownload}
            disabled={!startDate || !endDate || isDownloading}
            className="bg-institutional-700 hover:bg-institutional-800 disabled:opacity-50 disabled:cursor-not-allowed text-white font-bold py-2.5 px-6 rounded-lg transition-all flex items-center justify-center gap-2 shadow-lg shadow-institutional-200"
          >
            {isDownloading ? (
              <div className="h-5 w-5 border-2 border-white/30 border-t-white rounded-full animate-spin" />
            ) : (
              <>
                <Download size={20} />
                <span>Descargar Excel</span>
              </>
            )}
          </button>
        </div>
      </div>

      <div className="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
        <div className="px-6 py-4 border-b border-gray-100 flex items-center justify-between bg-gray-50">
          <h3 className="font-bold text-gray-800">Vista Previa de Datos</h3>
          <span className="text-xs font-bold text-gray-500 uppercase tracking-widest">Últimos registros</span>
        </div>
        <div className="overflow-x-auto">
          <table className="w-full text-left border-collapse">
            <thead>
              <tr className="bg-white border-b border-gray-100">
                <th className="px-6 py-4 text-xs font-bold text-gray-500 uppercase tracking-wider">Fecha</th>
                <th className="px-6 py-4 text-xs font-bold text-gray-500 uppercase tracking-wider">Estudiante</th>
                <th className="px-6 py-4 text-xs font-bold text-gray-500 uppercase tracking-wider">Grado</th>
                <th className="px-6 py-4 text-xs font-bold text-gray-500 uppercase tracking-wider">Hora</th>
                <th className="px-6 py-4 text-xs font-bold text-gray-500 uppercase tracking-wider">Estado</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-50">
              {rows.length > 0 ? rows.map((row) => (
                <tr key={`${row.student_id}-${row.time}-${row.date}`} className="hover:bg-gray-50 transition-colors">
                  <td className="px-6 py-4 text-sm text-gray-600">{row.date}</td>
                  <td className="px-6 py-4 text-sm font-medium text-gray-900">{row.student_id}</td>
                  <td className="px-6 py-4 text-sm text-gray-600">-</td>
                  <td className="px-6 py-4 text-sm text-gray-600">{row.time}</td>
                  <td className="px-6 py-4">
                    <span className="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">
                      {row.event_type}
                    </span>
                  </td>
                </tr>
              )) : (
                <tr>
                  <td colSpan={5} className="px-6 py-10 text-center text-sm text-gray-400">Sin datos disponibles</td>
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
