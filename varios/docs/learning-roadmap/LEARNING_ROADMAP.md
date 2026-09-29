# NEXO — One-Month Implementation Apprenticeship

A repository-driven learning roadmap. The repository is the classroom; every
concept is learned because a real NEXO file demands it.

---

## 1. Who this is for

You designed and orchestrated NEXO with AI assistance. You already understand
what the system does, why the components exist, and how they relate. That is
*architectural* competence, and it is real — most junior programmers do not
have it.

What you lack is *procedural* competence: writing code unaided, reading
unfamiliar code fluently, tracing execution line by line, predicting behavior,
and debugging without delegating. Those are different skills, acquired
differently — by doing, not by understanding.

This roadmap converts your architectural advantage into implementation fluency.
Every day you will read real NEXO code, explain it, predict it, break it, and
rebuild a piece of it yourself. The code you already understand
*conceptually* becomes the corpus through which you learn *how software is
actually written*.

Two honest statements to hold simultaneously:

- You are **not** starting from zero.
- You still have substantial work ahead.

## 2. What one month can and cannot do

**One-month objective** — operational competence:

- Read any NEXO file and explain what it does, line by line where needed.
- Trace the five core flows (edge ingest, login, dashboard query, chat
  message, worker cycle) without notes.
- Write SQL (joins, aggregation, upserts, transactions) unaided.
- Write PHP endpoints, small classes, and scripts unaided.
- Write React components that fetch and render API data unaided.
- Write and read C++ at the level of `NexoResult.h`, `ConfigManager`, and the
  HAL interfaces; modify edge logic with guidance.
- Debug: reproduce, form a hypothesis, isolate, fix, explain.
- Sit a technical interview: live code, SQL, code-reading, architecture
  explanation — without freezing.

**Not promised in one month** — these remain longer-term objectives:

- Line-by-line mastery of `routes/chat.php` (~3,000 lines) — goal is pipeline
  literacy, not total recall.
- PL/pgSQL authoring (`fn_evaluate_student_risk`, audit chain) — read and
  explain, not yet write from scratch.
- The OTA state machine, MQTT internals, the PWA service worker, real-hardware
  drivers — understand the design, defer deep implementation.
- Professional-level mastery of any single stack. That takes years of reps;
  this month builds the engine that makes those reps productive.

## 3. The rules of the apprenticeship

### 3.1 The central learning loop

For every significant topic, run this cycle — in this order:

```
Read → Explain → Predict → Run → Modify → Break → Debug → Rebuild → Transfer
```

- **Read** the real code. **Explain** it aloud or in writing, no notes.
- **Predict** its output/behavior before running. **Run** and compare.
- **Modify** one thing; predict the diff in behavior first.
- **Break** it deliberately (wrong index, wrong condition, wrong type).
- **Debug** it: observe the symptom, form a hypothesis, inspect evidence
  (logs, prints, `var_dump`, `EXPLAIN`, debugger), fix, state the root cause.
- **Rebuild** a simplified version from scratch, from memory.
- **Transfer**: solve the same class of problem on a *different* dataset or
  domain (NEXO teaches the JOIN; a library database proves you own it).

"I read it and understood it" never counts as mastery. Only produced work
counts.

### 3.2 AI discipline — the learner, not the AI, writes the code

You historically orchestrated AI to produce NEXO. That skill stays; but this
month, AI is a *tutor and reviewer*, never the author of your exercise code.

| Allowed | Not allowed during exercises |
|---|---|
| "Explain what `hash_equals` does and why" | "Write this function for me" |
| "Give me a hint, not the solution" | Pasting AI output you can't trace |
| "My attempt fails with X — why?" (after 20+ min own effort) | Asking for the fix before attempting |
| "Review my code for bugs" | Letting AI write the capstone core |
| "Quiz me on this file" | Skipping prediction/runs because "it's obvious" |

Concrete protocol: **attempt → get stuck ≥20 min → ask for a hint → attempt
again → compare your solution against reality → explain the difference**.

### 3.3 Evidence standard

Each day ends with an artifact in a learning journal (`notes/` outside the
repo, or a private folder): a written trace, a query file, a diff, a test, a
recorded explanation. A day with no artifact didn't happen.

### 3.4 Environment

All tools exist locally: PHP 8.5 CLI, `psql` 18, `sqlite3`, Node/npm, CMake,
g++ 16, Python 3. Work locally per `varios/docs/AGENTS.md`: local tests only,
never read `.env` values, small commits, no push. If a local PostgreSQL with
seeded data is available, exercise against it (`psql "$DATABASE_URL"`,
`sql/seed.sql`). If not, do SQL drills in SQLite — flagging dialect
differences (no `ILIKE`, no `FILTER`, `RETURNING` exists in modern SQLite,
timestamps differ) — and treat `sql/schema.sql` + `test/sql/` as the
PostgreSQL reading corpus. The `test/e2e` Docker stack is **reading
material** this month (it documents the integration contract); run it only
if you deliberately choose to, since local-only was the chosen constraint.

Verification commands that work locally, from the repo root:

