<?php
/* ============================================================================
 * nexus_scp.php — Semantic Conversational Parsing (capa de significado).
 *
 * Posición en el pipeline: mensaje → NLU (cls) → DSM (interp) → **SCP** →
 * planificador/executors. El SCP NO ejecuta nada ni inventa datos: produce
 * un FRAME semántico normalizado y validable que el pipeline existente
 * consume vía nxScpToSlots() (refinado de intent+slots) o nxScpToPlan()
 * (plan estructurado para rank/compare/correcciones — pasa por
 * nxPlanValidate + nxPlanAllowed como cualquier otro plan).
 *
 * Reglas fundamentales implementadas aquí:
 *  - explícito > referencia > ítem activo > herencia (prioridad de sujeto)
 *  - el turno que no redefine contexto hereda el activo (tiempo/alcance)
 *  - referencias («su», «ese», «el primero», «los demás») se resuelven
 *    contra memoria conversacional / result-set activo — nunca re-preguntar
 *    lo ya resuelto
 *  - correcciones actualizan el plan activo, no reinician la conversación
 *  - objetivos múltiples se conservan todos (multi-target)
 * ========================================================================== */

/* ---------------------------------------------------------------------------
 * Taxonomía de tareas — explícita, examinable, con evidencia por señal.
 * lookup | count | aggregate | compare | rank | filter | relation
 * navigate | transform | general | out_of_scope | correct | clarify
 * ------------------------------------------------------------------------- */
