<?php
/**
 * test/op_eval.php — Evaluador del benchmark operativo.
 * Corre production_operational_blind.json: singles por nxClassify y
 * conversaciones por simulateTurn (misma lógica de ctx que producción).
 * Uso (parser en vivo — NX_CLASSIFY_FIXTURE= fuerza API real): php test/op_eval.php [--clean]
 */
require __DIR__ . '/../backend/api/lib/nexus_nlu.php';
require __DIR__ . '/../backend/api/routes/chat.php';
require __DIR__ . '/harness_turn.php';

const ROLE = 'TEACHER';
$TRACES = [];

$set = json_decode(file_get_contents(__DIR__ . '/fixtures/production_operational_blind.json'), true);
$clean = in_array('--clean', $argv ?? [], true);
$contamIdx = json_decode(@file_get_contents('/tmp/op_contam.json'), true) ?: [];

function checkExpect(array $exp, string $intent, array $slots, ?string $op, float $conf): array {
    $fails = [];
    if (isset($exp['intent']) && !in_array($intent, $exp['intent'], true))
        $fails[] = "intent={$intent} ∉ " . implode('|', $exp['intent']);
    if (isset($exp['not_intent']) && in_array($intent, $exp['not_intent'], true))
        $fails[] = "intent={$intent} ∈ not " . implode('|', $exp['not_intent']);
    foreach (['student','group','module','field'] as $k) {
        if (isset($exp[$k])) {
            $got = mb_strtolower((string)($slots[$k] ?? ''));
            $want = mb_strtolower($exp[$k]);
            if (mb_strtolower($got) !== $want && !($want === '*' && $got !== ''))
                $fails[] = "$k=$got ≠ $want";
        }
    }
    if (isset($exp['days']) && (int)($slots['days'] ?? -1) !== (int)$exp['days'])
        $fails[] = "days=" . var_export($slots['days'] ?? null, true) . " ≠ {$exp['days']}";
    if (isset($exp['op']) && $op !== $exp['op'])
        $fails[] = "op=" . var_export($op, true) . " ≠ {$exp['op']}";
    return $fails;
}

/* ── SINGLES ──────────────────────────────────────────────────────────────── */
$S = ['ok'=>0,'n'=>0,'abst'=>0,'abst_ok'=>0,'fc'=>0,'crit_fail'=>0];
$perCat = []; $fcList = []; $critList = []; $allRows = [];
$S['raw_ok'] = 0;
foreach ($set['single'] as $idx => $t) {
    if ($clean && in_array($idx, $contamIdx, true)) continue;
    $cat = $t['cat'] ?? 'misc';
    $cls = nxClassify($t['text']);
    $rawIntent = $cls['intent']; $conf = $cls['confidence'] ?? 0;
    if (in_array($rawIntent, $t['expect'], true)) $S['raw_ok']++;
    // el sistema real resuelve single-turn por nxDialogueResolve (ctx null):
    // resolución contextual del DSM (herencia, coverage) — la ruta de producción
    $interp = nxDialogueResolve($cls, null, nxNorm($t['text']));
    $intent = $interp['resolved']['intent'];
    $perCat[$cat]['n'] = ($perCat[$cat]['n'] ?? 0) + 1;
    $S['n']++;
    $ok = in_array($intent, $t['expect'], true);
    if ($conf >= 0.90 && !$ok) {
        $S['fc']++; $fcList[] = [$t['text'], $intent, $conf, $t['expect'][0]];
    }
    if ($conf < 0.65) {
        $S['abst']++;
        if (in_array('out_of_scope', $t['expect'], true)) $S['abst_ok']++;
    }
    if ($ok) { $S['ok']++; $perCat[$cat]['ok'] = ($perCat[$cat]['ok'] ?? 0) + 1; }
    if (!empty($t['critical']) && !$ok) {
        $S['crit_fail']++;
        $critList[] = [$t['text'], $intent, $conf];
    }
    $allRows[] = ['type'=>'s','text'=>$t['text'],'intent'=>$intent,'conf'=>$conf,
                  'expect'=>$t['expect'],'ok'=>$ok,'cat'=>$cat];
}
printf("  (crudo clasificador: %d/%d = %.1f%% — ablación NLU solo)\n",
    $S['raw_ok'], $S['n'], $S['raw_ok']/$S['n']*100);
printf("SINGLES: %d/%d = %.1f%%  | abst=%d (bien=%d) | FC≥0.90=%d | críticos-fallidos=%d\n",
    $S['ok'], $S['n'], $S['ok']/$S['n']*100, $S['abst'], $S['abst_ok'], $S['fc'], $S['crit_fail']);

/* ── CONVERSACIONES ───────────────────────────────────────────────────────── */
$C = ['turns'=>0,'ok'=>0,'convos'=>0,'convos_ok'=>0,'bycat'=>[]];
$convFails = [];
foreach ($set['conversations'] as $cv) {
    $ctx = null; $last = null; $allOk = true;
    foreach ($cv['turns'] as $tn => $turn) {
        $res = simulateTurn($turn['text'], $ctx, $last);
        $intent = $res['intent'];
        $slots = $res['trace']['9_slots_finales'] ?? [];
        $conf = $res['trace']['6_confianza'] ?? 0;
        $op = $res['operation'] ?? null;
        if ($res['trace']['17_handler'] ?? false) $last = ['intent'=>$intent,'entities'=>$slots];
        $fails = checkExpect($turn['expect'], $intent, $slots, $op, $conf);
        $C['turns']++;
        $C['bycat'][$cv['cat']]['n'] = ($C['bycat'][$cv['cat']]['n'] ?? 0) + 1;
        if (!$fails) { $C['ok']++; $C['bycat'][$cv['cat']]['ok'] = ($C['bycat'][$cv['cat']]['ok'] ?? 0) + 1; }
        else { $allOk = false; $convFails[] = "{$cv['name']}[T{$tn}] «{$turn['text']}» → " . implode('; ', $fails); }
        $allRows[] = ['type'=>'c','conv'=>$cv['name'],'tn'=>$tn,'text'=>$turn['text'],
                      'intent'=>$intent,'conf'=>$conf,'fails'=>$fails,'cat'=>$cv['cat']];
    }
    $C['convos']++;
    if ($allOk) $C['convos_ok']++;
}
printf("CONVERSACIONES: %d/%d turnos (%.1f%%) · %d/%d convos completas\n",
    $C['ok'], $C['turns'], $C['ok']/$C['turns']*100, $C['convos_ok'], $C['convos']);

echo "\n── por categoría (single) ──\n";
ksort($perCat);
foreach ($perCat as $c => $m) printf("  %-18s %d/%d\n", $c, $m['ok'] ?? 0, $m['n']);
echo "\n── por categoría (convos) ──\n";
foreach ($C['bycat'] as $c => $m) printf("  %-18s %d/%d\n", $c, $m['ok'] ?? 0, $m['n']);
if ($fcList) { echo "\n── falsos convencidos ≥0.90 ──\n";
    foreach ($fcList as [$t,$i,$c,$e]) printf("  «%s» → %s (%.2f) esp %s\n", $t, $i, $c, $e); }
if ($critList) { echo "\n── CRÍTICOS FALLIDOS ──\n";
    foreach ($critList as [$t,$i,$c]) printf("  «%s» → %s %.2f\n", $t, $i, $c); }
if ($convFails) { echo "\n── fallos conversacionales ──\n";
    foreach ($convFails as $f) echo "  $f\n"; }
file_put_contents('/tmp/op_eval_rows.json', json_encode($allRows, JSON_UNESCAPED_UNICODE));