```bash
php test/dsm_units.php
php test/nexus_capability_eval_v1.php
php test/real_conversation_v1.php
php test/readonly_guard.php
php test/resilience.php
backend/api/vendor/bin/phpunit --configuration test/phpunit.xml \
    --testsuite 'API Unit Tests' --do-not-cache-result
cd frontend/pwa && npm test
```

Do **not** run `test/continuity_50.php` (needs a real API+DB and writes
history).

## 4. Technology map — what is actually in this repo

| Layer | Real technology | Where |
|---|---|---|
| Backend | PHP 8.2+, no framework; front controller + included route files | `backend/api/api.php`, `backend/api/routes/` |
| DB access | PDO, prepared statements, emulated prepares (PgBouncer) | `backend/api/core/db.php` |
| Config | Environment variables, fail-closed boot check | `backend/api/core/boot_check.php` |
| Cache/queues | Redis (phpredis), queues, blocklist, locks, heartbeats, circuit breaker | `backend/api/core/redis.php`, `workers/` |
| Auth | JWT RS256/HS256 (hand-rolled), refresh tokens, HttpOnly cookies, 2FA via WhatsApp | `routes/_auth_middleware.php`, `routes/auth.php` |
| Authorization | RBAC roles + permissions + Postgres **RLS** by `school_id` | `_auth_middleware.php`, `sql/schema.sql` ~line 2390+ |
| Async | PHP CLI workers: reliable-queue pattern (LMOVE Lua), DLQ, heartbeats, cron or daemon | `workers/worker_biometric.php`, `worker_permission_status.php` |
| DB | PostgreSQL 15+: ~68 tables, partitions by month, 23 functions, 5 triggers, RLS on 53 tables, `America/Bogota` | `sql/schema.sql`, `sql/seed.sql`, `sql/factory_reset.sql` |
| Edge | C++20, CMake, SQLite, OpenSSL (AES-256-GCM), libcurl, MQTT, HAL stubs, Catch2 | `backend/edge/` |
| Frontend | React 18, Vite 5, React Router 6, axios, Tailwind 3, Framer Motion, vite-plugin-pwa, Vitest | `frontend/pwa/` |
| AI layer | "Nexus": LLM parser and composer (Groq/etc.) + deterministic PHP NLU/SCP/planner/executors, fixtures | `backend/api/nexus/`, `routes/chat.php` |
| External | Twilio WhatsApp, MQTT broker, NTP | `lib/twilio.php`, `mqtt/` |
| Tests | PHPUnit 11, custom PHP harnesses, Vitest+Testing Library, Catch2, SQL tests, E2E docker stack | `test/` |
| Deploy | Docker, Nginx+PHP-FPM, PgBouncer, Render/Vercel, GitHub Actions, systemd (edge) | `varios/docs/DEPLOYMENT.md`, `backend/api/infra/` |
| History | evolution docs: ECB→GCM, MySQL→PG, monolith→routes, TF-IDF→LLM | `varios/NEXO-HISTORY/` |

Note the pedagogical goldmine: **procedural PHP, OOP PHP, JS/React, C++,
and two SQL dialects in one repo**, plus tests that act as executable
specifications.

## 5. Knowledge dependency graph (derived from the code)

```
CLI + git + env vars
      │
Programming core (shared): values, types, strings, arrays/maps,
control flow, functions, errors/exceptions, modules, I/O, JSON
      │
      ├────────────── PHP procedural (routes, workers, Nexus)
      │                    │
      │              HTTP request/response ── REST/API ── auth middleware
      │                    │                        (JWT, RBAC, CSRF header)
      │                    │
      ├────────────── SQL core: tables, SELECT, WHERE, JOIN, GROUP BY,
      │                    INSERT/UPDATE, constraints, transactions
      │                    │
      │              Postgres advanced: indexes, EXPLAIN, partitions,
      │                    functions/triggers, RLS + set_config, ON CONFLICT
      │                    │
      │              workers/queues/Redis ── resilience/fallbacks
      │
      ├────────────── JS core → async (promises/fetch/axios)
      │                    │
      │              React: components, props, state, effects, context,
      │                    router → full-stack trace
      │
      ├────────────── C++ core: types, headers, compile/link, classes,
      │                    RAII, references/pointers, std containers,
      │                    optional/templates lite, interfaces
      │                    │
      │              Edge: HAL, SQLite embed, crypto, workers/threads,
      │                    main() orchestration
      │
      └────────────── Security thread (cross-cutting): hashing, HMAC,
                           AES-GCM, JWT, RLS, injection, rate limits,
                           secret handling, threat modeling

Cross-capstone: trace end-to-end flows (sensor→PG→worker→WhatsApp→PWA)
Nexus literacy (needs: PHP + SQL + HTTP + RBAC + JSON + prompts)
```

