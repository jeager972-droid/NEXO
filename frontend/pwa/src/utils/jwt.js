/**
 * jwt utils / NEXO
 * Decodificación mínima del payload de un JWT (sin validar firma).
 */

export function decodeJwtPayload(token) {
  if (!token || typeof token !== 'string') return null;
  const parts = token.split('.');
  if (parts.length < 2) return null;
  try {
    const base64 = parts[1].replace(/-/g, '+').replace(/_/g, '/');
    const padded = base64.padEnd(base64.length + (4 - (base64.length % 4)) % 4, '=');
    const json = atob(padded);
    const decoded = decodeURIComponent(
      json.split('').map((c) => '%' + ('00' + c.charCodeAt(0).toString(16)).slice(-2)).join('')
    );
    return JSON.parse(decoded);
  } catch {
    return null;
  }
}

export function getTokenExp(token) {
  const payload = decodeJwtPayload(token);
  if (!payload || typeof payload.exp !== 'number') return null;
  return payload.exp;
}

export function isTokenExpiringSoon(token, withinMinutes = 10) {
  const exp = getTokenExp(token);
  if (exp === null) return false;
  const nowSec = Math.floor(Date.now() / 1000);
  return (exp - nowSec) <= withinMinutes * 60;
}

export function isTokenExpired(token) {
  const exp = getTokenExp(token);
  if (exp === null) return false;
  return Math.floor(Date.now() / 1000) >= exp;
}
