<?php
/**
 * =============================================================================
 * lib/nexus_nlu.php — Puente NLU de Nexus.
 * =============================================================================
 *
 * La interpretación es del LLM (lib/nexus_llm.php — parser semántico sobre
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
 * Normalización (idéntica a la del pipeline Python)
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
 * Clasificación — parser LLM (nexus_llm.php). No existe clasificador local:
 * el stack TF-IDF+LR se retiró (ver auditoria/AUDITORIA_NLU_VEREDICTO_*.md).
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
    if ($fxMap) {
        $k = nxNorm($text);
        if (isset($fxMap[$k])) $r = $fxMap[$k] + ['source' => 'fixture'];
    }
    if (!$r) $r = nxLlmClassify($text, $ctx);
    if ($r) {
        $r['entities'] = array_merge(
            $r['entities'] ?? [],
            array_filter(nxSlots(nxNorm($text)), fn($v) => $v !== null && $v !== '')
        );
    }
    return $r;
}

function nxClassify(string $text, ?array $ctx = null): array {
    // multi-intención — cada segmento se clasifica por separado
    $norm = nxNorm($text);
    $segments = array_values(array_filter(preg_split('/\s+(?:y|ademas|además|tambien|también|e)\s+|,\s*/u', $norm), fn($s)=>mb_strlen(trim($s))>2));
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
        $r['intent'] = 'out_of_scope';
        $r['fallback'] = true;
    }
    return $r;
}

/* ---------------------------------------------------------------------------
 * Slot-filling PHP — extracción determinista de entidades/fechas.
 * ------------------------------------------------------------------------- */
