<?php
/**
 * test/parity_v2b.php — paridad Python(servicio :8092) ↔ PHP(model_php_v2_B.json).
 * Réplica nxClassifyLocal pero con el JSON candidato. NO toca producción.
 * Uso: NEXO_NLU_URL=http://localhost:8092 php test/parity_v2b.php
 */
require __DIR__ . '/../backend/api/lib/nexus_nlu.php';

$modelPath = $argv[1] ?? __DIR__ . '/../backend/nlu/model/exp/model_php_v2_B.json';
$PHP_MODEL = json_decode(file_get_contents($modelPath), true);

/** Réplica exacta de nxClassifyLocal con modelo inyectado. */
function classifyPhp(string $text): array {
    global $PHP_MODEL;
    $all = $PHP_MODEL;
    $norm = nxNorm($text);
    $slots = nxSlots($norm);
    $q = nxMask($norm);
    $r = nxSoftmax($all['router'], $q);
    $pFormal = $r['_proba'][array_search('formal', $all['router']['classes'])] ?? 0;
    $critical = !empty($slots['student']) || !empty($slots['group']) || !empty($slots['module']);
    $informalOnly = !$critical && (bool)preg_match(
        '/\b(chiste|chistes|cuento|cuentos|historia|cantame|canta|baila|'
      . 'frio|calor|clima|llov|hambre|sed|aburrid|pereza|'
      . 'triste|alegre|feliz|estresad|ansios|sentido|existimos|'
      . 'vivimos|vida|horoscopo|tarot|zodiacal|noticias|futbol|partido|'
      . 'deporte|pelicula|serie|musica|cancion|almuerzo|comida|desayuno|'
      . 'arepa|receta|sueno|cansado|inutil|tonto|bruto|feo|fea|lindo|'
      . 'hermoso|genial|chevere|bacano|sorprendeme|impresioname|'
      . 'que dia es|que fecha|que hora|a que dia|te amo|te quiero|'
      . 'me gustas|enamorad|novio|novia|casar|beso|'
      . 'como andas|como estas|como vas|que tal|como te va|'
      . 'hemos hablado|de que hablamos|de que hemos)\b/u', $q);
    $domain = $informalOnly ? 'informal' : (($critical || $pFormal >= NX_FORMAL_BIAS) ? 'formal' : 'informal');
    $sub = nxSoftmax($all[$domain], $q);
    $intent = $sub['intent'] ?? 'out_of_scope';
    $conf = $sub['confidence'] ?? 0;
    $top3 = array_map(fn($i) => [$all[$domain]['classes'][$i], round($sub['top3'][$i] ?? 0, 4)],
                      array_keys($sub['top3']));
    if (!$critical && !$informalOnly && $pFormal >= 0.20 && $pFormal <= 0.80) {
        $other = $domain === 'formal' ? 'informal' : 'formal';
        $sub2 = nxSoftmax($all[$other], $q);
        if (($sub2['confidence'] ?? 0) > $conf + 0.15) {
            $domain = $other;
            $intent = $sub2['intent']; $conf = $sub2['confidence'];
        }
    }
    return ['domain' => $domain, 'intent' => $intent,
            'confidence' => round($conf, 4), 'entities' => $slots];
}

$set = json_decode(file_get_contents(__DIR__ . '/blind_set.json'), true);
$texts = array_column($set['single'], 'text');
foreach ($set['conversational'] as $c) foreach ($c['turns'] as $t) $texts[] = $t['text'];

$exact = $close = $diff = 0; $worst = [];
foreach ($texts as $t) {
    $php = classifyPhp($t);
    $r = nxClassifyService($t);          // apunta a :8092 vía NEXO_NLU_URL
    $py = ['intent' => $r['intent'] ?? '?', 'confidence' => $r['confidence'] ?? 0,
           'domain' => $r['domain'] ?? '?'];
    if ($php['intent'] === $py['intent'] && abs($php['confidence'] - $py['confidence']) < 0.01) {
        $exact++;
    } elseif ($php['intent'] === $py['intent']) {
        $close++;
    } else {
        $diff++;
        $worst[] = [$t, $php, $py];
    }
}
$n = count($texts);
printf("PARIDAD Py↔PHP  n=%d  exactas=%d (%.0f%%)  mismo-intent-conf-diff=%d  divergentes=%d\n",
    $n, $exact, $exact / $n * 100, $close, $diff);
foreach ($worst as [$t, $php, $py]) {
    printf("  «%s»\n    php: %s %.3f (%s)\n    py : %s %.3f (%s)\n",
        $t, $php['intent'], $php['confidence'], $php['domain'],
        $py['intent'], $py['confidence'], $py['domain']);
}
