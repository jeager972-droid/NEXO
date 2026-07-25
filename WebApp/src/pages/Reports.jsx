/**
 * SCR-RPT-01 Reports
 * Previsualización y exportación de reportes institucionales en CSV.
 */
import { useEffect, useState } from 'react';
import { FileSpreadsheet, Calendar, Download, AlertTriangle } from 'lucide-react';
import { reportsApi } from '../api/reports';
import { Section, Surface } from '../components/ui/Surface';
import { Input } from '../components/ui/Input';
import { Button } from '../components/ui/Button';
import { Card } from '../components/ui/Card';
import { EmptyState } from '../components/ui/EmptyState';
import { Skeleton } from '../components/ui/Skeleton';

const Reports = () => {
  const [startDate, setStartDate] = useState('');
  const [endDate, setEndDate] = useState('');
  const [isDownloading, setIsDownloading] = useState(false);
  const [rows, setRows] = useState([]);
  const [loading, setLoading] = useState(true);
  const [status, setStatus] = useState('');

  useEffect(() => {
    const loadPreview = async () => {
      try {
        const data = await reportsApi.getReport();
        setRows(Array.isArray(data) ? data : []);
      } catch (e) {
        console.error(e);
      } finally {
        setLoading(false);
      }
    };
    loadPreview();
  }, []);

  const handleDownload = async () => {
    if (!startDate || !endDate) return;
    setIsDownloading(true);
    setStatus('');
    try {
      const data = await reportsApi.exportReport(startDate, endDate);
      const reportRows = Array.isArray(data) ? data : [];
      if (reportRows.length === 0) {
        setStatus('No hay datos para el rango seleccionado.');
        setIsDownloading(false);
        return;
      }
      const headers = Object.keys(reportRows[0]);
      const csv = [
        headers.join(','),
        ...reportRows.map((row) => headers.map((h) => `"${String(row[h] ?? '').replace(/"/g, '""')}"`).join(',')),
      ].join('\n');
      const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
      const url = URL.createObjectURL(blob);
      const a = document.createElement('a');
      a.href = url;
      a.download = `nexo-reporte-${startDate}-al-${endDate}.csv`;
      document.body.appendChild(a);
      a.click();
      document.body.removeChild(a);
      URL.revokeObjectURL(url);
      setStatus('Descarga iniciada.');
    } catch (e) {
      console.error(e);
      setStatus('Error al generar el reporte.');
    } finally {
      setIsDownloading(false);
    }
  };

  return (
    <div className="space-y-8">
      <Section title="Reportes" subtitle="Exporta datos institucionales en CSV" />

      <Card>
        <div className="grid grid-cols-1 md:grid-cols-3 gap-4 items-end">
          <Input
            type="date"
            label="Desde"
            value={startDate}
            onChange={(e) => setStartDate(e.target.value)}
          />
          <Input
            type="date"
            label="Hasta"
            value={endDate}
            onChange={(e) => setEndDate(e.target.value)}
          />
          <Button
            onClick={handleDownload}
            loading={isDownloading}
            disabled={!startDate || !endDate}
            leftIcon={<Download size={18} />}
          >
            Descargar CSV
          </Button>
        </div>
        {status && (
          <div className="mt-4 flex items-center gap-2 text-body-sm text-[var(--nx-text-muted)]">
            {status.startsWith('Error') ? <AlertTriangle size={16} className="text-[var(--nx-danger)]" /> : <FileSpreadsheet size={16} className="text-[var(--nx-success)]" />}
            {status}
          </div>
        )}
      </Card>

      <Section title="Vista previa" subtitle="Últimos registros disponibles" />
      {loading ? (
        <div className="space-y-3">
          {[1, 2, 3].map((i) => <Skeleton key={i} className="h-16 w-full" />)}
        </div>
      ) : rows.length === 0 ? (
        <Surface>
          <EmptyState icon={<Calendar size={32} className="text-[var(--nx-border)]" />} title="Sin datos" description="No hay registros para mostrar en la vista previa." />
        </Surface>
      ) : (
        <Surface className="overflow-x-auto">
          <table className="w-full min-w-[600px]">
            <thead>
              <tr className="border-b border-[var(--nx-border)] bg-[var(--nx-surface-subtle)]">
                {Object.keys(rows[0]).map((h) => (
                  <th key={h} className="px-4 py-3 text-left text-caption font-medium uppercase text-[var(--nx-text-muted)]">{h}</th>
                ))}
              </tr>
            </thead>
            <tbody className="divide-y divide-[var(--nx-border)]">
              {rows.slice(0, 20).map((row, i) => (
                <tr key={i} className="hover:bg-[var(--nx-surface-subtle)]">
                  {Object.keys(rows[0]).map((h) => (
                    <td key={h} className="px-4 py-3 text-body-sm text-[var(--nx-text)]">{row[h]}</td>
                  ))}
                </tr>
              ))}
            </tbody>
          </table>
        </Surface>
      )}
    </div>
  );
};

export default Reports;
