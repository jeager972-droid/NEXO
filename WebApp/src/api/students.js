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
  getAll: async () => {
    const schoolId = getSchoolId();
    const response = await client.get('/students', {
      params: schoolId ? { school_id: schoolId } : undefined
    });
    const rows = response.data?.data ?? response.data ?? [];
    return Array.isArray(rows) ? rows.map(normalizeStudent) : [];
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
