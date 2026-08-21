/**
 * =============================================================================
 * DownloadButton.jsx — Botón de descarga animado para la landing NEXO.
 * =============================================================================
 * RESPONSABILIDAD:
 *   Renderiza un botón con efecto de rebote GSAP al hacer clic y abre el href
 *   en una nueva pestaña. Usado para las tarjetas de instalación PWA en la
 *   sección de descargas. Implementado con forwardRef para exposición del DOM.
 *
 * DEPENDENCIAS:
 *   - react (forwardRef, useRef, useImperativeHandle)
 *   - gsap
 * =============================================================================
 */

import { useRef, forwardRef, useImperativeHandle } from 'react'
import gsap from 'gsap'

// Componente de descarga simple con efecto de rebote

const AnimatedDownloadButton = forwardRef(function AnimatedDownloadButton({
  href,
  children,
  className = '',
  id,
  ...props
}, ref) {
  const buttonRef = useRef()

  useImperativeHandle(ref, () => buttonRef.current)

  const handleDownload = (e) => {
    e.preventDefault()

    // Efecto de rebote con GSAP
    gsap.timeline()
      .to(buttonRef.current, {
        scale: 0.92,
        duration: 0.1,
        ease: 'power2.in',
      })
      .to(buttonRef.current, {
        scale: 1.05,
        duration: 0.2,
        ease: 'elastic.out(1, 0.3)',
      })
      .to(buttonRef.current, {
        scale: 1,
        duration: 0.15,
        ease: 'power2.out',
      })

    // Abrir PWA en nueva pestaña
    window.open(href, '_blank', 'noopener,noreferrer')
  }

  return (
    <button
      ref={buttonRef}
      onClick={handleDownload}
      className={className}
      id={id}
      style={{
        ...props.style,
      }}
      {...props}
    >
      {children}
    </button>
  )
})

export default AnimatedDownloadButton
