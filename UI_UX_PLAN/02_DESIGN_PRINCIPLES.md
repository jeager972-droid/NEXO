# 02. Principios de diseño verificables

**Autoridad:** [UX_DESIGN.md](./UX_DESIGN.md). **Objetivo:** convertir la filosofía en leyes medibles del frontend.

## 1. Leyes obligatorias

### DEC-010. Una pantalla, una pregunta
El título, primer bloque y acción primaria deben responder la misma pregunta. Prueba: al ocultar el contenido secundario, 4 de 5 participantes explican propósito y próximo paso en ≤5 s.

### DEC-011. Una acción primaria
Solo un control por estado usa énfasis primario. Acciones secundarias son texto, tono neutro o menú. Excepción: elección binaria simétrica solo cuando ninguna opción es predeterminada.

### DEC-012. Reconocimiento sobre recuerdo
Grupo activo, filtros, destinatario, fecha y origen permanecen visibles o recuperables. Nunca pedir información ya disponible y vigente. Métrica: cero reingresos redundantes en un mismo flujo.

### DEC-013. Prevención antes que error
Usar opciones válidas, defaults explícitos, validación inmediata no intrusiva y resumen previo. Bloquear solo lo inválido. Preservar datos ante error.

### DEC-014. Contexto persistente
El grupo activo persiste entre Inicio, Operaciones y Consultas hasta cambio, pérdida de permiso, cierre de sesión o caducidad institucional. Un retorno desde detalle restaura posición, filtros y foco.

### DEC-015. Divulgación progresiva
Mostrar primero situación, impacto y acción; evidencia, historial y datos técnicos bajo expansión o superficie de detalle. Lo oculto nunca debe ser requisito para entender el riesgo.

### DEC-016. Estado del sistema visible
Toda operación >100 ms confirma recepción; >1 s muestra progreso/skeleton; >10 s ofrece continuar en segundo plano o reintentar. No usar progreso falso.

### DEC-017. Control y recuperación
Cancelar antes de enviar, deshacer acciones reversibles y confirmar acciones críticas. Errores dicen qué ocurrió, qué se preservó y qué hacer.

### DEC-018. Consistencia semántica
Mismo objeto, acción y estado conservan etiqueta, icono y comportamiento en todas las superficies. No usar “Seguimiento” y “Caso” como equivalentes de navegación: el módulo es **Casos Activos**.

### DEC-019. Privacidad por defecto
La vista inicial contiene el mínimo necesario. Revelar datos sensibles exige permiso y propósito; no se exportan por defecto.

### DEC-020. Accesibilidad como contrato
WCAG 2.2 AA es criterio de salida. La equivalencia teclado/táctil/lector, zoom 400%, reflow, foco visible y reduced motion no son variantes opcionales.

## 2. Presupuestos

| Presupuesto | Límite | Validación |
|---|---:|---|
| Elementos prioritarios simultáneos | ≤5 | conteo de primer viewport |
| Acción primaria | 1 | revisión automática/visual |
| Profundidad para iniciar tarea frecuente | ≤1 selección desde módulo | prueba de recorrido |
| Pasos de operación frecuente | 3–5 decisiones | analítica por `FLOW-*` |
| Campos visibles por paso | ideal ≤5 | inventario de formulario |
| Opciones sin agrupación | ≤7; si excede, buscar/agrupar | inspección |
| Respuesta visual a entrada | ≤100 ms | medición RUM |
| Transición | 150–250 ms; máximo 300 ms | tokens de motion |
| Contenido textual | 65–75 caracteres por línea | visual regression |
| Target táctil recomendado | 44×44 CSS px; mínimo WCAG 24×24 | auditoría |
| Tareas críticas sin scroll móvil | no obligatorio; acción siempre alcanzable | prueba 360×640 |

Los números son límites de diseño, no afirmaciones psicológicas universales. La ley de Miller no justifica mágicamente “7”; se valida por tarea y población.

## 3. Pruebas de selección de patrón

### Tabla
Usarla si la tarea primaria es comparar ≥3 atributos entre ≥5 registros, ordenar o exportar. Debe tener encabezados, versión móvil por prioridades y alternativa de detalle. No usar para casos, notificaciones o acciones de una persona.

### Card
Usarla para iniciar una acción, resumir una entidad con destino claro o agrupar contenido autónomo. No envolver secciones arbitrarias ni anidar cards. Una lista uniforme supera a una cuadrícula de cards cuando hay >8 elementos.