function nxScpTask(string $q0, array $slots, ?array $sig, ?array $ds): array {
    $t = 'lookup'; $e = [];

    // — corrección del usuario (siempre gana: es meta-conversación) —
    if (preg_match('/\b(me refiero|no me refiero|no esos|no esas|no era|no eran|no es |no son |'
        . 'te falt(ó|o)|se te olvid(ó|o)|se me pas(ó|o)|te olvidaste|'
        . 'no ser(ía|ia) empate|ser(ía|ia) que ninguna'
        . '|mejor (dame|muestrame)|no,? mejor)\b/u', $q0)) { $t = 'correct'; $e[] = 'lex:correction'; }

    // — ranking: superlativo sobre métrica, con o sin «top N» —
    if ($t !== 'correct'
        && (preg_match('/\btop\s*\d*|\blos? (que )?(m[áa]s|mayor|mayores|peores)|\branking\b|\breinciden|\blidera|\bencabeza|\bel mayor n[úu]mero de|\bpeores\b|\bmayores ofensores\b|\bque m[áa]s (han\s+)?(faltado|faltaron|faltan|falta|faltas|tardanzas|inasistencias|ausencias|evasiones|incidentes|llegadas)/u', $q0)
            || preg_match('/\bcu[áa]l(es)? (grupo|estudiante|estudiantes|curso)\b.{0,30}\bm[áa]s\b/u', $q0)
            || preg_match('/\blos? \d+ (peores|mayores|con m[áa]s)\b/u', $q0))) {
        $t = 'rank'; $e[] = 'lex:rank';
    }

    // — comparación: dos grupos/métricas frente a frente —
    if ($t === 'lookup'
        && (preg_match('/\b(vs|versus|contra|compar[ae]|diferencia entre)\b/u', $q0)
            || preg_match('/\bqu[ée]\s+(grupo|curso)\s+tiene\s+m[áa]s\b[^?]*\b(o|y)\b/u', $q0))) {
        $t = 'compare'; $e[] = 'lex:compare';
    }

    // — conteo (de colección o de una persona: «cuánto ha faltado Juan») —
    // Guarda: «el número de celular del acudiente» pide un DATO de
    // persona, no una agregación — campo de persona presente ≠ conteo.
    $personField = (bool)preg_match('/documento|telefono|celular|correo|direccion|whatsapp|contacto|acudiente|tutor|profesor|docente/u', (string)($slots['field'] ?? ''));
    if ($t === 'lookup' && !$personField
        && preg_match('/\b(cu[áa]nt[oa]s?|cu[áa]nto|n[úu]mero de|total de|ha faltado|han faltado)\b/u', $q0)) {
        $t = 'count';
        $e[] = !empty($slots['student']) ? 'lex:count_person' : 'lex:count';
    }

    // — seguimiento que solo cambia módulo/estado/tiempo: hereda la tarea
    // activa («¿y llegadas tarde?» tras «cuántas evasiones…»). Guarda: solo
    // frases CORTAS de continuación — una pregunta completa («cuáles son
    // los estudiantes que más faltas…») es una consulta nueva, no un
    // seguimiento. Un campo relacional en el turno NO es un conteo —
    // Un ordinal posicional («la primera tardanza») tampoco: es POSICIÓN
    // sobre la serie, no un conteo — salvo ordinal+unidad temporal (rango)
    $posRef = preg_match('/\b(el|la|del|de|los|las)\s*(primer[oa]?|segund[oa]?|tercer[oa]?|'
        . 'cuart[oa]?|quint[oa]?|últim[oa]|ultim[oa]|penúltim[oa]|penultim[oa])\b/u', $q0)
        && !preg_match('/\b(primer[oa]?|segund[oa]?|tercer[oa]?|cuart[oa]?|quint[oa]?|últim[oa]|ultim[oa]|penúltim[oa]|penultim[oa])\s+(de\s+)?(d[ií]a|d[ií]as|mes|meses|semana|semanas|a[ñn]o|a[ñn]os|lunes|martes|mi[eé]rcoles|jueves|viernes|s[áa]bado|domingo|periodo|bimestre|trimestre|cuatrimestre|semestre)\b/u', $q0);
    if ($t === 'lookup' && empty($slots['field']) && !$posRef
        && (str_starts_with($q0, 'y ') || mb_substr_count(trim($q0), ' ') <= 5)
        && isset($slots['_resolved_intent'])
        && (in_array($slots['_resolved_intent'], ['count_events','list_events'], true)
            || in_array($ds['intent'] ?? null, ['count_events'], true))
        && (isset($slots['module']) || isset($slots['days']) || isset($slots['from'])
            || !empty($sig['filters']['module'] ?? null))) {
        $t = (in_array($slots['_resolved_intent'], ['count_events'], true)
              || ($ds['intent'] ?? null) === 'count_events') ? 'count' : 'lookup';
        $e[] = 'inherit:module_followup';
    }

    // — agregación —
    if ($t === 'lookup' && preg_match('/\b(porcentaje|promedio|proporci[óo]n|tasa)\b/u', $q0)) {
        $t = 'aggregate'; $e[] = 'lex:aggregate';
    }

    // — relación: campo relacional sobre un sujeto —
    if ($t === 'lookup' && !empty($sig['relation'])) { $t = 'relation'; $e[] = 'sig:relation'; }

    // — relación por campo de persona con sujeto presente o dominio
    // guardian («celular del acudiente Juan Camilo», «y su documento») —
    if ($t === 'lookup' && !empty($slots['field'])
        && preg_match('/^(acudiente|celular|celular_acudiente|documento_acudiente|documento|'
            . 'telefono|whatsapp|grupo|jornada|nombre|edad|nacimiento)$/u', (string)$slots['field'])
        && (!empty($slots['student']) || !empty($sig['relation']) || ($sig['entity'] ?? null) === 'guardians'
            || preg_match('/\b(acudiente|representante|responsable|tutor)\b/u', $q0))) {
        $t = 'relation'; $e[] = 'relation:field_person';
    }

    // — filtro por umbral de riesgo: «pasado mi umbral», «en riesgo» —
    if ($t === 'lookup' && preg_match('/\b(umbral|pasado mi|en riesgo|alerta pedag|supera(mi| el)|por encima del)\b/u', $q0)) {
        $t = 'filter'; $e[] = 'lex:threshold';
    }

    // — navegación posicional / transformación sobre el set activo —
    if ($t === 'lookup' && !empty($slots['_nav'])
        && preg_match('/^(nth:\d+|first|last|next|prev|rest|all|count|name|'
            . 'slice:\d+:(start|end)|goto:\d+|table|proj:|sort:)/u', (string)$slots['_nav'])) {
        $t = (preg_match('/^(slice:|proj:|sort:|table)/u', (string)$slots['_nav'])) ? 'transform' : 'navigate';
        $e[] = 'nav:' . $slots['_nav'];
    }

    // — relación por campo+referencia: «acudiente del primero/ese último» —
    // la posición materializa el ítem y el campo corre sobre él; es
    // relación, no navegación cruda
    if ($t === 'navigate' && !empty($slots['field'])
        && !empty($slots['_nav']) && preg_match('/^(nth:\d+|first|last)$/u', (string)$slots['_nav'])) {
        $t = 'relation'; $e[] = 'relation:nav_field';
    }

    // — transformación pura sin _nav detectado —
    if ($t === 'lookup' && preg_match('/\b(en|de|como|hazme|ponme|pasame)\s+(una\s+)?(tabla|cuadro)|solo nombres|'
        . 's[óo]lo (los )?(primeros?|[úu]ltimos?|ultimos?)\s*\d*|agrega\s+(documento|tel|cel|grupo)/u', $q0)) {
        $t = 'transform'; $e[] = 'lex:transform';
    }

    // — general / fuera de dominio: lo decide el NLU, el frame lo registra —
    if ($t === 'lookup') {
        $nluIntent = $slots['_nlu_intent'] ?? null;
        if (in_array($nluIntent, ['greeting','smalltalk','joke','thanks','goodbye','wellbeing',
            'human_check','about_nexus','about_me','motivation','food_music','love','compliment',
            'emotion_sad','wellbeing_reply','name_meaning','colombia_history','foreign_culture'], true)) {
            $t = 'general'; $e[] = 'nlu:general';
        }
    }
    return [$t, $e];
}

