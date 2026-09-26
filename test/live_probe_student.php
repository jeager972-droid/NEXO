<?php
/**
 * live_probe_student.php — sonda quirúrgica del pipeline conversacional.
 * Reproduce los fallos observados en la transcripción real 2026-09-24
 * con estudiantes del fixture local:
 *  - filtro de estudiante en inasistencias (bug: devolvía 400 filas del colegio)
 *  - «y sus <módulo>?» hereda estudiante + rango
 *  - filtro justified (excusa) vía risk_justifications
 *  - permisos/citaciones por estudiante con rango
 *  - comparación por grupo + tendencia (group_by=trend)
 *  - «los grupos a mi cargo» → scope=mine
 */
$BASE = getenv('BASE') ?: 'http://127.0.0.1:18080';

function login(string $email, string $pass, string $base): string {
    $ch = curl_init("$base/auth/login");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>15,
        CURLOPT_POSTFIELDS=>json_encode(['email'=>$email,'password'=>$pass]),
        CURLOPT_HTTPHEADER=>['Content-Type: application/json']]);
    $l = json_decode(curl_exec($ch) ?: '{}', true); curl_close($ch);
    $t = $l['token'] ?? $l['data']['token'] ?? null;
    if (!$t) { fwrite(STDERR,"login FAIL $email\n"); exit(2); }
    return $t;
}

function ask(string $text, string $sid, string $token, string $base): array {
    $ch = curl_init("$base/chat/message");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>30,
        CURLOPT_HTTPHEADER=>["Authorization: Bearer $token",
            'X-Requested-With: XMLHttpRequest','Content-Type: application/json'],
        CURLOPT_POSTFIELDS=>json_encode(['text'=>$text,'session_id'=>$sid])]);
    $r = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    $d = json_decode($r ?: '{}', true);
    $data = $d['data'] ?? $d ?? [];
    $data['_http'] = $code;
    return $data;
}

function sid(): string {
    return sprintf('%08x-%04x-4%03x-%04x-%012x', random_int(0,0xffffffff),
        random_int(0,0xffff), random_int(0,0xfff),
        random_int(0,0xffff), random_int(0,0xffffffffffff));
}

function show(string $text, array $d): void {
    $intent = $d['intent'] ?? '-';
    $cards  = isset($d['cards']) ? count($d['cards']) : 0;
    $acts   = array_map(fn($a)=>($a['kind']??'?').':'.$a['label'], $d['actions'] ?? []);
    echo "  USER> $text\n";
    echo "    ► intent=$intent cards=$cards\n";
    $reply = trim((string)($d['reply'] ?? ''));
    echo "    ► reply: " . str_replace("\n"," ⏎ ",mb_substr($reply,0,320)) . "\n";
    foreach ($acts as $a) echo "    ► action: $a\n";
    $ds = $d['_ds'] ?? null;
    if ($ds) {
        $lr = $ds['last_result'] ?? null;
        echo "    ► _ds: intent=" . ($ds['intent']??'-')
           . " ent=" . json_encode($ds['entities']??[], JSON_UNESCAPED_UNICODE)
           . ($lr ? " last_result[type=".($lr['type']??'?')." n=".count($lr['items']??[])."]" : "")
           . "\n";
    }
    if ($cards) {
        $c = $d['cards'][0];
        echo "    ► card: " . ($c['title'] ?? '-') . " cols=" . count($c['columns'] ?? []) . " rows=" . count($c['rows'] ?? []) . "\n";
    }
    echo "\n";
}

$TEACH = login('teach@test.nexo', 'test1234', $BASE);

echo "═══ A. filtro de estudiante (bug 400-filas) ═══\n";
$s = sid();
show('lista los estudiantes de 10-a', ask('lista los estudiantes de 10-a', $s, $TEACH, $BASE));
show('una tabla con las inasistencias que ha tenido tomas castano gutierrez los ultimos 15 dias',
     ask('una tabla con las inasistencias que ha tenido tomas castano gutierrez los ultimos 15 dias', $s, $TEACH, $BASE));
show('y sus llegadas tarde?', ask('y sus llegadas tarde?', $s, $TEACH, $BASE));

echo "═══ B. permisos + citaciones por estudiante con rango ═══\n";
$s = sid();
show('tabla con los permisos que ha tenido tomas castano gutierrez, fechas y motivo los ultimos 15 dias',
     ask('tabla con los permisos que ha tenido tomas castano gutierrez, fechas y motivo los ultimos 15 dias', $s, $TEACH, $BASE));
show('tabla con las citaciones que ha tenido tomas castano gutierrez los ultimos 15 dias',
     ask('tabla con las citaciones que ha tenido tomas castano gutierrez los ultimos 15 dias', $s, $TEACH, $BASE));

echo "═══ C. comparación por grupo + tendencia ═══\n";
$s = sid();
show('una tabla donde compares la cantidad y aumento de las llegadas tarde en los grupos decimos los ultimos 15 dias',
     ask('una tabla donde compares la cantidad y aumento de las llegadas tarde en los grupos decimos los ultimos 15 dias', $s, $TEACH, $BASE));
show('los grupos que tengo a mi cargo', ask('los grupos que tengo a mi cargo', $s, $TEACH, $BASE));

echo "═══ D. excusa + herencia de rango ═══\n";
$s = sid();
show('las inasistencias de los ultimos 15 dias', ask('las inasistencias de los ultimos 15 dias', $s, $TEACH, $BASE));
show('alguno tiene excusa?', ask('alguno tiene excusa?', $s, $TEACH, $BASE));
show('y llegadas tarde?', ask('y llegadas tarde?', $s, $TEACH, $BASE));
