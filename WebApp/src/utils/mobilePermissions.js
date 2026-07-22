/**
 * mobilePermissions / NEXO Institucional
 * Responsabilidad: Helpers para solicitar permisos del navegador/móvil: notificaciones,
 * cámara y soporte de WebAuthn. Retorna estados seguros sin lanzar excepciones.
 * Dependencias: Navegador APIs (Notification, navigator.mediaDevices, PublicKeyCredential).
 */
export async function requestNotificationPermission() {
  if (typeof window === 'undefined' || !('Notification' in window)) return 'unsupported'
  if (Notification.permission === 'granted') return 'granted'
  try {
    return await Notification.requestPermission()
  } catch {
    return 'denied'
  }
}

export async function requestCameraPermission() {
  if (!navigator?.mediaDevices?.getUserMedia) return { ok: false, reason: 'unsupported' }
  try {
    const stream = await navigator.mediaDevices.getUserMedia({ video: true })
    stream.getTracks().forEach((track) => track.stop())
    return { ok: true }
  } catch {
    return { ok: false, reason: 'denied' }
  }
}

export function isWebAuthnSupported() {
  return Boolean(window?.PublicKeyCredential)
}
