<?php
/**
 * test/gen_llm_fixture.php — genera test/fixtures/llm_intents.json.
 *
 * Snapshot (VCR) de respuestas REALES del parser LLM para las frases que
 * usan las suites. Las suites lo sirven vía NX_CLASSIFY_FIXTURE →
 * determinismo offline sin gastar cuota en cada corrida.
 *
 * Flujo:
 *   1. Correr las suites con NX_CLASSIFY_LOG=/tmp/frases.txt → recoge las
 *      claves normalizadas que nxClassifyCore consulta.
 *   2. php test/gen_llm_fixture.php /tmp/frases.txt
 *      → llama el LLM en lotes de 15 (una llamada por lote).
 *   3. Commit del fixture generado.
 *
 * Uso: NLU_LLM_KEY=... php test/gen_llm_fixture.php <phrases.txt> [salida.json]
 */
require __DIR__ . '/../backend/api/lib/nexus_llm.php';

$in  = $argv[1] ?? null;
$out = $argv[2] ?? __DIR__ . '/fixtures/llm_intents.json';
if (!$in || !is_file($in)) { fwrite(STDERR, "uso: php test/gen_llm_fixture.php <phrases.txt> [out.json]\n"); exit(1); }
if (!nxLlmEnabled()) { fwrite(STDERR, "LLM deshabilitado — falta NLU_LLM_KEY o NLU_LLM_MODE=off\n"); exit(1); }

$phrases = array_values(array_unique(array_filter(
    array_map('trim', file($in, FILE_IGNORE_NEW_LINES)))));
if (!$phrases) { fwrite(STDERR, "sin frases\n"); exit(1); }
echo count($phrases) . " frases → lotes de 15\n";

$formal = implode('|', NX_LLM_FORMAL);
$informal = implode('|', NX_LLM_INFORMAL);
$sys = <<<PROMPT
Eres el parser semántico de Nexus, chatbot institucional de asistencia escolar en Colombia.
Clasifica CADA línea numerada → intent + confidence 0-1 + entities.
intents: {$formal} | {$informal} | out_of_scope
entities posibles: student(nombre persona), group(ej. 8B/once), module(INASISTENCIA|EVASION|SALIDA|RETIRO|DISCIPLINARIO|PERMISO|CITACION), field(celular|telefono|email|direccion|acudiente|grupo|documento|fecha_nacimiento|edad), days(num días a atrás), person(docente|personal), grade, search
REGLAS: acudiente/padre/madre de <estudiante o "el niño que..."> → student_field field=acudiente; referencia "el niño/el estudiante que llegó tarde/faltó/está en X" cuenta como estudiante (no uses out_of_scope por eso); padres/acudientes de un grupo → students_in_group; permisos/autorizaciones pendientes o por aprobar → permissions; no marcaron entrada/no han llegado → attendance_today; comparar grupos → attendance_ranking; comparar días/periodos → list_events con days; dato+social juntos → intent del dato; pronombres/deícticos/posesivos (él, ella, su, sus, este, ese, aquel, el primero, el último, el niño ese, uno, otro, le, les) NUNCA van en entities — student/person/search quedan vacíos (el DSM los resuelve por contexto); «<incidente> de <persona>» sin verbo de listado → count_events; «los/las que <verbo>» (los que se volaron, las que faltaron) → list_events; «faltaron/faltan» sobre asistencia → attendance_today o list_events, nunca group_summary; fragmentos de seguimiento sin verbo ni sujeto («y del mes», «y ayer», «y los del 8B», «y de X») → confidence≤0.5; ambiguo real → confidence<0.6.
Responde SOLO JSON: {"r":[{"i":1,"intent":"...","confidence":0.9,"entities":{}}]}
PROMPT;

$c = nxLlmCfg();
$map = [];
$B = 15;
for ($off = 0; $off < count($phrases); $off += $B) {
    $batch = array_slice($phrases, $off, $B);
    $lines = implode("\n", array_map(fn($i, $p) => ($i + 1) . '. ' . $p,
        array_keys($batch), $batch));
    $payload = [
        'model' => $c['model'], 'temperature' => 0, 'max_tokens' => 4000,
        'response_format' => ['type' => 'json_object'],
        'messages' => [
            ['role' => 'system', 'content' => $sys],
            ['role' => 'user', 'content' => $lines],
        ],
    ];
    $ch = curl_init($c['url'] . '/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json',
            'Authorization: Bearer ' . $c['key']],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_TIMEOUT_MS => 30000, CURLOPT_CONNECTTIMEOUT_MS => 5000,
    ]);
    $rows = null;
    for ($try = 0; $try < 3 && $rows === null; $try++) {
        $res = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $j = json_decode((string)$res, true);
        $rows = json_decode($j['choices'][0]['message']['content'] ?? '', true)['r'] ?? null;
        if ($code === 200 && is_array($rows)) break;
        $rows = null;
        if ($code === 429) { echo "  lote " . ($off/$B+1) . ": 429 → esperando 65s\n"; sleep(65); }
        else break;
    }
    curl_close($ch);
    if (!is_array($rows)) {
        fwrite(STDERR, "lote $off FALLÓ (HTTP $code) — se omite\n");
        continue;
    }
    foreach ($rows as $r) {
        $idx = ((int)($r['i'] ?? 0)) - 1;
        if (!isset($batch[$idx])) continue;
        $intent = trim((string)($r['intent'] ?? ''));
        if (!in_array($intent, NX_LLM_FORMAL, true)
            && !in_array($intent, NX_LLM_INFORMAL, true)) $intent = 'out_of_scope';
        $ent = [];
        foreach ((array)($r['entities'] ?? []) as $k => $v) {
            if ($v === null || $v === '') continue;
            $ent[$k] = $k === 'days' ? max(0, (int)$v) : mb_substr(trim((string)$v), 0, 120);
        }
        $map[$batch[$idx]] = [
            'intent' => $intent,
            'confidence' => round(max(0, min(1, (float)($r['confidence'] ?? 0.5))), 4),
            'entities' => $ent,
            'domain' => in_array($intent, NX_LLM_FORMAL, true) ? 'formal' : 'informal',
            'top3' => [],
        ];
    }
    echo "  lote " . ($off / $B + 1) . ": +" . count($rows) . "\n";
    usleep(21000000); // 21s — TPM 8k/min ≈ 3 lotes/min
}

// merge con fixture previo si existe (regeneración incremental)
if (is_file($out)) {
    $prev = json_decode((string)file_get_contents($out), true) ?: [];
    $map = $map + $prev;
}
file_put_contents($out, json_encode($map, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
echo count($map) . " entradas → $out\n";
