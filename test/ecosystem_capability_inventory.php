<?php
/* ============================================================================
 * ecosystem_capability_inventory — §16/§17/§32/§39.
 * Auditoría del grafo real: DB schema + rutas + registry + intents legacy.
 * Produce cobertura trazable a elementos reales — nunca porcentajes libres.
 * Corre dentro del contenedor API (necesita PDO + archivos deployados).
 * ========================================================================== */
$HTML = is_dir('/var/www/html/lib') ? '/var/www/html' : dirname(__DIR__) . '/backend/api';
foreach (['nexus_nlu.php','nexus_semantic.php'] as $lib) {
    foreach ([__DIR__.'/../backend/api/lib/'.$lib, "$HTML/lib/$lib"] as $p)
        if (is_file($p)) { require_once $p; break; }
if (!getenv('NX_CLASSIFY_FIXTURE')) putenv('NX_CLASSIFY_FIXTURE=' . __DIR__ . '/fixtures/llm_intents.json');
}
$pdo = null;
foreach (["$HTML/core/db.php", __DIR__.'/../backend/api/core/db.php', __DIR__.'/../backend/core/db.php'] as $p)
    if (is_file($p)) { require $p; break; }
$conn = $pdo;
$REG = nxCapabilityRegistry();
$GRAPH = nxCapabilityGraph();

/* ── 1. entidades reales del schema ──────────────────────────────────────── */
$tables = $conn->query(
    "SELECT table_name FROM information_schema.tables
      WHERE table_schema='public' AND table_type='BASE TABLE' ORDER BY 1"
)->fetchAll(PDO::FETCH_COLUMN);

// mapeo entidad-conversacional → tabla(s) real(es) — verificado contra
// sql/schema.sql (2026-09-22): nombres corregidos donde divergían
$entityTables = [
    'students'    => ['students','student_group_assignments'],
    'guardians'   => ['guardians','guardian_student_relationships'],
    'teachers'    => ['users','teacher_group_access'],
    'groups'      => ['academic_groups'],
    'subjects'    => ['subjects'],
    'schedules'   => ['schedules','school_schedule_config','daily_schedule_config','school_time_blocks'],
    'attendance'  => ['biometric_events','attendance_incidents'],
    'incidents'   => ['attendance_incidents','security_incidents'],
    'tracking'    => ['student_tracking','student_tracking_notes'],
    'permissions' => ['class_exit_authorizations','school_exit_authorizations','pedagogical_trip_authorizations'],
    'exits'       => ['school_exit_authorizations'],
    'notifications'=>['notifications','twilio_messages','school_notification_routes','internal_messages'],
    'devices'     => ['edge_devices','device_commands'],
    'audit'       => ['global_audit_logs','student_record_audit','risk_audit_log'],
    'users'       => ['users','roles'],
    'risk'        => ['student_behavior_metrics','risk_alerts','risk_active_snapshot','risk_rules'],
];

/* ── 2. endpoints reales (rutas + handlers consulta) ─────────────────────── */
$routeFiles = glob("$HTML/routes/*.php");
$endpointCount = 0;
foreach ($routeFiles as $f) {
    $src = file_get_contents($f);
    $endpointCount += preg_match_all('/\$path\s*={0,2}\s*[\'"]|case\s+[\'"]|elseif\s*\(\$path/u', $src);
}

/* ── 3. handlers conversacionales legacy (chat_*) ────────────────────────── */
$chatSrc = file_get_contents("$HTML/routes/chat.php");
preg_match_all('/function\s+(chat_\w+)\s*\(/u', $chatSrc, $hm);
$handlers = array_unique($hm[1]);
sort($handlers);

/* ── 4. intents legacy → clasificación A-E (§32) ─────────────────────────── */
$delegated = []; // caps cuyo exec es intent:X
foreach ($REG as $id => $c)
    if (str_starts_with((string)($c['exec'] ?? ''), 'intent:'))
        foreach (explode('|', substr($c['exec'], 7)) as $i) $delegated[$i] = $id;
$semanticExecs = array_filter($REG, fn($c) => !str_starts_with((string)($c['exec'] ?? ''), 'intent:'));

$legacyClass = [];
foreach ($handlers as $h) {
    $intent = substr($h, 5); // chat_foo → foo
    if (in_array($h, ['chat_dispatch','chat_log','chat_help','chat_scope','chatrange','chatresolvegroup','chatresolvestudent','chatlastpayload','chatloadds','chatbuildds','chatresultnav','chatpolicyenabled','chatallowed','chatsmalltalkproxy'], true)
        || str_starts_with($intent, 'smalltalk') || str_contains($intent, 'resolve')) continue; // infraestructura
    if (isset($delegated[$intent]))        $legacyClass[$h] = 'A'; // reemplazable por cap
    elseif (isset($REG[$intent]))          $legacyClass[$h] = 'A';
    elseif (in_array($intent, ['start_operation','derive_action','confirm_op','cancel','repeat_op','export_data','generate_document','send_notification'], true))
                                         $legacyClass[$h] = 'E'; // mutación
    else                                 $legacyClass[$h] = 'B'; // fallback útil / pendiente
}

