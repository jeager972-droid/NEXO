/**
 * ConsultationDrawer / NEXO Institucional — B-13 Drawer unificado
 * Drawer detallado de consulta por módulo: filtros, tabla dinámica y seguimiento.
 * Usa Drawer de Overlay.jsx, RiskBadge pattern, SkeletonRows, humanizeError.
 */
import { useState, useEffect, useRef } from 'react';
import { Search, Eye, AlertTriangle, Sparkles, FileSpreadsheet, FileText, FileDown } from 'lucide-react';
import { AnimatePresence } from 'framer-motion';
import { studentsApi } from '../api/students';
import { TrackingModal } from './TrackingModal';
import { useAuth } from '../hooks/useAuth';
import { ROLES } from '../config/roles';
import { Surface } from '../components/ui/Surface';
import { Input } from '../components/ui/Input';
import { Button } from '../components/ui/Button';
import { EmptyState } from '../components/ui/EmptyState';
import { SkeletonRows } from '../components/ui/Skeleton';
import { Drawer } from '../components/ui/Overlay';
import { RiskBadge } from '../components/patterns/RiskBadge';
import { SearchableSelect as GlobalSearchableSelect } from '../components/ui/SearchableSelect';
import { exportExcel, exportWord, exportPdf } from '../utils/exporters';
import { formatGroupName, formatGroupOption } from '../utils/groupFormat';

const DETAIL_MODULES = [
  'Seguimiento Estudiantil', 'Alertas', 'Seguimientos completados', 'Seguimientos',
  'Permisos', 'Permisos Emitidos', 'Permisos de Salida', 'Permisos Internos',
  'SOS Emitidos', 'Evasiones Internas', 'Situaciones Críticas', 'Daños Reportados',
  'Spam al Nodo', 'Estudiantes con Permiso',
];

const EXCLUDE_COLS = ['student_id', 'id', 'metadata', 'metadata_json', 'raw', 'event_result'];

import { GRADO_OPTIONS } from '../config/grados';

