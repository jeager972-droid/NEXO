import { Canvas } from '@react-three/fiber'
import { View } from '@react-three/drei'

export default function GlobalCanvas({ eventSource }) {
  return (
    <Canvas
      eventSource={eventSource}
      gl={{ antialias: true, alpha: true, powerPreference: 'high-performance' }}
      dpr={[1, 1.5]}
      shadows
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
      <View.Port />
    </Canvas>
  )
}
