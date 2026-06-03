import client from './client';

export const usersApi = {
  getByRole: async (role, sameShift = false) => {
    const response = await client.get('/users/by-role', {
      params: { role, same_shift: sameShift ? 1 : 0 }
    });
    return response.data ?? { data: [] };
  },

  getExtendedProfile: async () => {
    const response = await client.get('/users/me/extended');
    return response.data ?? {};
  },

  uploadPhoto: async (file) => {
    const formData = new FormData();
    formData.append('photo', file);
    const response = await client.post('/users/upload-photo', formData);
    return response.data ?? {};
  },

  getMyPhoto: async () => {
    const response = await client.get('/users/me/photo');
    return response.data ?? {};
  },

  sendVerificationCode: async (purpose, target) => {
    const response = await client.post('/users/send-verification', { purpose, target });
    return response.data ?? {};
  },

  verifyCode: async (purpose, code) => {
    const response = await client.post('/users/verify-code', { purpose, code });
    return response.data ?? {};
  },

  updateProfile: async (purpose, value) => {
    const response = await client.post('/users/update-profile', { purpose, value });
    return response.data ?? {};
  },

  changePassword: async (currentPassword, newPassword) => {
    const response = await client.post('/users/change-password', {
      current_password: currentPassword,
      new_password: newPassword
    });
    return response.data ?? {};
  }
};
