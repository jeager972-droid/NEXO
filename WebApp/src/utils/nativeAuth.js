/**
 * nativeAuth / NEXO Institucional
 * Responsabilidad: Abstraer autenticación biométrica y almacenamiento seguro del
 * refresh token cuando la app corre como Tauri ( escritorio/móvil híbrido ).
 * Dependencias: @tauri-apps/plugin-biometric, @tauri-apps/plugin-store.
 * Nota: Solo se invoca en entornos con window.__TAURI__ disponible.
 */
import { checkBiometry, authenticate } from '@tauri-apps/plugin-biometric';
import { Store } from '@tauri-apps/plugin-store';

const store = new Store('.nexo-auth.dat');

export const nativeAuth = {
  async isBiometricAvailable() {
    try {
      const status = await checkBiometry();
      return status[0] !== 'none';
    } catch {
      return false;
    }
  },

  async authenticateBiometric(reason = 'Inicia sesión en NEXO') {
    try {
      await authenticate(reason);
      return true;
    } catch (error) {
      console.error('Biometric auth failed:', error);
      return false;
    }
  },

  async saveToken(token) {
    await store.set('jwt_refresh_token', token);
    await store.save();
  },

  async getToken() {
    return await store.get('jwt_refresh_token');
  },

  async clearToken() {
    await store.delete('jwt_refresh_token');
    await store.save();
  }
};
