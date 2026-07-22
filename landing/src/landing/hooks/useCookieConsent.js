/**
 * =============================================================================
 * useCookieConsent.js — Hook de gestión de consentimiento de cookies.
 * =============================================================================
 * RESPONSABILIDAD:
 *   Lee/escribe el consentimiento de cookies desde localStorage, muestra el
 *   banner si no hay consentimiento previo y expone funciones para aceptar,
 *   rechazar, personalizar y resetear. Define categorías de cookies.
 *
 * DEPENDENCIAS:
 *   - react hooks
 * =============================================================================
 */

import { useState, useEffect } from 'react'

const COOKIE_KEY = 'nexo_cookie_consent'
const COOKIE_VERSION = '1.0'

export const COOKIE_CATEGORIES = {
  necessary: {
    id: 'necessary',
    label: 'Cookies necesarias',
    description: 'Esenciales para el funcionamiento básico del sitio. No se pueden desactivar.',
    required: true,
  },
  analytics: {
    id: 'analytics',
    label: 'Cookies analíticas',
    description: 'Nos ayudan a entender cómo los visitantes interactúan con el sitio para mejorar la experiencia.',
    required: false,
  },
  marketing: {
    id: 'marketing',
    label: 'Cookies de marketing',
    description: 'Permiten mostrar contenido y anuncios relevantes según tus intereses.',
    required: false,
  },
  preferences: {
    id: 'preferences',
    label: 'Cookies de preferencias',
    description: 'Recuerdan tus configuraciones y personalizaciones para visitas futuras.',
    required: false,
  },
}

export function useCookieConsent() {
  const [consent, setConsent] = useState(null)
  const [showBanner, setShowBanner] = useState(false)
  const [showManager, setShowManager] = useState(false)

  useEffect(() => {
    const saved = localStorage.getItem(COOKIE_KEY)
    if (saved) {
      try {
        const parsed = JSON.parse(saved)
        if (parsed.version === COOKIE_VERSION) {
          setConsent(parsed)
          setShowBanner(false)
          return
        }
      } catch {}
    }
    setTimeout(() => setShowBanner(true), 800)
  }, [])

  const saveConsent = (categories) => {
    const data = {
      version: COOKIE_VERSION,
      timestamp: new Date().toISOString(),
      categories,
    }
    localStorage.setItem(COOKIE_KEY, JSON.stringify(data))
    setConsent(data)
    setShowBanner(false)
    setShowManager(false)
  }

  const acceptAll = () => {
    saveConsent({
      necessary: true,
      analytics: true,
      marketing: true,
      preferences: true,
    })
  }

  const rejectAll = () => {
    saveConsent({
      necessary: true,
      analytics: false,
      marketing: false,
      preferences: false,
    })
  }

  const saveCustom = (custom) => {
    saveConsent({ necessary: true, ...custom })
  }

  const resetConsent = () => {
    localStorage.removeItem(COOKIE_KEY)
    setConsent(null)
    setShowBanner(true)
  }

  const hasConsent = (category) => consent?.categories?.[category] === true

  return {
    consent,
    showBanner,
    showManager,
    setShowManager,
    acceptAll,
    rejectAll,
    saveCustom,
    resetConsent,
    hasConsent,
  }
}