Why this order: PHP procedural is the gentlest entry (the repo's own style),
SQL unlocks reading every route, HTTP unlocks the frontend, and C++ comes
last because it adds memory/compile concerns you don't want first week.

## 6. Concept priority tiers

- **Tier A — essential**: PHP syntax/functions/arrays; PDO prepared
  statements; SQL SELECT/JOIN/GROUP BY/INSERT/UPDATE/transactions; HTTP +
  JSON; env config; reading `requireAuth`; JS core + async; React
  state/props/context; git diff/log; debugging loop.
- **Tier B — important**: JWT internals, RBAC+RLS, Redis queue/lock patterns,
  worker loop, exceptions, PHP OOP (`RiskScoreEngine`), C++ classes/RAII/
  interfaces/`NexoResult`, testing (PHPUnit, Vitest, Catch2), regex, Nexus
  pipeline literacy, CORS/CSRF, rate limiting, hashing/HMAC/AES-GCM concepts.
- **Tier C — supporting**: PgBouncer mechanics, partitions, triggers,
  PL/pgSQL reading, SSE, MQTT concepts, PWA/service worker awareness,
  Tailwind/design tokens, Framer Motion, base64url, semver, fixture systems.
- **Tier D — deferred**: writing PL/pgSQL functions, OTA manager internals,
  real-sensor drivers (libzkfp, gpiod, I²C), `chat.php` exhaustively,
  service worker internals, E2E stack execution, advanced concurrency.

## 7. The 30-day plan

Format per day: **READ** (exact NEXO material) · **LEARN** (concepts needed
to decode it) · **DO** (active work) · **PROVE** (the day's artifact/gate).
Budget ~3–4 focused hours; if a day overflows, carry the DO/PROVE, not the
reading.

---

### Week 0+1 — Foundations, PHP basics, SQL (Days 1–7)

**Day 1 — Orientation: how the machine is wired.**
- READ: root `README.md`; `varios/docs/AGENTS.md` (repo rules); skim
  `backend/api/README.md` §architecture; `git log --oneline -30`.
- LEARN: what a monorepo is; entry points; the idea "code is read far more
  than written"; how `git log/diff/show` reveal history.
- DO: draw the full data-flow (fingerprint→WhatsApp) from memory; then open
  `api.php` header comment and correct your diagram. `git show` one Nexus
  refactor commit (`1b36423`) and describe what changed and why.
- PROVE: written diagram + one-paragraph explanation of each top-level dir.

**Day 2 — PHP core: values, strings, functions.**
- READ: `backend/api/lib/ota.php` (all 43 lines); `lib/twilio.php`
  `normalizeWhatsAppPhone` (~line 45).
- LEARN: variables, typed params/returns, `preg_replace`, `array_map`,
  `explode`, `??`, string interpolation, `hash_hmac`, `hash_equals`.
- DO: `php -a` or scripts — reimplement `otaVersionCompare` from memory; write
  `normalizePhone` for a different format; predict `otaVersionCompare("1.2",
  "1.10.0")` before running.
- PROVE: 5 small PHP functions written solo, each with 3 asserts.

**Day 3 — PHP arrays, assoc arrays, control flow.**
- READ: `lib/calculator.php` lines 9–72 (dispatch table, `match`,
  arrow fn `fn`, while-loop GCD). Skip the parser for now.
- LEARN: assoc arrays, `switch`/`match`, closures/`fn`, `call_user_func`,
  list destructuring `[$a,$b]=[...]`.
- DO: write `scoreToLevel`-style classifier on a different domain (e.g.,
  temperature→label); write a mini operation-dispatch `switch` for a
  calculator with 4 ops.
- PROVE: predict output of `nxCalc(['function'=>'percent','a'=>10,'b'=>250])`;
  write own dispatch + tests.

**Day 4 — SQL I: tables, SELECT, WHERE, ORDER, LIMIT.**
- READ: `sql/README.md` schema section; `schema.sql` `users` (L176–202),
  `students` (L242–281), `schools`, `roles`, `permissions`.
- LEARN: table = relation; PK/FK; `SELECT/WHERE/ORDER BY/LIMIT`; NULLs;
  `ILIKE`; `::date` casts; `NOW()`; UUIDs.
- DO: 12 SELECT queries vs seeded DB (or paper + SQLite mock): students of a
  school; inactive users; students w/o consent; events today (Bogotá); etc.
- PROVE: write queries unaided + explain what `uq_students_school_document`
  enforces.

**Day 5 — SQL II: JOINs and aggregation.**
- READ: `routes/groups.php` (all ~103 lines — the teacher/coordinator
  branches); join `student_group_assignments`, `teacher_group_access`,
  `academic_groups`.
- LEARN: INNER/LEFT JOIN; `COUNT`, `FILTER (WHERE …)`; `GROUP BY`;
  `::INT` cast for ordering.
- DO: write 8 join queries (students+groups; guardians+students; teachers+
  groups). Independent exercise: build `library(books,authors,loans)` in
  SQLite; write author→books→loans joins.
- PROVE: explain why coordinator branch counts with `FILTER`; transfer test:
  "per-author loan count last month" unaided.

**Day 6 — SQL III: INSERT/UPDATE/DELETE, constraints, transactions, upsert.**
- READ: `lib/attendance_reconcile.php` (72 lines — UPDATE…RETURNING + INSERT);
  `api.php` L463–497 (fingerprint + `ON CONFLICT DO NOTHING`); schema FKs.
- LEARN: `INSERT … SELECT`, `ON CONFLICT`, `RETURNING`, `BEGIN/COMMIT/
  ROLLBACK`, why dedup needs a fingerprint.
- DO: write an upsert against a scratch table; simulate duplicate ingest —
  observe conflict no-op; write the reconcile UPDATE yourself.
- PROVE: explain exactly why `event_fingerprint` exists and what breaks
  without it.

**Day 7 — SQL IV + GATE 1: indexes, EXPLAIN, partitions, isolation.**
- READ: `schema.sql` partition on `biometric_events` (L534–551),
  `idx_late_arrival_per_day` (L1308–1310), `fn_bogota_date` (L1303); RLS
  policy block for `users` (L2456–2464) just to see the shape.
- LEARN: indexes as ordered structures; `EXPLAIN`; why `LIKE '%x%'` can't
  use a b-tree; partition pruning; what RLS does conceptually.
- DO: `EXPLAIN` 3 of your Day 4–5 queries; add an index, re-explain.
- **GATE 1 (no AI):** design a 3-table schema for a clinic (patients, visits,
  notes) w/ PKs+FKs; write 6 queries incl. a JOIN+GROUP BY; debug a broken
  query (column ambiguity); explain one NEXO query aloud; explain at a high
  level why `set_config('app.current_school_id',…,true)` matters (details
  Day 12).

---

### Week 2 — HTTP, PHP backend, auth, workers, security (Days 8–14)

**Day 8 — HTTP + the front controller.**
- READ: `api.php` L1–120 (headers, CORS include, boot check, `$input`
  parsing, `$cleanPath`); skim the route `require` list and `/health` block
  (L562–654).
- LEARN: request anatomy (method, path, headers, body), status codes,
  `json_decode`/`json_encode`, `php://input`, why a front controller +
  `cleanPath` exists; `exit` after `echo json_encode`.
- DO: **outside NEXO**, `php -S localhost:8080` a tiny `index.php` front
  controller: `GET /ping`→JSON, `POST /echo`→echoes JSON body, 404 default.
  Add a security header. Break it: send malformed JSON, observe/handle.
- PROVE: trace `GET /groups` through `api.php` on paper, ending at the
  `require` + handler.

**Day 9 — PDO and data safety.**
- READ: `core/db.php` (all); `groups.php` again focusing on
  `prepare/execute/fetchAll`; the CTE in `requireAuth` (L628–640).
- LEARN: PDO, `?` and `:name` placeholders, `PDO::FETCH_ASSOC`,
  `ATTR_EMULATE_PREPARES` (why PgBouncer needs it), `PDOException`,
  `statement_timeout`.
- DO: standalone PHP CLI script connecting to your scratch DB; run queries
  with bound params; deliberately string-interpolate a value → see injection
  risk → fix with placeholders.
- PROVE: write the "students of a group" query from PHP; explain why
  `{$uid}` interpolation in `chatScope` (chat.php L42–48) is *borderline*
  and what makes it safe there (`$conn->quote`).

**Day 10 — Errors, exceptions, fail-closed.**
- READ: `core/boot_check.php` (all); `core/redis.php` (singleton + circuit
  breaker, L141–234).
- LEARN: `try/catch/Throwable`, `exit(json_encode)`, error arrays collected
  then 503, `static` local state (singleton), `filemtime` circuit breaker.
- DO: write a `checkConfig()` validator for a fictional app requiring 3 env
  vars + one conditional pair; make it fail-closed; induce failures.
- PROVE: explain why boot check exists; write your validator unaided; show
  503 JSON when misconfigured.

**Day 11 — JWT mechanics.**
- READ: `_auth_middleware.php` L125–365: `b64url_*`, `loadPemFromEnv`,
  `issueJwtToken`, `verifyJwtToken`.
- LEARN: JWT structure header.payload.signature; HS256 vs RS256; claims
  `iss/aud/iat/nbf/exp/sub/jti`; `hash_hmac` vs `openssl_sign`; constant-time
  compare (`hash_equals`); Base64URL vs Base64.
- DO: decode a JWT by hand in PHP (`explode('.')`, `b64url_decode`,
  `json_decode`); then **write a minimal HS256 issue+verify** pair (~30
  lines) outside the repo.
- PROVE: explain each verification check and which attack it stops;
  reimplement verify unaided; predict what happens with `alg: none` (blocked
  by whitelist at L325).

**Day 12 — RBAC, RLS, sessions.**
- READ: `_auth_middleware.php` L380–750: `isJwtRevoked`, `revokeJwt`,
  `isSchoolInPanicMode`, `checkJwtAndPanicState` (MGET), `requireAuth`,
  `requireSchoolOnboarding`; `routes/auth.php` login flow L109–300
  (throttle, `password_verify`, `password_needs_rehash`, 2FA branch,
  cookies, refresh token in `user_sessions`).
- LEARN: `set_config(...,true)` transaction-local + why PgBouncer forces it;
  blocklist via `jti`; `X-Requested-With` CSRF check; static cache; SHA-256
  hashed refresh tokens.
- DO: trace `/auth/login` on paper through every exit path (400/429/401/202/
  200). Then write a flowchart. Deliberate break: remove the
  `X-Requested-With` check → who can now POST? Articulate the threat.
- PROVE: from memory, list the 6+ checks in `requireAuth` in order, and what
  response each failure produces.

**Day 13 — Workers, queues, resilience.**
- READ: `workers/worker_permission_status.php` (all 282 — the cleanest
  worker); `workers/worker_biometric.php` header docs + `processJob` region;
  `core/redis.php` if needed.
- LEARN: daemon vs cron mode; `pcntl_signal`/SIGTERM; `while(!$shutdown)`;
  Redis `SET NX EX` locks; heartbeats; reliable-queue pattern
  (ingest→processing→ack→DLQ); per-iteration reconnect.
- DO: write a small PHP CLI "worker" that polls a JSON-lines file or Redis
  list, processes items, writes a heartbeat, handles SIGTERM. Break it:
  kill mid-job → design the recovery.
- PROVE: explain `queue:biometric_ingest → processing → dlq` and why
  `SYNC_ATTENDANCE` also writes PG inline (api.php L462–497).

**Day 14 — Security consolidation + GATE 2.**
- READ: `varios/docs/SECURITY.md`; revisit `encryption.cpp` L1–60 concept
  (AES-256-GCM IV+tag); `fn_calculate_audit_hash` schema L1353–1379.
- LEARN: threat→mechanism mapping: bcrypt cost 12, GCM vs ECB, HMAC audit
  chain, nonces, RLS, rate limits, secrets in env, `securityLog`; what is
  NOT a defense (the ITP localStorage token workaround — read its TODO at
  client.js L43–48 and auth.php L265–268 — and explain why it's a
  concession).
- DO: build a table threat→mechanism→file:line for 8 mechanisms; write a
  deliberately broken "auth" (plain strcmp on hash) and exploit it.
- **GATE 2 (no AI):** trace `POST /auth/login` fully; write a PHP endpoint
  (mini-router from Day 8) with input validation, prepared INSERT, JSON
  error contract; explain RLS+RLS context in 3 sentences; debug a route with
  an injected bug you didn't write (have AI plant it, you find it).

---

### Week 3 — JavaScript, React, full-stack, Nexus (Days 15–21)

**Day 15 — JS core.**
- READ: `frontend/pwa/src/api/auth.js` (36 lines), `utils/jwt.js`,
  `config/roles.js`.
- LEARN: `const/let`, arrow fns, template literals, destructuring,
  `async` fn returning promises, modules `import/export`, objects vs PHP
  assoc arrays.
- DO: port `normalizeWhatsAppPhone` and `otaVersionCompare` to JS in Node;
  5 asserts each.
- PROVE: same functions, both languages, from memory.

**Day 16 — Async JS and the API client.**
- READ: `api/client.js` (all 177): axios instance, interceptors, refresh
  queue, telemetry events, ITP fallback.
- LEARN: promises, `async/await`, `try/catch` on await, `Promise.reject`,
  event dispatch (`CustomEvent`), `localStorage` vs HttpOnly cookies
  (security contrast with Day 12).
- DO: write your own `apiClient` wrapper: base URL, timeout, error→message
  mapping, one retry on 401. Test against `php -S` mini-API from Day 8/14.
- PROVE: explain the refresh-subscriber queue (L81–88) and why it prevents
  stampedes.

**Day 17 — React I: components, props, state.**
- READ: `components/ui/Button.jsx` (all), `ui/Input.jsx`, `ui/Badge.jsx`,
  `patterns/StatCard.jsx`, `patterns/SituationLine.jsx`.
- LEARN: JSX, props, `forwardRef`, `clsx`, `useState`, controlled inputs,
  `disabled/aria-busy`, variant maps.
- DO: in the PWA dev environment (`npm run dev`), create a scratch page or
  modify Button (add a `warning` variant); write a `MemberCard` component
  for the capstone domain; run `npm test` once to see Vitest.
- PROVE: explain why `loading` swaps the icon to `Loader2`; predict render
  output for given props.

**Day 18 — React II: effects, context, routing.**
- READ: `main.jsx` (providers, SW registration), `App.jsx` head (lazy
  routes), `routes/ProtectedRoute.jsx` (27 lines), `context/AuthContext.jsx`
  (all 225).
- LEARN: `useEffect` + cleanup, `useCallback`, `useRef`, context
  provider/consumer, `lazy/Suspense`, router guards, event listeners.
- DO: trace login: `Login.jsx handleSubmit → authApi.login → client
  interceptor → auth.php → 200 → setUser → ProtectedRoute`. Write a minimal
  context (e.g., `SessionContext`) in a scratch Vite app.
- PROVE: explain `hasInitRef` guard (L90–92) and the 5-min `verifyToken`
  interval (L173–177).

**Day 19 — Full-stack trace + fetch exercise.**
- READ: `pages/Dashboard.jsx` data flow: `dashboardApi` call → `EMPTY_STATS`
  → loading skeleton → render; `api/dashboard.js`; `pages/Devices.jsx`
  polling if present.
- LEARN: the request lifecycle end-to-end; loading/error/empty states;
  React-keys in lists.
- DO: **trace Day 19's core artifact:** pick `GET /dashboard` or
  `/attendance/today` — write the full chain click→JSX→axios→api.php→
  middleware→route→SQL→JSON→state→DOM. Then build a tiny page that GETs
  your mini-API and renders a list with loading + error + empty states.
- PROVE: the written end-to-end trace (this is the week's central artifact);
  working fetch page.

**Day 20 — Nexus I: deterministic NLU.**
- READ: `backend/api/nexus/README.md` (pipeline overview);
  `nexus/nexus_nlu.php`: `nxNorm` (L34), `nxClassifyCore` fixture path
  (L109–133), `nxClassify` multi-intent + threshold (L135–191), `nxSlots`
  dates section (L196–230), `nxIntentRoles`+`nxAllowed` (L789–900).
- LEARN: normalization; intent vs entity vs slot; confidence + abstention;
  fixture-replay (`NX_CLASSIFY_FIXTURE`); RBAC-by-intent; deterministic vs
  probabilistic layers.
- DO: run `php test/dsm_units.php` and `php test/real_conversation_v1.php`;
  write a micro intent-classifier in PHP: 4 intents, regex/keyword rules,
  confidence, `out_of_scope` fallback.
- PROVE: explain why low-confidence becomes `out_of_scope` (L186–189); your
  classifier routes 10 test phrases correctly.

**Day 21 — Nexus II: LLM boundary + GATE 3.**
- READ: `nexus_llm.php` `nxLlmSystemPrompt` + `nxLlmClassify` (L176–327);
  `chat.php` helpers `chatScope`/`chatResolveStudent`/`chatResolveGroup`
  (L38–103) + skim the intent dispatch table; `nexus_semantic.php`
  `nxPlanExecute` skeleton (L1228+); `test/fixtures/llm_intents.json` (a few
  entries).
- LEARN: the trust boundary — LLM proposes `{intent, entities}`; PHP decides
  truth; executors are read-only; mutations become navigation chips; `_ds`
  server-side dialogue state; audit on every message.
- DO: extend your Day-20 classifier with an executor layer: intent→prepared
  SQL→JSON card. Add a "mutation → action chip" case.
- **GATE 3 (no AI):** explain the Nexus pipeline end-to-end in writing;
  implement mini full-stack: one PHP GET endpoint + one React list page
  hitting it; 2 SQL queries incl. a JOIN; find a planted bug in a PHP route.

---

### Week 4 — C++ edge, integration, capstone, interview (Days 22–30)

**Day 22 — C++ I: syntax, compilation.**
- READ: `backend/edge/README.md` (build/architecture); `CMakeLists.txt`
  L1–80; `include/utils/NexoResult.h` (all 79).
- LEARN: compilation units, headers/`#pragma once`, `g++/cmake`,
  `enum class`, `std::optional`, `template<typename T>`, static factory
  methods, `explicit operator bool`.
- DO: `cmake --preset dev-x86` + `cmake --build` + `ctest` (build the stub
  target); write a standalone C++ program: read stdin, parse `name=value`
  pairs, print JSON — compile with `g++ -std=c++20`.
- PROVE: explain why `NexoResult<void>` needs specialization; write
  `NexoResult<int> divide(int a,int b)` that fails on 0.

**Day 23 — C++ II: classes, interfaces, RAII.**
- READ: `include/utils/ConfigManager.h` (singleton, mutex), `include/hal/
  IBiometricSensor.h` (interface), `src/hardware/dev_stub/
  DevStubBiometricSensor.cpp` (implementation), `include/base_de_datos/
  sqlite_manager.h` (skim the API surface).
- LEARN: class vs object; ctor/dtor; virtual + `=0` + `override`;
  polymorphism via base pointer; singleton `getInstance()`; `std::mutex`;
  `std::vector<uint8_t>`; why HAL stubs make `main.cpp` testable on x86.
- DO: define `class IStore` interface + `FileStore`/`MemStore` impls; write
  a test main exercising both through the interface; read one Catch2 test
  in `backend/edge/tests/` and mimic its structure for your class.
- PROVE: explain dependency injection via constructor-arg interfaces; your
  two impls pass the same test.

**Day 24 — C++ III: the edge machine.**
- READ: `src/main.cpp` header doc + `signalHandler` (L99–104), globals
  (L91–93), `checkSystemClock`/`runSecurityProvisioning` skim,
  `handleBiometricMatch` (L917+), `main()` structure (L1201+); skim
  `src/base_de_datos/encryption.cpp` encrypt/decrypt section and
  `sqlite_manager.cpp` `saveEstudiante` (L226+).
- LEARN: `std::atomic`, signal safety, threads (awareness level), SQLite C
  API wrapped in a class, "persistence before access" rule, encrypted
  fields + keyed doc hashes (`encField/decField/docKey`), IV-per-message.
- DO: written trace: finger on sensor → `searchUser` → `handleBiometricMatch`
  → `audit_trail` → SQLite → `SyncWorker` → HTTPS POST. Then break-and-fix:
  read a stub, predict behavior, flip a condition, observe test failure.
- PROVE: explain why an invalid clock blocks reads and why SQLite failure
  blocks access; explain `docKey` vs `encField` (lookup vs confidentiality).

**Day 25 — Integration day + GATE 4.**
- READ: reconnect everything: edge `handleBiometricMatch` → `cloud_manager`
  → `api.php` encrypted-payload path (L274–555: base64 → AES-256-GCM
  decrypt → device lookup → nonce check → queue/PG fallback) →
  `biometric_events` → `worker_biometric` →
  `attendance_incidents` → `notifications` → `worker_twilio` → guardian.
- DO: draw the full sequence with file:line anchors; identify every failure
  mode and its fallback (Redis down → direct PG; MQTT down → HTTP poll;
  PG down → edge WAL retains).
- **GATE 4 (no AI):** whiteboard the flow naming the actual files; write the
  SQL the worker effectively runs; answer "what happens when X fails" for
  5 X's (Redis, MQTT, NTP, DB, disk).

**Days 26–28 — Capstone: "PULSO" (mini NEXO-shaped system).**

See §8 for the full spec. Three build days, AI as reviewer only.

**Day 29 — Debugging gauntlet + code-reading drills.**
- Morning: 4 planted-bug exercises (PHP route, SQL query, React component,
  C++ function). For each: reproduce → symptom → hypothesis → evidence →
  fix → root-cause sentence.
- Afternoon: code-reading ladder drill — 20 min each explaining a file at
  levels 1–5 (see §9) with the book closed after first read.
- PROVE: 4 root-cause write-ups + 5 recorded explanations.

**Day 30 — Mock interview + matrix review.**
- Run the interview simulation in §10, timed, blank editor.
- Score yourself against the competency matrix (§11) — evidence only, no
  self-rating points.
- Write the "next 90 days" list: what the matrix shows still weak.

---

## 8. Capstone specification — "PULSO"

A deliberately *NEXO-shaped* but different system: access control for a
fictional **gym**. You build it in `~/proyectos/pulso/` (outside this repo;
nothing merges into NEXO). It must exercise the same competence classes:
relational data, backend API, async-ish worker-ish piece, minimal frontend,
security reasoning, tests.

**Functional spec (small but real):**

- `members(member_id, school-equivalent gym_id, document_number, name, active)`
- `entry_events(event_id, member_id, event_type[IN|OUT], occurred_at,
  fingerprint)` — dedup on `(member_id, event_type, occurred_at)`.
- `incidents(incident_id, member_id, kind, detected_at, metadata_json)`
- Endpoints (PHP, `php -S`, no framework):
  - `POST /api/ingest` — accepts a batch of events from the "edge sim";
    requires a static bearer token; writes dedup-safe rows.
  - `GET /api/members/{id}/status` — inside/outside + today's entries.
  - `GET /api/summary/today` — counts by hour + list of entries.
- Edge simulator: a CLI script (PHP or Node) that reads a JSONL file of
  events, POSTs them in batches, retries on failure, keeps a local
  `sent.log` (mirrors `audit_trail` → `SyncWorker`).
- Detector: a periodic script (cron-run, like `worker_permission_status`)
  flagging `OPEN_SESSION` incidents: member IN with no OUT after 4h.
- Frontend: one HTML+vanilla-JS page (or React if comfortable): a fetch to
  `/api/summary/today` rendering a table + a manual-entry form POSTing to
  `/api/ingest`. Loading + error + empty states.
- Tests: ≥3 unit tests (PHPUnit or plain `assert` script) covering dedup,
  status logic, detector firing; a `TESTING.md` describing manual checks.

**Constraints that teach:**

- SQL written by you (SQLite or PG). Prepared statements only.
- No AI-generated code for the core. AI may: explain errors, review diffs,
  suggest test cases. Log every AI interaction in `AI_LOG.md`.
- Written `TRACE.md`: one full request's journey, your own words.
- `THREATS.md`: 5 threats (replay, injection, spoofed member, token leak,
  duplicate event) → your mitigation or honest "not handled".

**Why this spec:** it is `SYNC_ATTENDANCE`'s shape — ingest → dedup → store
→ detect → query → UI — without biometric/legal complexity. Prove the
pattern transferred, not that you memorized NEXO.

**Pass criteria:** demo works end-to-end; you explain every line; tests pass;
a planted bug (have AI inject one on Day 28 evening) is found and fixed.

## 9. Code-reading & reimplementation ladders (NEXO-mapped)

**Reading ladder:**

| Lvl | Scope | NEXO example |
|---|---|---|
| 1 | one function | `normalizeWhatsAppPhone` (twilio.php:45), `otaVersionCompare` (ota.php:15) |
| 2 | related functions | `nxOk`/`nxErr`/`nxGcd`/`nxLcm` (calculator.php:68–72); `computeScore`/`scoreToLevel`/`shouldAlert` (RiskScoreEngine.php:79–114) |
| 3 | one module | `routes/groups.php` whole file; `worker_permission_status.php` |
| 4 | one operation | `POST /auth/login` (auth.php:109–300); `SYNC_ATTENDANCE` inline path (api.php:462–497) |
| 5 | module interaction | `requireAuth` → `verifyJwtToken` → `checkJwtAndPanicState` → `set_config` → route query |
| 6 | one subsystem | Nexus pipeline (`nxClassify`→SCP→plan→executor→present); biometric worker reliable-queue |
| 7 | end-to-end flow | sensor→edge→ingest→PG→worker→Twilio→PWA |
| 8 | architecture ↔ code | why Redis is optional; why RLS+PgBouncer force `set_config(...,true)`; why edge persists first |

**Reimplementation ladder:**

| Lvl | Task | NEXO anchor / independent equivalent |
|---|---|---|
| 1 | modify a line | change `WEIGHT_LATE`, rerun `RiskScoreEngineTest` |
| 2 | complete a partial function | fill in `scoreToLevel` thresholds given the signature |
| 3 | small function from scratch | `normalizePhone`, `b64url_decode`, `otaVersionCompare` — all from memory |
| 4 | small module | `checkConfig` validator; file-queue worker skeleton |
| 5 | backend operation | mini-router + validated endpoint (Days 8/14) |
| 6 | DB-backed feature | capstone schema + ingest + status queries |
| 7 | API + UI | capstone frontend page wired to your API |
| 8 | end-to-end mini-system | full PULSO capstone |

## 10. Interview simulation (Day 30, ~2.5 h, blank editor, no AI)

**Architecture (20 min, spoken):** walk the attend flow sensor→WhatsApp;
why edge-local identification; why RLS + app-context `set_config`; why
Redis optional; what happens to chat mutations.

**Code reading (25 min):** explain `requireAuth` (middleware L593–702) and
`nexoReconcileAbsence` cold, line by line; answer "what breaks if we remove
X".

**Live coding (60 min):**
1. PHP: `function classifyScore(float $s): string` + dedup-fingerprint
   generator (15 min). Pass: works + explains edge cases.
2. SQL on a surprise schema (hotel: guests/bookings/rooms): top-3 guests by
   nights last month (15 min). Pass: correct JOIN + GROUP BY unaided.
3. JS/React: fetch a list, render with loading + error states (20 min).
   Pass: runs, states correct.
4. Debug: a PHP route with 2 planted bugs (syntax + logic) (10 min). Pass:
   both found via evidence, not luck.

**Security + Nexus (20 min):** order the auth checks; what `hash_equals`
prevents; what the LLM is *not* allowed to do in Nexus and how the code
enforces it (whitelist intents, `nxAllowed`, read-only executors, chips for
mutations).

**Retro (20 min):** what broke under time pressure → goes into next-90-days
list.

## 11. Competency matrix (evidence, not vibes)

| Competency | Initial state | Target | Observable evidence |
|---|---|---|---|
| SQL | concepts known, can't write fluently | writes joins/aggregation/upserts/transactions unaided | Gate 1 + capstone schema + interview query pass |
| PHP | reads with effort, doesn't write | writes functions, routes, scripts; reads NEXO routes fluently | Days 2–14 artifacts; mini-API; capstone endpoints |
| C++ | weak | reads/modifies edge code; writes small classes/interfaces; compiles | Days 22–24 artifacts; `NexoResult` divide; `IStore` impls |
| JS | limited | writes async code, fetch wrappers, components | ported utils; own client.js equivalent |
| React/frontend | limited | builds a fetching page w/ states; traces auth flow | Day 19 page; capstone UI; traced login chain |
| APIs/HTTP | conceptual | designs + implements + debugs request lifecycles | mini-router, written end-to-end traces |
| Auth/RBAC/RLS | conceptual | explains + implements token verify, role gates, tenant isolation | Gate 2; JWT issue/verify reimplementation |
| Security | aware | maps threat→mechanism→code; spots broken auth | threat table; exploited-then-fixed broken auth |
| Redis/workers | conceptual | explains queue/reliable-pattern/DLQ; writes a worker | Day 13 worker; Day 25 failure analysis |
| Nexus/AI | knows design docs | explains implementation boundary from code; builds toy pipeline | Day 20–21 classifier+executors; Gate 3 write-up |
| Debugging | AI-dependent | reproduces, hypothesizes, isolates, fixes, states root cause | Day 29 write-ups; planted-bug finds |
| Testing | aware | writes unit tests before/after code; reads tests as spec | capstone tests; one test added to a scratch PHP lib |
| Git | basic | reads history, small clean commits, diffs for review | Day 1+ daily commits of exercises |
| Architecture | strong | ties every mechanism to file:line | Day 25 gate; interview architecture segment |

## 12. Daily operating notes

- **Order matters**: if you must compress, protect DO/PROVE over READ.
- **Spaced retrieval**: each morning, 10 min recall of yesterday's artifact
  without looking; weekly, re-derive one thing from the prior week (write
  `otaVersionCompare` again on Day 23, re-explain `requireAuth` on Day 21).
- **Debugging posture**: reproduce first, always; one hypothesis at a time;
  write the root cause in one sentence before moving on.
- **Journal**: `notes/dayNN.md` — what I predicted vs. what happened, plus
  the day's artifact links.
- **When stuck**: 20 min own effort → read the error literally → isolate
  (comment out, `var_dump`, `EXPLAIN`, `console.log`, `LOG_*`) → then, and
  only then, ask AI for a *hint*.
- **Don't protect the code from yourself**: the repo is yours to break in a
  branch. `git checkout -b learn/dayNN`, break things, `git diff` to see
  exactly what you changed, discard freely.

## 13. Deliberately deferred (say it out loud)

Reading `chat.php` end-to-end; writing PL/pgSQL (`fn_evaluate_student_risk`,
audit chain) — read-only this month; OTA state machine internals;
`Zk9500`/real-GPIO drivers; MQTT worker internals; service worker/Workbox;
the Docker E2E stack (read `test/e2e/README.md` for the contract, run only
if you choose); advanced concurrency (you need to *recognize*
`std::atomic`/locks, not master them); deployment automation. Marking these
deferred is not failure — it is what makes the month honest.
