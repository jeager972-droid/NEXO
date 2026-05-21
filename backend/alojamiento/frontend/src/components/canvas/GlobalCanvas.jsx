import { Suspense } from 'react'
import { Canvas } from '@react-three/fiber'
import { View, Preload } from '@react-three/drei'

const DPR_RANGE = [1, typeof window !== 'undefined' ? Math.min(window.devicePixelRatio, 1.5) : 1]

export default function GlobalCanvas({ eventSource }) {
  return (
    <Canvas
      eventSource={eventSource}
      eventPrefix="client"
      gl={{
        antialias: true,
        alpha: true,
        powerPreference: 'high-performance',
        stencil: false,
      }}
      dpr={DPR_RANGE}
      frameloop="always"
      style={{
        position: 'fixed',
        top: 0,
        left: 0,
        width: '100vw',
        height: '100vh',
        pointerEvents: 'none',
        zIndex: 2,
      }}
    >
      <Suspense fallback={null}>
        <View.Port />
      </Suspense>
      <Preload all />
    </Canvas>
  )
}
