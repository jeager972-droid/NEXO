import React from 'react'

export default class ErrorBoundary extends React.Component {
  constructor(props) {
    super(props)
    this.state = { hasError: false, error: null }
  }

  static getDerivedStateFromError(error) {
    return { hasError: true, error }
  }

  componentDidCatch(error, errorInfo) {
    console.error('ErrorBoundary caught an error:', error, errorInfo)
  }

  render() {
    if (this.state.hasError) {
      return (
        <div style={{
          position: 'fixed', inset: 0, zIndex: 99999,
          background: '#0a0f0d', color: '#ff5252',
          padding: '2rem', fontFamily: 'monospace',
          display: 'flex', flexDirection: 'column',
          gap: '1rem', overflow: 'auto'
        }}>
          <h2 style={{ color: '#ff5252', margin: 0 }}>⚠️ NEXO Error Boundary</h2>
          <p style={{ color: '#fff', fontSize: '1rem' }}>El sitio experimentó un error al renderizar:</p>
          <pre style={{
            background: '#111614', padding: '1rem',
            borderRadius: '4px', border: '1px solid #ff5252',
            color: '#ff8a80', whiteSpace: 'pre-wrap'
          }}>
            {this.state.error?.toString()}
          </pre>
          <p style={{ color: '#8da898', fontSize: '0.85rem' }}>
            Revisa la consola del navegador para más detalles.
          </p>
        </div>
      )
    }

    return this.props.children
  }
}
