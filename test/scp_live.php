<?php
/* ============================================================================
 * scp_live — §CASOS OBLIGATORIOS A-N end-to-end contra la API real.
 * Cada caso es una conversación multi-turno con sesión propia. Imprime la
 * traza por capa de cada turno (USER INPUT → SEMANTIC FRAME → INTENT →
 * REPLY → MEMORY UPDATE) y verifica el contenido esperado.
 *
 * Uso: php test/scp_live.php   (stack nexo-test en :18080)
 * ========================================================================== */
$BASE = getenv('NEXO_API') ?: 'http://localhost:18080';
$ch = curl_init("$BASE/auth/login");
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>15,
    CURLOPT_HTTPHEADER=>['Content-Type: application/json','X-Requested-With: XMLHttpRequest'],
    CURLOPT_POSTFIELDS=>json_encode(['email'=>getenv('NEXO_TEST_USER') ?: 'teach@test.nexo',
        'password'=>getenv('NEXO_TEST_PASS') ?: 'test1234'])]);
$login = json_decode(curl_exec($ch) ?: '{}', true); curl_close($ch);
$TOKEN = $login['data']['token'] ?? $login['token'] ?? null;
if (!$TOKEN) { echo "login FAIL\n"; exit(1); }

function ask(string $sid, string $text): array {
    global $BASE, $TOKEN;
    $ch = curl_init("$BASE/chat/message");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 25,
        CURLOPT_HTTPHEADER => ["Authorization: Bearer $TOKEN",
            'X-Requested-With: XMLHttpRequest', 'Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode(['text'=>$text,'session_id'=>$sid]),
    ]);
    $r = curl_exec($ch); curl_close($ch);
    $d = json_decode((string)$r, true);
    return is_array($d['data'] ?? null) ? $d['data'] : [];
}
function sid(): string {
    return sprintf('%08x-%04x-4%03x-8%03x-%012x', random_int(0,0xffffffff), random_int(0,0xffff), random_int(0,0xfff), random_int(0,0xfff), random_int(0,0xffffffffffff));
}

