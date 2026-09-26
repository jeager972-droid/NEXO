/**
 * =============================================================================
 * Footer.jsx — Pie de página de la landing NEXO.
 * =============================================================================
 * RESPONSABILIDAD:
 *   Muestra el logo, enlaces legales (privacidad, tratamiento de datos,
 *   términos) y copyright. Abre LegalModal al hacer clic en los enlaces.
 *
 * DEPENDENCIAS:
 *   - react hooks
 *   - LegalModal
 * =============================================================================
 */

// Footer — minimal, legally compliant (Ley 1581 Colombia)
import { useState } from 'react'
import LegalModal from '../components/LegalModal'

export default function Footer() {
  const year = new Date().getFullYear()
  const [legalModal, setLegalModal] = useState(null)

  const LEGAL_LINKS = [
    { label: 'Política de privacidad', type: 'privacy' },
    { label: 'Tratamiento de datos',   type: 'treatment' },
    { label: 'Términos de uso',        type: 'terms' },
  ]

  return (
    <>
      {legalModal && <LegalModal type={legalModal} onClose={() => setLegalModal(null)} />}

      <footer
        className="nx-footer"
        style={{
          paddingTop: '3rem',
          paddingBottom: '3rem',
          paddingLeft: 'var(--nx-section-px)',
          paddingRight: 'var(--nx-section-px)',
        }}
      >
        <div
          style={{
            maxWidth: '1280px',
            margin: '0 auto',
            display: 'flex',
            alignItems: 'center',
            justifyContent: 'space-between',
            gap: '2rem',
            flexWrap: 'wrap',
          }}
        >
          {/* Logo */}
          <span style={{ fontWeight: 800, fontSize: '1rem', letterSpacing: '-0.02em', color: 'var(--nx-white)' }}>
            NEXO
          </span>

          {/* Legal links — open LegalModal */}
          <nav aria-label="Legal">
            <ul style={{ display: 'flex', gap: '1.75rem', listStyle: 'none', flexWrap: 'wrap' }}>
              {LEGAL_LINKS.map(({ label, type }) => (
                <li key={type}>
                  <button
                    type="button"
                    onClick={() => setLegalModal(type)}
                    style={{
                      background: 'none',
                      border: 'none',
                      padding: 0,
                      fontSize: '0.78rem',
                      color: 'var(--nx-muted-2)',
                      cursor: 'pointer',
                      transition: 'color 0.2s',
                      fontFamily: 'inherit',
                    }}
                    onMouseEnter={e => e.currentTarget.style.color = 'var(--nx-muted)'}
                    onMouseLeave={e => e.currentTarget.style.color = 'var(--nx-muted-2)'}
                  >
                    {label}
                  </button>
                </li>
              ))}
            </ul>
          </nav>

          {/* Copyright */}
          <span style={{ fontSize: '0.75rem', color: 'var(--nx-muted-2)' }}>
            © {year} NEXO. Todos los derechos reservados.
          </span>
        </div>
      </footer>
    </>
  )
}
