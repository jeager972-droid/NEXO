# 09. Accesibilidad

**Objetivo:** WCAG 2.2 AA completo; AAA selectivo para foco, targets críticos, contraste y lenguaje cuando sea viable. La conformidad no reemplaza pruebas con personas.

## 1. Personas y barreras

NEXO contempla ceguera, baja visión, daltonismo, movilidad reducida, temblor, uso solo teclado/voz/switch, sordera, atención/memoria variables, ansiedad, dislexia y baja alfabetización digital. Profesores 60+ son población de prueba obligatoria, sin asumir incompetencia: se reducen barreras de visión, destreza, memoria y exploración.

## 2. Reglas no negociables

### Percepción
- Texto normal ≥4.5:1; grande ≥3:1; componentes/foco ≥3:1.
- 200% zoom sin pérdida; 400% reflow a 320 CSS px sin scroll bidimensional salvo tablas esenciales.
- Texto no incrustado en imágenes; tema claro/oscuro y alto contraste robustos.
- Estado nunca solo por color: texto + icono/patrón + semántica.
- No audio ni video esencial. Si se añade, alternativas y controles.

### Operación
- Todo por teclado, orden lógico, sin trampas.
- Foco visible 2 px y nunca oculto por sticky bars.
- Targets recomendados 44×44; mínimo AA 24×24 o separación válida; acciones críticas 44×44 mínimo.
- No dependencia de hover, drag, gesto complejo u orientación.
- Skip link al contenido; landmarks y headings jerárquicos.

### Comprensión
- Labels persistentes, instrucciones antes del dato y errores reparables.
- No pedir dos veces información disponible (WCAG 3.3.7).
- Login permite password managers, pegar OTP y alternativas a pruebas cognitivas (3.3.8).
- Ayuda consistente, lenguaje `es-CO`, verbos específicos y fechas inequívocas.
- Confirmaciones nombran consecuencia; tiempo extendible cuando exista límite del cliente.

### Robustez
- HTML nativo primero; ARIA solo para completar patrón.
- Nombre, rol, valor y estados programáticos correctos.
- Live regions moderadas; no anunciar cada tick.
- Compatibilidad objetivo: Chrome/Edge/Firefox/Safari actuales, NVDA, VoiceOver y TalkBack.

## 3. Adultos mayores y baja experiencia digital

- Body 16 px mínimo; controles 48 px por defecto; caption nunca contiene acción o dato crítico.
- Evitar mayúsculas largas, texto tenue, icon-only y enlaces agrupados.
- Mostrar ejemplos y defaults, no jerga técnica.
- Una decisión por paso, contexto visible, deshacer y salida segura.
- Pistas contextuales máximo tres veces según fuente, pero siempre recuperables desde ayuda.
- No usar onboarding largo; aprendizaje dentro de tareas reales.
- Mensajes de error no culpan: “No pudimos enviar” en lugar de “Ingresaste mal”.

## 4. Patrones ARIA/teclado

| Componente | Teclado/semántica |
|---|---|
| Sidebar/nav | `nav`, lista de links, `aria-current=page`; Escape cierra móvil |
| Dialog | `dialog`, nombre, modal real, Tab contenido, Escape si seguro, retorno de foco |
| Combobox | patrón APG; flechas, Enter, Escape; estado expandido y opción activa |
| Tabs | tablist/tab/tabpanel; flechas, Home/End; activación manual si carga |
| Tabla | HTML table/caption/th/scope; `aria-sort`; controles como tab stops normales |
| Alert | `role=alert` solo urgente; mensajes informativos `status` |
| Toast | `status`, pausa, contenido no único |
| Stepper | lista ordenada, paso actual con `aria-current=step` |
| Timeline | lista semántica; fecha `time`; cambios anunciados una vez |
| Skeleton | contenedor `aria-busy`; skeleton oculto al árbol |
| Grupo selector | combobox/select con label “Grupo activo” |

Referencia: https://www.w3.org/WAI/ARIA/apg/patterns/ (consulta 2025-03-08).

## 5. Casos NEXO

### Riesgo e indicadores
Nivel y tendencia son texto. Gráfico tiene resumen y tabla alternativa. “Crítico” no dispara animación. La explicación de backend es accesible antes de acciones.

