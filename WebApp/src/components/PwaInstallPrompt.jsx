import { useEffect, useState } from 'react'

function PwaInstallPrompt() {
  const [deferredPrompt, setDeferredPrompt] = useState(null)
  const [installed, setInstalled] = useState(false)

  useEffect(() => {
    const onBeforeInstallPrompt = (event) => {
      event.preventDefault()
      setDeferredPrompt(event)
    }

    const onAppInstalled = () => {
      setInstalled(true)
      setDeferredPrompt(null)
    }

    window.addEventListener('beforeinstallprompt', onBeforeInstallPrompt)
    window.addEventListener('appinstalled', onAppInstalled)

    return () => {
      window.removeEventListener('beforeinstallprompt', onBeforeInstallPrompt)
      window.removeEventListener('appinstalled', onAppInstalled)
    }
  }, [])

  const handleInstall = async () => {
    if (!deferredPrompt) return
    deferredPrompt.prompt()
    await deferredPrompt.userChoice
    setDeferredPrompt(null)
  }

  if (!deferredPrompt || installed) return null

  return (
    <button
      type="button"
      onClick={handleInstall}
      className="fixed bottom-4 right-4 z-50 rounded-md bg-blue-700 px-3 py-2 text-xs font-semibold text-white shadow-lg hover:bg-blue-800"
    >
      Instalar app
    </button>
  )
}

export default PwaInstallPrompt
