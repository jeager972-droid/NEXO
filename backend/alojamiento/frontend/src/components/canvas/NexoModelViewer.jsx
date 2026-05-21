import { Suspense, useRef, useMemo, useEffect } from 'react'
import { useFrame } from '@react-three/fiber'
import {
  PerspectiveCamera,
  OrbitControls,
  Environment,
  useGLTF,
  MeshDistortMaterial,
} from '@react-three/drei'
import * as THREE from 'three'
import gsap from 'gsap'

const MODEL_PATH = '/assets/models/nodo.glb'
useGLTF.preload(MODEL_PATH)

// ─── Centralized visual config ───────────────────────────────────────────────
// To add a new section state: add one key with its parameters here.
// ─────────────────────────────────────────────────────────────────────────────
export const SECTION_CONFIG = {
  hero: {
    cameraPosition: [0, 0, 6],
    fov: 45,
    rotationSpeed: 0.12,
    floatAmplitude: 0.004,
    floatSpeed: 0.3,
    accentColor: '#00e676',
    ambientIntensity: 0.8,
    dirLightIntensity: 2.2,
    showShield: false,
    shieldDistort: 0.05,
    shieldSpeed: 0.4,
    shieldOpacity: 0.0,
    hoverRotationScale: 1.8,
    hoverShieldDistort: 0.22,
    hoverShieldSpeed: 1.4,
  },
  vision: {
    cameraPosition: [0, 0, 6],
    fov: 42,
    rotationSpeed: 0.08,
    floatAmplitude: 0.006,
    floatSpeed: 0.2,
    accentColor: '#00e5ff',
    ambientIntensity: 1.0,
    dirLightIntensity: 1.8,
    showShield: false,
    shieldDistort: 0.05,
    shieldSpeed: 0.4,
    shieldOpacity: 0.0,
    hoverRotationScale: 2.0,
    hoverShieldDistort: 0.05,
    hoverShieldSpeed: 0.4,
  },
  exploded: {
    cameraPosition: [0, 0, 7],
    fov: 50,
    rotationSpeed: 0.18,
    floatAmplitude: 0.003,
    floatSpeed: 0.4,
    accentColor: '#00e676',
    ambientIntensity: 0.6,
    dirLightIntensity: 2.8,
    showShield: false,
    shieldDistort: 0.05,
    shieldSpeed: 0.4,
    shieldOpacity: 0.0,
    hoverRotationScale: 1.6,
    hoverShieldDistort: 0.05,
    hoverShieldSpeed: 0.4,
  },
  cifrado: {
    cameraPosition: [0, 0, 6],
    fov: 45,
    rotationSpeed: 0.06,
    floatAmplitude: 0.004,
    floatSpeed: 0.3,
    accentColor: '#00e676',
    ambientIntensity: 0.9,
    dirLightIntensity: 2.0,
    showShield: true,
    shieldDistort: 0.05,
    shieldSpeed: 0.4,
    shieldOpacity: 0.45,
    hoverRotationScale: 1.5,
    hoverShieldDistort: 0.22,
    hoverShieldSpeed: 1.4,
  },
  grid: {
    cameraPosition: [0, 0, 9],
    fov: 45,
    rotationSpeed: 0.15,
    floatAmplitude: 0.08,
    floatSpeed: 0.3,
    accentColor: '#00e5ff',
    ambientIntensity: 0.8,
    dirLightIntensity: 2.0,
    showShield: false,
    shieldDistort: 0.0,
    shieldSpeed: 0.0,
    shieldOpacity: 0.0,
    hoverRotationScale: 1.0,
    hoverShieldDistort: 0.0,
    hoverShieldSpeed: 0.0,
  },
}

// ─── Canvas texture utilities ─────────────────────────────────────────────────

function buildAvatarTexture(variant) {
  const c = document.createElement('canvas')
  c.width = 256; c.height = 256
  const ctx = c.getContext('2d')
  ctx.clearRect(0, 0, 256, 256)

  ctx.strokeStyle = 'rgba(0,229,255,0.45)'
  ctx.lineWidth = 3
  ctx.setLineDash([8, 12])
  ctx.beginPath(); ctx.arc(128, 128, 122, 0, Math.PI * 2); ctx.stroke()
  ctx.setLineDash([])

  const g = ctx.createRadialGradient(128, 128, 60, 128, 128, 116)
  g.addColorStop(0, '#00e676')
  g.addColorStop(1, '#00e5ff')
  ctx.fillStyle = g
  ctx.beginPath(); ctx.arc(128, 128, 114, 0, Math.PI * 2); ctx.fill()

  ctx.strokeStyle = 'rgba(255,255,255,0.85)'
  ctx.lineWidth = 5
  ctx.beginPath(); ctx.arc(128, 128, 110, 0, Math.PI * 2); ctx.stroke()

  ctx.fillStyle = '#0a0f0d'
  const drawUser = (cx, cy, s) => {
    ctx.beginPath(); ctx.arc(cx, cy - 18 * s, 22 * s, 0, Math.PI * 2); ctx.fill()
    ctx.beginPath(); ctx.arc(cx, cy + 35 * s, 40 * s, Math.PI, Math.PI * 2); ctx.fill()
  }
  if (variant === 'single')       drawUser(128, 128, 1.25)
  else if (variant === 'group')   { drawUser(98, 138, 0.95);  drawUser(158, 124, 0.95) }
  else if (variant === 'group3')  { drawUser(85, 142, 0.8);   drawUser(171, 142, 0.8); drawUser(128, 116, 0.8) }

  const t = new THREE.CanvasTexture(c)
  t.colorSpace = THREE.SRGBColorSpace
  return t
}