function nxSlots(string $q): array {
    $s = [];
    // Períodos pasados específicos PRIMERO — «del mes pasado» no es «del mes»
    if (preg_match('/mes pasado|mes anterior/u', $q)) {
        $s['days'] = 60;
    } elseif (preg_match('/semana pasada|semana anterior/u', $q)) {
        $s['days'] = 14;
    } elseif (preg_match('/ano pasado|ano anterior/u', $q)) {
        $s['days'] = 365;
    } elseif (preg_match('/ultimos? (\d+) dias?|en (?:los )?(\d+) dias?|(\d+) dias? atras/u', $q, $m)) {
        $s['days'] = (int)($m[1] ?: $m[2] ?: $m[3]);
    } elseif (preg_match('/(\d+) semanas?/u', $q, $m)) {
        $s['days'] = (int)$m[1] * 7;
    } elseif (preg_match('/\bhoy\b|este dia|dia de hoy/u', $q)) {
        $s['days'] = 0;
    } elseif (preg_match('/\bayer\b/u', $q)) {
        $s['days'] = 1;
    } elseif (preg_match('/esta semana|de la semana|en la semana/u', $q)) {
        $s['days'] = 7;
    } elseif (preg_match('/este mes|del mes|en el mes|ultimo mes|al mes|de este mes/u', $q)) {
        $s['days'] = 30;
    } elseif (preg_match('/mes pasado/u', $q)) {
        $s['days'] = 60;
    } elseif (preg_match('/semana pasada|semana anterior/u', $q)) {
        $s['days'] = 14;
    } elseif (preg_match('/este ano|del ano|en el ano/u', $q)) {
        $s['days'] = 365;
    }
    if (isset($s['days'])) {
        $s['to'] = nxToday();
        $s['from'] = nxToday($s['days']);
        $s['range_label'] = $s['days'] === 0 ? 'hoy' : ($s['days'] === 1 ? 'ayer' : "últimos {$s['days']} días");
    }

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
            $s['_group_src'] = $mo[0];   // paridad Python — distingue ordinal de dígito
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

function nxExtractStudent(string $q): ?string {
    static $stop = ['grupo','salon','colegio','escuela','jornada','hoy','ayer','semana',
        'mes','ano','dias','dia','el','la','los','las','un','una','este','esta','esto',
        'eso','mi','tu','su','mis','tus','sus','que','cual','cuales','cuanto','cuanta',
        'cuantos','cuantas','dime','dame','muestrame','ver','hay','tiene','tienen',
        'tenido','tuvo','fue','son','ser','nexus','nexo','favor','porfa','porfavor',
        'acudiente','acudientes','papa','mama','padre','madre','documento','cedula',
        'celular','telefono','whatsapp','numero','contacto','datos','informacion',
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
        // colectivos genéricos — «chicos del 7-B» es el grupo, no una persona
        'chico','chicos','chica','chicas','muchacho','muchachos','muchacha',
        'muchachas','pelado','pelados','pelada','peladas','menor','menores',
        'chino','chinos','china','chinas','ninios','ninias','onceavo','undecimo',
          'tardanza','inasistencia','evasion','ausencia','falta','permiso',
         'citacion','familia','familiar','pariente','parientes',
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
        // conectores/demostrativos/temporales sueltos — Fase 13: limpieza de
        // candidatos «camila del», «maria manana», «mismo juan»
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
        // verbos de consulta/comparación — «compara las tardanzas de 10A»
        // no nombra a nadie; «compara» es operación, no apellido
        'compara','comparar','comparame','comparacion','comparativa','versus',
        'vs','contra','diferencia','diferencias','mide','miden','evalua'];
    $boundary = '(?:\s+(?:del|de|en|grupo|salon|durante|en los|en las|hoy|ayer|esta|ultimos|en el|por|que|y)\b|$)';
    $cands = [];
    foreach ([
        // marcador de persona explícito — «la niña camila», «el muchacho
        // juan»: el nombre sigue al sustantivo, no al conector
        '/(?=(?:estudiante|alumno|alumna|nino|nina|muchacho|muchacha|pelado|pelada|chico|chica|menor)\s+([a-z]+(?:\s+[a-z]+){0,3})' . $boundary . ')/u',
        '/(?=\b(?:de|del|sobre|para|(?<![-\d])a|solo|solamente|tenido|tuvo|tiene|tienen|sido|hizo|estado|estuvo|hecho|falto|faltaron|llego|entro|salio|capo|volo|evadio|evadieron|caparon|volaron|volado|capado)\s+([a-z]+(?:\s+[a-z]+){0,3})' . $boundary . ')/u',
        // «camila del septimo», «juan del 8a», «pedro del jardin» —
        // nombre + «del/de» + grado: el nombre precede al conector
        '/\b([a-z]{2,}(?:\s+[a-z]+){0,2})\s+(?:del|de)\s+(?:el |la )?(?:primero|segundo|tercero|cuarto|quinto|sexto|septimo|octavo|noveno|decimo|once|undecimo|jardin|kinder|transicion|prescolar|\d)/u',
    ] as $pat) {
        preg_match_all($pat, $q, $mm, PREG_OFFSET_CAPTURE);
        static $leadMarkers = ['el','la','los','las','un','una','del','de','al',
            'mismo','misma','mismos','mismas','estudiante','estudiantes',
            'alumno','alumna','alumnos','alumnas','nino','nina','muchacho',
            'muchacha','pelado','pelada','chico','chica','menor'];
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
            if ($words) $cands[] = implode(' ', $words);
        }
    }
    return $cands ? end($cands) : null;
}

