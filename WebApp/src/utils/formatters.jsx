/**
 * Formatters / NEXO Institucional
 * Responsabilidad: Funciones de presentación reutilizables: fechas cortas y 12h,
 * etiquetas humanizadas de enums/columnas, formateo de celdas de tabla y listas de
 * columnas excluidas (PII/sensibles) que no se muestran en la UI.
 * Dependencias: React (para algunos badges JSX).
 * Exports: fmtShortDateOnly, fmt12h, ENUM_LABELS, COLUMN_LABELS, EXCLUDE_COLS,
 * humanizeColumn, formatCellValue.
 */
import React from 'react';

const MONTHS_ES = ['Ene','Feb','Mar','Abr','May','Jun','Jul','Ago','Sep','Oct','Nov','Dic'];

export function fmtShortDateOnly(iso) {
  const d = new Date(iso);
  if (isNaN(d)) return iso;
  const day = d.getDate();
  const month = MONTHS_ES[d.getMonth()];
  const year = d.getFullYear();
  return `${day} ${month} ${year}`;
}

export function fmt12h(iso) {
  if (!iso) return '—';
  if (/^\d{4}-\d{2}-\d{2}$/.test(String(iso))) {
    const [y, m, d] = String(iso).split('-');
    return `${parseInt(d)} ${MONTHS_ES[parseInt(m)-1]} ${y}`;
  }
  const d = new Date(iso);
  if (isNaN(d)) return String(iso);
  return d.toLocaleString('es-CO', {
    day: 'numeric', month: 'short', year: 'numeric',
    hour: 'numeric', minute: '2-digit', hour12: true
  });
}

export const ENUM_LABELS = {
  CHECK_IN: 'Entrada', CHECK_OUT: 'Salida', LATE_ARRIVAL: 'Llegada tarde',
  EARLY_EXIT: 'Salida anticipada', EARLY_DEPARTURE: 'Salida anticipada',
  INGRESO_NORMAL: 'Ingreso normal', INGRESO_TARDE: 'Ingreso tarde',
  WRONG_CLASSROOM: 'Salón incorrecto', EVASION_INTERNA: 'Evasión interna',
  MATCH: 'Coincidencia', NO_MATCH: 'Sin coincidencia',
  PARTIAL_MATCH: 'Coincidencia parcial', SPOOF_DETECTED: 'Intento de fraude',
  LIVENESS_FAIL: 'Prueba de vida fallida', TIMEOUT: 'Tiempo agotado',
  SUCCESS: 'Exitoso', FAILED: 'Fallido', PENDING: 'Pendiente',
  APPROVED: 'Aprobado', REJECTED: 'Rechazado', SENT: 'Enviado',
  DELIVERED: 'Entregado', UNDELIVERED: 'No entregado', READ: 'Leído',
  SOS_WEBAPP: 'Alerta SOS', SOS_DEVICE: 'Alerta SOS (dispositivo)',
  SOS_ALERT: 'Alerta SOS', PANIC: 'Pánico', ALARM: 'Alarma',
  INASISTENCIA: 'Inasistencia', UNAUTHORIZED_ABSENCE: 'Inasistencia',
  CITACION: 'Citación a acudiente', CITACION_CONFIRMADA: 'Citación confirmada',
  CITACION_REAGENDADA: 'Citación reagendada',
  AUTORIZAR_SALIDA: 'Salida autorizada', AUTORIZAR: 'Salida autorizada',
  PERMISO: 'Permiso de salida', PEDAGOGICA: 'Salida pedagógica',
  SOLICITUD: 'Solicitud interna', DAÑO: 'Daño físico',
  INCIDENTE: 'Incidente', HORARIO: 'Cambio de horario',
  NOTIFY_ROLE: 'Notificación interna',
  class: 'Salida de clase', school: 'Salida del colegio', trip: 'Salida pedagógica',
  INBOUND: 'Entrante', OUTBOUND: 'Saliente',
  RECTOR: 'Rector', COORDINATOR: 'Coordinador',
  TEACHER: 'Docente', SECRETARY: 'Secretaria', SECURITY: 'Portero',
  AUXILIARY: 'Auxiliar', COUNSELOR: 'Psicorientador',
  CRITICAL: 'Crítico', HIGH: 'Alto', MEDIUM: 'Medio', LOW: 'Bajo',
  TRUE: 'Sí', FALSE: 'No',
  COMPORTAMIENTO: 'Comportamiento', ACADEMICO: 'Académico', SALUD: 'Salud',
  DISCIPLINA: 'Disciplina', OTRO: 'Otro',
};

