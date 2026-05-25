import { useRef } from 'react'
import gsap from 'gsap'

// Componente de descarga simple con efecto de rebote

export default function AnimatedDownloadButton({ 
  href, 
  filename = 'nexo.apk',
  children,
  className = '',
  id,
  ...props 
}) {
  const buttonRef = useRef()

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
    window.open('https://nexo-bay-mu.vercel.app/app/', '_blank', 'noopener,noreferrer')
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
}