### Modal/dialog
Solo para confirmación crítica, decisión breve que bloquea el flujo o resultado que requiere reconocimiento. No para formularios largos, navegación ni detalles. En móvil puede ser pantalla completa sin cambiar semántica.

### Drawer/bottom sheet
Drawer para contexto o edición secundaria manteniendo origen visible en ancho amplio. Bottom sheet para selección/acción breve en touch. Si contiene más de un objetivo, se convierte en página.

### Gráfico
Solo si una forma/tendencia responde más rápido que texto. Debe incluir resumen textual, unidades, periodo, tabla o lista accesible y no superar una visualización dominante por pantalla.

## 4. Matriz de evidencia aplicada

| ID | Problema humano | Principio/fuente | Regla medible | Métrica |
|---|---|---|---|---|
| DEC-012 | memoria de trabajo limitada | NN/g, reconocimiento sobre recuerdo | contexto y opciones visibles | reingresos redundantes = 0 |
| DEC-016 | incertidumbre por latencia | heurística visibilidad de estado; Doherty como orientación | feedback ≤100 ms | p75 input feedback ≤100 ms |
| DEC-010 | carga extrínseca | NN/g carga cognitiva; minimalismo | una pregunta y ≤5 prioridades | comprensión ≤5 s |
| DEC-014 | pérdida de contexto | continuidad y Goal Gradient | retorno exacto y progreso visible | abandonos por retorno <5% |
| DEC-013 | errores y ansiedad | heurísticas prevención/recuperación | preservar datos y explicar reparación | recuperación ≥90% |
| DEC-011 | fatiga decisional/Hick | reducir elecciones concurrentes | 1 primaria, opciones agrupadas | selección correcta ≥95% |
| DEC-020 | destreza/visión variable | WCAG 2.2; investigación adultos mayores | 44 px recomendado, foco 2 px | 0 fallos AA |
| DEC-018 | expectativas aprendidas/Jakob | consistencia y estándares | vocabulario único | términos duplicados = 0 |
| DEC-015 | atención selectiva/Gestalt | proximidad, jerarquía, progressive disclosure | resumen antes de detalle | dato clave encontrado ≤10 s |
| DEC-017 | Peak-End | cierre explícito y recuperable | resultado + siguiente acción | confianza ≥5/7 |
| DEC-030 | Fitts | tamaño/distancia proporcional | acción frecuente grande y próxima | errores de toque <2% |
| DEC-031 | Von Restorff | singularidad controlada | acento reservado a estado/acción | primaria reconocida ≥90% |

## 5. Fuentes y límites

Consultadas el **2025-03-08**:

- WCAG 2.2, W3C: https://www.w3.org/TR/WCAG22/
- Novedades WCAG 2.2: https://www.w3.org/WAI/standards-guidelines/wcag/new-in-22/
- WAI-ARIA APG: https://www.w3.org/WAI/ARIA/apg/patterns/
- Heurísticas NN/g: https://www.nngroup.com/articles/ten-usability-heuristics/
- Carga cognitiva NN/g: https://www.nngroup.com/articles/minimize-cognitive-load/
- Reconocimiento y recuerdo: https://www.nngroup.com/articles/recognition-and-recall/
- Usabilidad para mayores: https://www.nngroup.com/articles/usability-for-senior-citizens/
- Apple HIG Foundations: https://developer.apple.com/design/human-interface-guidelines/foundations
- Material 3 breakpoints: https://m3.material.io/foundations/layout/breakpoints/overview
- PWA checklist: https://web.dev/articles/pwa-checklist

**Límites:** heurísticas y “leyes UX” orientan hipótesis, no sustituyen pruebas con roles reales. Apple/Material informan comportamiento nativo, pero NEXO mantiene identidad propia. WCAG AA no cubre todas las necesidades cognitivas; se añaden lenguaje claro y pruebas 60+.

## 6. Excepciones

Una excepción requiere registro `DEC-*`, problema concreto, alternativa descartada, duración y prueba. No son excepciones válidas: “se ve mejor”, “lo hace otra app”, “es más moderno” o limitación evitable del framework.

## 7. Criterios de aceptación

- Toda pantalla y flujo referencia al menos una ley.
- Toda excepción queda registrada y medible.
- Ningún patrón se elige por costumbre sin pasar su prueba.
- Pruebas por rol reportan éxito, tiempo, error, ayuda, confianza y carga percibida.
