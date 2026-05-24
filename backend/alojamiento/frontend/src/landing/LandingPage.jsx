// LandingPage — orchestrates all 10 modules of the NEXO world-class landing
import Navbar            from './components/Navbar'
import HeroSection       from './sections/HeroSection'
import CredibilityBar    from './sections/CredibilityBar'
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
import { useLenis }      from './components/useLenis'

export default function LandingPage() {
  // CAMBIO 6: Integrar Lenis como wrapper de scroll suave institucional
  useLenis()

  return (
    <>
      {/* CAMBIO 6: Efecto de cursor global */}
      <CustomCursor />

      {/* Fixed floating navbar */}
      <Navbar />

      {/* Main content flow */}
      <main id="nx-landing">
        <HeroSection />         {/* 01 — Hero */}
        <CredibilityBar />      {/* 02 — Credibilidad */}
        <ProblemSection />      {/* 03 — Problema */}
        <HowItWorksSection />   {/* 04 — Cómo funciona */}
        <ValuePropSection />    {/* 05 — Propuesta de valor */}
        <NodeSection />         {/* 06 — El nodo */}
        <RolesSection />        {/* 07 — Roles */}
        <DownloadSection />     {/* 08 — Descarga */}
        <SecuritySection />     {/* 09 — Seguridad */}
        <FinalCTASection />     {/* 10 — CTA Final */}
      </main>

      <Footer />
    </>
  )
}