function nxModuleSynonyms(): array {
    return [
        'LATE_ARRIVAL'      => ['llegadas tarde','llegada tarde','tardanzas','tardanza','tarde','llego tarde','llegaron tarde','tardes'],
        'INASISTENCIA'      => ['inasistencias','inasistencia','inasistieron','inasistio','inasistió','inasiste','faltas','falta','ausencias','ausencia','no vinieron','no vino','faltaron','falto','ausentes','ausente','no llegaron','no llego','no entraron','no entro','no asistieron','no asistio','no se presentaron','no se presento','se ausentaron','se ausento'],
        'INASISTENCIA_JUSTIFICADA'    => ['inasistencias justificadas','justificadas','faltas justificadas'],
        'INASISTENCIA_NO_JUSTIFICADA' => ['inasistencias no justificadas','sin justificar','injustificadas'],
        'EVASION_INTERNA'   => ['evasiones internas','evasion interna','evasiones','evasion','fugas','fuga','se salieron','se salio','escaparon','escapo','salio del salon','abandono la clase','abandonaron clase','abandono el aula','abandono del aula','abandono de aula','abandono aula','salio del aula','salieron del aula','salio de clase','abandono','se volaron','se volo','se la volaron','se la volo','tiraron','se tiraron','tajaron','se tajaron','caparon','se caparon','evasores','se fueron','se fueron de clase','se fueron del salon','abandono durante','abandonaron el aula'],
        'PERMISO'           => ['permisos','permiso','salidas autorizadas','autorizaciones','autorizacion','autorizados','autorizadas','salidas autorizadas'],
        'SALIDA_BAÑO'       => ['salidas al bano','bano','banos','salidas de bano'],
        'SALIDA_COLEGIO'    => ['salidas del colegio','salida del colegio','salidas anticipadas','salio del colegio'],
        'SOS'               => ['sos','alertas sos','panico','emergencias','emergencia'],
        'CITACION'          => ['citaciones','citacion','citas a acudientes','mensajes a acudientes'],
        'SEGUIMIENTO'       => ['seguimientos','seguimiento','casos','caso','derivaciones'],
        'INCIDENTE'         => ['incidentes','incidente','reportes disciplinarios','disciplina','situaciones criticas','situacion critica'],
        'DAÑO'              => ['danos','dano','danios reportados'],
        'INGRESO'           => ['ingresos','entradas','entraron','entro','ingresaron','ingreso','entradas del dia','vinieron','llegaron','asistieron','vino','llego','asistio'],
        'SALIDA_PEDAGOGICA' => ['salidas pedagogicas','salida pedagogica','paseos','excursiones'],
    ];
}

function nxFieldSynonyms(): array {
    return [
        'documento'  => ['documento','cedula','ti','tarjeta de identidad','numero de documento','identificacion'],
        'celular'    => ['celular','telefono','whatsapp','movil','numero de celular'],
        'acudiente'  => ['acudiente','acudientes','papa','mama','padre','madre','responsable','familiar','quien lo recoge','quien la recoge',
            // paráfrasis relacionales — «quién responde por él», «a nombre
            // de quién está», «la persona que lo representa»
            'responde por','responde ante','quien responde','lo representa','la representa','representa ante',
            'a cargo de','a cargo del','encargado del','encargada del','encargada de','encargado de el',
            'figura como','a nombre de quien','persona a cargo','adulto a cargo','tutor legal','adulto responsable'],
        'grupo'      => ['grupo','salon','curso'],
        'jornada'    => ['jornada','turno'],
        'nacimiento' => ['nacimiento','edad','cuando nacio','anos tiene','fecha de nacimiento','cumpleanos'],
        'estado'     => ['estado','activo','retirado','matriculado'],
    ];
}

/* ---------------------------------------------------------------------------
 * Catálogo smalltalk (respuestas con variación) — el intent lo decide el
 * clasificador estadístico; aquí solo vive el repertorio.
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

/* Permisos por intent (matriz RBAC del documento CHATBOT_NLP.md) */
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
        'biometric_spam' => ['RECTOR','COORDINATOR','SECURITY'],
        'group_student_count' => $STAFF,
        'students_in_group'   => $STAFF,
        'birthdays_today' => $ALL,
        'my_activity' => $ALL,
        'failed_messages' => ['RECTOR','COORDINATOR','SECRETARY'],
        'risk_config' => ['RECTOR','COORDINATOR'],
        'attendance_ranking' => ['RECTOR','COORDINATOR','COUNSELOR','SECRETARY'],
        'session_summary' => $ALL,
        'pending_tasks' => $ALL,
        'whatsapp_status' => ['RECTOR','COORDINATOR','SECRETARY'],
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
    'failed_messages','whatsapp_status','my_activity','pending_tasks','schedule_info',
    'risk_students','export_data','students_in_group','result_nav','frequency_table',
    // consultas de datos adicionales — también pueden ser tema activo
    'attendance_today','late_today','count_present','day_summary'];

