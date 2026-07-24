# 01. Filosofía de diseño de producto NEXO

**Estado:** especificación objetivo 1.0  
**Autoridad:** [UX_DESIGN.md](./UX_DESIGN.md) prevalece ante cualquier contradicción.  
**Alcance:** WebApp institucional responsive y PWA; adaptadores futuros Tauri/Capacitor. El acudiente interactúa exclusivamente por WhatsApp.

## 1. North Star

> NEXO convierte señales institucionales dispersas en la siguiente acción comprensible, segura y oportuna.

La medida final no es cuántos datos se muestran, sino si cada persona termina su tarea con menos esfuerzo, menos incertidumbre y plena comprensión de qué ocurrió. El usuario no “administra un sistema”: entra a su jornada y encuentra el contexto preparado.

### Promesa de experiencia

1. **Orientación inmediata:** en 5 segundos se entiende qué requiere atención.
2. **Acción próxima:** en un clic se inicia la tarea prioritaria; “un clic” significa descubrir e iniciar, no omitir confirmaciones seguras.
3. **Confianza explicable:** toda recomendación incluye qué se detectó, por qué importa, fuente/actualización y acción propuesta.
4. **Continuidad:** grupo, filtros y punto de retorno persisten mientras sean válidos.
5. **Cierre claro:** toda acción termina con resultado, alcance, estado de entrega y siguiente paso.

**Métrica North Star:** porcentaje de jornadas en que las tres tareas prioritarias se completan sin ayuda externa, error irreversible ni pérdida de contexto. Objetivo inicial ≥85%; profesores 60+ ≥80% tras ≤10 minutos de familiarización.

## 2. Escena de uso rectora

Un docente de 62 años abre NEXO en un teléfono bajo luz intensa entre clases, con menos de dos minutos y conectividad irregular. Debe reconocer su grupo, detectar una ausencia, iniciar una citación y saber si se enviará por WhatsApp sin recordar rutas ni interpretar un tablero. Esta escena exige superficies claras, texto legible, objetivos únicos, controles táctiles grandes, estado de red explícito y cero ornamentación competitiva.

La misma arquitectura escala a un rector en monitor de escritorio, con mayor densidad y comparación institucional, sin cambiar lenguaje ni modelo mental.

## 3. Emociones y tono

- **Calma operativa:** la urgencia se señala sin alarmismo, parpadeo ni miedo.
- **Control:** cada automatización es reversible cuando sea posible y explicable siempre.
- **Competencia:** lenguaje directo, institucional y humano, nunca condescendiente.
- **Confianza:** se distingue dato confirmado, dato obsoleto, inferencia y recomendación.
- **Alivio:** NEXO recuerda contexto, evita repetición y prepara decisiones.

### Voz

- “El acudiente confirmó la citación para mañana a las 8:00 a. m.”
- “NEXO recomienda revisar el caso: las inasistencias aumentaron durante 3 semanas.”
- “No se pudo enviar. Tu información está guardada; reintenta cuando vuelva la conexión.”

Nunca: “¡Alerta crítica!!!”, “La IA decidió”, “Error 500”, “Procesando mágicamente”.

## 4. Diferenciación

NEXO no es un ERP escolar porque organiza el trabajo por situaciones y decisiones, no por entidades administrativas ni menús CRUD. Tampoco es un chatbot: la conversación NEXO es un flujo de mensajes institucionales de solo lectura con acciones contextuales. La inteligencia es silenciosa: clasifica y propone, pero no oculta evidencia, suplanta criterio profesional ni inventa certeza.

### Anti-patrones

- Inicio como mosaico financiero de KPIs y gráficos.
- Tablas por defecto cuando la tarea es decidir sobre una persona o situación.
- Perfil integral del estudiante expuesto fuera de necesidad contextual.
- Múltiples banners, acciones primarias o llamadas de instalación.
- Interfaces infantiles, gubernamentales, saturadas o “IA” decorativa.
- Confirmación modal para toda acción; solo se reserva para consecuencias críticas.
- Color como única señal, urgencia intermitente o sonido.

## 5. Principios de confianza

### DEC-001. Privacidad contextual
Mostrar solo datos necesarios para la tarea, rol y momento. Ocultar documento, dirección, EPS y datos del acudiente salvo que una tarea autorizada los requiera. Registrar acceso sensible.

### DEC-002. Inteligencia explicable
Una recomendación presenta: señal, periodo, fuente, nivel/estado calculado por backend y acción sugerida. El frontend nunca calcula riesgo ni umbrales.

