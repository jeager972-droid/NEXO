# 04. Lenguaje visual NEXO

**Dirección:** precisión serena para una jornada institucional real.  
**Autoridad:** [UX_DESIGN.md](./UX_DESIGN.md); tokens en [03_DESIGN_SYSTEM.md](./03_DESIGN_SYSTEM.md).

## 1. Escena y tema

NEXO se usa en pasillos luminosos, aulas cambiantes y oficinas de jornada completa. Por eso el modo claro es la base de trabajo diurno, no blanco puro sino un lienzo azul-gris cálido de bajo deslumbramiento. El modo oscuro existe para baja iluminación y preferencia, no como estética tecnológica obligatoria. Ambos conservan jerarquía y semántica.

## 2. Moodboard verbal

Papel técnico impecable, señalética institucional precisa, instrumentos médicos calmados, aluminio mate, tinta azul profunda y una única luz de estado. Superficies planas, bordes finos, ritmo amplio, cifras sobrias. La tecnología se percibe en la respuesta y la coherencia, no en efectos futuristas.

### Personalidad

- **Serena, no pasiva.**
- **Institucional, no burocrática.**
- **Premium, no lujosa.**
- **Tecnológica, no futurista.**
- **Humana, no infantil.**

## 3. Gramática visual

1. Un lienzo continuo domina; superficies adicionales aparecen por función, no para envolver todo.
2. Jerarquía mediante escala, peso, posición y espacio antes que color.
3. Bordes separan cuando la proximidad no basta; sombras solo para elevación real.
4. El acento azul ocupa menos del 10% de la vista y significa acción, selección o información.
5. Verde, naranja y rojo se reservan a estados semánticos y siempre tienen texto/icono.
6. Radio moderado y consistente; píldoras únicamente para chips y badges.
7. Iconos lineales uniformes, 1.75–2 px, nunca sustituyen labels críticos.
8. Fotografía solo para reconocer personas autorizadas; sin imágenes decorativas de stock.
9. Ilustración únicamente en onboarding/empty states y debe explicar una acción.

## 4. Ritmo y composición

La página se lee como una hoja de trabajo: encabezado breve, una zona de decisión dominante y apoyo subordinado. El espacio no es vacío ornamental, es separación de tareas. El ancho de lectura queda contenido aunque la pantalla sea grande. Las vistas densas crecen en filas/columnas, no en tarjetas repetidas.

### Firma NEXO

La firma visual es una **línea de situación**: frase principal de NEXO, tiempo/fuente discreta y acción contextual. No es banner ni chat decorativo. En Inicio puede decir “Hoy en Colegio Central: 2 solicitudes requieren respuesta”; al abrir, revela evidencia y próximos pasos.

## 5. Referencias traducidas

| Referencia | Aprender | No copiar |
|---|---|---|
| Apple Health | resumen humano, indicadores legibles, tendencias pequeñas | anillos, saturación o metáforas fitness |
| Linear | jerarquía, velocidad, navegación consistente | densidad para desarrolladores, shortcuts ocultos como requisito |
| Arc | continuidad espacial y panel lateral | gestos o chrome no estándar |
| Raycast | reconocimiento, comandos eficientes para expertos | interfaz keyboard-first para novatos |
| Notion | composición tranquila y bloques claros | lienzo genérico/editorial para tareas estructuradas |
| Stripe | estados, tablas y explicación de sistemas complejos | estética financiera o dashboards métricos |
| Tesla | acción directa y estado de sistema | iconografía críptica o controles sin label |
| iOS Settings | agrupación familiar, labels claros y disclosure | listas interminables o navegación móvil separada |

## 6. Anti-referencias

- ERP con menú profundo, tablas grises y formularios de veinte campos.
- Portal gubernamental con exceso de cajas, texto legal dominante y navegación duplicada.
- Estética escolar infantil, colores primarios o ilustraciones de caricatura.
- Dashboard financiero con KPIs gigantes, gauges y gráficos simultáneos.
- Glassmorphism, fondos borrosos, neón, degradados decorativos o texto degradado.
- SaaS genérico con cuadrícula de cards idénticas e icono en cada una.
- “IA” con brillos, orbes, estrellas o mensajes antropomórficos.

## 7. Tema claro

- Lienzo de alta luminosidad tintado, nunca blanco puro.
- Texto azul carbón, no negro puro.
- Sidebar apenas más frío que contenido.
- Superficies seleccionadas usan tinte de acento muy bajo y borde claro.
- Estados críticos usan superficie tenue y texto fuerte; nunca bloque rojo completo salvo acción SOS confirmada.

## 8. Tema oscuro

- Lienzo azul carbón, no negro.
- Elevación por luminosidad y borde, no sombras negras pesadas.
- Acento y semánticos reducen cromaticidad a alta luminosidad para evitar vibración.
- Fotografías conservan color natural; se puede añadir borde, no overlay decorativo.
- No invertir jerarquías ni introducir más color que en claro.

## 9. Iluminación y entorno

| Escena | Ajuste |
|---|---|
| Pasillo exterior | contraste robusto, targets 52 px, sin información por hover |
| Aula | foco en grupo y acción, brillo del tema del sistema |
| Oficina | densidad cómoda, comparación y teclado disponibles |
| Noche/baja luz | dark, superficies cercanas, rojo contenido |
| Conectividad débil | estado de red persistente, caché fechada y acciones seguras |

## 10. Movimiento visual

El contenido no “entra en escena”. La continuidad usa fundidos y traslación ≤8 px cuando ayuda a entender origen/destino. Un cambio de estado puede interpolar icono y color, pero el texto se actualiza inmediatamente. No hay parallax, stagger de cards, rebote ni animación de números que retrase lectura.

## 11. Premium silencioso

Una superficie aprueba si:

- Se entiende sin iconos decorativos.
- Cada alineación pertenece a una retícula.
- Hay una sola voz cromática dominante.
- Los estados se reconocen sin dramatización.
- El contenido real cabe sin truncamientos frágiles.
- Teclado, touch y lector producen la misma tarea.
- La respuesta percibida es inmediata.
- No puede confundirse con un ERP, un dashboard financiero ni una demo de IA.

## 12. Pruebas de coherencia

1. **Desaturación:** al quitar color, jerarquía y estado siguen comprensibles.
2. **Solo títulos:** la pantalla explica propósito y secuencia.
3. **Blur test:** una zona domina; no compiten cinco cards.
4. **Swap test:** si una pantalla puede pertenecer a cualquier SaaS cambiando logo, falta lenguaje NEXO.
5. **Tema:** captura claro/oscuro conserva orden y contraste.
6. **Contenido extremo:** nombres largos, 200% texto y estados simultáneos no rompen composición.
7. **Rol:** cada vista muestra lo necesario, no toda capacidad disponible.

## 13. Criterios de aceptación

- Paleta restringida y semántica aplicada según [03_DESIGN_SYSTEM.md](./03_DESIGN_SYSTEM.md).
- Cero efectos prohibidos y cero color decorativo.
- Contraste AA documentado en ambos temas.
- Validación visual en iluminación alta y baja con al menos un usuario 60+.
- Toda desviación requiere decisión `DEC-*` y fecha de expiración.
