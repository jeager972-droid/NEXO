# NEXUS — Arquitectura real encontrada (auditoría de código)

> Verificado contra el código, no contra los informes. Rama:
> `nexus-conversational-core` · tag `nlu-baselines-frozen`.

## Flujo real `POST /chat/message` (backend/api/routes/chat.php:248-395)

```
PWA (chat.js: send(text, sessionId, ctx) — ctx viene de sessionStorage del navegador)
  │
  ▼
requireAuth() → {id, school_id, role}
  │
  ▼
Rate limit Redis: chat_rl:{userId} 60 msg/10min (fail-open si Redis cae)
  │
  ▼
Follow-up regex «dame otro|más|siguiente» → chatLastPayload() (chat_messages,
último payload_json del asistente en esta session_id) → chatDispatch directo
  │
  ▼
nxClassify(text):
   1. nxClassifyService() → HTTP POST {NLU_URL}/classify (service.py :8090)
   2. si falla → nxClassifyLocal() → model_php.json (word-level LR)
   3. devuelve {domain, intent, confidence, top3, entities, parts, source}
  │
  ▼
Multi-intent: cls.parts>1 → dispatch por parte (RBAC por parte) → replies unidas
  │
  ▼
HERENCIA DE CONTEXTO (chat.php:327-376):
   ctx = input['ctx'] del NAVEGADOR {last_intent, entities{student,group,
   module,days,from,to,range_label,field}, last_reply}
   · slots faltantes se completan desde ctx.entities → marcados _inherited
   · intent heredable solo si ctx.last_intent ∈ queryIntents (26 intents)
     Y mensaje tiene marcador de seguimiento (y|ahora|las|del|…) Y
     (intent==out_of_scope | conf<0.65)
   · intent_ctx_generic: intents genéricos (day_summary, attendance_today…)
     con marcador + sin verbo de acción + ≤8 palabras → reutiliza intent previo
  │
  ▼
RBAC: chatAllowed() = nxAllowed(intent,role) [matriz estática]
      ∧ chatPolicyEnabled(school, NX_CHAT_POLICY_MAP[intent]) [política por escuela]
      ∧ chat.smalltalk.enabled
  │
  ▼
chatDispatch() → smalltalk | chat_{intent}() → SQL real (attendance_incidents,
students, user_commands, notifications…) con chatScope() = WHERE school_id
  │
  ▼
start_operation → chatOperationCmd(q) → match por \b-patrones ordenados
  → responde chip nav → /operacion?cmd=… (el front ejecuta el comando real
  por la UI de operación — NUNCA se ejecuta en el chat)
  │
  ▼
chatLog() → INSERT chat_messages {user,assistant} payload_json
  │
  ▼
JSON {reply, intent, confidence, entities, actions, cards, session_id}
```

## Componentes reales

| Capa | Implementación | Archivo |
|---|---|---|
| Entrada | POST JSON {text≤500, session_id, ctx} | chat.php:248 |
| Normalización | nxNorm (acentos, puntuación) | nexus_nlu.php |
| Router | LR formal/informal + bias 0.3 + guardia informal regex + arbitraje dual | nexus_nlu.php:180-233 / service.py |
| NLU | TF-IDF(word+char union)+LR :8090; fallback model_php.json word-only | nlu/service.py, nexus_nlu.php |
| Slots | nxSlots: module×6, student, group, field, days→from/to | nexus_nlu.php + preprocess.py (dup) |
| Contexto | **sessionStorage del navegador** — NO servidor; follow-ups usan chat_messages.payload_json | PWA+chat.php |
| Operación | chatOperationCmd: match ordenado \b | chat.php:186-207 |
| Autorización | nxAllowed + chatPolicyEnabled + chatCanAction | chat.php:140-173 |
| Ejecución | chat_* handlers SQL con chatScope school_id | chat.php:500+ |
| Operación real | chip nav → /operacion?cmd= (UI, no el chat) | chatActionChip |
| Respuesta | plantillas PHP por handler + nxSmalltalk | chat.php / nexus_nlu.php |
| Persistencia | chat_messages(role,content,payload_json,session_id) | chatLog |
| Runtime dual | :8090 (FeatureUnion word+char) vs PHP (word-only export) — divergencia ~29% inherente | ambos |

## Defectos estructurales confirmados (medidos en Fase 4)

1. **OperationResolver**: `cita` imperativo no matchea (solo infinitivos);
   `confirmo la solicitud` re-dispara 'Mandar solicitud' por substring;
   `una autorización de salida` sin verbo → NULL.
2. **Entity extraction**: student recoge 'camila del', 'maria manana',
   'mismo juan' — stopwords no limpiadas al capturar nombre.
3. **Contexto del lado del cliente**: herencia funciona (83% turnos) pero
   vive en sessionStorage — ningún gate de sesión/institución en el ctx
   (el front puede mandar entities arbitrarias; los handlers las validan
   por scope, pero el contrato no está formalizado).
4. **Sin interpretación estructurada**: chat.php opera sobre arrays
   planos {intent, confidence, entities} — no distingue "lo que dijo el
   usuario" vs "lo inferido" vs "lo que NEXO sabe".
5. **Confusión de responsabilidad**: herencia de slots + herencia de
   intent + marcador + genéricos = 4 reglas dispersas en un solo bloque.
6. **Respuesta**: plantillas por handler — correcta pero rígida; no hay
   response planner ni grounding explícito (los números vienen de SQL,
   bien; el riesgo es en plantillas de listados).
7. **Telemetría**: chatLog guarda payload final; no hay traza por capa
   (router/top3/ctx-delta) en producción — solo en el harness.

## Lo que YA cumple el principio de seguridad

- El modelo nunca ejecuta: solo produce intent+slots; RBAC/scope
  validan; las operaciones reales son chips → UI.
- school_id viene de requireAuth(), nunca del texto ni del ctx.
- security_probe intent existe y loguea (securityLog).
- Rate limit por usuario.
