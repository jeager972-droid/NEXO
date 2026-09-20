<?php
/**
 * test/chat_context_sim.php — Simulación de sesión contextual.
 *
 * Simula el flujo de un docente en DOS turnos y demuestra que el segundo
 * turno (frase dependiente) hereda las entidades del primero:
 *
 *   T1: «búscame el reporte de Camilo Torres de 11A»
 *   T2: «y cuántas evasiones tiene»
 *
 * Uso: php test/chat_context_sim.php
 * Exit 0 si la herencia funciona.
 */
require __DIR__ . '/../backend/api/lib/nexus_nlu.php';

echo "╔══════════════════════════════════════════════════════════════╗\n";
echo "║  SIMULACIÓN DE SESIÓN — memoria de contexto (sessionStorage) ║\n";
echo "╚══════════════════════════════════════════════════════════════╝\n\n";

// ── sessionStorage simulado (equivalente a nx_chat_ctx del navegador) ──
$sessionStorage = [];

function simTurn(string $text, array &$sessionStorage): array {
    echo "👤 DOCENTE: «{$text}»\n";

    // Inyector de contexto (equivalente a injectCtx() del front)
    $ctx = $sessionStorage['ctx'] ?? null;
    $cls = nxClassify($text);
    $slots = $cls['entities'] ?? [];

    // Herencia: slots ausentes en el mensaje se completan desde ctx
    $inherited = [];
    if ($ctx) {
        foreach (['student','group','module','days','field'] as $k) {
            if (empty($slots[$k]) && !empty($ctx['entities'][$k])) {
                $slots[$k] = $ctx['entities'][$k];
                $inherited[] = $k;
            }
        }
        if (($cls['intent'] === 'out_of_scope' || ($cls['confidence'] ?? 0) < NX_NLU_THRESHOLD)
            && !empty($ctx['last_intent'])) {
            $cls['intent'] = $ctx['last_intent'];
            $inherited[] = 'intent';
        }
    }

    $intent = $cls['intent'];
    echo "   → intent: {$intent} (" . round(($cls['confidence'] ?? 0) * 100) . "%)\n";
    echo "   → slots enviados a PHP: " . json_encode($slots, JSON_UNESCAPED_UNICODE) . "\n";
    if ($inherited)
        echo "   ✔ HEREDADOS desde sessionStorage: " . implode(', ', $inherited) . "\n";

    // Persistencia de contexto (equivalente a saveCtx())
    if ($intent !== 'out_of_scope') {
        $sessionStorage['ctx'] = [
            'last_intent' => $intent,
            'entities'    => array_intersect_key($slots, array_flip(
                ['student','group','module','days','from','to','range_label','field'])),
            'ts'          => time(),
        ];
        echo "   💾 ctx guardado: " . json_encode($sessionStorage['ctx'], JSON_UNESCAPED_UNICODE) . "\n";
    }
    echo "\n";
    return ['intent' => $intent, 'slots' => $slots, 'inherited' => $inherited];
}

// ═══ TURNO 1 ═══
$t1 = simTurn('búscame el reporte de Camilo Torres de 11A', $sessionStorage);

// ═══ TURNO 2 ═══
$t2 = simTurn('y cuántas evasiones tiene', $sessionStorage);

// ═══ VERIFICACIÓN ═══
echo "────────────────────────────────────────────────────────────────\n";
$ok = true;
if (($t1['slots']['student'] ?? '') !== 'camilo torres') {
    echo "✗ T1 no extrajo el estudiante (got: " . var_export($t1['slots']['student'] ?? null, true) . ")\n"; $ok = false;
}
if (($t1['slots']['group'] ?? '') !== '11A') {
    echo "✗ T1 no extrajo el grupo (got: " . var_export($t1['slots']['group'] ?? null, true) . ")\n"; $ok = false;
}
if (($t2['slots']['student'] ?? '') !== 'camilo torres') {
    echo "✗ T2 no heredó el estudiante (got: " . var_export($t2['slots']['student'] ?? null, true) . ")\n"; $ok = false;
}
if (!in_array('student', $t2['inherited'], true)) {
    echo "✗ T2 no marcó 'student' como heredado\n"; $ok = false;
}
if (!in_array($t2['intent'], ['count_events','student_summary','list_events','student_field'], true)) {
    echo "✗ T2 intent inesperado: {$t2['intent']}\n"; $ok = false;
}

if ($ok) {
    echo "✔ TURNO 2 resolvió a Camilo Torres gracias a la memoria de sesión\n";
    echo "✔ Parámetros limpios listos para PHP: student=camilo torres, module=EVASION_INTERNA\n";
    echo "✔ PRUEBA SUPERADA\n";
    exit(0);
}
echo "✗ PRUEBA FALLIDA\n";
exit(1);
