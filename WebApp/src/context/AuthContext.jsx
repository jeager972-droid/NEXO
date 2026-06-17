import { createContext, useState, useEffect, useCallback } from 'react';
import { useNavigate } from 'react-router-dom';
import { authApi } from '../api/auth';
import { userStore } from '../store/userStore';

export const AuthContext = createContext();

export const AuthProvider = ({ children }) => {
  const [user, setUser] = useState(null);
  const [loading, setLoading] = useState(true);
  const navigate = useNavigate();

  const fetchUser = useCallback(async () => {
    try {
      const data = await authApi.getMe();
      setUser(data.user);
      userStore.set(data.user);
    } catch (error) {
      userStore.clear();
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

      // Interceptar flujo 2FA: devolver señal sin lanzar error
      if (data.status === '2fa_required' || data.requires_2fa) {
        return data;
      }

      if (!data.user) {
        throw new Error('La API no retornó el objeto de usuario esperado');
      }

      // iOS ITP fallback: guardar token en sessionStorage cuando cookie es bloqueada
      if (data.token) {
        sessionStorage.setItem('nexo_token', data.token);
      }

      userStore.set(data.user);
      setUser(data.user);
      return data;
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
      userStore.clear();
      setUser(null);
      sessionStorage.removeItem('nexo_token'); // iOS ITP cleanup
      navigate('/login');
    }
  }, [navigate]);

  const value = {
    user,
    setUser,
    login,
    logout,
    loading,
    isAuthenticated: !!user
  };

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
};
