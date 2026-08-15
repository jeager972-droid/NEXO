/**
 * NotificationContext / NEXO
 * Fuente única de polling de notificaciones (cada 60s).
 * Mantiene notifCount y notifications, expone refresh/clear.
 */
import { createContext, useState, useEffect, useContext, useCallback, useRef } from 'react';
import { notificationsApi } from '../api/notifications';
import { useAuth } from '../hooks/useAuth';

const LAST_COUNT_KEY = 'nexo:last-notif-count';

const NotificationContext = createContext(null);

export const NotificationProvider = ({ children }) => {
  const { isAuthenticated } = useAuth();
  const [notifications, setNotifications] = useState([]);
  const [notifCount, setNotifCount] = useState(0);
  const mountedRef = useRef(true);

  const computeCount = useCallback((arr) => {
    const lastSeen = parseInt(sessionStorage.getItem(LAST_COUNT_KEY) || '0', 10);
    return Math.max(0, arr.length - lastSeen);
  }, []);

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
      sessionStorage.setItem(LAST_COUNT_KEY, '0');
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

  useEffect(() => {
    const handler = (e) => {
      const count = e.detail?.count ?? 0;
      setNotifCount(count > 0 ? count : 0);
    };
    window.addEventListener('nexo:notif-count', handler);
    return () => window.removeEventListener('nexo:notif-count', handler);
  }, []);

  const value = { notifCount, notifications, refreshNotifications, clearNotifications };
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
