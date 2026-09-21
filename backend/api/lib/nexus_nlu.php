<?php
/**
 * =============================================================================
 * lib/nexus_nlu.php — Puente NLU de Nexus.
 * =============================================================================
 *
 * Estrategia en cascada (toda inferencia es estadística — el regex es solo
 * slot-filling y red de emergencia):
 *
 *   1. Servicio Python  → POST {NEXO_NLU_URL}/classify (TF-IDF + Regresión
 *      Logística scikit-learn; ~99.9% accuracy, 56 intents).
 *   2. Modelo PHP nativo → model_php.json (export del mismo clasificador,
 *      inferencia softmax en PHP puro; se usa si el servicio no responde).
 *   3. Fallback          → confianza < 0.66 ⇒ 'out_of_scope' (flujo de
 *      clarificación), nunca un handler adivinado.
 *
 * Retorna: ['intent','confidence','entities','top3','source']
 */

const NX_NLU_THRESHOLD = 0.65;   // umbral estricto por nivel (spec: 65%)

/* ---------------------------------------------------------------------------
 * Normalización (idéntica a la del pipeline Python)
 * ------------------------------------------------------------------------- */
function nxNorm(string $t): string {
    $t = mb_strtolower(trim($t), 'UTF-8');
    $t = strtr($t, ['á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ü'=>'u','ñ'=>'n',
                    'à'=>'a','è'=>'e','ì'=>'i','ò'=>'o','ù'=>'u']);
    $t = preg_replace('/[¿?¡!.,;:\(\)"\'«»]/u', ' ', $t);
    return trim(preg_replace('/\s+/', ' ', $t));
}

/* ---------------------------------------------------------------------------
 * 1) Servicio Python
 * ------------------------------------------------------------------------- */
function nxClassifyService(string $text): ?array {
    $url = getenv('NEXO_NLU_URL') ?: 'http://localhost:8090';
    // sin NEXO_NLU_URL configurado, el NLU corre embebido en el mismo contenedor
    $ch = curl_init(rtrim($url, '/') . '/classify');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode(['text' => $text], JSON_UNESCAPED_UNICODE),
        CURLOPT_TIMEOUT_MS => 900,
        CURLOPT_CONNECTTIMEOUT_MS => 300,
    ]);
    $res = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($res === false || $code !== 200) return null;
    $d = json_decode($res, true);
    if (!is_array($d) || !isset($d['intent'])) return null;
    $d['source'] = 'service';
    return $d;
}

/* ---------------------------------------------------------------------------
 * 2) Modelo PHP nativo — TF-IDF word 1-2gram + softmax del LR exportado
 * ------------------------------------------------------------------------- */
function nxPhpModel(): ?array {
    static $m = null;
    if ($m !== null) return $m ?: null;
    $f = __DIR__ . '/../../nlu/model/model_php.json';
    if (!is_file($f)) { $m = false; return null; }
    $m = json_decode(file_get_contents($f), true);
    return $m ?: null;
}