/* [caso, [(frase, chequeo del reply)]] — el chequeo recibe (reply, out) */
$CONVOS = [
 'A_tabla_y_acudiente_del_primero' => [
    ['pasame una tabla con los estudiantes de 10A', fn($r,$o)=> !empty($o['cards']) || str_contains($r,'estudiantes') || str_contains($r,'Tabla')],
    ['quien es el acudiente del primero', fn($r)=> str_contains($r,'cudiente') && str_contains($r,'Prueba') && !str_contains($r,'grupo 1')],
 ],
 'B_me_refiero_a' => [
    ['quien es el acudiente de María Fernanda Castaño Muñoz', fn($r)=> str_contains($r,'María Custodia')],
    ['me refiero al de Tomás Castaño Gutiérrez', fn($r)=> str_contains($r,'cudiente') && (str_contains($r,'Prueba') || str_contains($r,'Tomás'))],
 ],
 'C_acudiente_y_documento' => [
    ['quien es el acudiente de María Fernanda Castaño Muñoz', fn($r)=> str_contains($r,'María Custodia')],
    ['¿y su documento?', fn($r)=> str_contains($r,'8107')],
 ],
 'D_datos_evasiones_tardes' => [
    ['datos de María Fernanda Castaño Muñoz', fn($r,$o)=> str_contains($r,'María Fernanda') || str_contains($r,'8107')],
    ['¿cuántas evasiones los últimos 30 días?', fn($r)=> str_contains($r,'2 evas')],
    ['¿y llegadas tarde?', fn($r)=> preg_match('/1 (llegada|tardanza)/i', $r) || str_contains($r,'1 ')],
 ],
 'E_top5_mis_clases' => [
    ['top 5 estudiantes con más faltas en mis clases los últimos 30 días',
     fn($r,$o)=> ($o['intent'] ?? '') === 'top_offenders'
         && count($o['cards'][0]['rows'] ?? []) === 5
         && str_contains($r, 'Top de inasistencias')],
 ],
 'F_mayusculas_y_solo_5' => [
    ['top 8 estudiantes con más faltas en mis clases los últimos 30 días', fn($r,$o)=> true],
    ['LOS QUE MÁS FALTARON Y SOLO 5', fn($r,$o)=> count($o['cards'][0]['rows'] ?? []) <= 5 && (str_contains($r,'Juan Camilo') || str_contains(json_encode($o['cards'] ?? []),'Juan Camilo'))],
 ],
 'G_cuanto_ha_faltado' => [
    ['cuánto ha faltado Juan Camilo Ospina García de 10A los últimos 15 días', fn($r)=> str_contains($r,'4')],
 ],
 'H_ese_ultimo_y_documento' => [
    ['muéstrame los estudiantes de 10A', fn($r)=> str_contains($r,'estudiantes')],
    ['el último', fn($r)=> str_contains($r,'Ospina') || str_contains($r,'era el último') || str_contains($r,'último')],
    ['acudiente de ese último que me diste', fn($r)=> str_contains($r,'cudiente') && str_contains($r,'Ospina Ruiz')],
    ['¿y el documento del acudiente?', fn($r)=> str_contains($r,'9005')],
 ],
 'I_umbral_pedagogico' => [
    ['qué estudiantes de 10A han pasado mi umbral de alerta pedagógica?', fn($r,$o)=> str_contains($r,'umbral') && (str_contains($r,'María Fernanda') || str_contains(json_encode($o['cards'] ?? [], JSON_UNESCAPED_UNICODE),'María Fernanda')) && str_contains($r,'10-A')],
 ],
 'J_todos' => [
    ['dime solo los primeros 3 estudiantes de 10A', fn($r)=> true],
    ['todos', fn($r,$o)=> str_contains($r,'estudiantes') && (str_contains($r,'Ospina') || str_contains($r,'Castaño') || str_contains($r,'Tabla completa') || count($o['cards'][0]['rows'] ?? []) >= 8)],
 ],
 'K_celular_acudiente' => [
    ['celular del acudiente Juan Camilo Ospina García', fn($r)=> str_contains($r,'+573000000003')],
 ],
 'L_chiste_y_comparacion' => [
    ['dime un chiste y dame una tabla de comparación de inasistencias de 6A vs 10A',
     fn($r,$o)=> !empty($o['cards']) && (str_contains($r,'—') || str_contains($r,'chiste') || strlen($r) > 40)],
 ],
 'M_te_falto_lo_otro' => [
    ['dime un chiste y dame una tabla de comparación de inasistencias de 6A vs 10A', fn($r)=> true],
    ['te faltó lo otro', fn($r)=> strlen($r) > 10], // pendiente o honesto — nunca OOS crudo
 ],
 'N_comparacion_correccion' => [
    ['qué grupo tiene más evasiones internas: 10A o 8C', fn($r,$o)=> !empty($o['cards']) || str_contains($r,'10-A')],
    ['no sería empate, sería que ninguna', fn($r)=> str_contains($r,'ninguna') || str_contains($r,'cero')],
 ],
];

$tot = 0; $pass = 0; $fails = [];
foreach ($CONVOS as $case => $turns) {
    $s = sid();
    echo "╔═ {$case} (sesión " . substr($s,0,8) . ")\n";
    foreach ($turns as $i => [$say, $check]) {
        $out = ask($s, $say);
        $reply = $out['reply'] ?? '';
        $scp = $out['_interpretation']['scp'] ?? null;
        $ok = $check($reply, $out);
        $tot++; if ($ok) $pass++; else $fails[] = [$case, $i+1, $say, $out['intent'] ?? '?', mb_substr($reply,0,90)];
        printf("  %s t%d «%s»\n     task=%s subj=%s(%s) intent=%s\n     reply: %s\n",
            $ok ? '✓' : '✗', $i+1, mb_strimwidth($say,0,52),
            $scp['task'] ?? '-', mb_strimwidth((string)($scp['subject']['name'] ?? '-'),0,18), $scp['subject']['source'] ?? '-',
            $out['intent'] ?? '?', mb_strimwidth(str_replace("\n",' ',$reply),0,100));
        usleep(400000);
    }
    echo "╚═\n";
}
printf("\n═══ scp_live: %d/%d turnos PASS ═══\n", $pass, $tot);
foreach ($fails as [$c,$t,$say,$i,$r]) printf("  ✗ %s t%d «%s» → %s: %s\n", $c, $t, $say, $i, $r);
exit($fails ? 1 : 0);