function buildDotTexture() {
  const c = document.createElement('canvas')
  c.width = 64; c.height = 64
  const ctx = c.getContext('2d')
  ctx.clearRect(0, 0, 64, 64)
  const g = ctx.createRadialGradient(32, 32, 2, 32, 32, 30)
  g.addColorStop(0, '#00e5ff')
  g.addColorStop(0.3, 'rgba(0,230,118,0.8)')
  g.addColorStop(1, 'rgba(0,230,118,0)')
  ctx.fillStyle = g
  ctx.beginPath(); ctx.arc(32, 32, 30, 0, Math.PI * 2); ctx.fill()
  const t = new THREE.CanvasTexture(c)
  t.colorSpace = THREE.SRGBColorSpace
  return t
}

// ─── Network graph data ───────────────────────────────────────────────────────

const NETWORK_NODES = [
  { pos: [-3.0,  1.8, -0.4], size: 0.52, type: 'group'  },
  { pos: [ 1.8,  1.2,  0.5], size: 0.82, type: 'single' },
  { pos: [-1.2, -0.6,  0.3], size: 0.65, type: 'group3' },
  { pos: [ 2.8, -1.6, -0.2], size: 0.70, type: 'group'  },
  { pos: [-3.4, -1.4, -0.1], size: 0.45, type: 'single' },
  { pos: [-0.2,  2.0,  0.2], size: 0.55, type: 'single' },
  { pos: [-2.0,  0.8, -0.8], size: 0.16, type: 'dot'    },
  { pos: [ 0.6,  2.4, -0.4], size: 0.18, type: 'dot'    },
  { pos: [-0.6,  0.6,  0.8], size: 0.14, type: 'dot'    },
  { pos: [ 3.2,  0.4, -0.6], size: 0.15, type: 'dot'    },
  { pos: [ 0.1, -1.6,  0.3], size: 0.16, type: 'dot'    },
  { pos: [ 1.1, -0.4, -0.5], size: 0.15, type: 'dot'    },
  { pos: [-2.4, -2.4,  0.4], size: 0.13, type: 'dot'    },
  { pos: [ 3.8, -0.8,  0.2], size: 0.14, type: 'dot'    },
  { pos: [-1.8, -1.8, -0.6], size: 0.15, type: 'dot'    },
  { pos: [ 0.2, -0.2, -1.2], size: 0.13, type: 'dot'    },
]

// ─── Sub-components ───────────────────────────────────────────────────────────

function FloatingParticles({ count = 80 }) {
  const ref = useRef()
  const [positions, speeds] = useMemo(() => {
    const pos = [], sp = []
    for (let i = 0; i < count; i++) {
      pos.push((Math.random() - 0.5) * 11, (Math.random() - 0.5) * 7, (Math.random() - 0.5) * 4)
      sp.push((Math.random() - 0.5) * 0.05, (Math.random() - 0.5) * 0.05, (Math.random() - 0.5) * 0.05)
    }
    return [new Float32Array(pos), new Float32Array(sp)]
  }, [count])

  useFrame((_, delta) => {
    if (!ref.current) return
    const attr = ref.current.geometry.attributes.position
    for (let i = 0; i < count; i++) {
      const x = i * 3
      attr.array[x]     += speeds[x]     * delta * 4
      attr.array[x + 1] += speeds[x + 1] * delta * 4
      attr.array[x + 2] += speeds[x + 2] * delta * 4
      if (Math.abs(attr.array[x])     > 5.5) attr.array[x]     *= -0.95
      if (Math.abs(attr.array[x + 1]) > 3.5) attr.array[x + 1] *= -0.95
      if (Math.abs(attr.array[x + 2]) > 2.0) attr.array[x + 2] *= -0.95
    }
    attr.needsUpdate = true
  })

  return (
    <points ref={ref}>
      <bufferGeometry>
        <bufferAttribute attach="attributes-position" args={[positions, 3]} />
      </bufferGeometry>
      <pointsMaterial color="#00e5ff" size={0.06} transparent opacity={0.65} />
    </points>
  )
}

