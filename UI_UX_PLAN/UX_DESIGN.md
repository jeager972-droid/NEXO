# NEXO — Visión del Frontend 

## Objetivo
NEXO no es una aplicación de asistencia escolar.

NEXO es un Sistema Inteligente de Gestión Escolar. Usa biometría, automatización, auditoría e inteligencia de comportamiento para reducir el trabajo operativo de toda una institución educativa. El backend ya implementa prácticamente toda la lógica; el objetivo del nuevo frontend no es agregar funciones, sino construir una interfaz que permita usar todo el potencial del sistema con la menor fricción posible. Prioridad absoluta: experiencia de usuario.

## Filosofía
> NEXO no muestra datos. NEXO detecta situaciones y propone acciones.

> NEXO no debería sentirse como abrir una aplicación. Debería sentirse como entrar a tu jornada de trabajo.

El usuario no abre software; entra a trabajar.

## Principios de diseño
1. **Todo a un clic:** acciones importantes visibles inmediatamente.
2. **Cero fricción:** un profesor de 60+ años aprende NEXO en menos de 10 min.
3. **Belleza sin exceso:** no buscamos dashboards llenos de gráficos; buscamos espacio, tipografía, jerarquía visual impecable, componentes grandes y mínima carga cognitiva.
4. **Inteligencia silenciosa:** el backend piensa; el usuario recibe qué, por qué y qué hacer.
5. **Sensación premium:** debe sentirse como un producto construido por una startup multimillonaria; no parece software escolar, no parece un ERP. Compite visualmente con Apple Health, Linear, Arc, Raycast, Notion, Stripe Dashboard, Tesla App, iOS Settings. Inspirarse, no copiar.

## Identidad visual
Transmitir: tranquilidad, orden, confianza, rapidez, profesionalismo, tecnología, institucionalidad.  
Nunca: infantil, gubernamental, antiguo, saturado.

## Estilo visual
Minimalista, mucho espacio, componentes grandes, pocas líneas, poco ruido, animaciones suaves, transiciones rápidas. Modo claro y oscuro desde el inicio.

## Rendimiento
Las animaciones no afectan el rendimiento. Si comprometen velocidad, desaparecen. Todo debe sentirse instantáneo.

## Reglas de interfaz

### Espaciado
- Nunca más de 5 elementos importantes visibles.
- Muchísimo espacio negativo.
- Nada de interfaces comprimidas.

### Tipografía
- Toda pantalla debe poder entenderse leyendo únicamente los títulos.

### Jerarquía
- Cada pantalla tiene:
  - Un título.
  - Un objetivo.
  - Una acción principal.
- Nunca dos acciones principales.

### Colores
- El color nunca será decorativo.
- Cada color comunica algo.
- Verde: estado correcto.
- Azul: información.
- Rojo: acción crítica.
- Naranja: atención.

### Animaciones
- Todas duran entre 150 y 300 ms.
- Nunca bloquean.
- Nunca retrasan.
- Nunca distraen.

### Tarjetas
- Todas las acciones importantes viven en tarjetas.
- Nunca listas enormes.

### Inputs
- Siempre grandes.
- Nunca pequeños.
- Pensados para dedos.
- No mouse.

### Gráficos
- Inspiración: Apple Health.
- Pero mucho más minimalista.
- Nunca gráficos 3D.
- Nunca dashboards financieros.

### Componentes
- Todo debe poder reutilizarse.
- No diseñar pantallas.
- Diseñar componentes.

## Arquitectura por rol

Cada rol tiene una experiencia distinta. El menú lateral se filtra automáticamente según el rol.

### Rector

- **Navegación:** Inicio → Operaciones → Notificaciones → Casos Activos → Consultas → Auditoría → Informes.
- **Inicio:** Panel Ejecutivo — Estadísticas del Día (Presentes, Inasistentes, Alertas) de toda la institución + Eventos Recientes; tocar una estadística abre el detalle institucional.
- **Operaciones:** todas — Citar acudiente, Autorizar salida, SOS, Generar permiso, Mandar solicitud, Solicitar seguimiento/Caso, Salida pedagógica, Cambio de horario, Reportar incidente, Reportar daño.
- **Consultas:** Ejecutivo Institucional (Métricas Globales, Asistencia Institucional, Estadísticas Históricas, Indicadores Críticos) y Reportes Consolidados (Consolidados, Históricos, Exportaciones).
- **Casos Activos:** acceso total; revisar e iniciar casos.
- **Auditoría:** Asistencia, Disciplina, Permisos y Salidas, Actividad Docente, Alertas.
- **Informes:** previsualizar y exportar CSV de asistencia/biométrica.
- **Agenda Institucional:** crea reuniones, capacitaciones, comités, entrega de informes y eventos institucionales.
- **Notificaciones:** alertas, permisos, citaciones, solicitudes de seguimiento, SOS.

### Coordinador