/* ---------------------------------------------------------------------------
 * Correcciones — cada tipo produce una actualización del plan/contexto.
 * ------------------------------------------------------------------------- */
function nxScpCorrections(string $q0, array $slots): array {
    $c = [];
    // «no, me refiero a X» / «me refiero al de X» — reemplazo de sujeto
    if (preg_match('/\b(me refiero|no me refiero)\b.{0,6}\b(a|al|a la)\s+(.+)/u', $q0, $m))
        $c[] = ['kind'=>'replace_subject', 'value'=>trim($m[3]), 'evidence'=>'lex:referir'];
    // «no esos» — exclusión del set mostrado
    if (preg_match('/^no,?\s+(esos|esas|ellos|esos no|no esos)\b/u', $q0))
        $c[] = ['kind'=>'exclude_active', 'evidence'=>'lex:no_esos'];
    // «solo cinco» / «solo 5» — recorte del resultado activo
    if (preg_match('/\bsolo\s+(cinco|diez|dos|tres|cuatro|seis|siete|ocho|nueve|veinte|\d+)\b/u', $q0, $m))
        $c[] = ['kind'=>'limit', 'value'=>nxScpWordNum($m[1]), 'evidence'=>'lex:solo_N'];
    // «te faltó lo otro» — objetivo compuesto pendiente
    if (preg_match('/\b(te falt(ó|o)|te olvidaste|lo otro|la otra parte|la otra cosa|falt(ó|o) el otro)\b/u', $q0))
        $c[] = ['kind'=>'pending_target', 'evidence'=>'lex:falto_lo_otro'];
    // «no sería empate, sería que ninguna» — corrección de interpretación
    if (preg_match('/\bno\s+ser[íi]a\s+empate\b.{0,40}\b(ninguna|ninguno|que ninguna|que ninguno)\b/u', $q0))
        $c[] = ['kind'=>'none_of', 'evidence'=>'lex:no_empate_ninguna'];
    // «no, eran las faltas» — reemplazo de la métrica/módulo activo
    if (preg_match('/\bno,?\s+(?:ser[íi]an|ser[íi]a|eran|era|son|es)\s+(?:las|los|unas|unos|la|lo|el|del|de|en|una|un)?\s*([a-záéíóúñü]+(?:\s+[a-záéíóúñü]+){0,2})/u', $q0, $mm)
        && !preg_match('/\bempate\b/u', $q0)
        && !preg_match('/^(que|ningun|eso|esa|ese|esto|esta|este)\b/u', $mm[1]))
        $c[] = ['kind'=>'replace_metric', 'value'=>trim($mm[1]), 'evidence'=>'lex:no_era_metric'];
    // «de mi clase» — refinamiento de alcance
    if (preg_match('/\bde mi (clase|grupo|curso|sal[óo]n)\b/u', $q0))
        $c[] = ['kind'=>'refine_scope', 'value'=>'teacher_scope', 'evidence'=>'lex:mi_clase'];
    return $c;
}

/** numerales en palabra → valor (para límites de ranking/slice). */
function nxScpWordNum(string $w): ?int {
    $map = ['dos'=>2,'tres'=>3,'cuatro'=>4,'cinco'=>5,'seis'=>6,'siete'=>7,'ocho'=>8,
            'nueve'=>9,'diez'=>10,'quince'=>15,'veinte'=>20,'treinta'=>30];
    return ctype_digit($w) ? (int)$w : ($map[trim($w)] ?? null);
}

/* ---------------------------------------------------------------------------
 * Sujeto + referencias — prioridad: explícito > posicional > anafórico >
 * ítem activo > herencia. Nunca re-preguntar lo ya resuelto.
 * ------------------------------------------------------------------------- */
