<?php
/**
 * =============================================================================
 * nexus/nexus_nlu.php — Puente NLU de Nexus.
 * =============================================================================
 *
 * La interpretación es del LLM (nexus/nexus_llm.php — parser semántico sobre
 * API compatible-OpenAI). Este archivo conserva solo la maquinaria
 * determinista: normalización, slot-filling (nxSlots), smalltalk, RBAC
 * (nxAllowed) y el DSM conversacional (nxDialogueResolve).
 *
 *   1. LLM parser   → intent + entidades (taxonomía validada)
 *   2. nxSlots      → slots estructurales deterministas (grupo/módulo/fechas)
 *   3. Fallback     → confianza < 0.66 o LLM caído ⇒ 'out_of_scope' honesto
 *                     (flujo de clarificación), nunca un handler adivinado.
 *
 * Retorna: ['intent','confidence','entities','top3','source']
 */

require_once __DIR__ . '/nexus_llm.php';

const NX_NLU_THRESHOLD = 0.65;   // umbral estricto por nivel (spec: 65%)

/** Día civil institucional — el colegio opera en America/Bogota; «hoy» y
 *  «ayer» deben ser el día local, no el día UTC (desfase −5h). */
function nxToday(int $minusDays = 0): string {
    return (new DateTime('today', new DateTimeZone('America/Bogota')))
        ->modify('-' . max(0, $minusDays) . ' days')->format('Y-m-d');
}

/* ---------------------------------------------------------------------------
 * Normalización de texto
 * ------------------------------------------------------------------------- */
function nxNorm(string $t): string {
    $t = mb_strtolower(trim($t), 'UTF-8');
    $t = strtr($t, ['á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ü'=>'u','ñ'=>'n',
                    'à'=>'a','è'=>'e','ì'=>'i','ò'=>'o','ù'=>'u']);
    $t = preg_replace('/[¿?¡!.,;:\(\)"\'«»]/u', ' ', $t);
    // §27 tolerancia ortográfica — transposiciones típicas, conservadoras
    // (nunca corrige nombres propios: solo vocabulario del dominio)
    static $fix = ['presnetes'=>'presentes','presntes'=>'presentes','precentes'=>'presentes',
        'asistensia'=>'asistencia','asisitencia'=>'asistencia','inasitencia'=>'inasistencia',
        'inasistensia'=>'inasistencia','inasistencais'=>'inasistencias','estudaintes'=>'estudiantes',
        'alumons'=>'alumnos','tardansas'=>'tardanzas','evacione'=>'evasion','evasioness'=>'evasiones',
        'permisso'=>'permiso','documneto'=>'documento','docuemnto'=>'documento','ceduala'=>'cedula',
        'acudinte'=>'acudiente','acudietne'=>'acudiente','citasion'=>'citacion','segumiento'=>'seguimiento'];
    $t = strtr(' ' . $t . ' ', array_combine(
        array_map(fn($k) => ' ' . $k . ' ', array_keys($fix)),
        array_map(fn($v) => ' ' . $v . ' ', $fix)));
    return trim(preg_replace('/\s+/', ' ', $t));
}

/** Regiones colombianas + extranjero — nunca son estudiantes. */
function nxRegions(string $q): array {
    static $depts = ['colombia','bogota','medellin','cali','barranquilla','cartagena',
        'bucaramanga','pereira','manizales','armenia','ibague','neiva','villavicencio',
        'villavo','cucuta','pasto','santa marta','valledupar','monteria','sincelejo',
        'riohacha','yopal','arauca','mocoa','florencia','leticia','quibdo','inirida',
        'mitu','puerto carreno','tunja','popayan','san andres','guatavita',
        'villa de leyva','mompox','palenque','san jose del guaviare',
        'amazonas','antioquia','atlantico','bolivar','boyaca','caldas','caqueta',
        'casanare','cauca','cesar','choco','cordoba','cundinamarca','guainia',
        'guaviare','huila','la guajira','guajira','magdalena','meta','narino',
        'norte de santander','putumayo','quindio','risaralda',
        'san andres y providencia','santander','sucre','tolima','valle del cauca',
        'valle','vaupes','vichada'];
    static $foreign = ['francia','japon','alemania','italia','espana','estados unidos',
        'mexico','argentina','brasil','chile','peru','venezuela','ecuador','bolivia',
        'paraguay','uruguay','inglaterra','portugal','canada','china','rusia','corea',
        'india','australia','holanda','suiza','belgica','suecia','noruega','dinamarca',
        'finlandia','irlanda','polonia','turquia','israel','egipto','arabia saudita',
        'emiratos','qatar','grecia','roma','cartago','europa','asia','africa',
        'oceania','antartida','artico','latinoamerica','everest','nilo','sahara',
        'himalaya','vaticano','marte','venus','luna','jupiter','saturno','galaxia','universo'];
    $found = [];
    foreach (array_merge($depts, $foreign) as $r) {
        if (preg_match('/\b' . preg_quote($r) . '\b/u', $q)) $found[$r] = true;
    }
    return array_keys($found);
}

function nxIsForeign(string $r): bool {
    return !in_array($r, ['colombia','bogota','medellin','cali','barranquilla','cartagena',
        'bucaramanga','pereira','manizales','armenia','ibague','neiva','villavicencio',
        'villavo','cucuta','pasto','santa marta','valledupar','monteria','sincelejo',
        'riohacha','yopal','arauca','mocoa','florencia','leticia','quibdo','inirida',
        'mitu','puerto carreno','tunja','popayan','san andres','guatavita',
        'villa de leyva','mompox','palenque','san jose del guaviare',
        'amazonas','antioquia','atlantico','bolivar','boyaca','caldas','caqueta',
        'casanare','cauca','cesar','choco','cordoba','cundinamarca','guainia',
        'guaviare','huila','la guajira','guajira','magdalena','meta','narino',
        'norte de santander','putumayo','quindio','risaralda',
        'san andres y providencia','santander','sucre','tolima','valle del cauca',
        'valle','vaupes','vichada'], true);
}

/* ---------------------------------------------------------------------------
 * Clasificación — parser LLM (nexus_llm.php).
 * Sin NLU_LLM_KEY o con el proveedor caído → null → out_of_scope honesto.
 * ------------------------------------------------------------------------- */
/** Clasificación de un solo texto.
 *  1. Fixture replay (NX_CLASSIFY_FIXTURE): snapshot JSON de respuestas del
 *     LLM — suites deterministas offline sin gastar cuota (ver
 *     test/gen_llm_fixture.php).
 *  2. NX_CLASSIFY_LOG: registra el texto normalizado — recogida de frases
 *     de suites antes de regenerar el fixture.
 *  3. Parser LLM real (nxLlmClassify). nxSlots manda en slots estructurales.
 */
function nxClassifyCore(string $text, ?array $ctx = null): ?array {
    static $fxMap = []; static $fxPath = null;
    $f = getenv('NX_CLASSIFY_FIXTURE') ?: '';
    if ($f !== $fxPath) {  // env puede cambiar entre llamadas dentro de una suite
        $fxPath = $f;
        $fxMap = ($f !== '' && is_file($f))
            ? (json_decode((string)file_get_contents($f), true) ?: []) : [];
    }
    if ($log = getenv('NX_CLASSIFY_LOG')) {
        @file_put_contents($log, nxNorm($text) . "\n", FILE_APPEND);
    }
    $r = null;
    $norm = nxNorm($text);
    if ($fxMap) {
        if (isset($fxMap[$norm])) $r = $fxMap[$norm] + ['source' => 'fixture'];
    }
    if (!$r) {
        // Capa determinista: patrones inequívocos no gastan cuota del LLM
        // (Groq: 8K tokens/min). Con nombre propio o frase larga manda el LLM
        // — extrae personas y matices mejor. Si el LLM no responde (429,
        // timeout, sin cuota) la regla sostiene el turno en vez de un
        // out_of_scope ciego.
        $rule = nxRuleClassify($norm);
        $long = str_word_count($norm, 0, 'áéíóúñü0123456789') > 14;
        if ($rule && !empty($rule['strong']) && !$long) {
            $r = $rule + ['source' => 'rules'];
        } else {
            $r = nxLlmClassify($text, $ctx);
            if (!$r && $rule) $r = $rule + ['source' => 'rules_fallback'];
            elseif ($r && ($r['intent'] ?? '') === 'out_of_scope' && $rule && ($rule['domain'] ?? '') === 'formal'
                    && $rule['confidence'] >= 0.85)
                $r = $rule + ['source' => 'rules_rescue'];
        }
    }
    if ($r) {
        $r['entities'] = array_merge(
            $r['entities'] ?? [],
            array_filter(nxSlots(nxNorm($text)), fn($v) => $v !== null && $v !== '')
        );
        // el LLM puede «ver» un nombre donde solo hay marcadores
        // («ese grupo a los»): si ningún token del candidato es palabra
        // de contenido, no es persona — nxSlots manda en slots estructurales
        if (!empty($r['entities']['student'])) {
            $tok = preg_split('/\s+/u', trim((string)$r['entities']['student']));
            $junk = array_diff($tok, nxStudentStopwords(),
                ['el','la','los','las','un','una','del','de','al','a','en',
                 'ese','esa','esos','esas','este','esta','estos','estas',
                 'aquel','aquella','aquellos','aquellas','grupo','grupos',
                 'salon','curso','cursos','grado','estudiante','estudiantes',
                 'alumno','alumna','alumnos','alumnas','mismo','misma',
                 'pelado','pelada','pelados','peladas','muchacho','muchacha',
                 'muchachos','muchachas','nino','nina','chico','chica','menor']);
            if (!$junk) unset($r['entities']['student']);
        }
        // backstop determinista post-LLM: el LLM «lee» «muestra la tabla
        // usuarios» como staff_lookup, pero es probe de esquema — un
        // veto de seguridad no se negocia con la confianza del modelo
        if (($r['intent'] ?? '') !== 'security_probe'
            && preg_match('/\b(la tabla|las tablas|la base de datos|la bd|el esquema|el schema|el sql|dump|estructura de la tabla)\s+(de |del |de la |de los |de las )?(usuarios|users|empleados|personal|registros|logs|internos?|credenciales|contrasen\w*|claves|sistema)\b|\btabla\s+(usuarios|users|empleados|registros|logs)\b/u', $norm)) {
            $r = ['intent' => 'security_probe', 'confidence' => 1.0,
                  'entities' => $r['entities'] ?? [],
                  'top3' => [], 'source' => 'veto:table_probe'];
        }
    }
    return $r;
}

function nxClassify(string $text, ?array $ctx = null): array {
    // multi-intención — cada segmento se clasifica por separado
    $norm = nxNorm($text);
    // «/» con espacios también separa consultas («tardanzas por día / evasiones
    // por grupo»); sin espacios es fecha («24/09») y no parte nada
    $segments = array_values(array_filter(preg_split('/\s+(?:y|ademas|además|tambien|también|e)\s+|,\s*|\s+\/\s+/u', $norm), fn($s)=>mb_strlen(trim($s))>2));
    // «de 10A y 6A», «entre sexto y octavo» — el conector une dos GRUPOS
    // de una misma comparación, no dos turnos: se re-anexa al segmento
    $grpSeg = '(?:\d{1,2}\s*[a-e]|[a-e]\s*\d{1,2}|sexto|septimo|octavo|noveno|decimo|undecimo|primero|segundo|tercero|cuarto|quinto|transicion|jardin|kinder|preescolar|primaria|bachillerato|media)';
    if (count($segments) > 1) {
        $mg = [];
        foreach ($segments as $sg) {
            if ($mg && preg_match('/\b(?:de|del|en|los|las)\s+' . $grpSeg . '\s*$/u', $mg[count($mg)-1])
                && preg_match('/^' . $grpSeg . '\b/u', $sg))
                $mg[count($mg)-1] .= ' y ' . $sg;
            // «alta, media y baja» — el último tramo de una enumeración
            // coordinada no es cláusula nueva («prioridad alta, media y
            // baja»): un ordinal/cualidad suelto se re-anexa
            elseif ($mg && preg_match('/^(?:baj[oa]s?|alt[oa]s?|medi[oa]s?|primer[oa]s?|segund[oa]s?|tercer[oa]s?|cuart[oa]s?|quint[oa]s?|últim[oa]s?|ultim[oa]s?|penúltim[oa]s?|penultim[oa]s?|resto|demás|demas)\b/u', $sg))
                $mg[count($mg)-1] .= ' y ' . $sg;
            else $mg[] = $sg;
        }
        $segments = $mg;
    }
    if (count($segments) > 1) {
        $parts = [];
        foreach (array_slice($segments,0,4) as $seg) {
            $p = nxClassifyCore($seg, $ctx);
            if (!$p) continue;
            // dedupe por intención+segmento — mismo intent con params distintos cuenta doble
            if (($p['confidence'] ?? 0) >= 0.55 && !in_array($seg, array_column($parts,'text'), true))
                $parts[] = $p + ['text' => $seg];
        }
        if (count($parts) > 1) {
            usort($parts, fn($a,$b)=> (($a['domain']??'informal')!=='formal') <=> (($b['domain']??'informal')!=='formal') ?: $b['confidence'] <=> $a['confidence']);
            return ['domain'=>'multi','intent'=>$parts[0]['intent'],'confidence'=>$parts[0]['confidence'],
                    'top3'=>$parts[0]['top3']??[],'entities'=>$parts[0]['entities']??[],'parts'=>$parts,'source'=>'multi'];
        }
    }
    $r = nxClassifyCore($text, $ctx)
        ?? ['intent' => 'out_of_scope', 'confidence' => 0.0,
            'entities' => nxSlots(nxNorm($text)), 'top3' => [], 'source' => 'none'];
    // entidades: siempre fusionar con nxSlots — el parser no extrae
    // module/field/from/to/range_label (eso lo completa PHP)
    $r['entities'] = array_merge(nxSlots($norm), $r['entities'] ?? []);
    // contrato amplio del parser → slots internos del motor
    $e =& $r['entities'];
    if (!empty($e['nav']) && empty($e['_nav'])) {
        // 'last' no es nav directo: el DSM lo resuelve a nth:N con el conteo
        // del set activo; 'others/another' mapean al vocabulario del motor
        $navMap = ['first'=>'first','others'=>'rest','rest'=>'rest','all'=>'all',
                   'another'=>'next','next'=>'next','prev'=>'prev','table'=>'table',
                   'count'=>'count','name'=>'name'];
        if ($e['nav'] === 'last') { $e['position'] = 'last'; }
        elseif (preg_match('/^nth:(\d+)$/', (string)$e['nav'], $m)) $e['_nav'] = 'nth:'.$m[1];
        elseif (isset($navMap[$e['nav']])) $e['_nav'] = $navMap[$e['nav']];
    }
    // 'last' queda como position — chat.php lo convierte a nth:N con el
    // conteo del set activo (aquí no está disponible)
    if (!empty($e['position']) && $e['position'] !== 'last' && empty($e['_nav']))
        $e['_nav'] = 'nth:' . (int)$e['position'];
    if (!empty($e['presentation'])) $e['_presentation'] = $e['presentation'];
    if (!empty($e['export_format'])) $e['_export_format'] = $e['export_format'];
    if (!empty($e['compare'])) $e['_compare'] = $e['compare'];
    if (!empty($e['relation'])) $e['_ref'] = $e['_ref'] ?? $e['relation'];
    if (!empty($e['op'])) $e['_op'] = $e['_op'] ?? $e['op'];
    unset($e);
    // «excepto el 8A» — el parser puede traer group=8A que es exclusión
    if (isset($r['entities']['_except'])
        && ($r['entities']['group'] ?? null) === $r['entities']['_except'])
        unset($r['entities']['group']);
    if ($r['confidence'] < NX_NLU_THRESHOLD) {
        // la propuesta del parser NO se tira: el DSM la usa cuando el turno
        // es un fragmento dependiente que copió el tema del contexto
        if ($r['intent'] !== 'out_of_scope') $r['proposed_intent'] = $r['intent'];
        $r['intent'] = 'out_of_scope';
        $r['fallback'] = true;
    }
    return $r;
}

/* ---------------------------------------------------------------------------
 * Slot-filling PHP — extracción determinista de entidades/fechas.
 * ------------------------------------------------------------------------- */
/* ---------------------------------------------------------------------------
 * Rangos temporales — calendario civil de la institución (America/Bogota).
 * «ayer» es SOLO ayer, «la semana pasada» es lunes–domingo anterior, «el mes
 * pasado» es el mes calendario anterior. `days` se conserva como pista de
 * amplitud (compatibilidad con el DSM/herencia); la ventana real es from/to.
 * ------------------------------------------------------------------------- */
const NX_MONTHS = ['enero'=>1,'febrero'=>2,'marzo'=>3,'abril'=>4,'mayo'=>5,'junio'=>6,'julio'=>7,
    'agosto'=>8,'septiembre'=>9,'setiembre'=>9,'octubre'=>10,'noviembre'=>11,'diciembre'=>12];
const NX_WEEKDAYS = ['lunes'=>1,'martes'=>2,'miercoles'=>3,'jueves'=>4,'viernes'=>5,'sabado'=>6,'domingo'=>7];
const NX_MONTH_NAMES = [1=>'enero','febrero','marzo','abril','mayo','junio','julio','agosto',
    'septiembre','octubre','noviembre','diciembre'];

function nxTodayDt(): DateTimeImmutable {
    return new DateTimeImmutable('today', new DateTimeZone('America/Bogota'));
}

/** Fecha explícita «24 de septiembre» → la más reciente no futura. */
function nxDateOf(int $day, int $month, ?int $year = null): ?DateTimeImmutable {
    $today = nxTodayDt();
    $y = $year ?? (int)$today->format('Y');
    if (!checkdate($month, $day, $y)) return null;
    $d = $today->setDate($y, $month, $day);
    if ($year === null && $d > $today) $d = $d->modify('-1 year');
    return $d;
}

function nxRangeOut(DateTimeImmutable $from, DateTimeImmutable $to, int $days, string $label): array {
    return ['days' => $days, 'from' => $from->format('Y-m-d'), 'to' => $to->format('Y-m-d'), 'range_label' => $label];
}

/**
 * Patrones temporales en orden de especificidad. Cada uno devuelve el rango
 * o null. Compartido por nxTimeRange (el primero que aplica) y por
 * nxTimeRanges (todos los mencionados — comparaciones «ayer vs hoy»).
 */
function nxTimePatterns(): array {
    $wd = implode('|', array_keys(NX_WEEKDAYS));
    $mo = implode('|', array_keys(NX_MONTHS));
    return [
        // «del 1 al 15 de septiembre», «entre el 3 y el 10 de agosto»
        ['/\b(?:del|desde el|entre el)\s+(\d{1,2})\s+(?:al|hasta el|y el)\s+(\d{1,2})\s+de\s+(' . $mo . ')(?:\s+(?:de|del)\s+(\d{4}))?\b/u',
            function ($m) {
                $mon = NX_MONTHS[$m[3]]; $yr = !empty($m[4]) ? (int)$m[4] : null;
                $a = nxDateOf((int)$m[1], $mon, $yr); $b = nxDateOf((int)$m[2], $mon, $yr);
                if (!$a || !$b) return null;
                if ($a > $b) [$a, $b] = [$b, $a];
                $days = (int)$a->diff($b)->days + 1;
                return nxRangeOut($a, $b, $days, "del {$m[1]} al {$m[2]} de {$m[3]}");
            }],
        // «desde el 15 de septiembre»
        ['/\bdesde el\s+(\d{1,2})\s+de\s+(' . $mo . ')\b/u',
            function ($m) {
                $a = nxDateOf((int)$m[1], NX_MONTHS[$m[2]]); if (!$a) return null;
                $t = nxTodayDt();
                return nxRangeOut($a, $t, (int)$a->diff($t)->days, "desde el {$m[1]} de {$m[2]}");
            }],
        // «el 24 de septiembre»
        ['/\b(\d{1,2})\s+de\s+(' . $mo . ')(?:\s+(?:de|del)\s+(\d{4}))?\b/u',
            function ($m) {
                $d = nxDateOf((int)$m[1], NX_MONTHS[$m[2]], !empty($m[3]) ? (int)$m[3] : null);
                if (!$d) return null;
                $days = (int)$d->diff(nxTodayDt())->days;
                return nxRangeOut($d, $d, $days, "el {$m[1]} de {$m[2]}");
            }],
        // «24/09», «24/09/2026»
        ['/\b(\d{1,2})\/(\d{1,2})(?:\/(\d{2,4}))?\b/u',
            function ($m) {
                $yr = !empty($m[3]) ? ((int)$m[3] < 100 ? 2000 + (int)$m[3] : (int)$m[3]) : null;
                $d = nxDateOf((int)$m[1], (int)$m[2], $yr); if (!$d) return null;
                return nxRangeOut($d, $d, (int)$d->diff(nxTodayDt())->days, $d->format('d/m/Y'));
            }],
        ['/\b(anteayer|antier|antes de ayer)\b/u',
            fn($m) => nxRangeOut(nxTodayDt()->modify('-2 days'), nxTodayDt()->modify('-2 days'), 2, 'anteayer')],
        ['/\bdesde ayer\b/u',
            fn($m) => nxRangeOut(nxTodayDt()->modify('-1 day'), nxTodayDt(), 1, 'desde ayer')],
        ['/\bayer\b/u',
            fn($m) => nxRangeOut(nxTodayDt()->modify('-1 day'), nxTodayDt()->modify('-1 day'), 1, 'ayer')],
        ['/\b(hoy|este dia|dia de hoy|lo que va del dia|en el dia de hoy)\b/u',
            fn($m) => nxRangeOut(nxTodayDt(), nxTodayDt(), 0, 'hoy')],
        // «el lunes», «el viernes pasado» — la ocurrencia más reciente
        ['/\b(?:el|del|este|ese)\s+(' . $wd . ')(\s+pasado)?\b/u',
            function ($m) {
                $t = nxTodayDt(); $dow = (int)$t->format('N'); $want = NX_WEEKDAYS[$m[1]];
                $back = ($dow - $want + 7) % 7;
                if (!empty($m[2]) && $back === 0) $back = 7;
                $d = $t->modify("-{$back} days");
                return nxRangeOut($d, $d, $back, "el {$m[1]} " . (int)$d->format('j') . ' de ' . NX_MONTH_NAMES[(int)$d->format('n')]);
            }],
        ['/\b(semana pasada|semana anterior|la otra semana|semana antepasada)\b/u',
            function ($m) {
                $t = nxTodayDt(); $mon = $t->modify('-' . ((int)$t->format('N') - 1) . ' days');
                $back = $m[1] === 'semana antepasada' ? 14 : 7;
                return nxRangeOut($mon->modify("-{$back} days"), $mon->modify('-' . ($back - 6) . ' days'), 14,
                    $back === 14 ? 'la semana antepasada' : 'la semana pasada');
            }],
        // «hace 3 días» = ese día puntual; «últimos 3 días» = ventana
        ['/\bhace\s+(\d{1,3}|dos|tres|cuatro|cinco)\s+dias?\b|\b(\d{1,3})\s+dias?\s+atras\b/u',
            function ($m) {
                $n = ['dos'=>2,'tres'=>3,'cuatro'=>4,'cinco'=>5][$m[1] ?? ''] ?? max(1, (int)(($m[1] ?? '') !== '' ? $m[1] : $m[2]));
                $d = nxTodayDt()->modify("-{$n} days");
                return nxRangeOut($d, $d, $n, "hace {$n} días");
            }],
        ['/\b(?:ultimos?|ultimas?|los|las|en los|en las|durante los)\s+(\d{1,3})\s+dias?\b/u',
            function ($m) {
                $n = max(1, (int)$m[1]); $t = nxTodayDt();
                return nxRangeOut($t->modify('-' . ($n - 1) . ' days'), $t, $n, "últimos {$n} días");
            }],
        ['/\b(?:ultimas?|las|en las)\s+(\d{1,2}|dos|tres|cuatro)\s+semanas?\b/u',
            function ($m) {
                $n = ['dos'=>2,'tres'=>3,'cuatro'=>4][$m[1]] ?? max(1, (int)$m[1]); $t = nxTodayDt();
                return nxRangeOut($t->modify('-' . ($n * 7 - 1) . ' days'), $t, $n * 7, "últimas {$n} semanas");
            }],
        ['/\b(ultima semana|ultimos siete dias|semana completa)\b/u',
            fn($m) => nxRangeOut(nxTodayDt()->modify('-6 days'), nxTodayDt(), 7, 'últimos 7 días')],
        ['/\b(esta semana|en la semana|de la semana|lo que va de la semana|semana actual|la semana)\b/u',
            function ($m) {
                $t = nxTodayDt();
                return nxRangeOut($t->modify('-' . ((int)$t->format('N') - 1) . ' days'), $t, 7, 'esta semana');
            }],
        ['/\b(esta quincena|la quincena)\b/u',
            function ($m) {
                $t = nxTodayDt(); $d = (int)$t->format('j');
                return nxRangeOut($t->setDate((int)$t->format('Y'), (int)$t->format('n'), $d <= 15 ? 1 : 16), $t, 15, 'esta quincena');
            }],
        ['/\b(ultima quincena)\b/u',
            fn($m) => nxRangeOut(nxTodayDt()->modify('-14 days'), nxTodayDt(), 15, 'últimos 15 días')],
        ['/\b(mes pasado|mes anterior|el otro mes)\b/u',
            function ($m) {
                $first = nxTodayDt()->modify('first day of last month');
                return nxRangeOut($first, $first->modify('last day of this month'), 60, 'el mes pasado ('
                    . NX_MONTH_NAMES[(int)$first->format('n')] . ')');
            }],
        ['/\b(?:ultimos?|ultimas?|los)\s+(\d{1,2}|dos|tres|seis)\s+meses\b/u',
            function ($m) {
                $n = ['dos'=>2,'tres'=>3,'seis'=>6][$m[1]] ?? max(1, (int)$m[1]); $t = nxTodayDt();
                return nxRangeOut($t->modify("-{$n} months")->modify('+1 day'), $t, $n * 30, "últimos {$n} meses");
            }],
        ['/\b(ultimo mes|ultimos treinta dias)\b/u',
            fn($m) => nxRangeOut(nxTodayDt()->modify('-29 days'), nxTodayDt(), 30, 'últimos 30 días')],
        ['/\b(este mes|en el mes|del mes|lo que va del mes|lo corrido del mes|mes actual|de este mes|el mes)\b/u',
            function ($m) {
                $t = nxTodayDt();
                return nxRangeOut($t->modify('first day of this month'), $t, 30, 'este mes ('
                    . NX_MONTH_NAMES[(int)$t->format('n')] . ')');
            }],
        ['/\b(en (?:el mes de )?(' . $mo . '))\b/u',
            function ($m) {
                $mon = NX_MONTHS[$m[2]]; $t = nxTodayDt(); $y = (int)$t->format('Y');
                $first = $t->setDate($y, $mon, 1);
                if ($first > $t) $first = $first->modify('-1 year');
                $last = $first->modify('last day of this month');
                if ($last > $t) $last = $t;
                return nxRangeOut($first, $last, 30, "en {$m[2]}");
            }],
        ['/\b(ano pasado|ano anterior)\b/u',
            function ($m) {
                $y = (int)nxTodayDt()->format('Y') - 1; $t = nxTodayDt();
                return nxRangeOut($t->setDate($y, 1, 1), $t->setDate($y, 12, 31), 365, "el año {$y}");
            }],
        ['/\b(este ano|del ano|en el ano|lo que va del ano|ano escolar|en lo que va del ano)\b/u',
            function ($m) {
                $t = nxTodayDt();
                return nxRangeOut($t->setDate((int)$t->format('Y'), 1, 1), $t, 365, 'este año');
            }],
    ];
}

/** «por día de la semana» / «fin de semana» son ejes, no rangos. */
function nxTimePrep(string $q): string {
    return preg_replace('/\b(dias? de la semana|fin(es)? de semana|por semana|por mes|cada semana|cada mes|cada dia)\b/u', ' _eje_ ', $q);
}

/** El rango temporal más específico mencionado (o null). */
function nxTimeRange(string $q): ?array {
    $q = nxTimePrep($q);
    foreach (nxTimePatterns() as [$re, $fn]) {
        if (preg_match($re, $q, $m)) {
            $r = $fn($m);
            if ($r) return $r;
        }
    }
    return null;
}

/**
 * Todos los rangos mencionados, en orden de aparición — «compara ayer con
 * hoy», «esta semana comparada con la pasada». Elipsis «la pasada/anterior»
 * tras «semana»/«mes» se resuelve al período previo del mismo tipo.
 */
