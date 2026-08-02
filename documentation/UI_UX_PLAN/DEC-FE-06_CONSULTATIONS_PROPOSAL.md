# DEC-FE-06 — Propuesta de Consultas Institucionales

**Estado:** Borrador para validación
**Autoridad:** 05_INFORMATION_ARCHITECTURE.md §6, 06_USER_FLOWS.md §9
**Fecha:** 2026-07-27

---

## 1. Contexto

NEXO no cuenta actualmente con un módulo de Consultas. Los flujos de comunicación
entre padres, docentes y coordinación se resuelven a través de Operations (citar,
solicitud) y Notifications, pero no existe un canal persistente de conversación
bidireccional.

Esta propuesta define la arquitectura de pantalla y los patrones de interacción
para un futuro módulo de Consultas, alineado con el design system existente.

---

## 2. Objetivo

Permitir que acudientes y personal institucional intercambien mensajes
contextualizados a un estudiante o un caso, sin recurrir a WhatsApp ni a canales
externos, manteniendo trazabilidad y privacidad.

---

## 3. Arquitectura de pantalla

### 3.1 Ruta

```
/consultas → SCR-CON-01 Consultations
```

### 3.2 Roles con acceso

| Rol | Acceso |
|-----|--------|
| Rector | Lectura total, intervención |
| Coordinador | Lectura total, gestión |
| Psicorientador | Lectura asignada, intervención |
| Docente | Lectura de su grupo, respuesta |
| Secretaría | Solo lectura administrativa |

### 3.3 Estructura

```
PageHeader
  eyebrow: "Comunicación institucional"
  title:   "Consultas"
  actions: [Button "Nueva consulta" → abre Drawer]

[Input de búsqueda + filtro de estado]

Surface (lista de hilos)
  └─ SituationLine por hilo
       ├─ Avatar / iniciales del remitente
       ├─ Último mensaje (truncado)
       ├─ Badge de estado (abierta / cerrada)
       └─ Timestamp relativo

Drawer (detalle del hilo)
  ├─ NexoMessage × N (historial)
  ├─ Textarea (respuesta)
  └─ Button primary "Enviar" + Button secondary "Cerrar hilo"
```

---

## 4. Patrones de interacción

### 4.1 Lista de hilos

- Usar `SituationLine` como fila accionable.
- `StatusDot` para indicar hilos no leídos.
- `Badge` scheme `info` para abierta, `success` para cerrada.
- Búsqueda instantánea con debounce de 400ms (patrón existente).

### 4.2 Drawer de detalle

- Usar `Drawer` unificado de `Overlay.jsx`, tamaño `md`.
- Historial renderizado con `NexoMessage` (burbuja de solo lectura).
- Respuesta mediante `Textarea` + `Button` primary.
- Cierre de hilo mediante `ConfirmDialog` (proporcional al daño: pérdida de canal).
- Scroll automático al último mensaje.

### 4.3 Nueva consulta

- `Drawer` tamaño `sm`.
- Campos: destinatario (`Select`), asunto (`Input`), mensaje (`Textarea`).
- Validación: destinatario y mensaje obligatorios.
- `OperationResult` tras envío exitoso.

### 4.4 Estados

| Estado | Componente |
|--------|-----------|
| Cargando | `SkeletonRows` |
| Vacío | `EmptyState` variant `empty` |
| Sin permiso | `EmptyState` variant `denied` |
| Error | `EmptyState` variant `error` + `humanizeError` |

---

## 5. Modelo de datos (propuesta)

```json
{
  "thread_id": "uuid",
  "subject": "string",
  "status": "open|closed",
  "student_id": "uuid|null",
  "participants": ["user_id"],
  "last_message": {
    "text": "string",
    "sender_id": "uuid",
    "created_at": "ISO8601"
  },
  "unread_count": 0,
  "created_at": "ISO8601",
  "updated_at": "ISO8601"
}
```

---

## 6. API (propuesta)

| Método | Endpoint | Descripción |
|--------|----------|-------------|
| GET | `/consults` | Lista de hilos |
| GET | `/consults/:id` | Detalle con mensajes |
| POST | `/consults` | Crear hilo |
| POST | `/consults/:id/messages` | Enviar mensaje |
| PATCH | `/consults/:id/close` | Cerrar hilo |

---

## 7. Consideraciones de diseño

- **No introducir nuevos design tokens.** Usar `--nx-accent`, `--nx-surface`,
  `--nx-border` existentes.
- **No crear un componente de chat nuevo.** Reutilizar `NexoMessage` para
  burbujas y `Drawer` para el panel.
- **Accesibilidad:** el historial debe ser navegable por teclado. El `Textarea`
  de respuesta debe tener `aria-label` y foco automático al abrir el Drawer.
- **Privacidad:** los mensajes son visibles solo para participantes autorizados
  según rol. No exponer datos de otros estudiantes.

---

## 8. Dependencias

- Componentes existentes: `PageHeader`, `SituationLine`, `NexoMessage`, `Drawer`,
  `ConfirmDialog`, `Badge`, `EmptyState`, `SkeletonRows`, `OperationResult`.
- Sin nuevos componentes.
- Sin nuevas dependencias npm.

---

## 9. Próximos pasos

1. Validar modelo de datos con backend.
2. Definir permisos granulares por rol.
3. Implementar endpoints en backend.
4. Implementar `Consultations.jsx` siguiendo esta especificación.
5. Registrar en `roles.js` y `App.jsx`.