- **Navegación:** Inicio → Operaciones → Notificaciones → Casos Activos → Consultas.
- **Inicio:** Panel de Dirección — Estadísticas del Día (Presentes, Inasistentes, Alertas) + Eventos Recientes (igual que rector).
- **Operaciones:** Citar acudiente, Autorizar salida, SOS, Generar permiso, Mandar solicitud, Solicitar seguimiento/Caso, Salida pedagógica, Cambio de horario.
- **Consultas:** Incidentes (Spam Biométrico, Vulneraciones, Alertas, Seguimiento Estudiantil) y Permisos (Permisos Emitidos, Salidas permitidas, Salidas Pedagógicas).
- **Casos Activos:** sí.
- **Auditoría e Informes:** no. Exclusivos del rector.
- **Agenda Institucional:** crea reuniones, capacitaciones, comités, entrega de informes y eventos institucionales.
- **Notificaciones:** alertas, permisos, citaciones, solicitudes.

### Docente

- **Navegación:** Inicio → Operaciones → Notificaciones → Consultas.
- **Inicio:** Panel Docente — selector de grupo + 4 tarjetas (Presentes, Inasistentes, Alertas, Permisos) + Eventos Recientes; tocar una tarjeta abre el detalle del grupo seleccionado.
- **Operaciones:** Citar acudiente, Generar permiso, Reportar incidente, Mandar solicitud, SOS.
- **Consultas:** Mis Clases — Llegadas Tarde, Inasistencias, Estudiantes Ausentes, Estudiantes fuera del salón, Estudiantes con Permiso, Citaciones.
- **Casos Activos:** no gestiona la lista; puede ser notificado.
- **Notificaciones:** alertas de sus grupos, citaciones, respuestas.

### Secretaría

- **Navegación:** Inicio → Operaciones → Notificaciones → Consultas → Enrolamiento.
- **Inicio:** Panel Secretaría — Eventos recientes.
- **Operaciones:** Enviar solicitud.
- **Consultas:** Gestión Estudiantil (Estudiantes, Grupos, Acudientes, Matrículas, Cambios Registro), Personal, Mensajería, Históricos, Control de Acceso.
- **Enrolamiento:** registro de estudiantes, listado con búsqueda, vinculación de huella, activar/inactivar.
- **Casos Activos:** no.
- **Notificaciones:** mensajes del sistema.

### Psicoorientador

- **Navegación:** Inicio → Operaciones → Notificaciones → Casos Activos → Consultas.
- **Inicio:** Panel de Bienestar — eventos recientes y casos activos.
- **Operaciones:** Citar acudiente, Reportar incidente, SOS; además gestiona casos: iniciar seguimiento psicológico, agregar notas, registrar evolución.
- **Consultas:** Análisis de Riesgo y Seguimientos completados.
- **Casos Activos:** sí; recibe solicitudes, gestiona casos, notas y evolución auditada.
- **Notificaciones:** solicitudes de seguimiento.

### Portero y Auxiliar

- **Navegación:** Inicio → Operaciones → Notificaciones.
- **Inicio:** Panel de Servicio — Eventos recientes.
- **Operaciones:** SOS, Mandar solicitud, Reportar daño.
- **Notificaciones:** Centro de Órdenes (instrucciones directas de directivos).
- **Casos, Consultas, Auditoría, Informes, Enrolamiento:** no.

## Inicio de sesión
Experiencia de 3 pasos:
1. Animación del logo (máx. 2 s, sin botón "Continuar").
2. Saludo personalizado: "Buenos días, Profesor hon Pérez. Martes 22 de julio".
3. Ingreso automático al Home.

## Home
Responde: **¿Qué necesita mi atención ahora?** El contenido cambia según el rol; el desglose completo está en Arquitectura por rol.
- Primero selecciona el grupo.
- Luego aparecen métricas importantes: Presentes, Ausentes, Permisos activos, Alertas activas.
- Tarjetas tipo grid/botones; al entrar, muestran los datos correspondientes.

## Tarjeta "Hoy en (nombre del colegio)"
Tarjeta inteligente desplegable en la parte superior.
- Ejemplo: 5 clases (ver clases), reunión a las 2:30 PM, 2 solicitudes pendientes, ninguna citación pendiente.
- Si hay urgencia: "Se detectó comportamiento anómalo" o "El acudiente respondió la citación".
- Solo aparece la barra; al hacer clic, abre el chatbot de NEXO para explicar el contexto.
- Nunca múltiples banners ni ruido visual.

## Navegación

Menú lateral, no híbrido. Los ítems se filtran automáticamente según el rol:

- Inicio
- Operaciones
- Notificaciones
- Casos Activos (Coordinador, Rector, Psicoorientador)
- Consultas (Coordinador, Secretaría, Docente, Psicoorientador, Rector)
- Auditoría (Rector)
- Informes (Rector)
- Enrolamiento (Secretaría)

El modo oscuro y la tarjeta de usuario con cierre de sesión quedan en el panel inferior.

## Operaciones
Centro de acciones, no información:
- Citar acudiente
- Generar permiso
- Reportar incidente
- Solicitar apoyo
- Enviar solicitud
- Programar reunión

