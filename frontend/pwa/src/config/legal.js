/**
 * legal.js / NEXO Institucional
 * Textos legales de la PWA: aviso de cookies y Términos y Condiciones.
 * Fuente de verdad del contenido mostrado en LegalGate (post-login).
 *
 * TERMS_VERSION debe mantenerse sincronizada con NEXO_TERMS_VERSION en
 * backend/api/routes/auth.php — al publicar una versión nueva se vuelve a
 * pedir aceptación a todos los usuarios.
 *
 * Secciones: { title, paragraphs: string[], bullets: string[] }
 */

export const TERMS_VERSION = '2026.09';
export const TERMS_LAST_UPDATE = 'septiembre de 2026';

export const COOKIE_CONSENT_KEY = 'nexo_cookie_consent';
export const COOKIE_CONSENT_VERSION = '1.0';
export const TERMS_FALLBACK_KEY = 'nexo:terms-acceptance';

export const COOKIE_CATEGORIES = {
  necessary: {
    id: 'necessary',
    label: 'Estrictamente necesarias',
    required: true,
    description: 'Hacen posible la sesión y la seguridad. Sin ellas la aplicación no funciona y no pueden desactivarse.',
  },
  preferences: {
    id: 'preferences',
    label: 'Preferencias',
    required: false,
    description: 'Recuerdan tu tema visual, tamaño de letra y las guías de Nodus que ya viste.',
  },
  analytics: {
    id: 'analytics',
    label: 'Analíticas y diagnóstico',
    required: false,
    description: 'Nos ayudan a detectar errores y medir el rendimiento, sin identificarte personalmente.',
  },
  marketing: {
    id: 'marketing',
    label: 'Marketing',
    required: false,
    description: 'NEXO no utiliza cookies de publicidad ni comparte datos con redes de anuncios. Categoría declarada sin uso.',
  },
};

