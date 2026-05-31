import { Link } from 'react-router-dom'

export default function DashboardPage() {
  return (
    <div
      style={{
        minHeight: '100vh',
        background: 'linear-gradient(180deg, var(--nx-surface) 0%, var(--nx-deep) 100%)',
        display: 'flex',
        flexDirection: 'column',
        alignItems: 'center',
        justifyContent: 'center',
        padding: '2rem',
        fontFamily: 'var(--nx-font)',
        color: 'var(--nx-green)',
      }}
    >
      <h1 style={{ fontSize: '2rem', fontWeight: 800, marginBottom: '1rem' }}>
        Dashboard NEXO
      </h1>
      <p style={{ fontSize: '1rem', color: 'var(--nx-muted)', marginBottom: '2rem' }}>
        En construcción. Pronto encontrarás aquí información sobre quién construyó NEXO.
      </p>
      <Link
        to="/"
        style={{
          fontSize: '0.9rem',
          color: 'var(--nx-green)',
          textDecoration: 'none',
          display: 'flex',
          alignItems: 'center',
          gap: '0.4rem',
          fontWeight: 600,
        }}
      >
        <svg width="14" height="14" viewBox="0 0 14 14" fill="none" aria-hidden="true">
          <path d="M12 7H2M6 3 2 7l4 4" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" strokeLinejoin="round"/>
        </svg>
        Volver al inicio
      </Link>
    </div>
  )
}
