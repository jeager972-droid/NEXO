/**
 * students API / NEXO Institucional
 * Responsabilidad: Cliente para gestión de estudiantes: listado paginado/busqueda,
 * grupos, creación y normalización/deduplicación de registros.
 * Dependencias: axios client.js.
 */
import client from './client';

const normalizeStudent = (student) => ({
  ...student,
  id: student.id ?? student.student_id ?? null,
  name: `${student.last_name || ''} ${student.first_name || ''}`.trim(),
  group: student.group_name || student.group || 'Sin grupo',
  fingerprintId: student.fingerprint_id || null,
});

export const studentsApi = {
  getAll: async ({ last_id = '', limit = 50, search = '', group_name = '', grade = '' } = {}) => {
    const params = { limit };
    if (last_id !== '' && last_id !== '0' && last_id !== 0) params.last_id = last_id;
    if (search && search.trim()) params.search = search.trim();
    if (group_name && group_name.trim()) params.group_name = group_name.trim();
    if (grade) params.grade = grade;
    const response = await client.get('/students', { params });
    const payload = response.data;
    const rows = payload?.data ?? [];
    const normalized = Array.isArray(rows) ? rows.map(normalizeStudent) : [];
    // Defensa: deduplicar por id por si el backend envía duplicados
    const seen = new Set();
    const deduped = normalized.filter(s => {
      if (seen.has(s.id)) return false;
      seen.add(s.id);
      return true;
    });
    return {
      students: deduped,
      lastId: payload?.meta?.last_id ?? last_id,
      hasMore: payload?.meta?.has_more ?? false,
    };
  },
  getAllPaginated: async (search = '', { maxPages = 20, onProgress } = {}) => {
    const seen = new Set();
    const all = [];
    let lastId = '';
    let hasMore = true;
    let iterations = 0;
    while (hasMore && iterations < maxPages) {
      const batch = await studentsApi.getAll({ last_id: lastId, limit: 100, search });
      if (batch.students.length === 0) break;
      for (const s of batch.students) {
        if (!seen.has(s.id)) {
          seen.add(s.id);
          all.push(s);
        }
      }
      lastId = batch.lastId;
      hasMore = batch.hasMore;
      iterations++;
      if (typeof onProgress === 'function') {
        onProgress({ loaded: all.length, pages: iterations, hasMore });
      }
    }
    if (hasMore && iterations >= maxPages) {
      all.truncated = true;
    }
    return all;
  },
  getGroups: async (teacherOnly = false) => {
    const params = {};
    if (teacherOnly) params.teacher_only = '1';
    const response = await client.get('/groups', { params: Object.keys(params).length ? params : undefined });
    const rows = response.data?.data ?? response.data ?? [];
    if (!Array.isArray(rows)) return [];

    // Deduplicate groups like '6A' and '6-A'
    const deduped = [];
    const seen = new Set();
    for (const g of rows) {
      const norm = (g.name || g.group_name || '').replace(/[\s-]/g, '').toUpperCase();
      if (!norm) continue;
      if (!seen.has(norm)) {
        seen.add(norm);
        deduped.push(g);
      }
    }
    return deduped;
  },
  create: async (data) => {
    const response = await client.post('/students', data);
    return response.data;
  },
  getUnassigned: async (search = '') => {
    const params = {};
    if (search) params.search = search;
    const response = await client.get('/students/unassigned', { params });
    return response.data?.data ?? [];
  },
  assignGroup: async (studentId, groupId) => {
    const response = await client.post(`/students/${studentId}/assign-group`, { group_id: groupId });
    return response.data;
  },
};
