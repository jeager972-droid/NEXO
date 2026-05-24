export default function Navbar() {
  return (
    <nav className="nx-navbar" aria-label="Navegación principal">
      <span className="nx-navbar__logo">NEXO</span>

      <ul className="nx-navbar__links">
        <li><a href="#como-funciona">Cómo funciona</a></li>
        <li><a href="#el-nodo">El nodo</a></li>
        <li><a href="#roles">Roles</a></li>
        <li><a href="#seguridad">Seguridad</a></li>
      </ul>

      <div style={{ display: 'flex', gap: '0.75rem', alignItems: 'center' }}>
        <a
          href="#login"
          style={{
            fontSize: '0.82rem',
            fontWeight: 500,
            color: 'var(--nx-muted)',
            transition: 'color 0.2s',
          }}
          onMouseEnter={e => e.target.style.color = 'var(--nx-text)'}
          onMouseLeave={e => e.target.style.color = 'var(--nx-muted)'}
        >
          Iniciar sesión
        </a>
        <a href="#demo" className="nx-btn-primary" style={{ padding: '0.5rem 1.1rem', fontSize: '0.82rem' }}>
          Demo
        </a>
      </div>
    </nav>
  )
}
