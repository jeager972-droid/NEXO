/**
 * exporters / NEXO Institucional
 * Responsabilidad: generar descargas institucionales (CSV, Excel y Word) desde datos
 * que ya devuelve el backend. No añade endpoints ni dependencias: Excel se emite como
 * hoja HTML que Excel abre nativamente y Word como documento HTML compatible.
 * Autoridad: FLOW-REP-01, DEC-016 (previsualizar antes de exportar), DEC-FE-04.
 */

const PII_KEYS = ['password', 'token', 'hash', 'secret', 'fingerprint_template'];
const HIDDEN_KEYS = ['metadata', 'raw', '__typename'];

/** Etiqueta legible desde una clave técnica: `student_id` → `Estudiante`. */
const LABEL_OVERRIDES = {
  student_id: 'ID estudiante',
  group_name: 'Grupo',
  first_name: 'Nombres',
  last_name: 'Apellidos',
  created_at: 'Registrado',
  updated_at: 'Actualizado',
  event_time: 'Fecha',
  event_timestamp: 'Fecha',
  event_type: 'Suceso',
  event_result: 'Resultado',
  risk_score: 'Riesgo',
  risk_level: 'Nivel',
  document: 'Documento',
  documento: 'Documento',
  phone: 'Teléfono',
  guardian_name: 'Acudiente',
  guardian_phone: 'Teléfono acudiente',
  grade: 'Grado',
  grade_level: 'Nivel',
  status: 'Estado',
  reason: 'Motivo',
  teacher_name: 'Docente',
  issuer: 'Registrado por',
  sender_name: 'Enviado por',
  channel: 'Canal',
  time: 'Hora',
  date: 'Fecha',
  count: 'Cantidad',
  total: 'Total',
};

export const humanizeKey = (key) =>
  LABEL_OVERRIDES[key] ??
  String(key)
    .replace(/_/g, ' ')
    .replace(/\b\w/g, (c) => c.toUpperCase());

/** Columnas visibles: excluye ruido técnico y datos sensibles innecesarios. */
export const visibleColumns = (rows) => {
  if (!Array.isArray(rows) || rows.length === 0) return [];
  const keys = new Set();
  rows.slice(0, 50).forEach((row) => Object.keys(row || {}).forEach((k) => keys.add(k)));
  return [...keys].filter(
    (k) => !HIDDEN_KEYS.includes(k) && !PII_KEYS.some((p) => k.toLowerCase().includes(p))
  );
};

const EVENT_TRANSLATIONS = {
  INGRESO_NORMAL: 'Ingreso normal',
  INGRESO_TARDE: 'Llegada tarde',
  LATE_ARRIVAL: 'Llegada tarde',
  EARLY_EXIT: 'Salida temprana',
  EVASION_INTERNA: 'Evasión interna',
  SPAM_BIOMETRIC: 'Spam biométrico',
  BIOMETRIC_FAILURE: 'Falla biométrica',
  UNAUTHORIZED_ABSENCE: 'Fuga',
  WRONG_CLASSROOM: 'Salón incorrecto',
  SOS_WEBAPP: 'Alerta SOS',
  SOS_DEVICE: 'Alerta SOS (dispositivo)',
  RISK_ALERT_HIGH: 'Riesgo alto',
  RISK_ALERT_MEDIUM: 'Riesgo medio',
  RISK_ALERT_LOW: 'Riesgo bajo',
  SUCCESS: 'Exitoso',
  FAILED: 'Fallido',
  PENDING: 'Pendiente',
  APPROVED: 'Aprobado',
  REJECTED: 'Rechazado',
  LATE: 'Tardío',
  class: 'Salida de clase',
  school: 'Salida del colegio',
  trip: 'Salida pedagógica',
};

const formatDateEs = (v) => {
  if (!v) return '';
  const d = new Date(v);
  if (isNaN(d)) return String(v);
  const dd = String(d.getDate()).padStart(2, '0');
  const mm = String(d.getMonth() + 1).padStart(2, '0');
  const yy = String(d.getFullYear()).slice(-2);
  let h = d.getHours();
  const min = String(d.getMinutes()).padStart(2, '0');
  const ampm = h >= 12 ? 'pm' : 'am';
  h = h % 12 || 12;
  return `${dd}/${mm}/${yy} a las ${h}:${min} ${ampm}`;
};

const humanizeValue = (v) => {
  if (v === null || v === undefined) return '';
  const s = String(v).trim();
  return EVENT_TRANSLATIONS[s] ?? EVENT_TRANSLATIONS[s.toUpperCase()] ?? s;
};

const isDateKey = (k) => {
  const lower = k.toLowerCase();
  return lower.includes('date') || lower.includes('_at') || lower.includes('created') || lower.includes('entry') || lower.includes('timestamp') || lower.includes('_time') || lower === 'time';
};

const isEventKey = (k) => {
  const lower = k.toLowerCase();
  return lower === 'event_type' || lower === 'event_result' || lower === 'alert_type' || lower === 'status' || lower === 'risk_level';
};

const cell = (key, value) => {
  if (value === null || value === undefined || value === '') return '';
  if (isDateKey(key)) return formatDateEs(value);
  if (isEventKey(key)) return humanizeValue(value);
  if (typeof value === 'object') return JSON.stringify(value);
  return String(value);
};

const escapeHtml = (key, value) => {
  const v = value === undefined ? key : value;
  const k = value === undefined ? '' : key;
  return cell(k, v)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;');
};

const slug = (text) =>
  String(text || 'nexo')
    .toLowerCase()
    .normalize('NFD')
    .replace(/[\u0300-\u036f]/g, '')
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/^-|-$/g, '')
    .slice(0, 60);

