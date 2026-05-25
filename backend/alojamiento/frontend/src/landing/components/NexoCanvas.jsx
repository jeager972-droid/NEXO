import { Canvas, useFrame } from '@react-three/fiber'
import { Environment } from '@react-three/drei'
import { Suspense, useRef, useMemo, useState, useEffect, useCallback } from 'react'
import * as THREE from 'three'
import NexoModel from '../core/NexoModel'

// Helper function to create canvas-based textures for the user/group avatars dynamically
function createAvatarTexture(avatarType) {
  const canvas = document.createElement('canvas')
  canvas.width = 256
  canvas.height = 256
  const ctx = canvas.getContext('2d')

  ctx.imageSmoothingEnabled = true
  ctx.clearRect(0, 0, 256, 256)

  // 1. Outer dashed indicator orbit ring
  ctx.strokeStyle = 'rgba(45, 110, 48, 0.45)'
  ctx.lineWidth = 3
  ctx.setLineDash([8, 12])
  ctx.beginPath()
  ctx.arc(128, 128, 122, 0, Math.PI * 2)
  ctx.stroke()

  // Reset line dash
  ctx.setLineDash([])

  // 2. Base glowing circle with a gradient from primary green to light green
  const grad = ctx.createRadialGradient(128, 128, 60, 128, 128, 116)
  grad.addColorStop(0, '#56b85a') // light green glow
  grad.addColorStop(1, '#2d6e30') // brand green
  ctx.fillStyle = grad
  ctx.beginPath()
  ctx.arc(128, 128, 114, 0, Math.PI * 2)
  ctx.fill()

  // 3. Crisp white outer ring
  ctx.strokeStyle = 'rgba(255, 255, 255, 0.85)'
  ctx.lineWidth = 5
  ctx.beginPath()
  ctx.arc(128, 128, 110, 0, Math.PI * 2)
  ctx.stroke()

  // 4. Draw user avatar shapes in light mode background color
  ctx.fillStyle = '#ffffff'

  const drawUser = (cx, cy, scale) => {
    // Head circle
    ctx.beginPath()
    ctx.arc(cx, cy - 18 * scale, 22 * scale, 0, Math.PI * 2)
    ctx.fill()

    // Shoulders base arc
    ctx.beginPath()
    ctx.arc(cx, cy + 35 * scale, 40 * scale, Math.PI, Math.PI * 2)
    ctx.fill()
  }

  if (avatarType === 'single') {
    drawUser(128, 128, 1.25)
  } else if (avatarType === 'group') {
    drawUser(98, 138, 0.95)
    drawUser(158, 124, 0.95)
  } else if (avatarType === 'group3') {
    drawUser(85, 142, 0.8)
    drawUser(171, 142, 0.8)
    drawUser(128, 116, 0.8)
  }

  const texture = new THREE.CanvasTexture(canvas)
  texture.colorSpace = THREE.SRGBColorSpace
  texture.needsUpdate = true
  return texture
}

// Helper to create a glowing network dot texture
function createDotTexture() {
  const canvas = document.createElement('canvas')
  canvas.width = 64
  canvas.height = 64
  const ctx = canvas.getContext('2d')
  
  ctx.clearRect(0, 0, 64, 64)
  
  const grad = ctx.createRadialGradient(32, 32, 2, 32, 32, 30)
  grad.addColorStop(0, '#56b85a')
  grad.addColorStop(0.3, 'rgba(45, 110, 48, 0.8)')
  grad.addColorStop(1, 'rgba(45, 110, 48, 0)')
  
  ctx.fillStyle = grad
  ctx.beginPath()
  ctx.arc(32, 32, 30, 0, Math.PI * 2)
  ctx.fill()
  
  const texture = new THREE.CanvasTexture(canvas)
  texture.colorSpace = THREE.SRGBColorSpace
  texture.needsUpdate = true
  return texture
}

