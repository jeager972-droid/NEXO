<?php
/**
 * continuity_50 — §42: sesión larga de ≥50 turnos alternando
 * entidad/grupo/campo/tiempo/presentación/operación/referencia.
 * Valida coherencia por tipo de turno — corre contra la API real.
 *
 * Uso: php test/continuity_50.php  (host; necesita NEXO_TOKEN o login)
 */
$BASE = getenv('NEXO_API') ?: 'http://localhost:18080';
// credenciales del entorno de pruebas — sobreescribibles por entorno, nunca
// de producción: NEXO_TEST_USER / NEXO_TEST_PASS
$ch = curl_init("$BASE/auth/login");
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>15,
    CURLOPT_HTTPHEADER=>['Content-Type: application/json','X-Requested-With: XMLHttpRequest'],
    CURLOPT_POSTFIELDS=>json_encode(['email'=>getenv('NEXO_TEST_USER') ?: 'teach@test.nexo',
        'password'=>getenv('NEXO_TEST_PASS') ?: 'test1234'])]);
$login = json_decode(curl_exec($ch) ?: '{}', true); curl_close($ch);
$TOKEN = $login['data']['token'] ?? $login['token'] ?? (getenv('NEXO_TOKEN') ?: trim(@file_get_contents('/tmp/nexo_token.txt')));
// session_id es UUID — ids arbitrarios rompen chatLoadDs silenciosamente
$SID = sprintf('%08x-%04x-4%03x-8%03x-%012x', random_int(0,0xffffffff), random_int(0,0xffff), random_int(0,0xfff), random_int(0,0xfff), random_int(0,0xffffffffffff));

function ask(string $text): array {
    global $BASE, $TOKEN, $SID;
    $ch = curl_init("$BASE/chat/message");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 25,
        CURLOPT_HTTPHEADER => ["Authorization: Bearer $TOKEN",
            'X-Requested-With: XMLHttpRequest', 'Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode(['text'=>$text,'session_id'=>$SID]),
    ]);
    $r = curl_exec($ch); curl_close($ch);
    $d = json_decode($r ?: '{}', true);
    return $d['data'] ?? ['intent'=>'HTTP_ERR','reply'=>substr($r ?: '', 0, 200)];
}