const download = (blob, filename) => {
  const url = URL.createObjectURL(blob);
  const link = document.createElement('a');
  link.href = url;
  link.download = filename;
  link.rel = 'noopener';
  document.body.appendChild(link);
  link.click();
  document.body.removeChild(link);
  // Revocar en el siguiente tick evita cancelar la descarga en Safari.
  setTimeout(() => URL.revokeObjectURL(url), 1000);
};

const periodLabel = (from, to) => {
  if (from && to) return `${from} a ${to}`;
  if (from) return `desde ${from}`;
  if (to) return `hasta ${to}`;
  return 'todo el periodo disponible';
};

const stamp = () =>
  new Date().toLocaleString('es-CO', { dateStyle: 'long', timeStyle: 'short' });

/**
 * Metadatos comunes de una exportación.
 * @typedef {{ title: string, rows: Array<object>, columns?: string[], from?: string, to?: string, institution?: string, actor?: string }} ExportSpec
 */

/** CSV con BOM para que Excel respete acentos. */
export const exportCsv = ({ title, rows, columns, from, to }) => {
  const cols = columns?.length ? columns : visibleColumns(rows);
  const escape = (v) => `"${cell('', v).replace(/"/g, '""')}`;
  const body = [
    cols.map((c) => escape(humanizeKey(c))).join(','),
    ...rows.map((row) => cols.map((c) => escape(cell(c, row?.[c]))).join(',')),
  ].join('\r\n');
  const blob = new Blob([`\uFEFF${body}`], { type: 'text/csv;charset=utf-8;' });
  download(blob, `nexo-${slug(title)}-${slug(periodLabel(from, to))}.csv`);
};

const tableHtml = (cols, rows) => `
  <table border="1" cellspacing="0" cellpadding="6" style="border-collapse:collapse;font-family:Calibri,Arial,sans-serif;font-size:10pt">
    <thead>
      <tr>
        ${cols
          .map(
            (c) =>
              `<th style="background:#EEF2F7;text-align:left;font-size:9pt;color:#334155">${escapeHtml(
                humanizeKey(c)
              )}</th>`
          )
          .join('')}
      </tr>
    </thead>
    <tbody>
      ${rows
        .map(
          (row) =>
            `<tr>${cols
              .map((c) => `<td style="vertical-align:top">${escapeHtml(c, row?.[c])}</td>`)
              .join('')}</tr>`
        )
        .join('')}
    </tbody>
  </table>`;

const documentHtml = ({ title, institution, actor, from, to, cols, rows }) => `<!DOCTYPE html>
<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel">
<head>
  <meta charset="utf-8" />
  <title>${escapeHtml(title)}</title>
</head>
<body style="font-family:Calibri,Arial,sans-serif;color:#0F172A">
  <p style="font-size:8pt;letter-spacing:1.4pt;color:#475569;margin:0 0 4pt">NEXO · ${escapeHtml(
    institution || 'Institución educativa'
  )}</p>
  <h1 style="font-size:16pt;margin:0 0 6pt">${escapeHtml(title)}</h1>
  <p style="font-size:9pt;color:#475569;margin:0 0 2pt">Periodo: ${escapeHtml(
    periodLabel(from, to)
  )}</p>
  <p style="font-size:9pt;color:#475569;margin:0 0 2pt">Registros: ${rows.length}</p>
  <p style="font-size:9pt;color:#475569;margin:0 0 16pt">Generado el ${escapeHtml(stamp())}${
  actor ? ` por ${escapeHtml(actor)}` : ''
}</p>
  ${tableHtml(cols, rows)}
  <p style="font-size:8pt;color:#64748B;margin-top:16pt">Documento generado por NEXO. Uso institucional interno; contiene datos de estudiantes sujetos a la política de datos sensibles.</p>
</body>
</html>`;

/** Excel: hoja HTML con MIME de Excel — se abre nativamente en Excel y LibreOffice. */
export const exportExcel = (spec) => {
  const cols = spec.columns?.length ? spec.columns : visibleColumns(spec.rows);
  const html = documentHtml({ ...spec, cols, rows: spec.rows });
  const blob = new Blob([`\uFEFF${html}`], {
    type: 'application/vnd.ms-excel;charset=utf-8;',
  });
  download(blob, `nexo-${slug(spec.title)}-${slug(periodLabel(spec.from, spec.to))}.xls`);
};

/** Word: documento HTML con MIME de Word. */
export const exportWord = (spec) => {
  const cols = spec.columns?.length ? spec.columns : visibleColumns(spec.rows);
  const html = documentHtml({ ...spec, cols, rows: spec.rows });
  const blob = new Blob([`\uFEFF${html}`], {
    type: 'application/msword;charset=utf-8;',
  });
  download(blob, `nexo-${slug(spec.title)}-${slug(periodLabel(spec.from, spec.to))}.doc`);
};

/** PDF: abre una ventana de impresión con HTML formateado — el usuario guarda como PDF. */
export const exportPdf = (spec) => {
  const cols = spec.columns?.length ? spec.columns : visibleColumns(spec.rows);
  const html = documentHtml({ ...spec, cols, rows: spec.rows });
  const w = window.open('', '_blank');
  if (!w) return;
  w.document.write(html);
  w.document.close();
  w.focus();
  setTimeout(() => w.print(), 300);
};

export const EXPORT_FORMATS = [
  { id: 'excel', label: 'Excel', extension: '.xls', run: exportExcel },
  { id: 'word',  label: 'Word',  extension: '.doc', run: exportWord },
  { id: 'pdf',   label: 'PDF',   extension: '.pdf', run: exportPdf },
];
