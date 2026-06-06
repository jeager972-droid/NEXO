let currentUser = null;

export const userStore = {
  set: (user) => { currentUser = user; },
  get: () => currentUser,
  clear: () => { currentUser = null; },
};