### WhatsApp
Estado de entrega se anuncia al abrir detalle, no cada polling. Teléfono enmascarado. Respuestas del acudiente tienen origen y fecha explícitos.

### Biometría
Captura no depende de color/pantalla externa: instrucciones textuales, visuales y estado programático en WebApp. Existe vía para completar alta sin huella y registrar pendiente según política.

### Offline/sync
Estado global visible y anunciado solo en cambios significativos. “Guardado local” y “Sincronizado” son labels diferentes. Conflictos no se resuelven automáticamente.

### SOS
Control grande, label textual, confirmación proporcional, no activación por gesto accidental. Resultado se anuncia asertivamente una vez. No sonido/parpadeo.

## 6. Tema, contraste y motion

- Preferencias iniciales siguen SO; usuario puede elegir Sistema/Claro/Oscuro.
- `forced-colors` conserva bordes, foco e iconos.
- `prefers-reduced-motion` aplica [08_MICRO_INTERACTIONS.md](./08_MICRO_INTERACTIONS.md).
- No impedir zoom ni selección de texto.
- Modo oscuro se prueba por separado; no se obtiene invirtiendo colores.

## 7. Matriz criterio × componente × pantalla

| WCAG | Riesgo NEXO | Componentes | Pantallas críticas | Prueba |
|---|---|---|---|---|
| 1.3.1 Info/relaciones | formularios/tablas | CMP-002–008/022 | OPS, QRY, AUD, ENR | lector + DOM |
| 1.4.3/1.4.11 | temas/estados | todos | todas | contraste automatizado/manual |
| 1.4.10 Reflow | shell/table/drawer | 030/022/034 | todas | 320 px/400% |
| 2.1.1 Teclado | controles compuestos | 005/032/036 | todas | recorrido teclado |
| 2.4.3/2.4.7/2.4.11 | foco y sticky | navegación/dialog | todas | secuencia y visibilidad |
| 2.5.8 Target | móvil/60+ | interactivos | OPS/ENR/SOS | medición 24/44 px |
| 3.2.3 Consistencia | rol/rutas | shell | todas | auditoría comparativa |
| 3.3.1–3.3.4 | errores/acciones críticas | forms/dialog | AUTH/OPS/CASE/ENR | error y recuperación |
| 3.3.7 Redundancia | grupo/datos | CMP-100/forms | OPS/QRY | prueba flujo |
| 3.3.8 Auth | login/OTP | auth controls | AUTH | password manager/pegado |
| 4.1.2 Nombre/rol/valor | custom UI | todos | todas | axe + lector |

## 8. Plan de pruebas

### Automatizadas en cada PR
Lint semántico, axe-core sobre estados, contraste de tokens, unit tests de teclado y nombres, visual regression a zoom/temas. Cero violaciones críticas/serias; automatización no declara conformidad.

### Manual por release
- Teclado completo y foco.
- NVDA+Firefox/Chrome, VoiceOver+Safari, TalkBack+Chrome.
- Zoom 200%, reflow 400%, texto grande, orientación, forced colors.
- Touch coarse y switch de input.
- Reduced motion, offline y errores.
- Login/2FA, citación, SOS, caso, consulta, exportación y enrolamiento.

### Con usuarios por hito
Mínimo: 2 usuarios por rol crítico, 5 profesores 60+, participantes con baja visión/movilidad/lector cuando sea posible. Medir éxito, tiempo, errores, ayuda, confianza y carga. No usar solo empleados del proyecto.

## 9. Defectos y severidad

- **Bloqueante:** tarea imposible, fuga de datos, trampa de foco, acción crítica inaccesible.
- **Alta:** criterio AA incumplido o error no recuperable.
- **Media:** fricción significativa con alternativa.
- **Baja:** mejora AAA/no normativa.

Bloqueantes y altas impiden release. Excepción temporal requiere owner, alternativa, fecha ≤30 días y comunicación.

## 10. Criterios de aceptación

- 0 fallos WCAG 2.2 AA conocidos.
- Tareas críticas completadas por teclado y lector.
- 400% reflow y touch 44 px en acciones críticas.
- Profesores 60+ logran ≥80% éxito tras ≤10 min.
- Ninguna PII se anuncia o enfoca fuera del permiso/contexto.
