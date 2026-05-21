import { Suspense, useRef, useMemo, useState } from 'react'
import { useFrame } from '@react-three/fiber'
import { PerspectiveCamera, OrbitControls, Environment } from '@react-three/drei'
import * as THREE from 'three'
import NexoModel from '../../landing/core/NexoModel'

// Helper function to create canvas-based textures for the user/group avatars dynamically
function createAvatarTexture(avatarType) {
  const canvas = document.createElement('canvas')
  canvas.width = 256
  canvas.height = 256
  const ctx = canvas.getContext('2d')

  ctx.imageSmoothingEnabled = true
  ctx.clearRect(0, 0, 256, 256)

  // 1. Outer dashed indicator orbit ring
  ctx.strokeStyle = 'rgba(0, 229, 255, 0.45)'
  ctx.lineWidth = 3
  ctx.setLineDash([8, 12])
  ctx.beginPath()
  ctx.arc(128, 128, 122, 0, Math.PI * 2)
  ctx.stroke()

  // Reset line dash
  ctx.setLineDash([])

  // 2. Base glowing circle with a gradient from primary green to cyan
  const grad = ctx.createRadialGradient(128, 128, 60, 128, 128, 116)
  grad.addColorStop(0, '#00e676') // brand green
  grad.addColorStop(1, '#00e5ff') // cyan glow
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

  // 4. Draw user avatar shapes in deep landing background color
  ctx.fillStyle = '#0a0f0d'

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
  grad.addColorStop(0, '#00e5ff')
  grad.addColorStop(0.3, 'rgba(0, 230, 118, 0.8)')
  grad.addColorStop(1, 'rgba(0, 230, 118, 0)')
  
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
      <pointsMaterial color="#00e5ff" size={0.06} transparent opacity={0.65} />
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
        <lineBasicMaterial color="#00e5ff" transparent opacity={0.25} linewidth={1} />
      </lineSegments>

      {/* ── Network Nodes ── */}
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

export default function NexoModelViewer({ type, scale = 1.0, showShield = false }) {
  const orbitRef = useRef()
  const [zoomEnabled, setZoomEnabled] = useState(false)

  return (
    <>
      <PerspectiveCamera makeDefault position={[0, 0, type === 'grid' ? 9 : 6]} fov={45} />
      
      <ambientLight intensity={0.8} />
      <directionalLight position={[5, 8, 5]} intensity={2.2} castShadow />
      <directionalLight position={[-4, 2, -4]} intensity={0.8} />
      <pointLight position={[0, 4, 2]} intensity={1.5} color="#00e676" />
      <Environment preset="city" />

      <OrbitControls
        ref={orbitRef}
        enableZoom={zoomEnabled}
        enablePan={true}
        dampingFactor={0.06}
        enableDamping
        minPolarAngle={Math.PI * 0.05}
        maxPolarAngle={Math.PI * 0.95}
      />

      <Suspense fallback={null}>
        {type === 'grid' ? (
          <InstitutionalNetwork onHoverChange={setZoomEnabled} />
        ) : (
          <NexoModel 
            type={type} 
            scale={scale} 
            showShield={showShield} 
            onHoverChange={setZoomEnabled} 
          />
        )}
      </Suspense>
    </>
  )
}
