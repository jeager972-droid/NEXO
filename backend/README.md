# Backend de NEXO

El backend tiene dos mitades, cada una con su documentación exhaustiva:

| Componente | Directorio | Documentación |
|---|---|---|
| **API central** — PHP 8, PostgreSQL/PgBouncer, Redis, workers, Nexus | `api/` | [api/README.md](api/README.md) |
| **Nodo edge** — C++20 embebido, huellas, SQLite local, MQTT, OTA | `edge/` | [edge/README.md](edge/README.md) |

```
nodo edge (colegio) ──AES-256-GCM──► api.php ──► PostgreSQL (RLS·school_id)
       ▲ MQTT/OTA                       └──► Redis (colas, dedup, rate-limit)
       └──────── comandos remotos ◄── workers/ (11 daemons)
```

El edge captura y verifica huellas **localmente** (la biometría no sale del
dispositivo) y sincroniza eventos cifrados; la API procesa, detecta
situaciones y notifica. El subsistema conversacional Nexus vive dentro de
la API (`api/lib/nexus_*.php` + `api/routes/chat.php`) — su documento es
[../docs/nexus/NEXUS.md](../docs/nexus/NEXUS.md).
