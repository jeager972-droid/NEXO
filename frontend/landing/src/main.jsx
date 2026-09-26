/**
 * =============================================================================
 * main.jsx — Punto de entrada React de la landing NEXO.
 * =============================================================================
 * RESPONSABILIDAD:
 *   Crea el root de React 18 y renderiza <App /> en modo estricto dentro del
 *   contenedor #root definido en index.html. Importa los estilos globales.
 *
 * DEPENDENCIAS:
 *   - react / react-dom/client
 *   - App.jsx
 *   - index.css
 * =============================================================================
 */

import React from 'react'
import ReactDOM from 'react-dom/client'
import App from './App'
import './index.css'

ReactDOM.createRoot(document.getElementById('root')).render(
  <React.StrictMode>
    <App />
  </React.StrictMode>,
)
