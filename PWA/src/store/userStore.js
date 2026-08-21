/**
 * userStore / NEXO Institucional
 * Responsabilidad: Singleton en memoria para guardar el usuario autenticado actual.
 * Se sincroniza desde AuthContext; útil para acceso fuera de React sin propagar contexto.
 * API: set(user), get(), clear().
 * Dependencias: Ninguna externa.
 */
let currentUser = null;

const userStore = {
  set: (user) => { currentUser = user; },
  get: () => currentUser,
  clear: () => { currentUser = null; },
};

export default userStore;
