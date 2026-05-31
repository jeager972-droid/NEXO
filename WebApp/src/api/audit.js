import client from './client';

const get = async (path, params = {}) => {
  const response = await client.get(path, { params });
  return response.data ?? {};
};

export const auditApi = {
  // Legacy
  getGlobalLogs: (params) => get('/audit/global', params),
  getIntegrity: (params) => get('/audit/integrity', params),

  // Auxiliares
  getGroups: () => get('/audit/groups'),
  getGroupStudents: (groupId) => get(`/audit/groups/${groupId}/students`),
  getStaff: () => get('/audit/staff'),

  // 1. Asistencia
  getAttendanceGeneral: (params) => get('/audit/attendance/general', params),
  getAttendanceAbsences: (params) => get('/audit/attendance/absences', params),
  getAttendanceLates: (params) => get('/audit/attendance/lates', params),
  getAttendanceEvasion: (params) => get('/audit/attendance/evasion', params),

  // 2. Disciplina
  getDisciplineIncidents: (params) => get('/audit/discipline/incidents', params),
  getDisciplineViolations: (params) => get('/audit/discipline/violations', params),
  getDisciplineWrongClassroom: (params) => get('/audit/discipline/wrong-classroom', params),
  getDisciplineBiometricSpam: (params) => get('/audit/discipline/biometric-spam', params),
  getDisciplineReports: (params) => get('/audit/discipline/reports', params),

  // 3. Permisos y Salidas
  getPermissionsClassExits: (params) => get('/audit/permissions/class-exits', params),
  getPermissionsSchoolExits: (params) => get('/audit/permissions/school-exits', params),
  getPermissionsPedagogical: (params) => get('/audit/permissions/pedagogical', params),
  getPermissionsPendingReturns: (params) => get('/audit/permissions/pending-returns', params),
  getPermissionsHistory: (params) => get('/audit/permissions/history', params),

  // 4. Mensajería
  getMessagingWhatsAppSent: (params) => get('/audit/messaging/whatsapp-sent', params),
  getMessagingGuardianReplies: (params) => get('/audit/messaging/guardian-replies', params),
  getMessagingFailed: (params) => get('/audit/messaging/failed', params),
  getMessagingCitations: (params) => get('/audit/messaging/citations', params),
  getMessagingInternal: (params) => get('/audit/messaging/internal', params),
  getMessagingConversations: (params) => get('/audit/messaging/conversations', params),

  // 5. Actividad Docente
  getTeacherActivity: (params) => get('/audit/teacher/activity', params),
  getTeacherClasses: (params) => get('/audit/teacher/classes', params),
  getTeacherPermissions: (params) => get('/audit/teacher/permissions', params),
  getTeacherIncidents: (params) => get('/audit/teacher/incidents', params),
  getTeacherSystemActivity: (params) => get('/audit/teacher/system-activity', params),

  // 6. Seguridad
  getSecurityGlobal: (params) => get('/audit/security/global', params),
  getSecurityAccesses: (params) => get('/audit/security/accesses', params),
  getSecuritySessions: (params) => get('/audit/security/sessions', params),
  getSecurityCommands: (params) => get('/audit/security/commands', params),
  getSecurityAdminActivity: (params) => get('/audit/security/admin-activity', params),
  getSecurityFailedAttempts: (params) => get('/audit/security/failed-attempts', params),

  // 7. Alertas SOS
  getSosAlerts: (params) => get('/audit/sos/alerts', params),
  getSosResolved: (params) => get('/audit/sos/resolved', params),
  getSosResolutionTime: (params) => get('/audit/sos/resolution-time', params),
  getSosHistory: (params) => get('/audit/sos/history', params),

  // 8. Históricos
  getHistoricalStudent: (params) => get('/audit/historical/student', params),
  getHistoricalTeacher: (params) => get('/audit/historical/teacher', params),
  getHistoricalAttendance: (params) => get('/audit/historical/attendance', params),
  getHistoricalDiscipline: (params) => get('/audit/historical/discipline', params),
  getHistoricalPermissions: (params) => get('/audit/historical/permissions', params),
  getHistoricalMessaging: (params) => get('/audit/historical/messaging', params),
  getHistoricalSearch: (params) => get('/audit/historical/search', params),
  getHistoricalDownload: (params) => get('/audit/historical/download', params),
  getHistoricalDownloadConsolidated: (params) => get('/audit/historical/download-consolidated', params),

  // 9. Consolidados
  getConsolidatedAttendance: (params) => get('/audit/consolidated/attendance', params),
  getConsolidatedDiscipline: (params) => get('/audit/consolidated/discipline', params),
  getConsolidatedPermissions: (params) => get('/audit/consolidated/permissions', params),
  getConsolidatedMessaging: (params) => get('/audit/consolidated/messaging', params),
  getConsolidatedTeacher: (params) => get('/audit/consolidated/teacher', params),
  getConsolidatedSecurity: (params) => get('/audit/consolidated/security', params),
  getConsolidatedInstitutional: (params) => get('/audit/consolidated/institutional', params),
};
