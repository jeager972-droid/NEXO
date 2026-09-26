/**
 * =============================================================================
 * LandingPage.jsx — Página principal de la landing NEXO.
 * =============================================================================
 * RESPONSABILIDAD:
 *   Orquesta todas las secciones de la landing (Hero, Problema, Cómo funciona,
 *   Propuesta de valor, El nodo, Roles, Seguridad, Descarga, CTA final y Footer).
 *   Gestiona el registro único del plugin GSAP ScrollTrigger, el refresh con
 *   debounce y el flujo de consentimiento de cookies.
 *
 * FLUJO:
 *   1. Registrar ScrollTrigger.
 *   2. Refrescar layout tras montaje y resize (debounced).
 *   3. Renderizar Navbar, secciones, Footer y modales de cookies.
 *
 * DEPENDENCIAS:
 *   - gsap / ScrollTrigger
 *   - Secciones y componentes de landing/
 *   - useCookieConsent
 * =============================================================================
 */

import React, { useEffect, Suspense, lazy } from 'react'
import gsap from 'gsap'
import { ScrollTrigger } from 'gsap/ScrollTrigger'
import Navbar            from './components/Navbar'
import HeroSection       from './sections/HeroSection'
import ProblemSection    from './sections/ProblemSection'
import HowItWorksSection from './sections/HowItWorksSection'
import ValuePropSection  from './sections/ValuePropSection'
import NodeSection       from './sections/NodeSection'
import RolesSection      from './sections/RolesSection'
import DownloadSection   from './sections/DownloadSection'
import SecuritySection   from './sections/SecuritySection'
import FinalCTASection   from './sections/FinalCTASection'
import Footer            from './sections/Footer'
import CustomCursor      from './components/CustomCursor'
import { useCookieConsent } from './hooks/useCookieConsent'
import CookieBanner from './components/CookieBanner'
import CookieManager from './components/CookieManager'
import CookieFloatingButton from './components/CookieFloatingButton'

// Lazy load heavy components
const NexoCanvas = lazy(() => import('./components/NexoCanvas'))

gsap.registerPlugin(ScrollTrigger) // SINGLE registration point for the entire app

export default function LandingPage() {
  const cookieConsent = useCookieConsent()

  useEffect(() => {
    // ScrollTrigger.refresh() called ONCE after full DOM paint
    // setTimeout(1200) ensures all child components and 3D models have mounted and rendered
    const refreshTimer = setTimeout(() => {
      ScrollTrigger.refresh()
    }, 1200)

    // Debounced refresh on resize (250ms cooldown)
    let resizeTimer
    const handleResize = () => {
      clearTimeout(resizeTimer)
      resizeTimer = setTimeout(() => {
        ScrollTrigger.refresh()
      }, 250)
    }

    window.addEventListener('resize', handleResize)
    return () => {
      clearTimeout(refreshTimer)
      clearTimeout(resizeTimer)
      window.removeEventListener('resize', handleResize)
    }
  }, [])

  return (
    <>
      {/* Efecto de cursor global */}
      <CustomCursor />

      {/* Fixed floating navbar */}
      <Navbar />

      {/* Main content flow */}
      <main id="nx-landing">
        <HeroSection />         {/* 01 — Hero */}
        <ProblemSection />      {/* 03 — Problema */}
        <HowItWorksSection />   {/* 04 — Cómo funciona */}
        <ValuePropSection />    {/* 05 — Propuesta de valor */}
        <NodeSection />         {/* 06 — El nodo */}
        <RolesSection />        {/* 07 — Roles */}
        <SecuritySection />     {/* 09 — Seguridad */}
        <DownloadSection />     {/* 08 — Descarga */}
        <FinalCTASection />     {/* 10 — CTA Final */}
      </main>

      <Footer />

      {cookieConsent.showBanner && (
        <CookieBanner
          onAcceptAll={cookieConsent.acceptAll}
          onRejectAll={cookieConsent.rejectAll}
          onManage={() => cookieConsent.setShowManager(true)}
        />
      )}

      {cookieConsent.showManager && (
        <CookieManager
          onSave={cookieConsent.saveCustom}
          onAcceptAll={cookieConsent.acceptAll}
          onClose={() => cookieConsent.setShowManager(false)}
          initialValues={cookieConsent.consent?.categories}
        />
      )}

      {cookieConsent.consent && !cookieConsent.showBanner && (
        <CookieFloatingButton
          onClick={() => cookieConsent.setShowManager(true)}
        />
      )}
    </>
  )
}