// 16 interconnected nodes forming a beautiful institutional network
const NETWORK_NODES = [
  // Major avatar nodes
  { pos: [-3.0, 1.8, -0.4], size: 0.52, type: 'group' },
  { pos: [1.8, 1.2, 0.5], size: 0.82, type: 'single' },
  { pos: [-1.2, -0.6, 0.3], size: 0.65, type: 'group3' },
  { pos: [2.8, -1.6, -0.2], size: 0.70, type: 'group' },
  { pos: [-3.4, -1.4, -0.1], size: 0.45, type: 'single' },
  { pos: [-0.2, 2.0, 0.2], size: 0.55, type: 'single' },
  
  // Minor connection junction dots
  { pos: [-2.0, 0.8, -0.8], size: 0.16, type: 'dot' },
  { pos: [0.6, 2.4, -0.4], size: 0.18, type: 'dot' },
  { pos: [-0.6, 0.6, 0.8], size: 0.14, type: 'dot' },
  { pos: [3.2, 0.4, -0.6], size: 0.15, type: 'dot' },
  { pos: [0.1, -1.6, 0.3], size: 0.16, type: 'dot' },
  { pos: [1.1, -0.4, -0.5], size: 0.15, type: 'dot' },
  { pos: [-2.4, -2.4, 0.4], size: 0.13, type: 'dot' },
  { pos: [3.8, -0.8, 0.2], size: 0.14, type: 'dot' },
  { pos: [-1.8, -1.8, -0.6], size: 0.15, type: 'dot' },
  { pos: [0.2, -0.2, -1.2], size: 0.13, type: 'dot' },
]

function FloatingParticles({ count = 80 }) {
  const pointsRef = useRef()
  
  const [positions, speeds] = useMemo(() => {
    const pos = []
    const sp = []
    for (let i = 0; i < count; i++) {
      pos.push(
        (Math.random() - 0.5) * 11,
        (Math.random() - 0.5) * 7,
        (Math.random() - 0.5) * 4
      )
      sp.push(
        (Math.random() - 0.5) * 0.05,
        (Math.random() - 0.5) * 0.05,
        (Math.random() - 0.5) * 0.05
      )
    }
    return [new Float32Array(pos), new Float32Array(sp)]
  }, [count])
  
  useFrame((state, delta) => {
    if (!pointsRef.current) return
    const attr = pointsRef.current.geometry.attributes.position
    for (let i = 0; i < count; i++) {
      const idx = i * 3
      attr.array[idx] += speeds[idx] * delta * 4
      attr.array[idx + 1] += speeds[idx + 1] * delta * 4
      attr.array[idx + 2] += speeds[idx + 2] * delta * 4
      
      // Wrap limits
      if (Math.abs(attr.array[idx]) > 5.5) attr.array[idx] *= -0.95
      if (Math.abs(attr.array[idx + 1]) > 3.5) attr.array[idx + 1] *= -0.95
      if (Math.abs(attr.array[idx + 2]) > 2.0) attr.array[idx + 2] *= -0.95
    }
    attr.needsUpdate = true
  })
  
  return (
    <points ref={pointsRef}>
      <bufferGeometry>
        <bufferAttribute
          attach="attributes-position"
          args={[positions, 3]}
        />
      </bufferGeometry>
      <pointsMaterial color="#56b85a" size={0.06} transparent opacity={0.65} />
    </points>
  )
}

function InstitutionalNetwork({ onHoverChange }) {
  const groupRef = useRef()

  // Generate CanvasTextures once
  const textures = useMemo(() => {
    return {
      single: createAvatarTexture('single'),
      group: createAvatarTexture('group'),
      group3: createAvatarTexture('group3'),
      dot: createDotTexture(),
    }
  }, [])

  // Calculate static distance-based connections to draw lines
  const connections = useMemo(() => {
    const points = []
    for (let i = 0; i < NETWORK_NODES.length; i++) {
      for (let j = i + 1; j < NETWORK_NODES.length; j++) {
        const p1 = NETWORK_NODES[i].pos
        const p2 = NETWORK_NODES[j].pos
        const dist = Math.sqrt(
          (p1[0] - p2[0]) ** 2 +
          (p1[1] - p2[1]) ** 2 +
          (p1[2] - p2[2]) ** 2
        )
        // Draw connection if close enough
        if (dist < 3.8) {
          points.push(new THREE.Vector3(...p1))
          points.push(new THREE.Vector3(...p2))
        }
      }
    }
    return new THREE.BufferGeometry().setFromPoints(points)
  }, [])

  useFrame((state) => {
    if (groupRef.current) {
      // Gentle floating sway & yaw rotation of the entire network graph
      const time = state.clock.getElapsedTime()
      groupRef.current.rotation.y = Math.sin(time * 0.15) * 0.2
      groupRef.current.rotation.x = Math.cos(time * 0.1) * 0.1
      groupRef.current.position.y = Math.sin(time * 0.3) * 0.08
    }
  })

  const handlePointerOver = (e) => {
    e.stopPropagation()
    if (onHoverChange) onHoverChange(true)
  }

  const handlePointerOut = (e) => {
    e.stopPropagation()
    if (onHoverChange) onHoverChange(false)
  }

  return (
    <group 
      ref={groupRef} 
      onPointerOver={handlePointerOver}
      onPointerOut={handlePointerOut}
    >
      {/* ── Floating Background Particles ── */}
      <FloatingParticles count={90} />

      {/* ── Network Connection Lines ── */}
      <lineSegments geometry={connections}>
        <lineBasicMaterial color="#2d6e30" transparent opacity={0.25} linewidth={1} />
      </lineSegments>

      {/* ── Network Nodes (Sprites that always face the camera) ── */}
      {NETWORK_NODES.map((node, i) => {
        const tex = textures[node.type] || textures.dot
        return (
          <sprite 
            key={i} 
            position={node.pos} 
            scale={[node.size * 2, node.size * 2, 1]}
          >
            <spriteMaterial map={tex} transparent />
          </sprite>
        )
      })}
    </group>
  )
}