function nxScpSubject(array $q0slots, ?array $ds, string $q0, ?string $task = null): array {
    $subj = ['entity'=>null,'name'=>null,'id'=>null,'source'=>'none','evidence'=>[]];
    $refs = [];

    // 1) explícito por nombre/documento en el turno — domina sobre todo
    if (!empty($q0slots['student'])) {
        $subj['entity'] = 'student'; $subj['name'] = $q0slots['student'];
        // distinguir extraído del turno vs heredado del contexto: la
        // entidad explícita domina, la heredada es respaldo
        $subj['source'] = in_array('student', $q0slots['_inherited'] ?? [], true)
            ? 'inherited' : 'explicit';
        $subj['evidence'][] = 'slot:student(' . $subj['source'] . ')';
    }

    // 1b) «celular del acudiente Juan Camilo Ospina García» — el nombre
    // propio tras «acudiente (de)» es EXPLÍCITO y domina sobre cualquier
    // herencia: nunca re-preguntar lo que el usuario ya nombró (caso K)
    if (in_array($subj['source'], ['none','inherited'], true)
        && preg_match('/\b(?:acudiente|representante|responsable)\s+(?:de\s+|del\s+)?([a-záéíóúñü]+(?:\s+[a-záéíóúñü]+){1,3})\s*[.!?]*$/u', $q0, $m)
        && !preg_match('/\b(este|ese|aquel|primero|segundo|tercero|ultimo|el|la)\b/u', $m[1])) {
        $cand = trim($m[1]);
        if (mb_strlen($cand) >= 6) {
            $subj['entity'] = 'student'; $subj['name'] = $cand;
            $subj['source'] = 'explicit'; $subj['qualifies'] = 'guardian';
            $subj['evidence'][] = 'lex:acudiente_nombre→explicit';
        }
    }

    // 2) referencia posicional sobre el set activo («el primero/último/segundo»)
    // — domina sobre la herencia: el usuario apunta, no repite tema
    $rsItems = $ds['last_result']['items'] ?? [];
    if ((!$subj['name'] || $subj['source'] === 'inherited') && $rsItems && preg_match('/\b(el|la|del|de)\s*(primer[oa]?|segund[oa]?|tercer[oa]?|'
        . 'cuart[oa]?|quint[oa]?|últim[oa]|ultim[oa]|penúltim[oa]|penultim[oa]|anterior|siguiente)\b/u', $q0, $m)
        // ordinal + unidad temporal = RANGO, no posición («del último mes»)
        && !preg_match('/\b(primer[oa]?|segund[oa]?|tercer[oa]?|cuart[oa]?|quint[oa]?|últim[oa]|ultim[oa]|penúltim[oa]|penultim[oa]|anterior|siguiente)\s+(de\s+)?(d[ií]a|d[ií]as|mes|meses|semana|semanas|a[ñn]o|a[ñn]os|lunes|martes|mi[eé]rcoles|jueves|viernes|s[áa]bado|domingo|periodo|bimestre|trimestre|cuatrimestre|semestre)\b/u', $q0)) {
        $ord = ['primero'=>0,'primer'=>0,'primera'=>0,'segundo'=>1,'segunda'=>1,'tercero'=>2,'tercera'=>2,
                'cuarto'=>3,'cuarta'=>3,'quinto'=>4,'quinta'=>4,'último'=>-1,'ultimo'=>-1,'última'=>-1,'ultima'=>-1,
                'penúltimo'=>-2,'penultimo'=>-2,'anterior'=>-2,'siguiente'=>null];
        $k = mb_strtolower($m[2]);
        if (array_key_exists($k, $ord)) {
            $idx = $ord[$k];
            if ($idx === null) $idx = (int)($ds['cursor'] ?? 0) + 1;
            $item = $idx >= 0 ? ($rsItems[$idx] ?? null) : ($rsItems[max(0, count($rsItems)+$idx)] ?? null);
            if ($item) {
                $subj['entity'] = 'student'; $subj['name'] = $item['label']; $subj['id'] = $item['id'] ?? null;
                $subj['source'] = 'reference_positional';
                $subj['evidence'][] = "ref:positional({$k})→rs[" . ($idx >= 0 ? $idx : 'last') . "]";
                $refs[] = ['type'=>'positional','text'=>$k,'resolves_to'=>'last_result['.($idx>=0?$idx:'last').']',
                           'value'=>$item['label']];
            }
        }
    }

    // 3) anafórico/demostrativo («su», «ese», «ese último que me diste», «de él»)
    // — domina sobre la herencia (el usuario refiere, no repite tema)
    if ((!$subj['name'] || $subj['source'] === 'inherited')
        && preg_match('/\b(su|suyo|suya|sus|de [ée]l|de ella|ese|esa|esos|esas|aquel|aquella|el último que|ese último)\b/u', $q0)) {
        // «ese último que me diste» → ítem en cursor (lo último mostrado)
        if (preg_match('/\b(último|ultimo) que me (diste|di|mostraste)\b/u', $q0) && $rsItems) {
            $cur = (int)($ds['cursor'] ?? 0);
            $item = $rsItems[$cur] ?? end($rsItems);
            if ($item) {
                $subj['entity'] = 'student'; $subj['name'] = $item['label']; $subj['id'] = $item['id'] ?? null;
                $subj['source'] = 'reference_anaphora';
                $subj['evidence'][] = 'ref:anaphora(ese_ultimo)→cursor['.$cur.']';
                $refs[] = ['type'=>'anaphora','text'=>'ese último','resolves_to'=>'cursor['.$cur.']','value'=>$item['label']];
            }
        } elseif (!empty($ds['entities']['student'])) {
            // «su X» sin posición → estudiante activo en memoria
            $subj['entity'] = 'student'; $subj['name'] = $ds['entities']['student'];
            $subj['source'] = 'reference_anaphora'; $subj['evidence'][] = 'ref:anaphora(su)→ds.student';
            $refs[] = ['type'=>'anaphora','text'=>'su/ese','resolves_to'=>'ds.entities.student','value'=>$ds['entities']['student']];
        } elseif (!empty($ds['current']['result'])) {
            // sin persona: la referencia queda SIN resolver (el frame lo dice,
            // no adivina) — pero si hay cursor con set, el ítem activo aplica
            $cur = (int)($ds['cursor'] ?? 0);
            if ($rsItems && isset($rsItems[$cur])) {
                $subj['entity'] = 'student'; $subj['name'] = $rsItems[$cur]['label']; $subj['id'] = $rsItems[$cur]['id'] ?? null;
                $subj['source'] = 'active_item'; $subj['evidence'][] = 'ref:anaphora→active_item(cursor)';
                $refs[] = ['type'=>'anaphora','text'=>'su/ese','resolves_to'=>'active_item','value'=>$subj['name']];
            }
        }
    }

    // 4) ítem activo (cursor) cuando el turno es puramente relacional
    if (!$subj['name'] && !empty($ds['entities']['student'])
        && preg_match('/\b(acudiente|documento|celular|tel[ée]fono|grupo|jornada|whatsapp)\b/u', $q0)
        && !preg_match('/\b(grupo|grupos|sal[óo]n|curso)s?\s+\d|[6-9]|10|11\b/u', $q0)) {
        $subj['entity'] = 'student'; $subj['name'] = $ds['entities']['student'];
        $subj['source'] = 'inherited'; $subj['evidence'][] = 'inherit:ds.student';
    }

    // 5) métrica sin sujeto nuevo («¿cuántas evasiones…?») — el sujeto de
    // conversación se hereda SOLO si el tema activo era esa persona
    // (ficha/resumen/turno de campo): una consulta global no la secuestra
    // el estudiante activo (caso D: «datos de María» → «cuántas evasiones»)
    if (!$subj['name'] && in_array($task, ['count','aggregate'], true)
        && !empty($ds['entities']['student'])
        && in_array($ds['intent'] ?? null, ['student_field','student_summary','students.position','count_events','list_events'], true)) {
        // count_events/list_events en ds solo cuenta si el turno previo
        // trajo al estudiante como sujeto (field/summary), no como filtro
        $prevWasPersonTopic = in_array($ds['intent'], ['student_field','student_summary','students.position'], true)
            || (in_array($ds['intent'], ['count_events','list_events'], true)
                && !empty($ds['entities']['field']));
        if ($prevWasPersonTopic) {
            $subj['entity'] = 'student'; $subj['name'] = $ds['entities']['student'];
            $subj['source'] = 'inherited'; $subj['evidence'][] = 'inherit:subject_of_conversation';
        }
    }
    return [$subj, $refs];
}