### DEC-003. Autoridad humana
NEXO recomienda; el personal autorizado decide. Acciones disciplinarias, psicológicas, salidas y comunicaciones requieren identidad del actor, confirmación proporcional y auditoría.

### DEC-004. Serenidad proporcional
Crítico significa consecuencia grave y próxima, no dato llamativo. Rojo ocupa el mínimo necesario y siempre se acompaña de icono, etiqueta y explicación.

### DEC-005. Integridad visible
Estados de envío, sincronización y actualización no se traducen falsamente a éxito. “Guardado en este dispositivo” no equivale a “registrado en la institución”.

## 6. Tensiones resueltas

| Tensión | Regla |
|---|---|
| Belleza vs. velocidad | Si una decisión visual añade latencia, movimiento o lectura sin mejorar tarea, desaparece. |
| Inteligencia vs. explicabilidad | La recomendación nunca sustituye evidencia resumida ni fecha. |
| Urgencia vs. serenidad | Prioridad por orden, texto y contraste; no por animación o volumen cromático. |
| Densidad vs. comprensión | Vista resumida con divulgación progresiva; tablas solo para comparación/exportación. |
| Automatización vs. control | Defaults y propuestas, nunca consecuencias ocultas. |
| Consistencia vs. adaptación | Un sistema y vocabulario; cambia composición por ancho/capacidad, no arquitectura. |

## 7. Objetos y vocabulario canónico

- **Situación:** señal que merece interpretación.
- **Acción operativa:** comando institucional acotado.
- **Caso:** expediente vivo de seguimiento, con estado, responsable, indicadores y evolución.
- **Indicador:** dato interpretado por backend con estado y tendencia.
- **Notificación:** mensaje institucional de solo lectura que informa y puede proponer acción.
- **Evento:** hecho registrado y fechado.
- **Grupo activo:** contexto persistente seleccionado.
- **Acudiente:** actor externo por WhatsApp; no usuario WebApp.
- **Entrega:** ciclo del mensaje (en cola, enviado, entregado, leído si existe, fallido, respondido).

## 8. Modelo de calidad

Toda capacidad usa IDs `CAP-*`; flujos `FLOW-*`; pantallas `SCR-*`; componentes `CMP-*`; decisiones `DEC-*`. Estados técnicos permitidos:

- `Implementado (Verificado)`: solo prueba manual end-to-end, evidencia Nivel 1.
- `Implementado (Pendiente de validación funcional)`: evidencia técnica Nivel 2.
- `Backend listo`: Nivel 3.
- `Backend parcial`: soporte incompleto explicado.
- `Planificado`: visión Nivel 4.

Sin ejecución real durante esta auditoría, no se asigna Nivel 1.

## 9. Recomendaciones no aprobadas

- **REC-001:** sustituir la animación fija de login por carga real y saludo posterior no bloqueante. La fuente aprueba máximo 2 s, pero rendimiento y accesibilidad aconsejan no imponer espera.
- **REC-002:** resolver la tensión “todas las acciones en tarjetas” usando tarjetas solo para iniciar operaciones; listas y tablas se admiten cuando mejoran comparación y escala.
- **REC-003:** renombrar en implementación “Seguimiento” a “Casos Activos”, como dicta la fuente.
- **REC-004:** validar con instituciones si “Portero” debe mostrarse como “Portero” para evitar personalización innecesaria del rol.

## 10. Criterios de aceptación

- Cada pantalla responde una pregunta y presenta una sola acción primaria.
- Ninguna recomendación aparece sin motivo, fecha y acción disponible.
- Ningún estado depende solo del color.
- El acudiente no recibe navegación WebApp.
- Un profesor 60+ completa citación, permiso y consulta con ≥80% de éxito sin ayuda tras 10 minutos.
- El sistema comunica offline, obsolescencia y sincronización sin afirmar éxito anticipado.

## 11. Trazabilidad

Esta filosofía gobierna [02_DESIGN_PRINCIPLES.md](./02_DESIGN_PRINCIPLES.md), [03_DESIGN_SYSTEM.md](./03_DESIGN_SYSTEM.md), [05_INFORMATION_ARCHITECTURE.md](./05_INFORMATION_ARCHITECTURE.md), [06_USER_FLOWS.md](./06_USER_FLOWS.md) y [12_FRONTEND_IMPLEMENTATION_GUIDE.md](./12_FRONTEND_IMPLEMENTATION_GUIDE.md).
