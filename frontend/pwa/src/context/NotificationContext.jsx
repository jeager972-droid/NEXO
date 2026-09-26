/**
 * NotificationContext / NEXO
 * Fuente única de polling de notificaciones (cada 60s).
 * notifCount = no leídas según el servidor (read_at), no un diff
 * de localStorage — el estado sobrevive sesiones y dispositivos.
 */
import { createContext, useState, useEffect, useContext, useCallback, useRef } from 'react';
import { notificationsApi } from '../api/notifications';
import { useAuth } from '../hooks/useAuth';

const NotificationContext = createContext(null);

export const NotificationProvider = ({ children }) => {
  const { isAuthenticated } = useAuth();
  const [notifications, setNotifications] = useState([]);
  const [notifCount, setNotifCount] = useState(0);
  const mountedRef = useRef(true);

  const computeCount = useCallback((arr) => arr.filter((n) => !n.read).length, []);

  const refreshNotifications = useCallback(async () => {
    try {
      const data = await notificationsApi.getAll();
      if (!mountedRef.current) return;
      const arr = Array.isArray(data) ? data : [];
      setNotifications(arr);
      setNotifCount(computeCount(arr));
    } catch {
      /* silencioso */
    }
  }, [computeCount]);

  const clearNotifications = useCallback(async () => {
    try {
      await notificationsApi.clearAll();
      if (!mountedRef.current) return;
      setNotifications([]);
      setNotifCount(0);
    } catch {
      /* silencioso */
    }
  }, []);

  const markRead = useCallback(async (id) => {
    setNotifications((prev) => {
      const next = prev.map((n) => (n.id === id ? { ...n, read: true } : n));
      setNotifCount(next.filter((n) => !n.read).length);
      return next;
    });
    try {
      await notificationsApi.markRead(id);
    } catch {
      /* silencioso — el estado local ya refleja la lectura */
    }
  }, []);

  const markAllRead = useCallback(async () => {
    setNotifications((prev) => prev.map((n) => ({ ...n, read: true })));
    setNotifCount(0);
    try {
      await notificationsApi.markAllRead();
    } catch {
      /* silencioso */
    }
  }, []);

  useEffect(() => {
    mountedRef.current = true;
    if (!isAuthenticated) return;
    refreshNotifications();
    const id = setInterval(refreshNotifications, 60000);
    return () => { mountedRef.current = false; clearInterval(id); };
  }, [refreshNotifications, isAuthenticated]);

  const value = { notifCount, notifications, refreshNotifications, clearNotifications, markRead, markAllRead };
  return <NotificationContext.Provider value={value}>{children}</NotificationContext.Provider>;
};

export const useNotifications = () => {
  const ctx = useContext(NotificationContext);
  if (!ctx) {
    throw new Error('useNotifications debe usarse dentro de NotificationProvider');
  }
  return ctx;
};

export default NotificationContext;