/* ---------------------------------------------------------------------------
 * FRAME — construcción consolidada. Entrada: todo lo disponible; salida:
 * significado normalizado (las claves que no aplican quedan null).
 * ------------------------------------------------------------------------- */
function nxScpFrame(string $q0, array $cls, array $interp, ?array $ds, ?array $sig = null): array {
    $slots = $interp['resolved']['slots'] ?? [];
    $slots['_nlu_intent'] = $cls['intent'] ?? null;
    $slots['_resolved_intent'] = $interp['resolved']['intent'] ?? null;
    $sig = $sig ?: nxSemSignals($q0, $slots, $ds);

    [$task, $taskEv] = nxScpTask($q0, $slots, $sig, $ds);
    [$subject, $refs] = nxScpSubject($slots, $ds, $q0, $task);
    $corrections = nxScpCorrections($q0, $slots);

    // — rango temporal: el del turno, o heredado si no se redefine —
    $timeRange = null; $timeInherited = false;
    if (isset($slots['days']) || isset($slots['from'])) {
        $timeRange = ['from'=>$slots['from'] ?? null, 'to'=>$slots['to'] ?? null,
                      'days'=>$slots['days'] ?? null, 'label'=>$slots['range_label'] ?? null,
                      'inherited'=>false];
    } elseif (!empty($ds['entities']['days']) || !empty($ds['entities']['from'])) {
        $timeRange = ['from'=>$ds['entities']['from'] ?? null, 'to'=>$ds['entities']['to'] ?? null,
                      'days'=>$ds['entities']['days'] ?? null,
                      'label'=>$ds['entities']['range_label'] ?? null, 'inherited'=>true];
        $timeInherited = true;
    }

    // — alcance: explícito > scope docente > colegio —
    // el grupo se normaliza a la forma canónica «10-A» (el extractor trae
    // «10A», «10 a», «decimo a» — el frame unifica)
    $normGroup = function ($g) {
        if (!is_string($g)) return $g;
        if (preg_match('/^(\d{1,2})\s*[- ]?\s*([a-e])$/ui', trim($g), $m)) return $m[1] . '-' . strtoupper($m[2]);
        return $g;
    };
    $scope = ['kind'=>'school', 'label'=>null, 'inherited'=>false];
    if (!empty($slots['group']) || !empty($sig['filters']['group'] ?? null)) {
        $scope = ['kind'=>'group', 'label'=>$normGroup($slots['group'] ?? ($sig['filters']['group'] ?? null)), 'inherited'=>false];
    } elseif (preg_match('/\b(mis? (clases?|grupos?|cursos?)|mi sal[óo]n)\b/u', $q0)) {
        $scope = ['kind'=>'teacher', 'label'=>null, 'inherited'=>false];
    } elseif (!empty($ds['entities']['group'])) {
        $scope = ['kind'=>'group', 'label'=>$normGroup($ds['entities']['group']), 'inherited'=>true];
    }

    // — ranking: métrica + límite («top 5», «solo cinco», «los 5 peores») —
    $ranking = null;
    if ($task === 'rank') {
        $metric = 'events';
        if (preg_match('/falt|inasist|ausen/u', $q0)) $metric = 'absences';
        elseif (preg_match('/tardanz|tarde|impuntu/u', $q0)) $metric = 'lates';
        elseif (preg_match('/evasi|escap|fug/u', $q0)) $metric = 'evasions';
        elseif (preg_match('/incident|novedad/u', $q0)) $metric = 'events';
        $limit = null;
        if (preg_match('/\btop\s*(\d+)/u', $q0, $m)) $limit = (int)$m[1];
        elseif (preg_match('/\b(?:los|las)\s+(\d+)\s+(?:[\wáéíóúñü]+\s+)?que\s+m[aá]s/u', $q0, $m)) $limit = (int)$m[1];
        elseif (preg_match('/\blos\s+(\d+)\s+(peores|mayores|con\s*m[áa]s|primeros|primeras)/u', $q0, $m)) $limit = (int)$m[1];
        elseif (preg_match('/\b(los )?(primeros|primeras)\s*(\d+)?\b/u', $q0, $m)) $limit = $m[3] ? (int)$m[3] : 5;
        foreach ($corrections as $cc) if ($cc['kind'] === 'limit') $limit = $cc['value'] ?? $limit;
        $ranking = ['metric'=>$metric, 'order'=>'desc', 'limit'=>$limit, 'evidence'=>'lex:rank'];
    }

    // — salida / presentación —
    $output = 'list';
    if (preg_match('/\b(tabla|cuadro)\b/u', $q0)) $output = 'table';
    elseif ($task === 'count' || $task === 'aggregate') $output = 'metric';
    elseif ($task === 'compare') $output = 'comparison';
    elseif ($task === 'rank') $output = 'table';
    elseif (!empty($subject['name']) && $task === 'relation') $output = 'single';

    // — transformaciones detectadas —
    $transforms = [];
    if (!empty($slots['_nav'])) $transforms[] = ['kind'=>'nav', 'value'=>$slots['_nav']];

    // — objetivos múltiples (composición): cada cláusula conserva su
    // tarea — se clasifica cada cláusula por separado (un chiste solo se
    // detecta clasificando la cláusula) —
    $targets = [];
    $clauses = function_exists('nxSemSplitCompound') ? nxSemSplitCompound($q0) : [];
    if (count($clauses) > 1) {
        foreach ($clauses as $cl) {
            $cN = function_exists('nxNorm') ? nxNorm($cl) : $cl;
            $cCls = function_exists('nxClassify') ? nxClassify($cN) : ['intent'=>null];
            [$cTask] = nxScpTask($cN, ['_nlu_intent'=>$cCls['intent'] ?? null], null, null);
            $targets[] = ['text'=>trim($cl), 'task'=>$cTask];
        }
    }

    // — confianza: NLU × soporte de señales × resolución de referencias —
    $nluConf = (float)($cls['confidence'] ?? 0);
    $signalSupport = min(1.0, 0.4 + 0.15 * count($taskEv) + ($subject['source'] !== 'none' ? 0.2 : 0)
        + ($timeRange ? 0.1 : 0) + ($scope['kind'] !== 'school' ? 0.1 : 0));
    $frameConf = round($nluConf * 0.5 + $signalSupport * 0.5, 3);

    $frameFilters = array_filter(array_merge(
        is_array($sig['filters'] ?? null) ? $sig['filters'] : [],
        array_intersect_key($slots, array_flip(['group','grade','module','status','student','search']))
    ), fn($v) => $v !== null && $v !== '' && $v !== []);
    if (isset($frameFilters['group'])) $frameFilters['group'] = $normGroup($frameFilters['group']);
    if (isset($frameFilters['group2'])) $frameFilters['group2'] = $normGroup($frameFilters['group2']);

    $frame = [
        'task' => $task,
        'domain' => $sig['entity'] ?? null,
        'subject' => $subject,
        'relation' => $sig['relation'] ?? null,
        'field' => $slots['field'] ?? null,
        'targets' => $targets ?: null,
        'filters' => $frameFilters,
        'scope' => $scope,
        'time_range' => $timeRange,
        'aggregation' => $task === 'aggregate' ? 'percent' : (($task === 'count') ? 'count' : null),
        'ranking' => $ranking,
        'output' => $output,
        'references' => $refs ?: null,
        'transformations' => $transforms ?: null,
        'corrections' => $corrections ?: null,
        'position' => $sig['position'] ?? ($slots['position'] ?? null),
        'pending' => null,   // lo llena chat.php con ds.pending_targets
        'confidence' => ['nlu'=>$nluConf, 'frame'=>$frameConf, 'signals'=>$signalSupport],
        'evidence' => array_merge($taskEv, $subject['evidence'], $sig['evidence'] ?? []),
        '_source_intent' => $interp['resolved']['intent'] ?? null,
    ];
    return $frame;
}