## Agenda Institucional
El rector o coordinador crea:
- Reuniones generales, por departamentos, por profesores.
- Capacitaciones, comités, entrega de informes, eventos institucionales.

El profesor no administra calendarios; NEXO recuerda cada evento.

## Notificaciones
No es una bandeja; es una conversación con NEXO. El usuario no escribe, solo recibe:
- "El acudiente aceptó la citación. Ver detalles."
- "Se detectó comportamiento anómalo. Revisar caso."
- "Reunión institucional hoy a las 2:30 PM. Ver información."

## Consultas
Replanteadas: tablas tradicionales desaparecen. Experiencia visual cómoda y rápida.

## Perfil del estudiante
Desaparece. El profesor rara vez necesita ficha completa. Las acciones ocurren desde Operaciones.

## Caso
Cuando hay una anomalía no se abre perfil; se abre un Caso:
- Fotografía
- Nombre
- Grupo
- Nivel de riesgo
- Resumen automático
- Indicadores
- Análisis de NEXO
- Acciones disponibles

## Indicadores de comportamiento
Inspirados en Apple Health, más simples. Sin gráficas enormes. Ejemplo:
- Asistencia 🟢 Normal
- Baños 🟠 Alto
- Evasiones 🔴 Crítico
- Llegadas tarde 🟡 Medio

Interpretación inmediata.

## Acciones del caso
- Iniciar seguimiento
- Iniciar seguimiento psicológico

## Seguimiento psicológico
Al iniciar, el psicoorientador recibe notificación, acepta, comienza seguimiento, agrega notas, registra evolución. Todo auditado.

## Casos activos
El módulo se llama oficialmente **Casos Activos** porque comunica prioridad.

## Filosofía de pantallas
Cada pantalla responde una sola pregunta:
- **Inicio:** ¿Qué necesita mi atención ahora?
- **Operaciones:** ¿Qué acción quiero realizar?
- **Notificaciones:** ¿Qué ocurrió mientras trabajaba?
- **Casos activos:** ¿Qué estudiantes requieren seguimiento?
- **Consultas:** ¿Qué información necesito buscar?

## Decisiones UX aprobadas
- Menú lateral.
- Operaciones mediante tarjetas grid.
- Notificaciones tipo conversación con NEXO.
- Skeleton loading.
- Errores resaltados en el componente.
- Modo oscuro.
- Sin sonidos.
- Pistas contextuales solo las primeras tres veces.
- Balance animaciones/rendimiento.
- Estética premium tecnológica.

## Filosofías adicionales

1. **No software escolar.** No queremos que el usuario piense: *"Estoy usando el sistema del colegio"*. Queremos que piense: *"Estoy usando una herramienta premium de trabajo"*. Eso cambia todo.
2. **No castigar con información.** No mostrar edad, documento, dirección, EPS o datos del acudiente. Solo lo que ayude al trabajo actual.
3. **Menos es más.** 15 botones = mal diseño. 3 botones enormes = probablemente bien diseñado.
4. **La app propone acciones.** No "Juan tiene muchas salidas al baño". Sí: "NEXO recomienda iniciar seguimiento".
5. **Colores no comunican solos.** Rojo, amarillo y verde siempre acompañados de ícono, texto y estado (accesibilidad/daltonismo).
6. **Sin miedo.** Nada rojo urgente parpadeante. Tranquilo, ordenado, controlado.
7. **Una acción = un flujo.** "Citar acudiente" = elegir estudiante, elegir fecha, confirmar. No veinte campos.
8. **Componentes reutilizables.** La tarjeta de estudiante sirve para profesor, secretaría, coordinación y psicología con pequeñas variaciones.
9. **El backend manda.** El frontend no calcula reglas, riesgos ni umbrales; solo representa.
10. **El profesor no administra.** Enseña; la administración desaparece.
11. **El coordinador sí administra.** Necesita contexto y prioridades, no tablas infinitas.
12. **Los casos tienen vida.** Nacen, evolucionan, empeoran, cierran; expediente vivo.
13. **Indicadores vivos.** Tendencias, estado, evolución: Normal, Subiendo, Bajando, Crítico.
14. **Nunca pregunta dos veces.** Si escoge Grupo 7A, toda la app asume 7A hasta que cambie.
15. **Recuerda contexto.** Al volver tras una notificación, regresa exactamente donde estaba.
16. **Animaciones con propósito.** Cambio de estado, carga, confirmación, éxito. Nada más.
17. **Home es escritorio de trabajo.** Nunca un dashboard.
18. **Chat de NEXO.** Asistente institucional. No conversa ni responde preguntas; informa, guía, propone.
19. **IA invisible.** Sin botones "IA", "GPT" o "Asistente Inteligente". Solo "NEXO detectó...", "NEXO recomienda...", "NEXO organizó...".
20. **Sensación final.** Cerrar la app y pensar: *"Qué cómodo fue trabajar"*. Belleza es consecuencia; comodidad es objetivo.
