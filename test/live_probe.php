<?php
/**
 * live_probe — runner de diagnóstico §1/§2 contra la API viva.
 * Envía la conversación verbatim y captura por turno:
 * intent, confidence, reply, entities, _ds(before/after), source, cards.
 *
 * Uso:
 *   php test/live_probe.php [--turns=N] [--sleep=MS]
 * Env: NEXO_API, NEXO_TEST_USER, NEXO_TEST_PASS
 */
$BASE = getenv('NEXO_API') ?: 'http://localhost:18080';
$SLEEP_MS = 700;
$TURNS_LIMIT = 0;
foreach ($argv ?? [] as $a) {
    if (str_starts_with($a,'--turns=')) $TURNS_LIMIT = (int)substr($a,8);
    if (str_starts_with($a,'--sleep=')) $SLEEP_MS = (int)substr($a,8);
}

// conversación §1 verbatim — no tocar las frases
$SCRIPT = [
    'Muéstrame el primero de décimo A.',
    '¿Quién es su acudiente?',
    'Muéstrame una tabla con los grupos.',
    '¿Quién es el acudiente del primero?',
    'Dame el acudiente de Tomás Castaño Gutiérrez de 10A.',
    'Dame las asistencias de mis grupos en total.',
    'Dame las asistencias de mi grupo.',
    '¿Cuántas evasiones internas hubo?',
    '¿Y del último mes?',
    'Muéstrame los estudiantes de 6A.',
    'Dámelos en una tabla.',
    'Dame una tabla de los estudiantes de 10A.',
    'Dime solo los primeros 5.',
    '¿Quién es el acudiente del primero?',
];

$ch = curl_init("$BASE/auth/login");
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>15,
    CURLOPT_HTTPHEADER=>['Content-Type: application/json','X-Requested-With: XMLHttpRequest'],
    CURLOPT_POSTFIELDS=>json_encode(['email'=>getenv('NEXO_TEST_USER') ?: 'teach@test.nexo',
        'password'=>getenv('NEXO_TEST_PASS') ?: 'test1234'])]);
$login = json_decode(curl_exec($ch) ?: '{}', true); curl_close($ch);
$TOKEN = $login['token'] ?? $login['data']['token'] ?? null;
if (!$TOKEN) { fwrite(STDERR,"login FAIL: ".json_encode($login)."\n"); exit(2); }

$SID = sprintf('%08x-%04x-4%03x-8%03x-%012x', random_int(0,0xffffffff), random_int(0,0xffff), random_int(0,0xfff), random_int(0,0xfff), random_int(0,0xffffffffffff));

function ask(string $text, string $sid, string $token, string $base): array {
    $ch = curl_init("$base/chat/message");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 25,
        CURLOPT_HTTPHEADER => ["Authorization: Bearer $token",
            'X-Requested-With: XMLHttpRequest', 'Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode(['text'=>$text,'session_id'=>$sid]),
    ]);
    $r = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    $d = json_decode($r ?: '{}', true);
    $data = $d['data'] ?? $d ?? [];
    $data['_http'] = $code;
    return $data;
}

function dsSnapshot(?array $ds): string {
    if (!$ds) return 'null';
    $cur = $ds['current'] ?? [];
    $lr  = $ds['last_result'] ?? null;
    return json_encode([
        'intent'    => $ds['intent'] ?? null,
        'entities'  => $ds['entities'] ?? [],
        'goal'      => $ds['goal'] ?? null,
        'current'   => ['entity'=>$cur['entity']??null,'scope'=>$cur['scope']??null,
                        'relation'=>$cur['relation']??null,'result'=>$cur['result']??null],
        'previous'  => $ds['previous'] ?? null,
        'objects'   => array_map(fn($o)=>['rid'=>$o['rid']??null,'type'=>$o['type']??null,'filters'=>$o['filters']??null], $ds['objects'] ?? []),
        'last_result'=> $lr ? ['type'=>$lr['type']??null,'count'=>$lr['count']??null,'n_items'=>count($lr['items']??[])] : null,
        'cursor'    => $ds['cursor'] ?? null,
        'pending_op'=> $ds['pending_op'] ?? null,
    ], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
}

echo "═══ live_probe — $BASE — sesión $SID ═══\n\n";
$prevDs = null;
$rows = [];
foreach ($SCRIPT as $i => $msg) {
    if ($TURNS_LIMIT && $i >= $TURNS_LIMIT) break;
    $out = ask($msg, $SID, $TOKEN, $BASE);
    $ds = $out['_ds'] ?? null;
    $rows[] = [
        'n'=>$i+1,'in'=>$msg,
        'http'=>$out['_http'] ?? 0,
        'intent'=>$out['intent'] ?? '?',
        'conf'=>$out['confidence'] ?? null,
        'source'=>$out['source'] ?? null,
        'reply'=>mb_substr(str_replace("\n",' ',$out['reply'] ?? ''),0,140),
        'entities'=>$out['entities'] ?? null,
        'cards'=>isset($out['cards']) ? count($out['cards']) : 0,
        'ds_before'=>$prevDs,'ds_after'=>$ds,
    ];
    printf("── t%02d «%s»\n    intent=%s conf=%s src=%s cards=%d http=%s\n    reply: %s\n    _ds.before: %s\n    _ds.after : %s\n\n",
        $i+1, $msg,
        $out['intent'] ?? '?', $out['confidence'] ?? '-', $out['source'] ?? '-',
        isset($out['cards']) ? count($out['cards']) : 0, $out['_http'] ?? 0,
        mb_substr(str_replace("\n",' ',$out['reply'] ?? ''),0,120),
        dsSnapshot($prevDs), dsSnapshot($ds));
    $prevDs = $ds;
    usleep($SLEEP_MS*1000);
}
file_put_contents('/tmp/live_probe_last.json', json_encode($rows, JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT));
echo "→ /tmp/live_probe_last.json\n";
