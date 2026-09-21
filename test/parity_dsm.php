<?php
/* test/parity_dsm.php — paridad de extracción PHP (nxSlots) ↔ Python
 * (preprocess.extract_entities vía servicio). Una divergencia en entidades
 * rompe el DSM en producción (PHP) cuando el servicio está caído.
 *
 * Uso: NEXO_NLU_URL=http://localhost:8095 php test/parity_dsm.php
 */
define('ROLE', 'TEACHER');
require_once __DIR__ . '/../backend/api/lib/nexus_nlu.php';

$CASES = [
    'los permisos del 8a del mes pasado',
    'las tardanzas de juan del 7b',
    'datos de camila del quinto',
    'el resumen de pedro del octavo',
    'info de pedro del octavo',
    'camila del septimo',
    'juan del 8a documento',
    'de juan especificamente',
    'el permiso para el mismo juan',
    'maria manana',
    'para ella',
    'cuantas evasiones hubo esta semana',
    'los del noveno',
    'del septimo',
    'y ahora del octavo',
    'evasiones del segundo',
    'seguimientos abiertos del noveno a',
    'los que faltaron ayer',
    'tardanzas de hoy',
    'permisos del 9b del mes pasado',
    'su ficha completa',
    'cuantas hubo hoy',
    'las de ayer',
    'estudiante juan perez tardanzas',
    'cita a la mama de laura',
];

$service = getenv('NEXO_NLU_URL') ?: 'http://localhost:8090';
$KEYS = ['student','group','module','days','field'];

function pyEntities(string $url, string $text): ?array {
    $ch = curl_init($url . '/classify');
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 5, CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode(['text' => $text])]);
    $r = curl_exec($ch);
    return $r ? (json_decode($r, true)['entities'] ?? []) : null;
}

$P = 0; $F = 0;
echo "═══ paridad PHP ↔ Python (extracción de entidades) ═══\n\n";
printf("%-44s │ %-32s │ %-32s │ %s\n", 'texto', 'PHP', 'Python', 'paridad');
echo str_repeat('─', 120) . "\n";
foreach ($CASES as $text) {
    $php = nxSlots(nxNorm($text));
    $py = pyEntities($service, $text);
    if ($py === null) { echo "  servicio $service caído — aborta\n"; exit(2); }
    $phpC = []; $pyC = [];
    foreach ($KEYS as $k) {
        if (isset($php[$k]) && $php[$k] !== null && $php[$k] !== '') $phpC[$k] = (string)$php[$k];
        if (isset($py[$k]) && $py[$k] !== null && $py[$k] !== '') $pyC[$k] = (string)$py[$k];
    }
    // conflicto real: misma clave, distinto valor (module/field son PHP-only
    // por diseño — array_merge los añade siempre en producción)
    $conflict = [];
    foreach ($phpC as $k => $v)
        if (isset($pyC[$k]) && $pyC[$k] !== $v) $conflict[] = "$k:$v≠{$pyC[$k]}";
    $same = !$conflict;
    if ($same) $P++; else $F++;
    printf("%-44s │ %-32s │ %-32s │ %s\n", mb_substr($text, 0, 44),
        mb_substr(json_encode($phpC, JSON_UNESCAPED_UNICODE), 0, 32),
        mb_substr(json_encode($pyC, JSON_UNESCAPED_UNICODE), 0, 32),
        $same ? 'OK' : 'CONFLICTO ' . implode(',', $conflict));
}
printf("\n  RESULTADO: %d sin conflicto · %d en conflicto · %.1f%% paridad\n",
    $P, $F, $P / ($P + $F) * 100);
exit($F ? 1 : 0);   // un conflicto en claves compartidas es fallo de paridad
