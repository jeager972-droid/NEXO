let currentUser = null;

const userStore = {
  set: (user) => { currentUser = user; },
  get: () => currentUser,
  clear: () => { currentUser = null; },
};

export default userStore;