/* ── 5. cobertura por dimensión ──────────────────────────────────────────── */
$coveredEntities = []; $missingEntities = [];
foreach ($entityTables as $ent => $tabs) {
    $exists = count(array_intersect($tabs, $tables)) > 0;
    $hasCap = false;
    foreach ($GRAPH as $id => $c) {
        $src = (string)($c['source_entity'] ?? '');
        // cubierta si alguna capability la nombra o su fuente toca sus tablas
        if (str_starts_with($id, $ent . '.') || str_contains($id, $ent)
            || array_intersect($tabs, preg_split('/[+\s]/', $src))) { $hasCap = true; break; }
    }
    if ($exists && $hasCap) $coveredEntities[] = $ent; else $missingEntities[] = $ent;
}

$allFilters = []; $allSorts = []; $allPres = []; $allOps = [];
foreach ($GRAPH as $c) {
    $allFilters = array_merge($allFilters, $c['filters'] ?? []);
    $allSorts   = array_merge($allSorts,   $c['sorting'] ?? []);
    $allPres    = array_merge($allPres,    $c['presentation_modes'] ?? []);
    $allOps[]   = $c['operation'] ?? 'list';
}
$allFilters = array_values(array_unique($allFilters));
$allSorts   = array_values(array_unique($allSorts));
$allPres    = array_values(array_unique($allPres));
$allOps     = array_values(array_unique($allOps));

$execCaps = array_keys($semanticExecs);
$delegatedCaps = array_keys(array_filter($REG, fn($c) => str_starts_with((string)($c['exec'] ?? ''), 'intent:')));

/* ── 6. salida ───────────────────────────────────────────────────────────── */
echo "═══ ECOSYSTEM CAPABILITY INVENTORY ═══\n\n";
printf("DB tables: %d · route files: %d · chat handlers: %d\n\n",
    count($tables), count($routeFiles), count($handlers));

echo "── Capability graph ──\n";
printf("  capacidades registradas: %d\n", count($GRAPH));
printf("  con executor semántico propio: %d (%s)\n", count($execCaps), implode(', ', $execCaps));
printf("  delegadas a intents: %d\n", count($delegatedCaps));
printf("  dominios: %s\n\n", implode(', ', array_values(array_unique(array_column($GRAPH,'domain')))));

echo "── Cobertura de entidades (schema real) ──\n";
foreach ($entityTables as $ent => $tabs) {
    $exists = (bool)array_intersect($tabs, $tables);
    $cap = in_array($ent, $coveredEntities, true);
    printf("  %-14s tables=%-40s %s\n", $ent, implode(',', $tabs),
        !$exists ? 'AUSENTE-DB' : ($cap ? 'CUBIERTA' : 'SIN-CAPABILITY'));
}

echo "\n── Superficie declarativa del grafo ──\n";
printf("  filtros soportados (%d): %s\n", count($allFilters), implode(', ', $allFilters));
printf("  sorts (%d): %s\n", count($allSorts), implode(', ', $allSorts));
printf("  presentaciones (%d): %s\n", count($allPres), implode(', ', $allPres));
printf("  operaciones (%d): %s\n", count($allOps), implode(', ', $allOps));

echo "\n── Intents legacy (§32) ──\n";
$counts = array_count_values($legacyClass);
foreach ($legacyClass as $h => $cls) printf("  %-42s %s\n", $h, $cls);
printf("\n  A(absorbible)=%d  B(fallback)=%d  E(mutación)=%d\n\n",
    $counts['A'] ?? 0, $counts['B'] ?? 0, $counts['E'] ?? 0);

echo "── Resumen §39 ──\n";
printf("  N entidades-db: %d\n", count($tables));
printf("  M relaciones registradas: %d\n", array_sum(array_map(fn($c)=>count($c['relations'] ?? []), $GRAPH)));
printf("  K capacidades: %d (ejecutables %d + delegadas %d)\n", count($GRAPH), count($execCaps), count($delegatedCaps));
printf("  P operaciones: %d\n", count($allOps));
printf("  Q filtros: %d\n", count($allFilters));
printf("  R presentaciones: %d\n", count($allPres));
printf("  S composiciones: %d (capability × presentation × ops)\n",
    count($execCaps) * max(1,count($allPres)));
printf("  T legacy mapeados(A): %d\n", $counts['A'] ?? 0);
printf("  U no conversacionales(E): %d\n", $counts['E'] ?? 0);
