<?php
/**
 * test/blind_eval.php — Evaluación ciega + calibración del NLU.
 * Usa el camino REAL de producción: nxClassify() (servicio→php→fallback).
 * Read-only. Uso: php test/blind_eval.php  → /tmp/blind_eval.json
 */
require __DIR__ . '/../backend/api/lib/nexus_nlu.php';

$set = json_decode(file_get_contents(__DIR__ . '/blind_set.json'), true);
// --clean: excluir frases contaminadas corpus↔blind (auditoría Fase 3B)
$cleanOnly = in_array('--clean', $argv ?? [], true);
$contam = json_decode(@file_get_contents('/tmp/contam.json'), true)['contaminated_idx'] ?? [];
if ($cleanOnly && $contam) {
    $set['single'] = array_values(array_filter($set['single'],
        fn($t, $i) => !in_array($i, $contam, true), ARRAY_FILTER_USE_BOTH));
}
$rows = [];
$bands = ['>=0.90' => ['ok' => 0, 'n' => 0], '0.65-0.90' => ['ok' => 0, 'n' => 0],
          '<0.65' => ['ok' => 0, 'n' => 0]];
$wrongConfident = []; $abstain = [];

foreach ($set['single'] as $t) {
    $r = nxClassify($t['text']);
    $intent = $r['intent']; $conf = $r['confidence'] ?? 0;
    $ok = in_array($intent, $t['expect'], true);
    $band = $conf >= 0.90 ? '>=0.90' : ($conf >= 0.65 ? '0.65-0.90' : '<0.65');
    // tras threshold, <0.65 ⇒ out_of_scope ya marcado por nxClassify
    if ($conf < 0.65) { $intent = $r['intent']; } // ya out_of_scope
    $okPost = in_array($intent, $t['expect'], true);
    $bands[$band]['n']++;
    if ($okPost) $bands[$band]['ok']++;
    if (!$okPost && $conf >= 0.90) $wrongConfident[] = [$t['text'], $intent, $conf, $t['expect']];
    if ($conf < 0.65) $abstain[] = [$t['text'], $r['top3'][0][0] ?? '?', $conf, in_array($r['top3'][0][0] ?? '', $t['expect'])];
    $rows[] = ['text' => $t['text'], 'cat' => $t['cat'], 'intent' => $intent,
               'conf' => round($conf, 4), 'ok' => $okPost, 'top3' => $r['top3'] ?? []];
}

$total = count($rows);
$correct = count(array_filter($rows, fn($r) => $r['ok']));
echo "BLIND TEST — {$correct}/{$total} (" . round($correct / $total * 100, 1) . "%)\n\n";

echo "Calibración por banda de confianza:\n";
foreach ($bands as $b => $d) {
    $acc = $d['n'] ? round($d['ok'] / $d['n'] * 100, 1) : 0;
    echo "  {$b}: {$d['ok']}/{$d['n']} = {$acc}%\n";
}

echo "\nFalso convencimiento (≥0.90 y mal):\n";
foreach ($wrongConfident as $w) echo "  «{$w[0]}» → {$w[1]} ({$w[2]}) esperaba " . implode('|', $w[3]) . "\n";
if (!$wrongConfident) echo "  ninguno\n";

$abstSaved = count(array_filter($abstain, fn($a) => !$a[3]));
$abstMiss = count(array_filter($abstain, fn($a) => $a[3]));
echo "\nAbstenciones (<0.65): " . count($abstain) . " — correctas por abstenerse: {$abstSaved}, perdidas (top1 era bueno): {$abstMiss}\n";
foreach ($abstain as $a) echo "  «{$a[0]}» top1={$a[1]}({$a[2]}) " . ($a[3] ? 'era correcto' : 'bien abstenerse') . "\n";

echo "\nFallos por categoría:\n";
$byCat = [];
foreach ($rows as $r) {
    $byCat[$r['cat']] = $byCat[$r['cat']] ?? ['n' => 0, 'fail' => 0];
    $byCat[$r['cat']]['n']++;
    if (!$r['ok']) $byCat[$r['cat']]['fail']++;
}
foreach ($byCat as $c => $d) {
    printf("  %-16s %d/%d\n", $c, ($d['n'] - ($d['fail'] ?? 0)), $d['n']);
}

file_put_contents('/tmp/blind_eval.json', json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
echo "\n→ /tmp/blind_eval.json\n";
