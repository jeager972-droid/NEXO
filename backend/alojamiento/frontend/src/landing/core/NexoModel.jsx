import { useRef, useEffect, useMemo } from 'react'
import { useFrame } from '@react-three/fiber'
import { useGLTF, MeshDistortMaterial } from '@react-three/drei'
import * as THREE from 'three'

const MODEL_PATH = '/assets/models/nodo.glb'

export default function NexoModel({ type, scale = 1.0, showShield = false, onHoverChange }) {
  const { scene } = useGLTF(MODEL_PATH)
  const outerRef = useRef()
  const innerRef = useRef()
  const shieldRef = useRef()
  const isHoveredRef = useRef(false)

  // Clone the scene so that each canvas has its own independent hierarchy
  const clonedScene = useMemo(() => {
    if (!scene) return null
    const cl = scene.clone()
    // Make sure shadows are enabled on the cloned meshes
    cl.traverse((child) => {
      if (child.isMesh) {
        child.castShadow = true
        child.receiveShadow = true
      }
    })
    return cl
  }, [scene])

  // Continuous rotation of the model
  useFrame((state, delta) => {
    if (innerRef.current) {
      innerRef.current.rotation.y += delta * 0.12
    }

    if (shieldRef.current && shieldRef.current.material) {
      const isHovered = isHoveredRef.current
      const time = state.clock.getElapsedTime()
      
      const targetDistort = isHovered ? 0.22 : 0.05
      const targetSpeed = isHovered ? 1.4 : 0.4
      
      const mat = shieldRef.current.material
      mat.distort = THREE.MathUtils.lerp(mat.distort, targetDistort, 0.08)
      mat.speed = THREE.MathUtils.lerp(mat.speed, targetSpeed, 0.08)

      const floatSpeedScale = isHovered ? 1.5 : 0.3
      const floatAmp = isHovered ? 0.015 : 0.004
      
      shieldRef.current.position.y = 0.1 + Math.sin(time * floatSpeedScale) * floatAmp
      shieldRef.current.position.x = Math.cos(time * (floatSpeedScale * 0.7)) * (floatAmp * 0.5)

      const rotSpeed = isHovered ? 0.2 : 0.04
      shieldRef.current.rotation.y -= delta * rotSpeed
      shieldRef.current.rotation.z += delta * (rotSpeed * 0.6)
    }
  })

  useEffect(() => {
    if (!clonedScene || !outerRef.current || !innerRef.current) return

    const box    = new THREE.Box3().setFromObject(clonedScene)
    const size   = new THREE.Vector3()
    const center = new THREE.Vector3()
    box.getSize(size)
    box.getCenter(center)

    const maxDim = Math.max(size.x, size.y, size.z)
    if (maxDim > 0) {
      const TARGET_SIZE = 2.6 * scale
      const s = TARGET_SIZE / maxDim
      outerRef.current.scale.setScalar(s)
      innerRef.current.position.set(-center.x, -center.y, -center.z)
    }
  }, [clonedScene, scale])

  const handlePointerOver = (e) => {
    e.stopPropagation()
    isHoveredRef.current = true
    if (onHoverChange) onHoverChange(true)
  }

  const handlePointerOut = (e) => {
    e.stopPropagation()
    isHoveredRef.current = false
    if (onHoverChange) onHoverChange(false)
  }

  return (
    <group 
      ref={outerRef} 
      position={[0, 0, 0]}
      onPointerOver={handlePointerOver}
      onPointerOut={handlePointerOut}
    >
      <group ref={innerRef}>
        {clonedScene && <primitive object={clonedScene} />}
      </group>

      {showShield && (
        <mesh 
          ref={shieldRef} 
          scale={[1.15, 1.15, 1.15]} 
          position={[0, 0.1, 0]}
          onPointerOver={handlePointerOver}
          onPointerOut={handlePointerOut}
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
