# 08. Microinteracciones

**Propósito:** hacer visible estado, causalidad y continuidad sin decorar ni retrasar. **Autoridad:** [UX_DESIGN.md](./UX_DESIGN.md).

## 1. Tokens

| Token | Duración | Uso |
|---|---:|---|
| `motion.none` | 0 ms | reduced motion/cambio inmediato |
| `motion.fast` | 150 ms | hover, focus, press, icono |
| `motion.standard` | 200 ms | selección, reveal corto |
| `motion.deliberate` | 250 ms | drawer/sheet/transición espacial |
| `motion.max` | 300 ms | límite absoluto |

Curva de entrada/salida: salida exponencial `(.22,1,.36,1)`; opacidad puede ser lineal corta. Solo `transform` y `opacity`; color/borde pueden transicionar ≤150 ms. Nunca animar layout, blur grande, sombras complejas ni números que retrasen lectura.

## 2. Presupuesto

- Feedback visual a input ≤100 ms.
- Ninguna coreografía bloquea interacción.
- Máximo un elemento en movimiento dominante.
- Desplazamiento ≤12 px; escala de press 0.98 solo en targets grandes y nunca en texto/filas.
- Animación total simultánea ≤300 ms.
- 60 fps objetivo; degradar a cambio instantáneo si el dispositivo no sostiene fluidez.

## 3. Estados de control

| Estado | Respuesta |
|---|---|
| Hover | cambio tenue de superficie/borde; no revela función indispensable |
| Focus-visible | outline 2 px, offset 2 px, contraste ≥3:1; sin transición que demore |
| Press/tap | superficie más fuerte + opcional escala 0.98 durante contacto |
| Selected | fondo/borde + icono/check + `aria-selected/checked` |
| Disabled | contraste suficiente para label, razón visible/tooltip al elemento adyacente; no target engañoso |
| Loading | label estable, progreso inline, `aria-busy`; bloquea doble envío |
| Error | mensaje junto al control, icono y resumen; foco al primer error tras submit |
| Success | confirmación textual; no depender de check animado |

Touch y teclado producen el mismo cambio. `Enter/Space` no dispara dos veces. Drag futuro siempre tendrá botones alternativos mover arriba/abajo.

## 4. Navegación y continuidad

- Cambio de ruta: contenido nuevo aparece por opacidad 150 ms; no secuencia escalonada.
- Drawer: traslación desde borde lógico 250 ms; fondo sin blur; foco entra tras montar y vuelve al disparador.
- Bottom sheet: traslación vertical 250 ms; gesto de cerrar nunca es única vía.
- Tabs: indicador se mueve 150 ms si la geometría está disponible; panel cambia sin desplazamiento.
- Retorno: restaura scroll y foco instantáneamente; no reanima toda la página.
- Skeleton a contenido: crossfade 150 ms sin salto de geometría.

## 5. Guardado y operaciones

### Guardado breve (<1 s)
Press → estado “Guardando” inmediato → “Guardado” textual 2–4 s → estado estable. No toast si el resultado ya es visible junto al control.

### Proceso lento
A los 100 ms confirmar recepción; a 1 s progreso/skeleton; a 10 s explicar continuidad o segundo plano. Si porcentaje es real, mostrarlo; si no, progreso indeterminado. Cancelar solo si backend lo soporta.

### Mutación crítica
Tras confirmar, botón queda ocupado y el contenido no desaparece. Timeout muestra “resultado desconocido” y consulta estado antes de reintento. Éxito muestra alcance y auditoría. Error conserva entradas.

### Eliminación/inactivación
Preferir inactivar/deshacer. Fila no se desvanece antes de confirmación backend. Tras éxito, retirada 150 ms y anuncio live region. Si se deshace, vuelve a posición y foco.

## 6. Operaciones específicas

| Patrón | Secuencia |
|---|---|
| Cambio de grupo | seleccionar → topbar confirma → contenido skeleton estable → datos + fecha; formulario sucio pide decisión |
| Citar acudiente | pasos sin animación ornamental → resumen → enviar → estado WhatsApp progresa por texto/icono |
| SOS enviado | press → confirmación crítica breve → envío bloqueado → resultado inequívoco; sin pulso/parpadeo |
| SOS recibido | notificación nativa del sistema operativo (sonido/vibración) permitida para reducir tiempo de respuesta; sin beep/pulso/parpadeo decorativo de NEXO. Ver DEC-021. |
| Caso | iniciar → responsable/estado se actualiza → nueva entrada timeline resaltada una vez ≤2 s sin movimiento |
| Nota | guardar local temporal si seguro → enviar → insertar timeline → foco a confirmación |
| WhatsApp | en cola → enviado → entregado/fallido/respondido; cambios anunciados sin mover fila |
| Sync PWA | badge discreto cambia; detalle muestra cola; éxito no lanza múltiples toasts |
| Instalación | CTA solo tras elegibilidad; prompt nativo; retorno muestra “Instalado” |

## 7. Error y recuperación

- Error de campo: transición de borde ≤150 ms y mensaje inmediato; sin shake.
- Error de sección: alert estática con foco programático solo tras submit.
- Error global: superficie persistente y reintento; no toast efímero.
- Red recuperada: “Conexión restablecida. Sincronizando N cambios”; luego resumen único.
- Conflicto: no animar elección; comparar versiones y exigir decisión explícita.

## 8. Reduced motion y sensibilidad

Con `prefers-reduced-motion: reduce`: duración 0–100 ms, sin traslación, escala, parallax, auto-scroll suave ni contadores. El significado permanece mediante texto, icono y foco. No sonido. Ningún destello >3 por segundo; NEXO no utiliza destellos.

## 9. Interrupción

Toda animación responde a nueva entrada: si se invierte drawer, continúa desde posición actual; si cambia ruta, cancela transición previa. El estado lógico se actualiza antes que la animación. La pérdida de visibilidad pausa animación no esencial, no timers de seguridad del backend.

## 10. Instrumentación

Medir p75 input-to-feedback, duración de mutación, doble submit evitado, abandono por paso, reduced motion activo y errores de sync. No registrar contenido ni PII. Umbrales: feedback p75 ≤100 ms; transición p95 ≤300 ms; long tasks durante interacción <50 ms.

## 11. Criterios de aceptación

- Toda animación explica estado o continuidad.
- Equivalencia teclado/touch y reduced motion comprobada.
- No hay layout animation, rebote, parpadeo, sonido ni stagger.
- Procesos lentos y timeouts comunican estado real.
- Foco se conserva/restaura en todas las superficies transitorias.
