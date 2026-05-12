# ⚠️ Espejo NO autoritativo del backend

## Rol intencional de `WebApp/` en esta etapa

`WebApp/` cumple **dos funciones legítimas**:

1. **Frontend autoritativo** — el código React real (`WebApp/src/`, `vite.config.js`, `tailwind.config.js`, `package.json`, `index.html`) vive aquí. Más adelante este frontend se empaquetará como app nativa multiplataforma (Tauri / Capacitor / PWA L3) descargable desde la página de NEXO.
2. **Espejo de lectura del backend** — los `.php` y `routes/` aquí están **solo para que sea fácil leer y entender las rutas, contratos y lógica del backend al integrar el frontend**. No es código activo: es referencia viva. La copia ejecutable es la que está en `Logica de negocio/alojamiento/`.

> Mantener este espejo durante Etapas 0–2 es una **decisión consciente de productividad de integración**, no un descuido. La eliminación física se programa para Etapa 3.3, cuando el repo se reestructure en `nexo-edge/`, `nexo-cloud-api/`, `nexo-client/`.

---

## Archivos espejo (no editar)

Los siguientes archivos dentro de `WebApp/` son **un espejo** del backend real
y **no deben editarse aquí**:

- `WebApp/api.php`
- `WebApp/db.php`
- `WebApp/check_roles.php`
- `WebApp/debug_db.php`
- `WebApp/routes/`
- `WebApp/sql/`
- `WebApp/_dev/`

**Fuente de verdad oficial:** `Logica de negocio/alojamiento/`

A la fecha de la Etapa 0, ambos lados son **idénticos byte-a-byte**
(verificado por SHA-256 — ver `STAGE_0_AUDIT.md` §2).

## Reglas

1. **No edites** ningún PHP dentro de `WebApp/`. Edita en `Logica de negocio/alojamiento/`.
2. Si vas a probar localmente con el backend, levanta el contenedor desde
   `Logica de negocio/alojamiento/docker-compose.yml`.
3. La eliminación física de este espejo se hará en **Etapa 3.3** (Reestructuración Limpia),
   no antes, para no romper despliegues activos sin auditoría previa.

## ¿Por qué no se borra hoy?

- La raíz del proyecto **no tiene git**: hay dos repos anidados con **el mismo remoto**.
  Borrar este espejo en uno de los repos podría reaparecer en el otro tras un pull.
- Un despliegue (Vercel, Railway u otro) podría estar apuntando a `WebApp/api.php`
  sin que esté documentado. Validamos antes de eliminar.

## Estado deseado

En Etapa 3.3 el repositorio se reestructura en tres unidades:

```
nexo-edge/        ← C++/Linux para Raspberry Pi 4
nexo-cloud-api/   ← PHP/Postgres/Redis (lo que hoy vive en alojamiento/)
nexo-client/      ← React/Tauri/Capacitor (lo que hoy vive en WebApp/src/)
```

Y este espejo desaparece.