// Drag overlay component that captures orbital drag without blocking page scroll
function DragOverlay({ onDrag, onDragStart, onDragEnd }) {
  const overlayRef = useRef()

  useEffect(() => {
    const el = overlayRef.current
    if (!el) return

    let isDragging = false
    let prev = { x: 0, y: 0 }
    let resumeTimer = null

    const startDrag = (x, y) => {
      isDragging = true
      prev = { x, y }
      el.style.cursor = 'grabbing'
      clearTimeout(resumeTimer)
      if (onDragStart) onDragStart()
    }

    const moveDrag = (x, y) => {
      if (!isDragging) return
      const dx = x - prev.x
      const dy = y - prev.y
      prev = { x, y }
      if (onDrag) onDrag(dx, dy)
    }

    const endDrag = () => {
      if (!isDragging) return
      isDragging = false
      el.style.cursor = 'grab'
      // Resume auto-rotation after 2s
      resumeTimer = setTimeout(() => {
        if (onDragEnd) onDragEnd()
      }, 2000)
    }

    // Mouse
    const onMouseDown = (e) => startDrag(e.clientX, e.clientY)
    const onMouseMove = (e) => moveDrag(e.clientX, e.clientY)
    const onMouseUp = () => endDrag()

    // Touch — discriminate between vertical scroll and horizontal rotation
    const onTouchStart = (e) => startDrag(e.touches[0].clientX, e.touches[0].clientY)
    const onTouchMove = (e) => {
      if (!isDragging) return
      const dx = e.touches[0].clientX - prev.x
      const dy = e.touches[0].clientY - prev.y
      // If movement is mostly vertical → release to page scroll
      if (Math.abs(dy) > Math.abs(dx) * 1.5 && Math.abs(dx) < 8) {
        endDrag()
        return
      }
      // Horizontal or diagonal → rotate the model
      e.preventDefault() // only called when rotating, not scrolling
      moveDrag(e.touches[0].clientX, e.touches[0].clientY)
    }
    const onTouchEnd = () => endDrag()

    el.addEventListener('mousedown', onMouseDown)
    window.addEventListener('mousemove', onMouseMove)
    window.addEventListener('mouseup', onMouseUp)
    el.addEventListener('touchstart', onTouchStart, { passive: true })
    el.addEventListener('touchmove', onTouchMove, { passive: false }) // non-passive so preventDefault works
    el.addEventListener('touchend', onTouchEnd, { passive: true })

    return () => {
      clearTimeout(resumeTimer)
      el.removeEventListener('mousedown', onMouseDown)
      window.removeEventListener('mousemove', onMouseMove)
      window.removeEventListener('mouseup', onMouseUp)
      el.removeEventListener('touchstart', onTouchStart)
      el.removeEventListener('touchmove', onTouchMove) // non-passive cleanup
      el.removeEventListener('touchend', onTouchEnd)
    }
  }, [onDrag, onDragStart, onDragEnd])

  const isMobileDevice = typeof window !== 'undefined' && window.innerWidth <= 768

  return (
    <div
      ref={overlayRef}
      style={{
        position: 'absolute',
        inset: 0,
        zIndex: 10,
        cursor: 'grab',
        touchAction: isMobileDevice ? 'pan-y' : 'none', // Mobile: allow vertical scroll, JS handles horizontal rotation
      }}
    />
  )
}