/* ---------------------------------------------------------------------------
 * Validación — contrato por tarea. El frame inválido se rechaza ANTES de
 * planificar (nunca llega a ejecución un significado incompleto).
 * ------------------------------------------------------------------------- */
function nxScpValidate(array $frame): array {
    $t = $frame['task'];
    $hasSubject = !empty($frame['subject']['name']);
    $hasFilters = !empty($frame['filters']);
    switch ($t) {
        case 'relation':
            if (!$hasSubject) return [false, 'relation_sin_sujeto'];
            if (empty($frame['field']) && empty($frame['relation']))
                return [false, 'relation_sin_campo'];
            break;
        case 'count':
            if (!$hasSubject && !$hasFilters && empty($frame['time_range']))
                return [false, 'count_sin_contexto'];
            break;
        case 'rank':
            if (empty($frame['filters']) && $frame['scope']['kind'] === 'school'
                && empty($frame['time_range'])) return [false, 'rank_sin_contexto'];
            break;
        case 'compare':
            if (empty($frame['filters']['group']) || empty($frame['filters']['group2']))
                return [false, 'compare_requiere_dos_grupos'];
            break;
        case 'navigate':
            if (empty($frame['references']) && $frame['subject']['source'] === 'none'
                && empty($frame['transformations'])) return [false, 'navigate_sin_set'];
            break;
        case 'correct':
            if (empty($frame['corrections'])) return [false, 'correccion_sin_tipo'];
            break;
    }
    return [true, null];
}

