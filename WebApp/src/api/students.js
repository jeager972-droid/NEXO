import client from './client';

const getSchoolId = () => {
  const user = JSON.parse(localStorage.getItem('user') || '{}');
  return user.school_id || user.inst_id || user.institucion_id || null;
};

const normalizeStudent = (student) => ({
  ...student,
  name: `${student.first_name || ''} ${student.last_name || ''}`.trim(),
  group: student.group_name || student.group || 'Sin grupo',
  fingerprintId: student.fingerprint_id || null,
});

export const studentsApi = {
  getAll: async ({ last_id = 0, limit = 50, search = '' } = {}) => {
    const schoolId = getSchoolId();
    const params = { last_id, limit };
    if (schoolId) params.school_id = schoolId;
    if (search && search.trim()) params.search = search.trim();
    const response = await client.get('/students', { params });
    const payload = response.data;
    const rows = payload?.data ?? [];
    return {
      students: Array.isArray(rows) ? rows.map(normalizeStudent) : [],
      lastId: payload?.meta?.last_id ?? last_id,
      hasMore: payload?.meta?.has_more ?? false,
    };
  },
  getGroups: async () => {
    const schoolId = getSchoolId();
    const response = await client.get('/groups', {
      params: schoolId ? { school_id: schoolId } : undefined
    });
    const rows = response.data?.data ?? response.data ?? [];
    return Array.isArray(rows) ? rows : [];
  },
};
