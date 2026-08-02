import { describe, it, expect, vi, beforeEach } from 'vitest';

vi.mock('../../api/client', () => ({
  default: { get: vi.fn(), post: vi.fn() },
}));

import client from '../../api/client';
import { auditApi } from '../../api/audit';

describe('auditApi', () => {
  beforeEach(() => vi.clearAllMocks());

  it('getGlobalLogs calls GET /audit/global with params', async () => {
    client.get.mockResolvedValue({ data: { data: [] } });
    await auditApi.getGlobalLogs({ page: 1 });
    expect(client.get).toHaveBeenCalledWith('/audit/global', { params: { page: 1 } });
  });

  it('getIntegrity calls GET /audit/integrity', async () => {
    client.get.mockResolvedValue({ data: { ok: true } });
    await auditApi.getIntegrity();
    expect(client.get).toHaveBeenCalledWith('/audit/integrity', { params: {} });
  });

  it('getGroups calls GET /audit/groups', async () => {
    client.get.mockResolvedValue({ data: { data: [{ id: 1 }] } });
    const result = await auditApi.getGroups();
    expect(client.get).toHaveBeenCalledWith('/audit/groups', { params: {} });
    expect(result.data).toHaveLength(1);
  });

  it('getGroupStudents calls GET /audit/groups/:id/students', async () => {
    client.get.mockResolvedValue({ data: { data: [] } });
    await auditApi.getGroupStudents(42);
    expect(client.get).toHaveBeenCalledWith('/audit/groups/42/students', { params: {} });
  });

  it('getStaff calls GET /audit/staff', async () => {
    client.get.mockResolvedValue({ data: { data: [] } });
    await auditApi.getStaff();
    expect(client.get).toHaveBeenCalledWith('/audit/staff', { params: {} });
  });

  it('attendance endpoints call correct paths', async () => {
    client.get.mockResolvedValue({ data: {} });
    await auditApi.getAttendanceGeneral({ x: 1 });
    await auditApi.getAttendanceAbsences({});
    await auditApi.getAttendanceLates({});
    await auditApi.getAttendanceEvasion({});
    expect(client.get).toHaveBeenNthCalledWith(1, '/audit/attendance/general', { params: { x: 1 } });
    expect(client.get).toHaveBeenNthCalledWith(2, '/audit/attendance/absences', { params: {} });
    expect(client.get).toHaveBeenNthCalledWith(3, '/audit/attendance/lates', { params: {} });
    expect(client.get).toHaveBeenNthCalledWith(4, '/audit/attendance/evasion', { params: {} });
  });

  it('discipline endpoints call correct paths', async () => {
    client.get.mockResolvedValue({ data: {} });
    await auditApi.getDisciplineIncidents({});
    await auditApi.getDisciplineViolations({});
    await auditApi.getDisciplineWrongClassroom({});
    await auditApi.getDisciplineBiometricSpam({});
    await auditApi.getDisciplineReports({});
    expect(client.get).toHaveBeenCalledTimes(5);
  });

  it('permissions endpoints call correct paths', async () => {
    client.get.mockResolvedValue({ data: {} });
    await auditApi.getPermissionsClassExits({});
    await auditApi.getPermissionsSchoolExits({});
    await auditApi.getPermissionsPedagogical({});
    await auditApi.getPermissionsPendingReturns({});
    await auditApi.getPermissionsHistory({});
    expect(client.get).toHaveBeenCalledTimes(5);
  });

  it('messaging endpoints call correct paths', async () => {
    client.get.mockResolvedValue({ data: {} });
    await auditApi.getMessagingWhatsAppSent({});
    await auditApi.getMessagingGuardianReplies({});
    await auditApi.getMessagingFailed({});
    await auditApi.getMessagingCitations({});
    await auditApi.getMessagingInternal({});
    await auditApi.getMessagingConversations({});
    expect(client.get).toHaveBeenCalledTimes(6);
  });

  it('teacher endpoints call correct paths', async () => {
    client.get.mockResolvedValue({ data: {} });
    await auditApi.getTeacherActivity({});
    await auditApi.getTeacherClasses({});
    await auditApi.getTeacherPermissions({});
    await auditApi.getTeacherIncidents({});
    await auditApi.getTeacherSystemActivity({});
    expect(client.get).toHaveBeenCalledTimes(5);
  });

  it('security endpoints call correct paths', async () => {
    client.get.mockResolvedValue({ data: {} });
    await auditApi.getSecurityGlobal({});
    await auditApi.getSecurityAccesses({});
    await auditApi.getSecuritySessions({});
    await auditApi.getSecurityCommands({});
    await auditApi.getSecurityAdminActivity({});
    await auditApi.getSecurityFailedAttempts({});
    expect(client.get).toHaveBeenCalledTimes(6);
  });

  it('sos endpoints call correct paths', async () => {
    client.get.mockResolvedValue({ data: {} });
    await auditApi.getSosAlerts({});
    await auditApi.getSosResolved({});
    await auditApi.getSosResolutionTime({});
    await auditApi.getSosHistory({});
    expect(client.get).toHaveBeenCalledTimes(4);
  });

  it('historical endpoints call correct paths', async () => {
    client.get.mockResolvedValue({ data: {} });
    await auditApi.getHistoricalStudent({});
    await auditApi.getHistoricalTeacher({});
    await auditApi.getHistoricalAttendance({});
    await auditApi.getHistoricalDiscipline({});
    await auditApi.getHistoricalPermissions({});
    await auditApi.getHistoricalMessaging({});
    await auditApi.getHistoricalSearch({});
    await auditApi.getHistoricalDownload({});
    await auditApi.getHistoricalDownloadConsolidated({});
    expect(client.get).toHaveBeenCalledTimes(9);
  });

  it('consolidated endpoints call correct paths', async () => {
    client.get.mockResolvedValue({ data: {} });
    await auditApi.getConsolidatedAttendance({});
    await auditApi.getConsolidatedDiscipline({});
    await auditApi.getConsolidatedPermissions({});
    await auditApi.getConsolidatedMessaging({});
    await auditApi.getConsolidatedTeacher({});
    await auditApi.getConsolidatedSecurity({});
    await auditApi.getConsolidatedInstitutional({});
    expect(client.get).toHaveBeenCalledTimes(7);
  });

  it('returns empty object when response.data is null', async () => {
    client.get.mockResolvedValue({ data: null });
    const result = await auditApi.getGroups();
    expect(result).toEqual({});
  });
});