function InstitutionalNetwork() {
  const groupRef = useRef()

  // GSAP intermediate target — no React state, no re-renders
  const gsapTarget = useRef({ rotationY: 0, rotationX: 0, positionY: 0 })
  const current    = useRef({ rotationY: 0, rotationX: 0, positionY: 0 })

  const textures = useMemo(() => ({
    single: buildAvatarTexture('single'),
    group:  buildAvatarTexture('group'),
    group3: buildAvatarTexture('group3'),
    dot:    buildDotTexture(),
  }), [])

  const connections = useMemo(() => {
    const pts = []
    for (let i = 0; i < NETWORK_NODES.length; i++) {
      for (let j = i + 1; j < NETWORK_NODES.length; j++) {
        const [p1, p2] = [NETWORK_NODES[i].pos, NETWORK_NODES[j].pos]
        const d = Math.hypot(p1[0]-p2[0], p1[1]-p2[1], p1[2]-p2[2])
        if (d < 3.8) { pts.push(new THREE.Vector3(...p1)); pts.push(new THREE.Vector3(...p2)) }
      }
    }
    return new THREE.BufferGeometry().setFromPoints(pts)
  }, [])

  useEffect(() => {
    // Drive gsapTarget with a slow idle tween loop
    const tick = () => {
      const t = performance.now() * 0.001
      gsapTarget.current.rotationY = Math.sin(t * 0.15) * 0.2
      gsapTarget.current.rotationX = Math.cos(t * 0.10) * 0.1
      gsapTarget.current.positionY = Math.sin(t * 0.30) * 0.08
    }
    const id = setInterval(tick, 16)
    return () => clearInterval(id)
  }, [])

  useFrame((_, delta) => {
    if (!groupRef.current) return
    const lf = 1 - Math.pow(0.04, delta)
    current.current.rotationY  = THREE.MathUtils.lerp(current.current.rotationY,  gsapTarget.current.rotationY,  lf)
    current.current.rotationX  = THREE.MathUtils.lerp(current.current.rotationX,  gsapTarget.current.rotationX,  lf)
    current.current.positionY  = THREE.MathUtils.lerp(current.current.positionY,  gsapTarget.current.positionY,  lf)
    groupRef.current.rotation.y   = current.current.rotationY
    groupRef.current.rotation.x   = current.current.rotationX
    groupRef.current.position.y   = current.current.positionY
  })

  return (
    <group ref={groupRef}>
      <FloatingParticles count={90} />
      <lineSegments geometry={connections}>
        <lineBasicMaterial color="#00e5ff" transparent opacity={0.25} />
      </lineSegments>
      {NETWORK_NODES.map((node, i) => (
        <sprite key={i} position={node.pos} scale={[node.size * 2, node.size * 2, 1]}>
          <spriteMaterial map={textures[node.type] ?? textures.dot} transparent />
        </sprite>
      ))}
    </group>
  )
}

// ─── GLTF model scene with GSAP → Lerp bridge ────────────────────────────────

