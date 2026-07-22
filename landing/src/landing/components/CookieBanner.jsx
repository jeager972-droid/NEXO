/**
 * =============================================================================
 * CookieBanner.jsx — Banner de consentimiento de cookies.
 * =============================================================================
 * RESPONSABILIDAD:
 *   Muestra un banner flotante con opciones para aceptar todas, rechazar
 *   (solo necesarias) o abrir el gestor de cookies. Incluye animación de entrada
 *   y estilos responsive.
 *
 * PROPS:
 *   - onAcceptAll, onRejectAll, onManage
 *
 * DEPENDENCIAS:
 *   - lucide-react (Cookie)
 * =============================================================================
 */

import { Cookie } from 'lucide-react'

const CookieBanner = ({ onAcceptAll, onRejectAll, onManage }) => {
  return (
    <div className="cookie-banner">
      <style>{`
        @keyframes slideUpBanner {
          from {
            transform: translateY(120%);
            opacity: 0;
          }
          to {
            transform: translateY(0);
            opacity: 1;
          }
        }

        .cookie-banner {
          position: fixed;
          bottom: 1.5rem;
          left: 1.5rem;
          max-width: 420px;
          background: #ffffff;
          border: 1px solid rgba(45, 110, 48, 0.2);
          border-radius: 1rem;
          padding: 1.5rem;
          box-shadow: 0 8px 40px rgba(0, 0, 0, 0.12);
          z-index: 9999;
          animation: slideUpBanner 0.4s cubic-bezier(0.16, 1, 0.3, 1);
        }

        @media (max-width: 640px) {
          .cookie-banner {
            left: 1rem;
            right: 1rem;
            bottom: 1rem;
            max-width: none;
          }
        }

        .cookie-banner__header {
          display: flex;
          align-items: flex-start;
          gap: 0.875rem;
          margin-bottom: 1rem;
        }

        .cookie-banner__icon {
          flex-shrink: 0;
          width: 2.5rem;
          height: 2.5rem;
          background: rgba(26, 74, 31, 0.1);
          border-radius: 0.625rem;
          display: flex;
          align-items: center;
          justify-content: center;
          color: #1a4a1f;
        }

        .cookie-banner__content h3 {
          font-size: 1rem;
          font-weight: 700;
          color: #0f2d12;
          margin: 0 0 0.25rem 0;
        }

        .cookie-banner__content p {
          font-size: 0.875rem;
          line-height: 1.5;
          color: #0f2d12;
          opacity: 0.8;
          margin: 0;
        }

        .cookie-banner__actions {
          display: flex;
          flex-direction: column;
          gap: 0.625rem;
          margin-top: 1.25rem;
        }

        .cookie-banner__btn {
          width: 100%;
          padding: 0.75rem 1rem;
          border-radius: 0.625rem;
          font-size: 0.875rem;
          font-weight: 600;
          cursor: pointer;
          transition: all 0.2s cubic-bezier(0.16, 1, 0.3, 1);
          border: none;
          outline: none;
        }

        .cookie-banner__btn--primary {
          background: #1a4a1f;
          color: #ffffff;
        }

        .cookie-banner__btn--primary:hover {
          background: #143a18;
          transform: translateY(-1px);
          box-shadow: 0 4px 12px rgba(26, 74, 31, 0.3);
        }

        .cookie-banner__btn--primary:active {
          transform: translateY(0);
        }

        .cookie-banner__btn--ghost {
          background: transparent;
          color: #1a4a1f;
          border: 1.5px solid #1a4a1f;
        }

        .cookie-banner__btn--ghost:hover {
          background: rgba(26, 74, 31, 0.05);
        }

        .cookie-banner__link {
          text-align: center;
          margin-top: 0.5rem;
        }

        .cookie-banner__link button {
          background: none;
          border: none;
          color: #1a4a1f;
          font-size: 0.813rem;
          font-weight: 500;
          text-decoration: underline;
          cursor: pointer;
          padding: 0;
        }

        .cookie-banner__link button:hover {
          opacity: 0.8;
        }
      `}</style>

      <div className="cookie-banner__header">
        <div className="cookie-banner__icon">
          <Cookie size={20} />
        </div>
        <div className="cookie-banner__content">
          <h3>Usamos cookies</h3>
          <p>
            Utilizamos cookies para mejorar tu experiencia, analizar el tráfico y personalizar el contenido. 
            Puedes aceptar todas o administrar tus preferencias.
          </p>
        </div>
      </div>

      <div className="cookie-banner__actions">
        <button 
          onClick={onAcceptAll}
          className="cookie-banner__btn cookie-banner__btn--primary"
        >
          Aceptar todas
        </button>
        <button 
          onClick={onRejectAll}
          className="cookie-banner__btn cookie-banner__btn--ghost"
        >
          Solo necesarias
        </button>
      </div>

      <div className="cookie-banner__link">
        <button onClick={onManage}>
          Administrar cookies
        </button>
      </div>
    </div>
  )
}

export default CookieBanner
