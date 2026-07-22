/**
 * ErrorBoundary / NEXO Institucional
 * Responsabilidad: Capturar errores de renderizado en React y mostrar una interfaz de
 * error con recarga, evitando que toda la SPA se quede en blanco. Registra errores en consola.
 * Tipo: React Class Component (componentDidCatch).
 * Dependencias: React Component.
 * Propiedades: { children }.
 */
import { Component } from 'react'

export default class ErrorBoundary extends Component {
  constructor(props) {
    super(props)
    this.state = { hasError: false }
  }

  static getDerivedStateFromError() {
    return { hasError: true }
  }

  componentDidCatch(error, info) {
    console.error('ErrorBoundary caught:', error, info)
  }

  render() {
    if (this.state.hasError) {
      return (
        <div className="min-h-screen flex flex-col items-center justify-center bg-white dark:bg-slate-900 p-8">
          <div className="text-center space-y-6">
            <h2 className="text-3xl font-black text-red-600 uppercase tracking-tight">Error de carga</h2>
            <p className="text-gray-400 dark:text-slate-500 font-bold max-w-md">
              Hubo un problema al cargar este módulo. Por favor, reintenta recargando la página.
            </p>
            <button
              onClick={() => window.location.reload()}
              className="bg-institutional-900 hover:bg-institutional-800 text-white font-black py-4 px-10 rounded-2xl uppercase tracking-widest transition-all"
            >
              Reintentar
            </button>
          </div>
        </div>
      )
    }
    return this.props.children
  }
}
