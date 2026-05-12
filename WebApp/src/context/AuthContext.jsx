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
      localStorage.removeItem('token');
      localStorage.removeItem('user');
      setUser(null);
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    const token = localStorage.getItem('token');
    const savedUser = localStorage.getItem('user');
    if (savedUser) {
      try {
        setUser(JSON.parse(savedUser));
      } catch {
        localStorage.removeItem('user');
      }
    }
    if (token) {
      fetchUser();
    } else {
      setLoading(false);
    }
  }, [fetchUser]);

  const login = useCallback(async (email, password) => {
    setLoading(true);
    try {
      const data = await authApi.login(email, password);
      
      if (!data.token || !data.user) {
        throw new Error('La API no retornó el token o el objeto de usuario esperado');
      }

      localStorage.setItem('token', data.token);
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
      localStorage.removeItem('token');
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
