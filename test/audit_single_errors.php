<?php
/* test/audit_single_errors.php — auditoría de errores single-turn (§3).
 * Dump: mensaje → esperado → intent NLU → conf → top-k → entities → slots
 * → causa clasificada (vocabulario|sintaxis|semantica|entidad|ambiguedad|
 * taxonomia|contexto|resolver|correcto).
 * Uso: NEXO_NLU_URL=http://localhost:8095 php test/audit_single_errors.php
 */
define('ROLE', 'TEACHER');
require_once __DIR__ . '/../backend/api/lib/nexus_nlu.php';
require_once __DIR__ . '/../backend/api/routes/chat.php';

$set = json_decode(file_get_contents(__DIR__ . '/production_operational_blind.json'), true);

/* Clasificador de causa — heurístico pero explícito y revisable */
function classifyCause(array $t, array $cls, string $resolved): string {
    $exp = $t['expect'] ?? [];
    $intent = $cls['intent'];
    $conf = $cls['confidence'] ?? 0;
    $top3 = $cls['top3'] ?? [];
    $text = mb_strtolower($t['text']);

    // 1. el intent esperado está en el top-k → borderline de confianza
    $inTop3 = false;
    foreach ($top3 as [$i2, $p2]) if (in_array($i2, $exp, true)) $inTop3 = true;
    if ($inTop3) return 'confianza_borderline';

    // 2. abstención cuando esperaba OOS → comportamiento correcto
    if (in_array('out_of_scope', $exp, true) && $intent === 'out_of_scope')
        return 'correcto';
    if ($intent === 'out_of_scope') {
        // OOS + dominio claro en el texto → cobertura semántica/vocabulario
        if (preg_match('/\b(volaron|voló|volo|escap|capar|tarde|temprano|falt|ausent|permiso|citacion|seguim|mensaje|acudiente|docente|coordinador|grupo|estudiant|alumn|profesor|maestro|horario|auditoria|riesgo|cumplea)\w*/u', $text))
            return 'semantica/vocabulario';
        return 'fuera_dominio_legitimo';
    }

    // 3. esperado OOS pero el sistema respondió algo → falso positivo
    if (in_array('out_of_scope', $exp, true))
        return 'falso_positivo';

    // 4. misma familia semántica (list/count/summary) → taxonomía/frontera
    $fam = [
        ['list_events','count_events','late_today','attendance_today','day_summary','count_present','top_offenders','attendance_ranking'],
        ['student_field','student_summary','group_summary','students_count','group_student_count'],
        ['trackings','count_trackings','citations','permissions','pending_returns'],
        ['teachers_list','staff_lookup'],
    ];
    foreach ($fam as $f) {
        $hits = array_intersect($f, $exp);
        if ($hits && in_array($intent, $f, true)) return 'taxonomia_frontera';
    }

    // 5. confianza alta y error → sobreconfianza/semántica
    if ($conf >= 0.90) return 'falsa_confianza';
    return 'clasificacion';
}

$causes = []; $errors = []; $n = 0; $ok = 0;
foreach ($set['single'] as $idx => $t) {
    $cls = nxClassify($t['text']);
    $interp = nxDialogueResolve($cls, null, nxNorm($t['text']));
    $intent = $interp['resolved']['intent'];
    $isOk = in_array($intent, $t['expect'], true);
    $n++; if ($isOk) { $ok++; continue; }
    $cause = classifyCause($t, $cls, $intent);
    $causes[$cause] = ($causes[$cause] ?? 0) + 1;
    $errors[] = [
        'idx' => $idx, 'text' => $t['text'], 'cat' => $t['cat'] ?? '',
        'expect' => $t['expect'], 'intent' => $intent,
        'conf' => round($cls['confidence'] ?? 0, 3),
        'top3' => $cls['top3'] ?? [], 'entities' => $cls['entities'] ?? [],
        'cause' => $cause,
    ];
}

printf("═══ AUDITORÍA SINGLE-TURN (%d errores de %d) ═══\n\n", count($errors), $n);
printf("%-30s %6s %5s\n", 'causa', 'n', '%err');
arsort($causes);
foreach ($causes as $c => $v) printf("%-30s %6d %4.1f%%\n", $c, $v, $v / count($errors) * 100);

echo "\n── detalle por causa ──\n";
foreach ($causes as $c => $_) {
    echo "\n### $c\n";
    foreach ($errors as $e) {
        if ($e['cause'] !== $c) continue;
        $t3 = implode(', ', array_map(fn($x) => $x[0] . ':' . round($x[1], 2), $e['top3']));
        printf("  [%d] «%s» exp=%s → %s(%.2f) top3=[%s]%s\n", $e['idx'], $e['text'],
            implode('|', $e['expect']), $e['intent'], $e['conf'], $t3,
            $e['entities'] ? ' ent=' . json_encode($e['entities'], JSON_UNESCAPED_UNICODE) : '');
    }
}
