/**
 * =============================================================================
 * CookieFloatingButton.jsx — Botón flotante para gestionar cookies.
 * =============================================================================
 * RESPONSABILIDAD:
 *   Botón circular fijo que reaparece tras aceptar/rechazar cookies para
 *   permitir al usuario reabrir el gestor de preferencias. Oculta tooltip en
 *   móvil y tiene efecto hover scale.
 *
 * PROPS:
 *   - onClick
 *
 * DEPENDENCIAS:
 *   - lucide-react (Shield)
 * =============================================================================
 */

import { Shield } from 'lucide-react'

const CookieFloatingButton = ({ onClick }) => {
  return (
    <button 
      onClick={onClick}
      className="cookie-floating-btn"
      aria-label="Gestionar cookies"
    >
      <style>{`
        .cookie-floating-btn {
          position: fixed;
          bottom: 1.5rem;
          left: 1.5rem;
          width: 48px;
          height: 48px;
          border-radius: 50%;
          background: #ffffff;
          border: 1px solid rgba(45, 110, 48, 0.3);
          box-shadow: 0 2px 12px rgba(0, 0, 0, 0.1);
          display: flex;
          align-items: center;
          justify-content: center;
          cursor: pointer;
          z-index: 9998;
          transition: all 0.2s cubic-bezier(0.16, 1, 0.3, 1);
          color: #1a4a1f;
        }

        .cookie-floating-btn:hover {
          transform: scale(1.1);
          box-shadow: 0 4px 20px rgba(0, 0, 0, 0.15);
          border-color: #1a4a1f;
        }

        .cookie-floating-btn:active {
          transform: scale(1.05);
        }

        .cookie-floating-btn::before {
          content: attr(aria-label);
          position: absolute;
          left: 100%;
          margin-left: 0.75rem;
          padding: 0.5rem 0.75rem;
          background: #0f2d12;
          color: #ffffff;
          font-size: 0.75rem;
          font-weight: 500;
          white-space: nowrap;
          border-radius: 0.375rem;
          opacity: 0;
          pointer-events: none;
          transition: opacity 0.2s;
        }

        .cookie-floating-btn:hover::before {
          opacity: 1;
        }

        @media (max-width: 640px) {
          .cookie-floating-btn {
            bottom: 1rem;
            left: 1rem;
            width: 44px;
            height: 44px;
          }

          .cookie-floating-btn::before {
            display: none;
          }
        }
      `}</style>
      <Shield size={20} />
    </button>
  )
}

export default CookieFloatingButton
