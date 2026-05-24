// Footer — minimal, legally compliant (Ley 1581 Colombia)

export default function Footer() {
  const year = new Date().getFullYear()

  return (
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

        {/* Legal links */}
        <nav aria-label="Legal">
          <ul style={{ display: 'flex', gap: '1.75rem', listStyle: 'none', flexWrap: 'wrap' }}>
            {[
              { label: 'Política de privacidad', href: '#privacidad' },
              { label: 'Tratamiento de datos', href: '#datos' },
              { label: 'Términos de uso', href: '#terminos' },
            ].map(({ label, href }) => (
              <li key={label}>
                <a
                  href={href}
                  style={{
                    fontSize: '0.78rem',
                    color: 'var(--nx-muted-2)',
                    transition: 'color 0.2s',
                  }}
                  onMouseEnter={e => e.target.style.color = 'var(--nx-muted)'}
                  onMouseLeave={e => e.target.style.color = 'var(--nx-muted-2)'}
                >
                  {label}
                </a>
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
  )
}
