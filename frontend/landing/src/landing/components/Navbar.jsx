/**
 * =============================================================================
 * Navbar.jsx — Barra de navegación flotante de la landing NEXO.
 * =============================================================================
 * RESPONSABILIDAD:
 *   Muestra el logo y enlaces de anclaje a las secciones principales de la
 *   landing. Diseño limpio sin CTAs; estilos definidos en index.css.
 *
 * DEPENDENCIAS:
 *   - Ninguna (componente puro)
 * =============================================================================
 */

// CAMBIO 1: Navbar limpio — solo logo + links internos. Sin CTAs.
export default function Navbar() {
  return (
    <nav className="nx-navbar" aria-label="Navegación principal">
      <span className="nx-navbar__logo">NEXO</span>

      <ul className="nx-navbar__links">
        <li><a href="#como-funciona">Cómo funciona</a></li>
        <li><a href="#el-nodo">El nodo</a></li>
        <li><a href="#roles">Roles</a></li>
        <li><a href="#seguridad">Seguridad</a></li>
        <li><a href="#contacto">Contacto</a></li>
      </ul>
    </nav>
  )
}