/* ---------------------------------------------------------------------------
 * Traductor frame → intent+slots del pipeline existente. Devuelve
 * [intent, slots, forced]. forced=true → el pipeline debe usar esta
 * interpretación (el frame tuvo soporte estructural suficiente).
 * ------------------------------------------------------------------------- */
function nxScpToSlots(array $frame): array {
    $f = $frame['filters'];
    $tr = $frame['time_range'] ?? [];
    $slots = [];

    // módulo por métrica del ranking o filtros
    $module = $f['module'] ?? null;
    if (!$module && !empty($frame['ranking']['metric'])) {
        $module = ['absences'=>'INASISTENCIA','lates'=>'LATE_ARRIVAL',
                   'evasions'=>'EVASION_INTERNA'][$frame['ranking']['metric']] ?? null;
    }
    if (!$module && ($frame['task'] === 'count' || $frame['task'] === 'rank')
        && !empty($frame['field']) && $frame['field'] === 'acudiente') $module = null;

    foreach (['group','grade','status','search','student','module'] as $k)
        if (!empty($f[$k])) $slots[$k] = $f[$k];
    if (!empty($f['group2'])) $slots['group2'] = $f['group2'];
    foreach (['days','from','to','range_label'] as $k)
        if (isset($tr[$k]) && $tr[$k] !== null) $slots[$k] = $tr[$k];

    switch ($frame['task']) {
        case 'rank':
            $intent = 'top_offenders';
            if (!empty($frame['ranking']['limit'])) $slots['_rank_limit'] = $frame['ranking']['limit'];
            if ($frame['scope']['kind'] === 'teacher') $slots['_my_scope'] = true;
            return [$intent, $slots, true];

        case 'compare':
            return ['groups_compare', $slots, true];

        case 'filter':
            // umbral pedagógico → motor de riesgo (scope real en el executor)
            return ['risk_students', $slots, true];

        case 'count':
            // conteo de PERSONAS en un grupo («los pelados de 6A cuántos
            // son») — dominio students, sin módulo de eventos
            if ($frame['domain'] === 'students' && !empty($slots['group'])
                && empty($slots['module']) && empty($frame['subject']['name']))
                return ['group_student_count', $slots, true];
            // métrica de UNA persona («cuánto ha faltado Juan…»)
            if (!empty($frame['subject']['name']) && $frame['subject']['entity'] === 'student') {
                $slots['student'] = $frame['subject']['name'];
                if ($module) $slots['module'] = $module;
                return ['count_events', $slots, true];
            }
            return ['count_events', $slots, true];

        case 'relation':
            // relaciones de GRUPO (horario/docentes/acudientes del grupo)
            // las compone el planner — no son campos de un estudiante
            if (in_array($frame['relation'] ?? '', ['schedule_of_group','teachers_of_group','guardians_of_group'], true))
                return [null, $slots, false];
            $slots['student'] = $frame['subject']['name'];
            $slots['field'] = $frame['field'] ?: 'acudiente';
            if (!empty($frame['subject']['qualifies']) || $frame['domain'] === 'guardians')
                $slots['_ref'] = 'guardian';
            return ['student_field', $slots, true];

        case 'navigate':
        case 'transform':
            // el pipeline de _nav/chatResultNav ya lo resuelve — solo afinar
            if (!empty($frame['transformations'][0]['value']))
                $slots['_nav'] = $frame['transformations'][0]['value'];
            return [null, $slots, false];

        default:
            return [null, $slots, false];
    }
}

