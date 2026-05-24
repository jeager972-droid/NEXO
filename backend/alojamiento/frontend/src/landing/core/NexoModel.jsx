import { useRef, useEffect, useMemo } from 'react'
import { useFrame, useThree } from '@react-three/fiber'
import { useGLTF, MeshDistortMaterial } from '@react-three/drei'
import * as THREE from 'three'
import { ScrollTrigger } from 'gsap/ScrollTrigger'

// BUG 1 FIX — rotación automática continua en eje Y usando Three.js clock (no GSAP)
// BUG 1 FIX — drag orbital vía dragDeltaRef (overlay externo, no OrbitControls)
// El canvas tiene pointer-events: none; el overlay captura el drag sin bloquear el scroll.

const MODEL_PATH = '/assets/models/nodo.glb'
const AUTO_ROTATION_SPEED = 0.004 // rad/frame  ≈ 0.24°/frame @ 60fps

export default function NexoModel({ type, scale = 1.0, showShield = false, scrollProgress, isUserDragging, dragDeltaRef }) {
  const { gl } = useThree()
  const { scene } = useGLTF(MODEL_PATH)
  const outerRef = useRef()
  const innerRef = useRef()
  const shieldRef = useRef()
  const hasNormalized = useRef(false)

  // Clone the scene so each canvas has its own independent hierarchy
  const clonedScene = useMemo(() => {
    if (!scene) return null
    const cl = scene.clone()

    // Enable shadows + max anisotropy on every mesh
    const maxAnisotropy = gl.capabilities.getMaxAnisotropy()
    cl.traverse((child) => {
      if (child.isMesh) {
        child.castShadow = true
        child.receiveShadow = true
        if (child.material) {
          const mats = Array.isArray(child.material) ? child.material : [child.material]
          mats.forEach(mat => {
            if (mat.map) mat.map.anisotropy = maxAnisotropy
          })
        }
      }
    })
    return cl
  }, [scene, gl])

  // BUG 1: Normalize model position to bounding box center on first load
  useEffect(() => {
    if (!clonedScene || !outerRef.current || !innerRef.current || hasNormalized.current) return
    hasNormalized.current = true

    const box = new THREE.Box3().setFromObject(clonedScene)
    const size = new THREE.Vector3()
    const center = new THREE.Vector3()
    box.getSize(size)
    box.getCenter(center)

    const maxDim = Math.max(size.x, size.y, size.z)
    if (maxDim > 0) {
      const TARGET_SIZE = 2.6 * scale
      const s = TARGET_SIZE / maxDim
      outerRef.current.scale.setScalar(s)

      // Centrar en bounding box real (Bug 1 fix)
      innerRef.current.position.set(-center.x, -center.y, -center.z)

      // Al terminar la normalización, el modelo está en el DOM con dimensiones reales
      setTimeout(() => ScrollTrigger.refresh(), 100)
    }
  }, [clonedScene, scale])

  // BUG 1: Auto-rotation + drag orbital (useFrame runs at ~60fps, no GSAP conflict)
  useFrame((_state, delta) => {
    if (!innerRef.current) return

    if (scrollProgress !== undefined) {
      // Hero mode: rotate Y proportional to scroll progress (0 → 0.4π)
      innerRef.current.rotation.y = scrollProgress * Math.PI * 0.4
    } else if (isUserDragging && dragDeltaRef?.current) {
      // User dragging: apply orbital rotation from overlay deltas
      const { dx, dy } = dragDeltaRef.current
      innerRef.current.rotation.y += dx * 0.008
      innerRef.current.rotation.x += dy * 0.008

      // Clamp X rotation so model doesn't flip upside down
      innerRef.current.rotation.x = Math.max(
        -Math.PI / 4,
        Math.min(Math.PI / 4, innerRef.current.rotation.x)
      )

      // Reset deltas after consuming them
      dragDeltaRef.current = { dx: 0, dy: 0 }
    } else {
      // Auto-rotation: continuous slow Y spin at 60fps
      innerRef.current.rotation.y += AUTO_ROTATION_SPEED
    }

    // Shield animation (independent of main model)
    if (shieldRef.current && shieldRef.current.material) {
      const time = _state.clock.getElapsedTime()
      const mat = shieldRef.current.material
      mat.distort = THREE.MathUtils.lerp(mat.distort, 0.05, 0.08)
      mat.speed   = THREE.MathUtils.lerp(mat.speed, 0.4, 0.08)
      shieldRef.current.position.y = 0.1 + Math.sin(time * 0.3) * 0.004
      shieldRef.current.rotation.y -= delta * 0.04
      shieldRef.current.rotation.z += delta * 0.024
    }
  })

  return (
    <group ref={outerRef} position={[0, 0, 0]}>
      <group ref={innerRef}>
        {clonedScene && <primitive object={clonedScene} />}
      </group>

      {showShield && (
        <mesh
          ref={shieldRef}
          scale={[1.15, 1.15, 1.15]}
          position={[0, 0.1, 0]}
        >
          <sphereGeometry args={[1.3, 32, 32]} />
          <MeshDistortMaterial
            attach="material"
            color="#01260f"
            distort={0.05}
            speed={0.4}
            roughness={0.25}
            metalness={0.9}
            transparent
            opacity={0.45}
            wireframe
          />
        </mesh>
      )}
    </group>
  )
}

useGLTF.preload(MODEL_PATH)