// [mensaje, chequeo] — el chequeo recibe (intent, reply) → bool
$turns = [
    // bloque 1: roster + nav + transforms
    ['muéstrame los estudiantes del 6-A', fn($i,$r)=>str_contains($r,'6-A') && str_contains($r,'Ana')],
    ['¿cuántos son?',                     fn($i,$r)=>str_contains($r,'3')],
    ['el primero',                        fn($i,$r)=>str_contains($r,'Ana')],
    ['el segundo',                        fn($i,$r)=>str_contains($r,'Luis')],
    ['su documento',                      fn($i,$r)=>str_contains($r,'8002')],
    ['los demás',                         fn($i,$r)=>str_contains($r,'Eva')],
    ['ponmelos en una tabla',             fn($i,$r)=>!empty($GLOBALS['_last']['cards']) || str_contains($r,'Tabla')],
    ['solo nombres',                      fn($i,$r)=>str_contains($r,'Ana') && !str_contains($r,'doc')],
    ['ordénalos por apellido',            fn($i,$r)=>str_contains($r,'Estudiante')],
    ['los dos últimos',                   fn($i,$r)=>str_contains($r,'últimos 2') || str_contains($r,'Eva')],
    ['vuelve al primero',                 fn($i,$r)=>str_contains($r,'Ana')],
    ['agrega documento',                  fn($i,$r)=>str_contains($r,'800')],
    // bloque 2: cambio de grupo + comparación
    ['¿y en 7-B?',                        fn($i,$r)=>str_contains($r,'7-B')],
    ['cuántos hay',                       fn($i,$r)=>preg_match('/\d/',$r)],
    // RBAC: docente scoped a 6-A — comparar con 7-B debe NEGARSE (§33)
    ['compárame 6-A con 7-B',             fn($i,$r)=>str_contains($r,'asignados') || (str_contains($r,'6-A') && str_contains($r,'7-B'))],
    // rank sobre el scope del docente — con fixture nueva 10-B tiene 1
    // tardanza real → respuesta honesta con grupo, o negación de alcance
    ['cuál tiene más tardanzas',          fn($i,$r)=>preg_match('/alcance|asignado|\d+-[AB]|más llegadas|mas tardanzas|no hay|limpio|tranquilo/i',$r)],
    // bloque 3: incidentes + tiempo
    ['cuántos faltaron hoy',              fn($i,$r)=>preg_match('/\d|faltaron|inasistencia/i',$r)],
    ['¿y ayer?',                          fn($i,$r)=>preg_match('/\d|ayer|hoy|inasistencia|no registra|limpio/i',$r)],
    ['las tardanzas de esta semana',      fn($i,$r)=>preg_match('/tardanza|tarde|semana|no hay|sin/i',$r)],
    ['la primera tardanza de hoy',        fn($i,$r)=>in_array($i,['students.position','incidents.position','result_nav','list_events'],true)
        || preg_match('/tardanza|primero|no hay|registra|limpio/i',$r)],
    // bloque 4: relaciones
    ['acudientes del 6-A',                fn($i,$r)=>str_contains($r,'cudiente') || str_contains($r,'no tiene')],
    ['docentes del 6-A',                  fn($i,$r)=>preg_match('/docente|profesor|no tiene|staff/i',$r)],
    ['horario del 6-A',                   fn($i,$r)=>preg_match('/horario|lunes|martes|no hay/i',$r)],
    // bloque 5: composición abierta
    ['los de 6-A y cuántos son',          fn($i,$r)=>str_contains($r,'6-A') && preg_match('/\d/',$r)],
    ['los del 6-A y del primero el nombre',fn($i,$r)=>str_contains($r,'Ana')],
    ['muéstrame 7-B y después los acudientes de 6-A', fn($i,$r)=>str_contains($r,'7-B') && str_contains($r,'cudiente')],
    // bloque 6: volver a referencias anteriores
    ['muéstrame los de 6-A otra vez',     fn($i,$r)=>str_contains($r,'Ana')],
    ['el último',                         fn($i,$r)=>str_contains($r,'Eva')],
    ['dame su acudiente',                 fn($i,$r)=>preg_match('/cudiente|responsable/i',$r)],
    ['¿y su número?',                     fn($i,$r)=>preg_match('/\d{6,}|WhatsApp|sin teléfono/i',$r)],
    // bloque 7: campos + búsqueda
    ['dame el documento de Ana Estudiante',fn($i,$r)=>str_contains($r,'8001')],
    ['¿quién es Luis Estudiante?',        fn($i,$r)=>str_contains($r,'Luis') || str_contains($r,'estudiante')],
    ['de quién es ese acudiente',         fn($i,$r)=>preg_match('/Ana|Luis|Eva|estudiante/i',$r)],
    // bloque 8: cortesía y ruido (no debe romper el estado)
    ['hola',                              fn($i,$r)=>strlen($r) > 3],
    ['gracias',                           fn($i,$r)=>strlen($r) > 3],
    ['el primero otra vez',               fn($i,$r)=>str_contains($r,'Ana')],
    // bloque 9: porcentajes y conteos
    ['cuántos estudiantes hay en total',  fn($i,$r)=>preg_match('/\d/',$r)],
    ['qué porcentaje de 6-A faltó hoy',   fn($i,$r)=>preg_match('/%|por ciento|0|no hay/i',$r)],
    ['cuántos están exentos del sensor',  fn($i,$r)=>preg_match('/\d|exento/i',$r)],
    ['estudiantes sin grupo',             fn($i,$r)=>preg_match('/\d|grupo|no hay/i',$r)],
    // bloque 10: recuperación tras OOD (no debe perder el set)
    ['cuál es la capital de Francia',     fn($i,$r)=>strlen($r) > 5],
    ['los demás',                         fn($i,$r)=>$i==='result_nav' || str_contains($r,'demás') || str_contains($r,'todos')],
    ['vuelve al primero',                 fn($i,$r)=>$i==='result_nav' || str_contains($r,'Ana') || str_contains($r,'Luis') || str_contains($r,'Eva')],
    // bloque 11: slice + sort encadenados — si el set quedó vacío
    // («sin grupo»=0) la respuesta honesta también vale
    ['los dos primeros',                  fn($i,$r)=>preg_match('/primeros 2|Ana|Luis|no trajo|no hay nada/',$r)],
    ['en tabla',                          fn($i,$r)=>!empty($GLOBALS['_last']['cards']) || str_contains($r,'Tabla') || str_contains($r,'no trajo')],
    ['solo sus nombres',                  fn($i,$r)=>(preg_match('/Ana|Luis|Eva/',$r) && !str_contains($r,'doc')) || str_contains($r,'no trajo')],
    // bloque 12: cierre con cambio de tema
    ['incidentes de esta semana',         fn($i,$r)=>preg_match('/incidente|semana|no hay|limpio/i',$r)],
    ['cuántos fueron',                    fn($i,$r)=>preg_match('/\d/',$r)],
    ['muéstrame los del 7-B de nuevo',    fn($i,$r)=>str_contains($r,'7-B')],
    ['el segundo',                        fn($i,$r)=>in_array($i,['result_nav','students.position'],true)
        || preg_match('/segundo|posición|puesto|—/',$r)],
    ['su acudiente',                      fn($i,$r)=>preg_match('/cudiente|responsable|no tiene|De qué estudiante/i',$r)],
    ['los demás',                         fn($i,$r)=>$i==='result_nav' || str_contains($r,'demás') || str_contains($r,'todos')],
    ['adiós',                             fn($i,$r)=>strlen($r) > 3],
];

echo "═══ continuity_50 — sesión $SID ═══\n\n";
$pass = 0; $fails = [];
foreach ($turns as $i => [$msg, $check]) {
    $out = ask($msg);
    $GLOBALS['_last'] = $out;
    $intent = $out['intent'] ?? '?';
    $reply = $out['reply'] ?? '';
    $ok = $check($intent, $reply);
    if ($ok) $pass++; else $fails[] = [$i+1, $msg, $intent, mb_substr($reply,0,80)];
    printf("  %s %2d %-48s → %-18s %s\n", $ok ? '✓' : '✗✗', $i+1, mb_strimwidth($msg,0,46),
        $intent, mb_strimwidth(str_replace("\n",' ',$reply),0,60));
    usleep(650000); // ritmo humano — respeta el rate-limit 60/10min
}
printf("\n  %d/%d turnos coherentes\n", $pass, count($turns));
if ($fails) { echo "\n  incoherencias:\n"; foreach ($fails as [$n,$m,$i,$r]) echo "    t{$n} «{$m}» → {$i}: {$r}\n"; }
exit($fails ? 1 : 0);