export const COOKIE_NOTICE = {
  title: 'Uso de cookies y almacenamiento local',
  summary:
    'NEXO usa cookies seguras y almacenamiento del navegador para mantener tu sesión activa, ' +
    'recordar tus preferencias y medir el rendimiento de la plataforma. Nada de esto se usa ' +
    'para publicidad.',
  sections: [
    {
      title: '1. Qué tecnologías utilizamos',
      paragraphs: [
        'Este aviso cubre tanto las cookies HTTP como las tecnologías equivalentes de almacenamiento ' +
          'en el navegador (localStorage y sessionStorage). Para efectos de este aviso y de tu ' +
          'consentimiento, todas se tratan bajo la misma denominación de «cookies».',
        'NEXO no instala cookies de terceros en tu navegador: todos los elementos listados son ' +
          'propios de la plataforma.',
      ],
    },
    {
      title: '2. Cookies estrictamente necesarias',
      paragraphs: [
        'Son imprescindibles para autenticarte y mantener la seguridad de la sesión. No requieren ' +
          'consentimiento porque sin ellas el servicio solicitado no puede prestarse:',
      ],
      bullets: [
        'token — token de acceso firmado (JWT). Cookie HttpOnly, Secure y SameSite que no puede leerse ' +
          'desde scripts. Duración: ~15 minutos por emisión.',
        'refresh_token — permite renovar la sesión sin pedir credenciales otra vez. Cookie HttpOnly y ' +
          'Secure. Duración: hasta 7 días.',
        'nexo:auth-token, nexo:auth-refresh-token, nexo:user-fallback — respaldo local de sesión usado ' +
          'únicamente en iOS/Safari, donde el sistema bloquea cookies entre sitios (ITP).',
        'nexo_cookie_consent — guarda la elección que hagas en este aviso para no volver a preguntarte. ' +
          'Duración: hasta 12 meses.',
      ],
    },
    {
      title: '3. Cookies de preferencias (opcionales)',
      paragraphs: [
        'Recuerdan cómo te gusta trabajar para que la aplicación se abra tal como la dejaste:',
      ],
      bullets: [
        'darkMode — tema claro u oscuro elegido.',
        'nx-font-scale — escala tipográfica de accesibilidad configurada en tu perfil.',
        'nx:hint:* — registro de las guías contextuales de Nodus ya mostradas en cada sección.',
        'nx:bot-dismissed y nx:shown-insights — mensajes y lecturas de Nodus que ya descartaste o viste, ' +
          'para no repetirlos.',
      ],
    },
    {
      title: '4. Cookies de analítica y diagnóstico (opcionales)',
      paragraphs: [
        'Si las aceptas, la aplicación envía eventos técnicos de diagnóstico a nuestros servidores ' +
          'para detectar fallos y mejorar el rendimiento:',
      ],
      bullets: [
        'Errores de ejecución de la aplicación (tipo de error y componente, sin contenido de usuario).',
        'Tiempos de respuesta de la API y de lecturas biométricas.',
        'Datos de plataforma: tipo de dispositivo (web, iOS o Android), resolución de pantalla, zona ' +
          'horaria y tipo de conexión.',
      ],
      after: [
        'La telemetría usa un identificador de sesión aleatorio que se regenera en cada visita. No ' +
          'incluye nombres, documentos, mensajes del chat ni datos de estudiantes.',
      ],
    },
    {
      title: '5. Cookies de marketing',
      paragraphs: [
        'NEXO no utiliza cookies de publicidad, remarketing ni seguimiento publicitario, y no ' +
          'comparte información con redes de anuncios. Las integraciones con terceros (por ejemplo, ' +
          'el envío de notificaciones por WhatsApp) ocurren de servidor a servidor, sin instalar ' +
          'cookies en tu navegador.',
      ],
    },
    {
      title: '6. Cómo gestionar tu consentimiento',
      paragraphs: [
        'Puedes aceptar todas las categorías, quedarte solo con las necesarias o consultar este ' +
          'aviso de nuevo cuando quieras. También puedes borrar las cookies desde la configuración ' +
          'de tu navegador.',
        'Ten en cuenta: si bloqueas las cookies necesarias, no será posible iniciar sesión ni ' +
          'mantener la sesión activa. Si rechazas las opcionales, la aplicación funciona igual, ' +
          'pero no recordará tus preferencias ni enviará diagnósticos.',
      ],
    },
    {
      title: '7. Marco legal',
      paragraphs: [
        'El uso de estas tecnologías se rige por la Ley Estatutaria 1581 de 2012, el Decreto ' +
          'Reglamentario 1377 de 2013 y los lineamientos de la Superintendencia de Industria y ' +
          'Comercio sobre protección de datos personales en Colombia. Las cookies necesarias se ' +
          'soportan en la ejecución del servicio contratado por la institución educativa; las ' +
          'opcionales se activan únicamente con tu consentimiento expreso.',
      ],
    },
    {
      title: '8. Actualizaciones de este aviso',
      paragraphs: [
        'Si incorporamos nuevas categorías o cambia la finalidad de las existentes, actualizaremos ' +
          'este texto y volveremos a solicitar tu consentimiento. Última actualización: septiembre ' +
          'de 2026.',
      ],
    },
  ],
};