/* ---------------------------------------------------------------------------
 * Plan especializado para tareas que el pipeline de intents no expresa
 * bien (compare con métrica explícita). Pasa por nxPlanValidate +
 * nxPlanAllowed como cualquier plan — nada de ejecución directa.
 * ------------------------------------------------------------------------- */
function nxScpToPlan(array $frame): ?array {
    $f = $frame['filters'];
    if ($frame['task'] !== 'compare' || empty($f['group']) || empty($f['group2']))
        return null;
    $tr = $frame['time_range'] ?? [];
    $plan = [
        'capability'=>'groups.compare', 'entity'=>'groups', 'op'=>'compare',
        'filters'=>[
            'group'=>$f['group'], 'group2'=>$f['group2'],
            'module'=>$f['module'] ?? null,
            'days'=>$tr['days'] ?? null, 'from'=>$tr['from'] ?? null,
            'to'=>$tr['to'] ?? null, 'range_label'=>$tr['label'] ?? null,
        ],
        'read_only'=>true, '_src'=>'scp',
        'conf'=>$frame['confidence']['frame'],
        'evidence'=>$frame['evidence'],
    ];
    return $plan;
}

/* ---------------------------------------------------------------------------
 * Traza de desarrollo — una línea por capa, activada con NEXO_SCP_TRACE=1.
 * Formato obligatorio: INPUT → FRAME → ENTITIES → ACTIVE → INHERITED →
 * PLAN → RBAC → EXECUTION → RESULT → MEMORY (chat.php completa las últimas).
 * ------------------------------------------------------------------------- */
function nxScpTrace(string $layer, array $data): void {
    if (getenv('NEXO_SCP_TRACE') !== '1') return;
    $j = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    error_log("[SCP:{$layer}] " . substr((string)$j, 0, 2000));
}
