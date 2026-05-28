import { useEffect, useRef } from 'react'

// Legal modal for Privacy Policy, Data Treatment, and Terms of Use
// Styled consistently with NEXO landing (Plus Jakarta Sans, --nx-* palette)

const LEGAL_CONTENT = {
  privacy: {
    title: 'Política de Privacidad',
    body: `NEXO S.A.S., identificada con NIT [en trámite], con domicilio en Colombia, actúa como Responsable del Tratamiento de los datos personales recopilados a través de su plataforma de custodia educativa.

**1. Datos que recopilamos**

• Datos biométricos: huellas dactilares de estudiantes, procesadas y almacenadas en formato de plantilla cifrada (no como imagen).
• Datos de contacto: nombre, número de teléfono y correo electrónico de acudientes, docentes y directivos.
• Datos de asistencia y presencia: registros de entrada, salida, hora, ubicación dentro de la institución y eventos asociados.
• Datos del dispositivo institucional: identificadores del nodo, estado de batería, conectividad y logs operativos.

**2. Finalidad del tratamiento**

Los datos se recopilan exclusivamente para:
• Registrar y verificar la presencia de estudiantes en tiempo real.
• Notificar a los acudientes sobre inasistencias o eventos relevantes.
• Generar informes de trazabilidad para la institución educativa.
• Detectar patrones de comportamiento que requieran atención preventiva.
• Cumplir con obligaciones legales ante el MEN y entes de control.

**3. Responsable del tratamiento**

NEXO S.A.S. es el responsable del tratamiento. La institución educativa vinculada actúa como Encargada del Tratamiento en lo que respecta al acceso y uso de la información dentro de su jurisdicción.

**4. Protección de los datos**

• Encriptación de extremo a extremo en cada transmisión.
• Los datos biométricos se almacenan como plantillas matemáticas irreversibles.
• Acceso restringido por roles con autenticación segura.
• Infraestructura con conectividad M2M independiente (no depende de redes institucionales).
• Auditoría completa de cada acceso y modificación.

**5. No compartimos datos con terceros**

NEXO no vende, cede ni comparte datos personales con terceros comerciales. Los datos solo se comparten con la institución educativa vinculada y, cuando la ley lo exija, con autoridades competentes.

Esta política se rige por la Ley 1581 de 2012, el Decreto 1377 de 2013 y las directrices de la Superintendencia de Industria y Comercio (SIC).`,
  },
  treatment: {
    title: 'Tratamiento de Datos Personales',
    body: `En cumplimiento de la Ley Estatutaria 1581 de 2012 y el Decreto Reglamentario 1377 de 2013, NEXO S.A.S. informa las condiciones del tratamiento de datos personales:

**1. Base legal del tratamiento**

El tratamiento de datos personales se fundamenta en:
• Autorización previa, expresa e informada del titular o su representante legal (para menores de edad, el acudiente o padre de familia).
• Cumplimiento de una obligación legal (registros de asistencia escolar conforme a la normativa del MEN).
• Interés legítimo de la institución educativa en garantizar la seguridad y custodia de sus estudiantes.

**2. Derechos del titular**

De conformidad con el artículo 8 de la Ley 1581, los titulares tienen derecho a:
• Conocer, actualizar y rectificar sus datos personales.
• Solicitar prueba de la autorización otorgada.
• Ser informado sobre el uso que se ha dado a sus datos.
• Revocar la autorización y/o solicitar la supresión de los datos cuando no se respeten los principios, derechos y garantías constitucionales y legales.
• Acceder de forma gratuita a sus datos personales que hayan sido objeto de tratamiento.

**3. Canal para ejercer sus derechos**

Las solicitudes, consultas o reclamos pueden dirigirse a:
• Correo electrónico: jhonedisonalvarez21@gmail.com
• Teléfono/WhatsApp: +57 314 862 2367
• Tiempo de respuesta: 10 días hábiles para consultas, 15 días hábiles para reclamos (prorrogables conforme a la ley).

**4. Tiempo de retención**

• Los datos de asistencia y presencia se conservan durante la vigencia del contrato con la institución educativa y hasta 5 años después de su finalización, conforme a obligaciones legales de archivo institucional.
• Los datos biométricos (plantillas cifradas) se eliminan dentro de los 30 días siguientes a la desvinculación del estudiante de la institución o a la terminación del contrato.
• Los datos de contacto de acudientes se conservan mientras el estudiante esté vinculado a la institución.

**5. Transferencia y transmisión**

Los datos no se transfieren a terceros países. La transmisión se realiza únicamente entre el nodo (hardware), los servidores seguros de NEXO y la interfaz de la institución educativa autorizada.

Fecha de última actualización: mayo de 2026.`,
  },
  terms: {
    title: 'Términos de Uso',
    body: `Los presentes Términos de Uso regulan el acceso y utilización de la plataforma NEXO, operada por NEXO S.A.S., con domicilio en Colombia.

**1. Objeto**

NEXO es una plataforma de custodia educativa que integra hardware biométrico y software de gestión para el registro de presencia, trazabilidad y comunicación institucional en entidades educativas colombianas.

**2. Condiciones de uso**

• El acceso a la plataforma está reservado exclusivamente a instituciones educativas que hayan formalizado un contrato de vinculación con NEXO.
• Cada usuario (rector, coordinador, profesor, acudiente) accede con credenciales individuales e intransferibles.
• El usuario se compromete a no intentar acceder a información de otros usuarios o instituciones, modificar el hardware, o utilizar la plataforma para fines distintos a los previstos.
• El uso indebido de la plataforma podrá resultar en la suspensión inmediata del acceso.

**3. Límites de responsabilidad**

• NEXO garantiza el funcionamiento del hardware y software conforme a las especificaciones técnicas del contrato.
• NEXO no es responsable por interrupciones derivadas de fuerza mayor, daños intencionales al hardware por parte de terceros, ni por el uso indebido de la información por parte de usuarios autorizados de la institución.
• La institución educativa es responsable del uso que sus funcionarios hagan de la información accesible a través de la plataforma.

**4. Propiedad intelectual**

• El software, diseño, algoritmos, marca y todos los elementos de la plataforma NEXO son propiedad exclusiva de NEXO S.A.S.
• La institución educativa adquiere una licencia de uso no exclusiva, no transferible, vigente durante la duración del contrato.
• Queda prohibida la reproducción, distribución, ingeniería inversa o modificación de cualquier componente de la plataforma sin autorización escrita.

**5. Jurisdicción y ley aplicable**

• Estos términos se rigen por las leyes de la República de Colombia.
• Cualquier controversia será resuelta por los jueces y tribunales de la ciudad de Bogotá D.C., Colombia, salvo pacto arbitral incluido en el contrato de vinculación.

**6. Modificaciones**

NEXO se reserva el derecho de modificar estos términos. Las modificaciones serán notificadas a las instituciones vinculadas con al menos 15 días de anticipación.

Fecha de última actualización: mayo de 2026.`,
  },
}

