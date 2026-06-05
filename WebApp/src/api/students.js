import client from './client';

const getSchoolId = () => {
  const user = JSON.parse(localStorage.getItem('user') || '{}');
  return user.school_id || user.inst_id || user.institucion_id || null;
};

const normalizeStudent = (student) => ({
  ...student,
  id: student.id ?? student.student_id ?? null,
  name: `${student.last_name || ''}, ${student.first_name || ''}`.trim(),
  group: student.group_name || student.group || 'Sin grupo',
  fingerprintId: student.fingerprint_id || null,
});

export const studentsApi = {
  getAll: async ({ last_id = '', limit = 50, search = '' } = {}) => {
    const schoolId = getSchoolId();
    const params = { limit };
    if (last_id !== '' && last_id !== '0' && last_id !== 0) params.last_id = last_id;
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
  getAllPaginated: async (search = '') => {
    const all = [];
    let lastId = '';
    let hasMore = true;
    let iterations = 0;
    while (hasMore && iterations < 50) {
      const batch = await studentsApi.getAll({ last_id: lastId, limit: 100, search });
      if (batch.students.length === 0) break;
      all.push(...batch.students);
      lastId = batch.lastId;
      hasMore = batch.hasMore;
      iterations++;
    }
    return all;
  },
  getGroups: async (teacherOnly = false) => {
    const schoolId = getSchoolId();
    const params = {};
    if (schoolId) params.school_id = schoolId;
    if (teacherOnly) params.teacher_only = '1';
    const response = await client.get('/groups', { params: Object.keys(params).length ? params : undefined });
    const rows = response.data?.data ?? response.data ?? [];
    return Array.isArray(rows) ? rows : [];
  },
};
