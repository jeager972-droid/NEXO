<?php
/* ============================================================================
 * golden_live — §15 conversación golden, prueba BLOQUEANTE.
 * Los 9 turnos verbatim contra la API real, una sola sesión:
 *   tabla 10A → acudiente del primero → «del chico de 10A» → conteo →
 *   grupos colegio → mis grupos → inasistencias hoy → reacción → celular
 *   del acudiente de Sofía Herrera Ruiz.
 * Un solo fallo bloquea READY.
 *
 * Uso: php test/golden_live.php   (stack nexo-test en :18080)
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
$sid = sprintf('%08x-%04x-4%03x-8%03x-%012x', random_int(0,0xffffffff), random_int(0,0xffff),
    random_int(0,0xfff), random_int(0,0xfff), random_int(0,0xffffffffffff));

$TURNS = [
    // t1 — tabla de alumnos de 10A → result-set R1
    ['hola nexus dame la tabla de alumnos de 10A',
     fn($r,$o)=> (str_contains($r,'10-A') || !empty($o['cards']))
             && !empty($o['_ds']['last_result']['items'])],
    // t2 — acudiente del primero de R1 (Tomás Castaño → Acudiente Prueba)
    ['quien es el acudiente del primero?',
     fn($r,$o)=> str_contains($r,'cudiente') && !str_contains($r,'Tabla completa')
             && !str_contains($r,'¿De qué estudiante')],
    // t3 — «del chico de 10A» re-ancla contra R1, NUNCA re-lista el roster
    ['Del chico de 10A',
     fn($r,$o)=> !str_contains($r,'Tabla completa')
             && (str_contains($r,'10-A') || str_contains($r,'Tomás') || str_contains($r,'Pedro')
                 || str_contains($r,'cuál') || str_contains($r,'¿Cuál'))],
    // t4 — conteo del grupo (9 con Sofía)
    ['cuantos alumnos hay en 10A?',
     fn($r)=> str_contains($r,'9') || str_contains($r,'estudiantes')],
    // t5 — todos los grupos del colegio (alcance escolar: incluye 7-B,
    // que el docente NO tiene asignado — distingue «todos» de «míos»)
    ['todos los grupos del colegio',
     function($r,$o){ $flat=json_encode($o['cards'] ?? [], JSON_UNESCAPED_UNICODE);
        return str_contains($flat,'10-A') && str_contains($flat,'7-B'); }],
    // t6 — mis grupos (teacher_group_access: 6-A, 10-A, 10-B, 8-C — sin 7-B)
    ['que grupos estan a mi cargo?',
     function($r,$o){ $flat=json_encode($o['cards'] ?? [], JSON_UNESCAPED_UNICODE);
        return str_contains($flat,'10-A') && str_contains($flat,'10-B')
            && !str_contains($flat,'7-B'); }],
    // t7 — inasistencias de 10-A hoy = 1
    ['cuanto de 10a inasistieron hoy',
     fn($r)=> (str_contains($r,'1') && (str_contains($r,'inasist') || str_contains($r,'falt'))) ],
    // t8 — reacción: reconocimiento coherente, nunca oos crudo
    ['excelente noticia eso?',
     fn($r,$o)=> strlen($r) > 10 && !str_contains($r,'no tengo datos confiables')],
    // t9 — celular del acudiente de Sofía Herrera Ruiz (10-A)
    ['si, traeme el numero de celular del acudiente de sofia herrera ruiz de 10a',
     fn($r)=> str_contains($r,'+573000000004') || (str_contains($r,'Paola') && str_contains($r,'cudiente'))],
];

$tot=0; $pass=0; $fails=[];
echo "╔═ GOLDEN §15 (sesión " . substr($sid,0,8) . ")\n";
foreach ($TURNS as $i => [$say,$check]) {
    $out = ask($sid, $say);
    $reply = $out['reply'] ?? '';
    $scp = $out['_interpretation']['scp'] ?? null;
    $ok = $check($reply, $out);
    $tot++; if ($ok) $pass++; else $fails[] = [$i+1,$say,$out['intent'] ?? '?',mb_substr($reply,0,100)];
    printf("  %s t%d «%s»\n     task=%s intent=%s\n     reply: %s\n",
        $ok?'✓':'✗',$i+1,mb_strimwidth($say,0,52),
        $scp['task'] ?? '-', $out['intent'] ?? '?', mb_strimwidth(str_replace("\n",' ',$reply),0,110));
    usleep(400000);
}
echo "\n═══ golden_live: {$pass}/{$tot} turnos PASS ═══\n";
foreach ($fails as [$i,$say,$int,$r]) echo "  ✗ t{$i} «{$say}» → {$int}: {$r}\n";
exit($pass === $tot ? 0 : 1);
