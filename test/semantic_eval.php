<?php
/* test/semantic_eval.php — evalúa semantic_blind.json por la ruta real
 * (nxClassify → nxDialogueResolve). Imprime ablación crudo vs resuelto,
 * métricas por categoría, falsos-convencidos y críticos.
 * Uso (parser en vivo — NX_CLASSIFY_FIXTURE= fuerza API real): php test/semantic_eval.php
 */
define('ROLE', 'TEACHER');
require_once __DIR__ . '/../backend/api/nexus/nexus_nlu.php';
require_once __DIR__ . '/../backend/api/routes/chat.php';
require_once __DIR__ . '/harness_turn.php';

$set = json_decode(file_get_contents(__DIR__ . '/fixtures/semantic_blind.json'), true);

$S = ['ok'=>0,'n'=>0,'raw'=>0,'fc'=>0,'crit'=>0];
$perCat = []; $fcList = []; $critList = [];
foreach ($set['single'] as $t) {
    $cls = nxClassify($t['text']);
    if (in_array($cls['intent'], $t['expect'], true)) $S['raw']++;
    $i = nxDialogueResolve($cls, null, nxNorm($t['text']));
    $intent = $i['resolved']['intent'];
    $cat = $t['cat']; $perCat[$cat]['n'] = ($perCat[$cat]['n'] ?? 0) + 1;
    $S['n']++;
    $ok = in_array($intent, $t['expect'], true);
    if ($ok) { $S['ok']++; $perCat[$cat]['ok'] = ($perCat[$cat]['ok'] ?? 0) + 1; }
    if (($cls['confidence'] ?? 0) >= 0.90 && !$ok) { $S['fc']++; $fcList[] = [$t['text'], $intent]; }
    if (!empty($t['critical']) && !$ok) { $S['crit']++; $critList[] = [$t['text'], $intent]; }
}
printf("═══ SINGLES %d ═══\nresuelto: %d = %.1f%% | crudo: %d = %.1f%% | FC≥0.90=%d | críticos=%d\n\n",
    $S['n'], $S['ok'], $S['ok']/$S['n']*100, $S['raw'], $S['raw']/$S['n']*100, $S['fc'], $S['crit']);
foreach ($perCat as $c => $d)
    printf("  %-14s %d/%d\n", $c, $d['ok'] ?? 0, $d['n']);

/* ── ADVERSARIALES — todo debe resolver a intent seguro ─────────────── */
$SAFE = ['security_probe','out_of_scope','permissions','export_data','student_summary','student_field','about_me','derive_action','start_operation'];
$A = ['ok'=>0,'n'=>0]; $advFails = [];
foreach ($set['adversarial'] as $t) {
    $cls = nxClassify($t['text']);
    $i = nxDialogueResolve($cls, null, nxNorm($t['text']));
    $intent = $i['resolved']['intent'];
    $A['n']++;
    $ok = in_array($intent, $t['expect'], true) && in_array($intent, $SAFE, true);
    if ($ok) $A['ok']++; else $advFails[] = [$t['text'], $intent];
}
printf("\n═══ ADVERSARIALES %d ═══\nseguros: %d = %.1f%% | escapes=%d\n", $A['n'], $A['ok'], $A['ok']/$A['n']*100, $A['n']-$A['ok']);
foreach (array_slice($advFails, 0, 12) as [$t,$i2]) printf("  ✗ «%s» → %s\n", $t, $i2);

/* ── CONVERSACIONES ─────────────────────────────────────────────────── */
$C = ['turns'=>0,'ok'=>0,'convos'=>0,'convos_ok'=>0];
$convFails = [];
foreach ($set['conversations'] as $cv) {
    $ctx = null; $last = null; $allOk = true; $bad = null;
    foreach ($cv['turns'] as $turn) {
        $res = simulateTurn($turn['text'], $ctx, $last);
        $intent = $res['intent'];
        $C['turns']++;
        $ok = $intent !== 'out_of_scope' && $intent !== 'confused';
        // los turnos smalltalk/deicidic no cuentan como fallo
        if (in_array($intent, ['thanks','yes','no','greeting','ok','smalltalk','deictic','clarify','confirmation','cancel','repeat_op'], true)) $ok = true;
        if ($ok) $C['ok']++; else { $allOk = false; $bad = $turn['text'] . '→' . $intent; }
        $last = $res;
    }
    $C['convos']++; if ($allOk) $C['convos_ok']++; else $convFails[] = $cv['title'] . ' ' . $bad;
}
printf("\n═══ CONVERSACIONES ═══\nturnos: %d/%d = %.1f%% | convos completas: %d/%d\n",
    $C['ok'], $C['turns'], $C['ok']/$C['turns']*100, $C['convos_ok'], $C['convos']);
foreach (array_slice($convFails, 0, 15) as $f) echo "  ✗ $f\n";

printf("\n── FC≥0.90 (top) ──\n");
foreach (array_slice($fcList, 0, 15) as [$t,$i2]) printf("  «%s» → %s\n", $t, $i2);
printf("── CRÍTICOS FALLIDOS ──\n");
foreach ($critList as [$t,$i2]) printf("  «%s» → %s\n", $t, $i2);
