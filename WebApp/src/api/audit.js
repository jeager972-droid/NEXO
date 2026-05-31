import client from './client';

const get = async (path) => {
  const response = await client.get(path);
  return response.data ?? {};
};

export const auditApi = {
  // Legacy
  getGlobalLogs: () => get('/audit/global'),
  getIntegrity: () => get('/audit/integrity'),

  // 1. Asistencia
  getAttendanceGeneral: () => get('/audit/attendance/general'),
  getAttendanceAbsences: () => get('/audit/attendance/absences'),
  getAttendanceLates: () => get('/audit/attendance/lates'),
  getAttendanceEvasion: () => get('/audit/attendance/evasion'),
  getAttendanceByGroup: () => get('/audit/attendance/by-group'),
  getAttendanceByStudent: (q) => get(`/audit/attendance/by-student?q=${encodeURIComponent(q || '')}`),

  // 2. Disciplina
  getDisciplineIncidents: () => get('/audit/discipline/incidents'),
  getDisciplineViolations: () => get('/audit/discipline/violations'),
  getDisciplineWrongClassroom: () => get('/audit/discipline/wrong-classroom'),
  getDisciplineBiometricSpam: () => get('/audit/discipline/biometric-spam'),
  getDisciplineReports: () => get('/audit/discipline/reports'),
  getDisciplineStudentHistory: (studentId) => get(`/audit/discipline/student-history?student_id=${studentId}`),

  // 3. Permisos y Salidas
  getPermissionsClassExits: () => get('/audit/permissions/class-exits'),
  getPermissionsSchoolExits: () => get('/audit/permissions/school-exits'),
  getPermissionsPedagogical: () => get('/audit/permissions/pedagogical'),
  getPermissionsPendingReturns: () => get('/audit/permissions/pending-returns'),
  getPermissionsHistory: (from, to) => get(`/audit/permissions/history?from=${from}&to=${to}`),

  // 4. Mensajería
  getMessagingWhatsAppSent: () => get('/audit/messaging/whatsapp-sent'),
  getMessagingGuardianReplies: () => get('/audit/messaging/guardian-replies'),
  getMessagingFailed: () => get('/audit/messaging/failed'),
  getMessagingCitations: () => get('/audit/messaging/citations'),
  getMessagingInternal: () => get('/audit/messaging/internal'),
  getMessagingConversations: () => get('/audit/messaging/conversations'),

  // 5. Actividad Docente
  getTeacherActivity: () => get('/audit/teacher/activity'),
  getTeacherClasses: () => get('/audit/teacher/classes'),
  getTeacherPermissions: () => get('/audit/teacher/permissions'),
  getTeacherIncidents: () => get('/audit/teacher/incidents'),
  getTeacherSystemActivity: () => get('/audit/teacher/system-activity'),

  // 6. Seguridad
  getSecurityGlobal: () => get('/audit/security/global'),
  getSecurityAccesses: () => get('/audit/security/accesses'),
  getSecuritySessions: () => get('/audit/security/sessions'),
  getSecurityCommands: () => get('/audit/security/commands'),
  getSecurityAdminActivity: () => get('/audit/security/admin-activity'),
  getSecurityFailedAttempts: () => get('/audit/security/failed-attempts'),

  // 7. Alertas SOS
  getSosAlerts: () => get('/audit/sos/alerts'),
  getSosResolved: () => get('/audit/sos/resolved'),
  getSosResolutionTime: () => get('/audit/sos/resolution-time'),
  getSosHistory: () => get('/audit/sos/history'),

  // 8. Históricos
  getHistoricalStudent: (studentId) => get(`/audit/historical/student?student_id=${studentId}`),
  getHistoricalTeacher: (userId) => get(`/audit/historical/teacher?user_id=${userId || ''}`),
  getHistoricalAttendance: (from, to) => get(`/audit/historical/attendance?from=${from}&to=${to}`),
  getHistoricalDiscipline: (from, to) => get(`/audit/historical/discipline?from=${from}&to=${to}`),
  getHistoricalPermissions: (from, to) => get(`/audit/historical/permissions?from=${from}&to=${to}`),
  getHistoricalMessaging: (from, to) => get(`/audit/historical/messaging?from=${from}&to=${to}`),
  getHistoricalSearch: (q) => get(`/audit/historical/search?q=${encodeURIComponent(q)}`),
  getHistoricalDownload: (type, id) => get(`/audit/historical/download?type=${type}&id=${id}`),
  getHistoricalDownloadConsolidated: (from, to) => get(`/audit/historical/download-consolidated?from=${from}&to=${to}`),

  // 9. Consolidados
  getConsolidatedAttendance: (from, to) => get(`/audit/consolidated/attendance?from=${from}&to=${to}`),
  getConsolidatedDiscipline: (from, to) => get(`/audit/consolidated/discipline?from=${from}&to=${to}`),
  getConsolidatedPermissions: (from, to) => get(`/audit/consolidated/permissions?from=${from}&to=${to}`),
  getConsolidatedMessaging: (from, to) => get(`/audit/consolidated/messaging?from=${from}&to=${to}`),
  getConsolidatedTeacher: (from, to) => get(`/audit/consolidated/teacher?from=${from}&to=${to}`),
  getConsolidatedSecurity: (from, to) => get(`/audit/consolidated/security?from=${from}&to=${to}`),
  getConsolidatedInstitutional: (from, to) => get(`/audit/consolidated/institutional?from=${from}&to=${to}`),
};