export default function LegalModal({ type, onClose }) {
  const overlayRef = useRef()
  const content = LEGAL_CONTENT[type]

  useEffect(() => {
    const handleEsc = (e) => { if (e.key === 'Escape') onClose() }
    document.addEventListener('keydown', handleEsc)
    // Prevent body scroll while modal is open
    const scrollY = window.scrollY
    document.body.style.position = 'fixed'
    document.body.style.top = `-${scrollY}px`
    document.body.style.left = '0'
    document.body.style.right = '0'
    document.body.style.overflow = 'hidden'
    return () => {
      document.removeEventListener('keydown', handleEsc)
      document.body.style.position = ''
      document.body.style.top = ''
      document.body.style.left = ''
      document.body.style.right = ''
      document.body.style.overflow = ''
      window.scrollTo(0, scrollY)
    }
  }, [onClose])

  const handleOverlayClick = (e) => {
    if (e.target === overlayRef.current) onClose()
  }

  if (!content) return null

  return (
    <div
      ref={overlayRef}
      onClick={handleOverlayClick}
      onTouchEnd={(e) => { if (e.target === overlayRef.current) onClose() }}
      style={{
        position: 'fixed',
        inset: 0,
        zIndex: 9999,
        background: 'rgba(0, 0, 0, 0.75)',
        backdropFilter: 'blur(4px)',
        WebkitBackdropFilter: 'blur(4px)',
        display: 'flex',
        alignItems: 'center',
        justifyContent: 'center',
        padding: '1rem',
        animation: 'legalFadeIn 0.2s ease',
        touchAction: 'none',
      }}
    >
      <div
        role="dialog"
        aria-modal="true"
        aria-labelledby="legal-modal-title"
        style={{
          background: 'var(--nx-surface, #1a1d23)',
          border: '1px solid var(--nx-border, #2a2d35)',
          borderRadius: '1.25rem',
          maxWidth: '680px',
          width: '100%',
          maxHeight: '80vh',
          display: 'flex',
          flexDirection: 'column',
          overflow: 'hidden',
        }}
      >
        {/* Header */}
        <div style={{
          display: 'flex',
          alignItems: 'center',
          justifyContent: 'space-between',
          padding: '1.5rem 2rem',
          borderBottom: '1px solid var(--nx-border, #2a2d35)',
          flexShrink: 0,
        }}>
          <h3
            id="legal-modal-title"
            style={{
              fontSize: '1.1rem',
              fontWeight: 700,
              color: 'var(--nx-white, #f0f2f5)',
              margin: 0,
            }}
          >
            {content.title}
          </h3>
          <button
            onClick={onClose}
            onTouchEnd={(e) => { e.preventDefault(); onClose(); }}
            type="button"
            aria-label="Cerrar"
            style={{
              background: 'none',
              border: '1px solid var(--nx-border, #2a2d35)',
              borderRadius: '0.5rem',
              width: '44px',
              height: '44px',
              minWidth: '44px',
              display: 'flex',
              alignItems: 'center',
              justifyContent: 'center',
              cursor: 'pointer',
              color: 'var(--nx-muted, #8a8f9a)',
              transition: 'color 0.2s, border-color 0.2s',
              touchAction: 'manipulation',
            }}
            onMouseEnter={e => { e.currentTarget.style.color = 'var(--nx-white)'; e.currentTarget.style.borderColor = 'var(--nx-muted)' }}
            onMouseLeave={e => { e.currentTarget.style.color = 'var(--nx-muted)'; e.currentTarget.style.borderColor = 'var(--nx-border)' }}
          >
            <svg width="16" height="16" viewBox="0 0 16 16" fill="none" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round">
              <line x1="4" y1="4" x2="12" y2="12" />
              <line x1="12" y1="4" x2="4" y2="12" />
            </svg>
          </button>
        </div>

        {/* Body */}
        <div style={{
          padding: '1.5rem 2rem',
          overflowY: 'auto',
          WebkitOverflowScrolling: 'touch',
          touchAction: 'pan-y',
          fontSize: '0.85rem',
          lineHeight: 1.75,
          color: 'var(--nx-text, #c8ccd4)',
          fontFamily: "'Plus Jakarta Sans', sans-serif",
        }}>
          {content.body.split('\n').map((line, i) => {
            if (line.startsWith('**') && line.endsWith('**')) {
              return (
                <h4 key={i} style={{
                  fontSize: '0.9rem',
                  fontWeight: 700,
                  color: 'var(--nx-white, #f0f2f5)',
                  marginTop: '1.75rem',
                  marginBottom: '0.75rem',
                }}>
                  {line.replace(/\*\*/g, '')}
                </h4>
              )
            }
            if (line.startsWith('• ')) {
              return (
                <div key={i} style={{ paddingLeft: '1rem', marginBottom: '0.35rem' }}>
                  <span style={{ color: 'var(--nx-blue, #6b9fff)', marginRight: '0.5rem' }}>•</span>
                  {line.slice(2)}
                </div>
              )
            }
            if (line.trim() === '') return <div key={i} style={{ height: '0.75rem' }} />
            return <p key={i} style={{ margin: '0 0 0.5rem' }}>{line}</p>
          })}
        </div>
      </div>

      <style>{`
        @keyframes legalFadeIn {
          from { opacity: 0; }
          to { opacity: 1; }
        }
      `}</style>
    </div>
  )
}
