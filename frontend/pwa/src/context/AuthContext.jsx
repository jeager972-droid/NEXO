/**
 * AuthContext / NEXO
 * Estado global de autenticación: fuente de verdad authApi.getMe(), flujo 2FA, logout.
 * Incluye fallback para iOS PWA standalone donde las cookies HttpOnly no se envían.
 */
import { createContext, useState, useEffect, useCallback, useRef } from 'react';
import { useNavigate } from 'react-router-dom';
import { authApi } from '../api/auth';
import userStore from '../store/userStore';
import { isTokenExpired, isTokenExpiringSoon } from '../utils/jwt';

export const AuthContext = createContext();

const USER_FALLBACK_KEY = 'nexo:user-fallback';

// TEMPORAL ITP WORKAROUND: token en localStorage para iOS/Safari donde ITP bloquea cookies cross-site.
// TODO: Cuando frontend y backend estén en same-site, eliminar TOKEN_KEY y usar solo cookie HttpOnly.
const TOKEN_KEY = 'nexo:auth-token';
const REFRESH_TOKEN_KEY = 'nexo:auth-refresh-token';

const saveToken = (token) => {
  try {
    if (token) localStorage.setItem(TOKEN_KEY, token);
    else localStorage.removeItem(TOKEN_KEY);
  } catch { /* ignore */ }
};

const saveRefreshToken = (refreshToken) => {
  try {
    if (refreshToken) localStorage.setItem(REFRESH_TOKEN_KEY, refreshToken);
    else localStorage.removeItem(REFRESH_TOKEN_KEY);
  } catch { /* ignore */ }
};

const getRefreshToken = () => {
  try {
    return localStorage.getItem(REFRESH_TOKEN_KEY) || '';
  } catch { return ''; }
};

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
      saveToken(null);
      saveRefreshToken(null);
      setLoading(true);
      navigateRef.current('/login');
    };
    window.addEventListener('nexo:auth-logout', onAuthLogout);
    return () => window.removeEventListener('nexo:auth-logout', onAuthLogout);
  }, []);

  const verifyToken = useCallback(async () => {
    const token = localStorage.getItem(TOKEN_KEY);
    if (!token) return;
    if (isTokenExpired(token)) {
      // Intentar refresh antes de logout
      const refreshToken = getRefreshToken();
      if (refreshToken) {
        try {
          const data = await authApi.refresh(refreshToken);
          if (data?.token) {
            saveToken(data.token);
            if (data.refresh_token) saveRefreshToken(data.refresh_token);
            if (data?.user) {
              setUser(data.user);
              userStore.set(data.user);
              saveUserFallback(data.user);
            }
            return;
          }
        } catch (refreshError) {
          // Refresh falló — logout
        }
      }
      window.dispatchEvent(new CustomEvent('nexo:auth-logout', {
        detail: { reason: 'Tu sesión ha expirado' },
      }));
      return;
    }
    if (!isTokenExpiringSoon(token, 10)) return;
    // Token próximo a expirar — usar refresh en lugar de getMe
    const refreshToken = getRefreshToken();
    if (refreshToken) {
      try {
        const data = await authApi.refresh(refreshToken);
        if (data?.token) {
          saveToken(data.token);
          if (data.refresh_token) saveRefreshToken(data.refresh_token);
          if (data?.user) {
            setUser(data.user);
            userStore.set(data.user);
            saveUserFallback(data.user);
          }
          return;
        }
      } catch (refreshError) {
        // Refresh falló — intentar getMe como fallback
      }
    }
    try {
      const data = await authApi.getMe();
      if (data?.user) {
        setUser(data.user);
        userStore.set(data.user);
        saveUserFallback(data.user);
      }
    } catch (error) {
      if (error.response?.status === 401) {
        window.dispatchEvent(new CustomEvent('nexo:auth-logout', {
          detail: { reason: 'Tu sesión ha expirado' },
        }));
      }
    }
  }, []);

  useEffect(() => {
    if (!user) return;
    const id = setInterval(verifyToken, 5 * 60 * 1000);
    return () => clearInterval(id);
  }, [user, verifyToken]);

  useEffect(() => {
    const onTokenCheck = () => verifyToken();
    window.addEventListener('nexo:token-check', onTokenCheck);
    return () => window.removeEventListener('nexo:token-check', onTokenCheck);
  }, [verifyToken]);

  const login = useCallback(async (email, password) => {
    setLoading(true);
    try {
      const data = await authApi.login(email, password);
      if (data.status === '2fa_required' || data.requires_2fa) return data;
      if (data.token) saveToken(data.token);
      if (data.refresh_token) saveRefreshToken(data.refresh_token);
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
      saveToken(null);
      saveRefreshToken(null);
      setLoading(true);
      navigate('/login');
    }
  }, [navigate]);

  const value = { user, setUser, login, logout, loading, isAuthenticated: !!user };

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
};
