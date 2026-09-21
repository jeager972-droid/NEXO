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
    } elseif (preg_match('/este mes|del mes|en el mes|ultimo mes/u', $q)) {
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
            || preg_match('/\b(?:grado|grupo|salon)\s+(' . implode('|', array_keys($ord)) . ')\b/u', $q, $mo)) {
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
        'venidera','entrante','corriente'];
    $boundary = '(?:\s+(?:del|de|en|grupo|salon|durante|en los|en las|hoy|ayer|esta|ultimos|en el|por|que|y)\b|$)';
    $cands = [];
    foreach ([
        '/(?=(?:estudiante|alumno|alumna|nino|nina)\s+([a-z]+(?:\s+[a-z]+){0,3})' . $boundary . ')/u',
        '/(?=\b(?:de|del|sobre|para|a|tenido|tuvo|tiene|tienen|sido|hizo|estado|estuvo|hecho|falto|faltaron|llego|entro|salio|capo|volo|evadio|evadieron|caparon|volaron|volado|capado)\s+([a-z]+(?:\s+[a-z]+){0,3})' . $boundary . ')/u',
    ] as $pat) {
        preg_match_all($pat, $q, $mm, PREG_OFFSET_CAPTURE);
        foreach ($mm[1] ?? [] as $cand) {
            $words = array_values(array_filter(
                explode(' ', trim($cand[0])),
                fn($w) => !in_array($w, $stop) && mb_strlen($w) > 1));
            if ($words) $cands[] = implode(' ', $words);
        }
    }
    return $cands ? end($cands) : null;
}

function nxModuleSynonyms(): array {
    return [
        'LATE_ARRIVAL'      => ['llegadas tarde','llegada tarde','tardanzas','tardanza','tarde','llego tarde','llegaron tarde','tardes'],
        'INASISTENCIA'      => ['inasistencias','inasistencia','faltas','falta','ausencias','ausencia','no vinieron','no vino','faltaron','falto','ausentes','ausente'],
        'INASISTENCIA_JUSTIFICADA'    => ['inasistencias justificadas','justificadas','faltas justificadas'],
        'INASISTENCIA_NO_JUSTIFICADA' => ['inasistencias no justificadas','sin justificar','injustificadas'],
        'EVASION_INTERNA'   => ['evasiones internas','evasion interna','evasiones','evasion','fugas','fuga','se salieron','se salio','escaparon','escapo','salio del salon','abandono la clase','abandonaron clase'],
        'PERMISO'           => ['permisos','permiso','salidas autorizadas'],
        'SALIDA_BAÑO'       => ['salidas al bano','bano','banos','salidas de bano'],
        'SALIDA_COLEGIO'    => ['salidas del colegio','salida del colegio','salidas anticipadas','salio del colegio'],
        'SOS'               => ['sos','alertas sos','panico','emergencias','emergencia'],
        'CITACION'          => ['citaciones','citacion','citas a acudientes','mensajes a acudientes'],
        'SEGUIMIENTO'       => ['seguimientos','seguimiento','casos','caso','derivaciones'],
        'INCIDENTE'         => ['incidentes','incidente','reportes disciplinarios','disciplina','situaciones criticas','situacion critica'],
        'DAÑO'              => ['danos','dano','danios reportados'],
        'INGRESO'           => ['ingresos','entradas','llegaron','entradas del dia'],
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
