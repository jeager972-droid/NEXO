import client from './client';

export const authApi = {
  login: async (email, password) => {
    // Si estamos en desarrollo local, Vite usa el proxy o la URL directa.
    // Asegurémonos de que el path sea limpio.
    const response = await client.post('/auth/login', { 
      email: email.trim(), 
      password: password,
      action: 'LOGIN'
    });
    return response.data;
  },
  logout: async () => {
    const response = await client.post('/auth/logout');
    return response.data;
  },
  getMe: async () => {
    const response = await client.get('/auth/me');
    return response.data;
  },
};