export const COLUMN_LABELS = {
  first_name: 'Nombre', last_name: 'Apellido', document_number: 'Documento',
  group_name: 'Grupo', event_timestamp: 'Fecha/Hora', event_type: 'Tipo',
  event_result: 'Resultado', detected_at: 'Detectado', incident_type: 'Incidente',
  permiso_type: 'Tipo', permiso_at: 'Fecha', reason: 'Motivo',
  alert_type: 'Alerta', alert_at: 'Fecha', absent_since: 'Desde',
  last_entry: 'Último ingreso', guardian_name: 'Acudiente',
  guardian_phone: 'Teléfono', status: 'Estado', message: 'Mensaje',
  exit_time: 'Hora de salida', return_time: 'Hora de retorno',
  departure_time: 'Hora de partida', authorization_reason: 'Motivo',
  issuer_first: 'Autorizado por', issuer_last: 'Apellido',
  delivery_status: 'Estado de entrega', phone_number: 'Teléfono',
  message_content: 'Mensaje', sent_at: 'Enviado', direction: 'Dirección',
  type_code: 'Tipo', intentos: 'Intentos', total_estudiantes: 'Estudiantes',
  risk_score: 'Puntuación', risk_level: 'Nivel de riesgo',
  detected: 'Detectado', destination: 'Destino', purpose: 'Propósito',
};

export const EXCLUDE_COLS = [
  'school_id','student_id','guardian_id','incident_id','alert_id','metadata_json','command_payload',
  'sync_hash','event_signature','biometric_hash','device_id','event_id','log_id','audit_id',
  'assignment_id','schedule_id','classroom_id','report_export_id','command_id','twilio_message_id',
  'relationship_id','staff_record_id','previous_data','new_data',
  'authorization_id', 'authorized_by_user_id', 'exit_authorization_id',
  'trip_authorization_id', 'tracking_id', 'provider_message_sid', 'metadata',
  'guardian_user_phone', 'sender_user_id', 'deactivated_at', 'created_at_raw', 'updated_at',
];

export function humanizeColumn(key) {
  return COLUMN_LABELS[key] || String(key).replace(/_/g, ' ').replace(/^\w/, c => c.toUpperCase());
}

export function formatCellValue(key, value) {
  if (value === null || value === undefined) return '—';
  if (typeof value === 'boolean') return value ? 'Sí' : 'No';
  const sk = String(key).toLowerCase();
  
  if (sk.includes('timestamp') || sk.includes('_at') || sk.includes('time') ||
      sk.includes('detected') || sk.includes('emitted') || sk.includes('created') ||
      sk.includes('departure') || sk.includes('return')) {
    const d = new Date(value);
    if (!isNaN(d)) return fmt12h(value);
  }
  if (sk.includes('date')) {
    const d = new Date(value);
    if (!isNaN(d)) return fmtShortDateOnly(value);
  }
  const sv = String(value).trim();
  if (sv === 'en proceso') return <span className="inline-flex items-center px-2 py-0.5 rounded text-[9px] font-bold uppercase tracking-widest bg-amber-50 text-amber-600 border border-amber-200">En Proceso</span>;
  if (sv === 'resuelto') return <span className="inline-flex items-center px-2 py-0.5 rounded text-[9px] font-bold uppercase tracking-widest bg-emerald-50 text-emerald-600 border border-emerald-200">Resuelto</span>;
  
  if (ENUM_LABELS[sv]) return ENUM_LABELS[sv];
  if (ENUM_LABELS[sv.toUpperCase()]) return ENUM_LABELS[sv.toUpperCase()];
  if (sv.length > 120) return sv.slice(0, 120) + '…';
  return sv;
}
