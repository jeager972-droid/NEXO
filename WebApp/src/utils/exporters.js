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
  event_time: 'Hora',
  risk_score: 'Riesgo',
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

const cell = (value) => {
  if (value === null || value === undefined || value === '') return '';
  if (typeof value === 'object') return JSON.stringify(value);
  return String(value);
};

const escapeHtml = (value) =>
  cell(value)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;');

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
  const escape = (v) => `"${cell(v).replace(/"/g, '""')}"`;
  const body = [
    cols.map((c) => escape(humanizeKey(c))).join(','),
    ...rows.map((row) => cols.map((c) => escape(row?.[c])).join(',')),
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
              .map((c) => `<td style="vertical-align:top">${escapeHtml(row?.[c])}</td>`)
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

export const EXPORT_FORMATS = [
  { id: 'excel', label: 'Excel', extension: '.xls', run: exportExcel },
  { id: 'word',  label: 'Word',  extension: '.doc', run: exportWord },
];
