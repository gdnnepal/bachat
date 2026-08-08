import { createContext, useCallback, useContext, useMemo, useRef, useState } from 'react';

/**
 * Global toast notifications (Req 9.5).
 *
 * The API layer pushes 403 / 409 / 500 messages here, and pages push their own
 * success and error notices. Toasts auto-dismiss unless duration is 0.
 */

const ToastContext = createContext(null);

const DEFAULT_DURATION = 4500;
const MAX_VISIBLE = 4;

export function ToastProvider({ children }) {
  const [toasts, setToasts] = useState([]);
  const timers = useRef(new Map());
  const nextId = useRef(1);

  const dismiss = useCallback((id) => {
    setToasts((current) => current.filter((toast) => toast.id !== id));

    const timer = timers.current.get(id);
    if (timer) {
      clearTimeout(timer);
      timers.current.delete(id);
    }
  }, []);

  const push = useCallback(
    (message, variant = 'info', duration = DEFAULT_DURATION) => {
      if (!message) return null;

      const id = nextId.current;
      nextId.current += 1;

      setToasts((current) => [...current, { id, message, variant }].slice(-MAX_VISIBLE));

      if (duration > 0) {
        timers.current.set(
          id,
          setTimeout(() => dismiss(id), duration),
        );
      }

      return id;
    },
    [dismiss],
  );

  const value = useMemo(
    () => ({
      toasts,
      dismiss,
      push,
      success: (message, duration) => push(message, 'success', duration),
      error: (message, duration) => push(message, 'error', duration),
      warning: (message, duration) => push(message, 'warning', duration),
      info: (message, duration) => push(message, 'info', duration),
    }),
    [toasts, dismiss, push],
  );

  return <ToastContext.Provider value={value}>{children}</ToastContext.Provider>;
}

export function useToast() {
  const context = useContext(ToastContext);

  if (!context) {
    throw new Error('useToast must be used inside a <ToastProvider>.');
  }

  return context;
}

export default useToast;