const COLUMN_LABELS_ES = {
  first_name: 'Nombres',
  last_name: 'Apellidos',
  group_name: 'Grupo',
  document: 'Número de documento',
  document_number: 'Número de documento',
  documento: 'Número de documento',
  doc: 'Número de documento',
  event_timestamp: 'Fecha',
  event_time: 'Fecha',
  event_type: 'Suceso',
  event_result: 'Resultado',
  created_at: 'Registrado',
  updated_at: 'Actualizado',
  last_entry: 'Último ingreso',
  absent_since: 'Desde',
  alert_type: 'Evento',
  alert_at: 'Fecha',
  permiso_type: 'Tipo',
  permiso_at: 'Fecha',
  reason: 'Motivo',
  status: 'Estado',
  risk_score: 'Riesgo',
  risk_level: 'Nivel',
  phone: 'Teléfono',
  guardian_name: 'Acudiente',
  guardian_phone: 'Teléfono acudiente',
  guardian_document: 'Documento acudiente',
  guardian_id: 'Documento acudiente',
  grade: 'Grado',
  grade_level: 'Nivel',
  institution_name: 'Institución',
  school_name: 'Institución',
  teacher_name: 'Docente',
  issuer: 'Registrado por',
  sender_name: 'Enviado por',
  channel: 'Canal',
  time: 'Hora',
  date: 'Fecha',
  count: 'Cantidad',
  total: 'Total',
  student_name: 'Estudiante',
  location: 'Ubicación',
  description: 'Descripción',
  message: 'Mensaje',
  motive: 'Motivo',
  sender_role: 'Rol remitente',
  recipient_name: 'Destinatario',
  recipient_role: 'Rol destinatario',
  delivery_status: 'Estado de entrega',
  sent_at: 'Enviado',
  delivered_at: 'Entregado',
  read_at: 'Leído',
  resolved_at: 'Resuelto',
  resolved_by: 'Resuelto por',
  resolution_time: 'Tiempo de resolución',
  active: 'Activo',
  inactive: 'Inactivo',
  entry_time: 'Hora de ingreso',
  exit_time: 'Hora de salida',
  type: 'Tipo',
  notes: 'Notas',
  category: 'Categoría',
  severity: 'Severidad',
  start_time: 'Hora de inicio',
  end_time: 'Hora de fin',
  day: 'Día',
  shift: 'Jornada',
  role: 'Rol',
  email: 'Correo',
  full_name: 'Nombre completo',
  user_id: 'Usuario',
  device_id: 'Dispositivo',
  device_name: 'Nombre del dispositivo',
  action: 'Acción',
  target: 'Objetivo',
  result: 'Resultado',
  duration: 'Duración',
  ip_address: 'Dirección IP',
  user_agent: 'Navegador',
  session_id: 'Sesión',
  method: 'Método',
  endpoint: 'Ruta',
  status_code: 'Código de estado',
  response_time: 'Tiempo de respuesta',
  request_id: 'ID de solicitud',
  error_message: 'Mensaje de error',
  stack_trace: 'Traza de error',
  entity_type: 'Tipo de entidad',
  entity_id: 'ID de entidad',
  old_value: 'Valor anterior',
  new_value: 'Valor nuevo',
  changed_by: 'Modificado por',
  changed_at: 'Modificado',
  permissions: 'Permisos',
  is_active: 'Activo',
  last_login: 'Último acceso',
  created_by: 'Creado por',
  updated_by: 'Actualizado por',
  deleted_at: 'Eliminado',
  parent_name: 'Acudiente',
  parent_phone: 'Teléfono acudiente',
  parent_document: 'Documento acudiente',
  student_id: 'Estudiante',
  tracking_id: 'Seguimiento',
  start_date: 'Fecha de inicio',
  end_date: 'Fecha de fin',
  approved_by: 'Aprobado por',
  approved_at: 'Aprobado',
  rejected_by: 'Rechazado por',
  rejected_at: 'Rechazado',
  pending: 'Pendiente',
  completed: 'Completado',
  completed_at: 'Completado',
  in_progress: 'En progreso',
  cancelled: 'Cancelado',
  cancelled_at: 'Cancelado',
  priority: 'Prioridad',
  assigned_to: 'Asignado a',
  assigned_by: 'Asignado por',
  assigned_at: 'Asignado',
  due_date: 'Fecha límite',
  closed_at: 'Cerrado',
  closed_by: 'Cerrado por',
  resolution_notes: 'Notas de resolución',
  feedback: 'Retroalimentación',
  rating: 'Calificación',
  comment: 'Comentario',
  comments: 'Comentarios',
  attachment: 'Adjunto',
  attachments: 'Adjuntos',
  file_name: 'Nombre del archivo',
  file_size: 'Tamaño del archivo',
  file_type: 'Tipo de archivo',
  uploaded_at: 'Subido',
  uploaded_by: 'Subido por',
  downloaded_at: 'Descargado',
  shared_with: 'Compartido con',
  shared_by: 'Compartido por',
  shared_at: 'Compartido',
  access_level: 'Nivel de acceso',
  granted_by: 'Concedido por',
  granted_at: 'Concedido',
  revoked_by: 'Revocado por',
  revoked_at: 'Revocado',
  expires_at: 'Expira',
  expired: 'Expirado',
  valid: 'Válido',
  invalid: 'Inválido',
  verified: 'Verificado',
  unverified: 'No verificado',
  confirmed: 'Confirmado',
  unconfirmed: 'No confirmado',
  locked: 'Bloqueado',
  unlocked: 'Desbloqueado',
  enabled: 'Habilitado',
  disabled: 'Deshabilitado',
  visible: 'Visible',
  hidden: 'Oculto',
  public: 'Público',
  private: 'Privado',
  internal: 'Interno',
  external: 'Externo',
  incoming: 'Entrante',
  outgoing: 'Saliente',
  missed: 'Perdido',
  returned: 'Devuelto',
  forwarded: 'Reenviado',
  replied: 'Respondido',
  unread: 'No leído',
  read: 'Leído',
  archived: 'Archivado',
  pinned: 'Fijado',
  starred: 'Destacado',
  labeled: 'Etiquetado',
  filtered: 'Filtrado',
  sorted: 'Ordenado',
  grouped: 'Agrupado',
  merged: 'Combinado',
  split: 'Dividido',
  duplicated: 'Duplicado',
  original: 'Original',
  copy: 'Copia',
  source: 'Origen',
  destination: 'Destino',
  origin: 'Origen',
  target_name: 'Nombre del objetivo',
  target_type: 'Tipo de objetivo',
  target_id: 'ID del objetivo',
  source_name: 'Nombre del origen',
  source_type: 'Tipo de origen',
  source_id: 'ID del origen',
  reference: 'Referencia',
  reference_id: 'ID de referencia',
  reference_type: 'Tipo de referencia',
  reference_name: 'Nombre de referencia',
  external_id: 'ID externo',
  external_ref: 'Referencia externa',
  external_source: 'Origen externo',
  external_url: 'URL externa',
  url: 'URL',
  link: 'Enlace',
  path: 'Ruta',
  route: 'Ruta',
  page: 'Página',
  section: 'Sección',
  tab: 'Pestaña',
  field: 'Campo',
  value: 'Valor',
  label_text: 'Etiqueta',
  title: 'Título',
  subtitle: 'Subtítulo',
  description_text: 'Descripción',
  summary: 'Resumen',
  details: 'Detalles',
  notes_text: 'Notas',
  content: 'Contenido',
  body: 'Cuerpo',
  text: 'Texto',
  html: 'HTML',
  format: 'Formato',
  language: 'Idioma',
  locale: 'Localización',
  timezone: 'Zona horaria',
  currency: 'Moneda',
  amount: 'Cantidad',
  price: 'Precio',
  cost: 'Costo',
  discount: 'Descuento',
  tax: 'Impuesto',
  subtotal: 'Subtotal',
  total_amount: 'Total',
  balance: 'Saldo',
  paid: 'Pagado',
  unpaid: 'No pagado',
  refunded: 'Reembolsado',
  charged: 'Cobrado',
  fee: 'Tarifa',
  rate: 'Tasa',
  unit: 'Unidad',
  quantity: 'Cantidad',
  unit_price: 'Precio unitario',
  line_total: 'Total de línea',
  invoice_number: 'Número de factura',
  invoice_id: 'Factura',
  payment_method: 'Método de pago',
  payment_status: 'Estado del pago',
  payment_date: 'Fecha de pago',
  transaction_id: 'Transacción',
  transaction_type: 'Tipo de transacción',
  account_number: 'Número de cuenta',
  account_name: 'Nombre de cuenta',
  bank_name: 'Banco',
  branch: 'Sucursal',
  routing_number: 'Número de ruta',
  swift_code: 'Código SWIFT',
  iban: 'IBAN',
  card_number: 'Número de tarjeta',
  card_type: 'Tipo de tarjeta',
  card_holder: 'Titular de tarjeta',
  expiry_date: 'Fecha de expiración',
  cvv: 'CVV',
  billing_address: 'Dirección de facturación',
  shipping_address: 'Dirección de envío',
  tracking_number: 'Número de seguimiento',
  carrier: 'Transportista',
  shipped_at: 'Enviado',
  delivered: 'Entregado',
  delivery_date: 'Fecha de entrega',
  estimated_delivery: 'Entrega estimada',
  actual_delivery: 'Entrega real',
  shipping_method: 'Método de envío',
  shipping_cost: 'Costo de envío',
  weight: 'Peso',
  dimensions: 'Dimensiones',
  color: 'Color',
  size: 'Tamaño',
  material: 'Material',
  brand: 'Marca',
  model: 'Modelo',
  serial_number: 'Número de serie',
  sku: 'SKU',
  barcode: 'Código de barras',
  inventory: 'Inventario',
  stock: 'Existencias',
  in_stock: 'En existencia',
  out_of_stock: 'Agotado',
  reorder_level: 'Nivel de reorden',
  reorder_quantity: 'Cantidad de reorden',
  supplier: 'Proveedor',
  manufacturer: 'Fabricante',
  warehouse: 'Almacén',
  location_code: 'Código de ubicación',
  aisle: 'Pasillo',
  shelf: 'Estante',
  bin: 'Contenedor',
  batch_number: 'Número de lote',
  lot_number: 'Número de lote',
  expiration_date: 'Fecha de caducidad',
  manufacture_date: 'Fecha de fabricación',
  received_date: 'Fecha de recepción',
  ordered_date: 'Fecha de pedido',
  expected_date: 'Fecha esperada',
  actual_date: 'Fecha real',
  due: 'Pendiente',
  overdue: 'Vencido',
  scheduled: 'Programado',
  rescheduled: 'Reprogramado',
  postponed: 'Pospuesto',
  canceled: 'Cancelado',
  no_show: 'No asistió',
  attended: 'Asistió',
  registered: 'Registrado',
  enrolled: 'Matriculado',
  withdrawn: 'Retirado',
  graduated: 'Graduado',
  transferred: 'Transferido',
  promoted: 'Promovido',
  retained: 'Reprobado',
  passed: 'Aprobado',
  failed: 'Reprobado',
  incomplete: 'Incompleto',
  in_attendance: 'Presente',
  absent: 'Ausente',
  late: 'Tarde',
  excused: 'Justificado',
  unexcused: 'Injustificado',
  present: 'Presente',
  attendance_status: 'Estado de asistencia',
  attendance_rate: 'Tasa de asistencia',
  absence_count: 'Inasistencias',
  late_count: 'Llegadas tarde',
  excused_count: 'Justificadas',
  unexcused_count: 'Injustificadas',
  total_absences: 'Total de inasistencias',
  total_lates: 'Total de llegadas tarde',
  total_excused: 'Total de justificadas',
  total_unexcused: 'Total de injustificadas',
  total_present: 'Total de presentes',
  total_absent: 'Total de ausentes',
  total_late: 'Total de llegadas tarde',
  total_days: 'Total de días',
  days_present: 'Días presentes',
  days_absent: 'Días ausentes',
  days_late: 'Días con llegada tarde',
  days_excused: 'Días justificados',
  days_unexcused: 'Días injustificados',
  percentage: 'Porcentaje',
  average: 'Promedio',
  median: 'Mediana',
  mode: 'Moda',
  min: 'Mínimo',
  max: 'Máximo',
  range: 'Rango',
  variance: 'Varianza',
  standard_deviation: 'Desviación estándar',
  trend: 'Tendencia',
  change: 'Cambio',
  change_percent: 'Cambio porcentual',
  previous: 'Anterior',
  current: 'Actual',
  next: 'Siguiente',
  first: 'Primero',
  last: 'Último',
  name: 'Nombre',
  username: 'Usuario',
  password: 'Contraseña',
  role_name: 'Rol',
  role_id: 'Rol',
  group_id: 'Grupo',
  grade_id: 'Grado',
  student_doc: 'Documento del estudiante',
  student_name_full: 'Nombre del estudiante',
  teacher_id: 'Docente',
  teacher_doc: 'Documento del docente',
  teacher_email: 'Correo del docente',
  teacher_phone: 'Teléfono del docente',
  staff_id: 'Personal',
  staff_name: 'Nombre del personal',
  staff_role: 'Rol del personal',
  staff_doc: 'Documento del personal',
  staff_phone: 'Teléfono del personal',
  staff_email: 'Correo del personal',
  device: 'Dispositivo',
  sensor: 'Sensor',
  sensor_id: 'Sensor',
  sensor_name: 'Nombre del sensor',
  sensor_type: 'Tipo de sensor',
  sensor_status: 'Estado del sensor',
  sensor_location: 'Ubicación del sensor',
  sensor_reading: 'Lectura del sensor',
  sensor_unit: 'Unidad del sensor',
  sensor_value: 'Valor del sensor',
  sensor_timestamp: 'Fecha del sensor',
  sensor_battery: 'Batería del sensor',
  sensor_signal: 'Señal del sensor',
  sensor_firmware: 'Firmware del sensor',
  sensor_hardware: 'Hardware del sensor',
  sensor_manufacturer: 'Fabricante del sensor',
  sensor_model: 'Modelo del sensor',
  sensor_serial: 'Número de serie del sensor',
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

const humanizeColumn = (k) => COLUMN_LABELS_ES[k] ?? String(k).replace(/_/g, ' ').replace(/\b\w/g, (l) => l.toUpperCase());

const formatDateEs = (v) => {
  if (!v) return '—';
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
  if (v === null || v === undefined) return '—';
  const s = String(v).trim();
  return EVENT_TRANSLATIONS[s] ?? EVENT_TRANSLATIONS[s.toUpperCase()] ?? s;
};

const formatCellValue = (k, v) => {
  if (v === null || v === undefined) return '—';
  const lower = k.toLowerCase();
  if (lower === 'group_name' || lower === 'group' || lower === 'grupo') {
    return formatGroupName(String(v));
  }
  if (lower.includes('date') || lower.includes('_at') || lower.includes('created') || lower.includes('entry') || lower.includes('timestamp') || lower.includes('_time') || lower === 'time') {
    return formatDateEs(v);
  }
  if (lower === 'event_type' || lower === 'event_result' || lower === 'alert_type' || lower === 'status' || lower === 'risk_level') {
    return humanizeValue(v);
  }
  return String(v);
};

const sortRowsAlpha = (rows) => {
  if (!Array.isArray(rows) || rows.length === 0) return rows;
  const hasName = rows.some(r => r.last_name || r.first_name);
  if (!hasName) return rows;
  return [...rows].sort((a, b) => {
    const la = (a.last_name || '').toLowerCase();
    const lb = (b.last_name || '').toLowerCase();
    if (la !== lb) return la.localeCompare(lb, 'es');
    return (a.first_name || '').toLowerCase().localeCompare((b.first_name || '').toLowerCase(), 'es');
  });
};

const riskLevelMap = { CRITICAL: 'critico', MEDIUM: 'medio', LOW: 'bajo', HIGH: 'alto' };

const SearchableSelect = ({ label, options, value, onChange, placeholder, loading }) => {
  const opts = options.map((o) => ({ value: o.id, label: o.name }));
  return (
    <GlobalSearchableSelect
      label={label}
      options={opts}
      value={value || ''}
      onChange={onChange}
      placeholder={loading ? 'Cargando…' : placeholder}
      clearable
    />
  );
};

const ExportActions = ({ rows, columns, item, fromDate, toDate, canExport }) => {
  if (!rows || rows.length === 0 || !canExport) return null;
  const spec = { title: item, rows, columns, from: fromDate, to: toDate };
  const btnBase = 'flex items-center gap-1.5 rounded-control px-3 py-1.5 text-caption font-medium transition-all duration-fast hover:shadow-small';
  return (
    <div className="flex flex-wrap items-center gap-2 border-b border-[var(--nx-border)] pb-3 mb-3">
      <span className="text-caption text-[var(--nx-text-muted)]">Descargar:</span>
      <button onClick={() => exportExcel(spec)} className={`${btnBase} bg-[var(--nx-subtle-bg-success)] text-[var(--nx-success)] border border-[var(--nx-border-success)] hover:bg-[color-mix(in_oklch,var(--nx-success)_12%,transparent)]`}>
        <FileSpreadsheet size={14} /> Excel
      </button>
      <button onClick={() => exportWord(spec)} className={`${btnBase} bg-[var(--nx-subtle-bg-accent)] text-[var(--nx-accent)] border border-[var(--nx-border-accent)] hover:bg-[color-mix(in_oklch,var(--nx-accent)_12%,transparent)]`}>
        <FileText size={14} /> Word
      </button>
      <button onClick={() => exportPdf(spec)} className={`${btnBase} bg-[var(--nx-subtle-bg-danger)] text-[var(--nx-danger)] border border-[var(--nx-border-danger)] hover:bg-[color-mix(in_oklch,var(--nx-danger)_12%,transparent)]`}>
        <FileDown size={14} /> PDF
      </button>
    </div>
  );
};

const TeacherQueryPanel = ({
  item, groups, selectedGroup, setSelectedGroup, selectedGrade, setSelectedGrade, selectedStudent, setSelectedStudent,
  fromDate, setFromDate, toDate, setToDate, onQuery, loadingData, hasQueried, dynamicData, error, canExport
}) => {
  const [students, setStudents] = useState([]);
  const [studentsLoading, setStudentsLoading] = useState(false);
  const rows = sortRowsAlpha(dynamicData);
  const visibleKeys = rows.length > 0 ? Object.keys(rows[0]).filter((k) => !EXCLUDE_COLS.includes(k)) : [];

  useEffect(() => {
    if (!selectedGroup) { setStudents([]); setSelectedStudent(''); return; }
    setStudentsLoading(true);
    studentsApi.getAll({ limit: 100, group_name: selectedGroup })
      .then((res) => setStudents(res.students || []))
      .catch(() => setStudents([]))
      .finally(() => setStudentsLoading(false));
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [selectedGroup]);

  const filteredGroups = selectedGrade
    ? groups.filter((g) => {
        const gName = g.name || g.group_name || g;
        return String(gName).startsWith(selectedGrade) || g.grade_level === selectedGrade;
      })
    : groups;
  const groupOptions = filteredGroups.map((g) => ({ value: g.name || g.group_name || g, label: formatGroupOption(g) }));
  const studentOptions = [...students].sort((a, b) => (a.last_name || '').localeCompare(b.last_name || '', 'es')).map((s) => ({ value: String(s.id || s.student_id), label: `${s.last_name || ''} ${s.first_name || ''}`.trim() }));

  return (
    <div className="flex flex-col">
      <Surface className="border-b border-[var(--nx-border)] p-5 space-y-4 rounded-none">
        <div className="flex items-center gap-2 border-b border-[var(--nx-border)] pb-3">
          <div className="h-6 w-0.5 rounded-full bg-[var(--nx-accent)]" />
          <p className="text-label text-[var(--nx-text)]">Filtros de consulta</p>
        </div>
        <SearchableSelect label="Grado" placeholder="Seleccionar grado…" options={GRADO_OPTIONS} value={selectedGrade} onChange={(v) => { setSelectedGrade(v); setSelectedGroup(''); setSelectedStudent(''); }} />
        <SearchableSelect label="Grupo" placeholder="Seleccionar grupo…" options={groupOptions} value={selectedGroup} onChange={(v) => { setSelectedGroup(v); setSelectedStudent(''); }} />
        <SearchableSelect label="Estudiante" placeholder={!selectedGroup ? 'Primero seleccione un grupo' : 'Todos los estudiantes del grupo'} options={studentOptions} value={selectedStudent} onChange={(v) => setSelectedStudent(v)} loading={studentsLoading} />
        <div className="grid grid-cols-2 gap-3">
          <Input type="date" label="Desde" value={fromDate} onChange={(e) => setFromDate(e.target.value)} />
          <Input type="date" label="Hasta" value={toDate} onChange={(e) => setToDate(e.target.value)} />
        </div>
        <Button onClick={onQuery} loading={loadingData} disabled={!selectedGroup} leftIcon={<Eye size={16} />}>Consultar</Button>
      </Surface>

      <div className="p-5">
        {error ? (
          <EmptyState icon={<AlertTriangle size={32} className="text-[var(--nx-danger)]" />} title="Error de consulta" description={error} />
        ) : !hasQueried && !loadingData ? (
          <EmptyState icon={<Sparkles size={32} className="text-[var(--nx-success)]" />} title="No hay nada para mostrar." description="Selecciona un grupo y un rango de fechas, luego presiona Consultar." />
        ) : loadingData && rows.length === 0 ? (
          <SkeletonRows count={4} />
        ) : rows.length === 0 ? (
          <EmptyState icon={<Sparkles size={32} className="text-[var(--nx-success)]" />} title="No hay nada para mostrar." description={`No se encontraron registros para ${item} en el grupo y período seleccionado.`} />
        ) : (
          <Surface className="overflow-x-auto p-5">
            <ExportActions rows={rows} columns={visibleKeys} item={item} fromDate={fromDate} toDate={toDate} canExport={canExport} />
            <table className="w-full min-w-[500px]">
              <thead>
                <tr className="border-b border-[var(--nx-border)] bg-[var(--nx-surface-subtle)]">
                  {visibleKeys.map((k) => <th key={k} className="px-4 py-3 text-left text-caption font-medium uppercase text-[var(--nx-text-muted)] whitespace-nowrap">{humanizeColumn(k)}</th>)}
                </tr>
              </thead>
              <tbody className="divide-y divide-[var(--nx-border)]">
                {rows.map((row, i) => (
                  <tr key={i} className="hover:bg-[var(--nx-surface-subtle)]">
                    {visibleKeys.map((k) => <td key={k} className="px-4 py-3 text-body-sm text-[var(--nx-text)] whitespace-nowrap max-w-[200px] truncate">{formatCellValue(k, row[k])}</td>)}
                  </tr>
                ))}
              </tbody>
            </table>
          </Surface>
        )}
      </div>
    </div>
  );
};

const AdminFilterPanel = ({
  groups, selectedGroup, setSelectedGroup, selectedGrade, setSelectedGrade, selectedStudent, setSelectedStudent,
  fromDate, setFromDate, toDate, setToDate, onQuery, loadingData,
}) => {
  const [students, setStudents] = useState([]);
  const [studentsLoading, setStudentsLoading] = useState(false);

  useEffect(() => {
    if (!selectedGroup) { setStudents([]); setSelectedStudent(''); return; }
    setStudentsLoading(true);
    studentsApi.getAll({ limit: 100, group_name: selectedGroup })
      .then((res) => setStudents(res.students || []))
      .catch(() => setStudents([]))
      .finally(() => setStudentsLoading(false));
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [selectedGroup]);

  const filteredGroups = selectedGrade
    ? groups.filter((g) => {
        const gName = g.name || g.group_name || g;
        return String(gName).startsWith(selectedGrade) || g.grade_level === selectedGrade;
      })
    : groups;
  const groupOptions = filteredGroups.map((g) => ({ value: g.name || g.group_name || g, label: formatGroupOption(g) }));
  const studentOptions = [...students].sort((a, b) => (a.last_name || '').localeCompare(b.last_name || '', 'es')).map((s) => ({ value: String(s.id || s.student_id), label: `${s.last_name || ''} ${s.first_name || ''}`.trim() }));

  return (
    <Surface className="border-b border-[var(--nx-border)] p-5 space-y-4 rounded-none">
      <div className="flex items-center gap-2 border-b border-[var(--nx-border)] pb-3">
        <div className="h-6 w-0.5 rounded-full bg-[var(--nx-accent)]" />
        <p className="text-label text-[var(--nx-text)]">Filtros de consulta</p>
      </div>
      <SearchableSelect label="Grado" placeholder="Todos los grados" options={GRADO_OPTIONS} value={selectedGrade} onChange={(v) => { setSelectedGrade(v); setSelectedGroup(''); setSelectedStudent(''); }} />
      <SearchableSelect label="Grupo" placeholder="Todos los grupos" options={groupOptions} value={selectedGroup} onChange={(v) => { setSelectedGroup(v); setSelectedStudent(''); }} />
      <SearchableSelect label="Estudiante (opcional)" placeholder="Todos los estudiantes" options={studentOptions} value={selectedStudent} onChange={(v) => setSelectedStudent(v)} loading={studentsLoading} />
      <div className="grid grid-cols-2 gap-3">
        <Input type="date" label="Desde" value={fromDate} onChange={(e) => setFromDate(e.target.value)} />
        <Input type="date" label="Hasta" value={toDate} onChange={(e) => setToDate(e.target.value)} />
      </div>
      <Button onClick={onQuery} loading={loadingData} leftIcon={<Eye size={16} />}>Consultar</Button>
    </Surface>
  );
};

export const ConsultationDrawer = ({
  item, riskStudents, dynamicData, dynamicColumns, loadingData,
  isTeacherModule, hasQueried, groups, selectedGroup, setSelectedGroup,
  selectedGrade, setSelectedGrade,
  selectedStudent, setSelectedStudent, fromDate, setFromDate, toDate, setToDate,
  onQuery, onClose, error, executeQuery
}) => {
  const { user } = useAuth();
  const keys = Object.keys(dynamicColumns);
  const [trackingModalOpen, setTrackingModalOpen] = useState(false);
  const [selectedTrackingTarget, setSelectedTrackingTarget] = useState(null);

  const isAdminRole = user?.role === ROLES.RECTOR || user?.role === ROLES.COORDINADOR || user?.role === ROLES.SECRETARIA;
  const canExport = user?.role === ROLES.RECTOR;
  const showFilters = isTeacherModule || isAdminRole;

  const openTracking = (studentId, studentName, trackingId = null, metadata = null) => {
    setSelectedTrackingTarget({ studentId, studentName, trackingId, metadata });
    setTrackingModalOpen(true);
  };

  return (
    <>
      <Drawer
        title={showFilters ? 'Consulta de datos institucionales' : item}
        context={showFilters ? item : undefined}
        onClose={onClose}
        size="lg"
      >
        {isTeacherModule ? (
          <TeacherQueryPanel
            item={item} groups={groups} selectedGroup={selectedGroup} setSelectedGroup={setSelectedGroup}
            selectedGrade={selectedGrade} setSelectedGrade={setSelectedGrade}
            selectedStudent={selectedStudent} setSelectedStudent={setSelectedStudent}
            fromDate={fromDate} setFromDate={setFromDate} toDate={toDate} setToDate={setToDate}
            onQuery={onQuery} loadingData={loadingData} hasQueried={hasQueried} dynamicData={dynamicData} error={error} canExport={canExport}
          />
        ) : isAdminRole ? (
          <div className="flex flex-col">
            <AdminFilterPanel
              groups={groups} selectedGroup={selectedGroup} setSelectedGroup={setSelectedGroup}
              selectedGrade={selectedGrade} setSelectedGrade={setSelectedGrade}
              selectedStudent={selectedStudent} setSelectedStudent={setSelectedStudent}
              fromDate={fromDate} setFromDate={setFromDate} toDate={toDate} setToDate={setToDate}
              onQuery={onQuery} loadingData={loadingData}
            />
            <div className="p-5">
              {error && !loadingData ? (
                <EmptyState icon={<AlertTriangle size={32} className="text-[var(--nx-danger)]" />} title="Error de consulta" description={error} />
              ) : loadingData ? (
                <SkeletonRows count={4} />
              ) : !hasQueried ? (
                <EmptyState icon={<Sparkles size={32} className="text-[var(--nx-success)]" />} title="No hay nada para mostrar." description="Selecciona un grupo y un rango de fechas, luego presiona Consultar." />
              ) : item === 'Análisis de Riesgo' && riskStudents.length > 0 ? (
              <Surface className="overflow-x-auto p-5">
                <ExportActions rows={riskStudents} columns={['last_name', 'first_name', 'group_name', 'risk_score', 'risk_level']} item={item} fromDate={fromDate} toDate={toDate} canExport={canExport} />
                <table className="w-full min-w-[440px]">
                  <thead>
                    <tr className="border-b border-[var(--nx-border)] bg-[var(--nx-surface-subtle)]">
                      {['Estudiante', 'Grupo', 'Score', 'Nivel', 'Acción'].map((h) => <th key={h} className="px-4 py-3 text-left text-caption font-medium uppercase text-[var(--nx-text-muted)]">{h}</th>)}
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-[var(--nx-border)]">
                    {[...riskStudents].sort((a, b) => (a.last_name || '').localeCompare(b.last_name || '', 'es')).map((s) => (
                      <tr key={s.student_id} className="hover:bg-[var(--nx-surface-subtle)]">
                        <td className="px-4 py-3 text-body text-[var(--nx-text)]">{s.last_name} {s.first_name}</td>
                        <td className="px-4 py-3 text-body-sm text-[var(--nx-text-muted)]">{s.group_name}</td>
                        <td className="px-4 py-3 text-body font-mono" style={{ color: s.risk_score >= 85 ? 'var(--nx-danger)' : 'var(--nx-warning)' }}>{s.risk_score}</td>
                        <td className="px-4 py-3"><RiskBadge level={riskLevelMap[s.risk_level] || 'bajo'} /></td>
                        <td className="px-4 py-3 text-right">
                          <Button size="sm" variant="quiet" onClick={() => openTracking(s.student_id, `${s.last_name} ${s.first_name}`, null, { risk_score: s.risk_score, absence_count: s.absence_count, late_count: s.late_count })}>Seguimiento</Button>
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </Surface>
            ) : dynamicData.length > 0 ? (
              <Surface className="overflow-x-auto p-5">
                <ExportActions rows={dynamicData} columns={keys} item={item} fromDate={fromDate} toDate={toDate} canExport={canExport} />
                <table className="w-full min-w-[500px]">
                  <thead>
                    <tr className="border-b border-[var(--nx-border)] bg-[var(--nx-surface-subtle)]">
                      {keys.map((k) => <th key={k} className="px-4 py-3 text-left text-caption font-medium uppercase text-[var(--nx-text-muted)] whitespace-nowrap">{humanizeColumn(k)}</th>)}
                      {DETAIL_MODULES.includes(item) && <th className="px-4 py-3 text-left text-caption font-medium uppercase text-[var(--nx-text-muted)]">Acción</th>}
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-[var(--nx-border)]">
                    {sortRowsAlpha(dynamicData).map((row, i) => (
                      <tr key={i} className="hover:bg-[var(--nx-surface-subtle)]">
                        {keys.map((k) => <td key={k} className="px-4 py-3 text-body-sm text-[var(--nx-text)] whitespace-nowrap max-w-[200px] truncate">{formatCellValue(k, row[k])}</td>)}
                        {DETAIL_MODULES.includes(item) && row.student_id && (
                          <td className="px-4 py-3">
                            <Button size="sm" variant="quiet" onClick={() => openTracking(row.student_id, `${row.last_name} ${row.first_name}`, row.tracking_id, row.metadata_json)}>Ver detalles</Button>
                          </td>
                        )}
                      </tr>
                    ))}
                  </tbody>
                </table>
              </Surface>
            ) : (
              <EmptyState icon={<Sparkles size={32} className="text-[var(--nx-success)]" />} title="No hay nada para mostrar." description="No se encontraron registros para este módulo." />
            )}
            </div>
          </div>
        ) : (
          <div className="flex-1 overflow-y-auto p-6">
            {error && !loadingData ? (
              <EmptyState icon={<AlertTriangle size={32} className="text-[var(--nx-danger)]" />} title="Error de consulta" description={error} />
            ) : loadingData ? (
              <SkeletonRows count={4} />
            ) : item === 'Análisis de Riesgo' && riskStudents.length > 0 ? (
              <Surface className="overflow-x-auto p-5">
                <ExportActions rows={riskStudents} columns={['last_name', 'first_name', 'group_name', 'risk_score', 'risk_level']} item={item} fromDate={fromDate} toDate={toDate} canExport={canExport} />
                <table className="w-full min-w-[440px]">
                  <thead>
                    <tr className="border-b border-[var(--nx-border)] bg-[var(--nx-surface-subtle)]">
                      {['Estudiante', 'Grupo', 'Score', 'Nivel', 'Acción'].map((h) => <th key={h} className="px-4 py-3 text-left text-caption font-medium uppercase text-[var(--nx-text-muted)]">{h}</th>)}
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-[var(--nx-border)]">
                    {[...riskStudents].sort((a, b) => (a.last_name || '').localeCompare(b.last_name || '', 'es')).map((s) => (
                      <tr key={s.student_id} className="hover:bg-[var(--nx-surface-subtle)]">
                        <td className="px-4 py-3 text-body text-[var(--nx-text)]">{s.last_name} {s.first_name}</td>
                        <td className="px-4 py-3 text-body-sm text-[var(--nx-text-muted)]">{s.group_name}</td>
                        <td className="px-4 py-3 text-body font-mono" style={{ color: s.risk_score >= 85 ? 'var(--nx-danger)' : 'var(--nx-warning)' }}>{s.risk_score}</td>
                        <td className="px-4 py-3"><RiskBadge level={riskLevelMap[s.risk_level] || 'bajo'} /></td>
                        <td className="px-4 py-3 text-right">
                          <Button size="sm" variant="quiet" onClick={() => openTracking(s.student_id, `${s.last_name} ${s.first_name}`, null, { risk_score: s.risk_score, absence_count: s.absence_count, late_count: s.late_count })}>Seguimiento</Button>
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </Surface>
            ) : item !== 'Análisis de Riesgo' && dynamicData.length > 0 ? (
              <Surface className="overflow-x-auto p-5">
                <ExportActions rows={dynamicData} columns={keys} item={item} fromDate={fromDate} toDate={toDate} canExport={canExport} />
                <table className="w-full min-w-[500px]">
                  <thead>
                    <tr className="border-b border-[var(--nx-border)] bg-[var(--nx-surface-subtle)]">
                      {keys.map((k) => <th key={k} className="px-4 py-3 text-left text-caption font-medium uppercase text-[var(--nx-text-muted)]">{humanizeColumn(k)}</th>)}
                      {DETAIL_MODULES.includes(item) && user?.role !== ROLES.DOCENTE && <th className="px-4 py-3 text-left text-caption font-medium uppercase text-[var(--nx-text-muted)]">Acción</th>}
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-[var(--nx-border)]">
                    {sortRowsAlpha(dynamicData).map((row, i) => (
                      <tr key={i} className="hover:bg-[var(--nx-surface-subtle)]">
                        {keys.map((k) => <td key={k} className="px-4 py-3 text-body-sm text-[var(--nx-text)] whitespace-nowrap max-w-[200px] truncate">{formatCellValue(k, row[k])}</td>)}
                        {DETAIL_MODULES.includes(item) && user?.role !== ROLES.DOCENTE && row.student_id && (
                          <td className="px-4 py-3">
                            <Button size="sm" variant="quiet" onClick={() => openTracking(row.student_id, `${row.last_name} ${row.first_name}`, row.tracking_id, row.metadata_json)}>Ver detalles</Button>
                          </td>
                        )}
                      </tr>
                    ))}
                  </tbody>
                </table>
              </Surface>
            ) : (
              <EmptyState icon={<Sparkles size={32} className="text-[var(--nx-success)]" />} title="No hay nada para mostrar." description="No se encontraron registros para este módulo." />
            )}
          </div>
        )}
      </Drawer>

      <AnimatePresence>
        {trackingModalOpen && selectedTrackingTarget && (
          <TrackingModal
            trackingId={selectedTrackingTarget.trackingId}
            studentId={selectedTrackingTarget.studentId}
            studentName={selectedTrackingTarget.studentName}
            metadata={selectedTrackingTarget.metadata}
            onClose={() => setTrackingModalOpen(false)}
            onRefresh={() => { if (typeof executeQuery === 'function') executeQuery(); else if (typeof onQuery === 'function') onQuery(); }}
          />
        )}
      </AnimatePresence>
    </>
  );
};