function ModelScene({ type, scale = 1.0 }) {
  const cfg      = SECTION_CONFIG[type] ?? SECTION_CONFIG.hero
  const { scene } = useGLTF(MODEL_PATH)
  const outerRef  = useRef()
  const innerRef  = useRef()
  const shieldRef = useRef()

  // Plain object written by GSAP — zero React re-renders
  const gsapTarget = useRef({
    rotationSpeed: cfg.rotationSpeed,
    shieldDistort:  cfg.shieldDistort,
    shieldSpeed:    cfg.shieldSpeed,
    shieldOpacity:  cfg.showShield ? cfg.shieldOpacity : 0,
  })

  // Current lerped state applied each frame
  const current = useRef({
    rotationSpeed: cfg.rotationSpeed,
    shieldDistort:  cfg.shieldDistort,
    shieldSpeed:    cfg.shieldSpeed,
    shieldOpacity:  0,
  })

  const clonedScene = useMemo(() => {
    if (!scene) return null
    const cl = scene.clone()
    cl.traverse((child) => {
      if (child.isMesh) { child.castShadow = false; child.receiveShadow = false }
    })
    return cl
  }, [scene])

  // Normalise & centre model
  useEffect(() => {
    if (!clonedScene || !outerRef.current || !innerRef.current) return
    const box    = new THREE.Box3().setFromObject(clonedScene)
    const size   = new THREE.Vector3()
    const center = new THREE.Vector3()
    box.getSize(size); box.getCenter(center)
    const maxDim = Math.max(size.x, size.y, size.z)
    if (maxDim > 0) {
      outerRef.current.scale.setScalar((2.6 * scale) / maxDim)
      innerRef.current.position.set(-center.x, -center.y, -center.z)
    }
  }, [clonedScene, scale])

  // Hover: GSAP tweens gsapTarget (no setState, no re-render)
  const onOver = (e) => {
    e.stopPropagation()
    gsap.to(gsapTarget.current, {
      rotationSpeed: cfg.rotationSpeed * cfg.hoverRotationScale,
      shieldDistort:  cfg.hoverShieldDistort,
      shieldSpeed:    cfg.hoverShieldSpeed,
      duration: 0.5,
      ease: 'power2.out',
      overwrite: true,
    })
  }
  const onOut = (e) => {
    e.stopPropagation()
    gsap.to(gsapTarget.current, {
      rotationSpeed: cfg.rotationSpeed,
      shieldDistort:  cfg.shieldDistort,
      shieldSpeed:    cfg.shieldSpeed,
      duration: 0.9,
      ease: 'power2.out',
      overwrite: true,
    })
  }

  useFrame((state, delta) => {
    const t  = state.clock.elapsedTime
    // Frame-rate-independent lerp factor
    const lf = 1 - Math.pow(0.05, delta)

    current.current.rotationSpeed = THREE.MathUtils.lerp(
      current.current.rotationSpeed, gsapTarget.current.rotationSpeed, lf
    )

    if (innerRef.current) {
      innerRef.current.rotation.y += delta * current.current.rotationSpeed
      innerRef.current.position.y  = Math.sin(t * cfg.floatSpeed) * cfg.floatAmplitude
    }

    if (shieldRef.current && cfg.showShield) {
      current.current.shieldDistort = THREE.MathUtils.lerp(
        current.current.shieldDistort, gsapTarget.current.shieldDistort, lf
      )
      current.current.shieldSpeed = THREE.MathUtils.lerp(
        current.current.shieldSpeed, gsapTarget.current.shieldSpeed, lf
      )
      current.current.shieldOpacity = THREE.MathUtils.lerp(
        current.current.shieldOpacity, gsapTarget.current.shieldOpacity, lf * 2
      )

      const mat = shieldRef.current.material
      if (mat) {
        mat.distort = current.current.shieldDistort
        mat.speed   = current.current.shieldSpeed
        mat.opacity = current.current.shieldOpacity
      }

      const rotRate = current.current.shieldDistort > 0.1 ? 0.2 : 0.04
      shieldRef.current.rotation.y -= delta * rotRate
      shieldRef.current.rotation.z += delta * rotRate * 0.6
      shieldRef.current.position.y  = 0.1 + Math.sin(t * cfg.floatSpeed) * cfg.floatAmplitude
    }
  })

  return (
    <group ref={outerRef} onPointerOver={onOver} onPointerOut={onOut}>
      <group ref={innerRef}>
        {clonedScene && <primitive object={clonedScene} />}
      </group>

      {cfg.showShield && (
        <mesh ref={shieldRef} scale={[1.15, 1.15, 1.15]} position={[0, 0.1, 0]}
          onPointerOver={onOver} onPointerOut={onOut}>
          <sphereGeometry args={[1.3, 32, 32]} />
          <MeshDistortMaterial
            color="#01260f"
            distort={0.05}
            speed={0.4}
            roughness={0.25}
            metalness={0.9}
            transparent
            opacity={0}
            wireframe
          />
        </mesh>
      )}
    </group>
  )
}

// ─── Public API ───────────────────────────────────────────────────────────────

export default function NexoModelViewer({ type, scale = 1.0 }) {
  const cfg = SECTION_CONFIG[type] ?? SECTION_CONFIG.hero

  return (
    <>
      <PerspectiveCamera makeDefault position={cfg.cameraPosition} fov={cfg.fov} />

      <ambientLight intensity={cfg.ambientIntensity} />
      <directionalLight position={[5, 8, 5]}   intensity={cfg.dirLightIntensity} />
      <directionalLight position={[-4, 2, -4]}  intensity={0.8} />
      <pointLight       position={[0, 4, 2]}    intensity={1.5} color={cfg.accentColor} />
      <Environment preset="city" />

      <OrbitControls
        enableZoom={false}
        enablePan={false}
        dampingFactor={0.06}
        enableDamping
        minPolarAngle={Math.PI * 0.1}
        maxPolarAngle={Math.PI * 0.9}
      />

      <Suspense fallback={null}>
        {type === 'grid'
          ? <InstitutionalNetwork />
          : <ModelScene type={type} scale={scale} />
        }
      </Suspense>
    </>
  )
}