export default function NexoCanvas({ type, scale = 1.0, showShield = false, coldLight = false, interactive = true, scrollProgress }) {
  const [isUserDragging, setIsUserDragging] = useState(false)
  const [isMobile, setIsMobile] = useState(false)
  const dragDeltaRef = useRef({ dx: 0, dy: 0 })
  const modelRef = useRef(null)

  useEffect(() => {
    const handleResize = () => {
      setIsMobile(window.innerWidth <= 768)
    }
    handleResize()
    window.addEventListener('resize', handleResize)
    return () => window.removeEventListener('resize', handleResize)
  }, [])

  // Callbacks passed to DragOverlay
  const handleDrag = useCallback((dx, dy) => {
    dragDeltaRef.current = { dx, dy }
    setIsUserDragging(true)
  }, [])

  const handleDragStart = useCallback(() => {
    setIsUserDragging(true)
  }, [])

  const handleDragEnd = useCallback(() => {
    setIsUserDragging(false)
    dragDeltaRef.current = { dx: 0, dy: 0 }
  }, [])

  // 3-point professional lighting setup — light-mode palette
  const keyLightColor   = '#ffffff'
  const keyIntensity    = coldLight ? 2.5 : 2.2
  const fillLightColor  = '#c8e6c8'
  const fillIntensity   = coldLight ? 1.2 : 0.8
  const rimLightColor   = '#2d6e30'
  const rimIntensity    = coldLight ? 1.8 : 1.5

  const finalScale = scale  // Scale controlled per-section, no global mobile penalty

  const containerStyle = { position: 'relative', width: '100%', height: '100%' }

  return (
    <div style={containerStyle}>
      {/* Canvas — pointer-events: none so it NEVER blocks page scroll */}
      <Canvas
        camera={{
          position: [0, 0, type === 'grid' ? 9 : 6],
          fov: 45,
        }}
        gl={{
          antialias: true,             // antialias ON for mobile quality
          alpha: true,
          powerPreference: 'high-performance',
          precision: isMobile ? 'mediump' : 'highp',
        }}
        dpr={[1, Math.min(window.devicePixelRatio, 2)]}
        shadows={!isMobile}            // sin sombras en móvil
        frameloop={isMobile ? "always" : "demand"} // móvil: always garantiza rotación automática
        style={{ width: '100%', height: '100%', pointerEvents: 'none' }}
      >
        {/* 3-point lighting */}
        <ambientLight intensity={isMobile ? 0.6 : 0.3} />
        <directionalLight
          position={[5, 5, 5]}
          intensity={keyIntensity}
          color={keyLightColor}
          castShadow={!isMobile}
        />
        <directionalLight
          position={[-5, -2, 3]}
          intensity={fillIntensity}
          color={fillLightColor}
        />
        {/* RIM LIGHT: activa en móvil y desktop */}
        <directionalLight
          position={[-3, 5, -5]}
          intensity={rimIntensity}
          color={rimLightColor}
        />
        {/* Environment: solo desktop por performance */}
        {!isMobile && <Environment preset="city" />}

        <Suspense fallback={null}>
          {type === 'grid' ? (
            <InstitutionalNetwork />
          ) : (
            <NexoModel
              type={type}
              scale={finalScale}
              showShield={showShield}
              scrollProgress={scrollProgress}
              isUserDragging={isUserDragging}
              dragDeltaRef={dragDeltaRef}
              modelRef={modelRef}
              dragSensitivity={isMobile ? 0.015 : 0.008}
              isMobile={isMobile}
            />
          )}
        </Suspense>
      </Canvas>

      {/* Drag overlay — sits above canvas, captures drag without blocking scroll */}
      {type !== 'grid' && (
        <DragOverlay
          onDrag={handleDrag}
          onDragStart={handleDragStart}
          onDragEnd={handleDragEnd}
        />
      )}

      {/* Touch rotation hint — mobile only, animated on first load */}
      {type !== 'grid' && isMobile && (
        <div
          aria-hidden="true"
          className="nx-touch-rotate-hint"
          style={{
            position: 'absolute',
            bottom: '0.85rem',
            left: '50%',
            transform: 'translateX(-50%)',
            fontSize: '0.6rem',
            fontWeight: 600,
            letterSpacing: '0.12em',
            textTransform: 'uppercase',
            color: 'rgba(74, 110, 76, 0.7)',
            whiteSpace: 'nowrap',
            pointerEvents: 'none',
            zIndex: 20,
          }}
        >
          ← Desliza para rotar →
        </div>
      )}
    </div>
  )
}