const NX_GENERIC_INTENTS = ['day_summary','attendance_today','late_today','count_present'];

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
    $correctionWeak = (bool)preg_match('/\b(no|perdon|perdona)\b/u', $q0);
    // confirmación/cancelación explícita — empieza por verbo de confirmación/
    // cancelación («confirmo la solicitud» confirma, no inicia otra operación)
    $confirmMark = (bool)preg_match('/^(ahora |pero |y |entonces )?(si|sí|confirmo|confirma|'
        . 'confirmar|dale|hazlo|adelante|correcto|de acuerdo|perfecto|vale|ok|bueno si|listo si)\b/u', $q0);
    $cancelMark = (bool)preg_match('/^(ahora |pero |y |espera |entonces )?(cancela|cancelar|'
        . 'cancelo|dejalo|deja|olvida|olvídalo|mejor no|ya no|detente|parale|no eso)\b/u', $q0)
        || preg_match('/^(no|nel|nop)\b[.! ]*$/u', $q0);

    // §15 ctx corrupto: tipos incorrectos se invalidan, nunca propagan
    $ctxEntities = is_array($ctx['entities'] ?? null) ? $ctx['entities'] : [];
    $lastIntent  = is_string($ctx['last_intent'] ?? null) ? $ctx['last_intent'] : null;
    $inheritable = $lastIntent && in_array($lastIntent, NX_QUERY_INTENTS, true);

    $hasNewEntity = false;
    foreach (['student','group','module','days','field'] as $k)
        if (!empty($slots[$k])) { $hasNewEntity = true; break; }
    $correctionMark = $correctionStrong || ($correctionWeak && $hasNewEntity);

    // ── 1. Corrección explícita: «no, de María» → solo reemplaza el slot nuevo ──
    if ($correctionMark && $inheritable && ($hasNewEntity || $correctionStrong)) {
        $turnType = 'correction';
        // el intent previo se mantiene; el slot nuevo (student/group/days/field)
        // REEMPLAZA al heredado — no se acumula.
        foreach (['student','group','module','days','from','to','range_label','field'] as $k) {
            if (!empty($slots[$k])) { $newSlots[] = $k; }
            elseif (!empty($ctxEntities[$k])) { $slots[$k] = $ctxEntities[$k]; $inherited[] = $k; }
        }
        $intent = $lastIntent;
        $inherited[] = 'intent';
    } else {
        // ── 2. Herencia de slots — SOLO en turnos dependientes (regla F:
        // una consulta autónoma no hereda entidades arbitrariamente).
        // También cuentan como dependencia: verbos en 3ª persona que
        // presuponen sujeto («acumula», «tiene», «lleva», «sigue») ──
        $deicticVerb = (bool)preg_match('/\b(acumula|tiene|lleva|sigue|mantiene|hizo|hace|hicieron|fue|estuvo|anda|va|viene|quedo|quedaron|resulto)\b/u', $q0);
        // interrogativo sin sustantivo de tema («cuales fueron justificadas»)
        // — depende del tema anterior; con tema propio es autónoma
        $interrogDep = (bool)(preg_match('/^(cuales?|quien|quienes|cuant[oa]s?|que)\b/u', $q0)
            && !preg_match('/\b(tardanza|inasist|falt|evasion|permiso|citacion|cita|evento|incident|seguim|notif|salid|estudiant|mensaje|alerta|ausen|presente|ingres|cumpl|tarea|pendient|docent|acudient|llamad|horario|nota|grado|grupo|salon|jornada|correo|cumpleano|caso|casos|alumno|reporte)/u', $q0));
        // sustantivo desnudo de campo («documento», «acudiente», «el teléfono»)
        // — ≤3 palabras con un sustantivo de ficha: presupone el sujeto activo
        $bareNoun = (bool)(str_word_count($q0, 0, 'áéíóúñü') <= 4
            && preg_match('/\b(documento|telefono|celular|acudiente|contacto|direccion|correo|ficha|datos|perfil|resumen|horario|grupo|salon|papa|mama|padre|madre|familiar|edad|cumpleanos|numero|whatsapp|cedula|identificacion)\b/u', $q0));
        $dependent = $followupMark || $correctionWeak || $deicticVerb || $interrogDep || $bareNoun
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
        // «dame otro / el siguiente / el primero / los demás / su nombre»
        // sobre el result-set anterior — consulta informativa, nunca op
        if ($hasResult) {
            $nav = null;
            // ── transformaciones sobre el set activo (§12-13): proyección,
            // orden, slice, goto — antes de los ordinales sueltos ──
            if (preg_match('/\b(solo (los |las )?nombres?|nada mas (los |las )?nombres?|solo sus nombres|sin documentos?|solo el nombre)\b[.!? ]*$/u', $q0)) $nav = 'proj:name';
            elseif (preg_match('/\b(agrega|añade|anade|incluye|ponles|mete|con)\s*(le|les|me)?\s*(el |los |la |las )?(documento|documentos|telefono|celular|whatsapp|grupo|edad)\b/u', $q0, $mp)
                    && preg_match('/\b(agrega|añade|anade|incluye|ponles|mete)\b/u', $q0))
                $nav = 'proj:' . ['documento'=>'+document','documentos'=>'+document','telefono'=>'+phone','celular'=>'+phone','whatsapp'=>'+phone','grupo'=>'+group','edad'=>'+group'][$mp[4]];
            // «con documento» desnudo tras un set = añade la columna a la
            // vista activa — no es un lookup de estudiante
            elseif (preg_match('/^(?:y\s+|ahora\s+|pero\s+)?con\s+(?:el\s+|los\s+|la\s+|las\s+|su\s+|sus\s+)?(documento|documentos|telefono|celular|whatsapp|grupo)\b[.!? ]*$/u', $q0, $mpb))
                $nav = 'proj:' . ['documento'=>'+document','documentos'=>'+document','telefono'=>'+phone','celular'=>'+phone','whatsapp'=>'+phone','grupo'=>'+group'][$mpb[1]];
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
            elseif (preg_match('/^(y |ahora |dame |dime |muestra(?:me)? |muestrame |trae(?:me)? )?(todos|todas|todo|todos ellos|todas ellas|el listado completo|la lista completa)[.! ]*$/u', $q0)) $nav = 'all';
            elseif (preg_match('/^(y |ahora |y ahora |dame |dime |muestra(?:me)? |muestrame |trae(?:me)? )?(los demas|las demas|los otros|las otras|el resto|los que faltan|los restantes)[.! ]*$/u', $q0)) $nav = 'rest';
            elseif (preg_match('/\b(en tabla|en una tabla|como tabla|formato tabla|ponmelos en una tabla|ponlos en tabla|en columnas|en cuadro|tabulados?|la tabla completa|todos en tabla|muestralos todos|muéstralos todos|muestramelos todos|muéstramelos todos|pasame todos|dame todos|lista completa|la lista entera|la nomina completa|el listado completo)\b/u', $q0)) $nav = 'table';
            elseif (preg_match('/\b(cuantos|cuantas|cuanto|cuanta|cuantos son|cuantas son|cuantos hay|cuantas hay)( son| hay| eran| fueron| resultaron| en total| son en total| al final| en total son)?\b[?¡! ]*$/u', $q0)) $nav = 'count';
            elseif (preg_match('/\b(cual|como|quien) (es|fue|se llama)? ?(su|el) (nombre|como se llama)\b[?¡! ]*$/u', $q0)
                || preg_match('/^(y )?(su nombre|el nombre|como se llama|quien es|quien era)[.!? ]*$/u', $q0)) $nav = 'name';
            if ($nav) { $slots['_nav'] = $nav; $turnType = 'context_modify'; }
        }
        // «del primero / del segundo / del último» — el extractor ordinal
        // produjo group='1'/'2'/…; con referente activo (set, tema de grupo
        // o campo relacional) es POSICIÓN sobre el tema, nunca grado N.
        if (!empty($slots['group']) && !preg_match('/\d/', $q0)
            && preg_match('/\b(?:del|de|el|la|los|las)\s+(primero|primera|segundo|segunda|tercero|tercera|cuarto|cuarta|quinto|quinta|primer|tercer|ultimo|ultima)\b(?!\s+(?:de|del|en|a|por|para|dia|mes|semana|ano|lugar|puesto)\b)/u', $q0, $mog)
            && !preg_match('/\b(grado|grupo|salon|curso)\b/u', $q0)
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
        // «su <campo>» sobre la entidad activa (estudiante/acudiente)
        // — NO es número de lotería ni dato del asistente
        if (!isset($slots['_nav'])) {
            $refField = [
                'numero|telefono|celular|whatsapp|contacto' => 'celular',
                'documento|cedula|identificacion'           => 'documento',
                'acudiente|tutor|responsable|papa|mama'     => 'acudiente',
                'grupo|salon|curso|seccion'                 => 'grupo',
                'jornada|turno|horario'                     => 'jornada',
                'nacimiento|fecha de nacimiento|cumpleanos' => 'nacimiento',
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
                    if ($intent !== 'student_field' && $intent !== 'student_summary') $intent = 'student_field';
                    $turnType = 'context_modify';
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
            if (preg_match('/^(y |pero |ahora |entonces |o sea )?(del |de la |de |en |sobre )?(ayer|anteayer|hoy|esta semana|la semana pasada|este mes|el mes pasado|mes pasado|la semana|del mes|de hoy|de ayer|el otro dia|ese dia|esa semana|ese mes|semana pasada|semana anterior|mes anterior|ano pasado|ultimos? \d+ dias?|\d+ dias?|ultimo mes|ultima semana|ultimo ano|la quincena|el bimestre pasado|bimestre pasado|lo que va del mes|lo que va de la semana|lo corrido del mes)\b[.!? ]*$/u', $q0)
                && in_array($intent, ['foreign_culture','out_of_scope','smalltalk','greeting','yes','no','audit_query','about_nexus','deictic','students.position','incidents.position','students_in_group','list_events','count_events','birthdays_today','random_student','student_summary','group_summary','attendance_today','count_present','late_today','permissions','day_summary','trackings'], true)) {
                $intent = $lastIntent; $inherited[] = 'intent';
                $turnType = 'context_modify';
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
        }
        // «cuántos son en total» con grupo activo → conteo DEL grupo
        // (el inherit genérico ya pudo llenar slots.group — igual aplica)
        if ($intent === 'students_count'
            && !preg_match('/\b(en el colegio|del colegio|de la institucion|de todo el plantel|en total del colegio|matriculados en total)\b/u', $q0)
            && (!empty($ctxEntities['group']) || !empty($slots['group']))) {
            if (empty($slots['group'])) { $slots['group'] = $ctxEntities['group']; $inherited[] = 'group'; }
            $intent = 'group_student_count';
            $turnType = 'context_modify';
        }
        // «¿y cuántos son en total?» — conteo desnudo sobre el set activo.
        // Sin sustantivo el clasificador cae a oos/list_events; el universo
        // lo define el contexto: nómina → conteo del grupo, módulo/eventos
        // → count_events del rango
        if (in_array($intent, ['out_of_scope','list_events','count_events'], true)
            && preg_match('/\b(cuantos|cuantas)\s+(son|hay|en total|somos|en el grupo|quedan)\b/u', $q0)
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
            if (($slots['days'] ?? null) === null && isset($ctxEntities['days'])) {
                $slots['days'] = $ctxEntities['days']; $inherited[] = 'days';
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
        if ($opVerb && in_array($intent, ['out_of_scope','permissions','citations',
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
        if (($intent === 'out_of_scope' || $conf < NX_NLU_THRESHOLD)
            && !in_array($intent, ['derive_action','start_operation','repeat_op','confirm_op','security_probe'], true)
            && $inheritable && $dependent && !$hasOpNoun && !$coverageHit) {
            $intent = $lastIntent;
            $inherited[] = 'intent';
            $turnType = 'context_modify';
        }
        // ── 4. Modificación contextual (genéricos) — «¿y las de hoy?» ──
        // Guardias: coverage override exento; y un mensaje con
        // cuantificador+métrica propia («y cuántas tardanzas») NO es
        // modificación deíctica — el intent propio es el correcto.
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

    // §17 self-check — evidencia de la interpretación final:
    // strong = modelo confiado / evidencia léxica+módulo;
    // borderline = rerank por margen fino; abstained = sin evidencia (oos)
    $selfcheck = 'strong';
    if ($intent === 'out_of_scope') $selfcheck = 'abstained';
    elseif (($cls['top3'][0][1] ?? 0) < 0.65 && $conf < 0.80) $selfcheck = 'borderline';
    elseif ($coverageHit && ($cls['top3'][0][1] ?? 0) < 0.40) $selfcheck = 'borderline';

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
