import '@testing-library/jest-dom';

// Enable React act() environment so state updates are flushed in tests
globalThis.IS_REACT_ACT_ENVIRONMENT = true;

// Mock matchMedia for components that use it
if (!window.matchMedia) {
  window.matchMedia = (query) => ({
    matches: false,
    media: query,
    onchange: null,
    addListener: () => {},
    removeListener: () => {},
    addEventListener: () => {},
    removeEventListener: () => {},
    dispatchEvent: () => false,
  });
}

// Mock IntersectionObserver
if (!window.IntersectionObserver) {
  window.IntersectionObserver = class {
    observe() {}
    unobserve() {}
    disconnect() {}
  };
}
