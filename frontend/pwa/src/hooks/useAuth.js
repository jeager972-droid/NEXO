/**
 * useAuth hook / NEXO Institucional
 * Responsabilidad: Consumidor conveniente de AuthContext. Lanza error si se usa fuera de AuthProvider.
 * Retorno: { user, setUser, login, logout, loading, isAuthenticated }.
 * Dependencias: React useContext, AuthContext.
 */
import { useContext } from 'react';
import { AuthContext } from '../context/AuthContext';

export const useAuth = () => {
  const context = useContext(AuthContext);
  if (!context) {
    throw new Error('useAuth debe ser usado dentro de un AuthProvider');
  }
  return context;
};
