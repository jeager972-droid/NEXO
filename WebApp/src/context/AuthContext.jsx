/**
 * AuthContext / NEXO
 * Estado global de autenticación: fuente de verdad authApi.getMe(), flujo 2FA, logout.
 * Incluye fallback para iOS PWA standalone donde las cookies HttpOnly no se envían.
 */
import { createContext, useState, useEffect, useCallback, useRef } from 'react';
import { useNavigate } from 'react-router-dom';
import { authApi } from '../api/auth';
import userStore from '../store/userStore';

export const AuthContext = createContext();

const USER_FALLBACK_KEY = 'nexo:user-fallback';

const saveUserFallback = (user) => {
  try {
    if (user) localStorage.setItem(USER_FALLBACK_KEY, JSON.stringify(user));
    else localStorage.removeItem(USER_FALLBACK_KEY);
  } catch { /* ignore */ }
};

const loadUserFallback = () => {
  try {
    const raw = localStorage.getItem(USER_FALLBACK_KEY);
    return raw ? JSON.parse(raw) : null;
  } catch { return null; }
};

export const AuthProvider = ({ children }) => {
  const [user, setUser] = useState(null);
  const [loading, setLoading] = useState(true);
  const navigate = useNavigate();
  const navigateRef = useRef(navigate);
  navigateRef.current = navigate;
  const hasInitRef = useRef(false);

  const fetchUser = useCallback(async () => {
    try {
      const data = await authApi.getMe();
      setUser(data.user);
      userStore.set(data.user);
      saveUserFallback(data.user);
    } catch (error) {
      // iOS PWA fallback: if getMe fails but we have a recently stored user, use it
      const fallback = loadUserFallback();
      if (fallback && error.response?.status !== 403) {
        setUser(fallback);
        userStore.set(fallback);
      } else {
        userStore.clear();
        setUser(null);
        saveUserFallback(null);
        const publicPaths = ['/login', '/instalar/', '/descargas'];
        const currentPath = window.location.pathname;
        const isPublic = publicPaths.some((p) => currentPath.includes(p));
        if (!isPublic) navigateRef.current('/login');
      }
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    if (hasInitRef.current) return;
    hasInitRef.current = true;
    fetchUser();
  }, [fetchUser]);

  useEffect(() => {
    const onAuthLogout = () => {
      userStore.clear();
      setUser(null);
      saveUserFallback(null);
      setLoading(true);
      navigateRef.current('/login');
    };
    window.addEventListener('nexo:auth-logout', onAuthLogout);
    return () => window.removeEventListener('nexo:auth-logout', onAuthLogout);
  }, []);

  const login = useCallback(async (email, password) => {
    setLoading(true);
    try {
      const data = await authApi.login(email, password);
      if (data.status === '2fa_required' || data.requires_2fa) return data;
      if (!data.user) throw new Error('La API no retornó el objeto de usuario esperado');
      userStore.set(data.user);
      setUser(data.user);
      saveUserFallback(data.user);
      return data;
    } catch (error) {
      userStore.clear();
      setUser(null);
      throw new Error(error.response?.data?.message || error.message || 'Error al iniciar sesión');
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
      saveUserFallback(null);
      setLoading(true);
      navigate('/login');
    }
  }, [navigate]);

  const value = { user, setUser, login, logout, loading, isAuthenticated: !!user };

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
};