export const TERMS_NOTICE = {
  title: 'Términos y Condiciones de Uso',
  preamble:
    'Los presentes Términos y Condiciones regulan el acceso y uso de la plataforma NEXO, ' +
    'operada por NEXO S.A.S., sociedad domiciliada en Colombia. Al pulsar «Aceptar y ' +
    'continuar», declaras que leíste y aceptas estas condiciones en tu calidad de usuario ' +
    'autorizado por una institución educativa vinculada.',
  summaryBullets: [
    'Tu cuenta es personal e intransferible: las credenciales son tu responsabilidad.',
    'La información de estudiantes es confidencial y su uso está regulado por ley.',
    'Nodus te asiste, pero no decide: las decisiones institucionales son siempre tuyas.',
    'Está prohibido usar la plataforma para fines ajenos a tu función en la institución.',
    'Aceptar estos términos es requisito indispensable para usar la aplicación.',
  ],
  sections: [
    {
      title: '1. Objeto y aceptación',
      paragraphs: [
        'NEXO es una plataforma de custodia educativa en tiempo real que integra control de acceso ' +
          'biométrico, trazabilidad estudiantil, detección de situaciones (ausencias, tardanzas, ' +
          'evasiones), notificaciones a acudientes y un asistente conversacional («Nodus»).',
        'El acceso está reservado al personal autorizado por las instituciones educativas que hayan ' +
          'formalizado un contrato de vinculación con NEXO S.A.S. Estos términos complementan, y no ' +
          'sustituyen, dicho contrato ni las políticas de tratamiento de datos personales.',
        'Si no estás de acuerdo con estos términos, no podrás utilizar la aplicación. Comunícate ' +
          'con el administrador de tu institución.',
      ],
    },
    {
      title: '2. Cuentas y credenciales',
      paragraphs: [
        'Cada usuario accede con credenciales individuales e intransferibles asignadas por su ' +
          'institución. Eres responsable de la confidencialidad de tu contraseña y de toda ' +
          'actividad que ocurra bajo tu cuenta.',
      ],
      bullets: [
        'No compartas tus credenciales ni permitas que terceros operen con tu sesión.',
        'Notifica de inmediato a tu institución y a NEXO cualquier uso no autorizado o sospecha de compromiso.',
        'Cuando la institución lo habilite, la verificación en dos pasos (OTP) es obligatoria y no debe eludirse.',
      ],
    },
    {
      title: '3. Uso autorizado y conductas prohibidas',
      paragraphs: [
        'La plataforma solo puede utilizarse para las funciones propias del rol asignado por la ' +
          'institución educativa. Queda expresamente prohibido:',
      ],
      bullets: [
        'Acceder o intentar acceder a información de usuarios, estudiantes o instituciones distintas ' +
          'de las autorizadas.',
        'Extraer, copiar o descargar masivamente datos de estudiantes o acudientes con fines ajenos ' +
          'al servicio.',
        'Usar la plataforma para vigilancia, discriminación, acoso o cualquier fin contrario a la ' +
          'ley o a la dignidad de los estudiantes.',
        'Interferir con la seguridad del sistema, realizar ingeniería inversa o explotar vulnerabilidades.',
        'Alterar, manipular o falsificar registros de asistencia o cualquier dato del sistema.',
      ],
      after: [
        'El incumplimiento de estas prohibiciones puede causar la suspensión inmediata de la cuenta ' +
          'y la comunicación a la institución educativa y, cuando corresponda, a las autoridades.',
      ],
    },
    {
      title: '4. Datos personales y de menores de edad',
      paragraphs: [
        'NEXO trata datos personales — incluidos datos de menores de edad y plantillas biométricas — ' +
          'conforme a la Ley Estatutaria 1581 de 2012, el Decreto 1377 de 2013 y el Código de la ' +
          'Infancia y la Adolescencia (Ley 1098 de 2006), bajo el principio rector del interés ' +
          'superior del menor.',
        'La institución educativa es la titular del vínculo con los titulares de los datos; NEXO ' +
          'S.A.S. actúa como Responsable del Tratamiento en los términos del contrato de vinculación ' +
          'y la política de privacidad vigente.',
      ],
      bullets: [
        'Como usuario, solo puedes consultar y usar los datos estrictamente necesarios para tu función.',
        'Los datos biométricos se procesan como plantillas matemáticas cifradas; nunca como imágenes ' +
          'y nunca salen del nodo de captura.',
        'Toda consulta y modificación queda registrada en el registro de auditoría de la plataforma.',
        'La divulgación no autorizada de información de estudiantes constituye una infracción grave ' +
          'de estos términos y puede acarrear responsabilidad legal.',
      ],
    },
    {
      title: '5. Nodus: alcance y límites del asistente',
      paragraphs: [
        'Nodus es un asistente conversacional que interpreta consultas en lenguaje natural y las ' +
          'responde con información verificable del sistema. Nodus no toma decisiones ' +
          'institucionales: las decisiones sobre estudiantes, sanciones, permisos o comunicaciones ' +
          'son siempre responsabilidad del personal autorizado.',
      ],
      bullets: [
        'Las respuestas de Nodus provienen de datos del sistema; aun así, verifica la información ' +
          'crítica antes de actuar.',
        'No ingreses en el chat contraseñas, códigos de verificación ni datos sensibles ajenos a la consulta.',
        'Las conversaciones pueden ser auditadas por la institución y por NEXO con fines de seguridad y mejora.',
        'El dictado por voz del chat utiliza el reconocimiento de voz del navegador o del ' +
          'dispositivo del usuario: el audio no se almacena en los servidores de NEXO y la ' +
          'transcripción la presta el proveedor del navegador conforme a sus propias políticas.',
      ],
    },
    {
      title: '6. Notificaciones a terceros',
      paragraphs: [
        'NEXO envía notificaciones a acudientes mediante WhatsApp y SMS a través de proveedores de ' +
          'mensajería. La institución educativa es responsable de la veracidad de los datos de ' +
          'contacto y de contar con la autorización de los acudientes para dichos envíos.',
        'La entrega de mensajes depende de terceros (operadores y plataformas de mensajería); NEXO ' +
          'no garantiza la entrega inmediata ni la recepción en todos los casos.',
      ],
    },
    {
      title: '7. Disponibilidad y continuidad',
      paragraphs: [
        'NEXO procura mantener el servicio disponible de forma continua. El nodo de captura opera ' +
          'con autonomía local (offline-first), de modo que el registro de presencia continúa aun ' +
          'cuando la conectividad falle y sincroniza al restablecerse.',
        'No obstante, el servicio puede interrumpirse por mantenimiento, fuerza mayor o fallos de ' +
          'terceros. Cuando sea posible, los mantenimientos programados se notificarán con antelación.',
      ],
    },
    {
      title: '8. Propiedad intelectual',
      paragraphs: [
        'El software, el diseño, la marca NEXO, Nodus y todos los componentes de la plataforma son ' +
          'propiedad exclusiva de NEXO S.A.S. La institución dispone de una licencia de uso no ' +
          'exclusiva e intransferible durante la vigencia del contrato.',
        'Queda prohibida la reproducción, distribución, modificación o creación de obras derivadas ' +
          'de cualquier componente sin autorización escrita.',
      ],
    },
    {
      title: '9. Limitación de responsabilidad',
      paragraphs: [
        'NEXO responde por el funcionamiento de la plataforma conforme a las especificaciones ' +
          'contratadas. En la medida permitida por la ley, NEXO no será responsable por:',
      ],
      bullets: [
        'Interrupciones derivadas de fuerza mayor, fallos de conectividad del usuario o daños ' +
          'intencionales al hardware por parte de terceros.',
        'El uso indebido de la información por parte de usuarios autorizados de la institución.',
        'Decisiones institucionales adoptadas con base en la información presentada por la plataforma.',
        'La falta de entrega de notificaciones imputable a operadores o plataformas de mensajería.',
      ],
      after: [
        'NEXO es una herramienta de apoyo a la custodia educativa: no sustituye los deberes de ' +
          'vigilancia, cuidado y supervisión que la ley impone a la institución y su personal.',
      ],
    },
    {
      title: '10. Modificaciones',
      paragraphs: [
        'NEXO puede actualizar estos términos. Cuando los cambios sean sustanciales, se notificará ' +
          'a las instituciones vinculadas con al menos quince (15) días de anticipación y la nueva ' +
          'versión se mostrará nuevamente en el acceso para su aceptación.',
        'El uso continuado de la plataforma tras la entrada en vigor de una nueva versión constituye ' +
          'la aceptación de los términos modificados.',
      ],
    },
    {
      title: '11. Suspensión y terminación',
      paragraphs: [
        'El acceso puede suspenderse de forma inmediata ante un incumplimiento de estos términos, ' +
          'un riesgo de seguridad o una instrucción de la institución educativa. El acceso termina ' +
          'cuando finaliza la vinculación del usuario con la institución o el contrato entre la ' +
          'institución y NEXO.',
      ],
    },
    {
      title: '12. Ley aplicable y controversias',
      paragraphs: [
        'Estos términos se rigen por las leyes de la República de Colombia. Cualquier controversia ' +
          'se resolverá ante los jueces y tribunales de Bogotá D.C., Colombia, salvo pacto arbitral ' +
          'contenido en el contrato de vinculación.',
      ],
    },
    {
      title: '13. Canal de contacto',
      paragraphs: [
        'Para consultas sobre estos términos, ejercicio de derechos de los titulares de datos o ' +
          'reportes de seguridad: correo electrónico jhonedisonalvarez21@gmail.com · WhatsApp ' +
          '+57 314 862 2367.',
        `Versión ${TERMS_VERSION} — última actualización: ${TERMS_LAST_UPDATE}.`,
      ],
    },
  ],
};

/** Guion breve de Nodus para el aviso de Términos (NodusGuide). */
export const TERMS_NODUS_SCRIPT = [
  {
    text: 'Hola, soy <b>Nodus</b>. Antes de entrar hay un paso legal rápido.',
    dismissKey: 'legal:terms-1',
  },
  {
    text: 'Los <b>Términos y Condiciones</b> dicen qué puedes esperar de NEXO y qué esperamos de ti — protegen tu cuenta y los datos de los estudiantes.',
    dismissKey: 'legal:terms-2',
  },
  {
    text: 'Léelos con calma: toca <b>«Leer más»</b> para el texto completo y <b>«Aceptar y continuar»</b> cuando estés listo.',
    dismissKey: 'legal:terms-3',
  },
];
