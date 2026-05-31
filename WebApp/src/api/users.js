import client from './client';

export const usersApi = {
  getByRole: async (role, sameShift = false) => {
    const response = await client.get('/users/by-role', {
      params: { role, same_shift: sameShift ? 1 : 0 }
    });
    return response.data ?? { data: [] };
  },

  uploadPhoto: async (file) => {
    const formData = new FormData();
    formData.append('photo', file);
    const response = await client.post('/users/upload-photo', formData, {
      headers: { 'Content-Type': 'multipart/form-data' }
    });
    return response.data ?? {};
  },

  getMyPhoto: async () => {
    const response = await client.get('/users/me/photo');
    return response.data ?? {};
  }
};
