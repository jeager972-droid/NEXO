/**
 * ErrorBoundary / NEXO Institucional
 * Captura errores de renderizado y muestra una interfaz de recuperación.
 */
import { Component } from 'react';
import { AlertTriangle, RotateCcw } from 'lucide-react';
import { Surface } from './ui/Surface';
import { Button } from './ui/Button';

export default class ErrorBoundary extends Component {
  constructor(props) {
    super(props);
    this.state = { hasError: false, error: null };
  }

  static getDerivedStateFromError(error) {
    return { hasError: true, error };
  }

  componentDidCatch(error, info) {
    console.error('ErrorBoundary caught:', error, info);
  }

  render() {
    if (this.state.hasError) {
      return (
        <div className="flex min-h-[50vh] items-center justify-center p-6">
          <Surface className="max-w-md p-8 text-center">
            <div className="mx-auto flex h-14 w-14 items-center justify-center rounded-full bg-[var(--nx-subtle-bg-danger)] text-[var(--nx-danger)]">
              <AlertTriangle size={28} />
            </div>
            <h2 className="mt-5 text-h2 text-[var(--nx-text)]">Algo salió mal</h2>
            <p className="mt-2 text-body text-[var(--nx-text-muted)]">Se produjo un error inesperado. Puedes recargar la aplicación para continuar.</p>
            <Button className="mt-6" onClick={() => window.location.reload()} leftIcon={<RotateCcw size={18} />}>Recargar aplicación</Button>
          </Surface>
        </div>
      );
    }
    return this.props.children;
  }
}
