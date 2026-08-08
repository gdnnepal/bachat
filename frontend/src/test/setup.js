import '@testing-library/jest-dom/vitest';

// jsdom has no matchMedia; Tailwind-driven components occasionally probe it.
if (!window.matchMedia) {
  window.matchMedia = () => ({
    matches: false,
    addListener: () => {},
    removeListener: () => {},
    addEventListener: () => {},
    removeEventListener: () => {},
    dispatchEvent: () => false,
  });
}
