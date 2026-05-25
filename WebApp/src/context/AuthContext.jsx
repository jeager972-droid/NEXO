import { createContext, useState, useEffect, useCallback } from 'react';
import { useNavigate } from 'react-router-dom';
import { authApi } from '../api/auth';

export const AuthContext = createContext();

export const AuthProvider = ({ children }) => {
  const [user, setUser] = useState(null);
  const [loading, setLoading] = useState(true);
  const navigate = useNavigate();

  const fetchUser = useCallback(async () => {
    try {
      const data = await authApi.getMe();
      setUser(data.user);
    } catch (error) {
      localStorage.removeItem('user');
      setUser(null);
      const publicPaths = ['/login', '/instalar/', '/descargas'];
      const currentPath = window.location.pathname;
      const isPublic = publicPaths.some(p => currentPath.includes(p));
      if (!isPublic) {
        navigate('/login');
      }
    } finally {
      setLoading(false);
    }
  }, [navigate]);

  useEffect(() => {
    // FIX: No confiar en localStorage para la fuente de verdad del usuario.
    // Solo authApi.getMe() (cookie HttpOnly) determina el estado real.
    fetchUser();
  }, [fetchUser]);

  const login = useCallback(async (email, password) => {
    setLoading(true);
    try {
      const data = await authApi.login(email, password);
      
      if (!data.user) {
        throw new Error('La API no retornó el objeto de usuario esperado');
      }

      localStorage.setItem('user', JSON.stringify(data.user));
      
      setUser(data.user);
      return data.user;
    } catch (error) {
      throw error.response?.data?.message || error.message || 'Error al iniciar sesión';
    } finally {
      setLoading(false);
    }
  }, []);

  const logout = useCallback(async () => {
    try {
      await authApi.logout();
    } catch (error) {
      console.error('Error during logout', error);
    } finally {
      localStorage.removeItem('user');
      setUser(null);
      navigate('/login');
    }
  }, [navigate]);

  const value = {
    user,
    login,
    logout,
    loading,
    isAuthenticated: !!user
  };

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
};
