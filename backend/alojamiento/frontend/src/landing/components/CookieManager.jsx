import { useState, useEffect } from 'react'
import { X } from 'lucide-react'
import { COOKIE_CATEGORIES } from '../hooks/useCookieConsent'

const CookieManager = ({ onSave, onAcceptAll, onClose, initialValues }) => {
  const [categories, setCategories] = useState({
    necessary: true,
    analytics: initialValues?.analytics ?? false,
    marketing: initialValues?.marketing ?? false,
    preferences: initialValues?.preferences ?? false,
  })

  useEffect(() => {
    const scrollY = window.scrollY
    document.body.style.position = 'fixed'
    document.body.style.top = `-${scrollY}px`
    document.body.style.width = '100%'

    return () => {
      document.body.style.position = ''
      document.body.style.top = ''
      document.body.style.width = ''
      window.scrollTo(0, scrollY)
    }
  }, [])

  const handleToggle = (categoryId) => {
    if (categoryId === 'necessary') return
    setCategories(prev => ({
      ...prev,
      [categoryId]: !prev[categoryId]
    }))
  }

  const handleSave = () => {
    onSave(categories)
  }

  return (
    <div className="cookie-manager-overlay">
      <style>{`
        @keyframes fadeInOverlay {
          from { opacity: 0; }
          to { opacity: 1; }
        }

        @keyframes scaleInModal {
          from {
            opacity: 0;
            transform: scale(0.95);
          }
          to {
            opacity: 1;
            transform: scale(1);
          }
        }

        .cookie-manager-overlay {
          position: fixed;
          inset: 0;
          background: rgba(0, 0, 0, 0.5);
          backdrop-filter: blur(4px);
          display: flex;
          align-items: center;
          justify-content: center;
          z-index: 10000;
          padding: 1rem;
          animation: fadeInOverlay 0.2s ease-out;
        }

        .cookie-manager-modal {
          background: #ffffff;
          border-radius: 1.25rem;
          max-width: 560px;
          width: 100%;
          max-height: 90vh;
          overflow-y: auto;
          box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
          animation: scaleInModal 0.3s cubic-bezier(0.16, 1, 0.3, 1);
        }

        .cookie-manager-header {
          padding: 1.5rem;
          border-bottom: 1px solid rgba(45, 110, 48, 0.1);
          display: flex;
          align-items: center;
          justify-content: space-between;
        }

        .cookie-manager-header h2 {
          font-size: 1.25rem;
          font-weight: 700;
          color: #0f2d12;
          margin: 0;
        }

        .cookie-manager-close {
          width: 2rem;
          height: 2rem;
          border-radius: 0.5rem;
          background: transparent;
          border: none;
          color: #0f2d12;
          cursor: pointer;
          display: flex;
          align-items: center;
          justify-content: center;
          transition: background 0.2s;
        }

        .cookie-manager-close:hover {
          background: rgba(45, 110, 48, 0.1);
        }

        .cookie-manager-body {
          padding: 1.5rem;
        }

        .cookie-category {
          padding: 1.25rem;
          background: rgba(45, 110, 48, 0.03);
          border-radius: 0.875rem;
          margin-bottom: 0.875rem;
          border: 1px solid rgba(45, 110, 48, 0.1);
        }

        .cookie-category-header {
          display: flex;
          align-items: center;
          justify-content: space-between;
          margin-bottom: 0.5rem;
        }

        .cookie-category-title {
          font-size: 0.938rem;
          font-weight: 600;
          color: #0f2d12;
          margin: 0;
        }

        .cookie-category-desc {
          font-size: 0.813rem;
          line-height: 1.5;
          color: #0f2d12;
          opacity: 0.7;
          margin: 0;
        }

        .toggle-switch {
          position: relative;
          width: 44px;
          height: 24px;
          flex-shrink: 0;
        }

        .toggle-switch input {
          opacity: 0;
          width: 0;
          height: 0;
        }

        .toggle-slider {
          position: absolute;
          cursor: pointer;
          top: 0;
          left: 0;
          right: 0;
          bottom: 0;
          background-color: #e0e0e0;
          transition: 0.3s;
          border-radius: 12px;
        }

        .toggle-slider:before {
          position: absolute;
          content: "";
          height: 18px;
          width: 18px;
          left: 3px;
          bottom: 3px;
          background-color: white;
          transition: 0.3s;
          border-radius: 50%;
        }

        input:checked + .toggle-slider {
          background-color: #2d6e30;
        }

        input:checked + .toggle-slider:before {
          transform: translateX(20px);
        }

        input:disabled + .toggle-slider {
          opacity: 0.5;
          cursor: not-allowed;
        }

        .cookie-manager-footer {
          padding: 1.5rem;
          border-top: 1px solid rgba(45, 110, 48, 0.1);
          display: flex;
          gap: 0.75rem;
          flex-wrap: wrap;
        }

        .cookie-manager-btn {
          flex: 1;
          padding: 0.875rem 1.25rem;
          border-radius: 0.75rem;
          font-size: 0.875rem;
          font-weight: 600;
          cursor: pointer;
          transition: all 0.2s;
          border: none;
          min-width: 140px;
        }

        .cookie-manager-btn--primary {
          background: #1a4a1f;
          color: #ffffff;
        }

        .cookie-manager-btn--primary:hover {
          background: #143a18;
          transform: translateY(-1px);
          box-shadow: 0 4px 12px rgba(26, 74, 31, 0.3);
        }

        .cookie-manager-btn--secondary {
          background: rgba(26, 74, 31, 0.1);
          color: #1a4a1f;
        }

        .cookie-manager-btn--secondary:hover {
          background: rgba(26, 74, 31, 0.15);
        }
      `}</style>

      <div className="cookie-manager-modal">
        <div className="cookie-manager-header">
          <h3>Preferencias de cookies</h3>
          <button 
            onClick={onClose} 
            className="cookie-manager-close"
            aria-label="Cerrar"
          >
            <X size={20} />
          </button>
        </div>

        <div className="cookie-manager-body">
          {Object.values(COOKIE_CATEGORIES).map((category) => (
            <div key={category.id} className="cookie-category">
              <div className="cookie-category-header">
                <h3 className="cookie-category-title">{category.label}</h3>
                <label className="toggle-switch">
                  <input
                    type="checkbox"
                    checked={categories[category.id]}
                    disabled={category.required}
                    onChange={() => handleToggle(category.id)}
                  />
                  <span className="toggle-slider"></span>
                </label>
              </div>
              <p className="cookie-category-desc">{category.description}</p>
            </div>
          ))}
        </div>

        <div className="cookie-manager-footer">
          <button 
            onClick={handleSave}
            className="cookie-manager-btn cookie-manager-btn--primary"
          >
            Guardar preferencias
          </button>
          <button 
            onClick={onAcceptAll}
            className="cookie-manager-btn cookie-manager-btn--secondary"
          >
            Aceptar todas
          </button>
        </div>
      </div>
    </div>
  )
}

export default CookieManager