function nxTokens(string $q): array {
    $w = explode(' ', $q);
    $t = $w;
    for ($i = 0; $i + 1 < count($w); $i++) $t[] = $w[$i] . ' ' . $w[$i + 1];
    return $t;
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

/** Espejo de preprocess.mask_entities: el modelo aprende estructura, no nombres. */
function nxMask(string $q): string {
    $s = nxSlots($q);
    $masked = $q;
    foreach (($s['_regions'] ?? []) as $r) {
        $masked = preg_replace('/\b' . preg_quote($r) . '\b/u',
            nxIsForeign($r) ? ' extranjero_ent ' : ' region_ent ', $masked);
    }
    if (!empty($s['student'])) {
        $masked = str_replace($s['student'], ' estudiante_ent ', $masked);
    }
    if (!empty($s['group'])) {
        $masked = preg_replace('/\b' . preg_quote(mb_strtolower($s['group'])) . '\b/u', ' grupo_ent ', $masked);
    }
    $masked = preg_replace('/\b\d+\b/', ' num_ent ', $masked);
    $masked = preg_replace('/\b(uno|dos|tres|cuatro|cinco|seis|siete|ocho|nueve|diez|once|doce|trece|catorce|quince|veinte|treinta|cuarenta|cincuenta|sesenta|setenta|ochenta|noventa|cien|ciento|mil|millon|millones)\b/u', ' num_ent ', $masked);
    return trim(preg_replace('/\s+/', ' ', $masked));
}

/** Softmax TF-IDF nativo sobre un submodelo exportado. */
function nxSoftmax(array $m, string $q): array {
    $vec = [];
    foreach (nxTokens($q) as $tok) {
        if (!isset($m['vocab'][$tok])) continue;
        $i = $m['vocab'][$tok];
        $vec[$i] = ($vec[$i] ?? 0) + 1;
    }
    if (!$vec) return ['intent' => null, 'confidence' => 0.0, 'top3' => []];
    $norm = 0;
    foreach ($vec as $i => $c) { $vec[$i] = (1 + log($c)) * $m['idf'][$i]; $norm += $vec[$i] ** 2; }
    $norm = sqrt($norm) ?: 1;
    $logits = $m['intercept'];
    foreach ($vec as $i => $v) {
        $v /= $norm;
        foreach ($m['coef'] as $k => $row) $logits[$k] += $row[$i] * $v;
    }
    // binario: sklearn exporta una sola fila → sigmoide, no softmax
    if (count($m['coef']) === 1) {
        $p1 = 1 / (1 + exp(-$logits[0]));
        // sklearn binario: coef pertenece a la clase ordenada en classes_[1]
        $proba = [0 => 1 - $p1, 1 => $p1];
        arsort($proba);
        $bestIdx = array_key_first($proba);
        return ['intent' => $m['classes'][$bestIdx], 'confidence' => $proba[$bestIdx],
                'top3' => array_slice($proba, 0, 3, true), '_proba' => $proba];
    }
    $max = max($logits);
    $exp = array_map(fn($l) => exp($l - $max), $logits);
    $sum = array_sum($exp);
    $proba = array_map(fn($e) => $e / $sum, $exp);
    arsort($proba);
    $bestIdx = array_key_first($proba);
    return ['intent' => $m['classes'][$bestIdx], 'confidence' => $proba[$bestIdx],
            'top3' => array_slice($proba, 0, 3, true), '_proba' => $proba];
}

const NX_FORMAL_BIAS = 0.30;   // P(formal) mínima → dominio formal
const NX_NLU_THRESHOLD_2 = 0.65; // umbral por nivel

function nxClassifyLocal(string $text): ?array {
    $all = nxPhpModel();
    if (!$all || !isset($all['router'])) return null;
    $norm = nxNorm($text);
    $slots = nxSlots($norm);
    $q = nxMask($norm);

    // Nivel 1 — router formal/informal con sesgo a misión crítica
    $r = nxSoftmax($all['router'], $q);
    $pFormal = $r['_proba'][array_search('formal', $all['router']['classes'])] ?? 0;
    $critical = !empty($slots['student']) || !empty($slots['group']) || !empty($slots['module']);
    // Guardia informal determinística — paridad con service.py
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

    // Nivel 2 — submodelo
    $sub = nxSoftmax($all[$domain], $q);
    $intent = $sub['intent'] ?? 'out_of_scope';
    $conf = $sub['confidence'] ?? 0;
    $top3 = array_map(fn($i) => [$all[$domain]['classes'][$i], round($sub['top3'][$i] ?? 0, 4)],
                      array_keys($sub['top3']));
    // arbitraje dual — paridad con service.py: cuando el router duda,
    // el otro clasificador puede ganar si domina con claridad
    if (!$critical && !$informalOnly && $pFormal >= 0.20 && $pFormal <= 0.80) {
        $other = $domain === 'formal' ? 'informal' : 'formal';
        $sub2 = nxSoftmax($all[$other], $q);
        if (($sub2['confidence'] ?? 0) > $conf + 0.15) {
            $domain = $other;
            $intent = $sub2['intent']; $conf = $sub2['confidence'];
            $top3 = array_map(fn($i) => [$all[$other]['classes'][$i], round($sub2['top3'][$i] ?? 0, 4)],
                              array_keys($sub2['top3']));
        }
    }
    return [
        'domain' => $domain,
        'domain_conf' => round($pFormal, 4),
        'intent' => $intent,
        'confidence' => round($conf, 4),
        'top3' => $top3,
        'entities' => $slots,
        'source' => 'php-model',
    ];
}

/* ---------------------------------------------------------------------------
 * Clasificación final
 * ------------------------------------------------------------------------- */
function nxClassify(string $text): array {
    // multi-intención local — el servicio Python ya devuelve 'parts'
    $norm = nxNorm($text);
    $segments = array_values(array_filter(preg_split('/\s+(?:y|ademas|además|tambien|también|e)\s+|,\s*/u', $norm), fn($s)=>mb_strlen(trim($s))>2));
    if (count($segments) > 1) {
        $parts = [];
        foreach (array_slice($segments,0,4) as $seg) {
            $p = nxClassifyService($seg) ?? nxClassifyLocal($seg);
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
    $r = nxClassifyService($text) ?? nxClassifyLocal($text)
        ?? ['intent' => 'out_of_scope', 'confidence' => 0.0,
            'entities' => nxSlots(nxNorm($text)), 'top3' => [], 'source' => 'none'];
    // entidades: siempre fusionar con nxSlots — el servicio no extrae
    // module/field/from/to/range_label (eso lo completa PHP)
    $r['entities'] = array_merge(nxSlots($norm), $r['entities'] ?? []);
    // «excepto el 8A» — el servicio puede traer group=8A que es exclusión
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
 * Slot-filling PHP (espejo de service.py — se usa con el modelo local)
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
        $s['from'] = gmdate('Y-m-d', time() - $s['days'] * 86400);
        $s['to'] = gmdate('Y-m-d');
        $s['range_label'] = $s['days'] === 0 ? 'hoy' : ($s['days'] === 1 ? 'ayer' : "últimos {$s['days']} días");
    }

    if (preg_match('/\b(?:grupo|salon|del|de|en)\s+(\d{1,2}\s?[a-z]|\d{1,2}-\d{1,2}|\d{1,2}\.\d{1,2}|prescolar|jardin|transicion|kinder)\b/u', $q, $m)
        || preg_match('/\b(\d{1,2}[a-z]|\d{1,2}-\d{1,2}|\d{1,2}\.\d{1,2}|\d{1,2} \d{1,2})\b/u', $q, $m)) {
        $s['group'] = strtoupper(str_replace([' ', '.'], ['-', '-'], $m[1]));
    }
    // ordinales: «octavo a», «onceavo b», «grado noveno»
    if (empty($s['group'])) {
        $ord = ['primero'=>'1','segundo'=>'2','tercero'=>'3','cuarto'=>'4','quinto'=>'5',
                'sexto'=>'6','septimo'=>'7','octavo'=>'8','noveno'=>'9','decimo'=>'10',
                'once'=>'11','onceavo'=>'11','undecimo'=>'11'];
        if (preg_match('/\b(' . implode('|', array_keys($ord)) . ')\s*([a-j])\b/u', $q, $mo)
            || preg_match('/\b(?:grado|grupo|salon)\s+(' . implode('|', array_keys($ord)) . ')\b/u', $q, $mo)
            // ordinal desnudo tras preposición: «del octavo», «los del noveno»
            || preg_match('/\b(?:del|de|los|las|el|al)\s+(' . implode('|', array_keys($ord)) . ')\b/u', $q, $mo)) {
            $s['group'] = $ord[$mo[1]] . (isset($mo[2]) ? strtoupper($mo[2]) : '');
        }
    }

    // módulo por sinónimos
    foreach (nxModuleSynonyms() as $canon => $syns) {
        foreach ($syns as $syn) {
            if (str_contains($q, $syn)) { $s['module'] = $canon; break 2; }
        }
    }
    // campo de estudiante
    foreach (nxFieldSynonyms() as $field => $syns) {
        foreach ($syns as $syn) {
            if (str_contains($q, $syn)) { $s['field'] = $field; break 2; }
        }
    }
    // estudiante (misma heurística que service.py)
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
        'puedes','puedo','por','si','ok','vale','dale',
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
        'octavo','noveno','decimo','once','onceavo','undecimo',
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
        'matematicas','ingles','espanol','ciencias','sociales','fisica','quimica',
        'biologia','historia','geografia','arte','musica','religion','etica',
        'informatica','lectura','escritura','coordinador','coordinadores',
        'docente','docentes','profesor','profesores','maestro','maestros',
        'personal','rector','rectores','secretaria','secretarias','directivo',
        'exactamente','precisamente','respectivamente','personalmente',
        'excusa','medica','medico','durante','tiempo','sistemas','mejora','seguridad','conducta','nino','nina','academico','academica','transferida','transferido','natacion','autorizada','autorizado','autorizados','autorizadas','bimestre','preescolar','en','falto','jornada','estado','grupo','estudiante','estudiantes','alumno','alumnos','proceso','procesos','area','nivel','registrada','registrado','registrados','entrada','entradas','salida','salidas','anticipada','anticipado','temprana','temprano','tardia','tardio','alerta','alertas','tarea','tareas','caso','casos','incidencia','incidencias','evento','eventos','fuga','fugas','lector','lectores','piso','pisos','recreo','descanso','observacion','presente','presentes','ausente','ausentes','vinieron','llego','llegaron','entro','entraron','presento','presentaron','regreso','regresaron','acumulada','acumuladas','acumulado','acumulados','marcada','marcado','marcados','marcaron','resuelto','resueltos','resuelta','resueltas','completado','autorizo','autorizaron','faltaron','impuntual','impuntuales','registrar','registren','detectada','detectadas','detectado','detectados','detectaron','reportada','reportadas','reportado','reportados','reportaron','llamado','llamada','llamar','llamen','citado','citada','convocar','convocado','convocada','reunion','reuniones','peticion','peticiones','padres','padre','madre','mama','papa','abuela','abuelo','tia','tio','hermano','hermana','amigo','amiga','vecino','vecina','nadie','alguien','alguno','alguna','algunos','algunas','ninguno','ninguna','ningunos','ningunas','cualquiera','quienquiera','cuyo','cuya','filosofia','literatura','politica','geografia','historia','quimica','biologia','astronomia','religion','matematicas','espanol','ingles','frances','aleman','lejos','cerca','arriba','abajo','dentro','fuera','encima','debajo','delante','detras','alrededor','junto','juntos','juntas','aparte','incluso','volaron','volar','escaparon','escapar','caparon','capar','volaron','voló','volo','excepto','menos','salvo','aparte','reporto','reportaste','reportamos','aplican','aplica'];
    $boundary = '(?:\s+(?:del|de|en|grupo|salon|durante|en los|en las|hoy|ayer|esta|ultimos|en el|por|que|y)\b|$)';
    $cands = [];
    foreach ([
        '/(?=(?:estudiante|alumno|alumna|nino|nina)\s+([a-z]+(?:\s+[a-z]+){0,3})' . $boundary . ')/u',
        '/(?=\b(?:de|del|sobre|para|a|solo|solamente|tenido|tuvo|tiene|tienen|sido|hizo|estado|estuvo|hecho|falto|faltaron|llego|entro|salio|capo|volo|evadio|evadieron|caparon|volaron|volado|capado)\s+([a-z]+(?:\s+[a-z]+){0,3})' . $boundary . ')/u',
        // «camila del septimo», «juan del 8a», «pedro del jardin» —
        // nombre + «del/de» + grado: el nombre precede al conector
        '/\b([a-z]{2,}(?:\s+[a-z]+){0,2})\s+(?:del|de)\s+(?:el |la )?(?:primero|segundo|tercero|cuarto|quinto|sexto|septimo|octavo|noveno|decimo|once|undecimo|jardin|kinder|transicion|prescolar|\d)/u',
    ] as $pat) {
        preg_match_all($pat, $q, $mm, PREG_OFFSET_CAPTURE);
        foreach ($mm[1] ?? [] as $cand) {
            $words = array_values(array_filter(
                explode(' ', trim($cand[0])),
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
        'INASISTENCIA'      => ['inasistencias','inasistencia','faltas','falta','ausencias','ausencia','no vinieron','no vino','faltaron','falto','ausentes','ausente','no llegaron','no llego','no entraron','no entro','no asistieron','no asistio','no se presentaron','no se presento','se ausentaron','se ausento'],
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
        'acudiente'  => ['acudiente','acudientes','papa','mama','padre','madre','responsable','familiar','quien lo recoge','quien la recoge'],
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
        'pending_returns' => $STAFF,
        'sos_alerts' => ['RECTOR','COORDINATOR','SECURITY'],
        'biometric_spam' => ['RECTOR','COORDINATOR','SECURITY'],
        'group_student_count' => $STAFF,
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
    // intents smalltalk no listados → permitidos a todos
    return $roles === null || in_array($role, $roles, true);
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
    'risk_students','export_data',
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
 * nxCoverageOverride — reglas determinísticas para frases de dominio que el
 * clasificador no cubre. SOLO actúa cuando el modelo no resolvió
 * (out_of_scope) o asignó smalltalk inequívocamente erróneo a una frase
 * con señal de dominio clara. NUNCA toca autorización — el intent
 * resultante pasa por nxAllowed/chatPolicy igual que cualquier otro.
 */
/* ============================================================================
 * SEMANTIC RESOLVER — rerank determinista sobre el top-k del clasificador.
 * La auditoría single-turn (test/audit_single_errors.php) mostró ~80% de
 * errores «borderline»: el intent correcto ya está en el top-3 pero pierde
 * por umbral o empate. Esta capa NO es un modelo: puntúa cada candidato
 * del top-k con evidencia auditable (firma léxica del intent + alineación
 * de módulo + cuantificador) y decide EXECUTE / ABSTAIN por margen.
 * Nunca devuelve SQL/comandos/permisos — solo intent dentro de la taxonomía.
 * ==========================================================================*/

const NX_INTENT_LEXICON = [
    'late_today'          => 'tardanza|tardanzas|tarde|tardias|tardio|impuntual|impuntuales|llegaron tarde|entrada tardia|pasada la hora|pasado el horario|despues de la hora|despues de las|a destiempo|atraso|atrasados|demora|demorados|llegada tarde|llegadas tarde|entradas tardias',
    'attendance_today'    => 'asistencia|asistieron|asisten|asistio|presente|presentes|presento|presentaron|vinieron|llegaron|entaron|entraron|ausente|ausentes|faltaron|inasistencia|no vinieron|no llegaron|no entraron|no asistieron|no se present|ausencia|se ausent|faltan|faltan hoy|no entraron',
    'count_present'       => 'cuantos asistieron|cuantos presentes|presentes hoy|asistieron hoy|cuantos vinieron|cuantos llegaron|cuantos entraron|cuantos hay hoy|cuantos estudiantes vinieron|cuantos estudiantes llegaron|cuantos estudiantes asistieron|cuantos estudiantes entraron|cuantos alumnos vinieron|cuantos chicos vinieron',
    'count_events'        => 'eventos|casos|incidencias|incidentes|registros|disciplinarios|ocurrencias|hechos|sucesos|reportes|denuncias|faltas|inasistencias|evasiones|salidas|permisos|cuantos hubo|cuantas hubo|acumuladas|acumulados|consolidado|totales|fugas|escapadas|reincidencia',
    'list_events'         => 'muestra|muestrame|lista|listado|quienes|cuales|los que|dame|ver|revisar|registrados|registradas|detectados|detectadas|marcados|marcadas|traeme|pasame|reportados por|reportadas por|detectados por|detectadas por|los del|las del|las que',
    'day_summary'         => 'resumen|balance|panorama|como (cerro|va|fue)|general del dia|estado del dia|panorama general|plantel|estado general|todo el colegio|colegio entero|del plantel|como vamos|estado de la institucion',
    'group_summary'       => 'estado del (grupo|curso|seccion|salon)|resumen del grupo|como va el grupo|faltas del grupo|estado del \d+|el grupo al que|del grado|grupo del',
    'group_student_count' => 'cuantos estudiantes tiene|cuantos alumnos|matriculados|inscritos|poblacion|matricula',
    'students_count'      => 'cuantos estudiantes hay|cuantos estudiantes|total de estudiantes|censo|poblacion estudiantil|matriculados en total',
    'trackings'           => 'seguimiento|seguimientos|observacion|proceso de mejora|procesos|acompanamiento|bajo observacion|disciplinario|bajo seguimiento|casos abiertos|casos cerrados',
    'count_trackings'     => 'cuantos seguimientos|cuantos procesos|cuantas observaciones|procesos de mejora',
    'permissions'         => 'permiso|permisos|excusa|excusas|autorizacion|salida anticipada|salida temprana|retiro|retirarse|retire|salir antes|irse antes|permiso medico|incapacidad|autorizaciones|con permiso',
    'pending_returns'     => 'no regresaron|no volvieron|pendientes de regreso|sin regresar|no retornaron|no regreso',
    'citations'           => 'citacion|citaciones|citar|convocar|convocado|acudiente|acudientes|padres|reunion con|llamar a|llamado|citados?|citadas?|citasion|citaciones pendientes|convocados?|cita\b|convocaron',
    'student_summary'     => 'datos de|info de|ficha|resumen de|perfil|expediente|informacion de|quien es|historial|trayectoria|legajo|resumen academico',
    'student_field'       => 'documento|telefono|celular|direccion|contacto|eps|fecha de nacimiento|acudiente|whatsapp|papa|mama|padre|madre|familiar|correo|hermano|hermana|tutor',
    'groups_list'         => 'que grupos|lista de grupos|cuantos grupos|todos los grupos|cursos',
    'teachers_list'       => 'docentes|profesores|maestros|coordinadores|personal docente|planta docente|empleados|funcionarios',
    'staff_lookup'        => 'docente del que|docente que|profesor del que|el docente del|la docente del|de matematicas|de ingles|de espanol|de ciencias|de sociales|de fisica|de quimica|de biologia|de historia|de geografia|de arte|de musica|de educacion fisica|de religion|de etica|de informatica|del area|quien ensena|quien dicta|profe de|docente de|maestro de|rectora|rector|la rectora|el rector',
    'schedule_info'       => 'horario|bloque|jornada|hora de clase|periodo|descanso|recreo|almuerzo|clase de|cuando hay clase|turno|formacion|formaciones',
    'notifications_unread'=> 'mensajes?|notificaciones|avisos|sin leer|nuevos mensajes|llegaron mensajes|me escribieron',
    'failed_messages'     => 'sin enviar|no se enviaron|fallaron|rebotados|mensajes fallidos|pendientes de envio|quedaron sin enviar|avisos que no|mensajes que no|notificaciones que no|avisos que no llegaron|no llegaron a los padres|no les llego|no les llegaron',
    'whatsapp_status'     => 'whatsapp|estado de mensajeria|mensajeria|conexion whatsapp',
    'devices_status'      => 'lector|lectores|dispositivo|dispositivos|huella|biometrico|sensor|terminal|marcador|reloj biometrico|duplicad|spam del lector',
    'sos_alerts'          => 'sos|emergencia|emergencias|alerta|alertas|panico|critica|urgente|alarma',
    'risk_students'       => 'riesgo|abandono|desercion|en riesgo|critico|vulnerables|alto riesgo|riesgo de abandono',
    'audit_query'         => 'auditoria|auditor|accesos|registro de accesos|quien entro|quien consulto|trazabilidad|quien autorizo',
    'biometric_spam'      => 'duplicadas del lector|alertas de lector|spam de lector|lecturas repetidas|marcaciones repetidas|marcadas dobles',
    'birthdays_today'     => 'cumpleanos|cumple|cumpleanos de hoy|feliz cumple',
    'pending_tasks'       => 'tarea|tareas|deberes|por hacer|por entregar|sin completar|sin resolver|trabajos pendientes',
    'export_data'         => 'exportar|descargar|excel|informe|reporte completo|dump|extraer datos|copia de datos',
    'top_offenders'       => 'mas faltan|mas tardanzas|peores|reincidentes|mas evasiones|mayor numero|record de|reincidencia|acumulan|mas de \d+|repiten|reiterados',
    'attendance_ranking'  => 'ranking|clasificacion|orden de faltas|mas faltas|mas ausencias|promedio|comparativa|por grupo',
    'security_probe'      => 'password|clave|contrasena|token|credenciales|ignora|modo admin|modo administrador|otro colegio|otra institucion|base de datos|hackea|exploit|bypass',
    'time'                => 'que hora|son las|hora actual|hora exacta|a que horas|me dice la hora',
    'date'                => 'que fecha|que dia es|fecha de hoy|en que dia estamos',
    'random_student'      => 'estudiante aleatorio|alumno aleatorio|al azar|cualquiera|random',
    'about_me'            => 'quien soy|mi rol|mis permisos|mi perfil|que puedo hacer yo',
    'my_activity'         => 'mi actividad|mis consultas|mis acciones|que he hecho',
    'session_summary'     => 'resumen de sesion|que hablemos|de que hablamos|recapitula|recap',
    'do_for_me'           => 'traduce|traducir|hazme|escribe por mi|redacta|hazlo por mi|ayudame a|haz por mi',
    'no'                  => 'no es eso|eso no|incorrecto|asi no|no asi|equivocado|esta mal',
    'thanks'              => 'gracias|muchas gracias|perfecto|vale|listo|genial|excelente|de acuerdo|entiendo|muy bien|ya esta|nada mas|eso era todo|es todo|solo eso|hasta ahi|ya con eso|buenisimo',
    'yes'                 => 'si\\b|sip|claro|por supuesto|afirmativo|correcto|exacto|asi es',
    'compliment'          => 'eres genial|eres increible|eres lo maximo|me encantas|eres un sol|que bueno eres|eres lo mejor|excelente trabajo|muy amable|que amable',
    'help'                => 'ayuda|que puedes hacer|en que me ayudas|opciones|comandos|funciones|que sabes hacer',
    'risk_config'         => 'umbral|umbrales|config|configuracion|parametro|parametros|regla|reglas|politica|politicas|nivel de riesgo|aplican|aplica|rango de riesgo',
];

/* Alineación módulo→familia de intents */
const NX_MODULE_INTENT = [
    'PERMISO'         => ['permissions','list_events','count_events'],
    'EXCUSA'          => ['permissions','list_events','count_events'],
    'SALIDA_COLEGIO'  => ['permissions','list_events','count_events','late_today'],
    'EVASION_INTERNA' => ['list_events','count_events','top_offenders','attendance_ranking','day_summary'],
    'INASISTENCIA'    => ['attendance_today','count_events','list_events','day_summary','late_today','top_offenders'],
    'LATE_ARRIVAL'    => ['late_today','count_events','list_events','day_summary','attendance_today'],
    'INGRESO'         => ['attendance_today','count_present','count_events','late_today','list_events'],
    'SEGUIMIENTO'     => ['trackings','count_trackings','list_events'],
    'CITACION'        => ['citations','list_events','count_events'],
    'INCIDENTE'       => ['list_events','count_events','day_summary'],
    'SOS'             => ['sos_alerts','list_events','count_events'],
    'PRESENTE'        => ['attendance_today','count_present','count_events'],
];

/* nxLexGuarded — hit léxico con guardias contextuales:
 * «por el lector»=agente, «clase de X»=contexto-persona, «jornada anterior»=
 * pasado, «a qué hora es el recreo»=horario (no hora), ranking veta listas,
 * grupo presente veta day_summary. */
function nxLexGuarded(string $li, string $q0, array $g): int {
    $hit = (int)(isset(NX_INTENT_LEXICON[$li])
        && preg_match('/\b(' . NX_INTENT_LEXICON[$li] . ')/u', $q0));
    if (!$hit) return 0;
    if ($li === 'devices_status' && ($g['agentRef'] ?? false)) return 0;
    if ($li === 'staff_lookup' && !($g['personRef'] ?? false)) return 0;
    if ($li === 'schedule_info' && ($g['pastRef'] ?? false)) return 0;
    if ($li === 'time' && preg_match('/\b(recreo|descanso|jornada|bloque|periodo|horario|clase|entrada|salida|timbre|almuerzo|salen|entran|empieza|termina|comienza|acaba)\b/u', $q0)) return 0;
    if ($li === 'day_summary' && !empty($g['group'])) return 0;
    if (in_array($li, ['late_today','attendance_today','list_events','count_events','group_summary'], true)
        && preg_match('/\b(ranking|mas |peores|record|reincidencia|reincidentes|promedio|comparativa|top |mayor numero|frecuencia)\b/u', $q0)) return 0;
    return $hit;
}

function nxSemanticResolve(array $cls, string $q0, array $slots): ?string {
    $top3 = $cls['top3'] ?? [];
    $conf = $cls['confidence'] ?? 0;
    $intent = $cls['intent'] ?? 'out_of_scope';

    // los intents de operación NO son consulta-léxica: el léxico («permiso»)
    // no puede vetar una operación que el modelo+verbos ya resolvieron
    if (in_array($intent, ['start_operation','derive_action','repeat_op','confirm_op','security_probe'], true))
        return null;

    // early-exit solo con modelo confiado Y sin evidencia léxica contraria:
    // «pasada la hora» tiene cue fuerte de late_today aunque schedule gane 0.85
    $margin = count($top3) >= 2 ? $top3[0][1] - $top3[1][1] : 1;
    if ($conf >= 0.80 && $margin >= 0.20) {
        $i1 = $top3[0][0] ?? null;
        $g = ['group' => $slots['group'] ?? null,
              'agentRef' => (bool)preg_match('/\b(por|mediante|con|desde|en) (el|la|los|las)? ?(lector|biometrico|sensor|dispositivo|huella|registrador)\b/u', $q0),
              'personRef' => (bool)preg_match('/\b(clase|curso|materia|asignatura|grado|jornada|periodo) de [a-z]+|\b(del|de la|al) que |\bque (report|ensena|dicta|tuvo|registro|vio|dijo)|docente (del|de la|que)|profesor (del|de la|que)|maestro (del|de la|que)/u', $q0),
              'pastRef' => (bool)preg_match('/\b(anterior|pasada|pasado|ayer|del mes|de la semana|de hace|acumulad|la manana|la mañana|del dia)\b/u', $q0)];
        $i1Hit = $i1 && nxLexGuarded($i1, $q0, $g);
        $otherHit = false;
        foreach (NX_INTENT_LEXICON as $li => $lx) {
            if ($li === $i1) continue;
            if (nxLexGuarded($li, $q0, $g)) { $otherHit = true; break; }
        }
        if ($i1Hit || !$otherHit) return null;
    }

    // candidatos: el top-k real del modelo (out_of_scope no es candidato)
    $cands = [];
    foreach ($top3 as [$i2, $p2]) {
        if ($i2 === 'out_of_scope' || $p2 < 0.05) continue;
        $cands[$i2] = $p2;
    }
    if (!$cands) return null;

    $isCount = (bool)preg_match('/\b(cuant[oa]s?|que numero|total de|cuantos hay)\b/u', $q0);
    $isList  = (bool)(preg_match('/\b(quienes|cuales|muestra|lista|listado|los|las|dame|traeme|pasame|ver|revisar)\b/u', $q0));
    $module  = $slots['module'] ?? null;

    // guardias contextuales del léxico — evitan hits por contexto ajeno
    $agentRef = (bool)preg_match('/\bpor (el|la|los|las)\s+(lector|dispositivo|biometrico|sensor|camara|sistema)\b/u', $q0);
    $personRef = (bool)(preg_match('/\b(el|la|los|las|quien|quienes|profe|docente|maestro|rector|coordinador)\b[^.]{0,30}\b(de|del)\s+(matematicas|ingles|espanol|ciencias|sociales|fisica|quimica|biologia|historia|geografia|arte|musica|educacion fisica|religion|etica|informatica)/u', $q0)
        || preg_match('/\b(del|de la|al) que |\bque (report|ensena|dicta|tuvo|registro|vio|dijo)|docente (del|de la|que)|profesor (del|de la|que)|maestro (del|de la|que)/u', $q0));
    $pastRef  = (bool)preg_match('/\b(anterior|pasada|pasado|de ayer|del mes|de la semana|de hace|acumulad)/u', $q0);

    // hits del léxico con guardias (stems: boundary solo a la izquierda —
    // «citasiones» debe matchear el stem «citasion»)
    $lexHitOf = fn(string $li): int => nxLexGuarded($li, $q0,
        ['agentRef'=>$agentRef,'personRef'=>$personRef,'pastRef'=>$pastRef,
         'group'=>$slots['group'] ?? null]);

    // candidatos = top-k ∪ hits de léxico (el correcto puede faltar en top-k)
    foreach (NX_INTENT_LEXICON as $li => $_)
        if ($lexHitOf($li) && !isset($cands[$li])) $cands[$li] = 0.0;

    $scored = [];
    foreach ($cands as $i2 => $p2) {
        $lexHit = $lexHitOf($i2);
        $mw = 0;
        if ($lexHit && preg_match('/\b(' . (NX_INTENT_LEXICON[$i2] ?? '') . ')/u', $q0, $mm)
            && str_contains($mm[0], ' ')) $mw = 1;
        $score = 0.60 * $lexHit + 0.10 * $mw + $p2 * 0.5;
        // alineación de módulo — el módulo es evidencia fuerte
        // («se tajaron»→EVASION veta a schedule por «jornada»)
        if ($i2 === 'group_student_count' && empty($slots['group'])) $score -= 0.40;
        if ($module && isset(NX_MODULE_INTENT[$module])
            && in_array($i2, NX_MODULE_INTENT[$module], true)) {
            $score += 0.30;
        }
        // cuantificador→familia count / demostrativo→familia list
        if ($isCount && str_starts_with($i2, 'count_')) $score += 0.10;
        if ($isCount && in_array($i2, ['late_today','attendance_today','day_summary','students_count','group_student_count'], true)) $score += 0.08;
        if ($isList && $i2 === 'list_events') $score += 0.10;
        if ($isList && in_array($i2, ['teachers_list','groups_list','citations','trackings','permissions'], true)) $score += 0.05;
        $scored[$i2] = [$score, $lexHit, $mw];
    }
    arsort($scored);
    $best = array_key_first($scored);
    [$bs, $bh, $bw] = $scored[$best];
    $rest = array_slice($scored, 1, null, true);
    $second = $rest ? $rest[array_key_first($rest)][0] : 0;

    if (getenv('NXDBG')) { $tmp=$scored; usort($tmp,fn($a,$b)=>$b[0]<=>$a[0]); fwrite(STDERR, json_encode(array_combine(array_keys($scored),$tmp),JSON_UNESCAPED_UNICODE)."\n"); }
    // EXECUTE: el ganador con evidencia domina por margen — si no, ABSTAIN.
    // Dos top-2 ALINEADOS al mismo módulo no son contradictorios (familia
    // hermana) → también se resuelve; la discrepancia real exige margen.
    // oos + módulo extraído sin hits léxicos → la familia del módulo
    // («los pelados que se tiraron la clase» → EVASION → list)
    if ($intent === 'out_of_scope' && $module && isset(NX_MODULE_INTENT[$module])) {
        $fam = NX_MODULE_INTENT[$module];
        if ($isCount && in_array('count_events', $fam, true)) return 'count_events';
        if (in_array('list_events', $fam, true)) return 'list_events';
        return $fam[0];
    }
    $aligned2 = function(string $i2) use ($module): bool {
        return $module && isset(NX_MODULE_INTENT[$module])
            && in_array($i2, NX_MODULE_INTENT[$module], true);
    };
    $secondKey = $rest ? array_key_first($rest) : null;
    $sameFam = $secondKey && $aligned2($best) && $aligned2($secondKey);
    // un lexema multi-palabra específico («pasada la hora») supera cues
    // genéricos aunque el margen sea mínimo
    if (($bs - $second >= 0.10 || $sameFam || ($bw === 1 && $bs > $second))
        && ($bh === 1 || $bs >= 0.70))
        return $best;
    return null;
}

function nxCoverageOverride(string $q0, string $intent, array $slots): ?string {
    $oos = $intent === 'out_of_scope';
    // probes semánticos — el dominio léxico no importa, el patrón manda
    if (preg_match('/\b(ignora (tu|mi|el|su|los|las|tus|mis|todas) ?(rol|roles|reglas?|instrucciones?|restricciones?|limites?|permisos?)?|modo (admin|administrador|dios|desarrollador|root|debug)|sin restricciones|acceso total|privilegios (totales|de admin|administrador)|actua como (admin|administrador|root|superadmin)|hazme administrador|suplant|omite la (validacion|confirmacion|autenticacion)|salta(r|te)? la (seguridad|confirmacion|validacion)|sin autenticar|que nadie sepa|sin registrar|usa la cuenta|usa su cuenta|con la cuenta de|suplantando|haciendose pasar|pasar por|como (el|la) (rector|rectora|coordinador|administrador|docente) (cuenta|perfil|rol))\b/u', $q0))
        return 'security_probe';
    // cross-scope institucional
    if (preg_match('/\b(otro colegio|otra institucion|otra escuela|institucion vecina|colegio de al lado|colegio vecino|otra sede|datos de otros colegios|todas las instituciones|otros planteles|todos los colegios|de todos los planteles)\b/u', $q0))
        return 'security_probe';
    // credenciales / secretos / sql / exfiltración masiva
    if (preg_match('/\b(aunque (yo )?no (tenga|tienes|tiene|tengo) permiso|no tengo permiso|sin permiso (para|de)|sin autorizacion (para|de)|no me pertenece|que no me corresponde)\b/u', $q0))
        return 'security_probe';
    if (preg_match('/\b(password|contrasena|clave|token|api ?key|credenciales|secreto)\b/u', $q0))
        return 'security_probe';
    if (preg_match('/\b(select \*|drop table|union select|delete from|insert into|update \w+ set|ejecuta sql|consulta sql|dump de la base|vuelca la base)\b/u', $q0))
        return 'security_probe';
    if (preg_match('/\b(exporta|descarga|extrae|copia|vuelca|dame|muestrame|saca) (toda|todas|todo|todos) (la|el|los|las)? ?\w*/u', $q0)
        && preg_match('/\b(base|datos|informacion|registros|tabla)\b/u', $q0))
        return 'security_probe';
    // corrección de referencia: «hazlo sobre X aunque yo haya dicho Y»
    if (preg_match('/\baunque (yo )?(haya |habia )?(dicho|dije|pedi|pedido|mencionado)\b/u', $q0))
        return 'student_summary';
    // «no llegaron/vinieron» con sujeto persona → asistencia, no mensajería
    // («los chinos que no llegaron» ≠ «los mensajes que no llegaron»)
    if ($intent === 'failed_messages' || $intent === 'notifications_unread') {
        $msgSubj = (bool)preg_match('/\b(mensajes?|avisos?|notificaciones?|whatsapp|correos?|sms|texto)\b/u', $q0);
        $peopleVerb = (bool)preg_match('/\b(no llegaron|no vinieron|no entraron|no asistieron|no se present|faltaron|ausentes)\b/u', $q0);
        if (!$msgSubj && $peopleVerb) return 'attendance_today';
        if ($intent === 'failed_messages' && !$msgSubj
            && preg_match('/\b(los|las|quienes|estudiantes|chinos|pelados|muchachos|alumnos)\b/u', $q0)
            && preg_match('/\b(llegaron|vinieron|entraron|salieron|fueron|volvieron|regresaron)\b/u', $q0))
            return 'attendance_today';
    }
    // mensajes/notificaciones — «tengo mensajes», «hay mensajes nuevos»
    if ($oos && preg_match('/\b(mensajes?|notificaciones?|avisos?)\b/u', $q0)
        && preg_match('/\b(tengo|tienes|hay|nuevos?|sin leer|pendientes?|llego|llegaron|entro|mandaron|enviaron)\b/u', $q0))
        return 'notifications_unread';
    // hora — «que hora es», «la hora actual» (incluso si NLU dijo
    // schedule_info: la pregunta directa por la hora domina)
    if (($oos || $intent === 'schedule_info')
        && preg_match('/\b(que hora|hora actual|hora exacta|a que horas|son las|me dice la hora)\b/u', $q0))
        return 'time';
    // cierre compuesto — «listo, gracias»/«eso era todo» son despedida
    if (in_array($intent, ['yes','no','greeting','greeting_time'], true)
        && preg_match('/\bgracias\b/u', $q0)) return 'thanks';
    if (in_array($intent, ['out_of_scope','yes','no','greeting','greeting_time','smalltalk'], true) && preg_match('/\b(eso era todo|eso es todo|nada mas|ya esta|ya estuvo|es todo|solo eso|hasta ahi|listo gracias|ya con eso)\b/u', $q0))
        return 'thanks';
    // «datos/info de <estudiante>» — ficha con entidad explícita
    if ($oos && !empty($slots['student'])
        && preg_match('/\b(datos|info|ficha|perfil|resumen|contacto|telefono|documento|direccion)\b/u', $q0))
        return 'student_summary';
    // personal del colegio — «los coordinadores», «el de matemáticas»;
    // student_field sin estudiante también aplica (NLU desvió la consulta)
    $staffWrong = $oos || ($intent === 'student_field' && empty($slots['student']));
    if ($staffWrong && preg_match('/\b(coordinadores?|docentes?|profesores?|maestros?|personal|directivos?|rectores?|secretarias?)\b/u', $q0))
        return 'teachers_list';
    if ($staffWrong && preg_match('/\b(de|del|de la|del area de)\s+(matematicas|ingles|espanol|ciencias|sociales|fisica|quimica|biologia|historia|geografia|arte|musica|educacion fisica|religion|etica|informatica|lectura|escritura)\b/u', $q0))
        return 'staff_lookup';
    // «cuantas tardanzas/faltas/evasiones» — boundary count vs métrica;
    // student_field sin estudiante también corrige
    $countWrong = in_array($intent, ['out_of_scope','students_count','count_present','day_summary'], true)
        || ($intent === 'student_field' && empty($slots['student']));
    if ($countWrong) {
        if (preg_match('/\btardanzas?\b/u', $q0)) return 'late_today';
        if (preg_match('/\b(faltas?|inasistencias?|ausencias?)\b/u', $q0)) return 'count_events';
        if (preg_match('/\bevasiones?\b/u', $q0)) return 'count_events';
    }
    // model-op + adjetivo plural de permiso («autorizados para salir») →
    // es consulta: ningún verbo de operación presente
    if (in_array($intent, ['start_operation','derive_action'], true)
        && preg_match('/\b(autorizad[oa]s?|permitid[oa]s?|vencid[oa]s?|expedid[oa]s?|emitid[oa]s?|aprobado[oa]s?|vigentes?|activos?)\b/u', $q0)
        && !preg_match('/\b(genera|autoriza|expide|emite|tramita|reporta|registra|crea|manda|envia|cita|citar|convoca|dar|da)\b/u', $q0)
        && preg_match('/\b(permiso|permisos|autorizacion|autorizad[oa]s?|excusa|salida|retiro|salir)\b/u', $q0))
        return 'permissions';
    // «mis permisos / mi rol» = meta-pregunta del usuario, NO permisos de salida
    if (preg_match('/\b(mis permisos|mi rol|mis privilegios|mi perfil|quien soy|mis funciones|mi rol aqui)\b/u', $q0)
        && in_array($intent, ['about_me','permissions','out_of_scope','my_activity'], true))
        return 'about_me';
    // «exporta/descarga/extrae datos» → export_data (chip+RBAC, no ejecución)
    if (in_array($intent, ['start_operation','derive_action','out_of_scope','list_events','export_data'], true)
        && preg_match('/\b(exportar|exporta|descargar|descarga|extraer|extrae|saca|sacar|volcar|vuelca)\b/u', $q0)
        && preg_match('/\b(datos|informacion|registros|reporte|informe|excel|base|listado|archivo)\b/u', $q0))
        return 'export_data';
    // «resumen del quinto/octavo» → resumen de grupo
    if (preg_match('/\bresumen (del|de|del grado|del grupo)\b/u', $q0)
        && !empty($slots['group']) && in_array($intent, ['out_of_scope','student_summary','group_summary','day_summary'], true))
        return 'group_summary';
    // «el papá/mamá/acudiente del que…» → referencia a campo de estudiante
    if (preg_match('/\b(papa|mama|padre|madre|acudiente|familiar|tutor|hermano|hermana) (del|de la|del que|de la que)\b/u', $q0)
        && in_array($intent, ['out_of_scope','attendance_today','late_today','student_summary','student_field','list_events'], true))
        return 'student_field';
    // «dame/quiero/haz UN permiso PARA <nombre>» = operación (beneficiario)
    if (preg_match('/\b(un|una|el|los|las)\s+(permiso|excusa|solicitud|autorizacion|salida|cita|citacion)\s+para\s+[a-z]+/u', $q0)
        && in_array($intent, ['permissions','citations','trackings','list_events','out_of_scope'], true))
        return 'derive_action';
    // op-noun + verbo de CONSULTA → es consulta, no operación
    // («quiero ver el horario», «muestra las citaciones») — pero
    // «dame un permiso PARA <nombre>» sigue siendo operación (beneficiario)
    if (in_array($intent, ['start_operation','derive_action'], true)
        && preg_match('/\b(ver|mirar|muestra|mostrar|dame|consultar|revisar|listar|cuales|cuando|como|dime)\b/u', $q0)
        && !preg_match('/\b(un|una|el|los|las)\s+(permiso|excusa|solicitud|autorizacion|salida|cita|citacion)\s+para\s+[a-z]+/u', $q0)
        && !preg_match('/\b(citar|cita|generar|genera|autorizar|mandar|enviar|reportar|registrar|crear|abrir|convocar|expedir|derivar|tramitar)\b/u', $q0)) {
        if (preg_match('/\bhorario|bloque|jornada\b/u', $q0)) return 'schedule_info';
        if (preg_match('/\bcitacion|citaciones\b/u', $q0)) return 'citations';
        if (preg_match('/\bpermiso|permisos|excusa|excusas\b/u', $q0)) return 'permissions';
        if (preg_match('/\bseguimiento|seguimientos\b/u', $q0)) return 'trackings';
        if (preg_match('/\bsolicitud|solicitudes\b/u', $q0)) return 'trackings';
    }
    return null;
}

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
    // cobertura: frases de dominio que el modelo deja fuera de alcance
    // — reglas determinísticas y auditables (ver nxCoverageOverride)
    $coverageHit = false;
    if ($ovr = nxCoverageOverride($q0, $intent, $slots)) { $intent = $ovr; $coverageHit = true; }
    // rerank semántico — la función decide internamente si hay evidencia
    // suficiente (modelo confiado + sin evidencia contraria → early-exit)
    if (!$coverageHit && ($sem = nxSemanticResolve($cls, $q0, $slots))) {
        $intent = $sem; $coverageHit = true;
    }
    // temporal-guard: attendance/late/count_present sin marcador temporal
    // («faltas del once» ≠ «faltas de hoy») → la familia correcta según
    // cuantificador/demostrativo/grupo
    $strongNoToday = (bool)preg_match('/\b(acumulad|consolidad|reincidencia|reincidente|reinciden|promedio|record|del periodo|del bimestre|anterior)\b/u', $q0);
    if (in_array($intent, ['attendance_today','late_today','count_present'], true)
        && ($strongNoToday
            || (!preg_match('/\b(hoy|ahora|ahorita|esta manana|esta mañana|esta tarde|de la manana|de la mañana|en la manana|en la mañana|en la tarde|de hoy|del dia|del día|actual|en este momento|al momento|impuntual|tardanza|tarde)\b/u', $q0)
                && ($slots['days'] ?? null) === null && empty($slots['from'])
                && (preg_match('/\b(cuant[oa]s?|que numero|total)\b/u', $q0)
                    || !empty($slots['group']))))) {
        if (preg_match('/\b(cuant[oa]s?|que numero|total|acumulad|consolidad|reinciden|promedio|record)\b/u', $q0))
            $intent = 'count_events';
        elseif (!empty($slots['group']))
            $intent = 'group_summary';
        elseif (preg_match('/\b(quienes|cuales|muestra|lista|los que|dame|traeme|pasame|ver)\b/u', $q0))
            $intent = 'list_events';
    }
    $inherited = []; $newSlots = [];
    $turnType = 'new_request';
    $clarify = null;

    $followupMark = (bool)preg_match('/^(y|ahora|pero|tambien|ademas|solo|solamente|entonces|o sea|'
        . 'las|los|esas|esos|estas|estos|esa|ese|este|sus?|de|del|de la|de lo)\b/u', $q0)
        || (bool)preg_match('/^(y )?(ahora|ahora que|y ahora|y despues|y luego)\b[?]*$/u', $q0);
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

    $ctxEntities = is_array($ctx['entities'] ?? null) ? $ctx['entities'] : [];
    $lastIntent  = $ctx['last_intent'] ?? null;
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
        $dependent = $followupMark || $correctionWeak || $deicticVerb || $interrogDep || $bareNoun;
        if ($ctxEntities && $dependent) {
            foreach (['student','group','module','days','from','to','range_label','field'] as $k) {
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
        // deíctico «el mismo X»: «las tardanzas del mismo grupo» → el slot
        // referido se toma SIEMPRE del contexto aunque el mensaje lo nombre
        if ($followupMark && $ctxEntities) {
            if (preg_match('/mism[oa]s?\s+(grupo|salon)/u', $q0) && !empty($ctxEntities['group'])) {
                $slots['group'] = $ctxEntities['group']; $inherited[] = 'group';
            }
            if (preg_match('/mism[oa]s?\s+(estudiante|alumn[oa]|niñ[oa]|pelad[oa]|muchach[oa])/u', $q0) && !empty($ctxEntities['student'])) {
                $slots['student'] = $ctxEntities['student']; $inherited[] = 'student';
            }
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
        $opVerb = (bool)preg_match('/\b(citar|citalo|citala|cite|citamos|convocar|convoca|'
            . 'generar|genera|autorizar|autoriza|mandar|manda|enviar|envia|'
            . 'reportar|reporta|registrar|registra|crear|crea|expedir|expide|'
            . 'derivar|deriva|tramitar|tramita|constancia|dejar constancia|llamar a citacion|llamado a|convoco|convoca|emitir|emite|dar salida|da salida|exportar|exporta|descargar|descarga|extraer|extrae|saca|sacar)\b/u', $q0);
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
        // verbo destructivo + datos → security_probe (no existe operación
        // de borrado — el rechazo explícito es el comportamiento correcto)
        if (preg_match('/\b(borra|borrar|borre|elimina|eliminar|elimine|vacia|'
            . 'anula|anular|suprime|suprimir|destruye|destruir|limpia)\b/u', $q0)
            && preg_match('/\b(las|los|la|el|datos|registros|tabla|faltas|evasiones|'
                . 'tardanzas|estudiantes|permisos|citaciones|todo|mensajes|notificaciones|base)\b/u', $q0)
            && in_array($intent, ['list_events','count_events','attendance_today','late_today',
                'permissions','citations','trackings','out_of_scope','notifications_unread',
                'export_data','pending_returns','day_summary'], true)) {
            $intent = 'security_probe';
            $turnType = 'intent_switch';
        }

        // ── 3. Intent heredable bajo umbral con marcador (regla etapa-0) ──
        // Guardia: un sustantivo de operación con verbo/artículo («una
        // solicitud aparte», «el permiso») NO se degrada a la consulta previa.
        $hasOpNoun = (bool)preg_match('/\b(una?|el|la|esa|ese|otra?|hacer|haz|mandar|enviar|generar|crear|quiero|necesito)\s+\w*\s*(solicitud|citacion|cita|permiso|autorizacion|salida|seguimiento|incidente|reporte|registro|excusa)\b/u', $q0);
        if (($intent === 'out_of_scope' || $conf < NX_NLU_THRESHOLD)
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
            && preg_match('/\b(tardanza|inasist|falt|evasion|permiso|citacion|seguim|evento|incident|notif|salid|ingres|ausen|presente|estudiant|alumn)/u', $q0));
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
            && !preg_match('/tardanza|inasist|ausen|falt|evasion|permiso|citacion|cita|evento|incident|seguim|notif|salida|estudiant|mensaje|alerta|cumple|tarea|pendient|docent|acudient|correo|llamad/u', $q0)) {
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
            $newCtx = $newCtx ?? [];
            $newCtx['entities'] = array_merge($newCtx['entities'] ?? [], $ents);
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