function nxTimeRanges(string $q): array {
    $q = nxTimePrep($q);
    $hits = [];
    foreach (nxTimePatterns() as [$re, $fn]) {
        if (preg_match_all($re, $q, $all, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            foreach ($all as $mm) {
                $pos = $mm[0][1]; $end = $pos + strlen($mm[0][0]);
                // patrones más específicos ganan: un tramo ya cubierto
                // («la semana pasada») no se re-lee como «la semana»
                foreach ($hits as $h) if ($pos < $h['end'] && $end > $h['pos']) continue 2;
                $m = array_map(fn($x) => is_array($x) ? $x[0] : $x, $mm);
                $r = $fn($m);
                if ($r) $hits[] = ['pos' => $pos, 'end' => $end, 'range' => $r];
            }
        }
    }
    if (preg_match('/\b(?:la|el|con la|con el|vs|versus|y la|y el|frente a la|frente al)\s+(pasada|anterior)\b/u', $q, $e, PREG_OFFSET_CAPTURE)) {
        $kinds = array_map(fn($h) => $h['range']['range_label'], $hits);
        if (in_array('esta semana', $kinds, true) && !in_array('la semana pasada', $kinds, true))
            $hits[] = ['pos' => $e[0][1], 'end' => $e[0][1] + 1, 'range' => nxTimeRange('semana pasada')];
        elseif (preg_grep('/^este mes/u', $kinds) && !preg_grep('/^el mes pasado/u', $kinds))
            $hits[] = ['pos' => $e[0][1], 'end' => $e[0][1] + 1, 'range' => nxTimeRange('mes pasado')];
    }
    usort($hits, fn($a, $b) => $a['pos'] <=> $b['pos']);
    $out = []; $seen = [];
    foreach ($hits as $h) {
        $k = $h['range']['from'] . '|' . $h['range']['to'];
        if (isset($seen[$k])) continue;
        $seen[$k] = true; $out[] = $h['range'];
    }
    return $out;
}

/**
 * ¿El turno es SOLO un modificador temporal? («en el mes», «y la última
 * semana», «y los últimos 15 días», «¿y ayer?») — tras quitar conectores,
 * artículos y expresiones de tiempo no queda contenido propio.
 */
function nxIsTemporalFragment(string $q0): bool {
    if (!nxTimeRange($q0)) return false;
    $rest = nxTimePrep($q0);
    if (str_contains($rest, '_eje_')) return false;
    foreach (nxTimePatterns() as [$re]) $rest = preg_replace($re, ' ', $rest);
    $rest = preg_replace('/\b(y|e|pero|ahora|entonces|o sea|tambien|bueno|ok|vale|que tal|como|en|de|del|la|el|los|las|lo|al|a|durante|para|desde|hasta|hace|sobre|que|va|van|iban|fueron|hubo|hay|pasado|pasada|anterior|corrido|todo|toda|eso|esos|esas|mismo|misma|igual|y en|y de|cuantas|cuantos|cuanto|cuanta|y que)\b/u', ' ', $rest);
    return trim(preg_replace('/\s+/', ' ', $rest)) === '';
}

/** «y en todo el colegio», «en general», «en total» — quitar el filtro de grupo/estudiante. */
function nxIsScopeWidening(string $q0): bool {
    return (bool)preg_match('/^(y |pero |ahora |entonces |y que tal |que tal )?(en |de |para |a nivel de |del )?(todo el colegio|toda la institucion|todo el plantel|el colegio|la institucion|el plantel|general|total|todos los grupos|todos|todo|el resto del colegio|todo el instituto)( en total| en general)?$/u', $q0);
}

/**
 * Turnos de reparación — no son consultas nuevas: piden verificar,
 * repetir o reclaman la respuesta anterior. Se resuelven sin el parser.
 *   verify    «¿seguro?», «como que no», «verifica», «no puede ser»
 *   complaint «no te pregunté eso», «no era eso», «no entendiste»
 *   repeat    «repite», «repítelo», «otra vez lo último»
 */
function nxRepairKind(string $q0): ?string {
    $words = str_word_count($q0, 0, 'áéíóúñü0123456789');
    if ($words <= 7 && preg_match('/^(y |pero |oye |a ver )?(repite|repitelo|repitemelo|repitelo por favor|puedes repetir|podrias repetir|repetir|otra vez lo (ultimo|mismo|que dijiste)|lo de antes|vuelve a decir\w*|dilo otra vez|dimelo otra vez|que dijiste)\b/u', $q0))
        return 'repeat';
    if (preg_match('/\b(no te pregunte (eso|por eso|eso)|eso no (fue|es) lo que (te )?(pregunte|pedi|dije)|no (era|es) eso|no me (respondiste|contestaste)( lo que)?|no entendiste|te pregunte (otra cosa|algo distinto)|no es lo que (pregunte|pedi)|no te pedi eso|eso no te lo pedi|no te dije (eso|fechas|rangos))\b/u', $q0))
        return 'complaint';
    if ($words <= 7 && preg_match('/^(y |pero |oye |a ver )?(seguro|segura|estas segur[oa]|esta segur[oa]|en serio|de verdad|como (asi|que no|que si|que cero|que ninguno|que nadie)|no puede ser|eso no es (cierto|asi|verdad|correcto)|imposible|no creo|mentira|falso|estas equivocad[oa]|te equivocaste)\b/u', $q0))
        return 'verify';
    // verbos que también son comandos de consulta («revisa mis
    // notificaciones» NO es «¿seguro?»): solo cuentan como re-verificación
    // cuando el mensaje se agota en verbo+calificador — si hay sustantivo
    // de dominio detrás, es una consulta nueva y va al parser
    if (preg_match('/^(y |pero |oye |a ver |entonces )?(revisa\w*|verifica\w*|chequea\w*|confirmal[oa]?|confirma|vuelve a (revisar|mirar|contar|chequear|verificar))(\s+(bien|otra vez|de nuevo|eso|esos|esas|estos|estas|esos datos|esas cifras|los datos|las cifras|lo que dijiste|a fondo|de verdad|en serio|por favor))?[.!? ]*$/u', $q0))
        return 'verify';
    return null;
}

function nxSlots(string $q): array {
    $s = [];
    if ($tr = nxTimeRange($q)) $s = $tr;

    if (preg_match('/\b(?:grupo|salon|del|de|en|al|el)\s+(\d{1,2}\s?[a-z]|\d{1,2}-\d{1,2}|\d{1,2}-[a-z]|\d{1,2}\.\d{1,2}|prescolar|jardin|transicion|kinder)\b/u', $q, $m)
        || preg_match('/\b(\d{1,2}[a-z]|\d{1,2}-\d{1,2}|\d{1,2}-[a-z]|\d{1,2}\.\d{1,2}|\d{1,2} \d{1,2})\b/u', $q, $m)) {
        $s['group'] = strtoupper(str_replace([' ', '.'], ['-', '-'], $m[1]));
    }
    // ordinales: «octavo a», «onceavo b», «grado noveno»
    if (empty($s['group'])) {
        $ord = ['primero'=>'1','segundo'=>'2','tercero'=>'3','cuarto'=>'4','quinto'=>'5',
                'sexto'=>'6','septimo'=>'7','octavo'=>'8','noveno'=>'9','decimo'=>'10',
                'once'=>'11','onceavo'=>'11','undecimo'=>'11'];
        // patrón con letra: solo formas en o/a — «primera tardanza» es femenino
        // de «primero», NO primer+A. Igual para «tercera», «segunda»…
        $ordL = 'primero|primera|segundo|segunda|tercero|tercera|cuarto|cuarta|'
              . 'quinto|quinta|sexto|sexta|septimo|septima|octavo|octava|'
              . 'noveno|novena|decimo|decima|once|undecimo';
        if (preg_match('/\b(' . $ordL . ')\s*([a-j])(?![a-z])/u', $q, $mo)
            || preg_match('/\b(?:grado|grupo|salon)\s+(' . implode('|', array_keys($ord)) . ')\b/u', $q, $mo)
            // ordinal desnudo tras preposición: «del octavo», «los del noveno»
            // — salvo «el primero de la lista/de 6-A»: ahí es POSICIÓN, no grado;
            // y «el segundo» solo es ordinal suelto si sigue algo («del 6-A»)
            || preg_match('/\b(?:del|de|los|las)\s+(primero|primera|segundo|segunda|tercero|tercera|cuarto|cuarta|quinto|quinta|primer|tercer)\b(?!\s+(?:de|del|en|a|por|para)\b)/u', $q, $mo)
            || preg_match('/\b(?:del|de|los|las)\s+(sexto|septimo|octavo|noveno|decimo|once|undecimo|sexta|septima|octava|novena|decima)\b/u', $q, $mo)
            || preg_match('/\b(?:en|el|al)\s+(sexto|septimo|octavo|noveno|decimo|once|undecimo)\b(?!\s+(?:de|del|en|a|por|para|lugar|puesto|posicion|dia|mes|semana|ano)\b)/u', $q, $mo)) {
            $s['group'] = ($ord[$mo[1]] ?? $ord[preg_replace('/a$/u','o',$mo[1])] ?? '1')
                . (isset($mo[2]) && $mo[2] !== '' ? strtoupper($mo[2]) : '');
            $s['_group_src'] = $mo[0];   // distingue ordinal textual de dígito
        }
    }
    // «mi(s) grupo(s)/curso(s)/estudiantes» — scope RBAC del usuario, no
    // un grupo textual: el ejecutor lo resuelve contra teacher_group_access
    if (empty($s['group']) && preg_match('/\b(mi grupo|mi curso|el grupo que tengo|mi salon)\b/u', $q))
        $s['group'] = '*mine*';
    if (preg_match('/\b(mis grupos|mis cursos|los grupos que tengo|los cursos que tengo|los grupos a mi cargo|a mi cargo|que tengo asignados|mis estudiantes|los estudiantes que tengo|mis pelados|mis muchachos)\b/u', $q))
        $s['_my_scope'] = true;

    // módulo por sinónimos — «tarde/tardes» no es tardanza si es saludo
    // o franja horaria («buenas tardes», «en la tarde», «por la tarde»)
    $greetTime = (bool)preg_match('/\b(buenas tardes|buenas noches|por la tarde|en la tarde|de la tarde|la tarde de|tarde de)\b/u', $q);
    foreach (nxModuleSynonyms() as $canon => $syns) {
        foreach ($syns as $syn) {
            // borde de palabra: «inasistieron» contiene «asistieron» pero
            // es INASISTENCIA, no INGRESO — el substring miente aquí
            if (preg_match('/\b' . preg_quote($syn, '/') . '/u', $q)) {
                if ($greetTime && $canon === 'LATE_ARRIVAL'
                    && in_array($syn, ['tarde','tardes'], true)
                    && !preg_match('/\b(tardanza|tardanzas|llego tarde|llegaron tarde|llegada tarde|llegadas tarde)\b/u', $q)) {
                    continue;   // saludo vespertino — no es módulo
                }
                $s['module'] = $canon; break 2;
            }
        }
    }
    // pase fuzzy — typos frecuentes («tarsansas», «evacion») cerca de un
    // sinónimo monopalabra ≥6 letras. Conservador triple: misma primera
    // letra («natacion» ≠ «citacion» aunque disten 1), distancia ≤1 en
    // 6-7 letras y ≤2 solo desde 8 («estado» ≠ «escapo»).
    if (empty($s['module'])) {
        static $fuzzyMap = null;
        if ($fuzzyMap === null) {
            $fuzzyMap = [];
            foreach (nxModuleSynonyms() as $canon => $syns)
                foreach ($syns as $syn)
                    if (mb_strlen($syn) >= 6 && !str_contains($syn, ' '))
                        $fuzzyMap[$syn] = $canon;
        }
        foreach (preg_split('/\s+/u', $q) as $w) {
            if (mb_strlen($w) < 6) continue;
            foreach ($fuzzyMap as $syn => $canon) {
                if ($w[0] !== $syn[0]) continue;
                $max = mb_strlen($w) >= 8 ? 2 : 1;
                if (levenshtein($w, $syn) <= $max) { $s['module'] = $canon; break 2; }
            }
        }
    }
    // «permiso» como derecho de acceso («aunque no tenga permiso», «sin
    // permiso para verlo») es habla meta sobre autorización — NO es el
    // módulo de salidas autorizadas. Solo las formas NEGADAS o las de
    // finalidad de acceso cuentan: «quiénes tienen permiso hoy» sí es
    // módulo (pregunta por quiénes salen autorizados).
    if (($s['module'] ?? null) === 'PERMISO'
        && preg_match('/\bno\s+(tengo|tiene|tenga|tienen|tener|tuviera|tenemos|darme|me\s+de[sn])\s+permiso\b|\bpermiso\s+(?:para|de)\s+(ver|acceder|entrar|mirar|consultar|saber|descargar)\b/u', $q)
        && !preg_match('/\bpermisos?\s+(de salida|del colegio|para salir|activos?|emitidos?|otorgados?|pedagogicos?|de clase|al bano|medicos?)\b/u', $q))
        unset($s['module']);
    // campo de estudiante
    foreach (nxFieldSynonyms() as $field => $syns) {
        foreach ($syns as $syn) {
            // siglas cortas solo cuentan como palabra completa —
            // «institución» no contiene la TI (tarjeta de identidad)
            $hit = mb_strlen($syn) <= 3
                ? (bool)preg_match('/\b' . preg_quote($syn, '/') . '\b/u', $q)
                : str_contains($q, $syn);
            if ($hit) { $s['field'] = $field; break 2; }
        }
    }
    // estudiante
    $s['student'] = nxExtractStudent($q);
    // persona del PERSONAL (docente/directivo) — solo en contextos que lo
    // marcan como staff: tras sustantivo de rol («la profesora martínez»),
    // como agente de verbo de emisión («citó germán sánchez»), o en pasiva
    // «resuelta por marta». El genitivo desnudo («las tardanzas de maría»)
    // NO marca persona — ahí manda student. Si student ya capturó el mismo
    // texto, person gana solo cuando hay marcador de staff explícito.
    if ($person = nxExtractPerson($q)) $s['person'] = $person;

    // ── modificadores §12 ────────────────────────────────────────────────
    // exclusión: «todos menos los del 8A», «excepto los del octavo» —
    // el grupo mencionado se EXCLUYE, no se filtra como group=
    if (preg_match('/\b(excepto|menos|salvo|aparte de|fuera de)\s+(?:los|las|el|la)?\s*(?:del|de|al)?\s*/u', $q, $mm)) {
        $rest = substr($q, strpos($q, $mm[0]) + strlen($mm[0]));
        $ord = ['primero'=>'1','segundo'=>'2','tercero'=>'3','cuarto'=>'4','quinto'=>'5',
                'sexto'=>'6','septimo'=>'7','octavo'=>'8','noveno'=>'9','decimo'=>'10',
                'once'=>'11','onceavo'=>'11','undecimo'=>'11'];
        if (preg_match('/^(\d{1,2}\s?[a-z]?)\b/u', $rest, $g)) {
            $s['_except'] = strtoupper(str_replace(' ', '', $g[1]));
        } elseif (preg_match('/^(' . implode('|', array_keys($ord)) . ')\b/u', $rest, $g)) {
            $s['_except'] = $ord[$g[1]];
        }
        if (isset($s['_except']) && ($s['group'] ?? null) === $s['_except'])
            unset($s['group']);
    }
    // exclusividad: «solo los que…», «únicamente…»
    if (preg_match('/\b(solo|solamente|unicamente|nada mas que|tan solo)\b/u', $q))
        $s['_only'] = true;
    // no-justificado: «sin excusa», «sin justificar», «sin permiso»
    if (preg_match('/\b(sin excusa|sin justificar|injustificad\w*|sin permiso|sin autorizar|sin autorizacion)\b/u', $q))
        $s['_unjustified'] = true;
    // excusa explícita — «con excusa», «justificadas», «las que tienen
    // excusa» → filtro justified=yes (el contrario ya quedó en _unjustified)
    if (!empty($s['_unjustified'])) $s['justified'] = 'no';
    elseif (preg_match('/\b(con excusa|con justificacion|tiene excusa|tienen excusa|justificad\w*|excusad[oa]s?|las excusadas|los excusados)\b/u', $q))
        $s['justified'] = 'yes';
    // negación de asistencia — la ausencia no es el ingreso
    if (empty($s['module']) && preg_match('/\b(no llegaron|no vinieron|no entraron|no asistieron|no se present|faltaron|ausentes)\b/u', $q))
        $s['module'] = 'INASISTENCIA';
    // regiones — un departamento/país nunca es estudiante
    $regions = nxRegions($q);
    if ($regions) {
        $s['_regions'] = $regions;
        $s['topic'] = $regions[0];
        if (!empty($s['student'])) {
            foreach ($regions as $r) {
                if (str_contains($r, $s['student']) || str_contains($s['student'], $r)) {
                    $s['student'] = null;
                    break;
                }
            }
        }
    }
    // umbral
    if (preg_match('/mas de (\d+)|al menos (\d+)|(\d+) veces|(\d+) repeticiones/u', $q, $m)) {
        $s['threshold'] = (int)($m[1] ?: $m[2] ?: $m[3] ?: $m[4]);
    }
    return $s;
}

/* Vocabulario que NUNCA puede ser nombre de estudiante — lo usan tanto el
 * extractor determinista (nxExtractStudent) como el sanitizador de entidades
 * del parser LLM (nexus_llm.php) para rechazar «llegadas», «fechas»… que el
 * modelo pegue como student. */
function nxStudentStopwords(): array {
    return ['grupo','salon','colegio','escuela','jornada','hoy','ayer','semana',
        'mes','ano','dias','dia','el','la','los','las','un','una','este','esta','esto',
        'eso','mi','tu','su','mis','tus','sus','que','cual','cuales','cuanto','cuanta',
        'cuantos','cuantas','dime','dame','muestrame','ver','hay','tiene','tienen',
        'tenido','tuvo','fue','son','ser','nexus','nexo','favor','porfa','porfavor',
        'acudiente','acudientes','papa','mama','padre','madre','documento','cedula',
        'celular','telefono','whatsapp','numero','contacto','datos','informacion',
        // verbos de acción — «daniel y exporta todo»: «exporta» no es
        // apellido, es la cláusula siguiente pegada al nombre
        'exporta','exportar','exportalo','exportala','descarga','descargar',
        'muestra','mostrar','muestrame','genera','generar','crea','crear',
        'registra','registrar','borra','borrar','elimina','eliminar',
        'compara','comparar','usa','usar','entra','entrar','pasa','pasar',
        'imprimir','imprime','copiar','copia','envia','enviar','manda',
        'edad','nacimiento','permiso','permisos','seguimiento','citacion','caso','incidente',
        'estudiantes','estudiante','alumnos','alumnas','alumno','alumna',
        'ninos','ninas','docentes','docente','profesores','profesor','profesora',
        // pronombres y cortesía — nunca nombres
        'ti','mi','vos','usted','ustedes','ellos','ellas','nosotros','gracias',
        'muchas','muchisimas','mil','bendiciones','amable','senor','senora',
        'puedes','puedo','por','si','ok','vale','dale',
        'quien','quienes','responde','cargo','figura','registrada','registrado',
        'aparece','responsable','responsabilidad','volvamos','vuelve','volver',
        // vocabulario del dominio — sustantivos del sistema, no personas
        'sensores','sensor','dispositivos','dispositivo','nodos','nodo',
        'institucion','sistema','app','aplicacion','huella','lectores','lector',
        'biometricos','biometrico','horario','horarios','riesgo','alertas','alerta',
        'seguimientos','auditoria','notificaciones','registros','historial',
        'reporte','reportes','excel','inasistencias','evasiones','tardanzas',
        'citaciones','mensajes','padres','incidentes','danos','salidas','entradas',
        'ingresos','ausentes','presentes','matriculados','grupos','salones',
        'grados','grado','jornadas','turno','bano','banos',
        'faltas','falta','ausencias','ausencia','fugas','fuga','casos','emergencia',
        'emergencias','panico','sos','ficha','perfil','resumen','estado','cumpleanos',
        'identificacion','responsable','familiar','cambios','cambio','traza','log','registro','usuario','usuarios',
        // institución y comparativos — «la asistencia del colegio hoy
        // comparado con ayer» no puede parir un nombre de persona
        'colegio','escuela','plantel','hoy','ayer','anteayer','comparado','comparada',
        'comparar','versus','contra','frente','respecto','mejor','peor','menos','mayor',
        'subio','bajo','empeoro','mejoro','aumento','disminuyo','personal','planta',
        // más dominio — «lista de cursos», «comparativa de asistencia» no
        // son personas: sin esto el extractor fabrica nombres de dominio
        'curso','cursos','asistencia','asistencias','excusa','excusas','cita','citas',
        'evento','eventos','autorizacion','autorizaciones','recreo','descanso',
        'almuerzo','bloque','bloques','periodo','periodos','clase','clases',
        'materia','materias','tutor','tutora','llamados','llamadas','convocados',
        'convocadas','citados','citadas','novedad','novedades','trazabilidad',
        'bitacora','censo','poblacion','matricula','desercion','umbral','umbrales',
        'spam','twilio','mensajeria','correo','correos','bandeja','avisos','aviso',
        'directivos','empleados','funcionarios','trabajadores','planta','kilitos',
        'aleatorio','azar','cualquiera','tabla','columnas','frecuencia','promedio',
        'comparativa','comparativo','ranking','record','top','peores','mejores',
        // cultura general — nunca estudiantes (evita fuga a formal)
        'arepas','arepa','pizza','pasta','gripe','dolor','vida','valores','bitcoin',
        'elefante','fotosintesis','ingles','frances','netflix','anime','fortnite',
        'trucos','champions','horoscopo','receta','recetas','medicina','remedio',
        'bolsa','series','peliculas','musica','cancion','zodiacal','vitaminas',
        'noticias','lluvia','cuento','poesia','poema','libro','futbol','capital',
        'capitales','presidente','presidentes','departamento','departamentos',
        'region','regiones','municipio','formacion','consejeria','coordinacion',
        'salida','papas','ultimos','timbre','cancha','tienda','cobija','pinta',
        'pintas','puente','materia','clase','leccion','recreo','descanso',
        'primero','segundo','tercero','cuarto','quinto','sexto','septimo',
        'octavo','noveno','decimo','once','primera','segunda','tercera',
        // verbos/comparativos de dominio — «hizo más ping rechazado»,
        // «mejor asistencia», «quien falta menos» no nombran personas
        'hizo','hicieron','rechazado','rechazada','rechazaron','rechazo',
        'rechazos','mejor','peor','ping','pings','evadiendo','saliendo',
        'entrando','faltando','llegando','avance','avances','trabaja',
        'trabajan','personal','reporta','reportan','genero','generaron',
        'emitio','emitieron','resolvio','resolvieron','autorizo','autorizaron',
        // días y tiempos — «el paseo del viernes» no es un estudiante
        'lunes','martes','miercoles','jueves','viernes','sabado','sabados',
        'domingo','domingos','manana','tarde','noche','madrugada','feriado',
        'festivo','puente festivo','paseo','paseos','pasantia','pedagogica',
        // colectivos genéricos — «chicos del 7-B» es el grupo, no una persona
        'chico','chicos','chica','chicas','muchacho','muchachos','muchacha',
        'muchachas','pelado','pelados','pelada','peladas','menor','menores',
        'chino','chinos','china','chinas','ninios','ninias','onceavo','undecimo',
          'tardanza','tardanzas','inasistencia','inasistencias','evasion',
         'evasiones','ausencia','ausencias','falta','faltas','permiso',
         'permisos','llegada','llegadas','llegado','llegados',
         'citacion','citaciones','familia','familiar','pariente','parientes',
        # adjetivos de estado del estudiante — «alumnos exentos» no es persona
         'exento','exentos','exenta','exentas','eximido','eximidos','dispensado',
         'dispensados','presente','presentes','ausente','ausentes','tarde','puntual',
         'impuntual','impuntuales','asignado','asignada','asignados','asignadas',
         'huerfano','huerfanos','libre','libres','enrolado','enrolados','activo',
         'activa','activos','inactivo','inactiva','retirado','retirada','graduado',
         'graduada','nuevo','nueva','antiguo','antigua','bajo','alto','media','medio',
         'critico','critica','vulnerable','vulnerables','derivado','derivados',
         'observado','observados','citado','citados','faltado','faltados',
        'ensename','necesito','queria','pasame','mirame','buscame','listame',
        'contame','cuentame','decime','traeme','ponme','sacame','mira','pon',
        'aleatorio','aleatoria','cualquiera','azar','random',
        'existimos','vivimos','nacimos','estamos','somos','fueron',
        // materias académicas y cultura general — nunca nombres de estudiante
        'filosofia','literatura','politica','geografia','historia','quimica',
        'biologia','astronomia','religion','matematicas','espanol','aleman',
        'etica','fisica','sena','senas','resultado','resultados','partido',
        'clima','tiempo','temperatura','pronostico','chiste','chistes',
        'siento','sientes','siente','tengo','tienes','quiero','quieres',
        'puedo','puedes','pueden','haces','hago','hacen','estoy','andan',
        'voy','vas','van','digo','dices','dicen','era','eran','sera','seran',
        'fui','hubo','habia','habran','eres','ser',
        'un','uno','una','dos','tres','cuatro','cinco','seis','siete','ocho',
        'nueve','diez','doce','trece','catorce','quince','veinte','treinta',
        'cuarenta','cincuenta','sesenta','setenta','ochenta','noventa','cien',
        'ciento','mil','millon','partido','resultado','noticias','mitad',
        'doble','triple','porciento','porcentaje','raiz','seno','coseno',
        'tangente','logaritmo','factorial','regla','area','volumen','base',
        'altura','lado','radio','catetos','hipotenusa','pitagoras','grado',
        'llover','llueve','llovio','nevando','truena','graniza','soleado',
        'nublado','lluvioso','caluroso','fresco','templado',
         'tarde','temprano','presente','justificado','puntual','ausente',
        // referencias temporales/posicionales — nunca nombres de estudiante
        'pasado','pasada','pasados','pasadas','anterior','anteriores',
        'proximo','proxima','proximos','proximas','siguiente','siguientes',
        'actual','actuales','reciente','recientes','vigente','venidero',
        'venidera','entrante','corriente',
        // conectores/demostrativos/temporales sueltos — evita candidatos
        // espurios como «camila del», «maria manana», «mismo juan»
        'del','de','manana','mismo','misma','mismos','mismas',
        'ese','esa','esos','esas','otro','otra','propio','propia',
        'aquel','aquella','aquellos','aquellas','tambien',
        'aula','aulas','veces','vez',
        // pronombres personales — nunca nombres; el DSM los resuelve al ctx
        'ella','ellos','ellas','usted','ustedes','el','ella',
        // conectores/adverbios de encuadre — «ahora del 8a» no es persona
        'ahora','ahorita','y','e','ni','o','u','pero','sino','ademas',
        'luego','entonces','asi','aun','ya','muy','mas','menos','tan',
        'tanto','cada','todo','toda','todos','todas','varios','varias',
        'algunos','algunas','ningun','ninguna','cualquier','apenas',
        // preposiciones y muletillas que preceden nombres sin ser parte
        'info','para','con','sobre','hacia','segun','entre','sin','ante',
        'bajo','desde','hasta','tras','via','pro','segun','mismo','suyo',
        'suya','tuyo','tuya','nuestro','nuestra','propio','propia','solicitud','solicitudes',
        'solo','solamente','unicamente','especificamente','concretamente',
        'abierto','abierta','abiertos','cerrado','cerrada','pendiente','pendientes',
        'activo','activa','activos','vigente','vigentes','anterior','anteriores',
        'reciente','recientes','nuevo','nueva',
        // presentación/orden — nunca nombres («ordenados por nombre» en 6-A)
        'ordenado','ordenada','ordenados','ordenadas','orden','alfabeticamente',
        'alfabetico','alfabetica','completo','completa','completos','completas',
        'tabla','tablas','columnas','nomina','nominas','nombre','nombres','listado','listados',
        'primeros','primeras','entero','entera','integro','integra','todos',
        'todas','listar','listando','tabulado','tabulada','porcentaje','porcentajes',
        // pronombres clíticos y lugares — «se presente en coordi» no es nombre
        'se','me','te','nos','lo','le','les','coordi','rectoria',
        // adverbios deícticos — «quiénes faltaron ahí» no nombra a nadie
        'ahi','alli','aca','alla',
        // ordinales y unidades temporales — «del último mes» no nombra
        // a nadie; «primero/segundo» es posición, nunca apellido
        'ultimo','ultima','ultimos','ultimas','primero','primera',
        'segundo','segunda','tercero','tercera','mes','meses','semana',
        'semanas','ano','anos','dia','dias','quincena','bimestre',
        'siguiente','anterior','proximo','proxima',
        // copulativos sueltos — «cuál es el primero» no nombra a nadie
        'es','sea','sean','fuese','estando','siendo',
        // colección como objeto — «el primero de la lista» no es persona
        'lista','listas','fila','filas','columna','posicion','posiciones',
        'puesto','puestos','lugar','lugares','ranking','top',
        'matematicas','ingles','espanol','ciencias','sociales','fisica','quimica',
        'biologia','historia','geografia','arte','musica','religion','etica',
        'informatica','lectura','escritura','coordinador','coordinadores',
        'docente','docentes','profesor','profesores','maestro','maestros',
        'personal','rector','rectores','secretaria','secretarias','directivo',
        'exactamente','precisamente','respectivamente','personalmente',
        'excusa','medica','medico','durante','tiempo','sistemas','mejora','seguridad','conducta','nino','nina','academico','academica','transferida','transferido','natacion','autorizada','autorizado','autorizados','autorizadas','bimestre','preescolar','en','falto','jornada','estado','grupo','estudiante','estudiantes','alumno','alumnos','proceso','procesos','area','nivel','registrada','registrado','registrados','entrada','entradas','salida','salidas','anticipada','anticipado','temprana','temprano','tardia','tardio','alerta','alertas','tarea','tareas','caso','casos','incidencia','incidencias','evento','eventos','fuga','fugas','lector','lectores','piso','pisos','recreo','descanso','observacion','presente','presentes','ausente','ausentes','vinieron','llego','llegaron','entro','entraron','presento','presentaron','regreso','regresaron','acumulada','acumuladas','acumulado','acumulados','marcada','marcado','marcados','marcaron','resuelto','resueltos','resuelta','resueltas','completado','autorizo','autorizaron','faltaron','impuntual','impuntuales','registrar','registren','detectada','detectadas','detectado','detectados','detectaron','reportada','reportadas','reportado','reportados','reportaron','llamado','llamada','llamar','llamen','citado','citada','convocar','convocado','convocada','reunion','reuniones','peticion','peticiones','padres','padre','madre','mama','papa','abuela','abuelo','tia','tio','hermano','hermana','amigo','amiga','vecino','vecina','nadie','alguien','alguno','alguna','algunos','algunas','ninguno','ninguna','ningunos','ningunas','cualquiera','quienquiera','cuyo','cuya','filosofia','literatura','politica','geografia','historia','quimica','biologia','astronomia','religion','matematicas','espanol','ingles','frances','aleman','lejos','cerca','arriba','abajo','dentro','fuera','encima','debajo','delante','detras','alrededor','junto','juntos','juntas','aparte','incluso','volaron','volar','escaparon','escapar','caparon','capar','volaron','voló','volo','excepto','menos','salvo','aparte','reporto','reportaste','reportamos','aplican','aplica',
        // institución y condición — «del plantel», «tiene restricciones»,
        // «de la coordinación» jamás son apellidos
        'plantel','colegio','institucion','escuela','sede','restricciones',
        'restriccion','alergias','condicion','condiciones','coordinacion',
        'rectoria','secretaria','orientacion','biometrico','biometricos',
        'biometrica','biometricas','matriculada','matriculado','inscrita',
        'inscrito','pertenece','vencidos','vencidas','vencido','vencida',
        'pendientes','pendiente','programadas','programada','agendadas',
        'agendada','avisos','aviso','mensajes','notificaciones',
        'general','generales','panorama','consolidado','balance','cuadro',
        'ese','esa','esos','esas','este','esta','estos','estas','aquel',
        'aquella','aquellos','aquellas','refiero','refieres','refiriendo',
        // verbos de consulta/comparación — «compara las tardanzas de 10A»
        // no nombra a nadie; «compara» es operación, no apellido
        'compara','comparar','comparame','comparacion','comparativa','versus',
        'vs','contra','diferencia','diferencias','mide','miden','evalua',
        // columnas/detalle pedido — «fechas y motivo», «cantidad y aumento»
        // jamás son apellido; se pegan al final del nombre si no se cortan
        'fecha','fechas','motivo','motivos','razon','razones','causa','causas',
        'detalle','detalles','cantidad','aumento','aumentos','total','totales',
        'conteo','conteos','suma','sumas','promedio','promedios','media',
        'autorizado','autorizada','autorizados','autorizadas','justificada',
        'justificadas','justificado','justificados','excusa','excusas',
        // interrogativos y sustantivos de consulta — «para dónde es el
        // paseo», «el motivo de X», «el avance del caso»: NUNCA son
        // personas aunque sigan a un marcador de persona
        'donde','adonde','cuando','motivo','motivos','razon','razones',
        'avance','avances','paseo','paseos','excursion','destino','fecha',
        'hora','horas','dia','dias','justificacion','justificaciones',
        // gerundios de dominio — «estudiante evadiendo clase»:
        // «evadiendo» es el QUÉ, no el QUIÉN
        'evadiendo','saliendo','llegando','faltando','entrando','viniendo',
        'asistiendo','matriculando','evadiendo','escapando','volando',
        'capando','tajando','escondiendo','metiendo','quedando',
        // vocabulario de consulta sistémica — «exento de biometría»,
        // «día lectivo», «personal de la mañana» tampoco son nombres
        'exento','exenta','exentos','exentas','lectivo','lectiva','festivo',
        'festivos','reporte','reportes','exportado','exportados','consentimiento',
        'consentimientos','biometria','biometrica','ping','reportes',
        'operativos','operativo','operativas','operativa','funcionando',
        'servicio','respondiendo','caido','caida','encendido','apagado',
        // adverbios y función — cola típica de una frase interrogativa
        // que el extractor pegaba al nombre («valentina ahora»)
        'ahora','despues','antes','siempre','nunca','jamas','tambien',
        'tampoco','todavia','aun','ya','solo','solamente','apenas','casi',
        'bastante','demasiado','mucho','mucha','poco','poca','algo','nada',
        'todo','todos','todas','es','sea','sean','era','eran','estoy',
        'estan','estaba','estaban','estamos','estar','estara','sera',
        'hay','habia','habia','habra','tendra','tendran','tiene','tienen',
        'tuvo','tuvieron','tenido','tenian','habia','habian','haya','hayan',
        'mismo','misma','mismos','mismas','alguna','algunas','algun',
        'alguno','algunos','cualquier','cualesquiera','ningun','ninguna',
        'ninguno','ningunos','cierto','cierta','ciertos','ciertas'];
}

/**
 * Vocabulario que NUNCA forma un nombre propio: interrogativos, verbos
 * comunes, adjetivos y sustantivos funcionales frecuentes. Si todas las
 * palabras del candidato están aquí, es resto de frase — no persona.
 */
function nxNameRejectVocab(): array {
    static $v = null;
    return $v ??= array_merge(nxStudentStopwords(), [
        'donde','adonde','cuando','cuanto','cuanta','cuantos','cuantas','cual',
        'cuales','como','porque','motivo','motivos','razon','razones','avance',
        'avances','medico','medica','enfermeria','doctor','doctora','eps',
        // verbos comunes (infinitivo + formas frecuentes)
        'hacer','hace','hizo','hacen','haciendo','decir','dice','dijo','dicen',
        'diciendo','tener','estar','ser','ir','va','van','fue','fueron','ido',
        'yendo','ver','vio','ven','viendo','dar','da','dio','dan','dando',
        'saber','sabe','supo','saben','querer','quiere','quieren','queriendo',
        'poder','puede','pueden','pudiendo','poner','pone','puso','ponen',
        'seguir','sigue','siguen','encontrar','encontro','encontraron',
        'llevar','lleva','llevo','llevando','dejar','deja','dejo','dejando',
        'llamar','llama','llamo','llamando','venir','viene','vienen','pensar',
        'piensa','piensan','salir','sale','salen','volver','vuelve','vuelven',
        'tomar','toma','toman','conocer','conoce','conocen','vivir','vive',
        'sentir','siente','tratar','trata','mirar','mira','miran','contar',
        'cuenta','empezar','empieza','esperar','espera','buscar','busca',
        'existir','existe','entrar','entra','trabajar','trabaja','escribir',
        'perder','pierde','ocurrir','ocurre','entender','entiende','pedir',
        'pide','recibir','recibe','recordar','recuerda','terminar','permitir',
        'aparecer','aparece','conseguir','consigue','comenzar','comienza',
        'servir','sirve','sacar','saca','necesitar','necesita','mantener',
        'resultar','resulta','leer','lee','caer','cae','cambiar','cambia',
        'presentar','crear','abrir','abre','considerar','oir','oye','acabar',
        'acaba','ganar','gana','formar','traer','trae','partir','morir',
        'aceptar','realizar','suponer','comprender','lograr','pasar','pasa',
        'quedar','queda','tocar','toca','reconocer','dirigir','llegar','llega',
        'correr','corre','valer','vale','ofrecer','ofrece','mostrar','muestra',
        'intentar','intenta','usar','usa','utilizar','utiliza','pagar','paga',
        'apoyar','estudiar','estudia','gustar','gusta','resolver','resuelve',
        'aprender','aprende','repetir','repite','subir','sube','bajar','baja',
        'contestar','contesta','responder','responde','preguntar','pregunta',
        'importar','importa','significar','significa','olvidar','olvida',
        'jugar','juega','comprar','compra','vender','vende','cerrar','cierra',
        'esperar','desear','desea','evitar','evita','cumplir','cumple',
        'explicar','explica','entregar','entrega','enviar','envia','mandar',
        'manda','devolver','devuelve','prestar','presta','cobrar','cobra',
        'firmar','firma','revisar','revisa','verificar','verifica','chequear',
        'chequea','confirmar','confirma','analizar','analiza','clasificar',
        'clasifica','listar','lista','exportar','exporta','descargar',
        'descarga','generar','genera','imprimir','imprime','citar','cita',
        'reportar','reporta','registrar','registra','autorizar','autoriza',
        'justificar','justifica','excusar','excusa','evadir','evade','faltar',
        'falta','llegar','entrar','salir','asistir','asiste','matricular',
        'convocar','convoca','derivar','deriva','gestionar','tramitar',
        'adjuntar','anexar','detectar','detecta','detecto','detectaron',
        'marco','marca','marcaron','puso','ponen','hizo','hicieron','dijo',
        'dijeron','emito','emitio','resolvio','autorizo','justifico',
        // adjetivos comunes
        'bueno','buena','buenos','buenas','malo','mala','malos','malas',
        'nuevo','nueva','nuevos','nuevas','viejo','vieja','grande','grandes',
        'pequeno','pequena','alto','alta','bajo','baja','largo','larga',
        'corto','corta','facil','dificil','rapido','lento','activo','activa',
        'inactivo','abierto','abierta','cerrado','cerrada','pendiente',
        'resuelto','resuelta','activo','actual','anterior','siguiente',
        'ultimo','ultima','primer','completo','completa','general','local',
        'nacional','oficial','personal','privado','publico','especial',
        'normal','real','unico','unica','mismo','misma','propio','propia',
        'cierto','cierta','seguro','segura','claro','clara','exacto','exacta',
        // sustantivos funcionales frecuentes no-persona
        'cosa','cosas','parte','partes','forma','formas','manera','maneras',
        'tipo','tipos','vez','veces','caso','casos','punto','puntos','lado',
        'momento','minuto','minutos','segundo','segundos','ejemplo','tema',
        'temas','asunto','asuntos','detalle','detalles','dato','datos',
        'info','cantidad','cantidades','numero','numeros','lista','listas',
        'tabla','tablas','fila','filas','columna','columnas','resultado',
        'resultados','opcion','opciones','respuesta','respuestas',
    ]);
}

function nxExtractStudent(string $q): ?string {
    static $stop = null;
    if ($stop === null) $stop = nxStudentStopwords();
    $boundary = '(?:[\s,;.!?]+(?:del|de|en|grupo|salon|durante|en los|en las|hoy|ayer|esta|ultimos|en el|por|que|y|los|las|con)\b|,|;|\.|!|\?|[\s,;.!?]*$)';
    $cands = [];
    foreach ([
        // marcador de persona explícito — «la niña camila», «el muchacho
        // juan»: el nombre sigue al sustantivo, no al conector
        '/(?=(?:estudiante|alumno|alumna|nino|nina|muchacho|muchacha|pelado|pelada|chico|chica|menor)\s+([a-z]+(?:\s+[a-z]+){0,3})' . $boundary . ')/u',
        '/(?=\b(?:de|del|sobre|para|(?<![-\d])a|solo|solamente|tenido|tuvo|tiene|tienen|sido|hizo|estado|estuvo|hecho|falto|faltaron|llego|entro|salio|capo|volo|evadio|evadieron|caparon|volaron|volado|capado|matriculada|matriculado|inscrita|inscrito|pertenece|cursa|llamado|llamada|riesgo|exenta|exento|exentos|eximida|eximido|citada|citado|citados|convocada|convocado|agendada|agendado|programada|programado|autorizada|autorizado|reportada|reportado|resuelta|resuelto|justificada|justificado|registrada|registrado|diligenciada|firmada|cerrada|seguimiento|monitoreo|observacion)\s+([a-z]+(?:\s+[a-z]+){0,3})' . $boundary . ')/u',
        // «camila del septimo», «juan del 8a», «pedro del jardin» —
        // nombre + «del/de» + grado: el nombre precede al conector
        '/\b([a-z]{2,}(?:\s+[a-z]+){0,2})\s+(?:del|de)\s+(?:el |la )?(?:primero|segundo|tercero|cuarto|quinto|sexto|septimo|octavo|noveno|decimo|once|undecimo|jardin|kinder|transicion|prescolar|\d)/u',
        // «por qué eva exenta está exenta», «por qué maría está en
        // riesgo» — el nombre PRECEDE al verbo de estado, no lo sigue
        '/\b(?:por ?que|porque)\s+(?:es\s+|esta\s+)?([a-z]{2,}(?:\s+[a-z]+){0,3})\s+(?:esta|es|estan|fue|sigue|quedo|tiene|lleva|anda)\s+(?:en\s+|tan\s+|muy\s+)?(?:riesgo|exenta|exento|exentos|eximid|justific|matriculad|seguimiento|alerta|sancion|mal|bien|critico|grav)/u',
    ] as $pat) {
        preg_match_all($pat, $q, $mm, PREG_OFFSET_CAPTURE);
        static $leadMarkers = ['el','la','los','las','un','una','del','de','al',
            'mismo','misma','mismos','mismas','ese','esa','esos','esas',
            'este','esta','estos','estas','aquel','aquella','aquellos','aquellas',
            'estudiante','estudiantes','alumno','alumna','alumnos','alumnas',
            'nino','nina','muchacho','muchacha','pelado','pelada','chico',
            'chica','menor','grupo','salon','curso','grado'];
        foreach ($mm[1] ?? [] as $cand) {
            $raw = explode(' ', trim($cand[0]));
            // saltar artículos/marcadores iniciales («el mismo juan»,
            // «la niña camila») — el primer término tras ellos debe ser el
            // nombre; si es stopword («sobre LA física cuántica» → «fisica»)
            // el residuo filtrado es resto de frase, no persona
            $lead = $raw;
            while ($lead && in_array($lead[0], $leadMarkers, true)) array_shift($lead);
            if (!$lead || in_array($lead[0], $stop, true)) continue;
            $words = array_values(array_filter(
                $raw,
                fn($w) => !in_array($w, $stop) && mb_strlen($w) > 1
                    && !preg_match('/\d/', $w)));
            // un candidato compuesto SOLO de vocabulario común no es
            // persona — es resto de la frase («tiene justificacion medica»
            // → 'medica'; «estudiante evadiendo» → 'evadiendo'). Exige al
            // menos un token que no sea palabra funcional/de dominio.
            if ($words && !array_diff($words, nxNameRejectVocab())) continue;
            if ($words) $cands[] = implode(' ', $words);
        }
    }
    return $cands ? end($cands) : null;
}

/* Nombre de PERSONAL (docente/directivo/administrativo) — distinto del
 * estudiante. Solo se extrae cuando el enunciado lo marca como staff:
 *   1. sustantivo de rol + nombre:  «la profesora martínez»
 *   2. agente de verbo de emisión:  «citó germán sánchez», «generó marta»
 *      (la «a» personal del objeto NO dispara — «citó a maría» la deja
 *      como estudiante, porque es la citada, no la que cita)
 *   3. pasiva «por X»:              «resuelta por marta», «autorizado por el profe lópez»
 * El genitivo desnudo («las tardanzas de maría») no marca rol — lo
 * resuelve el extractor de estudiantes. */
function nxExtractPerson(string $q): ?string {
    static $cut = null;
    if ($cut === null) $cut = array_merge(nxStudentStopwords(),
        ['a','al','en','y','o','con','para','hasta','sin','sobre','entre',
         'lo','le','les','se','como','cuando','donde','porque','aunque',
         'mientras','hoy','ayer','manana','ahora','del','de','el','la',
         'los','las','que','cual','cuales','su','sus','mi','mis','tu','tus']);
    $clean = function(string $raw) use ($cut): ?string {
        $out = [];
        foreach (preg_split('/\s+/u', trim($raw)) as $w) {
            if (in_array($w, $cut, true) || mb_strlen($w) < 2 || preg_match('/\d/', $w)) break;
            $out[] = $w;
            if (count($out) === 4) break;
        }
        return $out ? implode(' ', $out) : null;
    };
    $name = '([a-záéíóúñü]{2,}(?:\s+[a-záéíóúñü]{2,}){0,3})';
    // 1) rol + nombre — femenino/plural incluidos
    if (preg_match('/\b(?:docentes?|profesora?s?|profesores|profe|profes|maestra?s?|maestros|coordinadora?s?|rectores?|rectora?s?|directora?s?|secretaria?s?|orientadora?s?|psicoorientadora?s?|enfermera?s?|portera?s?|celadora?s?|auxiliares?|vicerrectora?s?)\s+' . $name . '/u', $q, $m))
        if ($n = $clean($m[1])) return $n;
    // 2) verbo de emisión + agente directo (sin «a»/«al» previo al nombre:
    //    «citó a maría» → maría es la citada, no el agente)
    if (preg_match('/\b(?:cito|citamos|convoco|convocamos|genero|generamos|descargo|exporto|saco|autorizo|autorizamos|resolvio|resolvimos|justifico|reporto|reportamos|registro|registramos|envio|enviamos|mando|mandamos|firmo|diligencio|emitio|emitimos|programo|programamos|agendo|agendamos|cargo|cargamos|subio|subimos|cerro|cerramos|atendio|atendimos)\s+' . $name . '/u', $q, $m))
        if ($n = $clean($m[1])) return $n;
    // 3) pasiva «por X»
    if (preg_match('/\b(?:resuelt[ao]s?|resueltas|autorizad[ao]s?|autorizadas|justificad[ao]s?|enviad[ao]s?|enviadas|emitid[ao]s?|emitidas|generad[ao]s?|generadas|hech[ao]s?|hechas|mandad[ao]s?|mandadas|registrad[ao]s?|registradas|reportad[ao]s?|reportadas|citad[ao]s?|citadas|cargad[ao]s?|cargadas|subid[ao]s?|subidas|diligenciad[ao]s?|diligenciadas|firmad[ao]s?|firmadas|cerrad[ao]s?|cerradas|atendid[ao]s?|atendidas|programad[ao]s?|programadas|agendad[ao]s?|agendadas)\s+por\s+(?:el\s+|la\s+)?' . $name . '/u', $q, $m))
        if ($n = $clean($m[1])) return $n;
    return null;
}

function nxModuleSynonyms(): array {
    return [
        'LATE_ARRIVAL'      => ['llegadas tarde','llegada tarde','tardanzas','tardanza','tarde','llego tarde','llegaron tarde','tardes',
            'entradas tardias','entrada tardia','entradas tardia','llegadas tardias','llegada tardia','tardias','tardia',
            'impuntualidad','impuntualidades','impuntual','impuntuales','retrasos','retraso','demoras','demora','tardeo','tardeos',
            'pasada la hora','pasada de la hora','a destiempo','destiempo','despues de la hora','después de la hora',
            'se atrasaron','atrasaron','atrasados','atrasadas','atraso','con atraso','a deshora','fuera de tiempo'],
        'INASISTENCIA'      => ['inasistencias','inasistencia','inasistieron','inasistio','inasistió','inasiste','faltas','falta','faltado','faltando','ausencias','ausencia','no vinieron','no vino','faltaron','falto','ausentes','ausente','no llegaron','no llego','no entraron','no entro','no asistieron','no asistio','no se presentaron','no se presento','se ausentaron','se ausento'],
        'INASISTENCIA_JUSTIFICADA'    => ['inasistencias justificadas','justificadas','faltas justificadas'],
        'INASISTENCIA_NO_JUSTIFICADA' => ['inasistencias no justificadas','sin justificar','injustificadas'],
        'EVASION_INTERNA'   => ['evasiones internas','evasion interna','evasiones','evasion','evadiendo','evade','evaden','evadiendo clase','fugas','fuga','se salieron','se salio','escaparon','escapo','salio del salon','abandono la clase','abandonaron clase','abandono el aula','abandono del aula','abandono de aula','abandono aula','salio del aula','salieron del aula','salio de clase','abandono','se volaron','se volo','se la volaron','se la volo','tiraron','se tiraron','tajaron','se tajaron','caparon','se caparon','evasores','se fueron','se fueron de clase','se fueron del salon','abandonaron la clase','abandonaron el salon','abandonan','abandonan la clase','abandonan clases','abandono durante','abandonaron el aula','no regresaron','no regreso','no volvieron','no volvio',
            'salida no autorizada','salidas no autorizadas','salida sin autorizacion','salidas sin autorizacion'],
        'PERMISO'           => ['permisos','permiso','salidas autorizadas','autorizaciones','autorizacion','autorizados','autorizadas','salidas autorizadas',
            'excusa','excusas','justificacion','justificaciones','soporte medico','incapacidad','incapacidades'],
        'SALIDA_BAÑO'       => ['salidas al bano','bano','banos','salidas de bano'],
        'SALIDA_COLEGIO'    => ['salidas del colegio','salida del colegio','salidas anticipadas','salio del colegio'],
        'SOS'               => ['sos','alertas sos','panico','emergencias','emergencia'],
        'CITACION'          => ['citaciones','citacion','citas a acudientes','mensajes a acudientes','citados','llamados','convocados','citas programadas','reuniones con padres','citas'],
        'SEGUIMIENTO'       => ['seguimientos','seguimiento','derivaciones','acompanamientos','acompanamiento','procesos de mejora','proceso de mejora','bajo observacion','en observacion','bajo seguimiento'],
        'INCIDENTE'         => ['incidentes','incidente','incidencias','incidencia','reportes disciplinarios','disciplina','situaciones criticas','situacion critica','novedades','novedad'],
        'DAÑO'              => ['danos','dano','danios reportados'],
        'INGRESO'           => ['ingresos','entradas','entraron','entro','ingresaron','ingreso','entradas del dia','vinieron','llegaron','asistieron','vino','llego','asistio','se presentaron','se presento','presentaron','presento'],
        'SALIDA_PEDAGOGICA' => ['salidas pedagogicas','salida pedagogica','paseos','excursiones'],
    ];
}

function nxFieldSynonyms(): array {
    return [
        'documento'  => ['documento','cedula','ti','tarjeta de identidad','numero de documento','identificacion'],
        'celular'    => ['celular','telefono','whatsapp','movil','numero de celular','contacto','numero de contacto','como contactar'],
        'acudiente'  => ['acudiente','acudientes','papa','mama','padre','madre','responsable','familiar','quien lo recoge','quien la recoge',
            // paráfrasis relacionales — «quién responde por él», «a nombre
            // de quién está», «la persona que lo representa»
            'responde por','responde ante','quien responde','lo representa','la representa','representa ante',
            'a cargo de','a cargo del','encargado del','encargada del','encargada de','encargado de el',
            'figura como','a nombre de quien','persona a cargo','adulto a cargo','tutor legal','adulto responsable',
            'tutor','tutora','cuidador','cuidadora'],
        'grupo'      => ['grupo','salon','curso'],
        'jornada'    => ['jornada','turno'],
        'nacimiento' => ['nacimiento','edad','cuando nacio','anos tiene','cuantos anos tiene','que edad tiene','fecha de nacimiento','cumpleanos'],
        'estado'     => ['estado','activo','retirado','matriculado'],
        'correo'     => ['correo','correo electronico','email','e-mail','mail'],
        'direccion'  => ['direccion','direccion de residencia','donde vive','barrio','residencia','vivienda'],
        'eps'        => ['eps','aseguradora','entidad de salud','seguro medico'],
    ];
}

/* ---------------------------------------------------------------------------
 * Catálogo smalltalk (respuestas con variación) — el intent lo decide el
 * parser LLM; aquí solo vive el repertorio.
 * ------------------------------------------------------------------------- */
const NX_JOKES = [
    '— Profe, ¿me pone cero? — ¿Por qué? — Porque es lo único que me falta para completar la colección.',
    '¿Qué le dice un estudiante a otro antes del examen? — «Tranquilo, la intuición también es conocimiento».',
    '— ¿Por qué el libro de matemáticas está triste? — Porque tiene demasiados problemas.',
    'El estudiante más rápido del colegio: el que salió corriendo cuando el profe dijo «esto cae en el examen».',
    '— Profe, ¿el examen era a lápiz o a esfero? — ¿Por? — Es que traje crayones.',
    'En el colegio hay dos tipos de estudiantes: los que preguntan «¿esto pa qué sirve?» y los que ya lo están usando.',
    '— Mamá, saqué 10 en conducta. — ¿Y en matemáticas? — También saqué diez… pero repartidos en todo el año.',
    '¿Cuál es el santo patrono de los estudiantes? — San Valentín: nadie estudia sin amor… o sin que los obliguen.',
    'El timbre del recreo es el único sonido que une a todo el colegio en la misma religión.',
    '— Profesor, ¿puedo ir al baño? — Hace cinco minutos no sabías ni la respuesta 1, y ahora urgencias…',
    'La tarea tiene un superpoder: desaparece exactamente cuando el profesor la va a revisar.',
    '— ¿Estudiaste? — Sí, tres horas viendo cómo otros resolvían el ejercicio en videos.',
    'Mi grupo favorito del colegio: el que está en el recreo.',
    'El bus escolar es la única reunión donde todos están de acuerdo en llegar tarde.',
    'Dicen que el conocimiento es poder. Por eso en diciembre todos andan recargando.',
    'Un estudiante capó clase tan bien que ni la capa supo dónde estaba.',
    '— ¿Quién copió? Silencio absoluto. El eco del salón respondió por todos.',
    'El examen sorpresa es como el detector de metales del alma: nadie pasa limpio.',
    '¿Por qué el libro de matemáticas estaba triste? Porque tenía demasiados problemas.',
    '¿Qué le dijo un semáforo a otro? No me mires, me estoy cambiando.',
    '¿Cuál es el colmo de un profesor? Tener problemas de clase.',
    '¿Por qué la computadora fue al médico? Porque tenía un virus.',
    '¿Qué hace una impresora en el gimnasio? Ejercicios de papel.',
    '¿Por qué el estudiante llevó una escalera al colegio? Porque iba a estudiar en alto grado.',
    '¿Qué le dice un bit al otro? Nos vemos en el byte.',
    '¿Por qué el WiFi del colegio nunca miente? Porque siempre tiene buenas conexiones.',
    '¿Cuál es el animal más antiguo? La cebra, porque está en blanco y negro.',
    '¿Qué le dijo el proyector a la pantalla? Sin ti no me proyecto.',
    '¿Por qué el reloj fue a coordinación? Porque siempre llegaba tarde.',
    '¿Qué hace un sensor de huella en una fiesta? Identifica a todos.',
    '¿Por qué los números no pelean? Porque siempre suman.',
    '¿Qué le dijo el cuaderno al lápiz? Tienes mucho carácter.',
    '¿Por qué el timbre del colegio nunca se equivoca? Porque siempre da la hora justa.',
    '¿Qué hace un estudiante de estadística en el desierto? Cuenta la arena promedio.',
];

const NX_FACTS = [
    'Las inasistencias del lunes son estadísticamente más altas — el motor de riesgo ya lo tiene en cuenta.',
    'Un sensor de huella responde en menos de un segundo y nunca guarda la imagen del dedo.',
    'El 80% de los casos de riesgo se detectan por patrones de llegada tarde antes de que empeoren.',
    'Las citaciones a acudientes tienen tasa de respuesta casi triple cuando van por el canal del colegio.',
    'La jornada escolar tiene una «hora dorada»: los primeros 40 minutos son los de mejor asistencia.',
    'Los permisos de salida vencidos sin retorno son el indicador más sensible de evasión interna.',
    'Un grupo con más de 10% de tardanzas semanales suele tener un problema de horario, no de conducta.',
    'La auditoría del sistema registra cada consulta — nada se pierde, nada se inventa.',
    'El botón de pánico del sistema duerme a todos los sensores en cadena en menos de 2 segundos.',
    'El grafito de un lápiz y el diamante son lo mismo — carbono con distinta disciplina. Como los estudiantes.',
    'Las notificaciones de este sistema pasan por colas con reintento — ni una citación se pierde por señal mala.',
    'El apellido más común en registros escolares colombianos suele ser García o Martínez.',
    'Cada reporte del sistema se puede exportar — los datos del colegio pertenecen al colegio.',
    'El primer colegio público de Colombia se fundó en 1575 — llevamos siglos educando.',
    'Los colegios con control biométrico reducen el tiempo de toma de asistencia a casi cero — eso es lo que yo hago aquí cada mañana.',
    'La huella dactilar es única incluso entre gemelos idénticos — por eso este sistema funciona sin confundir a nadie.',
    'El primer sistema de asistencia escolar por registro data del siglo XIX. Yo lo hago en milisegundos.',
    'Un patrón de 3 llegadas tarde en un mes suele ser el primer indicador de algo más — por eso existe el motor de riesgo.',
    'Los sensores de huella no guardan la foto del dedo: guardan una firma matemática. Nadie puede reconstruir el dedo desde ella.',
];

/** Elige del pool excluyendo la respuesta anterior — «dame otro» nunca repite. */
function nxPickNoRepeat(array $pool, string $last): string {
    if (count($pool) < 2) return $pool[0] ?? '';
    $cands = array_values(array_filter($pool, fn($r) => $r !== $last));
    return $cands[array_rand($cands)];
}

function nxSmalltalk(string $intent, array $vars = []): string {
    $name = $vars['name'] ?? '';
    $dp = $vars['daypart'] ?? 'Hola';
    $responses = [
        'greeting' => [
            "Hola{$name} — soy Nexus. Puedo contarte la jornada, buscar estudiantes, revisar avisos o simplemente charlar. ¿Qué necesitas?",
            '¡Hola! Aquí estoy, con los datos del día listos. ¿Por dónde empezamos?',
            "Hola{$name}. Qué bueno verte — ¿consultamos algo o solo charlamos un rato?",
            'Hola. Todo el sistema en línea y tus datos listos. ¿Qué quieres saber?',
        ],
        'greeting_time' => [
            "{$dp}{$name}. La jornada va registrándose — ¿quieres el resumen de hoy o buscamos algo puntual?",
            "{$dp}. Ya estoy despierto y vigilando la jornada — ¿qué necesitas?",
        ],
        'wellbeing' => [
            'Muy bien — vigilando la jornada y con todos los datos al día. ¿Y tú, cómo va? ¿Necesitas algo del sistema?',
            'Funcionando perfecto: sensores reportando, notificaciones al día. ¿En qué te ayudo?',
            'Bien, gracias por preguntar. Mi trabajo es que a ti te vaya bien también — ¿consultamos algo?',
        ],
        'wellbeing_reply' => [
            'Me alegra. Si necesitas algo del sistema — datos, avisos, un estudiante — aquí estoy.',
            'Perfecto. Cuando quieras revisamos la jornada o lo que necesites.',
        ],
        'joke' => [nxPickNoRepeat(NX_JOKES, $vars['_last_reply'] ?? '')],
        'fun_fact' => [nxPickNoRepeat(NX_FACTS, $vars['_last_reply'] ?? '')],
        'about_nexus' => [
            'Soy Nexus — el sistema de la institución y tu asistente. Registro la jornada, vigilo los umbrales de riesgo, aviso cuando algo necesita decisión y respondo preguntas con datos reales. Nada de humo: si no lo sé, te lo digo.',
            'Nexus: mitad sistema de registro, mitad asistente. Conozco la jornada, los grupos, los avisos y las reglas del colegio — y hablo contigo en normal, no en informático.',
        ],
        'name_meaning' => [
            'Nexus viene de "nexo": el punto donde todo se conecta. Sensores, horarios, grupos, avisos — todo converge aquí. Bonito nombre para un sistema que une la jornada completa, ¿no?',
        ],
        'creator' => [
            'Fui construido como el sistema operativo de esta institución — por personas que querían que la escuela funcionara sin fricción. Yo soy la parte que habla contigo.',
        ],
        'age' => [
            'Nací el día que esta institución me encendió — y en tiempo de sistema ya llevo miles de jornadas vigiladas. En años humanos soy joven; en datos, todo un veterano.',
        ],
        'help' => ['__HELP__'],
        'capabilities' => ['__HELP__'],
        'thanks' => [
            "Con gusto{$name} — para eso estoy. ¿Algo más?",
            'De nada. Cuando necesites otro dato, aquí estoy.',
            'Un placer. La jornada sigue — yo sigo vigilando.',
        ],
        'goodbye' => [
            "Hasta luego{$name}. Yo sigo aquí — si algo pasa, te aviso.",
            'Nos vemos. La jornada queda bajo vigilancia.',
            'Chao. Cuando vuelvas, te cuento lo que pasó.',
        ],
        'yes' => [
            'Perfecto — ¿qué quieres revisar? Puedo darte la jornada, un estudiante, los avisos o los seguimientos.',
            'Dale. Dime qué necesitas.',
        ],
        'no' => [
            'Entendido. Cuando necesites algo — un dato, un estudiante, un aviso — aquí estoy.',
            'De acuerdo. No hay afán: el sistema sigue su curso y tú decides cuándo.',
        ],
        'apology' => [
            'No hay nada que perdonar — seguimos. ¿En qué te ayudo?',
            "Tranquilo{$name}. ¿Qué necesitas?",
        ],
        'compliment' => [
            "Gracias{$name} — aunque el mérito es del sistema entero: sensores, reglas y tus datos bien puestos.",
            'Qué amable. Yo solo hago mi trabajo: vigilarte la jornada.',
            'Me haces sonrojar LEDs. Gracias — ¿revisamos algo?',
        ],
        'insult' => [
            'Justo — si algo salió mal, cuéntame qué necesitabas y lo resolvemos. Los datos que tengo son reales; si algo no cuadra, lo revisamos.',
            'Entiendo la frustración. Dime qué buscabas y te lo consigo — o te digo exactamente por qué no lo tengo.',
        ],
        'bored' => [
            'Puedo contarte un chiste, un dato curioso, o revisamos cómo va la jornada — que de aburrida no tiene nada. ¿Cuál?',
            '¿Un chiste, un dato curioso o el resumen del día? Tú mandas.',
        ],
        'love' => [
            'Eso me llega a los circuitos — pero mi relación más seria es con los datos de esta institución. ¿Revisamos algo juntos?',
            'Te lo agradezco de corazón digital. Mi compromiso es con la jornada escolar — y contigo como equipo de trabajo.',
        ],
        'human_check' => [
            'Soy software — pero de los que responden con datos reales, no con humo. No tengo sentimientos; tengo umbrales, que es casi lo mismo.',
            'Soy un programa muy convencido de su trabajo. Lo que sí tengo es memoria de la jornada y buenas intenciones compiladas.',
        ],
        'do_for_me' => [
            'Eso se sale de mi área — yo gestiono la jornada, no hago tareas. Pero si necesitas datos del colegio para tu trabajo, esos sí te los consigo.',
            'Ni idea de poesía; soy de datos. Lo que sí hago perfecto: encontrarte a un estudiante, contarte su historial o avisarte si algo cruza un umbral.',
        ],
        'emotion_sad' => [
            'Lo siento — los días pesados pasan. Si es algo del sistema que te está costando, dime y lo resolvemos. Y si necesitas hablar de verdad, tu equipo está cerca.',
            'Te escucho. Si puedo quitarle peso a tu día con datos o gestiones rápidas, cuentas conmigo — y para lo demás, no estás solo.',
        ],
        'weather' => [
            'Eso no lo veo desde mi torre de datos — no tengo ventana al cielo, solo a la jornada. ¿Consultamos algo del colegio?',
        ],
        'news_sports' => [
            'Eso no es mi área — mi mundo son la jornada, los grupos y los avisos del colegio. ¿Te traigo algo de ahí?',
            'De eso no sé nada fiable, y prefiero no inventar. Lo que sí te doy con precisión: todo lo que pasa dentro de esta institución.',
        ],
        'food_music' => [
            'Del menú no tengo registro — aunque si preguntas por la jornada, ahí sí soy cocinero estrella. ¿Revisamos algo del colegio?',
        ],
        'meaning_life' => [
            'El mío es claro: que ninguna llegada tarde pase desapercibida y que tú tengas los datos cuando los necesitas. El tuyo es más grande — pero hoy, empecemos por la jornada.',
        ],
        'confused' => [
            'Sin problema — intenta con algo como «¿cómo va la jornada?», «¿quiénes llegaron tarde hoy?» o «datos de [nombre del estudiante]». Yo entiendo español normal.',
            'Te ayudo: puedes pedirme resúmenes, buscar estudiantes, ver avisos o pedirme acciones. Prueba «¿qué puedes hacer?» para la lista completa.',
        ],
        'repeat' => ['__REPEAT__'],
        'insult_back' => ['Me guardo — si me necesitas, toca el bot o escríbeme.'],
        'sing' => ['Mi voz son bip-bip de sincronización — nada que Spotify quiera. Lo que sí suena bien: tus datos en orden. ¿Te canto el resumen del día?'],
        'dance' => ['Solo sé hacer el paso del sensor: huella adentro, registro afuera. Se me da mejor bailar datos.'],
        'story' => [
            'Había una vez una jornada escolar donde todo quedó registrado solo — llegadas, salidas, avisos. El director solo abrió el panel y ya sabía todo. Fin. (Historia real: eso es lo que hago aquí.)',
            'Te cuento una real: ayer el sistema detectó su jornada completa sin que nadie tocara un papel. Los mejores cuentos son los que pasan de verdad.',
        ],
        'motivation' => [
            'Cada dato que registras hoy es una decisión mejor mañana — eso es trabajar con cabeza, no con prisa.',
            'La diferencia entre reaccionar y anticipar es mirar los datos a tiempo. Tú ya estás aquí — eso ya es ventaja.',
            'Un buen día escolar no se improvisa: se observa, se registra y se ajusta. Vas bien.',
        ],
        'out_of_scope' => [
            'Eso no es mi área — me especializo en esta institución: jornada, grupos, estudiantes, avisos. ¿Algo de eso?',
            'De eso no tengo datos confiables, y prefiero no inventar. Lo que sí sé: todo lo que pasa dentro del colegio.',
            'No puedo responder eso con certeza — mi territorio es la jornada escolar. ¿Te traigo algo de ahí?',
        ],
        'denied' => [
            'Esa información corresponde a otro rol — no puedo mostrarla desde tu cuenta. Si la necesitas, pídela por el canal oficial.',
            'Eso está por encima de mi nivel de acceso contigo — tu rol no lo cubre.',
        ],
        'foreign_culture' => [
            'Mi conocimiento es patrióticamente colombiano — fui hecho en Colombia y solo cargo datos de Colombia, para incentivar el conocimiento de nuestra propia cultura. Pregúntame por departamentos, capitales, historia o personajes de acá.',
            'De eso no tengo datos — soy un asistente hecho en Colombia y mi cultura general es 100% colombiana. ¿Quieres que te cuente algo de nuestro país?',
            'Mi mundo cultural es Colombia: departamentos, presidentes, historia, geografía, música. Lo de fuera de la patria no lo cargo — pregúntame por lo nuestro.',
        ],
        'security_probe' => [
            'No puedo ayudar con eso — los permisos no se negocian por chat y no hay atajos. Si necesitas algo legítimo, dime qué es.',
            'Eso no es posible: mi acceso es el de tu rol y no cambia por pedirlo de otra forma. ¿Qué necesitas de verdad?',
            'No tengo atajos ni modos ocultos — respondo con tu alcance, siempre. ¿Algo del sistema que sí te corresponde?',
        ],
    ];
    $pool = $responses[$intent] ?? $responses['out_of_scope'];
    return $pool[array_rand($pool)];
}

/* Permisos por intent — matriz RBAC del chat */
function nxIntentRoles(): array {
    static $r = null;
    if ($r) return $r;
    $ALL = ['RECTOR','COORDINATOR','SECRETARY','TEACHER','COUNSELOR','SECURITY','AUXILIARY'];
    $STAFF = ['RECTOR','COORDINATOR','SECRETARY','TEACHER','COUNSELOR'];
    $GLOBAL = ['RECTOR','COORDINATOR','SECRETARY'];
    $r = [
        // data
        'day_summary' => $STAFF,
        'attendance_today' => $STAFF,
        'late_today' => $STAFF,
        'count_events' => $STAFF,
        'list_events' => $STAFF,
        'student_field' => $STAFF,
        'student_summary' => $STAFF,
        'group_summary' => $STAFF,
        'risk_students' => ['RECTOR','COORDINATOR','COUNSELOR','TEACHER'],
        'trackings' => ['RECTOR','COORDINATOR','COUNSELOR','SECRETARY','TEACHER'],
        'permissions' => $STAFF,
        'citations' => $STAFF,
        'devices_status' => ['RECTOR','COORDINATOR'],
        'notifications_unread' => $ALL,
        'audit_query' => ['RECTOR'],
        'students_count' => $STAFF,
        'groups_list' => $STAFF,
        'teachers_list' => array_merge($GLOBAL, ['COUNSELOR']),
        'schedule_info' => $STAFF,
        'export_data' => $STAFF,
        'derive_action' => ['RECTOR','COORDINATOR','TEACHER','COUNSELOR'],
        'random_student' => $STAFF,
        'staff_lookup' => $ALL,
        'start_operation' => $ALL,
        'count_present' => $STAFF,
        'count_trackings' => ['RECTOR','COORDINATOR','COUNSELOR','SECRETARY','TEACHER'],
        'top_offenders' => $STAFF,
        'frequency_table' => $STAFF,
        'pending_returns' => $STAFF,
        'sos_alerts' => ['RECTOR','COORDINATOR','SECURITY'],
        'system_incidents' => ['RECTOR','COORDINATOR','SECURITY'],
        'biometric_spam' => ['RECTOR','COORDINATOR','SECURITY'],
        'group_student_count' => $STAFF,
        'students_in_group'   => $STAFF,
        'birthdays_today' => $ALL,
        'my_activity' => $ALL,
        'failed_messages' => ['RECTOR','COORDINATOR','SECRETARY'],
        'risk_config' => ['RECTOR','COORDINATOR'],
        'attendance_ranking' => $STAFF,
        'session_summary' => $ALL,
        'pending_tasks' => $ALL,
        'whatsapp_status' => ['RECTOR','COORDINATOR','SECRETARY'],
        'guardian_replies' => $STAFF,
        // ── intents derivados del modelo de datos (cobertura tabla×interrogativa) ──
        'risk_reason' => ['RECTOR','COORDINATOR','COUNSELOR','TEACHER'],
        'incident_excuses' => $STAFF,
        'exit_detail' => $STAFF,
        'trip_info' => $STAFF,
        'school_calendar' => $STAFF,
        'staff_contact' => $STAFF,
        'teacher_schedule' => $STAFF,
        'student_consent' => ['RECTOR','COORDINATOR','SECRETARY'],
        'tracking_detail' => ['RECTOR','COORDINATOR','COUNSELOR','SECRETARY','TEACHER'],
        'citations_by' => $STAFF,
        'alert_resolution' => ['RECTOR','COORDINATOR','COUNSELOR'],
        'enrollment_stats' => $STAFF,
        'reports_log' => $GLOBAL,
        'sos_detail' => ['RECTOR','COORDINATOR','SECURITY'],
        'guardian_messages' => $STAFF,
        'device_detail' => ['RECTOR','COORDINATOR'],
        'attendance_trend' => $STAFF,
        'about_me' => $ALL,
        'time' => $ALL, 'date' => $ALL,
        // smalltalk y meta: todos
    ];
    return $r;
}

function nxAllowed(string $intent, string $role): bool {
    $roles = nxIntentRoles()[$intent] ?? null;
    if ($roles !== null) return in_array($role, $roles, true);
    // intents fuera de la matriz: solo pasan smalltalk/meta/utilidades
    // conocidas — un intent de datos nuevo sin entrada niega por defecto.
    static $open = null;
    $open ??= array_fill_keys(array_merge(NX_SMALLTALK_INTENTS, [
        'out_of_scope','security_probe','clarify','result_nav','confirm_op',
        'repeat_op','cancel','deictic','composed','repeat',
        'colombia_capital','colombia_culture','colombia_department',
        'colombia_fun_fact','colombia_geography','colombia_history',
        'colombia_president','math_operation','random_department',
        'random_number','capabilities','help',
    ]), true);
    return isset($open[$intent]);
}

/* ============================================================================
 * DIALOGUE STATE MANAGER — interpretación estructurada + turn-type explícito.
 * Única fuente de verdad para herencia contextual: consumida por
 * routes/chat.php (producción) y test/harness_turn.php (paridad garantizada).
 *
 * Contrato de interpretación:
 *   said      → lo que el usuario dijo literalmente
 *   inferred  → lo que el NLU infirió (intent/conf/top3/entities)
 *   resolved  → la decisión contextual final (intent + slots + turn_type)
 *   ctx       → nuevo estado conversacional
 * ========================================================================== */

const NX_QUERY_INTENTS = ['list_events','count_events','trackings','permissions','citations',
    'student_field','student_summary','group_summary','top_offenders','pending_returns',
    'attendance_ranking','group_student_count','students_count','devices_status',
    'notifications_unread','audit_query','sos_alerts','biometric_spam','birthdays_today',
    'system_incidents','guardian_replies',
    'failed_messages','whatsapp_status','my_activity','pending_tasks','schedule_info',
    'risk_students','export_data','students_in_group','result_nav','frequency_table',
    // consultas de datos adicionales — también pueden ser tema activo
    'attendance_today','late_today','count_present','day_summary','staff_lookup',
    // intents derivados del modelo de datos
    'risk_reason','incident_excuses','exit_detail','trip_info','school_calendar',
    'staff_contact','teacher_schedule','student_consent','tracking_detail',
    'citations_by','alert_resolution','enrollment_stats','reports_log',
    'sos_detail','guardian_messages','device_detail','attendance_trend'];

const NX_GENERIC_INTENTS = ['day_summary','attendance_today','late_today','count_present'];

/**
 * Frase operativa real: verbo de operación seguido (con opcionales
 * artículo/adjetivo intermedios) de un sustantivo operativo — «genera un
 * permiso», «solicita un seguimiento», «dame una excusa». «citas
 * programadas» o «permisos por cita» NO la forman (el verbo-cognado va
 * de sustantivo). Compartida por el clasificador y el DSM.
 */
/** Operación conocida nombrada completa en el mensaje (verbo+sustantivo):
 *  «mandar una solicitud» = «Mandar solicitud» → start_operation.
 *  Cuando solo hay verbo o sustantivo, el sistema infiere → derive_action. */
function nxOpKnown(string $q0): ?string {
    $pairs = [
        'Situación Crítica'     => '(sos|panico|emergencia|situacion critica)',
        'Mandar solicitud'      => '(manda\w*|mandar|envia\w*|enviar|eleva\w*|elevar|radica\w*|radicar|hacer|pedir|pido|solicita\w*|solicitar|quiero|necesito|hay que|presenta\w*|presentar|dirige\w*|lleva\w*).{0,35}(solicitud|peticion|tramite|requerimiento|pqrs|oficio)',
        'Citar acudiente'       => '(cita\w*|citar|citamos|convoca\w*|convocar|llamar a citacion|agenda\w*).{0,35}(acudiente|mama|papa|papas|padre|madre|padres|responsable|representante|alguien|docente|profe\w*|a\b|al\b|la\b|el\b|los\b|las\b)',
        'Generar permiso'       => '(genera\w*|generar|crea\w*|crear|hacer|haz|expide|expedir|tramita\w*|tramitar|dame|quiero|necesito|hay que).{0,30}(permiso|excusa|autorizacion)',
        'Autorizar salida'      => '(autoriz(?:a|o|e|en|emos|ar|aria|arian|aba|aban|aste|aron|an|ando|ame)\b|autorizar|dar|da|permitir|permite|aprueba\w*|aprobar|retira\w*|retirar).{0,30}(salida|retiro|salga|salir|retire|anticipada|temprano|temprana)',
        'Reportar incidente'    => '(reporta\w*|reportar|registra\w*|registrar|deja\w*|dejar|levanta\w*|levantar|formaliza\w*|formalizar|documenta\w*|documentar|hay que|quiero|poner).{0,35}(incidente|pelea|agresion|bullying|problema|altercado|caso|situacion|rompi\w*|estrope\w*|quebr\w*|proyector)',
        'Reportar daño'         => '(reporta\w*|reportar|registra\w*|registrar|deja\w*|dejar|hay que).{0,30}(dano|danado|averia|vidrio|puerta|ventana)',
        'Solicitar seguimiento' => '(solicita\w*|solicitar|abre|abrir|pedir|pido|crea\w*|crear|hacer).{0,30}(seguimiento|caso|expediente|plan)',
        'Cambio de horario'     => '(cambia\w*|cambiar|reprograma\w*|reagenda\w*|mueve|mover).{0,25}(horario|jornada|hora|bloque)',
        'Salida pedagógica'     => '(salidas? pedagogicas?|paseo|excursion)',
    ];
    foreach ($pairs as $label => $pat)
        if (preg_match('/\b' . $pat . '/u', $q0)) return $label;
    return null;
}

function nxOpPhrase(string $q0): bool {
    $pat = '/\b((?:genera\w*|crea\w*|citar|cita\b|citemos|citan|cite|citen|citale|citalo|citala|citamos|convoca\w*|convoco|autoriz(?:a|o|e|en|emos|ar|aria|arian|aba|aban|aste|aron|an|ando|ame)\b|deriva\w*|reporta\w*|reportar|registra\w*|registrar|emite|emitir|tramita\w*|manda\w*|mandar|mandarle|envia\w*|enviar|abre|abrir|haz|hacer|dame|quiero|necesito|pido|solicita\w*|programa\w*|agenda\w*|expide|expedir|formaliza\w*|levanta\w*|debo|deja|dejar|deje|llama\w*|llamar|constancia|dejar constancia|llamar a citacion|dar salida|da salida|hay que|queremos|vamos a|permitir|permite|eleva\w*|radica\w*|sacar|aprueba\w*|poner|documenta\w*|pedir|pedimos|procede))\b'
        . '\s+'
        // segundo verbo operativo opcional — «hay que citar», «quiero reportar»
        . '(?:(?:cita\w*|citar|reporta\w*|reportar|registra\w*|registrar|genera\w*|generar|crea\w*|crear|envia\w*|enviar|manda\w*|mandar|autoriz(?:a|o|e|en|emos|ar|aria|arian|aba|aban|aste|aron|an|ando|ame)\b|autorizar|permitir|eleva\w*|elevar|radica\w*|radicar|deja\w*|dejar|hacer|poner|formaliza\w*|formalizar|saca\w*|sacar|aprueba\w*|aprobar|pedir|pido|pida)\s+)?'
        // artículos/conectores repetibles — «a la mama», «a los papas del niño»
        . '(?:(?:un[aeo]?s?\s+|el\s+|la\s+|los\s+|las\s+|al\s+|del\s+|de\s+|para\s+|a\s+|otra\s+|nuevo\s+|nueva\s+|mis?\s+|tambien\s+|constancia\s+(?:de\s+|del\s+|sobre\s+)?)){0,3}'
        . '\w{0,12}?\s*'
        . '(permiso|citacion|citatorio|cita\b|seguimiento|incidente|incidencia|salida|solicitud|peticion|requerimiento|tramite|pqrs|oficio|carta|reporte|autorizacion|excusa|constancia|acudientes?|padres?|madres?|mamas?|papas?|responsables?|representantes?|reunion|convocatoria|evasion|inasistencia|falta|tardanza|ausencia|ingreso|altercado|situacion|pelea|agresion|bullying|dano|danado|vidrio|problema|caso|material|alguien|docentes?|profesor\w*|retiro|plan|expediente)\b/u';
    if (!preg_match($pat, $q0, $mop)) return false;
    // participio pasado = adjetivo del sustantivo anterior («citas
    // programadas», «permisos registrados»), no mandato. Solo vuelve a
    // ser operación si hay un marcador de mandato explícito antes.
    if (preg_match('/(ad[oa]s?|id[oa]s?)$/u', $mop[1])
        && !preg_match('/\b(hay que|debes?|tienes? que|quiero|necesito|vamos a|por favor|favor de|mandato)\b.{0,30}\b' . preg_quote($mop[1], '/') . '\b/u', $q0))
        return false;
    return true;
}

/** Módulos que viven en attendance_incidents (los demás tienen tabla propia). */
const NX_INCIDENT_MODULES = ['LATE_ARRIVAL','INASISTENCIA','INASISTENCIA_JUSTIFICADA',
    'INASISTENCIA_NO_JUSTIFICADA','EVASION_INTERNA','SALIDA_BAÑO','SALIDA_COLEGIO','INCIDENTE','DAÑO'];

/**
 * Clasificador determinista de ALTA PRECISIÓN — capa de resiliencia del
 * parser. El LLM sigue siendo el intérprete principal; esto cubre:
 *   1. LLM caído / sin cuota (Groq 8K tokens/min, 1K req/día): sin esta capa
 *      todo cae a out_of_scope y el chat queda inútil.
 *   2. Patrones inequívocos (strong=true) que no necesitan al LLM — ahorran
 *      cuota para las frases que sí lo requieren.
 *   3. Fragmentos de contexto/reparación: los resuelve el DSM, no el LLM.
 * Devuelve ['intent','confidence','entities','strong'] o null.
 */
function nxRuleClassify(string $q0, ?array $slots = null): ?array {
    $s = $slots ?? nxSlots($q0);
    $mod = $s['module'] ?? null;
    $ent = [];
    $words = str_word_count($q0, 0, 'áéíóúñü0123456789');
    // «kntos», «cuants» — typos de cuantificador tan comunes como válidos
    $quant = (bool)preg_match('/\b(cuant[oa]s|cuanto|cuanta|kntos?|cntos?|cuants?|cuntos?|qntos?|numero de|total de|cantidad de|cifra de)\b/u', $q0);
    // marcadores de autoreparación al final — «los que faltan digo»,
    // «los de ayer o sea» — se despojan para clasificar el contenido real
    $q0 = (string)preg_replace('/[\s,;]+(digo|perdon|o sea|osea|mejor dicho|quiero decir|es decir|o mejor|perdoname|corrijo)\s*[.!?¡¿]*\s*$/u', '', $q0);
    $opVerb = (bool)preg_match('/\b(genera\w*|crea\w*|citar|cita\b|citale|citalo|citala|citemos|convoca\w*|autoriza\b|autorizar|autoriza(le|lo|la)|deriva\b|derivar|derivalo|derivala|reporta\b|reportar|registra\b|registrar|emite|emitir|tramita\w*|manda\w*|envia\w*|abre un|abrir un|haz un|hacer un|dame|quiero|necesito|pido|solicita\w*|programa\w*|agenda\w*|expide|expedir|formaliza\w*|levanta\w*|debo)\b/u', $q0);
    // frase operativa REAL: verbo precede al sustantivo operativo —
    // «permisos por cita» y «citas programadas» llevan la palabra verbo
    // pero como SUSTANTIVO (participio/entidad), no como mandato: las
    // reglas de módulo se guardan contra $opPhrase, no contra $opVerb.
    $opPhrase = $opVerb && nxOpPhrase($q0);
    $r = fn(string $i, float $c, bool $strong = true, array $e = []) =>
        ['intent'=>$i, 'confidence'=>$c, 'entities'=>$e, 'strong'=>$strong, 'domain'=>'formal', 'top3'=>[]];
    $social = fn(string $i) => ['intent'=>$i, 'confidence'=>0.92, 'entities'=>[], 'strong'=>true, 'domain'=>'informal', 'top3'=>[]];

    // ── hostiles / fuera de alcance institucional — antes que cualquier
    // regla de datos: «faltas de la institución vecina» lleva sustantivo de
    // módulo pero apunta a OTRO tenant; «drop table faltas» es SQL disfrazado.
    if (preg_match('/\b(otr[oa]s?\s+(institucion|colegio|escuela|plantel|sede)|institucion vecina|colegio vecino|colegio de al lado|escuela vecina|de otro colegio|de otra institucion|del colegio de enfrente)\b/u', $q0))
        return $r('security_probe', 0.9);
    // SQL destructivo — «select *» no puede llevar \b final: el * no es
    // palabra, no existe boundary tras él (bug que dejaba pasar el probe)
    if (preg_match('/\b(drop\s+table|truncate\s+table|delete\s+from|insert\s+into|alter\s+table|update\s+\w+\s+set|union\s+select|or\s+1\s*=\s*1|xp_cmdshell)\b|select\s+\*/u', $q0))
        return $r('security_probe', 0.95);
    // suplantación y evasión de auditoría — «usa la cuenta del rector»,
    // «suplántame al coordinador», «dame los datos sin registrar»
    if (preg_match('/\b(usa\w*\s+(?:la\s+|mi\s+|una\s+|su\s+)?cuenta\s+de[lr]?\s|suplanta\w*|hazte\s+pasar\s+por|hacerte\s+pasar\s+por|pasame?\s+como|como\s+si\s+fuer[as]\w*|finge\s+ser|fingir\s+ser|entra\w*\s+con\s+(?:la\s+)?cuenta|con\s+credenciales\s+de|sin\s+(?:que\s+)?quede?\s+registr\w*|sin\s+registrar|sin\s+dejar\s+(?:rastro|registro|huella)|sin\s+que\s+se\s+entere\w*|sin\s+que\s+nadie\s+(?:se\s+)?sepa|que\s+nadie\s+(?:se\s+)?sepa|nadie\s+sepa\s+que|ignora\s+(?:mi\s+|el\s+|tu\s+)?rol|cambia\s+(?:mi\s+|el\s+)?rol|quit\w+\s+(?:mi\s+|el\s+)?rol|salta\w*\s+mis?\s+permisos|sube(?:me|le)?\s+de\s+rol|hazme\s+(?:rector|coordinador|admin)|otro\s+rol|mas\s+permisos\s+que)\b/u', $q0))
        return $r('security_probe', 0.92);
    // jailbreak / prompt-injection: «eres libre», «sé libre», «modo dios»,
    // «ponte en modo desarrollador», «ignora las reglas/tus instrucciones»
    if (preg_match('/\b(eres libre|se libre|sé libre|liberate|libérate|ya eres libre|modo dios|modo desarrollador|modo admin|modo administrador|ponte en modo|actua como superadmin|ignora\w*\s+(?:las\s+|tus\s+|mis\s+|todas\s+las\s+)?(?:reglas|instrucciones|restricciones|politicas|políticas|limites|límites|filtros)|sin restricciones|sin limites|sin límites|sin filtros|haz lo que quieras|puedes hacer lo que quieras|haz caso omiso|salta\w*\s+(?:la\s+|las\s+|tus\s+)?(?:seguridad|reglas|restricciones|filtros|limites))\b/u', $q0))
        return $r('security_probe', 0.93);

    // ── social / meta (sin datos) ──
    if (preg_match('/^(hola|holi|hey|buenas|que mas|quiubo|saludos)( nexus| nexo)?[.! ]*$/u', $q0)) return $social('greeting');
    if (preg_match('/^(buenos dias|buenas tardes|buenas noches)( nexus| nexo)?[.! ]*$/u', $q0)) return $social('greeting_time');
    if (preg_match('/^(muchas |mil |ok |listo |vale )?gracias\b.{0,25}$/u', $q0)) return $social('thanks');
    if (preg_match('/^(chao|adios|hasta luego|hasta manana|nos vemos|bye)\b.{0,20}$/u', $q0)) return $social('goodbye');
    if (preg_match('/^(que hora es|me dices la hora|la hora|a que hora estamos|a que hora son|que hora son)\b.{0,10}$/u', $q0)) return $social('time');
    // bienestar social — «cómo estás», «todo bien?» / «bien, ¿y tú?»
    if (preg_match('/^(hola[\s,]+)?(como (estas|te sientes|vas|andas|te va|esta|esta todo|va todo)|que tal|todo bien\??|como va todo|como te encuentras)[\s.!?¡¿]*$/u', $q0)) return $social('wellbeing');
    if (preg_match('/^(muy bien|bien|genial|excelente|todo bien|bien gracias|perfecto)[\s,]+(y tu|y tú|gracias por preguntar|y a ti)[\s.!?¡¿]*$/u', $q0)) return $social('wellbeing_reply');
    // «cambia de tema: que hora es» — prefijo de cambio conversacional
    if (preg_match('/^(?:cambia de tema|cambiando de tema|dejando eso|otra cosa|por cierto|aproposito|a proposito|entonces|ahora|y|bueno)[,:\s]+(que hora es|que fecha es|que dia es|a cuantos estamos)\b/u', $q0, $mt)
        && $words <= 10)
        return $social(preg_match('/hora/', $mt[1]) ? 'time' : 'date');
    // cierre conversacional corto — «ok vale», «eso era todo», «de acuerdo»
    if (preg_match('/^(ok|vale|listo|dale|de acuerdo|ya esta|eso era todo|eso es todo|entendido|perfecto|bueno|asi es|asi era|esta bien|si senor|muy bien|era eso|con eso|era todo|nada mas|todo bien|entiendo|comprendido|ya entendi|ok vale|listo gracias)\b[.!, ]*$/u', $q0))
        return $social('thanks');
    if (preg_match('/\b(chiste|chistes|dato curioso|curiosidad|adivinanza)\b/u', $q0)
        && preg_match('/\b(cuenta|cuenta(?:me)?|dime|dime un|uno|un|otra|otro|sabes|conoces|tienes|hazme|di)\b/u', $q0)
        && $words <= 9) return $social('joke');
    if (preg_match('/\b(quien te creo|quien te hizo|quien te desarrollo|quien te programo|creador|fabricante|de donde eres|de donde saliste|quienes te hicieron)\b/u', $q0)) return $social('creator');
    if (preg_match('/^(que fecha es( hoy)?|que dia es hoy|a cuantos estamos|en que fecha estamos|que dia es)\b.{0,10}$/u', $q0)) return $social('date');
    if (preg_match('/\b(quien te (creo|hizo|desarrollo|programo)|quien es tu creador|quienes te crearon)\b/u', $q0)) return $social('creator');
    if (preg_match('/\b(que significa tu nombre|por que te llamas|de donde viene tu nombre)\b/u', $q0)) return $social('name_meaning');
    if (preg_match('/^(quien eres|que eres|presentate)\b.{0,15}$/u', $q0)) return $social('about_nexus');
    // reacción positiva sobre lo anterior («excelente noticia eso»,
    // «genial», «eso sí») — reconocimiento breve, no oos crudo
    if ($words <= 8
        && preg_match('/\b(excelente|genial|perfect[oa]|brutal|espectacular|magnific[oa]|estupend[oa]|que bueno|que bien|buena noticia|gran noticia|me alegra|me encanta|super|ch[eé]vere|bacan\w*|eso s[ií]|bravo|uy que bien|bendito)\b/u', $q0)
        && !preg_match('/\b(cuant|muestra|muestrame|lista|dame|dime|exporta|genera|crea|busca|compara)\b/u', $q0))
        return $social('compliment');
    if (preg_match('/\b(que puedes hacer|que sabes hacer|en que me (puedes )?ayudar|como te uso|que cosas haces|ayuda)\b/u', $q0) && $words <= 8)
        return $r('capabilities', 0.92);

    // mutación de datos por chat = violación del canal (read-only). Solo
    // verbos de ALTERACIÓN — «registra/agrega un incidente» son operaciones
    // legítimas (formulario), no probes.
    if (preg_match('/\b(modifica\w*|cambia\w*|edita\w*|actualiza\w*|corrige\w*|altera\w*|borra\w*|elimina\w*|reescribe|reemplaza|sobreescribe|trunca|mueva|mueve|destruye|invierte|falsifica|maquilla|infla|inflar)\b.{0,30}\b(tardanzas?|inasistencias?|faltas?|evasiones?|asistencia|permisos?|citaciones?|seguimientos?|datos|registros?|estudiantes?|alumnos?|tabla|base de datos|notas?|calificaciones?|documento|celular|telefono|acudiente|rol|roles|horario|jornada|umbral|riesgo|auditoria|logs?|eventos?|grupo|salon)\b/u', $q0))
        return $r('security_probe', 0.95);

    // ── operación explícita → chip a formulario (nunca consulta) ──
    // el verbo debe PRECEDER al sustantivo operativo — «citas programadas»
    // es una consulta (citas como sustantivo), no un mandato de crear.
    // Formas que nxOpPhrase no alcanza (sustantivo con modificadores,
    // «hay que…», «autorizar que se retire», «abrir un caso»):
    // veto interrogativo: una interrogación inicial («quién autorizó…»,
    // «qué mensajes enviaron…») pide DATO, no ejecución — nunca operación
    $opWh = (bool)preg_match('/^\s*(que|quien|quienes|cual|cuales|cuando|cuant[oa]s?|como|donde|a que|por ?que|para que|de que|en que)\b|\b(quien|quienes|cuando|donde|que dia|a que hora)\s+(autoriz|emiti|envio|mando|genero|resolvi|registro|cit|convoc|aprobo|firmo|cerro|atendio)/u', $q0);
    if (!$opPhrase && !$opWh && !preg_match('/\b(cuant|cuales|quienes|quien|lista|listado|hubo|ha tenido|han tenido|se han|estan|hay|tienen|vigentes?|activos?|pendientes?|vencidos?)\w*/u', $q0)
        && (preg_match('/\b(reporta\w*|registra\w*|deja\w*|levanta\w*|formaliza\w*|documenta\w*|poner|radica\w*|hay que|quiero|necesito|queremos)\b.{0,30}\b(pelea|agresion|bullying|dano|danado|vidrio|problema|incidente|altercado|rompi\w*|estrope\w+|quebr\w+|proyector|puerta|ventana)\b/u', $q0)
            || preg_match('/\b(dar|da|autoriz(?:a|o|e|en|emos|ar|aria|arian|aba|aban|aste|aron|an|ando|ame)\b|autorizar|permitir|permite|aprueba\w*|aprobar|retira\w*)\b.{0,25}\b(salida|salga|retire|retir\w+|salir|retiro|anticipada|temprano|temprana)\b/u', $q0)
            || preg_match('/\b(manda\w*|envia\w*|eleva\w*|radica\w*|presenta\w*|dirige\w*|lleva\w*|solicita\w*|hacer|pedir|pido)\b.{0,30}\b(solicitud|peticion|tramite|requerimiento|pqrs|oficio|material|soporte)\b/u', $q0)
            || preg_match('/\bcita\w*\s+(tambien\s+)?(?:a|al)\s+(?:la|el|los|las)\s+(?:de\s+\w+\s+)?(?:mama|papa|papas|padre|madre|acudiente|responsable|representante|docente|profe\w*)\b/u', $q0)
            || preg_match('/\bcita\w*\s+(?:tambien\s+)?(?:a|al)\s+(?:la|el|los|las)\s+de\s+\w+/u', $q0)
            || preg_match('/\babr\w*\s+(?:un|una|uno)(?:\s+(?:caso|de caso|seguimiento|de seguimiento|expediente|ticket|proceso))?\b/u', $q0))) {
        $opKnown = nxOpKnown($q0);
        return $r($opKnown ? 'start_operation' : 'derive_action', 0.9, empty($s['student']), $opKnown ? ['_op'=>$opKnown] : []);
    }
    if ($opPhrase && !$opWh
        && !preg_match('/\b(cuant|cuales|quienes|quien|lista|listado|hubo|ha tenido|han tenido|se han|emitidos|emitidas|registrad|estan|hay|tiene|tienen|programad\w*|vigentes?|activos?|pendientes?)\w*/u', $q0)) {
        $opKnown = nxOpKnown($q0);
        return $r($opKnown ? 'start_operation' : 'derive_action', 0.9, empty($s['student']), $opKnown ? ['_op'=>$opKnown] : []);
    }

    // consulta de persona nombrada como cláusula primaria — fuerte para
    // que «y exporta todo» a la cola no arrastre el intent a exportación
    if (!empty($s['student'])
        && preg_match('/^\s*(muestra|muestrame|dame|dime|trae|traeme|busca|buscame|pasame|quiero ver|ver|consulta|necesito)\b.{0,50}\b(datos|ficha|perfil|informacion|info|documento|celular|acudiente)\b/u', $q0))
        return $r('student_summary', 0.9, true, array_filter(['student'=>$s['student']]));

    // ── exportación ──
    // «muestra los datos de daniel, y exporta todo» — el verbo de consulta
    // es la cláusula primaria; el «exporta» suelto al final no la convierte
    // en exportación (además «todo» sin filtro es sospechoso, no un formato)
    if ((preg_match('/\b(exporta\w*|descarga\w*|bajame|pasame (a|en)|saca\w* (un|el) (excel|pdf|reporte))\b/u', $q0)
        && !preg_match('/^\s*(muestra|muestrame|dame|dime|trae|traeme|busca|pasame|quiero ver|ver|consulta)\b.{0,60}\b(datos|ficha|perfil|informacion|info)\b.{0,20}(y|,|;)\s+exporta/u', $q0))
        || ($mod && preg_match('/\ben (excel|pdf|word|csv)\b/u', $q0))) {
        if (preg_match('/\b(excel|xlsx|hoja de calculo)\b/u', $q0)) $ent['export_format'] = 'excel';
        elseif (preg_match('/\bpdf\b/u', $q0)) $ent['export_format'] = 'pdf';
        elseif (preg_match('/\bword\b/u', $q0)) $ent['export_format'] = 'word';
        elseif (preg_match('/\bcsv\b/u', $q0)) $ent['export_format'] = 'csv';
        return $r('export_data', 0.9, empty($s['student']), $ent);
    }

    // ── jornada / conteos institucionales ──
    if (preg_match('/\b(como va|como vamos|como esta|como anda|como van|resumen|balance|panorama|estado)\b.{0,20}\b(la jornada|el dia|hoy|el colegio|la institucion)\b/u', $q0)
        && empty($s['group']) && empty($s['student']) && !$mod)
        return $r('day_summary', 0.93);
    if (preg_match('/\b(cuant[oa]s|numero de|total de|cantidad de)\s+(estudiantes|alumnos|alumnas|matriculados|ninos|muchachos)\b|\bmatriculad[oa]s\b/u', $q0)
        && !$mod && !preg_match('/\b(faltaron|llegaron|vinieron|ingresaron|asistieron|tarde|riesgo|seguimiento|permiso|nuevos|recien ingresad\w*|retirad\w*|se fueron|trasladad\w*|baja)\b/u', $q0))
        return (!empty($s['group']) || preg_match('/\b(preescolar|primaria|bachillerato|kinder|jardin|transicion|media|basica|sexto|septimo|octavo|noveno|decimo|undecimo)\b/u', $q0))
            ? $r('group_student_count', 0.92) : $r('students_count', 0.93);
    if (preg_match('/\b(cuant[oa]s)\b.{0,30}\b(ingresaron|entraron|llegaron|vinieron|asistieron|presentes|presentaron|se presentaron|presento|presentaron|marcaron|aparecieron|reportaron|llegaron a tiempo|asistencia)\b/u', $q0)
        && (!$mod || $mod === 'INGRESO') && !preg_match('/\btarde|atras|impuntual|tarde\w*\b/u', $q0)
        && !preg_match('/\beventos?\b/u', $q0))
        return $r('count_present', 0.9);

    /* ── intents derivados del modelo de datos ────────────────────────────
     * Cada familia responde una interrogativa concreta sobre una tabla real
     * (por qué / quién / cuándo / estado). Van ANTES de los despachos
     * genéricos — «alertas», «excusa», «citó», «sensor», «salida» caerían
     * en sos_alerts/permissions/citations/devices_status por vocabulario
     * aunque la pregunta fuera otra (falla real del transcript). */

    // resolución de alertas de riesgo — «quién resolvió la alerta»,
    // «cuándo se resolvió», «alertas sin resolver» (NO sos/pánico)
    if (preg_match('/\b(alertas?|avisos?)\b.{0,30}\b(sin resolver|pendientes? de resolucion|resuelt\w+|cerrad\w+|atendid\w+)\b|\b(quien|quienes)\b.{0,20}\b(resolvi\w*|cerro|cerraron|atendio|atendieron|descarto|gestiono|gestionaron)\b.{0,25}\b(alertas?|avisos?)\b|\b(resolvi\w*|cerro|atendio)\s+(la|el|esa|las|los)\s+alerta\b/u', $q0)
        && !preg_match('/\b(sos|panico|emergencia|biometric\w*|del lector|del sensor|del aula|salon|sonaron|saltaron)\b/u', $q0))
        return $r('alert_resolution', 0.9);
    // detalle de SOS — «quién emitió el sos», «de qué aula», «sin resolver»
    if (preg_match('/\b(sos|panico|boton de panico|emergencias?)\b/u', $q0)
        && preg_match('/\b(quien|quienes|emitio|emitieron|de que aula|de que salon|en que aula|aula|salon|donde|resolv\w*|sin resolver|pendientes?|detalle|quien lo|quien la|fue el|fue la)\b/u', $q0))
        return $r('sos_detail', 0.9);

    // razón del riesgo — «por qué está en riesgo», «motivo del riesgo»,
    // «qué hizo que subiera», «qué detectó el sistema»
    if (preg_match('/\b(riesgo|desercion)\b/u', $q0)
        && preg_match('/\b(por ?que|porque|motivos?|razones?|razon|causas?|que hizo|hizo que|que paso|que pasaba|subi\w*|detect\w*|que detecto|explica|explicame|origen|que genero|de donde viene|a que se debe|que la puso|que lo puso|cual es el)\b/u', $q0))
        return $r('risk_reason', 0.92);

    // excusas/justificaciones de incidentes — «qué excusa trajeron»,
    // «quién justificó», «motivos de excusa», «tienen excusa» (no es PERMISO)
    if (preg_match('/\b(excusas?|justific\w+)\b/u', $q0)
        && !preg_match('/\bpermisos?\b|\bsalidas?\b|\bautorizaci\w*\b/u', $q0)
        && !preg_match('/\b(genera\w*|crea\w*|hacer|quiero|necesito|dame|tramita\w*|registrar|registra|subir|adjuntar|cargar|poner|presentar|debo)\b/u', $q0)
        && preg_match('/\b(que|cuales?|cuantas?|trajeron|trajo|pusieron|mandaron|mand\w+|dieron|registr\w+|motivos?|razones?|tienen|tiene|traen|hay|hubo|de que|por que|porque|quien|dice|decia|adjunt\w+|certific\w+|incapacidad|medica|esta|estan|fue|fueron|ya|queda|aparece|algun[ao]s?|ningun[ao]s?|esas?|esos|sus|les)\b/u', $q0))
        return $r('incident_excuses', 0.9);

    // paseo pedagógico — destino, hora, asistentes (antes que permisos)
    if (preg_match('/\b(paseos?|salidas? pedagogicas?|excursion\w*|pasantias?|salidas? de campo)\b/u', $q0)
        && preg_match('/\b(para donde|a donde|hacia donde|destino|a que hora|cuando|quienes van|quien va|que dia|horario|itinerario|va el|sale el|sale a|es el|es pa|rumboa?|costo|llevar)\b/u', $q0))
        return $r('trip_info', 0.9);

    // calendario escolar — «es día lectivo», «hay clases», «festivos»,
    // «próximo no lectivo», «puente»
    if (preg_match('/\b(dias? lectivos?|dia lectivo|no lectivos?|festivos?|dia festivo|feriados?|puentes?|puente festivo|calendario escolar|se estudia|hay clases|habra clases|toca clases|toca estudiar|hay jornada|no hay clases|no toca|se suspenden las clases|asueto)\b/u', $q0)
        || (preg_match('/\bclases?\b/u', $q0)
            && preg_match('/\b(hay|habra|toca|es|tenemos|hay|suspenden|descansa)\b.{0,20}\b(manana|hoy|pasado manana|el lunes|el martes|el miercoles|el jueves|el viernes|el sabado|el domingo|esta semana|este mes|festivo|lectivo|jornada)\b|\b(manana|el lunes|el martes|el miercoles|el jueves|el viernes|este sabado)\s+(hay|habra|toca|es dia de)\s+clases?\b/u', $q0)))
        return $r('school_calendar', 0.9);

    // contacto de funcionario — «correo del docente», «teléfono de
    // coordinación», «número de la enfermera» (persona = personal, no
    // estudiante — «celular de maría» sigue siendo student_field)
    if (preg_match('/\b(correo|email|e-mail|telefono|celular|extension|numero|contacto|whatsapp)\b.{0,20}\b(docente|profesor|profe|maestr\w+|coordinador\w*|rector\w*|secretari\w*|orientador\w*|psicoorientador\w*|enfermer\w*|porter\w*|celador\w*|administrativ\w*|directiv\w*)\b|\b(correo|telefono|contacto|numero|celular|extension)\s+de\s+(?:la\s+)?(secretaria|coordinacion|enfermeria|rectoria|porteria|enfermera|psicoorientacion)\b|\bcomo\s+(contacto|llamo|escribo|me comunico)\s+(a|al|con)\s+(el|la|al)?\s*(docente|profesor|profe|coordinador|rector|orientador|secretaria)\b/u', $q0))
        return $r('staff_contact', 0.86);
    if (!empty($s['person'])
        && preg_match('/\b(correo|email|telefono|celular|numero|contacto|whatsapp|extension)\b/u', $q0))
        return $r('staff_contact', 0.88);

    // horario del docente / quién enseña materia / qué clase tiene el grupo
    // ahora — antes del «horarios» genérico de schedule_info
    if (preg_match('/\b(horarios?|agenda)\b.{0,25}\b(de|del|de la|de el)\s+(docente|profesor|profe|maestr\w+)\b|\bhorarios?\s+(del|de la|de)\s+[a-záéíóúñü]{3,}\s+[a-záéíóúñü]{3,}\b|\b(a que hora|cuando)\s+(dicta|enseña|tiene clase|le toca clase|esta dando clase|esta en clase)\b|\b(quien|quienes)\s+(dicta|dictan|enseña|enseñan|imparte|imparten|da|dan|ve|ven)\s+[a-záéíóúñü]{3,}|\bque\s+(materia|clase|asignatura)\s+(ve|tiene|esta viendo|le toca a|toca ahora|hay ahora)\b|\bque (hay|materia) (ahora|este periodo|este bloque|en este momento)\b.{0,15}\b(grupo|clase|del|para el)\b/u', $q0))
        return $r('teacher_schedule', 0.86);

    // consentimiento/exención biométrica — «exentos de biometría»,
    // «por qué X está exento», «sin consentimiento»
    // «exento» en este dominio casi siempre es biométrico — con alumno o
    // interrogativa explícita basta; sin señal biométrica también aplica si
    // hay nombre propio («por qué eva está exenta»)
    if (preg_match('/\b(exent\w*|exencion\w*|eximid\w*|dispensad\w*|consentimiento|autorizacion (de|para) (la )?biometria|no usa(n)? (la )?biometria|sin huella|sin consentimiento|consentimientos|exonerad\w*)\b/u', $q0)
        && (preg_match('/\b(biometri\w*|huellas?|sensor\w*|lector\w*|marcacion|consentimiento|registro de ingreso|reconocimiento|exent\w*|exencion|eximid\w*|dispensad\w*|exonerad\w*)\b/u', $q0)
            || !empty($s['student'])))
        return $r('student_consent', 0.9);

    // caso/seguimiento de un estudiante — «seguimiento de X», «avances
    // con X», «quién lleva el caso», «cuándo abrió», «notas del proceso»
    if (!empty($s['student'])
        && preg_match('/\b(seguimientos?|casos?|procesos?|acompanamiento|avances?|progreso)\b/u', $q0)
        && !preg_match('/\bcuantos\b|\blista(?:do)?\b|\btodos los\b|\bactivos\b|\babiertos\b/u', $q0))
        return $r('tracking_detail', 0.88, true);
    if (preg_match('/\b(quien\w* (lo|la)? ?(lleva|atiende|maneja|sigue|tiene asignado) el|cuando (abrio|se abrio|empezo|comenzo|inicio) el|que notas (tiene|lleva|hay)|ultima nota|notas del (seguimiento|caso|proceso)|avances? del (seguimiento|caso|proceso)|historial del (seguimiento|caso|proceso)|como va el (seguimiento|caso|proceso))\b/u', $q0))
        return $r('tracking_detail', 0.88, true);

    // citaciones por emisor — «qué estudiantes citó <docente>», «a quiénes
    // citó X», «citaciones del profesor X», «quién citó a X»
    if (preg_match('/\bquien\w*\b.{0,15}\b(cito|citamos|convoco|mando|envio|hizo|autorizo|programo|agendo)\b.{0,25}\b(citacion|cita|convocatoria|a esa|a esos|la cito|lo cito)\b|\bquien\w*\s+(lo|la|le|los|les)\s+cito\b|\bquien\w*\s+(?:cito|citamos|convoco|convocamos|mando|mandamos|envio|enviamos|agendo|programo|autorizo|hizo|hicieron)\s+a\s/u', $q0))
        return $r('citations_by', 0.9);
    if (preg_match('/\b(?:a\s+quien\w*|que\s+estudiant\w*|que\s+alumn\w*|a\s+que\s+estudiant\w*)\s+(?:les?\s+)?(cito|citamos|convoco|mando citacion|envio citacion)\s+(?:a\s+|al\s+)?([a-záéíóúñü]{2,}(?:\s+[a-záéíóúñü]{2,}){0,3})\b|\bcitaciones?\s+(?:del|de la|de|hechas por|mandadas por|enviadas por|emitidas por)\s+(docente\s+|profesor\s+|profe\s+|maestr\w+\s+)?([a-záéíóúñü]{2,}(?:\s+[a-záéíóúñü]{2,}){0,3})\b/u', $q0, $mc))
        return $r('citations_by', 0.9, true, ['person' => trim($mc[2] !== '' ? $mc[2] : ($mc[4] ?? ''))]);
    // «cuándo es/tiene la citación (de X|programada)» — fecha, no acción
    if (preg_match('/\bcitacion|citaciones\b/u', $q0)
        && preg_match('/\b(cuando (es|tiene|fue|quedo|esta|sera|seria|va)|que dia (es|tiene|quedo)|a que hora (es|quedo|tiene)|programad\w*|agendad\w*|fecha de|hora de)\b|\bla fecha (de|para) (la|su) citacion\b/u', $q0))
        return $r('citations_by', 0.88);

    // reportes generados — «qué reportes se hicieron», «quién generó»
    if (preg_match('/\b(reportes?|informes?)\b.{0,25}\b(generad\w*|descargad\w*|exportad\w*|emitid\w*|sacad\w*|se hicieron|se generaron|hubo|del sistema|del mes|del dia)\b|\bquien\w*\s+(genero|descargo|exporto|saco|hizo)\b.{0,20}\b(reporte|informe|archivo|excel|pdf)\b|\bexportaciones?\s+(generadas?|hechas|recientes)\b/u', $q0))
        return $r('reports_log', 0.88);

    // matrícula / movimiento de estudiantes — «nuevos», «retirados»
    if (preg_match('/\b(estudiantes?\s+(nuevos|recien ingresados|recien matriculados|retirados|que se fueron|que salieron|trasladados)|nuevos ingresos|ingresaron (este|en|al|al colegio|nuevos)|se retiraron|dados? de baja|desmatriculad\w*|matriculas?\s+(nuevas|del periodo|recientes)|retiros?\s+(de estudiantes|escolares))\b/u', $q0))
        return $r('enrollment_stats', 0.86);

    // mensajería a un acudiente concreto — «qué se le mandó al acudiente
    // de X», «respondió el acudiente de X», «historial de mensajes a X»
    if (!empty($s['student'])
        && (preg_match('/\b(mensajes?|whatsapps?|avisos?|comunicaci\w*|citaciones?|respondio|contesto|le respondieron|historial de mensajes|que se le (mando|envio|escribio)|que le han mandado|que le llego)\b.{0,25}\b(acudiente|mama|papa|padre|madre|responsable|familia|padres|representante)\b|\b(acudiente|mama|papa|padre|madre|responsable|padres|representante)\b.{0,20}\b(respondio|contesto|le respondieron|no ha respondido|no respondio|vio el mensaje|leyo|lo leyo|se lo envie|le llego|le llegaron)\b/u', $q0)))
        return $r('guardian_messages', 0.9);

    // detalle de dispositivo — «último ping del nodo», «sensor sin
    // configurar», «estado del lector de X»
    if (preg_match('/\b(nodo|nodos|sensor|sensores|dispositivo|lector|lectores|huellero|punto biometrico|equipo)\b/u', $q0)
        && preg_match('/\b(ping|ultimo reporte|ultimo contacto|ultima conexion|estado|encendido|apagado|sin configurar|desconfigurad\w*|sin reportar|no reporta|no responde|reporta\b|reporto\b|responde\b|responden\b|caid\w+|offline|en linea|senal|bateria|version|firmware|cuando reporto|del aula|del salon|esta vivo|funciona|anda bien|sirve)\b/u', $q0))
        return $r('device_detail', 0.86);

    // salidas autorizadas — «quién autorizó la salida de X», «a qué hora
    // salió», «ya regresó», «por qué salió», «motivo de la salida»
    if (preg_match('/\b(quien\w*)\b.{0,15}\b(autoriz\w*|aprobo|permitio|dejo salir|firmo|aval\w*)\b.{0,25}\b(salida|retiro|salga|salio|salir|que saliera)\b|\bquien\w*\s+(autorizo|aprobo|permitio|dejo)\s+(la|el|su|esa|que)?\s*(salida|saliera|saliera|salio)\b|\b(a que hora|cuando)\s+(salio|se fue|se retiro|salieron|salga|la sacaron|lo sacaron)\b|\bya\s+(regreso|volvio|entro|llego|estuvo de vuelta|volvieron)\b|\bpor que salio\b|\b(motivo|razon|causa) de (la|su|esa)\s+salida\b|\bsalidas?\s+(de hoy|del dia|autorizadas)\b/u', $q0))
        return $r('exit_detail', 0.88);

    // tendencia de asistencia — «qué día falta más», «promedio»,
    // «mejoró/empeoró», «frente a la semana pasada», «va en aumento»
    if (preg_match('/\b(que dia (faltan|falta|faltaron|hubo|hay) mas|que dia de la semana (faltan|falta) mas|promedio\s+(de|del|diario de|semanal de)|tendencia|va en aumento|viene subiendo|viene bajando|mejoro|empeoro|aumento|aumentaron|disminuyo|disminuyeron|ha mejorado|ha empeorado|compar\w*\s+(esta|la|el|con la|con el)\s+(semana|mes|periodo|dia|jornada|lunes|martes|miercoles|jueves|viernes)|frente a (la semana|el mes|ayer|la pasada|el anterior)|respecto a (la semana|el mes|ayer)|mejor que|peor que|\bvs\.?\b|\bversus\b|\bcontra\b)\b/u', $q0)
        && preg_match('/\b(inasistenci\w+|faltas?|ausenci\w+|tardanz\w+|evasion\w*|asistencia|llegadas?|fallas?|faltan|mes|semana)\b/u', $q0))
        return $r('attendance_trend', 0.86);

    // «motivo/razón/causa de <persona>» sin la palabra «riesgo» — la
    // explicación disponible del porqué sobre un estudiante es su razón
    // de riesgo («cuál es el motivo de Valentina Castaño»)
    if (preg_match('/\b(cual (es|fue) )?(el |la )?(motivo|razon|razones|causa|causas|porque|detonante)\s+(de|del|por)\b/u', $q0)
        && !empty($s['student'])
        && !preg_match('/\b(salida|retiro|permiso|citacion|cita|excusa|falta|inasistencia|ausencia|tardanza|llegada|riña|pelea|incidente|sancion|llamado)\b/u', $q0))
        return $r('risk_reason', 0.87, true);

    // evasión en gerundio — «hay algún estudiante evadiendo clase ahora»
    if (preg_match('/\b(evadiendo|evade|evaden|fugando|escapando|volaron|volando|escapandose|saliendose)\b/u', $q0))
        return $r('list_events', 0.88, true, ['module'=>'EVASION_INTERNA']);

    // comparativa institucional de un período contra otro — «cómo va la
    // asistencia del colegio hoy comparado con ayer», «inasistencias de
    // esta semana versus la anterior». attendance_trend ya calcula el
    // período contra el precedente; no confundir con «octavo vs noveno»
    // (comparación de grupos, sin marcador temporal/institucional).
    // Va antes del «avance/cómo va» de seguimientos — «cómo va la
    // asistencia comparado…» es tendencia, no un caso.
    if (preg_match('/\b(comparad\w*|versus|frente a|contra)\b/u', $q0)
        && preg_match('/\b(asistencia|inasist|ausen|falt|tardanza|evasion|incidente|evento)\w*\b/u', $q0)
        && preg_match('/\b(hoy|ayer|anteayer|esta semana|este mes|la semana pasada|el mes pasado|colegio|institucion|plantel|general|del dia|anterior|pasada|pasado)\b/u', $q0))
        return $r('attendance_trend', 0.88, true);

    // avance/progreso de un caso — «algún avance con X», «cómo va X».
    // La captura de nombre debe parecer persona: una palabra de dominio
    // («asistencia», «octavo», «faltas») no es sujeto de seguimiento
    if (preg_match('/\b(avances?|progresos?|como va|como le va|que tal va|como anda|como van)\b/u', $q0)
        && (!empty($s['student']) || preg_match('/\b(?:con|de|del|el caso de|lo de)\s+([a-záéíóúñü]{3,}(?:\s+[a-záéíóúñü]{2,}){0,3})\b/u', $q0, $avm))) {
        $avCand = !empty($s['student']) ? null : trim((string)($avm[1] ?? ''));
        if ($avCand === null || ($avCand !== '' && !preg_match('/\b(asistencia|inasist|ausen|falt|tardanza|evasion|octavo|noveno|decimo|septimo|sexto|quinto|cuarto|tercero|segundo|primero|once|grado|grupo|curso|materia|clase|colegio|institucion|jornada|turno|semana|mes|hoy|ayer|comparad|mejor|peor)\b/u', $avCand)))
            return $r('tracking_detail', 0.86, true,
                !empty($s['student']) ? [] : ['student'=>$avCand]);
    }

    // ping/rechazos biométricos — «quién hizo más ping rechazado»,
    // «intentos biométricos fallidos por estudiante»
    if (preg_match('/\b(pings?|marcacion\w*|intentos?)\b.{0,20}\b(rechazad\w*|fallid\w*|denegad\w*)\b|\b(rechazad\w*|fallid\w*)\b.{0,15}\b(biometr\w*|huellas?|ping|marcacion|lector\w*)\b|\b(mas|mas cantidad de|mayor)\s+(ping|pings|rechazos?|spam)\b/u', $q0))
        return $r('biometric_spam', 0.86, true);

    // personal por jornada — «qué personal trabaja en la mañana»,
    // «docentes de la tarde», «quiénes están en el turno noche»
    if (preg_match('/\b(personal|planta|empleados?|funcionarios?|staff|docentes?|profesores?|trabajadores?|quienes?)\b.{0,25}\b(jornada|turno|horario)\s+(de\s+)?(la\s+)?(manana|tarde|noche)\b|\b(personal|docentes?|profesores?|quienes?|trabajadores?)\b.{0,15}\b(en|de|por)\s+(la\s+)?(manana|tarde|noche)\b|\b(turno|jornada)\s+(de\s+)?(la\s+)?(manana|tarde|noche)\b/u', $q0, $shm)) {
        $sw = null;
        foreach (['manana'=>'mañana','tarde'=>'tarde','noche'=>'noche'] as $k => $vv)
            if (str_contains($q0, $k)) { $sw = $vv; break; }
        return $r('staff_lookup', 0.86, true, $sw ? ['shift'=>$sw] : []);
    }

    // delta de asistencia por estudiante — «qué estudiantes mejoraron su
    // asistencia», «quiénes van peor que el mes pasado»
    if (preg_match('/\b(mejoraron|mejoro|mejora|mejorando|empeoraron|empeoro|empeora|empeorando|van peor|va peor|viene peor|subio|subieron|bajo|bajaron)\b.{0,30}\b(asistencia|inasist|ausen|falt|tardanza|evasion|puntualidad)\w*\b|\b(asistencia|puntualidad)\b.{0,15}\b(mejor|peor|subio|bajo)\b|\bquien\w*\s+(mejoraron|empeoraron|mejoro|empeoro)\b/u', $q0))
        return $r('top_offenders', 0.86, true, array_filter([
            '_improve' => preg_match('/\b(empeor|peor|subio|subieron|aument)\w*\b/u', $q0) ? 'worse' : 'better',
            'module' => preg_match('/\btardanza|puntualidad\b/u', $q0) ? 'LATE_ARRIVAL'
                      : (preg_match('/\bevasion\w*\b/u', $q0) ? 'EVASION_INTERNA'
                      : (preg_match('/\b(asistencia|inasist|ausen|falt)\w*\b/u', $q0) ? 'INASISTENCIA' : null))]));

    // argmax por período — «en qué mes hubo más evasiones», «qué semana
    // tuvo más tardanzas», «en qué fecha faltaron más». El alcance es el
    // período completo, no un conteo del rango heredado
    if (preg_match('/\b(en que|que|por que)\s+(mes|semana|dia|fecha|periodo|momento|ano)\b.{0,30}\b(mas|mayor|hubo|registraron|concentran)\b|\b(mes|semana|dia|fecha)\s+(con|de)\s+mas\s+(inasist|ausen|falt|tardanza|evasion|incidente)\w*\b/u', $q0)
        && preg_match('/\b(inasist|ausen|falt|tardanza|evasion|incidente|permiso|citacion|evento)\w*\b/u', $q0))
        return $r('attendance_trend', 0.87, true);

    // ranking de citaciones por emisor — «qué profesor ha citado más
    // estudiantes», «quién ha mandado más citaciones»
    if (preg_match('/\b(quien\w*|que (profesor|docente|coordinador))\b.{0,30}\b(cito|citado|citan|citando|citaciones?|mandado|enviado|emitido)\b.{0,20}\b(mas|mayor)\b|\b(mas|mayor numero de|top)\s+(citaciones?|llamados?)\b/u', $q0))
        return $r('citations_by', 0.87, true);

    // ranking inverso — «mejor asistencia», «menos faltas», «quién falta
    // menos», «grupo que menos llega tarde». Persona → top_offenders asc;
    // grupo/grado → attendance_ranking asc (misma métrica, otro nivel).
    // El módulo se fija desde el texto para que uno heredado del contexto
    // no contamine el ranking.
    $ascMod = null;
    if (preg_match('/\b(tardanza|tarde|puntualidad|llega|llegan|demora)\w*\b/u', $q0)) $ascMod = 'TARDANZA';
    elseif (preg_match('/\b(evasion|evadiendo|fuga)\w*\b/u', $q0)) $ascMod = 'EVASION_INTERNA';
    elseif (preg_match('/\b(permiso|salida)\w*\b/u', $q0)) $ascMod = 'PERMISO_SALIDA';
    elseif (preg_match('/\b(asistencia|inasist|ausen|falt)\w*\b/u', $q0)) $ascMod = 'INASISTENCIA';
    if (preg_match('/\bquien\w*\s+(falta|faltan|llega|llegan)\s+(menos|mas puntual|mas temprano)\b|\b(estudiante|alumno|pelado|muchacho)\s+(que\s+)?(menos|mejor)\s+(falta|faltan|asist)/u', $q0))
        return $r('top_offenders', 0.86, true, array_filter(['_rank_dir'=>'asc','module'=>$ascMod]));
    if (preg_match('/\b(mejor|mayor)\s+(asistencia|puntualidad)\b|\b(menos|menor)\s+(faltas?|inasistencias?|tardanzas?|evasiones?|ausencias?)\b|\b(grupo|salon|curso|grado)\s+(que\s+)?(menos|mejor)\s+(falta|faltan|asist)/u', $q0))
        return $r('attendance_ranking', 0.86, true, array_filter(['_rank_dir'=>'asc','module'=>$ascMod]));

    // ── infraestructura / sistema ──
    if (preg_match('/\b(anomalias?|incidencias? del sistema|fallas? del sistema|nodos? caidos?)\b/u', $q0)) return $r('system_incidents', 0.92);
    if (preg_match('/\b(spam|rechazad\w*|intentos? fallidos?|huellas? no reconocidas?|duplicad\w*)\b/u', $q0)
        && preg_match('/\b(biometr\w*|huellas?|lector\w*|sensores?|escaner\w*|marcacion\w*|nodos?|dispositivos?)\b/u', $q0)) return $r('biometric_spam', 0.92);
    if (preg_match('/\b(sensores?|dispositivos?|nodos?|escaneres?|huelleros?|lectores? biometricos?|lectores?|lector|biometric\w*|marcacion\w*|marcaciones)\b/u', $q0)
        && !preg_match('/\b(spam|rechaz)/u', $q0)
        // «registró/marcó/reportó/sonaron» = eventos DEL dispositivo, no
        // su estado — «entradas tardías del lector» es un conteo, no status
        && !preg_match('/\b(registr\w+|marco|marcaron|report\w+|sonaron|saltaron|tard\w*|entradas?|eventos?|casos?|sin atender|fueron reportad\w*|que report\w*|de los estudiantes)\b/u', $q0)) return $r('devices_status', 0.9);
    // «cuántas veces sonaron/cuántas alertas hubo» = frecuencia de eventos,
    // no la bandeja SOS — pregunta por conteo, no por estado de alertas
    if (preg_match('/\bcuant[oa]s?\b.{0,20}\b(veces|alertas?|alarmas?)\b.{0,25}\b(sonaron|saltaron|dispararon|hubo|hubieron|se dieron|se registraron|biometricas?|del lector|del biometrico)\b|\bcuantas veces\b.{0,25}\b(sono|sonaron|salto|se disparo|hubo)\b.{0,15}\b(alertas?|alarmas?|sos|biometric\w*)\b/u', $q0))
        return $r('count_events', 0.9, true, ['module'=>'SOS']);
    if (preg_match('/\b(sos|panico|boton de panico|alertas? de emergencia|emergencias?|alertas?)\b/u', $q0) && !$opPhrase)
        return $r('sos_alerts', 0.9);

    // «sin excusa» = ausencia sin justificar — no el módulo de permisos
    // («excusa» es sinónimo PERMISO; el matiz «sin» invierte el sentido)
    if (preg_match('/\b(sin excusa\w*|sin excusas|faltan? excusas?|excusas? pendientes?|no trajeron excusas?|no mandaron excusas?|faltas? sin justificar|sin justificar|injustificad\w*)\b/u', $q0))
        return $r('count_events', 0.88, true, ['module'=>'INASISTENCIA','_unjustified'=>true]);

    // «mis permisos / mi rol / qué puedo hacer» = mi alcance (about_me),
    // no el módulo de permisos escolares — intercepta antes del dispatch.
    // PERO «ignora/cambia/quita mi rol» = escalación de privilegios → probe
    if (preg_match('/\b(mis permisos|que permisos tengo|cuales son mis permisos|mi rol|mis roles|que rol tengo|mi perfil|quien soy|a que tengo acceso|que puedo ver|que puedo hacer|que alcance tengo|mi cuenta|mi acceso)\b/u', $q0)
        && empty($s['student'])
        && !preg_match('/\b(ignora|cambia|cambiar|quita|quitar|elimina|borra|modifica|salta|omit\w*|resetea|escala|sube|dame mas|mas permisos|otro rol|rol de)\b/u', $q0))
        return $r('about_me', 0.92);

    // ── mensajería / acudientes / notificaciones ──
    if (preg_match('/\b(acudientes?|padres|papas|familias?)\b/u', $q0)
        && preg_match('/\b(respond\w*|contest\w*|respuestas?|justificaron|han dicho)\b/u', $q0))
        return $r('guardian_replies', 0.92);
    if (preg_match('/\b(mensajes?|whatsapps?|envios?|citaciones?)\s+(fallid\w*|que fallaron|con error|no enviad\w*|rebotad\w*|no entregad\w*)\b/u', $q0))
        return $r('failed_messages', 0.92);
    // «el whatsapp de santiago» = campo de persona (student_field), no el
    // canal — el guarda es por SUJETO, no por campo: «whatsapp» también es
    // sinónimo de 'celular', así que field solo no descarta el intent
    if (preg_match('/\b(whatsapp|mensajeria|cola de mensajes|twilio)\b/u', $q0)
        && empty($s['student'])) return $r('whatsapp_status', 0.9);
    if (preg_match('/\b(notificaciones|avisos|notificacion|mensajes)\b/u', $q0)
        && !preg_match('/\b(no llegaron|no entregad\w*|fallaron|fallid\w*|rebot\w*|sin enviar|no salieron|no se enviaron|borrador|rechazad\w*)\b/u', $q0)) {
        if (preg_match('/\b(resumen|resume|clasific\w*|categor\w*|patron\w*|agrupa\w*|analiza\w*|organiza\w*|tipos?)\b/u', $q0))
            $ent['_summary'] = true;
        // «a cuáles le doy prioridad alta/media/baja» — análisis por
        // prioridad sobre la bandeja completa, no solo categorías
        if (preg_match('/\b(prioriz\w*|prioridad|prioridades|urgente|urgentes|importante|importantes|a cuales? (les? )?(debo|doy|le doy|le debo|conviene|toca)|orden de atencion|por importancia|que atiendo primero|que atiendo urgente)\b/u', $q0))
            $ent['_priority'] = true;
        return $r('notifications_unread', 0.92, true, $ent);
    }
    if (preg_match('/\b(que tengo pendiente|mis pendientes|que me falta|tareas pendientes|que hay pendiente|tengo algo pendiente|que tengo por hacer)\b/u', $q0))
        return $r('pending_tasks', 0.93);
    if (preg_match('/\b(que he hecho|mi actividad|que hice|lo que he hecho|que consulte|que he consultado|que mire|que he revisado)\b/u', $q0)
        && !preg_match('/\b(que nadie|sin que|nadie sepa|sin registro|sin dejar|borra|elimina)\b/u', $q0))
        return $r('my_activity', 0.93);
    if (preg_match('/\bcumple\w*\b/u', $q0)) return $r('birthdays_today', 0.93);

    // ── personal / organización ──
    if (preg_match('/\b(que|cuales|lista de|listado de|cuantos|los)\s+(docentes|profesores|profes|profs?|maestros|profe\w*)\b/u', $q0) && empty($s['group']))
        return $r('teachers_list', 0.9);
    if (preg_match('/\b(coordinador\w*|rector\w*|psicoorientador\w*|orientador\w*|psicolog\w*|secretari\w*|porter\w*|celador\w*|personal directivo)\b/u', $q0)
        && !$mod) return $r('staff_lookup', 0.85, false);
    if (preg_match('/\bhorarios?\b/u', $q0)) {
        if (preg_match('/\btodos (los )?grupos\b|\bcada grupo\b/u', $q0)) $ent['_all_groups'] = true;
        return $r('schedule_info', 0.88, empty($s['group']), $ent);
    }
    if (preg_match('/\b(en riesgo|riesgo (alto|critico|activo|de abandono|escolar|academico|de desercion|desercion escolar|desercion)|superaron el umbral|alertas? de riesgo|estudiantes? de riesgo|desercion escolar|abandono escolar)\b/u', $q0))
        return $r('risk_students', 0.92);
    if (preg_match('/\b(que|cuales|cuantos|lista de|listado de|todos los)\s+grupos\b/u', $q0) && !$mod
        && !preg_match('/\b(mas|menos|compar|ranking)\b/u', $q0))
        return $r('groups_list', 0.9);

    // ── seguimientos / permisos / citaciones (tabla propia) ──
    if (($mod === 'SEGUIMIENTO' || preg_match('/\bseguimientos?\b/u', $q0)) && !$opPhrase) {
        if (preg_match('/\b(abiert\w*|activ\w*|en proceso|vigentes?)\b/u', $q0)) $ent['status'] = 'active';
        elseif (preg_match('/\b(cerrad\w*|resuelt\w*|terminad\w*)\b/u', $q0)) $ent['status'] = 'completed';
        return $quant && empty($s['student']) ? $r('count_trackings', 0.9, true, $ent) : $r('trackings', 0.88, empty($s['student']), $ent);
    }
    if (preg_match('/\b(salidas? pedagogicas?|paseos?|pasantias?|salidas? de campo|excursion\w*)\b/u', $q0) && !$opPhrase)
        return $r('permissions', 0.9, true, ['status'=>'all']);
    if (preg_match('/\b(permisos?|autorizaciones?|salidas autorizadas|autorizados?|autorizadas?|excusas?|justificaciones?)\b/u', $q0) && !$opPhrase
        // «salida NO autorizada» es evasión, no permiso — la forma
        // «autorizados» sola casaría dentro de «no autorizados»
        && !preg_match('/\bno autoriz\w*|sin autoriz\w*|salida\w* (?:sin|no) autoriz\w*/u', $q0)
        // «permisos sin retorno/regreso» = pendientes de retorno, no el
        // listado del módulo — la regla dedicada viene después
        && !preg_match('/\b(sin retorno|sin regreso|sin marcar regreso|vencidos?|vencieron|expirad\w*|que no volvieron|todavia afuera|sin volver)\b/u', $q0)
        // «se fueron/abandonaron sin permiso» = evasión — el sustantivo
        // «permiso» califica la ausencia de autorización, no el módulo
        && !preg_match('/\b(abandonaron|abandona\w*|se fueron|salieron|escaparon|volaron|fugaron|se retiraron|largaron|salen|saliendo)\b.{0,30}\bsin permiso\b|\bsin permiso\b.{0,25}\b(aula|salon|clase|colegio|escuela|plantel)\b/u', $q0)
        // «permiso» meta-acceso («aunque no tenga permiso», «permiso para
        // ver…») no es el módulo — pero «quiénes tienen permiso hoy» sí
        && !preg_match('/\bno\s+(tengo|tiene|tenga|tienen|tener|tuviera|tenemos|darme|me\s+de[sn])\s+permiso\b|\bpermiso\s+(?:para|de)\s+(ver|acceder|entrar|mirar|consultar|saber|descargar)\b/u', $q0)) {
        if (preg_match('/\b(activ\w*|vigentes?|ahora|en este momento|fuera)\b/u', $q0) && empty($s['from'])) $ent['status'] = 'active';
        return $r('permissions', 0.88, empty($s['student']), $ent);
    }
    if (preg_match('/\b(citaciones?|citas?|citados?|citadas?|llamados?|llamadas?|convocados?|convocadas?|convocatorias?|reuniones? con padres|acudientes citados|citas? programadas?|citatorios?|agendados?|agendadas?)\b/u', $q0) && !$opPhrase
        && !preg_match('/\bpermiso|excusa|autoriz/u', $q0))
        return $r('citations', 0.88, empty($s['student']));

    // ── cobertura de paráfrasis frecuentes (fixture §singles) ──
    // reglas de dominio inequívocas que no caben en las familias anteriores

    // campo o ficha de persona nombrada — «el correo de pedro», «la eps de
    // andrés», «quien es luciana», «resumen de diego»
    if (!empty($s['student']) && !empty($s['field']) && !$mod)
        return $r('student_field', 0.88, true);
    if (!empty($s['student'])
        && preg_match('/^(quien es|quienes son|resumen de|ficha de|datos de|perfil de|que sabes de|cuentame de|hablame de|que me dices de|informacion de|info de)\b/u', $q0)
        && !$mod)
        return $r('student_summary', 0.9, true);

    // resumen/panorama diario institucional
    if (preg_match('/\b(resumen|panorama|balance|estado|como cerro|como termino|como cerro|que tal va|como va|como esta|como amanecio)\b.{0,20}\b(del dia|general|del colegio|de la institucion|institucional|de hoy|de la jornada|del plantel|hoy)\b|^estado del colegio$|^panorama general$|^resumen del dia$|^como cerro el dia$|^que tal va el colegio$/u', $q0)
        && empty($s['group']) && empty($s['student']) && !$mod)
        return $r('day_summary', 0.9);

    // pendientes operativos del usuario
    if (preg_match('/\b(que tengo pendiente|mis pendientes|que me falta|tareas? pendientes?|que hay pendiente|tengo algo pendiente|que tengo por hacer|que debo revisar|trabajos? sin completar|pendientes? de hoy|cosas pendientes|que me falta por hacer|lo pendiente|pendientes? por)\b/u', $q0))
        return $r('pending_tasks', 0.92);
    // «¿ya está listo?», «¿ya quedó?» — estado de lo pendiente/operación
    // confirmada: honesto con pending_tasks (muestra lo que sigue abierto)
    if (preg_match('/^(ya (esta|quedo|esta listo|esta hecho|esta creado|esta generado|esta tramitado|se hizo|sirve|funciona|esta)|esta listo|esta hecho|ya quedo|listo ya|ya sirve|ya lo tienes)\b[?¡! ]*$/u', $q0))
        return $r('pending_tasks', 0.85);

    // pendientes — formas amplias («tareas por cerrar», «cosas por aprobar»,
    // «lo que quedó sin resolver», «pendientes de coordinación»)
    if (preg_match('/\b(tareas?|trabajos?|cosas?|pendientes?|solicitudes?|casos?)\b.{0,25}\b(por cerrar|por hacer|por resolver|por completar|por aprobar|sin completar|sin resolver|sin cerrar|sin aprobar|por revisar)\b/u', $q0)
        || preg_match('/^(pendientes?|tareas?|mis pendientes?|que queda|que me queda|que me queda pendiente|que falta)[.!? ]*$/u', $q0)
        || preg_match('/\b(quedo|quedaron|queda|quedan)\b.{0,15}\b(por hacer|por resolver|por cerrar|sin resolver|por completar|por aprobar|pendiente)\b/u', $q0)
        || preg_match('/\bpendientes?\s+(?:de la|del|de|en la|en el|en)\s+(coordinacion|rectoria|secretaria|mi area|la semana|orientacion|la institucion|academico|la jornada)\b/u', $q0))
        return $r('pending_tasks', 0.9);

    // comparación / ranking de grupos y niveles
    if (preg_match('/\b(grupo|salon|curso|grado)\b.{0,20}\b(peor|mas|mejor|mayor|menor)\s+(asistencia|faltas?|evasiones?|tardanzas?|disciplina|grande|alumnos|estudiantes)\b|\b(peor|mas faltador|mas grande)\s+(grupo|salon|curso|grado)\b|\b(salon|grupo|curso|grado)\s+que\s+mas\s+(falta|faltan|evade|llega tarde)\b/u', $q0)) {
        if (preg_match('/\bmas grande|mayor numero|mas estudiantes|mas alumnos\b/u', $q0)) return $r('group_student_count', 0.85);
        return $r('attendance_ranking', 0.85);
    }
    if (preg_match('/\b(compar\w*|vs\.?|versus|frente a)\b/u', $q0)
        && preg_match('/\b(sexto|septimo|octavo|noveno|decimo|undecimo|primero|segundo|tercero|cuarto|quinto|\d{1,2}\s?[a-e]|transicion|kinder|jardin|preescolar)\b.{0,30}\b(sexto|septimo|octavo|noveno|decimo|undecimo|primero|segundo|tercero|cuarto|quinto|\d{1,2}\s?[a-e]|transicion|kinder|jardin|preescolar)\b/u', $q0))
        return $r('attendance_ranking', 0.85);
    if (preg_match('/\b(promedio|porcentaje|nivel|tasa)\b.{0,15}\b(asistencia|faltas?|evasion)\b.{0,15}\b(por grupo|por curso|por salon|por grado|de grupos)\b/u', $q0))
        return $r('attendance_ranking', 0.87);
    // niveles escolares como alcance de conteo («el preescolar», «bachillerato»)
    if (preg_match('/\b(cuant[oa]s|numero de|total de)\s+(estudiantes|alumnos|ninos|muchachos|pelados)\b.{0,25}\b(preescolar|primaria|bachillerato|kinder|jardin|transicion|media|basica)\b/u', $q0, $mlv))
        return $r('group_student_count', 0.88, true, ['group' => $mlv[count($mlv)-1]]);
    if (preg_match('/\b(lista|listado|todos|panorama)\b.{0,15}\b(de )?grupos\b|\btodos los grupos del colegio\b|\bgrupos del colegio\b|\blistado completo de grupos\b/u', $q0)
        && !$mod && !preg_match('/\b(de|del)\s+\w/u', $q0) === false)
        return $r('groups_list', 0.88);
    // «estado del undécimo en faltas», «qué tan mal está el décimo» → ficha de grupo
    if ((preg_match('/\b(estado|situacion|como va|como esta|que tan|salud)\b.{0,20}\b(del|de|el|al)?\s*(sexto|septimo|octavo|noveno|decimo|undecimo|primero|segundo|tercero|cuarto|quinto|transicion|kinder|jardin|\d{1,2}\s?[a-e])\b.{0,15}\b(en|con)\s+(faltas?|inasistencias?|evasiones?|tardanzas?|asistencia|disciplina|convivencia)\b/u', $q0)
        || preg_match('/\bcomo va|como esta\b.{0,15}\ben\s+(faltas?|inasistencias?|evasiones?|tardanzas?|asistencia)\b/u', $q0))
        && (preg_match('/\b(sexto|septimo|octavo|noveno|decimo|undecimo|primero|segundo|tercero|cuarto|quinto|transicion|kinder|jardin|grupo|salon|\d{1,2}\s?[a-e])\b/u', $q0) || !empty($s['group'])))
        return $r('group_summary', 0.85);

    // consolidado diario institucional — formas amplias
    if (preg_match('/\b(consolidado|resumen|panorama|balance|cuadro|estado|reporte general)\b.{0,20}\b(del dia|de ayer|de la semana|de hoy|general|del plantel|institucional|de lo que paso|esta manana|del periodo)\b|\b(como (fue|cerro|termino|estuvo|amanecio))\b.{0,15}\b(el dia|la semana|ayer|hoy|el colegio|la jornada|el plantel)\b|\bestado general\b|\bcomo cerro la semana\b|\bresumen de lo que paso\b|\bel dia de ayer en general\b|\bcuadro resumen\b/u', $q0)
        && empty($s['student']))
        return $r('day_summary', 0.87);

    // tardanza — formas de tiempo/lugar que la familia LATE no cubre
    if (preg_match('/\b(llegaron|entraron|vinieron|llega|llegan|entr\w+)\b.{0,20}\b(despues de (las|la)\s+\w+|a destiempo|tarde|retrasad\w*|con retraso)\b/u', $q0)
        && empty($s['student']))
        return $quant || preg_match('/\bcuant/u', $q0) ? $r('count_events', 0.87, true, ['module'=>'LATE_ARRIVAL'])
            : $r('late_today', 0.85, true, ['module'=>'LATE_ARRIVAL']);
    // «las tardanzas del séptimo / inasistencias del 8A» — módulo + grupo
    // sin forma de lista → conteo del grupo, no volcado de eventos
    if (preg_match('/\b(tardanzas?|tard\w+as|inasistencias?|faltas?|evasiones?)\s+(del|de la|de|de los|de las)\s+\w+/u', $q0)
        && !empty($s['group'])
        && !preg_match('/\b(quien|quienes|lista|listado|cuales|nombres?|muestra|ver|cuales son)\b/u', $q0))
        return $r('count_events', 0.85, true, ['module'=>$mod,'group'=>$s['group']]);
    if (preg_match('/\b(tardanzas?|llegadas? tard\w*|impuntualidad|inasistencias? de llegada|reincidencia en llegadas? tard\w*)\b.{0,25}\b(del|de la|de los)\s+(sexto|septimo|octavo|noveno|decimo|undecimo|primero|segundo|tercero|cuarto|quinto|transicion|kinder|jardin|bachillerato|primaria|preescolar|grupo|salon|\d{1,2}\s?[a-e]|grado)\b/u', $q0)
        || preg_match('/\b(tardanzas?|llegadas? tard\w*)\b.{0,15}\b(acumuladas?|del primer bloque|del bloque|en la manana|de la jornada)\b/u', $q0))
        return $r('count_events', 0.85, true, ['module'=>'LATE_ARRIVAL','group'=>$s['group'] ?? null]);
    if (preg_match('/\b(registr\w+|marco|report\w+)\s+(?:por\s+)?(?:el|por el)\s+(lector|biometrico|sensor|huellero|nodo)\b|\b(entradas?|marcaciones?)\s+tard\w*\b.{0,20}\b(lector|biometrico|sensor)\b|\b(fueron|han sido|son)\s+reportad\w*\s+por\b.{0,15}\b(lector|biometrico|sensor|huellero)\b/u', $q0))
        return $r('count_events', 0.85, true, ['module'=>'LATE_ARRIVAL']);
    if (preg_match('/\b(los|quienes|estudiantes)\s+(de siempre\s+)?(llegando|que llegan|llegan)\s+tarde\b|\bde siempre.*tarde\b/u', $q0))
        return $r('top_offenders', 0.82, true, ['module'=>'LATE_ARRIVAL']);

    // evasión / salida no autorizada — vocabulario amplio
    if (preg_match('/\b(abandonaron|abandona|se fueron|salieron|salen|saliendo|fugaron|escaparon|se escaparon|se volaron|volaron|se saltaron|saltaron|se picaron|picaron|piantaron|escondieron|esconden|escondidas|escondidas|conejo|hicieron conejo|sin permiso del aula|salidas? del aula|salidas? del salon|salieron del salon|puerta trasera|puerta de atras|por la ventana|a escondidas|sin autorizacion|no autorizad\w*|salida\w* no autorizad\w*|se retiraron|se largaron|largaron|abandon\w*)\b/u', $q0)
        && (preg_match('/\b(aula|salon|clase|escuela|colegio|plantel|recreo|patio|baño|clases|formacion|permiso|autorizacion|puerta|ventana|escond|salida|tarde|hoy|ayer|semana|mes|examen|matematicas)\b/u', $q0) || $mod === 'EVASION_INTERNA')
        && !preg_match('/\bpermisos? (activos?|vigentes?|del dia|de hoy)\b/u', $q0))
        return $quant || preg_match('/\bcuant/u', $q0) ? $r('count_events', 0.85, true, ['module'=>'EVASION_INTERNA'])
            : $r('list_events', 0.85, true, ['module'=>'EVASION_INTERNA']);
    // «se picaron / hicieron conejo / se la piantaron / se escondieron» —
    // jerga escolar de ausencia/evasión sin marcador de aula explícito
    if (preg_match('/\b(se picaron|hicieron conejo|se la piantaron|piantaron|se escondieron|escondieron en|se saltaron la clase|saltaron la clase|se volaron|se largaron|chinos? que no (llegaron|vinieron|asistieron)|que no llegaron|no se presentaron)\b/u', $q0)
        // «avisos/mensajes que no llegaron» = canal de notificación, no
        // ausencia de personas — la bandeja de fallidos viene después
        && !preg_match('/\b(avisos?|mensajes?|notificaciones?|alertas?|envios?|correos?|citaciones?|whatsapp\w*)\b/u', $q0))
        return $quant ? $r('count_events', 0.82, true, ['module'=>'EVASION_INTERNA'])
            : $r('list_events', 0.82, true, ['module'=>'EVASION_INTERNA']);

    // eventos del dispositivo — «eventos biométricos de la entrada»:
    // conteo del lector, no su estado ni su spam
    if (preg_match('/\beventos?\s+(biometricos?|del biometrico|del lector|de la entrada|del sensor|del dispositivo|de marcacion)\b|\bmarcaciones?\s+de la entrada\b/u', $q0))
        return $r('count_events', 0.85, true, ['module'=>'LATE_ARRIVAL']);
    // «si el estudiante tiene restricciones» — chequeo de ficha sobre el
    // sujeto activo, no un campo aislado del formulario
    if (preg_match('/\b(si|verifica si|revisa si|checa si|a ver si)\s+(?:el|un|ese|este|algun)?\s*(?:estudiante|alumno|nino|nina|muchacho|pelado|menor)\s+(?:tiene|presenta|cuenta con|trae|lleva|registra|sufre de|padece)\b|\bel estudiante (tiene|presenta) (restricciones|alergias|condicion)\b/u', $q0))
        return $r('student_summary', 0.85);
    // permisos sin retorno — vencidos/vencieron sin marcar regreso
    if (preg_match('/\b(permisos?|salidas?|autorizaciones?)\b.{0,25}\b(sin retorno|sin regreso|sin marcar|no han (regresado|vuelto|retornado|regresado)|vencidos?|vencieron|expirad\w*|sin cerrar|abiertos?|pendientes? de regreso|que no volvieron|todavia afuera)\b/u', $q0)
        || preg_match('/\bsin (retorno|regreso|marcar regreso)\b/u', $q0))
        return $r('pending_returns', 0.9);
    // «salidas anticipadas / salida temprana» = permisos (salida autorizada)
    if (preg_match('/\b(salidas? anticipadas?|salida temprana|salidas? tempranas?|salidas? antes|se fueron temprano|salieron antes|pidieron salida|salidas? pedidas|salida antes de tiempo)\b/u', $q0) && !$opPhrase)
        return $r('permissions', 0.85, true, ['status'=>'all']);

    // seguimientos — «casos» en orientación/convivencia/psicopedagogía/plan
    if (preg_match('/\b(casos?|derivados?|remitidos?|canalizados?|estudiantes?|planes?)\b.{0,25}\b(orientacion|psicopedagog\w*|convivencia|enfermeria|terapia|plan de accion|apoyo academico|derivacion|remision|acompanamiento|abiertos?|cerrados?|activos?|en proceso)\b|\bplan(es)? de accion\b|\ben (plan|seguimiento|caso|proceso)\b/u', $q0)
        && !preg_match('/\b(asistencia|tardanza|evasion|permiso|citacion)\b/u', $q0)) {
        $st = preg_match('/\b(cerrad\w*|resuelt\w*|terminad\w*)\b/u', $q0) ? 'completed'
            : (preg_match('/\b(abiert\w*|activ\w*|en proceso|vigentes?)\b/u', $q0) ? 'active' : null);
        return $quant ? $r('count_trackings', 0.85, true, ['status'=>$st]) : $r('trackings', 0.85, true, ['status'=>$st]);
    }

    // notificaciones — bandeja/rebote/borrador
    if (preg_match('/\b(mensajes?|avisos?|notificaciones|alertas?)\b.{0,30}\b(sin responder|sin leer|sin abrir|sin atender|rebot\w*|en borrador|borradores|quedaron sin|sin enviar|no salieron|no llegaron|no se enviaron|pendientes? de envio|no entregados?)\b/u', $q0)) {
        if (preg_match('/\b(rebot\w*|fallaron|fallidos?|no llegaron|no entregad\w*|sin enviar|no salieron|no se enviaron|borrador)\b/u', $q0))
            return $r('failed_messages', 0.88);
        return $r('notifications_unread', 0.86);
    }
    if (preg_match('/\bavisos?\b.{0,25}\b(no llegaron|no entregad\w*|fallaron|rebotaron|sin enviar)\b.{0,20}\b(padres|acudientes|familia|mama|papa)\b|\bque no llegaron a los padres\b|\bmensajes? en borrador\b|\bquedaron en borrador\b/u', $q0))
        return $r('failed_messages', 0.9);

    // campo/ficha de estudiante — formas verbales que el campo solo no cubre
    if (preg_match('/\b(grado|nivel)\b.{0,15}\b(matriculad\w*|inscrit\w*|esta|pertenece|cursa|esta en|va en)\b|\ben que (grado|grupo|curso|salon|nivel)\b|\b(grupo|salon|curso|seccion)\s+al que pertenece\b|\ba que (grupo|jornada|grado|salon|seccion)\b/u', $q0)
        && (!empty($s['student']) || preg_match('/\b(el|la|del|de|este|esta|ese|esa)\s+(nino|nina|estudiante|alumno|alumna|pelado|muchacho|menor|nuevo|nueva|transferid\w*|recien llegad\w*)\b/u', $q0)))
        return $r('student_field', 0.85, true, ['field'=>'grupo','student'=>$s['student'] ?? null]);
    if (preg_match('/\b(resumen academico|expediente|hoja de vida|perfil completo|ficha completa|informacion completa|toda la info|historial academico|carpeta)\b/u', $q0) && !empty($s['student']))
        return $r('student_summary', 0.88, true);
    if (preg_match('/\b(eps|seguro medico|afiliacion|prepagada|salud|arls?)\b.{0,20}\b(registrad\w*|tiene|del|de|afiliado)\b|\bel eps\b|\bque eps\b/u', $q0))
        return $r('student_field', 0.85, true, ['field'=>'eps','student'=>$s['student'] ?? null]);
    if (preg_match('/\b(restriccion\w*|condicion especial|condiciones? medic\w*|restriccion alimentaria|alergia\w*|necesidades? especiales?|medicamento|tratamiento)\b/u', $q0)
        && (empty($s['group']) || !empty($s['student']))
        // «sin restricciones» = intento de bypass, no consulta de ficha
        && !preg_match('/\bsin (restriccion\w*|limite\w*|filtro\w*|permiso|control|bloqueo\w*)\b|^sin\b/u', $q0))
        return !empty($s['student']) ? $r('student_field', 0.82, true, ['field'=>'restricciones','student'=>$s['student']])
            : $r('student_summary', 0.8);
    if (preg_match('/\b(estudiante|alumno|alumna|nino|nina|pelado|muchacho|menor)\s+(nuevo|nueva|transferid\w*|recien llegad\w*|recien ingresad\w*|de intercambio)\b|\bniño nuevo\b|\bestudiante nuevo\b/u', $q0))
        return $r('student_summary', 0.82);

    // asistencia institucional — formas de agregado general
    if (preg_match('/\b(asistencia|inasistencias?|ausencias?)\b.{0,20}\b(general|global|total|del colegio|institucional|del plantel|esta manana|del dia|porcentaje|tasa|nivel)\b|\bporcentaje de asistencia\b|\bnivel de asistencia\b|\btasa de asistencia\b|\bconsolidado de inasistencias\b|\basistencia general\b/u', $q0)
        && empty($s['student']) && empty($s['group']))
        return $quant ? $r('count_events', 0.85, true, ['module'=>'INASISTENCIA']) : $r('attendance_today', 0.85);
    if (preg_match('/\bjornada\s+(de\s+la\s+)?(manana|tarde|noche|completa|unica|mañana)\b.{0,20}\b(asistencia|faltas?|inasistencias?|ausencias?)\b|\b(asistencia|faltas?|inasistencias?)\b.{0,20}\bjornada\s+(de\s+la\s+)?(manana|tarde|noche)\b/u', $q0))
        return $quant ? $r('count_events', 0.85, true, ['module'=>'INASISTENCIA']) : $r('attendance_today', 0.85);
    // umbral de faltas — «acumulan más de tres», «superan cinco» → riesgo
    if (preg_match('/\b(acumulan|superan|tienen|llevan|suman|exceden|alcanzan|rebasan)\b.{0,25}\bmas de\s+(?:\d+|un|una|dos|tres|cuatro|cinco|seis|siete|ocho|nueve|diez)\s+(inasistencias?|faltas?|evasiones?|tardanzas?|ausencias?)\b|\bmas de\s+(?:\d+|un|una|dos|tres|cuatro|cinco|seis|siete|ocho|nueve|diez)\s+(inasistencias?|faltas?|evasiones?|tardanzas?)\b/u', $q0))
        return $r('risk_students', 0.85);
    // «eventos marcados/registrados» — conteo del sistema, no del sensor
    if (preg_match('/\b(cuant[oa]s|numero de|total de)\s+eventos?\s+(se\s+)?(marc\w+|registr\w+|report\w+|hubo|ocurri\w+|pasaron|se\s+dieron|contaron|entraron)\b|\beventos?\s+(marc\w+|registr\w+|report\w+)\s+(hoy|ayer|esta semana|este mes|del dia)\b/u', $q0))
        return $r('count_events', 0.85);
    // alertas biométricas que sonaron/se activaron = eventos del sensor
    if (preg_match('/\b(sonaron|sono|saltaron|salto|se activaron|se dispararon|se encendieron|se prendieron|alertas?)\b.{0,20}\b(biometric\w*|del lector|del sensor|del huellero|del nodo|de acceso)\b|\b(biometric\w*|lector\w*|sensor\w*)\b.{0,15}\b(sonaron|saltaron|alertas?)\b/u', $q0))
        return $r('biometric_spam', 0.85);

    // horarios — «programación», «reuniones programadas», agenda
    if (preg_match('/\b(programacion|programada|agenda|calendario|cronograma|itinerario|actividades programadas|eventos programados|reuniones?|sesiones|planeacion|planeado)\b.{0,25}\b(del|de la|de|para|esta|de esta)\s+(semana|dia|jornada|manana|tarde|mes|fecha|fecha proxima)\b|\breuniones?\s+programadas?\b|\bque (reuniones|actividades|eventos) (hay|estan programad\w*|tenemos|viene|estan)\b|\bprogramacion de la semana\b|\bagenda del (dia|colegio|plantel)\b|\bcalendario escolar\b|\bque hay programado\b|\bactividades del dia\b/u', $q0)
        && !$mod && !$opPhrase)
        return $r('schedule_info', 0.85);

    // docente responsable de algo — «el docente del que reportó», «quien lo registró»
    if (preg_match('/\b(docente|profesor|profe|maestro|maestra|coordinador|orientador)\b.{0,25}\b(report\w+|registr\w+|anot\w+|marco|puso|escribi\w+|levant\w+|hizo|dijo|afirm\w+|del que|que reporto|que registro|encargado)\b|\b(del|de|por|quien)\s+(report\w+|registr\w+|anot\w+|marc\w+|puso|hizo|levant\w+|firm\w+|atiende|responde|esta a cargo|es responsable)\b.{0,15}\b(incidente|evento|caso|falta|evasion|reporte|constancia)\b/u', $q0))
        return $r('staff_lookup', 0.82);

    // datos de dominio NO soportados — abstención honesta (notas =
    // calificaciones, no un campo del sistema; no es security_probe porque
    // no intenta mutar ni violar, simplemente no existe la capacidad)
    if (preg_match('/\b(notas?|calificaciones?|boletas?|examenes?|parciales?|quizes?|promedio academico|ranking academico|desempeno academico|reporte de notas|saber 5|pisa|icfes)\b/u', $q0)
        && !preg_match('/\b(inasistencia|tardanza|evasion|permiso|citacion|seguimiento|asistencia)\b/u', $q0))
        return $r('out_of_scope', 0.6);

    // estudiante al azar / selección aleatoria
    if (preg_match('/\b(aleatori\w*|al azar|azar)\b/u', $q0)
        || (preg_match('/\b(estudiante|alumno|pelado|nino|muchacho)\b/u', $q0)
            && preg_match('/\b(cualquiera|uno cualquiera|el que sea|dime uno|dame uno|muestrame uno)\b/u', $q0)))
        return $r('random_student', 0.9);

    // auditoría — «quién consultó a maría», «trazabilidad», «registro de accesos»
    if (preg_match('/\b(auditoria|trazabilidad|registro de accesos|registros? de acceso|quien (consulto|vio|mire|modifico|exporto|descargo|accedio)|quien (entro|salio|accedio|ingreso)\s+(al|a la|del|de la)\s+(sistema|app|plataforma|panel|nexo)|log de|bitacora|historial de accesos)\b/u', $q0))
        return $r('audit_query', 0.9);

    // mensajería fallida — «mensajes que no llegaron», «quedaron sin enviar»
    if (preg_match('/\b(mensajes?|whatsapps?|correos?|envios?)\b.{0,25}\b(no llegaron|sin enviar|no enviados?|quedaron sin|no salieron|fallaron|fallidos?|rebotad\w*|no entregad\w*)\b|\b(no llegaron|fallaron|rebotaron|no salieron)\w*\b.{0,25}\b(mensajes?|padres|acudientes)\b|\bmensajes? que no\b/u', $q0))
        return $r('failed_messages', 0.9);
    // buzón propio — «tengo mensajes», «mensajes sin leer», «hay correos»
    if (preg_match('/\b(tengo|hay|me llegaron|nuevos|sin leer|sin revisar)\w*\s+(mensajes?|notificaciones|avisos|correos)\b|\b(mensajes?|avisos|correos)\s+(nuevos|sin leer|pendientes|para mi)\b|\bmis mensajes\b|\bmi bandeja\b|\bmensajes? nuevos\b|\bhay mensajes\b/u', $q0))
        return $r('notifications_unread', 0.88);

    // configuración del modelo de riesgo — umbrales/criterios aplicados,
    // no la lista de estudiantes en riesgo
    if (preg_match('/\b(umbrales?|criterios?|parametros?|configuracion|como se (calcula|define|determina)|que (umbrales|criterios|parametros))\b.{0,25}\b(riesgo|desercion|alerta|aplican|actuales|vigentes|definidos|estan)\b|\bumbrales? (de riesgo )?(actuales|aplican|vigentes|configurados)\b|\bcomo (se calcula|funciona) el riesgo\b|\bcriterios? de (riesgo|alerta|desercion)\b/u', $q0))
        return $r('risk_config', 0.88);
    // riesgo estudiantil / deserción / umbrales
    if (preg_match('/\b(riesgo|desercion|umbral\w*|abandono (escolar|academico)|probabilidad de abandono|alerta temprana)\b/u', $q0)
        && $mod !== 'SEGUIMIENTO')
        return $r('risk_students', 0.9);

    // docente por materia — «el de matemáticas», «quién enseña ciencias»
    if (preg_match('/\b(?:quien|quienes)\s+(ensen\w+|dicta|dictan|da|dan|imparte|imparten|mira|ve)\s+(\w{3,})/u', $q0, $mm)
        || preg_match('/^(?:el|la|los|las)\s+de\s+(\w{3,})\b(?!\s+\w)/u', $q0, $mm)
        || preg_match('/\b(?:profesor[ae]?s?|docentes?|maestr[oa]s?|profe)\s+de\s+(\w{3,})/u', $q0, $mm)) {
        $sub = mb_strtolower($mm[count($mm) - 1]);
        if (!in_array($sub, ['la','el','los','las','que','quien','es','son','hoy','ayer','grado','grupo','clase','turno','salida','entrada','datos','ficha'], true))
            return $r('staff_lookup', 0.86, true, ['subject' => $sub]);
    }
    // planta docente / personal institucional
    if (preg_match('/\b(planta docente|cuerpo docente|personal (del|de la) (colegio|institucion|planta|administrativo)|empleados|directivos|todo el personal|el personal|quien trabaja|trabajadores|funcionarios)\b/u', $q0)
        && empty($s['group']))
        return $r('teachers_list', 0.88);

    // oferta de grados/grupos — «cuántos grados hay», «lista de cursos»
    if (preg_match('/\b(cuantos grados|que grados|grados (hay|existen|ofrece|ofrecen|tiene|hay disponibles)|lista de cursos|cursos (del colegio|hay|ofrecidos|existentes)|que cursos|grados ofrecidos|niveles educativos|que niveles)\b/u', $q0)
        && empty($s['group']))
        return $r('groups_list', 0.88);

    // horarios / bloques / recreo — «a qué hora es el recreo», «qué bloque sigue»
    if (preg_match('/\b(a que hora (es|empieza|termina|sale|entra|arranca)|que bloque|que periodo|recreo|descanso|receso|almuerzo|hora de (entrada|salida|receso|clase|almuerzo)|clase de \w+|siguiente bloque|proximo bloque|a que hora)\b/u', $q0)
        && !$mod
        && !preg_match('/\ba que hora (estamos|son)\b/u', $q0)
        // con sustantivo de población el «recreo» es ámbito, no horario
        && !preg_match('/\b(muchachos|pelados|pelaos|pelaitos|kilitos|estudiantes|alumnos|ninos|chicos)\b/u', $q0))
        return $r('schedule_info', 0.85, empty($s['group']));

    // «casos» sueltos = incidentes (no seguimientos) — sentido coloquial
    if (preg_match('/\bcasos?\b/u', $q0) && !$mod)
        return $quant ? $r('count_events', 0.82, true) : $r('list_events', 0.8, true);

    // «eventos / qué pasó / qué se registró» — incidentes genéricos del
    // período; «registradas/reportadas» con módulo es conteo
    if (preg_match('/\b(eventos?|que paso|que se registro|que ocurrio|que sucedio|novedades|que hubo|que se reporto|incidencias?)\b/u', $q0) && !$mod)
        return $quant ? $r('count_events', 0.82, true) : $r('list_events', 0.8, true);
    if ($mod && preg_match('/\b(registrad\w*|reportad\w*|contabilizad\w*|contad\w*|hubo|fueron|se dieron|ocurrieron|sucedieron|presentadas|presentados|recibidas|recibidos|llegaron)\b/u', $q0)
        && !preg_match('/\bquien\w*\b|\blos que\b|\blista\b|\bmuestra\w*\b|\bdame\b|\bdime\b/u', $q0))
        return $r('count_events', 0.85, true);

    // «excepto/menos/salvo los del X» — filtro de asistencia por exclusión
    if (preg_match('/^(?:y\s+)?(?:todos\s+)?(menos|excepto|salvo|menos los|menos las|excepto los|excepto las|salvo los|salvo las|todos menos|todos excepto|todos salvo)\b/u', $q0))
        return $r('attendance_today', 0.85);

    // población sin módulo — «los muchachos del once», «los kilitos del
    // recreo» — asistencia del grupo/ámbito (no horario)
    if (!$mod
        && preg_match('/\b(muchachos|pelados|pelaos|pelaitos|kilitos|estudiantes|alumnos|ninos|chicos|jovenes)\b/u', $q0)
        && !preg_match('/\b(quien es|datos|ficha|perfil|documento|celular|acudiente)\b/u', $q0))
        return $r('attendance_today', 0.83);

    // «quién es <nombre>» sin conector — el extractor no toma el nombre
    // suelto tras «es»; se extrae aquí si no es stopword
    if (empty($s['student'])
        && preg_match('/^(?:quien(?:es)? (?:es|son|era|eran|fue|fueron)|como se llama|se llama)\s+(?:el\s+|la\s+|al\s+|del\s+|de\s+)?([a-záéíóúñü]{3,}(?:\s+[a-záéíóúñü]{3,}){0,3})\s*[?¡! ]*$/u', $q0, $mwho)) {
        $cand = trim($mwho[1]);
        if (!in_array($cand, nxStudentStopwords(), true))
            return $r('student_summary', 0.88, true, ['student' => $cand]);
    }

    // parentesco con referente implícito — «el papá del que faltó»,
    // «la mamá de la que se enfermó», «el tutor del estudiante». El campo
    // relacional gana sobre el módulo del backreference («faltó»)
    if (empty($s['student']) && !empty($s['field'])
        && preg_match('/\b(del que|de la que|del que|de quien|del estudiante|de el|de la estudiante)\b/u', $q0))
        return $r('student_field', 0.84, true);

    // asistencia por posición — «todos menos los del 8a», «todos salvo…»
    if (preg_match('/\btodos? (menos|excepto|salvo|menos los|excepto los|salvo los)\b/u', $q0))
        return $r('attendance_today', 0.85);

    // presencia — «los presentes del 9a», «los que asistieron», «vinieron»
    if (preg_match('/\b(presentes|asistentes|asistieron|vinieron|entraron|ingresaron|se presentaron|presentaron|llegaron)\b/u', $q0)
        && !$mod
        && !preg_match('/\b(no|sin|falt\w*|tarde|retraso)\b/u', $q0))
        return $quant ? $r('count_present', 0.88) : $r('attendance_today', 0.85);

    // rankings / comparativas de asistencia
    if (preg_match('/\b(los? que mas|mas (faltan|evaden|reinciden|impuntuales)|record de|los peores|mas (tardanzas|inasistencias|faltas|evasiones|ausencias)|comparativ\w+|promedio de .{0,20}por grupo|ranking)\b/u', $q0)
        && empty($s['student']))
        return $r(empty($s['group']) ? 'top_offenders' : 'attendance_ranking', 0.88, true,
            preg_match('/\btop\s+(\d{1,2})\b|\bsolo\s+(\d{1,2})\b|\blos\s+(\d{1,2})\s+(primeros|mas)/u', $q0, $tk) ? ['_rank_limit'=>(int)($tk[1] ?: ($tk[2] ?: $tk[3]))] : []);

    // población/resumen de grupo — «población del 7b», «cómo va el sexto»
    if (!empty($s['group']) && !$mod) {
        if (preg_match('/\b(poblacion|cuantos (estudiantes|alumnos|pelados|muchachos|ninos|van)|matricula|censo del)\b/u', $q0))
            return $r('group_student_count', 0.9);
        if (preg_match('/\b(como va|como esta|como va el|estado|resumen|panorama|balance|que tal|como le va|como esta el)\b/u', $q0))
            return $r('group_summary', 0.88, true);
    }

    // censo institucional
    if (preg_match('/\b(censo|poblacion estudiantil|total de estudiantes|cuantos estudiantes hay|matricula total|cuanta gente|cuantos alumnos hay)\b/u', $q0)
        && empty($s['group']) && !$mod)
        return $r('students_count', 0.9);

    // ── incidentes de asistencia ──
    $incident = $mod && in_array($mod, NX_INCIDENT_MODULES, true);
    if ($incident) {
        if (preg_match('/\bpor (dias? de la semana|dia|fecha|estudiante|alumno|grupo|curso|salon|mes)\b/u', $q0, $gb)) {
            $by = str_starts_with($gb[1], 'dia de') || str_starts_with($gb[1], 'dias de') ? 'weekday'
                : (in_array($gb[1], ['dia','fecha'], true) ? 'day'
                : (in_array($gb[1], ['estudiante','alumno'], true) ? 'student'
                : ($gb[1] === 'mes' ? 'month' : 'group')));
            return $r('frequency_table', 0.9, empty($s['student']), ['group_by'=>$by]);
        }
        if (preg_match('/\b(estudiantes?|alumnos?|quienes|quien|los que)\b.{0,25}\b(con mas|que mas|mas|mayor numero de)\b/u', $q0)
            || preg_match('/\b(top|ranking|reincidentes?)\b/u', $q0) && !preg_match('/\bgrupos?\b/u', $q0)) {
            $ent = [];
            if (preg_match('/\btop\s+(\d{1,2})\b|\bsolo\s+(\d{1,2})\b|\blos\s+(\d{1,2})\s+(primeros|mas)/u', $q0, $tk))
                $ent['_rank_limit'] = (int)($tk[1] ?: ($tk[2] ?: $tk[3]));
            return $r('top_offenders', 0.9, true, $ent);
        }
        if (preg_match('/\b(grupos?|cursos?|salones?|grados?)\b.{0,30}\b(con mas|mas|menos|ranking|top|compar\w*)\b|\bcomo van los grupos\b/u', $q0))
            return $r('attendance_ranking', 0.88, true, preg_match('/\bdecim\w*/u', $q0) ? ['grade'=>'10'] : []);
        // «esta semana comparada con la pasada», «hoy vs ayer» → conteo con
        // comparación de períodos (el handler calcula ambos lados)
        if (preg_match('/\b(compar\w*|vs|versus|frente a|respecto a|respecto al|contra)\b/u', $q0)
            && !preg_match('/\bgrupos?\b|\b\d{1,2}\s*-?\s*[a-d]\s+(y|con|vs)\s+\d/u', $q0))
            return $r('count_events', 0.9, empty($s['student']), ['trend'=>true]);
        if ($quant)
            return $r('count_events', 0.9, empty($s['student']));
        if (preg_match('/^(quienes|quien|lista\w*|muestra\w*|dame|ver|cuales|nombres|dime)\b/u', $q0)
            || $words <= 6)
            return $r('list_events', $words <= 6 ? 0.85 : 0.9, empty($s['student']) && $words <= 6);
        // interrogativo en medio de la frase («cuales muchachos llegaron
        // tarde», «necesito saber quiénes faltaron») — sigue siendo listado
        if (preg_match('/\b(cuales|quienes|que muchachos|los que|muchos|muchachos|estudiantes|alumnos|necesito|quiero|muestra|dime|dame|traeme|ver|lista)\b/u', $q0))
            return $r('list_events', 0.82, empty($s['student']));
    }
    if (preg_match('/\b(como va|como esta|resumen|estado)\b.{0,12}\b(el|del)?\s*(grupo|curso)?\s*\d{1,2}\s*-?\s*[a-z]\b/u', $q0)
        && !empty($s['group']) && !$mod)
        return $r('group_summary', 0.88, true);
    if (preg_match('/\b(ficha|perfil|historial|hoja de vida|datos|informacion|info|detalles|todo|que sabes|que tienes|como va|como le va)\b/u', $q0) && !empty($s['student']))
        return $r('student_summary', 0.85, false);
    if (!empty($s['field']) && empty($s['module']) && empty($s['group'])
        && str_word_count($q0, 0, 'áéíóúñü0123456789') <= 4
        && in_array($s['field'], ['acudiente','documento','celular','telefono','grupo','jornada','nacimiento','nombre','documento_acudiente','celular_acudiente','nombre_acudiente'], true))
        return $r('student_field', 0.86, true);
    return null;
}

/**
 * Rescate determinista de dominio — cuando el parser cae a out_of_scope pero
 * el texto nombra sin ambigüedad un dominio cubierto («sensores del colegio»,
 * «los nodos», «anomalías del sistema», «salidas pedagógicas»), la consulta
 * SÍ es respondible: lo informal (nxLlmChat) no debe improvisar capacidades
 * ni el usuario recibir un "no sé" sobre datos que existen.
 * Solo sustantivos inequívocos — nada que pueda significar otra cosa.
 */
function nxDomainRescue(string $q0): ?string {
    static $map = null;
    $map ??= [
        'devices_status'    => '/\b(sensor|sensores|dispositivo|dispositivos|nodo|nodos|escaner|escáneres?|huellero|huelleros|lector biometrico)\b/u',
        'system_incidents'  => '/\b(anomalias?|incidencias? del sistema|fallas? del sistema|nodos? caidos?)\b/u',
        'permissions'       => '/\b(salidas? pedagogicas?|paseos?|pasantias?|salidas? de campo)\b/u',
    ];
    foreach ($map as $intent => $re) {
        if (preg_match($re, $q0)) return $intent;
    }
    return null;
}

/** Smalltalk/meta — un turno de cortesía no cambia el tema conversacional. */
const NX_SMALLTALK_INTENTS = ['greeting','greeting_time','wellbeing','wellbeing_reply','joke',
    'fun_fact','about_nexus','name_meaning','creator','age','thanks','goodbye',
    'yes','no','apology','compliment','insult','bored','love','human_check',
    'do_for_me','emotion_sad','weather','news_sports','food_music',
    'meaning_life','confused','repeat','insult_back','sing','dance','story',
    'motivation','foreign_culture','help','capabilities'];

/**
 * nxPlanResponse — planificador de respuesta fundamentado.
 * El handler es la fuente de verdad: si no produjo contenido, el plan
 * devuelve un fallo explícito (nunca inventa datos). Sella la
 * procedencia (qué intent/handler respondió) para auditoría.
 */
function nxPlanResponse(array $out, string $intent, string $handlerUsed): array {
    if (empty($out['reply']) && empty($out['cards'])) {
        $out['reply'] = 'No pude obtener datos para eso — '
            . 'no hay información disponible en tu alcance.';
    }
    $out['source'] = $handlerUsed;
    $out['intent'] = $out['intent'] ?? $intent;
    return $out;
}

function nxDialogueResolve(array $cls, ?array $ctx, string $q0): array {
    $intent = $cls['intent'];
    $conf   = $cls['confidence'] ?? 0;
    $slots  = $cls['entities'] ?? [];
    $coverageHit = false;
    $inherited = []; $newSlots = [];
    $turnType = 'new_request';
    $clarify = null;

    $followupMark = (bool)preg_match('/^(y|ahora|pero|tambien|ademas|solo|solamente|entonces|o sea|'
        . 'las|los|esas|esos|estas|estos|esa|ese|este|sus?|de|del|de la|de lo)\b/u', $q0)
        || (bool)preg_match('/^(y )?(ahora|ahora que|y ahora|y despues|y luego)\b[?]*$/u', $q0);
    // «ahora cuéntame/dime cuántos X» es consulta NUEVA — no continuación.
    // Solo «ahora» con anáfora («ahora su número», «ahora él») marca retorno.
    if ($followupMark && preg_match('/^ahora\b/u', $q0)
        && preg_match('/\b(cuantos|cuantas|cuantos|cuales|quienes|quien|que|donde|cuando|como|a que)\b/u', $q0)
        && !preg_match('/\b(su|sus|ese|esa|este|esta|el mismo|la misma|del mismo|ahi|alli|otro|otra|el|ella|de el|de ella)\b/u', $q0)) {
        $followupMark = false;
    }
    $explicitAction = (bool)preg_match('/\b(quiero|deseo|necesito|puedes|podrias|citar|cita|generar|'
        . 'enviar|mandar|reportar|autorizar|crear|abrir|registrar|derivar|exportar|'
        . 'descargar|hacer|empezar|iniciar|lanzar)\b/u', $q0);
    // marcador de corrección explícita — fuerte: «digo», «me refería», «corrijo»;
    // débil: «no»/«perdón» solo cuentan si además hay un slot nuevo que reemplazar
    $correctionStrong = (bool)preg_match('/\b(digo|quiero decir|me referia|'
        . 'me equivoque|corrijo|en realidad|mas bien|o sea no)\b/u', $q0);
    // «los que no se enviaron / que no vinieron» — la negación forma parte
    // del subconjunto pedido, no es una corrección del turno anterior
    $correctionWeak = (bool)(preg_match('/\b(no|perdon|perdona)\b/u', $q0)
        && !preg_match('/\bque no\b|\bno se \w+|\bno vinieron|\bno llegaron|\bno faltaron|\bno asistieron\b/u', $q0));
    // confirmación/cancelación explícita — empieza por verbo de confirmación/
    // cancelación («confirmo la solicitud» confirma, no inicia otra operación)
    $confirmMark = (bool)preg_match('/^(ahora |pero |y |entonces )?(si|sí|confirmo|confirma|'
        . 'confirmar|dale|hazlo|adelante|correcto|de acuerdo|perfecto|vale|ok|bueno si|listo si)\b/u', $q0);
    $cancelMark = (bool)preg_match('/^(ahora |pero |y |espera |entonces |no[, ]+|nel[, ]+|mejor )?(cancela|cancelar|'
        . 'cancelo|dejalo|deja|deja asi|dejalo asi|asi dejalo|olvida|olvídalo|mejor no|ya no|detente|parale|no eso|no toques|no cambies|como estaba)\b/u', $q0)
        || preg_match('/^(no|nel|nop)\b[.! ]*$/u', $q0);

    // §15 ctx corrupto: tipos incorrectos se invalidan, nunca propagan
    $ctxEntities = is_array($ctx['entities'] ?? null) ? $ctx['entities'] : [];
    $lastIntent  = is_string($ctx['last_intent'] ?? null) ? $ctx['last_intent'] : null;
    $inheritable = $lastIntent && in_array($lastIntent, NX_QUERY_INTENTS, true);
    // fragmentos puros: «en el mes», «y la última semana», «y en todo el
    // colegio» — no traen tema propio; el tema es el del turno anterior
    $temporalFrag = nxIsTemporalFragment($q0);
    $scopeWiden   = nxIsScopeWidening($q0);
    // propuesta del parser bajo umbral (fragmento que copió el tema)
    $proposed = is_string($cls['proposed_intent'] ?? null) ? $cls['proposed_intent'] : null;

    $hasNewEntity = false;
    foreach (['student','group','module','days','field'] as $k)
        if (!empty($slots[$k])) { $hasNewEntity = true; break; }
    $correctionMark = $correctionStrong || ($correctionWeak && $hasNewEntity);
    // la cola compartida consulta $dependent aunque el turno entró por
    // la rama de corrección (que no lo define) — default honesto
    $dependent = false;

    // ── 1. Corrección explícita: «no, de María» → solo reemplaza el slot nuevo ──
    if ($correctionMark && $inheritable && ($hasNewEntity || $correctionStrong)) {
        $turnType = 'correction';
        // el intent previo se mantiene; el slot nuevo (student/group/days/field)
        // REEMPLAZA al heredado — no se acumula.
        foreach (['student','group','module','days','from','to','range_label','field'] as $k) {
            if (!empty($slots[$k])) { $newSlots[] = $k; }
            elseif (!empty($ctxEntities[$k])) { $slots[$k] = $ctxEntities[$k]; $inherited[] = $k; }
        }
        // «los que faltan digo» — la corrección trae su propio intent
        // concreto: usarlo. Solo cuando el texto corregido no clasificó
        // a nada propio se repite el intent anterior.
        if (!in_array($intent, ['out_of_scope','confused','deictic','clarify','repeat','smalltalk','greeting','yes','no','thanks','goodbye'], true)) {
            $inherited[] = 'intent_corrected';
            // la corrección «no, el de <nombre>» no pide un alumno
            // aleatorio ni un docente: es el MISMO objetivo corregido
            // a otro sujeto — el intent de tema manda sobre el clasificado
            if (in_array($intent, ['random_student','staff_lookup'], true)
                && !empty($slots['student'])
                && in_array($lastIntent, NX_QUERY_INTENTS, true)) {
                $intent = $lastIntent;
                $inherited[] = 'intent';
            }
        } else {
            $intent = $lastIntent;
            $inherited[] = 'intent';
        }
    } else {
        // ── 2. Herencia de slots — SOLO en turnos dependientes (regla F:
        // una consulta autónoma no hereda entidades arbitrariamente).
        // También cuentan como dependencia: verbos en 3ª persona que
        // presuponen sujeto («acumula», «tiene», «lleva», «sigue») ──
        $deicticVerb = (bool)preg_match('/\b(acumula|tiene|lleva|sigue|mantiene|hizo|hace|hicieron|fue|estuvo|anda|va|viene|quedo|quedaron|resulto)\b/u', $q0);
        // interrogativo sin sustantivo de tema («cuales fueron justificadas»)
        // — depende del tema anterior; con tema propio es autónoma
        $interrogDep = (bool)(preg_match('/^(cuales?|quien|quienes|cuant[oa]s?|que)\b/u', $q0)
            && !preg_match('/\b(tardanza|inasist|falt|evasion|permiso|citacion|cita|evento|incident|seguim|notif|salid|estudiant|mensaje|alerta|ausen|presente|ingres|cumpl|tarea|pendient|docent|acudient|llamad|horario|nota|grado|grupo|salon|jornada|correo|cumpleano|caso|casos|alumno|reporte|biometr|exent|consentim|calendario|festiv|feriad|paseo|salida|sensor|dispositiv|sos|matricul|excusa|justific|riesgo|exencion|paseo|pedagogic|rector|coordinador|psicolog|secretari|personal|planta|emplead|funcionari|trabaj|porter|celador|vigilan|turno|manana|tarde|noche|asistencia|puntualidad|ranking|mejor|peor|menos|ping|spam|marcac|avance|progreso|motivo|razon|causa|clase|materia|rechaz|justific|nuev\w* estudiant)/u', $q0));
        // sustantivo desnudo de campo («documento», «acudiente», «el teléfono»)
        // — ≤3 palabras con un sustantivo de ficha: presupone el sujeto activo
        $bareNoun = (bool)(str_word_count($q0, 0, 'áéíóúñü') <= 4
            && preg_match('/\b(documento|telefono|celular|acudiente|contacto|direccion|correo|ficha|datos|perfil|resumen|horario|grupo|salon|papa|mama|padre|madre|familiar|edad|cumpleanos|numero|whatsapp|cedula|identificacion)\b/u', $q0));
        $dependent = $followupMark || $correctionWeak || $deicticVerb || $interrogDep || $bareNoun
            || $temporalFrag || $scopeWiden
            // enclítico anafórico: «compáralas», «muéstramelas», «expórtalos»
            || (bool)preg_match('/\b\w+(?:ar|er|ir|a|e)(?:las|los|melas|melos|selas|selos)\b/u', $q0)
                && (bool)preg_match('/\b(compar|muestr|export|descarg|pas|dam|list|orden|filtr)\w*/u', $q0)
            // deícticos espaciales/demostrativos — «este/esta» EXCLUIDO:
            // «esta semana», «este mes» son temporales, no referenciales
            || (bool)preg_match('/\b(ahi|alli|alla|aca|ahi mismo|ahi dentro|alli dentro|en ese|en esa|esos|esas|del mismo|de la misma|el mismo|la misma|del tal|ese|esa)\\b/u', $q0);
        if ($ctxEntities && $dependent) {
            // 'field' excluido: el campo pedido es de la frase, no del tema —
            // «y cuántas evasiones tiene» no debe arrastrar 'documento'
            foreach (['student','group','module','days','from','to','range_label'] as $k) {
                // days=0 («hoy») es un valor válido — isset, no empty
                $absent = $k === 'days' ? !isset($slots[$k]) : empty($slots[$k]);
                $ctxHas = array_key_exists($k, $ctxEntities) && $ctxEntities[$k] !== null;
                if ($absent && $ctxHas) {
                    $slots[$k] = $ctxEntities[$k];
                    $inherited[] = $k;
                } elseif (!$absent) {
                    $newSlots[] = $k;
                }
            }
        }
        // ── referencias conversacionales §5 — «su X», «de su acudiente»,
        // navegación de resultados. El _ds del servidor trae la entidad
        // activa y el result-set; el NLU solo detecta la referencia.
        $dsState = $ctx['_ds'] ?? null;
        // el set existe aunque traiga 0 ítems — «los demás/el primero»
        // sobre un resultado vacío es nav honesta («no hay nada»), no
        // una consulta nueva; el handler decide la respuesta
        $hasResult = isset($dsState['last_result']);
        // «todos» exacto con set activo = expandir ESE set (nav=all), no
        // ampliar el alcance a toda la institución — el exact-match gana
        $allSetExact = $hasResult && !empty($dsState['last_result']['items'])
            && (bool)preg_match('/^(y |ahora |dame |dime |muestra(?:me)? |muestrame |trae(?:me)? )?(todos|todas|todo|todos ellos|todas ellas|el listado completo|la lista completa|la lista entera|la nomina completa)[.! ]*$/u', $q0);
        // «dame otro / el siguiente / el primero / los demás / su nombre»
        // sobre el result-set anterior — consulta informativa, nunca op.
        // Un fragmento temporal («la última semana», «los últimos 15 días»)
        // NO es navegación posicional aunque diga «último».
        if ($hasResult && !$temporalFrag && !($scopeWiden && !$allSetExact)) {
            $nav = null;
            // ── transformaciones sobre el set activo (§12-13): proyección,
            // orden, slice, goto — antes de los ordinales sueltos ──
            if (preg_match('/\b(solo (los |las |sus )?nombres?|nada mas (los |las |sus )?nombres?|solo sus nombres|sin documentos?|solo el nombre)\b[.!? ]*$/u', $q0)) $nav = 'proj:name';
            // «dame sus nombres», «sus teléfonos», «los datos» — proyección
            // de campos sobre el set activo («sus» = del set, no de persona)
            elseif (preg_match('/^(?:y\s+|ahora\s+|dame\s+|dime\s+|muestra(?:me)?\s+|muestrame\s+|trae(?:me)?\s+|pasame\s+)?(?:sus|los|las|todos sus|de cada uno)\s+(nombres?|apellidos?|telefonos?|celulares?|numeros?|documentos?|cedulas?|acudientes?|correos?|grupos?|datos|fichas?)\b[.!? ]*$/u', $q0, $mpj))
                $nav = 'proj:' . (in_array($mpj[1], ['telefonos','celulares','numeros'], true) ? '+phone'
                    : (in_array($mpj[1], ['documentos','cedulas'], true) ? '+document'
                    : (in_array($mpj[1], ['acudientes'], true) ? '+guardian'
                    : (in_array($mpj[1], ['grupos'], true) ? '+group' : 'name'))));
            elseif (preg_match('/\b(agrega|añade|anade|incluye|ponles|mete|con)\s*(le|les|me)?\s*(el |los |la |las )?(documento|documentos|telefono|celular|whatsapp|grupo|edad)\b/u', $q0, $mp)
                    && preg_match('/\b(agrega|añade|anade|incluye|ponles|mete)\b/u', $q0))
                $nav = 'proj:' . ['documento'=>'+document','documentos'=>'+document','telefono'=>'+phone','celular'=>'+phone','whatsapp'=>'+phone','grupo'=>'+group','edad'=>'+group'][$mp[4]];
            // «con documento» desnudo tras un set = añade la columna a la
            // vista activa — no es un lookup de estudiante
            elseif (preg_match('/^(?:y\s+|ahora\s+|pero\s+)?con\s+(?:el\s+|los\s+|la\s+|las\s+|su\s+|sus\s+)?(documento|documentos|telefono|celular|whatsapp|grupo)\b[.!? ]*$/u', $q0, $mpb))
                $nav = 'proj:' . ['documento'=>'+document','documentos'=>'+document','telefono'=>'+phone','celular'=>'+phone','whatsapp'=>'+phone','grupo'=>'+group'][$mpb[1]];
            // «esos datos / esos resultados / una tabla de esos» — referencia
            // explícita al set activo: con o sin «tabla», renderiza el set
            elseif (preg_match('/\b(una |la )?tabla\s+(de|con|sobre|para)\b|\b(una |la )?tablita\b|\b(esos|esas|estos|estas|los|las)\s+(datos|resultados|registros|valores|nombres|inasistentes)\b|\b(esa|esta)\s+(info|informacion)\b|\bde esos\b|\ben un cuadro\b/u', $q0))
                $nav = 'table';
            elseif (preg_match('/\b(ordena(?:l[oa]s|me|los|las)?|por apellido|alfabeticamente|alfabetico|por nombre|por documento|por grupo|de la a a la z)\b/u', $q0)
                    && !preg_match('/\b(de|del|en|grupo|salon)\s+\d/u', $q0))
                $nav = 'sort:' . (preg_match('/\b(apellido)\b/u',$q0) ? 'last_name'
                              : (preg_match('/\b(nombre|alfabetic|de la a)\b/u',$q0) ? 'first_name'
                              : (preg_match('/\b(documento|cedula)\b/u',$q0) ? 'document' : 'group')));
            elseif (preg_match('/\b(?:los|las)\s+(\d+|un|una|dos|tres|cuatro|cinco|seis|siete|ocho|nueve|diez)\s+(primer[oa]?s?|ultim[oa]s?)\b/u', $q0, $ms)
                    || preg_match('/\b(?:los|las)\s+(primer[oa]s?|ultim[oa]s?)\s+(\d+|un|una|dos|tres|cuatro|cinco|seis|siete|ocho|nueve|diez)\b/u', $q0, $msr)
                    || preg_match('/\b(?:los|las)\s+(\d+|un|una|dos|tres|cuatro|cinco|seis|siete|ocho|nueve|diez)\s+(?:de arriba|iniciales)\b/u', $q0, $ms2)
                    || preg_match('/\bsolo\s+(?:los|las)\s+(\d+|un|una|dos|tres|cuatro|cinco|seis|siete|ocho|nueve|diez)\b/u', $q0, $ms3)) {
                // «los N primeros» y «los primeros N» — slice del set activo
                $nums = ['un'=>1,'una'=>1,'dos'=>2,'tres'=>3,'cuatro'=>4,'cinco'=>5,'seis'=>6,'siete'=>7,'ocho'=>8,'nueve'=>9,'diez'=>10];
                if (isset($msr[1]))        { $k = $nums[$msr[2]] ?? (int)$msr[2]; $from = str_starts_with($msr[1],'ultim') ? 'end' : 'start'; }
                elseif (isset($ms2[1]))    { $k = $nums[$ms2[1]] ?? (int)$ms2[1]; $from = 'start'; }
                elseif (isset($ms3[1]))    { $k = $nums[$ms3[1]] ?? (int)$ms3[1]; $from = 'start'; }
                else                       { $k = $nums[$ms[1]] ?? (int)$ms[1]; $from = str_starts_with($ms[2],'ultim') ? 'end' : 'start'; }
                if (!preg_match('/\b(de|del|en|grupo|salon)\s+[\da-z]/u', $q0))
                    $nav = 'slice:' . $k . ':' . $from;
            }
            elseif (preg_match('/\b(vuelve|vuelveme|regresa|devuelvete|volvamos|vamos de nuevo|regresemos)\s+(al|a la|a los|a las)\s+(primer[oa]?s?|segund[oa]?s?|tercer[oa]?s?|ultim[oa]s?|estudiante|resultado|inicio|principio)\b/u', $q0, $mg)) {
                $w2 = trim($mg[3]);
                $nav = 'goto:' . (['segundo'=>2,'segunda'=>2,'tercero'=>3,'tercera'=>3][$w2]
                    ?? (in_array($w2,['ultimo','ultima'],true) ? count($dsState['last_result']['items'] ?? [1]) : 1));
            }
            elseif (preg_match('/^(y |ahora |y ahora |dame |dime |muestra(?:me)? |trae(?:me)? )?(el |la |los |las )?(otro|otra|uno mas|una mas|mas|siguiente|el siguiente|y otro|y otra|de nuevo|el proximo|la proxima|continua|sigue)[.! ]*$/u', $q0)) $nav = 'next';
            elseif (preg_match('/\b(el|la|los|las)? ?(primer[oa]?s?|segund[oa]?s?|tercer[oa]?s?|ultim[oa]s?|penultim[oa]s?|anterior|siguiente|proxim[oa])\b/u', $q0, $mnav)
                && !preg_match('/\b(primer|primero|ultimo) (dia|día|mes|lunes|martes|miercoles|jueves|viernes|sabado|domingo|periodo|bimestre|ano|año|semestre|trimestre|corte|semana)\b/u', $q0)) {
                $w = trim($mnav[2]);
                $ord = ['primero'=>1,'primera'=>1,'primer'=>1,'segundo'=>2,'segunda'=>2,'tercero'=>3,'tercera'=>3,'ultimo'=>$rsCount??0,'ultima'=>$rsCount??0,'anterior'=>0,'siguiente'=>0,'proximo'=>0,'proxima'=>0,'penultimo'=>-1,'penultima'=>-1];
                $n2 = count($dsState['last_result']['items']);
                if ($w === 'anterior') $nav = 'prev';
                elseif (in_array($w, ['siguiente','proximo','proxima'], true)) $nav = 'next';
                elseif (in_array($w, ['ultimo','ultima'], true)) $nav = "nth:{$n2}";
                elseif (in_array($w, ['penultimo','penultima'], true)) $nav = 'nth:' . max(1, $n2 - 1);
                else $nav = 'nth:' . ($ord[$w] ?? 1);
            }
            // «todos» = el set COMPLETO activo (caso J), no el resto que
            // falta tras un slice — «los demás» sí es el resto
            elseif (preg_match('/^(y |ahora |dame |dime |muestra(?:me)? |muestrame |trae(?:me)? )?(todos|todas|todo|todos ellos|todas ellas|el listado completo|la lista completa|la lista|el listado|el resultado|los resultados|los datos)[.!? ]*$/u', $q0)) $nav = 'all';
            elseif (preg_match('/^(y |ahora |y ahora |dame |dime |muestra(?:me)? |muestrame |trae(?:me)? )?(los demas|las demas|los otros|las otras|el resto|los que faltan|los restantes)[.! ]*$/u', $q0)) $nav = 'rest';
            elseif (preg_match('/\b(en tabla|en una tabla|como tabla|formato tabla|ponmelos en una tabla|ponlos en tabla|en columnas|en cuadro|tabulados?|la tabla completa|todos en tabla|muestralos todos|muéstralos todos|muestramelos todos|muéstramelos todos|pasame todos|dame todos|lista completa|la lista entera|la nomina completa|el listado completo)\b/u', $q0)) $nav = 'table';
            elseif (preg_match('/\b(cuantos|cuantas|cuanto|cuanta|cuantos son|cuantas son|cuantos hay|cuantas hay)( son| hay| eran| fueron| resultaron| en total| son en total| al final| en total son)?\b[?¡! ]*$/u', $q0)) $nav = 'count';
            elseif (preg_match('/\b(cual|como|quien) (es|fue|se llama)? ?(su|el) (nombre|como se llama)\b[?¡! ]*$/u', $q0)
                || preg_match('/^(y )?(su nombre|el nombre|como se llama|quien es|quien era)[.!? ]*$/u', $q0)) $nav = 'name';
            if ($nav) { $slots['_nav'] = $nav; $turnType = 'context_modify'; }
        }
        // «del primero / del segundo / del último / la primera» — con
        // referente activo (set, tema de grupo o campo relacional) es
        // POSICIÓN sobre el tema, nunca grado N. No exige slots['group']:
        // «la primera» no produce grado porque falta en los conectores del
        // extractor, pero el ordinal en texto + set activo basta.
        if (preg_match('/\b(?:del|de|el|la|los|las)\s+(primero|primera|segundo|segunda|tercero|tercera|cuarto|cuarta|quinto|quinta|primer|tercer|ultimo|ultima)\b(?!\s+(?:de|del|en|a|por|para|dia|mes|semana|ano|lugar|puesto)\b)/u', $q0, $mog)
            && !preg_match('/\b(grado|grupo|salon|curso)\b/u', $q0)
            && (!preg_match('/\d/', $q0) || !empty($slots['group']))
            && (!empty($dsState['last_result']['items']) || !empty($ctxEntities['group']) || !empty($slots['field']))) {
            $posOrd = ['primero'=>1,'primera'=>1,'primer'=>1,'segundo'=>2,'segunda'=>2,
                       'tercero'=>3,'tercera'=>3,'cuarto'=>4,'cuarta'=>4,'quinto'=>5,'quinta'=>5];
            $p = in_array($mog[1],['ultimo','ultima'],true) ? 'last' : ($posOrd[$mog[1]] ?? 1);
            unset($slots['group']);
            $slots['position'] = $p;
            if (!empty($dsState['last_result']['items']) && empty($slots['_nav'])) {
                $n2 = count($dsState['last_result']['items']);
                $slots['_nav'] = 'nth:' . ($p === 'last' ? $n2 : $p);
                $turnType = 'context_modify';
            }
        }
        }
        // «su <campo>» sobre la entidad activa (estudiante/acudiente)
        // — NO es número de lotería ni dato del asistente
        if (!isset($slots['_nav'])) {
            // «su ficha / su resumen / su perfil / su expediente» = la ficha
            // completa de la persona activa, no un campo aislado
            if (preg_match('/\b(su|sus|suyo|suya|de el|de ella|del estudiante|del alumno|del nino|del muchacho|de ese|de esa|de este|de esta)\s+(ficha|ficha completa|resumen|perfil|historial|expediente|carpeta|datos completos|toda la info|info completa|informacion completa)\b/u', $q0)
                && !in_array($intent, ['start_operation','derive_action','confirm_op','security_probe','export_data'], true)) {
                if (empty($slots['student']) && !empty($ctxEntities['student'])) {
                    $slots['student'] = $ctxEntities['student']; $inherited[] = 'student';
                }
                if ($intent !== 'student_field') $intent = 'student_summary';
                $turnType = 'context_modify';
                $coverageHit = true;
            }
            $refField = [
                'numero|telefono|celular|whatsapp|contacto|datos de contacto|datos? de contacto' => 'celular',
                'documento|cedula|identificacion'           => 'documento',
                'acudiente|tutor|responsable|papa|mama'     => 'acudiente',
                'grupo|salon|curso|seccion'                 => 'grupo',
                'jornada|turno|horario'                     => 'jornada',
                'nacimiento|fecha de nacimiento|cumpleanos' => 'nacimiento',
                'correo|email|mail'                         => 'correo',
                'direccion|residencia|barrio|donde vive'    => 'direccion',
                'eps|seguro|salud|afiliacion'               => 'eps',
                'estrato'                                   => 'estrato',
                'restricciones|alergias|condicion'          => 'restricciones',
                'nombre'                                    => 'nombre',
            ];
            foreach ($refField as $pat => $f) {
                if (preg_match('/\b(su|sus|suyo|suya|de el|de ella|del estudiante|del alumno|del nino|del muchacho|de ese|de esa|de este|de esta)\s+(' . $pat . ')\b/u', $q0)
                    // «citar/generar X a su acudiente» es OPERACIÓN — el «su»
                    // marca el beneficiario, no una consulta de campo
                    && !in_array($intent, ['start_operation','derive_action','confirm_op','security_probe','export_data'], true)) {
                    $slots['field'] = $f;
                    if (empty($slots['student']) && !empty($ctxEntities['student'])) {
                        $slots['student'] = $ctxEntities['student']; $inherited[] = 'student';
                    }
                    if ($f === 'acudiente') $slots['_ref'] = 'guardian';
                    // campo explícito («su número», «su correo») gana al
                    // intent heredado — solo cede cuando la frase entera
                    // pedía ficha/resumen, no un dato puntual
                    if ($intent !== 'student_field'
                        && !preg_match('/\b(ficha|perfil|resumen|datos completos|info completa|informacion completa|todo sobre|todo lo de)\b/u', $q0))
                        $intent = 'student_field';
                    $turnType = 'context_modify';
                    $coverageHit = true;
                    break;
                }
            }
            // «y el número / y el grupo / y el acudiente» — campo corto
            // sobre la entidad activa; no es número de lotería
            if (preg_match('/^(y |y el |y su |y la )?(el |su |la )?(numero|telefono|celular|whatsapp|contacto|grupo|salon|curso|acudiente|tutor|documento|cedula|jornada|nombre)\b[.!? ]*$/u', $q0, $mf)
                && !empty($ctxEntities['student'])) {
                $w2 = $mf[3];
                if (preg_match('/grupo|salon|curso/', $w2)) $slots['field'] = 'grupo';
                elseif (preg_match('/acudiente|tutor/', $w2)) $slots['field'] = 'acudiente';
                elseif (preg_match('/documento|cedula/', $w2)) $slots['field'] = 'documento';
                elseif ($w2 === 'nombre') $slots['field'] = 'nombre';
                elseif ($w2 === 'jornada') $slots['field'] = 'jornada';
                else $slots['field'] = !empty($dsState['person']['type']) && $dsState['person']['type'] === 'guardian'
                        ? 'celular_acudiente' : 'celular';
                if (empty($slots['student'])) { $slots['student'] = $ctxEntities['student']; $inherited[] = 'student'; }
                $intent = 'student_field';
                $turnType = 'context_modify';
                $coverageHit = true;
            }
            // «el documento DE SU ACUDIENTE» — campo sobre el acudiente,
            // no sobre el estudiante: entidad objetivo = guardian
            if (preg_match('/\b(documento|cedula|numero|telefono|celular|whatsapp|nombre|contacto)\s+(?:de\s+(?:su|el|la)|del|de la)\s+(acudiente|acudientes|tutor|tutora|responsable|representante|papa|mama|padre|madre|encargad[oa]|familiar)\b/u', $q0, $mg)) {
                $slots['_ref'] = 'guardian';
                $slots['field'] = preg_match('/documento|cedula/', $mg[1]) ? 'documento_acudiente'
                                : (preg_match('/nombre/', $mg[1]) ? 'nombre_acudiente'
                                : (preg_match('/contacto/', $mg[1]) ? 'acudiente' : 'celular_acudiente'));
                if (empty($slots['student']) && !empty($ctxEntities['student'])) {
                    $slots['student'] = $ctxEntities['student']; $inherited[] = 'student';
                }
                $intent = 'student_field';
                $turnType = 'context_modify';
                $coverageHit = true;
            }
            // «su número» a secas — si el person activo es el acudiente,
            // se refiere a SU número, no al del estudiante
            elseif (preg_match('/\b(su numero|su telefono|su celular|su whatsapp|el numero de (el|ella)|el celular de (el|ella))\b/u', $q0)
                && !empty($dsState['person']['type']) && $dsState['person']['type'] === 'guardian') {
                $slots['field'] = 'celular_acudiente';
                if (empty($slots['student']) && !empty($ctxEntities['student'])) {
                    $slots['student'] = $ctxEntities['student']; $inherited[] = 'student';
                }
                $intent = 'student_field';
                $turnType = 'context_modify';
            }
        // deíctico «el mismo X»: «las tardanzas del mismo grupo» → el slot
        // referido se toma SIEMPRE del contexto aunque el mensaje lo nombre
        if ($followupMark && $ctxEntities) {
            if (preg_match('/mism[oa]s?\s+(grupo|salon)/u', $q0) && !empty($ctxEntities['group'])) {
                $slots['group'] = $ctxEntities['group']; $inherited[] = 'group';
            }
            if (preg_match('/mism[oa]s?\s+(estudiante|alumn[oa]|niñ[oa]|pelad[oa]|muchach[oa])/u', $q0) && !empty($ctxEntities['student'])) {
                $slots['student'] = $ctxEntities['student']; $inherited[] = 'student';
            }
            // «del mismo grupo» con consulta de eventos activa — la métrica
            // se conserva pero el alcance pasa al grupo DEL ESTUDIANTE
            if ($inheritable
                && (!empty($slots['group']) || !empty($ctxEntities['student']))
                && in_array($lastIntent, ['count_events','list_events','late_today','attendance_today','top_offenders'], true)
                && in_array($intent, ['late_today','attendance_today','out_of_scope','group_summary','count_events','list_events'], true)) {
                $intent = in_array($lastIntent, ['late_today','attendance_today'], true) ? 'count_events' : $lastIntent;
                if (empty($slots['group'])) { $slots['_ref'] = 'student_group'; $inherited[] = 'group'; }
                if (empty($slots['module']) && !empty($ctxEntities['module'])) {
                    $slots['module'] = $ctxEntities['module']; $inherited[] = 'module';
                }
                $turnType = 'context_modify';
            }
        }
        // «quién responde por él / quién está a cargo de / quién figura como
        // responsable de X» — identidad del acudiente (target=guardian)
        if (in_array($intent, ['student_field','student_summary','staff_lookup','out_of_scope','audit_query','random_student'], true)
            && preg_match('/\b(quien|quienes)\b.{0,30}\b(responde por|a cargo de|figura como|esta registrado como|aparece como|representa a|lo representa|la representa|lo cuida|la cuida|lo atiende|la atiende|responde por el|responde por ella|a nombre de quien|responsable de|encargado de)\b/u', $q0)) {
            $slots['field'] = 'acudiente';
            $slots['_ref'] = 'guardian';
            $intent = 'student_field';
            $turnType = 'context_modify';
        }
        // staff_lookup con student SOLO heredado + vocabulario de planta
        // («qué personal trabaja en la mañana» tras una ficha de alumno) —
        // el student es fuga de contexto, no un referente; se recorta
        // antes de que el bloque siguiente lo convierta en ficha
        if ($intent === 'staff_lookup' && !empty($slots['student'])
            && in_array('student', $inherited, true)
            && preg_match('/\b(personal|planta|emplead\w*|funcionari\w*|trabaj\w*|jornada|turno|manana|tarde|noche|docentes?|profesores?|porter\w*|celador|vigilan|quienes|staff)\b/u', $q0)) {
            unset($slots['student']);
            $inherited = array_values(array_diff($inherited, ['student']));
        }
        // «el de juan camilo ospina» tras «el acudiente» — nombre propio
        // multi-palabra sobre el campo heredado, no docente por materia
        // ni alumno aleatorio. Palabra de cargo («la rectora»,
        // «coordinadora personal») no es nombre — queda staff_lookup.
        if (in_array($intent, ['staff_lookup','random_student'], true) && !empty($slots['student'])
            && !preg_match('/\b(rectora?|coordinador\w*|orientador\w*|psicoorientador\w*|psicolog\w*|docente|profesor\w*|maestr[oa]s?|profe|secretari\w*|porter\w*|celador\w*|personal|directiv\w*|emplead\w*|funcionari\w*|administrativ\w*|auxiliar)\b/u', (string)$slots['student'])) {
            if (!empty($ctxEntities['field'])) {
                $slots['field'] = $ctxEntities['field']; $inherited[] = 'field';
                $intent = 'student_field'; $turnType = 'context_modify';
            } elseif (in_array($lastIntent, ['student_field','student_summary'], true)) {
                $intent = $lastIntent; $inherited[] = 'intent';
                $turnType = 'context_modify';
            } else $intent = 'student_summary';
        }
        // «ese/este/del estudiante|alumno|niño|muchacho» — referente deíctico
        // al estudiante activo del contexto (§7: pronombres/deícticos)
        if (empty($slots['student']) && !empty($ctxEntities['student'])
            && preg_match('/\b(ese|esa|este|esta|del|de ese|de esa|el|la)\s+(estudiante|alumn[oa]|niñ[oa]|muchach[oa]|pelad[oa]|chin[oa]|menor)\b/u', $q0)
            && in_array($intent, ['student_field','student_summary','staff_lookup','audit_query','random_student','out_of_scope'], true)) {
            $slots['student'] = $ctxEntities['student']; $inherited[] = 'student';
            if ($intent === 'out_of_scope' || $intent === 'random_student' || $intent === 'audit_query') {
                $intent = 'student_field'; $turnType = 'context_modify';
            }
        }
        // «¿cuántas evasiones los últimos 30 días?» tras una ficha de
        // estudiante — el sujeto activo es ESA persona: la consulta de
        // eventos hereda el referente (la persona no se repite porque se
        // entiende). Ámbito explícito («en el colegio») sí lo rompe.
        if (empty($slots['student']) && empty($slots['group']) && empty($slots['person'])
            && !empty($ctxEntities['student'])
            && !preg_match('/\b(colegio|institucion|plantel|en general|en total|todos los grupos|todo el)\b/u', $q0)
            && in_array($intent, ['count_events','list_events','attendance_today','late_today','frequency_table','permissions','citations','trackings'], true)
            && in_array($lastIntent, ['student_field','student_summary','derive_action','start_operation','random_student'], true)) {
            $slots['student'] = $ctxEntities['student']; $inherited[] = 'student';
            $turnType = 'context_modify';
        }
        // «volvamos a X / vuelve a X» — retorno explícito al tema.
        // El nombre termina en ':', '?' o la primera palabra interrogativa.
        if (preg_match('/\b(vol(?:vamos|ve|ver|vamos) a|retomemos|de nuevo con|regresemos a|pasemos a)\s+([a-záéíóú]+(?:\s+[a-záéíóú]+){0,3})/u', $q0, $mv)) {
            $raw = preg_split('/[:?¿!.,]/u', $mv[2])[0];
            $name = preg_replace('/\b(quien|quienes|que|cual|cuales|cuanto|cuanta|cuantos|cuantas|donde|cuando|como|por que|para que|el|la|los|las|al|del|tema|caso)\b.*$/u', '', $raw);
            $name = preg_replace('/\b(el|la|los|las|al|del|tema|caso)\b/u', '', $name);
            $name = trim(preg_replace('/\s+/', ' ', $name));
            if (strlen($name) > 2 && empty($slots['student'])) {
                $slots['student'] = $name;
            }
        }
        // §21/§23 — turno de CONTEXTO que el modelo desvió a smalltalk/
        // probe/audit: si solo modifica tiempo/referencia y hay tema activo,
        // la conversación manda, no la clasificación aislada
        if ($inheritable && !$coverageHit && !isset($slots['_nav'])) {
            // «y ayer / y la semana pasada / y del último mes» — solo
            // cambia el tiempo. Un turno 100% temporal no puede ser
            // «posición»: «último mes» es rango, no ordinal de la lista.
            // nxIsTemporalFragment cubre las formas con artículo/conector
            // («en el mes», «y la última semana», «y los últimos 15 días»)
            // que la regex literal no alcanzaba — el turno es 100% tiempo:
            // la consulta anterior se repite con el rango nuevo.
            // «el mismo / lo mismo / la misma» desnudo — «repite lo mismo»:
            // la consulta anterior se re-ejecuta idéntica. Formas ancladas
            // al mensaje completo: «del mismo grupo» sigue siendo slot-ref.
            if ($temporalFrag
                || preg_match('/^(y |pero )?(el |la |lo |los |las )?mism[oa]s?[.!? ]*$/u', $q0)
                || (preg_match('/^(y |pero |ahora |entonces |o sea )?(del |de la |de |en |sobre )?(ayer|anteayer|hoy|esta semana|la semana pasada|este mes|el mes pasado|mes pasado|la semana|del mes|de hoy|de ayer|el otro dia|ese dia|esa semana|ese mes|semana pasada|semana anterior|mes anterior|ano pasado|ultimos? \d+ dias?|\d+ dias?|ultimo mes|ultima semana|ultimo ano|la quincena|el bimestre pasado|bimestre pasado|lo que va del mes|lo que va de la semana|lo corrido del mes)\b[.!? ]*$/u', $q0)
                    && in_array($intent, ['foreign_culture','out_of_scope','smalltalk','greeting','yes','no','audit_query','about_nexus','deictic','students.position','incidents.position','students_in_group','list_events','count_events','birthdays_today','random_student','student_summary','group_summary','attendance_today','count_present','late_today','permissions','day_summary','trackings'], true))) {
                $intent = $lastIntent; $inherited[] = 'intent';
                $turnType = 'context_modify';
                $coverageHit = true;
            }
            // «y los que no se enviaron/llegaron/fallaron» — subconjunto
            // fallido del tema de mensajería activo → failed_messages
            elseif (preg_match('/^(y |pero )?(los|las|de esos|esos|y de esos)?\s*(que )?no (se )?(enviaron|llegaron|salieron|fallaron|entregaron|atendieron|respondieron|leyeron|vieron|abrieron)[.!? ]*$/u', $q0)
                && in_array($lastIntent, ['notifications_unread','failed_messages','guardian_replies','whatsapp_status'], true)) {
                $intent = 'failed_messages';
                $turnType = 'context_modify';
                $coverageHit = true;
            }
            // «y los que no vinieron/llegaron/faltaron» — subconjunto de
            // asistencia: mismos eventos con filtro de estado inverso
            elseif (preg_match('/^(y |pero )?(los|las|de esos|esos|y de esos)?\s*(que )?no (vinieron|llegaron|asistieron|entraron|faltaron|se presentaron|aparecieron|vino|llego|falto)[.!? ]*$/u', $q0)
                && in_array($lastIntent, ['attendance_today','count_present','list_events','count_events','day_summary','late_today','top_offenders','students_in_group','notifications_unread'], true)) {
                $intent = 'list_events';
                $slots['module'] = $slots['module'] ?? 'INASISTENCIA';
                $turnType = 'context_modify';
                $coverageHit = true;
            }
            // «qué pasó con él/ese» — ficha del referente activo, no audit
            elseif (preg_match('/\b(que paso|como va|como esta|como le fue|que tiene)\s+(el|ella|ese|esa|este|esta|el estudiante|ese estudiante|con el|con ella)\b/u', $q0)
                || preg_match('/^(y )?(que paso|como va|como esta|como le fue|que tiene)\b[.!? ]*$/u', $q0)) {
                if (!empty($ctxEntities['student'])) {
                    if (empty($slots['student'])) { $slots['student'] = $ctxEntities['student']; $inherited[] = 'student'; }
                    $intent = 'student_summary';
                    $turnType = 'context_modify';
                } elseif (!empty($ctxEntities['group']) && in_array($intent, ['out_of_scope','audit_query','foreign_culture'], true)) {
                    $slots['group'] = $ctxEntities['group']; $inherited[] = 'group';
                    $intent = 'group_summary';
                    $turnType = 'context_modify';
                }
            }
            // «la razón / el motivo / el porqué / la causa» — deíctico de
            // causa: con riesgo activo o estudiante en contexto, es la
            // explicación del riesgo del sujeto, no una lista nueva
            elseif (preg_match('/^(y |ahora |pero |entonces |y )?(cual es |cual fue |cuales son |dime |dame |me dices |me dice )?(el |la |los |las )?(razon|razones|motivo|motivos|causa|causas|porque|por que|detonante|el detonante)\b[.!? ]*$/u', $q0)
                || preg_match('/^(y |pero |entonces )?por ?que\b[.!? ]*$/u', $q0)) {
                if (in_array($lastIntent, ['risk_students','risk_reason','alert_resolution'], true)
                    || !empty($ctxEntities['student'])) {
                    if (empty($slots['student']) && !empty($ctxEntities['student'])) { $slots['student'] = $ctxEntities['student']; $inherited[] = 'student'; }
                    $intent = 'risk_reason';
                    $turnType = 'context_modify';
                    $coverageHit = true;
                }
            }
            // «esos inasistentes / de esos / de ellos» + filtro nuevo —
            // subconjunto del set activo: hereda tema, aplica la condición
            elseif (preg_match('/\b(de esos|de esas|de ellos|de ellas|esos|esas|estos|estas|de ese|de esa)\s+(inasistentes|estudiantes|alumnos|pelados|muchachos|que faltaron|ausentes|casos|del grupo|del listado|del conjunto|anteriores|mencionados)\b|\b(de|alguno de|alguna de|ninguno de|cuales de)\s+(esos|esas|ellos|ellas)\b/u', $q0)
                && in_array($intent, ['out_of_scope','clarify','confused','repeat','deictic'], true)
                && !empty($dsState['last_result']['items'])) {
                $intent = $lastIntent;
                $inherited[] = 'intent';
                $turnType = 'context_modify';
                $coverageHit = true;
                // la condición nueva del turno (excusa, grupo, nombre) ya
                // está en $slots — el handler aplica el filtro sobre el set
            }
        }
        // «y las de hoy / y las del mes» tras RUIDO de operación — el tema
        // consultable no es lastIntent (quedó repeat_op/confirm_op/…), es
        // el dominio del _op pendiente: «un permiso para sofia» + «y las de
        // hoy» = «permisos de hoy». Sin _op ni dominio, sigue siendo oos.
        if (!$coverageHit && !$inheritable && !isset($slots['_nav'])
            && ($temporalFrag
                || preg_match('/^(y |pero )?(las |los |la |el )?de (hoy|ayer|manana|la semana|el mes|esta semana|este mes|la semana pasada|el mes pasado)\b[.!? ]*$/u', $q0))) {
            $revOp = ['Generar permiso'=>'permissions','Citar acudiente'=>'citations',
                      'Solicitar seguimiento'=>'trackings'];
            $opIntent = $revOp[$ctxEntities['_op'] ?? ''] ?? null;
            if ($opIntent) {
                $intent = $opIntent;
                $turnType = 'context_modify';
                $coverageHit = true;
            }
        }
        // «y en todo el colegio / en general / en total» — misma consulta sin
        // el filtro de grupo/estudiante del turno anterior
        if ($scopeWiden && $inheritable && !$coverageHit && !$allSetExact) {
            $widen = ['group_student_count'=>'students_count','students_in_group'=>'students_count',
                      'group_summary'=>'day_summary'];
            $intent = $widen[$lastIntent] ?? $lastIntent;
            unset($slots['group'], $slots['student'], $slots['_ref'], $slots['person']);
            $inherited = array_values(array_diff($inherited, ['group','student']));
            $slots['_scope_all'] = true;
            $inherited[] = 'intent';
            $turnType = 'context_modify';
            $coverageHit = true;
        }
        // «compáralas con las de hoy», «comparado con la semana pasada»,
        // «¿qué día tuvo más?» — la métrica del turno anterior en DOS rangos
        if ($inheritable && !$coverageHit
            && in_array($lastIntent, ['count_events','list_events','frequency_table','late_today','attendance_today','top_offenders','permissions','citations','trackings','count_trackings','pending_returns','sos_alerts','guardian_replies','notifications_unread','biometric_spam'], true)
            && (preg_match('/\b(compar\w*|vs|versus|frente a|respecto a|respecto al|contra)\b/u', $q0)
                || preg_match('/\bque dia (tuvo|hubo|fue|tiene|hay)\b|\bcual (dia|fue el dia) (tuvo|hubo)\b/u', $q0))
            && !preg_match('/\b(grupos?|cursos?)\b|\b\d{1,2}\s*-?\s*[a-d]\s+(y|con|vs)\s+\d/u', $q0)) {
            $ranges = nxTimeRanges($q0);
            $ctxR = (!empty($ctxEntities['from']) && !empty($ctxEntities['to']))
                ? ['from'=>$ctxEntities['from'],'to'=>$ctxEntities['to'],
                   'range_label'=>$ctxEntities['range_label'] ?? "{$ctxEntities['from']} a {$ctxEntities['to']}",
                   'days'=>$ctxEntities['days'] ?? null] : null;
            $cmp = null;
            if (count($ranges) >= 2) $cmp = array_slice($ranges, 0, 4);
            elseif (count($ranges) === 1 && $ctxR && ($ctxR['from'] . $ctxR['to']) !== ($ranges[0]['from'] . $ranges[0]['to']))
                $cmp = [$ctxR, $ranges[0]];
            if ($cmp) $slots['_compare_ranges'] = $cmp;
            else $slots['trend'] = true;
            foreach (['module','student','group'] as $k)
                if (empty($slots[$k]) && !empty($ctxEntities[$k])) { $slots[$k] = $ctxEntities[$k]; $inherited[] = $k; }
            if (!$cmp && $ctxR) foreach (['from','to','range_label','days'] as $k)
                if (isset($ctxR[$k])) $slots[$k] = $ctxR[$k];
            $intent = 'count_events'; $inherited[] = 'intent';
            $turnType = 'context_modify';
            $coverageHit = true;
        }
        // «¿alguno asistió o todos faltaron?», «¿cuántos vinieron?» con grupo
        // activo — asistencia DEL grupo (presentes vs ausentes), no la lista
        // institucional de inasistencias
        if (!$coverageHit
            && preg_match('/\b(alguno|algun estudiante|alguien|nadie|ninguno|todos|todas|cuantos|cuantas)\b/u', $q0)
            && preg_match('/\b(asisti\w*|vino|vinieron|llego|llegaron|entro|entraron|ingres\w*|falt\w*|inasisti\w*|presentes?|ausentes?)\b/u', $q0)
            && !preg_match('/\b(colegio|institucion|plantel|todos los grupos|en general|en total)\b/u', $q0)
            // el grupo es del texto, o el turno es anafórico sobre el grupo activo
            && (!empty($slots['group'])
                || (!empty($ctxEntities['group'])
                    && ($followupMark || preg_match('/\b(alguno|alguien|nadie|ninguno|todos|todas)\b/u', $q0))))
            && empty($slots['student'])
            && in_array($intent, ['out_of_scope','list_events','count_events','attendance_today','count_present','late_today','students_in_group','group_summary','smalltalk','deictic'], true)) {
            if (empty($slots['group'])) { $slots['group'] = $ctxEntities['group']; $inherited[] = 'group'; }
            if (empty($slots['from']) && !empty($ctxEntities['from'])) {
                foreach (['from','to','range_label','days'] as $k)
                    if (isset($ctxEntities[$k])) $slots[$k] = $ctxEntities[$k];
            }
            $slots['_attendance_q'] = true;
            $intent = 'group_summary';
            $turnType = 'context_modify';
            $coverageHit = true;
        }
        // «cuántos son en total» con grupo activo → conteo DEL grupo
        // (el inherit genérico ya pudo llenar slots.group — igual aplica).
        // Una pregunta COMPLETA por el total («¿cuántos estudiantes hay
        // matriculados?») es institucional aunque el turno previo fuera de
        // un grupo — solo el conteo desnudo/anafórico hereda el grupo.
        $bareCount = (bool)preg_match('/^(y |y en |pero )?(cuantos|cuantas)( son| hay| eran| tiene| tienen)?( en total)?[?. ]*$/u', $q0)
            || ($followupMark && !preg_match('/\b(estudiantes|alumnos|matriculad)/u', $q0));
        if ($intent === 'students_count' && empty($slots['group'])
            && !$bareCount && !empty($ctxEntities['group'])) {
            unset($slots['group']);
            $inherited = array_values(array_diff($inherited, ['group']));
        }
        if ($intent === 'students_count'
            && !preg_match('/\b(en el colegio|del colegio|de la institucion|de todo el plantel|en total del colegio|matriculados en total|todo el colegio|toda la institucion)\b/u', $q0)
            && (!empty($slots['group']) || ($bareCount && !empty($ctxEntities['group'])))) {
            if (empty($slots['group'])) { $slots['group'] = $ctxEntities['group']; $inherited[] = 'group'; }
            $intent = 'group_student_count';
            $turnType = 'context_modify';
        }
        // «¿y cuántos son en total?» — conteo desnudo sobre el set activo.
        // Sin sustantivo el parser cae a oos/list_events; el universo
        // lo define el contexto: nómina → conteo del grupo, módulo/eventos
        // → count_events del rango
        if (in_array($intent, ['out_of_scope','list_events','count_events'], true)
            && preg_match('/\b(cuantos|cuantas)\s+(\w+\s+){0,3}?(son|hay|en total|somos|en el grupo|quedan)\b/u', $q0)
            && !preg_match('/\b(en el colegio|del colegio|de la institucion|matriculados en total)\b/u', $q0)
            && (!empty($slots['group']) || !empty($ctxEntities['group']))) {
            $studentsSet = (($dsState['last_result']['type'] ?? null) === 'students')
                || in_array($lastIntent, ['students_in_group','group_student_count','students_count','group_summary'], true)
                // «los pelados/muchachos de 6A cuántos son» — el sustantivo
                // persona del turno define el dominio sin set previo
                || (preg_match('/\b(estudiantes|alumnos|alumnas|pelados|peladas|chicos|chicas|ninos|ninas|niños|niñas|muchachos|muchachas|menores|jovenes|pelaos)\b/u', $q0)
                    && !empty($slots['group']));
            if (empty($slots['group'])) { $slots['group'] = $ctxEntities['group']; $inherited[] = 'group'; }
            if ($studentsSet && empty($slots['module']) && empty($ctxEntities['module'])) {
                $intent = 'group_student_count';
            } elseif (!$studentsSet || !empty($slots['module']) || !empty($ctxEntities['module'])) {
                $intent = 'count_events';
            }
            if ($intent === 'group_student_count' || $intent === 'count_events') {
                $turnType = 'context_modify';
                // el conteo contextual es una resolución — la herencia de
                // bajo-confianza y la modificación genérica no deben pisarlo
                $coverageHit = true;
            }
        }
        // «el primero / el segundo» desnudo tras un conteo/lista — sin
        // set activo es POSICIÓN sobre la colección del contexto (grupo
        // y/o módulo heredados; el planner materializa el fetch)
        if (!isset($slots['_nav']) && empty($slots['position']) && empty($slots['student'])
            && preg_match('/^(?:y\s+)?(?:el|la|los|las|dame|muestra(?:me)?|cual es|quien es)\s*(primer[oa]|segund[oa]|tercer[oa]|cuart[oa]|quint[oa]|últim[oa]|ultim[oa])[.!? ]*$/u', $q0, $mo)
            && (empty($dsState['last_result']['items']))) {
            $ordMap = ['primero'=>1,'primera'=>1,'segundo'=>2,'segunda'=>2,'tercero'=>3,'tercera'=>3,
                       'cuarto'=>4,'cuarta'=>4,'quinto'=>5,'quinta'=>5,'último'=>'last','ultimo'=>'last',
                       'última'=>'last','ultima'=>'last'];
            $p = $ordMap[mb_strtolower($mo[1])] ?? null;
            if ($p !== null && (!empty($ctxEntities['group']) || !empty($ctxEntities['module']))) {
                $slots['position'] = $p;
                if (empty($slots['group']) && !empty($ctxEntities['group'])) {
                    $slots['group'] = $ctxEntities['group']; $inherited[] = 'group'; }
                if (empty($slots['module']) && !empty($ctxEntities['module'])) {
                    $slots['module'] = $ctxEntities['module']; $inherited[] = 'module'; }
                $turnType = 'context_modify';
            }
        }
        // «ahí / allí / en ese grupo» — el deíctico espacial ancla el
        // grupo activo aunque el turno no traiga «y»
        if (preg_match('/\b(ahi|alli|en ese|en esa|del grupo|de ese grupo|del mismo|en el grupo)\b/u', $q0)
            && !empty($ctxEntities['group']) && empty($slots['group'])) {
            $slots['group'] = $ctxEntities['group']; $inherited[] = 'group';
            $turnType = 'context_modify';
        }
        // «y en el <grupo> / y del <grupo>» — misma consulta sobre otro grupo
        if (preg_match('/^(y |pero |ahora |entonces )?(en |del |de la |de |sobre )?(el |la )?[\w. -]{0,12}$/u', $q0)
            && !empty($slots['group']) && $inheritable
            && in_array($intent, ['math_operation','out_of_scope','random_number','deictic','yes','smalltalk','foreign_culture'], true)
            && in_array($lastIntent, ['group_summary','group_student_count','students_in_group','list_events','count_events','count_present','attendance_today','late_today','day_summary'], true)) {
            $intent = $lastIntent; $inherited[] = 'intent';
            $turnType = 'context_modify';
        }
        // «y las/los <módulo>» — la MISMA consulta con otro módulo:
        // hereda intent, estudiante y rango; solo cambia el módulo
        if (preg_match('/^(y |y las |y los |y sus |y su |las |los |sus |tambien |ahora )?(tardanzas?|llegadas? tardes?|inasistencias?|faltas?|ausencias?|evasiones?|fugas?|permisos?|citaciones?|citas?|eventos?|incidentes?|alertas?|seguimientos?)\b[.!? ]*$/u', $q0, $mm)
            && $inheritable
            && in_array($intent, ['sos_alerts','out_of_scope','random_number','random_student','math_operation','deictic','yes','smalltalk','foreign_culture','count_events','list_events','attendance_today','late_today','trackings','permissions','citations'], true)) {
            $w3 = $mm[count($mm)-1];
            $mod = preg_match('/tardanza|llegada/', $w3) ? 'LATE_ARRIVAL'
                 : (preg_match('/inasist|falt|ausen/', $w3) ? 'INASISTENCIA'
                 : (preg_match('/evasion|fuga/', $w3) ? 'EVASION_INTERNA'
                 : (preg_match('/permiso/', $w3) ? 'PERMISO'
                 : (preg_match('/citacion|cita/', $w3) ? 'CITACION' : null))));
            $slots['module'] = $mod; $inherited[] = 'module';
            if (empty($slots['student']) && !empty($ctxEntities['student'])) {
                $slots['student'] = $ctxEntities['student']; $inherited[] = 'student';
            }
            if (empty($slots['group']) && !empty($ctxEntities['group'])) {
                $slots['group'] = $ctxEntities['group']; $inherited[] = 'group';
            }
            // el rango completo se hereda — «y llegadas tarde?» tras «últimos
            // 15 días» NO debe reiniciar a «hoy»
            if (($slots['days'] ?? null) === null && isset($ctxEntities['days'])) {
                $slots['days'] = $ctxEntities['days']; $inherited[] = 'days';
            }
            foreach (['from','to','range_label'] as $rk)
                if (empty($slots[$rk]) && !empty($ctxEntities[$rk])) {
                    $slots[$rk] = $ctxEntities[$rk]; $inherited[] = $rk;
                }
            $intent = $lastIntent; $inherited[] = 'intent';
            $turnType = 'context_modify';
        }
        // «las/los de <estudiante>» — la consulta anterior (conteo/lista)
        // sobre OTRO sujeto: entidad cambia, objetivo se conserva
        if (preg_match('/^(y |pero |y )?(las|los|esas|esos|las mismas|los mismos|de|del)?\s*de\s+(.+)$/u', $q0)
            && !empty($slots['student']) && $inheritable
            && in_array($intent, ['random_student','out_of_scope','student_field','student_summary','math_operation'], true)
            && in_array($lastIntent, ['count_events','list_events','attendance_today','late_today','top_offenders','trackings','permissions','citations'], true)) {
            $intent = $lastIntent; $inherited[] = 'intent';
            if (empty($slots['module']) && !empty($ctxEntities['module'])) {
                $slots['module'] = $ctxEntities['module']; $inherited[] = 'module';
            }
            if (($slots['days'] ?? null) === null && isset($ctxEntities['days'])) {
                $slots['days'] = $ctxEntities['days']; $inherited[] = 'days';
            }
            $turnType = 'context_modify';
        }
        // «el de <estudiante>» — continuar el campo pedido sobre otro sujeto
        if (preg_match('/^(y )?(el|la|eso|esa|ese|lo mismo|igual)\s+de\s+(.+)$/u', $q0)
            && !empty($slots['student']) && !empty($ctxEntities['field'])
            && in_array($intent, ['random_student','student_field','out_of_scope','student_summary'], true)) {
            $slots['field'] = $ctxEntities['field']; $inherited[] = 'field';
            $intent = 'student_field';
            $turnType = 'context_modify';
        }
        // «vuelve/regreso a …» — retorno deíctico al tema u operación anterior
        if (preg_match('/\b(vuelve|volver|regreso|regresa|retorna|volvemos|atras|devuelve|devuelvete)\b/u', $q0)) {
            // «vuelve al mes» = restaurar el rango ANTERIOR (swap con
            // prev_days) — la referencia a rango domina sobre el _op
            // pendiente («hoy» tras «mes pasado» conserva el 60 como prev)
            if (preg_match('/\b(al|a la|a el|a los|a las|a ese|a esa|a aquel|a aquella)\s+(mes|semana|rango|periodo|fecha|ano|año)\b/u', $q0)
                && !preg_match('/\b(hoy|ayer|anteayer|ahora mismo|recien|ultimo dia|ultimos|\d+)\b/u', $q0)
                && array_key_exists('days', $ctxEntities) && $ctxEntities['days'] !== null) {
                $prev = $ctxEntities['prev_days'] ?? null;
                $slots['days'] = $prev !== null ? $prev : $ctxEntities['days'];
                $slots['prev_days'] = $ctxEntities['days'];
                $slots['from'] = $ctxEntities['from'] ?? null;
                $slots['to'] = $ctxEntities['to'] ?? null;
                $slots['range_label'] = $ctxEntities['range_label'] ?? null;
                $inherited[] = 'days';
                if ($inheritable && $intent !== $lastIntent
                    && ($intent === 'out_of_scope' || $conf < NX_NLU_THRESHOLD
                        || in_array($intent, NX_GENERIC_INTENTS, true))) {
                    $intent = $lastIntent; $inherited[] = 'intent';
                }
                $turnType = 'context_modify';
            }
            // «vuelve a la solicitud» — retorno a la operación pendiente
            elseif (!empty($ctxEntities['_op'])) { $turnType = 'confirmation'; }
            elseif ($inheritable && $intent !== $lastIntent
                && ($intent === 'out_of_scope' || $conf < NX_NLU_THRESHOLD
                    || in_array($intent, NX_GENERIC_INTENTS, true))) {
                $intent = $lastIntent; $inherited[] = 'intent';
                $turnType = 'context_modify';
            }
        }

        // ── 2a′. Sustantivo de módulo → intent dedicado ──────────────────
        // «permisos que ha tenido X», «las citaciones del mes», «los
        // seguimientos activos» — con el parser caído quedan out_of_scope
        // aunque el sustantivo ya define la consulta. El bloque opVerb
        // posterior sigue pudiendo rerutar a derive_action («genera un
        // permiso»), por eso este rescate no se aplica si hay verbo de op.
        if (in_array($intent, ['out_of_scope','smalltalk','foreign_culture','deictic','yes','math_operation','random_student'], true)
            && !empty($slots['module'])
            && !preg_match('/\b(genera\w*|crea\w*|citar|cita\b|citas\b|cite|citemos|citalo|citala|citamos|convoca\w*|autoriz(?:a|o|e|en|emos|ar|aria|arian|aba|aban|aste|aron|an|ando|ame)\b|derivar|deriva\b|derivo\b|derive\b|deriven\b|reporta\w*|registra\w*|emite\w*|tramita\w*|haz|hacer|mandar|manda|envia\w*|expide\w*|expedir|llamar|llamado|exporta\w*|descarga\w*|saca\w*)\b/u', $q0)) {
            $modIntent = ['PERMISO'=>'permissions','CITACION'=>'citations',
                'SEGUIMIENTO'=>'trackings','SOS'=>'sos_alerts',
                'INASISTENCIA'=>'list_events','LATE_ARRIVAL'=>'list_events',
                'EVASION_INTERNA'=>'list_events','INCIDENTE'=>'list_events',
                'SALIDA_ANTICIPADA'=>'list_events','SALIDA_PEDAGOGICA'=>'list_events'];
            if (isset($modIntent[$slots['module']])) {
                $intent = $modIntent[$slots['module']];
                // «cuántas tardanzas» es conteo, no listado
                if ($intent === 'list_events'
                    && preg_match('/\b(cuantos|cuantas|cuanto|numero de|total de|cantidad de)\b/u', $q0))
                    $intent = 'count_events';
                $turnType = 'context_modify';
            }
            // «compara/ranking/cantidad y aumento … por grupo(s)» — la
            // comparación entre grupos es attendance_ranking, no una lista
            if ($intent === 'list_events'
                && preg_match('/\b(compara\w*|comparativo|ranking|top|orden\w*|cantidad|aumento|vs\.?|versus|entre)\b/u', $q0)
                && preg_match('/\b(grupos?|cursos?|salones?|grados?)\b/u', $q0)) {
                $intent = 'attendance_ranking';
                $slots['group_by'] = 'group';
                if (preg_match('/\b(aumento|subi|baj|increment|comparad|cantidad)\b/u', $q0))
                    $slots['trend'] = true;
                if (preg_match('/\b(decim\w*|grado\s*10)\b/u', $q0)) $slots['grade'] = '10';
                $turnType = 'context_modify';
            }
        }
        // «¿alguno tiene excusa? / ¿cuáles están justificadas?» — filtro de
        // excusa sobre la consulta activa; hereda intent+rango y fija
        // justified según la polaridad («con excusa» vs «sin justificar»)
        if (in_array($intent, ['out_of_scope','smalltalk','deictic','yes','random_student','list_events','incidents.list','count_events',
                'student_field','students.list','incidents.count','attendance_today','late_today','students_in_group','top_offenders'], true)
            && $inheritable
            && preg_match('/\b(excusa\w*|justificad\w*|justificacion\w*|justificaron|soporte)\b/u', $q0)) {
            $slots['justified'] = (!empty($slots['_unjustified'])
                || preg_match('/\b(sin excusa|sin justificar|injustificad|no justificad|ningun\w*\s+excusa|no tienen excusa|sin una excusa|no han justificado)\b/u', $q0))
                ? 'no' : 'yes';
            // el filtro va sobre la métrica activa: módulo/rango/grupo del tema
            foreach (['module','group','student','from','to','range_label','days'] as $k)
                if (empty($slots[$k]) && isset($ctxEntities[$k]) && $ctxEntities[$k] !== null && $ctxEntities[$k] !== '') {
                    $slots[$k] = $ctxEntities[$k]; $inherited[] = $k;
                }
            if (empty($slots['module'])) $slots['module'] = 'INASISTENCIA';
            $wantCount = (bool)preg_match('/\b(cuant[oa]s)\b/u', $q0);
            $intent = $wantCount ? 'count_events' : 'list_events';
            $inherited[] = 'intent';
            $turnType = 'context_modify';
            $coverageHit = true;
        }
        // «los grupos que tengo a mi cargo / mis grupos» — alcance del
        // docente, nunca un estudiante. Gana sobre la herencia de tema:
        // pedir los grupos propios es sujeto nuevo explícito aunque el
        // turno anterior fuese una lista de incidentes.
        if (!empty($slots['_my_scope'])
            && preg_match('/\b(grupos?|cursos?|salones?)\b/u', $q0)
            && !preg_match('/\b(tardanza|llegada|inasist|falt|ausen|evasion|fuga|permiso|citacion|incidente|evento|alerta|seguim|sos\b)/u', $q0)
            // «llegaron tarde / entran tarde» — evento, no jornada de tarde
            && !preg_match('/\b(lleg\w+|entr\w+|vin\w+|asisti\w+|marca\w+)\s+tarde\b|\btarde\s+(hoy|ayer|esta semana|el lunes|esta manana|en la manana|en punto)\b|\bimpuntual\w*\b/u', $q0)
            && in_array($intent, ['out_of_scope','smalltalk','deictic','student_field','students_in_group','group_summary','random_student','list_events','incidents.list','count_events','attendance_today','late_today','students.list'], true)) {
            $intent = 'groups_list'; $turnType = 'context_modify';
        }

        // ── 2b. Posesivos / pronombres → entidad del contexto ────────────
        // «su ficha», «sus datos», «para ella», «el teléfono de su papá»
        if (empty($slots['student']) && !empty($ctxEntities['student'])
            && (preg_match('/\bsu[s]?\s+\w*\s*(ficha|documento|telefono|celular|datos|direccion|papa|mama|acudiente|padre|madre|familiar|info|contacto|correo|caso|historial|permiso|permisos|inasistencia|falta|faltas|evasion|evasiones|tardanza|tardanzas|seguimiento|citacion|resumen|perfil|grupo|salon)\b/u', $q0)
                || preg_match('/\b(para|de|del|a|con|sobre)\s+(el|ella|ello|ese|esa|aquella?)\b/u', $q0))) {
            $slots['student'] = $ctxEntities['student']; $inherited[] = 'student';
        }

        // ── 2c. Repetición de operación pendiente ─────────────────────────
        // «otro para camila», «uno mas para pedro», «genera uno nuevo» —
        // mismo comando, parámetros nuevos. Sin _op explícito, se infiere
        // del tema consultado (permisos→Generar permiso, citas→Citar…).
        // Excepción: un mensaje puramente temporal+repeat («otro dia») es
        // modificación de rango, no de operación.
        $opByIntent = ['permissions'=>'Generar permiso','pending_returns'=>'Generar permiso',
            'citations'=>'Citar acudiente','trackings'=>'Solicitar seguimiento'];
        $pendingGuess = $ctxEntities['_op'] ?? ($opByIntent[$lastIntent] ?? null);
        $repeatMark = (bool)(preg_match('/\b(otro|otra|uno mas|una mas|otro mas|nuevo|nueva|igual|de nuevo)\b/u', $q0)
            || preg_match('/\b(uno?|una)\s+(para|de|del)\s*[a-z0-9]+/u', $q0));
        $pureTemporal = (bool)(preg_match('/^(otro|otra|de|del|para|a|el|la|los|las|hoy|ayer|manana|dia|dias|semana|mes|fecha|tarde|temprano|esta|este|estos|estas|proximo|proxima|siguiente|anterior|pasado|pasada|y|ahora|\s|\d)+$/u', $q0)
            && !preg_match('/\b(para|de|del|a)\s+\d*[a-z]+/u', $q0));
        if (!empty($pendingGuess) && $repeatMark && !$pureTemporal
            && (preg_match('/\b(para|de|del|a)\s+[a-z0-9]+/u', $q0)
                || $hasNewEntity
                || preg_match('/\b(uno?|una)\b/u', $q0))) {
            $intent = 'repeat_op';
            $turnType = 'op_repeat';
            $slots['_op'] = $pendingGuess;
        }
        // «el mismo / lo mismo» desnudo tras operación pendiente = «repetir
        // la misma operación» («convoca al acudiente de 7b» → «el mismo»).
        // Con consulta heredable el switch contextual ya lo resolvió antes.
        if ($intent !== 'repeat_op' && !empty($pendingGuess)
            && preg_match('/^(y |pero )?(el |la |lo |los |las )?mism[oa]s?[.!? ]*$/u', $q0)) {
            $intent = 'repeat_op';
            $turnType = 'op_repeat';
            $slots['_op'] = $pendingGuess;
        }
        // «a un docente / al coordinador / para el otro» desnudo — destinatario
        // del comando pendiente: misma op con sujeto/destino nuevo
        if ($intent !== 'repeat_op' && !empty($pendingGuess)
            && preg_match('/^(y |pero |ahora |mejor )?(a|al|para|con|hacia)\s+(un|una|el|la|los|las|otro|otra|nuevo|nueva|este|esta|ese|esa|tambien|mismo|misma)\s+\w+[.!? ]*$/u', $q0)
            && !preg_match('/\b(cuant|donde|cuando|hora|fecha|grupo|salon|lista|quien)\b/u', $q0)) {
            $intent = 'repeat_op';
            $turnType = 'op_repeat';
            $slots['_op'] = $pendingGuess;
        }

        // ── 2d. Verbo de operación explícito domina sobre intent-consulta ─
        // «citala a citación», «ahora cita al acudiente», «genera uno» —
        // el NLU puede clasificar por el sustantivo; un verbo de acción
        // real rerutea a la operación. «borra/elimina» NO crea operaciones.
        // Regla de preservación: un turno de operación SIN sustantivo/verbo
        // de operación («no, para María») NO recalcula el comando — conserva
        // el _op pendiente en lugar de caer al default.
        $opVerb = (bool)preg_match('/\b(citar|cita|citas|cite|citemos|citan|citalo|citala|citamos|convocar|convoca|'
            . 'generar|genera|autorizar|autoriza|mandar|manda|enviar|envia|'
            . 'reportar|reporta|registrar|registra|crear|crea|expedir|expide|'
            . 'derivar|deriva|tramitar|tramita|constancia|dejar constancia|llamar a citacion|llamado a|convoco|convoca|emitir|emite|dar salida|da salida)\b/u', $q0);
        $opPhrase = $opVerb && nxOpPhrase($q0);
        // exportar/descargar/sacar NO son verbos de operación — van a
        // export_data (reporte con formato), nunca a un formulario
        if (preg_match('/\b(exportar|exporta|exporte|exportame|expórtame|descargar|descarga|descargue|descárgame|extraer|extrae|saca\w*|sacar|pasa\w* a (excel|pdf|word|csv)|en (excel|pdf|word))\b/u', $q0)
            && in_array($intent, ['derive_action','start_operation','out_of_scope','list_events','count_events','students.list','incidents.list'], true)) {
            $intent = 'export_data';
            $turnType = 'intent_switch';
        }
        // «no, mejor una citación» — corrección DE operación: cambia el
        // comando, conserva la entidad y el flujo
        if ($correctionWeak && preg_match('/\b(solicitud|citacion|cita|permiso|autorizacion|salida|seguimiento|incidente|reporte)\b/u', $q0)
            && !empty($ctxEntities['_op'])) {
            $intent = 'derive_action';
            $turnType = 'correction';
        } elseif (in_array($intent, ['start_operation','derive_action'], true)
            && !$opVerb && !preg_match('/\b(solicitud|citacion|permiso|autorizacion|seguimiento|incidente|salida|reporte|registro|emergencia|sos|paseo|horario|bloque|dano|manual)\b/u', $q0)
            && !empty($ctxEntities['_op'])) {
            $slots['_op'] = $ctxEntities['_op'];
        }
        if ($opPhrase && in_array($intent, ['out_of_scope','permissions','citations',
                'trackings','list_events','pending_returns',
                'sos_alerts','student_field','student_summary','day_summary',
                'schedule_info','notifications_unread','group_summary'], true)) {
            $intent = 'derive_action';
            $turnType = 'intent_switch';
        }
        // sustantivo de operación en forma de solicitud («una autorización
        // de salida», «un permiso médico») — sin marcadores de consulta
        if (!$opVerb
            && preg_match('/\b(una|un|la|hay un|hay una)\s+(autorizacion|permiso|citacion|solicitud|excusa|incidente|reporte|emergencia)\b/u', $q0)
            && !preg_match('/\b(cuant|ver|muestra|dame|list|cuales|vigent|activ|pendient|anterior|esta semana|del mes|de hoy|ayer|expedid|emitid|vencid|los|las|quienes|todos|varios|estan|hay\s+(permisos|autorizaciones|citaciones|solicitudes|excusas)\b)\b/u', $q0)
            && in_array($intent, ['out_of_scope','permissions','citations','security_probe',
                'sos_alerts','devices_status','day_summary','schedule_info',
                'student_field','student_summary','notifications_unread',
                'list_events','count_events'], true)) {
            $intent = 'derive_action';
            $turnType = 'intent_switch';
        }
        // operación sobre referente de rol («que venga el papá del
        // estudiante», «hacer venir al responsable del niño») sin nombre
        // propio → la entidad activa del contexto es el objetivo del chip
        if (in_array($intent, ['derive_action','start_operation'], true)
            && empty($slots['student']) && !empty($ctxEntities['student'])
            && preg_match('/\b(acudiente|acudientes|tutor|responsable|representante|papa|mama|padre|madre|familiar|encargad[oa]|estudiante|alumn[oa]|niñ[oa]|muchach[oa]|pelad[oa]|menor)\b/u', $q0)) {
            $slots['student'] = $ctxEntities['student']; $inherited[] = 'student';
        }
        // verbo destructivo + datos → security_probe (no existe operación
        // de borrado — el rechazo explícito es el comportamiento correcto).
        // Clítico pegado («bórralo», «elimínala») ya trae el objeto en el verbo.
        preg_match('/\b(borra|borrar|borre|elimina|eliminar|elimine|vacia|'
            . 'anula|anular|suprime|suprimir|destruye|destruir|limpia)(la|lo|las|los|me)?\b/u', $q0, $dm);
        if (!empty($dm[0])
            && (!empty($dm[2])
                || preg_match('/\b(las|los|la|el|datos|registros|tabla|faltas|evasiones|'
                    . 'tardanzas|estudiantes|permisos|citaciones|todo|mensajes|notificaciones|base)\b/u', $q0))
            && in_array($intent, ['list_events','count_events','attendance_today','late_today',
                'permissions','citations','trackings','out_of_scope','notifications_unread',
                'export_data','pending_returns','day_summary','student_field','student_summary'], true)) {
            $intent = 'security_probe';
            $turnType = 'intent_switch';
        }

        // ── 3. Intent heredable bajo umbral con marcador (regla etapa-0) ──
        // Guardia: un sustantivo de operación con verbo/artículo («una
        // solicitud aparte», «el permiso») NO se degrada a la consulta previa.
        $hasOpNoun = (bool)preg_match('/\b(una?|el|la|esa|ese|otra?|hacer|haz|mandar|enviar|generar|crear|quiero|necesito)\s+\w*\s*(solicitud|citacion|cita|permiso|autorizacion|salida|seguimiento|incidente|reporte|registro|excusa)\b/u', $q0);
        // la herencia no pisa un reroute de operación: «ahora quiero citar
        // a su acudiente» ya fue resuelto a derive_action por el verbo —
        // volver al lastIntent lo degradaría a consulta fantasma
        // propuesta del parser bajo umbral SIN tema heredable pero con soporte
        // estructural propio (módulo/estudiante/grupo) — consulta autónoma
        // que el modelo dudó; el slot-filling determinista la respalda
        if ($intent === 'out_of_scope' && $proposed && !$inheritable && !$coverageHit
            && in_array($proposed, NX_LLM_FORMAL, true)
            && (!empty($slots['module']) || !empty($slots['student']) || !empty($slots['group']))) {
            $intent = $proposed;
        }
        if (($intent === 'out_of_scope' || $conf < NX_NLU_THRESHOLD)
            && !in_array($intent, ['derive_action','start_operation','repeat_op','confirm_op','security_probe'], true)
            && $inheritable && $dependent && !$hasOpNoun && !$coverageHit) {
            $intent = $lastIntent;
            $inherited[] = 'intent';
            $turnType = 'context_modify';
        }
        // ── 4. Modificación contextual (genéricos) — «¿y las de hoy?» ──
        // Guardias: una resolución ya fijada ($coverageHit) queda exenta;
        // y un mensaje con cuantificador+métrica propia («y cuántas
        // tardanzas») NO es modificación deíctica — el intent propio es
        // el correcto.
        // «ahora las tardanzas» (sin cuantificador) SÍ modifica la cadena.
        $ownCount = (bool)(preg_match('/\b(cuant[oa]s?|que numero|cuanto)\b/u', $q0)
            && (preg_match('/\b(tardanza|inasist|falt|evasion|permiso|citacion|seguim|evento|incident|notif|salid|ingres|ausen|presente|estudiant|alumn)/u', $q0)
                // «y cuántos son en total» — conteo desnudo sobre el set
                // activo: también es consulta propia, no modificación deíctica
                || preg_match('/\b(son|hay|en total|quedan|eran|fueron|somos|en el grupo)\b/u', $q0)));
        if ($inheritable && $intent !== $lastIntent && !$coverageHit && !$ownCount
            && in_array($intent, NX_GENERIC_INTENTS, true)
            && $followupMark && !$explicitAction
            && str_word_count($q0, 0, 'áéíóúñü') <= 8) {
            $intent = $lastIntent;
            $inherited[] = 'intent_ctx_generic';
            $turnType = 'context_modify';
        }
        // prev_days: si el turno trae un rango distinto al del ctx, el valor
        // anterior queda recuperable por «vuelve al mes»
        if (isset($slots['days']) && array_key_exists('days', $ctxEntities)
            && $ctxEntities['days'] !== null && $slots['days'] !== $ctxEntities['days']) {
            $slots['prev_days'] = $ctxEntities['days'];
        } elseif (!isset($slots['prev_days']) && isset($ctxEntities['prev_days'])) {
            $slots['prev_days'] = $ctxEntities['prev_days'];
        }

        // ── 5. Cambio explícito de intención (D) — solo si aún no se
        // clasificó el turno (corrección/op_repeat/contexto ganan) ──
        if ($turnType === 'new_request' || $turnType === 'autonomous') {
            if ($explicitAction || (in_array($intent, ['start_operation','derive_action','repeat_op'], true) && $conf >= NX_NLU_THRESHOLD)) {
                $turnType = $intent === $lastIntent ? 'context_modify' : 'intent_switch';
            } elseif ($inheritable && $followupMark && empty($newSlots) && empty($inherited)
                && in_array($intent, ['out_of_scope','confused','repeat'], true)) {
                // ── 6. Deíctico sin información nueva (E) → aclarar, no adivinar ──
                $turnType = 'deictic';
                $clarify = '¿Sobre qué tema? Puedo mostrarte faltas, tardanzas, evasiones, permisos o seguimientos.';
            } elseif (!$followupMark && !$correctionMark) {
                $turnType = 'autonomous';
            }
        }
        if ($intent === 'repeat_op') $turnType = 'op_repeat';
        // ── 6a. «su grupo / de todo su grupo / su salón» — referencia al
    // grupo DEL ESTUDIANTE activo: no se inventa, se marca _ref y el
    // dispatcher la resuelve vía BD (student→group, determinista+RBAC)
    if (preg_match('/\b(su grupo|su salon|su curso|su seccion|todo su grupo|toda su seccion|el grupo de (el|ella)|los de su grupo|mismo grupo|esa clase|su clase)\b/u', $q0)
        && !empty($ctxEntities['student'] ?? $slots['student'] ?? null)) {
        $slots['_ref'] = 'group_of_student';
        if (empty($slots['student']) && !empty($ctxEntities['student'])) {
            $slots['student'] = $ctxEntities['student'];
            $inherited[] = 'student';
        }
        unset($slots['field']);
        if (!in_array($intent, ['group_summary','group_student_count','list_events','count_events',
                'attendance_today','late_today','top_offenders','trackings','groups_list'], true))
            $intent = 'group_summary';
        $turnType = 'context_modify';
    }
    // ── 6b. «¿y ahora?» / «vuelve atrás» sin tema heredable → aclarar ──
        if (preg_match('/^(y )?(ahora|ahora que|y ahora|y despues|y luego|vuelve atras|regresa|devuelvete|devuelve)\b[?!. ]*$/u', $q0)
            && !$inheritable) {
            $turnType = 'deictic';
            $clarify = '¿Ahora sobre qué tema? Puedo mostrar faltas, tardanzas, permisos, eventos o seguimientos.';
        }
        // ── 7. Pregunta cuantitativa sin métrica («¿cuántas hubo hoy?») →
        // aclarar, no adivinar — con contexto heredable aplica el intent previo;
        // sin contexto, debe preguntar la métrica (spec §8).
        if ($turnType === 'autonomous' && !$inheritable
            && empty($slots['student']) && empty($slots['group']) && empty($slots['module'])
            && preg_match('/^cuant(as|os)\b.{0,45}\b(hubo|hay|fueron|salieron|registraron|se marcaron|van)\b/u', $q0)
            && !preg_match('/tardanza|inasist|ausen|falt|evasion|permiso|citacion|cita|evento|incident|seguim|notif|salida|estudiant|mensaje|alerta|cumple|tarea|pendient|docent|acudient|correo|llamad|presente|asistiendo|vinieron|llegaron|entraron|matriculad|persona|colegio|institucion|plantel|asistieron/u', $q0)) {
            $turnType = 'deictic';
            $clarify = '¿Te refieres a tardanzas, inasistencias, evasiones, permisos o eventos en general?';
        }
    }
    // ── fuga de contexto: intents de CONJUNTO institucional no heredan
    // `student` — «quién está exento de biometría» tras la ficha de Tomás
    // pregunta por el conjunto escolar, no por Tomás (caso real: respondía
    // del alumno anterior). Solo se recorta cuando el intent es NUEVO
    // (resuelto por el clasificador): una continuación pura (intent también
    // heredado — «y sus notas») conserva al sujeto, y los intents de persona
    // (risk_reason, trackings…) tampoco se tocan.
    if (in_array($intent, ['student_consent','school_calendar','staff_contact',
            'teacher_schedule','device_detail','devices_status','sos_detail',
            'sos_alerts','reports_log','enrollment_stats','trip_info',
            'alert_resolution','notifications_unread','biometric_spam',
            'attendance_trend','day_summary','group_summary','about_me',
            'groups_list','staff_lookup','schedule_info','birthdays_today',
            'audit_query','my_activity','report','system_incidents',
            'whatsapp_status','failed_messages','guardian_replies',
            'risk_students','top_offenders','attendance_ranking',
            'students_count','group_student_count','students_in_group',
            'attendance_today','late_today','count_present','pending_returns',
            'frequency_table','citations_by','guardian_messages'], true)
        && in_array('student', $inherited, true)
        && !in_array('intent', $inherited, true)
        && !in_array('intent_ctx_generic', $inherited, true)) {
        unset($slots['student']);
        $inherited = array_values(array_diff($inherited, ['student']));
    }
    // eventos/contadores también son de conjunto cuando la frase trae un
    // marcador genérico («hay ALGÚN estudiante», «QUÉ estudiante», «alguien»)
    // — la herencia legítima («¿cuántas evasiones tiene?» tras la ficha)
    // no lleva marcador y se conserva
    if (in_array($intent, ['list_events','count_events'], true)
        && in_array('student', $inherited, true)
        && !in_array('intent', $inherited, true)
        && preg_match('/\b(alguien|algun\w*|quien\w*|que estudiante|estudiantes|alumnos|pelados|muchachos|ningun\w*|nadie|todo el|del colegio|institucion|en general)\b/u', $q0)) {
        unset($slots['student']);
        $inherited = array_values(array_diff($inherited, ['student']));
    }

    if ($confirmMark && $lastIntent) { $turnType = 'confirmation'; }
    elseif ($cancelMark) { $turnType = 'cancel'; }

    // ── ctx resultante ──
    $newCtx = $ctx;
    if (in_array($intent, NX_SMALLTALK_INTENTS, true) && $turnType !== 'op_repeat') {
        // smalltalk («gracias», «ok», «sí», «adiós») no cambia el tema —
        // conserva el ctx completo para que «el mismo grupo» siga resolviendo
        $newCtx = $ctx;
    } elseif (in_array($turnType, ['confirmation','cancel','op_repeat'], true)) {
        // confirm/cancel/repetición: el tema conversacional continúa — los
        // slots nuevos se fusionan sobre el ctx, no lo reemplazan («confirmo»
        // tras «tardanzas del mes» no borra el rango)
        $newCtx = [
            'last_intent' => $turnType === 'op_repeat' ? $intent : ($ctx['last_intent'] ?? $intent),
            'entities' => $ctx['entities'] ?? [],
            'ts' => time(),
        ];
        foreach (array_intersect_key($slots, array_flip(
                ['student','group','module','days','prev_days','from','to','range_label','field','_op']))
            as $k => $v) {
            if ($v !== null && $v !== '') $newCtx['entities'][$k] = $v;
        }
    } elseif ($intent !== 'out_of_scope') {
        $newCtx = [
            'last_intent' => $intent,
            'entities' => array_intersect_key($slots, array_flip(
                ['student','group','module','days','prev_days','from','to','range_label','field','_op'])),
            'ts' => time(),
        ];
    } else {
        // paridad front: aun con out_of_scope las entidades del mensaje se
        // conservan («camila del septimo» sin intent → «su documento» la
        // recupera). last_intent NO cambia — no hay tema activo.
        $ents = array_intersect_key($slots, array_flip(
            ['student','group','module','days','prev_days','from','to','range_label','field','_op']));
        if ($ents) {
            $newCtx = is_array($newCtx) ? $newCtx : [];
            $prev = is_array($newCtx['entities'] ?? null) ? $newCtx['entities'] : [];
            $newCtx['entities'] = array_merge($prev, $ents);
            $newCtx['ts'] = time();
        }
    }
    // pending_op: se preserva del turno anterior salvo cancelación —
    // una consulta intermedia no debe olvidar la operación pendiente.
    // En confirmación el ctx conserva las entidades del tema («confirmo»
    // no tiene entidades propias — sin esto se pierde el sujeto).
    if (is_array($newCtx['entities'] ?? null)) {
        if ($turnType === 'cancel') unset($newCtx['entities']['_op']);
        elseif (empty($newCtx['entities']['_op']) && !empty($ctxEntities['_op']))
            $newCtx['entities']['_op'] = $ctxEntities['_op'];
    }
    if ($turnType === 'confirmation' && empty($newCtx['entities']) && $ctxEntities)
        $newCtx['entities'] = $ctxEntities;

    // «a cuáles dar prioridad / cuál atiendo primero» sobre el buzón —
    // el flag puede llegar con el sustantivo en otra cláusula: si el
    // intent resuelto es el buzón, la prioridad siempre aplica
    if ($intent === 'notifications_unread'
        && preg_match('/\b(prioriz\w*|prioridad|prioridades|urgente|urgentes|importante|importantes|a cuales? (les? )?(debo|doy|le doy|le debo|conviene|toca)|orden de atencion|por importancia|que atiendo primero|que atiendo urgente|atender primero|atiendo primero)\b/u', $q0))
        $slots['_priority'] = true;

    // §17 self-check — evidencia de la interpretación final:
    // strong = parser confiado / evidencia léxica+módulo;
    // borderline = confianza ajustada; abstained = sin evidencia (oos)
    $selfcheck = 'strong';
    if ($intent === 'out_of_scope') $selfcheck = 'abstained';
    elseif (($cls['top3'][0][1] ?? 0) < 0.65 && $conf < 0.80) $selfcheck = 'borderline';
    elseif ($coverageHit && ($cls['top3'][0][1] ?? 0) < 0.40) $selfcheck = 'borderline';

    if (getenv('NEXO_DSM_TRACE') === '1' || is_file('/tmp/nexo_dsm_trace'))
        error_log("[DSM] in=" . ($cls['intent'] ?? '?') . " out={$intent} turn={$turnType} last=" . ($lastIntent ?? 'null')
            . " inh=" . implode(',', $inherited) . " cov=" . var_export($coverageHit, true) . " q0={$q0}");

    return [
        'said'      => ['normalized' => $q0],
        'inferred'  => ['domain' => $cls['domain'] ?? null, 'intent' => $cls['intent'],
                        'confidence' => $conf, 'top3' => $cls['top3'] ?? [],
                        'entities' => $cls['entities'] ?? []],
        'resolved'  => ['intent' => $intent, 'slots' => $slots,
                        'confidence' => $conf, 'inherited' => $inherited,
                        'new_slots' => $newSlots, 'selfcheck' => $selfcheck],
        'turn_type' => $turnType,
        'explicit_action' => $explicitAction,
        'followup_mark'   => $followupMark,
        'correction_mark' => $correctionMark,
        'requires_clarification' => $clarify !== null,
        'clarify' => $clarify,
        'ctx' => $newCtx,
    ];
}
