import { useRef, useEffect, useState } from 'react'
import { useGLTF } from '@react-three/drei'
import { Canvas } from '@react-three/fiber'
import gsap from 'gsap'

// Componente de descarga animado con Three.js + GSAP
// Efecto: Al hacer click, muestra un portal 3D rotando mientras descarga

function DownloadPortal({ isActive }) {
  const meshRef = useRef()
  const particlesRef = useRef()

  useEffect(() => {
    if (!meshRef.current) return

    if (isActive) {
      // Animación de activación con GSAP
      gsap.to(meshRef.current.rotation, {
        y: Math.PI * 4,
        duration: 2,
        ease: 'power2.inOut',
      })
      gsap.to(meshRef.current.scale, {
        x: 1.2,
        y: 1.2,
        z: 1.2,
        duration: 0.5,
        ease: 'elastic.out(1, 0.5)',
      })
    } else {
      gsap.to(meshRef.current.scale, {
        x: 1,
        y: 1,
        z: 1,
        duration: 0.3,
        ease: 'power2.out',
      })
    }
  }, [isActive])

  useEffect(() => {
    if (!meshRef.current) return
    
    // Rotación continua suave
    gsap.to(meshRef.current.rotation, {
      z: Math.PI * 2,
      duration: 20,
      repeat: -1,
      ease: 'none',
    })
  }, [])

  return (
    <group>
      {/* Anillo exterior */}
      <mesh ref={meshRef} position={[0, 0, 0]}>
        <torusGeometry args={[1.2, 0.15, 16, 100]} />
        <meshStandardMaterial 
          color="#2d6e30" 
          emissive="#2d6e30"
          emissiveIntensity={isActive ? 0.8 : 0.3}
          metalness={0.8}
          roughness={0.2}
        />
      </mesh>

      {/* Núcleo brillante */}
      <mesh position={[0, 0, 0]} scale={isActive ? 1.5 : 1}>
        <sphereGeometry args={[0.3, 32, 32]} />
        <meshStandardMaterial 
          color="#3ddc84"
          emissive="#3ddc84"
          emissiveIntensity={isActive ? 2 : 1}
          toneMapped={false}
        />
      </mesh>

      {/* Anillo interior pulsante */}
      <mesh ref={particlesRef} position={[0, 0, 0]} rotation={[Math.PI / 2, 0, 0]}>
        <torusGeometry args={[0.8, 0.08, 16, 100]} />
        <meshStandardMaterial 
          color="#61d89f"
          emissive="#61d89f"
          emissiveIntensity={isActive ? 1.5 : 0.5}
          transparent
          opacity={0.7}
        />
      </mesh>
    </group>
  )
}

export default function AnimatedDownloadButton({ 
  href, 
  filename = 'nexo.apk',
  children,
  className = '',
  id,
  ...props 
}) {
  const [isDownloading, setIsDownloading] = useState(false)
  const [progress, setProgress] = useState(0)
  const canvasRef = useRef()
  const buttonRef = useRef()
  const progressBarRef = useRef()

  const handleDownload = async (e) => {
    e.preventDefault()
    setIsDownloading(true)
    setProgress(0)

    try {
      // Animación del botón
      gsap.to(buttonRef.current, {
        scale: 0.95,
        duration: 0.1,
        yoyo: true,
        repeat: 1,
      })

      // Simular progreso (en producción, usar fetch con stream para progreso real)
      const progressTween = gsap.to(
        { value: 0 },
        {
          value: 100,
          duration: 1.5,
          ease: 'power2.inOut',
          onUpdate: function() {
            setProgress(Math.floor(this.targets()[0].value))
          },
        }
      )

      // Descargar archivo real
      const response = await fetch(href)
      const blob = await response.blob()
      const url = window.URL.createObjectURL(blob)
      const a = document.createElement('a')
      a.href = url
      a.download = filename
      document.body.appendChild(a)
      a.click()
      window.URL.revokeObjectURL(url)
      document.body.removeChild(a)

      // Completar animación
      await progressTween.then()
      
      // Efecto de éxito
      gsap.to(buttonRef.current, {
        scale: 1.1,
        duration: 0.2,
        yoyo: true,
        repeat: 1,
        ease: 'elastic.out(1, 0.3)',
      })

      setTimeout(() => {
        setIsDownloading(false)
        setProgress(0)
      }, 800)

    } catch (error) {
      console.error('Error al descargar:', error)
      setIsDownloading(false)
      setProgress(0)
      
      // Fallback: descarga directa
      window.location.href = href
    }
  }

  useEffect(() => {
    if (!canvasRef.current || !isDownloading) return

    gsap.fromTo(
      canvasRef.current,
      { opacity: 0, scale: 0.8 },
      { opacity: 1, scale: 1, duration: 0.4, ease: 'back.out(1.7)' }
    )
  }, [isDownloading])

  return (
    <div style={{ position: 'relative', display: 'inline-block' }}>
      <button
        ref={buttonRef}
        onClick={handleDownload}
        className={className}
        id={id}
        disabled={isDownloading}
        style={{
          position: 'relative',
          overflow: 'visible',
          cursor: isDownloading ? 'wait' : 'pointer',
          ...props.style,
        }}
        {...props}
      >
        {children}

        {/* Barra de progreso */}
        {isDownloading && (
          <div
            ref={progressBarRef}
            style={{
              position: 'absolute',
              bottom: 0,
              left: 0,
              height: '3px',
              width: `${progress}%`,
              background: 'linear-gradient(90deg, #2d6e30, #3ddc84)',
              transition: 'width 0.3s ease',
              borderRadius: '0 0 1rem 1rem',
            }}
          />
        )}
      </button>

      {/* Canvas 3D - Portal de descarga */}
      {isDownloading && (
        <div
          ref={canvasRef}
          style={{
            position: 'fixed',
            top: '50%',
            left: '50%',
            transform: 'translate(-50%, -50%)',
            width: '300px',
            height: '300px',
            pointerEvents: 'none',
            zIndex: 9999,
          }}
        >
          <Canvas
            camera={{ position: [0, 0, 5], fov: 50 }}
            style={{ background: 'transparent' }}
          >
            <ambientLight intensity={0.5} />
            <pointLight position={[10, 10, 10]} intensity={1} />
            <pointLight position={[-10, -10, -10]} intensity={0.5} color="#3ddc84" />
            <DownloadPortal isActive={isDownloading} />
          </Canvas>

          {/* Overlay de progreso */}
          <div
            style={{
              position: 'absolute',
              top: '50%',
              left: '50%',
              transform: 'translate(-50%, -50%)',
              textAlign: 'center',
              color: '#fff',
              fontWeight: 800,
              fontSize: '2rem',
              textShadow: '0 2px 10px rgba(0,0,0,0.5)',
            }}
          >
            {progress}%
          </div>

          {/* Mensaje de descarga */}
          <div
            style={{
              position: 'absolute',
              bottom: '20px',
              left: '50%',
              transform: 'translateX(-50%)',
              color: '#fff',
              fontSize: '0.875rem',
              fontWeight: 600,
              textShadow: '0 2px 10px rgba(0,0,0,0.5)',
              whiteSpace: 'nowrap',
            }}
          >
            Descargando {filename}...
          </div>

          {/* Backdrop blur */}
          <div
            style={{
              position: 'fixed',
              inset: 0,
              background: 'rgba(0, 0, 0, 0.7)',
              backdropFilter: 'blur(10px)',
              zIndex: -1,
            }}
          />
        </div>
      )}
    </div>
  )
}
