<?php
/**
 * lib/insights.php — Motor de inteligencia del dashboard (Nodus Insights).
 *
 * NO son textos fijos ni secuencias de if: cada novedad sale de funciones
 * matemáticas sobre los datos reales de la escuela:
 *
 *   - media / desviación estándar poblacional          (dispersión)
 *   - z-score vs línea base móvil de 20 días hábiles   (anomalías)
 *   - moda por histograma deslizante de 10 min         (clusters horarios)
 *   - pendiente por mínimos cuadrados + r²             (tendencias)
 *   - score de prioridad = w·z + w·magnitud + w·recencia (ranking)
 *
 * Produce tarjetas {kind, severity, score, title, body, action, data}
 * que el PWA renderiza como "lectura de la jornada" de Nodus.
 */

// ─────────────────────────── matemáticas ───────────────────────────

function nx_mean(array $xs): float {
    return count($xs) ? array_sum($xs) / count($xs) : 0.0;
}

function nx_stddev(array $xs, float $mean = null): float {
    $n = count($xs);
    if ($n < 2) return 0.0;
    $m = $mean ?? nx_mean($xs);
    $acc = 0.0;
    foreach ($xs as $x) { $d = $x - $m; $acc += $d * $d; }
    return sqrt($acc / $n); // poblacional: la muestra ES la población observada
}

function nx_zscore(float $x, float $mean, float $sd): float {
    // σ mínimo 0.7 evita explosiones cuando la base es casi plana
    return ($x - $mean) / max($sd, 0.7);
}

/** Regresión lineal por mínimos cuadrados: pendiente + r² sobre serie [día=>valor]. */
function nx_linreg(array $ys): array {
    $n = count($ys);
    if ($n < 3) return ['slope' => 0.0, 'r2' => 0.0];
    $mx = ($n - 1) / 2.0; $my = nx_mean($ys);
    $sxy = 0.0; $sxx = 0.0; $syy = 0.0;
    foreach ($ys as $i => $y) {
        $dx = $i - $mx; $dy = $y - $my;
        $sxy += $dx * $dy; $sxx += $dx * $dx; $syy += $dy * $dy;
    }
    $slope = $sxx > 0 ? $sxy / $sxx : 0.0;
    $r2 = ($sxx > 0 && $syy > 0) ? ($sxy * $sxy) / ($sxx * $syy) : 0.0;
    return ['slope' => $slope, 'r2' => $r2];
}

/**
 * Ventana modal por histograma deslizante sobre minutos del día.
 * Devuelve la ventana de $width min con mayor masa y su fracción del total.
 */
function nx_modal_window(array $mins, int $width = 10): array {
    if (!count($mins)) return ['start' => 0, 'end' => 0, 'mass' => 0, 'share' => 0.0];
    sort($mins);
    $best = 0; $bStart = $mins[0]; $n = count($mins);
    $lo = 0;
    for ($hi = 0; $hi < $n; $hi++) {
        while ($mins[$hi] - $mins[$lo] > $width) $lo++;
        if (($hi - $lo + 1) > $best) { $best = $hi - $lo + 1; $bStart = $mins[$lo]; }
    }
    return ['start' => $bStart, 'end' => $bStart + $width, 'mass' => $best, 'share' => $best / $n];
}

/** Logística suave 0..1 — convierte magnitudes continuas en pesos comparables. */
function nx_sig(float $x, float $mid, float $k = 1.0): float {
    return 1.0 / (1.0 + exp(-$k * ($x - $mid)));
}

function nx_hhmm(int $mins): string {
    return sprintf('%d:%02d', intdiv($mins, 60), $mins % 60);
}

// ─────────────────────────── motor ───────────────────────────

/**
 * nx_compute_insights(PDO $conn, string $schoolId, string $role, array $groupIds = [])
 * $groupIds vacío = toda la escuela; con grupos = vista docente.
 */
function nx_compute_insights(PDO $conn, string $schoolId, string $role, array $groupIds = []): array {
    $insights = [];
    $tz = "America/Bogota";
    $gFilter = '';
    $params = [$schoolId];
    if ($groupIds) {
        $ph = implode(',', array_fill(0, count($groupIds), '?'));
        $gFilter = " AND ai.group_id IN ($ph)";
        $params = array_merge($params, $groupIds);
    }

    // ── 1. Clusters temporales de llegadas tarde (hoy + semana) ──
    // Minutos desde medianoche por grupo; la ventana modal de 10' detecta
    // concentración (ej. acceso norte congestionado 7:02–7:09).
    $stmt = $conn->prepare("
        SELECT ai.group_id, ag.group_name,
               EXTRACT(HOUR FROM ai.detected_at AT TIME ZONE '$tz') * 60
             + EXTRACT(MINUTE FROM ai.detected_at AT TIME ZONE '$tz') AS minute_of_day,
               (ai.detected_at AT TIME ZONE '$tz')::date AS day
        FROM attendance_incidents ai
        JOIN academic_groups ag ON ag.group_id = ai.group_id
        WHERE ai.school_id = ? AND ai.incident_type = 'LATE_ARRIVAL'
          AND ai.detected_at >= NOW() - INTERVAL '7 days' $gFilter
    ");
    $stmt->execute($params);
    $byGroup = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $byGroup[$r['group_name']]['mins'][] = (int)$r['minute_of_day'];
        $byGroup[$r['group_name']]['days'][$r['day']] = true;
    }
    foreach ($byGroup as $gname => $d) {
        $mins = $d['mins']; $n = count($mins);
        if ($n < 3) continue;
        $win = nx_modal_window($mins, 10);
        $sd = nx_stddev($mins);
        // concentración: cuánto del grupo cae en una sola ventana de 10'
        $concentration = $win['share'];
        if ($concentration >= 0.6 && $win['mass'] >= 3) {
            $score = 0.55 + 0.45 * nx_sig($win['mass'], 5, 0.8);
            $insights[] = [
                'kind' => 'LATE_CLUSTER', 'severity' => 'MEDIUM',
                'score' => round($score, 3),
                'title' => "Llegadas tarde concentradas — {$gname}",
                'body'  => "{$win['mass']} de {$n} llegadas tarde de {$gname} esta semana cayeron entre "
                         . nx_hhmm($win['start']) . ' y ' . nx_hhmm($win['end'])
                         . " — sugiere un cuello de botella en el acceso, no dispersión de horarios.",
                'action' => ['label' => "Ver grupo {$gname}", 'target' => 'consulta'],
                'data' => ['group' => $gname, 'n' => $n, 'window' => [nx_hhmm($win['start']), nx_hhmm($win['end'])],
                           'concentration' => round($concentration, 2), 'sd_min' => round($sd, 1)],
            ];
        }
    }

    // ── 2. Anomalías vs línea base móvil (z-score sobre 20 días) ──
    $stmt = $conn->prepare("
        SELECT ai.incident_type, (ai.detected_at AT TIME ZONE '$tz')::date AS day, COUNT(*) AS c
        FROM attendance_incidents ai
        WHERE ai.school_id = ?
          AND ai.incident_type IN ('LATE_ARRIVAL','UNAUTHORIZED_ABSENCE','INASISTENCIA','EVASION','REAPARICION_TARDIA')
          AND ai.detected_at >= NOW() - INTERVAL '28 days' $gFilter
        GROUP BY ai.incident_type, day
    ");
    $stmt->execute($params);
    $series = [];
    $today = (new DateTime('now', new DateTimeZone($tz)))->format('Y-m-d');
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        if ($r['day'] === $today) $series[$r['incident_type']]['today'] = (int)$r['c'];
        else $series[$r['incident_type']]['hist'][$r['day']] = (int)$r['c'];
    }
    $TYPE_LABEL = [
        'LATE_ARRIVAL' => ['llegadas tarde', 'LATE_ANOMALY'],
        'UNAUTHORIZED_ABSENCE' => ['ausencias', 'ABSENCE_ANOMALY'],
        'INASISTENCIA' => ['ausencias', 'ABSENCE_ANOMALY'],
        'EVASION' => ['salidas sin retorno', 'EVASION_ANOMALY'],
        'REAPARICION_TARDIA' => ['reapariciones tardías', 'REAP_ANOMALY'],
    ];
    foreach ($series as $type => $d) {
        $hist = array_values($d['hist'] ?? []);
        if (count($hist) < 5) continue; // línea base insuficiente — no inventar
        $mu = nx_mean($hist); $sd = nx_stddev($hist, $mu);
        $today_c = $d['today'] ?? 0;
        $z = nx_zscore($today_c, $mu, $sd);
        if ($z >= 1.8) {
            [$lbl, $kind] = $TYPE_LABEL[$type] ?? [$type, 'ANOMALY'];
            $score = 0.5 + 0.5 * nx_sig($z, 2.5, 1.2);
            $insights[] = [
                'kind' => $kind, 'severity' => $z >= 3 ? 'HIGH' : 'MEDIUM',
                'score' => round($score, 3),
                'title' => "Día atípico en $lbl",
                'body' => "Hoy hay $today_c $lbl — " . round($z, 1)
                        . "σ sobre el promedio de " . round($mu, 1) . " de los últimos días. Está fuera del patrón normal.",
                'action' => ['label' => 'Ver en consulta', 'target' => 'consulta'],
                'data' => ['type' => $type, 'today' => $today_c, 'mean' => round($mu, 2), 'sd' => round($sd, 2), 'z' => round($z, 2)],
            ];
        }
    }

    // ── 3. Tendencias (pendiente por mínimos cuadrados, 10 días) ──
    $stmt = $conn->prepare("
        SELECT (ai.detected_at AT TIME ZONE '$tz')::date AS day,
               ai.incident_type, COUNT(*) AS c
        FROM attendance_incidents ai
        WHERE ai.school_id = ?
          AND ai.incident_type IN ('LATE_ARRIVAL','UNAUTHORIZED_ABSENCE','INASISTENCIA','EVASION')
          AND ai.detected_at >= NOW() - INTERVAL '14 days' $gFilter
        GROUP BY day, ai.incident_type ORDER BY day
    ");
    $stmt->execute($params);
    $perType = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $perType[$r['incident_type']][$r['day']] = (int)$r['c'];
    }
    foreach ($perType as $type => $days) {
        ksort($days);
        $ys = array_values($days);
        if (count($ys) < 6) continue;
        $lr = nx_linreg($ys);
        // tendencia real = pendiente positiva con ajuste r² decente
        if ($lr['slope'] > 0.25 && $lr['r2'] >= 0.35) {
            [$lbl] = $TYPE_LABEL[$type] ?? [$type];
            $score = 0.4 + 0.6 * nx_sig($lr['slope'] * $lr['r2'], 0.5, 3);
            $insights[] = [
                'kind' => 'TREND_' . $type, 'severity' => 'MEDIUM',
                'score' => round($score, 3),
                'title' => "Tendencia al alza en $lbl",
                'body' => "Las $lbl suben ~" . round($lr['slope'], 1)
                        . " por día desde hace " . count($ys) . " días (ajuste r²=" . round($lr['r2'], 2) . "). No es ruido: es un patrón sostenido.",
                'action' => ['label' => 'Ver evolución', 'target' => 'consulta'],
                'data' => ['type' => $type, 'slope' => round($lr['slope'], 2), 'r2' => round($lr['r2'], 2), 'days' => count($ys)],
            ];
        }
    }

    // ── 4. Ausencias sin respuesta del acudiente ──
    $stmt = $conn->prepare("
        SELECT COUNT(*) FILTER (WHERE COALESCE(ai.metadata_json->>'guardian_response','') = '') AS unreplied,
               COUNT(*) FILTER (WHERE ai.metadata_json->>'escalated' = 'true') AS escalated
        FROM attendance_incidents ai
        WHERE ai.school_id = ?
          AND ai.incident_type IN ('UNAUTHORIZED_ABSENCE','INASISTENCIA','INASISTENCIA_NO_JUSTIFICADA')
          AND ai.detected_at >= NOW() - INTERVAL '3 days' $gFilter
    ");
    $stmt->execute($params);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $unreplied = (int)($row['unreplied'] ?? 0); $escalated = (int)($row['escalated'] ?? 0);
    if ($unreplied > 0) {
        $score = 0.6 + 0.4 * nx_sig($unreplied + $escalated, 3, 0.9);
        $insights[] = [
            'kind' => 'UNREPLIED_ABSENCE', 'severity' => $escalated ? 'HIGH' : 'MEDIUM',
            'score' => round($score, 3),
            'title' => "$unreplied ausencia(s) sin respuesta del acudiente",
            'body' => "En los últimos 3 días, $unreplied ausencias siguen sin justificación"
                    . ($escalated ? " — $escalated ya escalaron. " : ' ')
                    . "Nodus detectó y escaló; el siguiente paso es decisión institucional.",
            'action' => ['label' => 'Derivar a seguimiento', 'target' => 'casos'],
            'data' => ['unreplied' => $unreplied, 'escalated' => $escalated],
        ];
    }

    // ── 5. Nodos sin conexión (salud de infraestructura) ──
    $stmt = $conn->prepare("
        SELECT device_id, device_name, location,
               EXTRACT(EPOCH FROM (NOW() - last_seen_timestamp)) AS silent_s
        FROM edge_devices
        WHERE school_id = ? AND active = TRUE AND configured = TRUE
          AND last_seen_timestamp < NOW() - INTERVAL '15 minutes'
    ");
    $stmt->execute([$schoolId]);
    $offline = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if ($offline) {
        $worst = max(array_column($offline, 'silent_s'));
        $score = 0.65 + 0.35 * nx_sig(count($offline), 2, 1.0);
        $names = implode(', ', array_map(fn($d) => $d['device_name'] ?? 'Nodo', $offline));
        $insights[] = [
            'kind' => 'NODE_OFFLINE', 'severity' => 'HIGH',
            'score' => round($score, 3),
            'title' => count($offline) . " nodo(s) sin conexión",
            'body' => "$names llevan más de 15 min sin reportar (el más antiguo "
                    . round($worst / 3600, 1) . " h). Sus grupos quedan marcados «sin datos de nodo» — no generan falsas ausencias.",
            'action' => ['label' => 'Ver dispositivos', 'target' => 'dispositivos'],
            'data' => ['count' => count($offline), 'worst_hours' => round($worst / 3600, 1)],
        ];
    }

    // ── 6. Lectura semanal — deltas de los últimos 7 días vs los 7 anteriores ──
    // No compara conteos aislados: mide la variación relativa por tipo y
    // resume las dos categorías con mayor cambio significativo.
    $stmt = $conn->prepare("
        SELECT ai.incident_type,
               COUNT(*) FILTER (WHERE ai.detected_at >= NOW() - INTERVAL '7 days') AS cur,
               COUNT(*) FILTER (WHERE ai.detected_at <  NOW() - INTERVAL '7 days'
                                  AND ai.detected_at >= NOW() - INTERVAL '14 days') AS prev
        FROM attendance_incidents ai
        WHERE ai.school_id = ?
          AND ai.incident_type IN ('LATE_ARRIVAL','UNAUTHORIZED_ABSENCE','INASISTENCIA','EVASION','REAPARICION_TARDIA')
          AND ai.detected_at >= NOW() - INTERVAL '14 days' $gFilter
        GROUP BY ai.incident_type
    ");
    $stmt->execute($params);
    $deltas = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $cur = (int)$r['cur']; $prev = (int)$r['prev'];
        if ($cur + $prev < 4) continue; // masa insuficiente — no inventar variaciones
        $deltas[] = ['type' => $r['incident_type'], 'cur' => $cur, 'prev' => $prev,
                     'rel' => ($cur - $prev) / max($prev, 1)];
    }
    if ($deltas) {
        usort($deltas, fn($a, $b) => abs($b['rel']) <=> abs($a['rel']));
        $parts = [];
        $worse = 0;
        foreach (array_slice($deltas, 0, 3) as $d) {
            [$lbl] = $TYPE_LABEL[$d['type']] ?? [strtolower($d['type'])];
            $pct = round(abs($d['rel']) * 100);
            $dir = $d['rel'] >= 0 ? "más" : "menos";
            if ($d['rel'] > 0.15) $worse++;
            $parts[] = "{$d['cur']} {$lbl} ({$pct}% {$dir})";
        }
        if ($parts) {
            $insights[] = [
                'kind' => 'WEEKLY_DIGEST', 'severity' => $worse ? 'MEDIUM' : 'LOW',
                'score' => round(0.35 + 0.65 * nx_sig($worse, 1, 1.5), 3),
                'title' => 'Lectura de la semana',
                'body' => 'Últimos 7 días vs la semana anterior: ' . implode(' · ', $parts) . '.',
                'action' => ['label' => 'Ver detalle', 'target' => 'consulta'],
                'data' => ['deltas' => array_map(fn($d) => ['type' => $d['type'], 'cur' => $d['cur'], 'prev' => $d['prev'], 'rel' => round($d['rel'], 2)], $deltas)],
            ];
        }
    }

    // ── 7. Umbral de riesgo alcanzado recientemente ──
    $stmt = $conn->prepare("
        SELECT COUNT(*) FROM notifications
        WHERE school_id = ? AND type LIKE 'RISK%' AND created_at >= NOW() - INTERVAL '24 hours'
    ");
    $stmt->execute([$schoolId]);
    $riskN = (int)$stmt->fetchColumn();
    if ($riskN >= 3) {
        $insights[] = [
            'kind' => 'RISK_SPIKE', 'severity' => 'MEDIUM',
            'score' => round(0.45 + 0.55 * nx_sig($riskN, 6, 0.6), 3),
            'title' => "Actividad elevada del motor de riesgo",
            'body' => "$riskN alertas de riesgo en las últimas 24 h — más de lo habitual. Conviene revisar los casos abiertos.",
            'action' => ['label' => 'Ver seguimiento', 'target' => 'casos'],
            'data' => ['alerts_24h' => $riskN],
        ];
    }

    // ranking: score desc, severidad como desempate
    $sevW = ['HIGH' => 3, 'MEDIUM' => 2, 'LOW' => 1];
    usort($insights, fn($a, $b) =>
        ($b['score'] <=> $a['score']) ?: ($sevW[$b['severity']] <=> $sevW[$a['severity']])
    );

    return array_slice($insights, 0, 5);
}

// ─────────────────────────── brief de métricas ───────────────────────────

/**
 * nx_compute_brief — lectura breve del estado de las métricas (home).
 *
 * A diferencia de nx_compute_insights (novedades que salen por el bot),
 * aquí se resume CÓMO VA EL DÍA: cada métrica de hoy contra la media de
 * los últimos ~20 días hábiles con actividad (un día hábil = día con al
 * menos una señal en la institución — ingreso, incidente o alerta;
 * fines de semana y festivos no diluyen la base).
 *
 * Reglas:
 *   - |Δ%| ≥ 10% vs línea base → se reporta (con dirección semántica:
 *     subir inasistencias es malo; subir ingresos es bueno).
 *   - Racha ≥3 días hábiles seguidos en la misma dirección → se reporta.
 *   - Scope de grupo sin ingresos mientras la escuela sí los tiene → aviso.
 *   - Nada fuera de rango → "todo en orden" (honesto, no silencio).
 *
 * $groupIds vacío = toda la escuela; con grupos = vista acotada.
 * Devuelve {tone, text, highlights, streaks, metrics, baseline_days}.
 */
function nx_compute_brief(PDO $conn, string $schoolId, string $role, array $groupIds = [], string $scopeLabel = 'la institución'): array {
    $tz = "America/Bogota";
    $today = (new DateTime('now', new DateTimeZone($tz)))->format('Y-m-d');

    // Filtros de alcance: incidentes por group_id; ingresos por membresía
    // de grupo (biometric_events no tiene group_id).
    $incFilter = '';
    $stuFilter = '';
    $incParams = [$schoolId];
    $stuParams = [$schoolId];
    if ($groupIds) {
        $ph = implode(',', array_fill(0, count($groupIds), '?'));
        $incFilter = " AND ai.group_id IN ($ph)";
        $incParams = array_merge($incParams, $groupIds);
        $stuFilter = " AND be.student_id IN (
            SELECT sga.student_id FROM student_group_assignments sga
            WHERE sga.group_id IN ($ph) AND sga.active = TRUE
        )";
        $stuParams = array_merge($stuParams, $groupIds);
    }

    // Serie diaria por métrica sobre incidentes (28 días ≈ 20 hábiles).
    $stmt = $conn->prepare("
        SELECT (ai.detected_at AT TIME ZONE '$tz')::date AS day,
               CASE
                   WHEN ai.incident_type IN ('INASISTENCIA','UNAUTHORIZED_ABSENCE') THEN 'absent'
                   WHEN ai.incident_type = 'LATE_ARRIVAL' THEN 'late'
                   WHEN ai.incident_type IN ('PERMISO','AUTORIZAR_SALIDA') THEN 'permisos'
                   ELSE 'alerts'
               END AS metric,
               COUNT(DISTINCT ai.student_id) AS c
        FROM attendance_incidents ai
        WHERE ai.school_id = ?
          AND ai.detected_at >= NOW() - INTERVAL '28 days'
          AND (ai.incident_type IN ('INASISTENCIA','UNAUTHORIZED_ABSENCE','LATE_ARRIVAL','PERMISO','AUTORIZAR_SALIDA','EVASION_INTERNA')
               OR ai.incident_type LIKE 'RISK_ALERT%')
          $incFilter
        GROUP BY day, metric
    ");
    $stmt->execute($incParams);
    $series = ['absent' => [], 'late' => [], 'permisos' => [], 'alerts' => []];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $series[$r['metric']][$r['day']] = (int)$r['c'];
    }

    // Las SOS son institucionales: solo suman a 'alerts' en alcance amplio
    // (consistente con /dashboard/stats, que las cuenta sin filtro de grupo).
    if (!$groupIds) {
        $stmt = $conn->prepare("
            SELECT (sa.emitted_at AT TIME ZONE '$tz')::date AS day, COUNT(*) AS c
            FROM sos_alerts sa
            WHERE sa.school_id = ?
              AND sa.emitted_at >= NOW() - INTERVAL '28 days'
              AND sa.resolved = FALSE
            GROUP BY day
        ");
        $stmt->execute([$schoolId]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $series['alerts'][$r['day']] = ($series['alerts'][$r['day']] ?? 0) + (int)$r['c'];
        }
    }

    // Ingresos del alcance por día + calendario escolar (días con actividad
    // en la institución, sin filtro de grupo).
    $stmt = $conn->prepare("
        SELECT (be.event_timestamp AT TIME ZONE '$tz')::date AS day,
               COUNT(DISTINCT be.student_id) AS c
        FROM biometric_events be
        WHERE be.school_id = ?
          AND be.event_type LIKE 'INGRESO_%'
          AND be.event_timestamp >= NOW() - INTERVAL '28 days'
          $stuFilter
        GROUP BY day
    ");
    $stmt->execute($stuParams);
    $series['present'] = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $series['present'][$r['day']] = (int)$r['c'];
    }

    // Calendario escolar: día activo = día con cualquier señal (ingresos,
    // incidentes o alertas SOS). 'c' conserva solo el conteo de ingresos,
    // que es lo que schoolToday necesita para detectar alcances vacíos.
    $stmt = $conn->prepare("
        SELECT day, SUM(ing) AS c FROM (
            SELECT (be.event_timestamp AT TIME ZONE '$tz')::date AS day,
                   COUNT(DISTINCT be.student_id) AS ing
            FROM biometric_events be
            WHERE be.school_id = ?
              AND be.event_type LIKE 'INGRESO_%'
              AND be.event_timestamp >= NOW() - INTERVAL '28 days'
            GROUP BY day
            UNION ALL
            SELECT (ai.detected_at AT TIME ZONE '$tz')::date AS day, 0
            FROM attendance_incidents ai
            WHERE ai.school_id = ?
              AND ai.detected_at >= NOW() - INTERVAL '28 days'
            UNION ALL
            SELECT (sa.emitted_at AT TIME ZONE '$tz')::date AS day, 0
            FROM sos_alerts sa
            WHERE sa.school_id = ?
              AND sa.emitted_at >= NOW() - INTERVAL '28 days'
        ) d
        GROUP BY day
    ");
    $stmt->execute([$schoolId, $schoolId, $schoolId]);
    $schoolDays = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $schoolDays[$r['day']] = (int)$r['c'];
    }

    // Días hábiles con datos, ordenados — excluye hoy para la línea base.
    $activeDays = array_keys($schoolDays);
    sort($activeDays);
    $baseDays = array_values(array_filter($activeDays, fn($d) => $d !== $today));
    $baselineDays = count($baseDays);
    $schoolToday = (int)($schoolDays[$today] ?? 0);

    $METRICS = [
        'present'  => ['label' => 'ingresos',        'bad_dir' => 'down'],
        'absent'   => ['label' => 'inasistencias',   'bad_dir' => 'up'],
        'late'     => ['label' => 'llegadas tarde',  'bad_dir' => 'up'],
        'alerts'   => ['label' => 'alertas',         'bad_dir' => 'up'],
        'permisos' => ['label' => 'permisos',        'bad_dir' => null],
    ];

    $metrics = [];
    $highlights = [];
    $streaks = [];
    foreach ($METRICS as $key => $cfg) {
        $hist = $series[$key] ?? [];
        $todayC = (int)($hist[$today] ?? 0);
        // Línea base: media sobre días hábiles (el alcance sin evento en un
        // día hábil cuenta 0 — es un dato real, no ausencia de dato).
        $vals = array_map(fn($d) => (int)($hist[$d] ?? 0), $baseDays);
        $mu = $baselineDays ? nx_mean($vals) : 0.0;
        $delta = $mu > 0.5 ? ($todayC - $mu) / $mu : null;
        $metrics[$key] = ['today' => $todayC, 'baseline' => round($mu, 1), 'delta_pct' => $delta !== null ? round($delta * 100) : null];

        if ($baselineDays >= 3) {
            // base ~0 y hoy aparecen varios → flag con redacción propia
            $isNew = ($mu < 0.5 && $todayC >= 2);
            // Un descenso a 0 sin ingresos en el alcance no es mejora:
            // es que la jornada aún no arrancó (lo dice el texto de fondo).
            $quietZero = ($todayC === 0 && $delta !== null && $delta < 0
                          && $metrics['present']['today'] === 0);
            if (!$quietZero && ($isNew || ($delta !== null && abs($delta) >= 0.10))) {
                $dir = $isNew ? 'up' : ($delta >= 0 ? 'up' : 'down');
                $highlights[] = [
                    'metric' => $key, 'label' => $cfg['label'], 'dir' => $dir,
                    'bad' => $cfg['bad_dir'] === $dir, 'is_new' => $isNew,
                    'today' => $todayC, 'baseline' => round($mu, 1),
                    'delta_pct' => $delta !== null ? abs(round($delta * 100)) : null,
                ];
            }
        }

        // Racha: días hábiles consecutivos (terminando en el último día con
        // datos — hoy si ya hay actividad, ayer si aún no) en una dirección.
        $run = array_values(array_filter($activeDays, fn($d) => $d <= $today));
        $streakLen = 1; $streakDir = null;
        for ($i = count($run) - 1; $i > 0; $i--) {
            $cur = (int)($hist[$run[$i]] ?? 0);
            $prev = (int)($hist[$run[$i - 1]] ?? 0);
            $d = $cur <=> $prev;
            if ($d === 0) break;
            if ($streakDir === null) $streakDir = $d > 0 ? 'up' : 'down';
            elseif (($d > 0 ? 'up' : 'down') !== $streakDir) break;
            $streakLen++;
        }
        if ($streakLen >= 3 && $streakDir) {
            $streaks[] = [
                'metric' => $key, 'label' => $cfg['label'], 'dir' => $streakDir,
                'days' => $streakLen, 'bad' => $cfg['bad_dir'] === $streakDir,
            ];
        }
    }

    // Alcance sin ingresos: el grupo no registra entradas pero la escuela sí
    // (jornada activa → es un dato, no un festivo). Si además hay nodos
    // caídos, puede ser falta de datos y se dice.
    $emptyScope = false;
    $nodesDown = 0;
    if ($groupIds && $schoolToday > 0 && $metrics['present']['today'] === 0) {
        $emptyScope = true;
        $stmt = $conn->prepare("
            SELECT COUNT(*) FROM edge_devices
            WHERE school_id = ? AND active = TRUE AND configured = TRUE
              AND last_seen_timestamp < NOW() - INTERVAL '15 minutes'
        ");
        $stmt->execute([$schoolId]);
        $nodesDown = (int)$stmt->fetchColumn();
    }

    usort($highlights, fn($a, $b) => ($b['delta_pct'] ?? 999) <=> ($a['delta_pct'] ?? 999));
    usort($streaks, fn($a, $b) => $b['days'] <=> $a['days']);

    $parts = [];
    foreach (array_slice($highlights, 0, 3) as $h) {
        if ($h['is_new']) {
            $parts[] = "{$h['today']} {$h['label']} hoy — el promedio de días hábiles era casi 0";
        } else {
            $dirWord = $h['dir'] === 'up' ? 'más' : 'menos';
            $parts[] = "{$h['label']}: {$h['today']} ({$h['delta_pct']}% {$dirWord} que el promedio de ~{$h['baseline']})";
        }
    }
    foreach (array_slice($streaks, 0, 2) as $s) {
        $dirWord = $s['dir'] === 'up' ? 'subiendo' : 'bajando';
        $parts[] = "{$s['label']} llevan {$s['days']} días seguidos {$dirWord}";
    }

    $bad = ($emptyScope && !$nodesDown)
        || array_filter($highlights, fn($h) => $h['bad'])
        || array_filter($streaks, fn($s) => $s['bad']);
    $tone = $bad ? 'warn' : ($parts ? 'info' : 'ok');

    if ($emptyScope) {
        $text = $nodesDown
            ? "{$scopeLabel} no tiene ingresos registrados hoy, pero hay nodos sin conexión — puede ser falta de datos, no de asistencia."
            : "{$scopeLabel} aún no tiene ingresos registrados hoy, mientras el resto de la institución sí.";
        if ($parts) $text .= ' Además: ' . implode(' · ', $parts) . '.';
        $text = ucfirst($text);
    } elseif ($parts) {
        $text = 'Hoy en ' . $scopeLabel . ': ' . implode(' · ', $parts) . '.';
    } elseif ($schoolToday === 0) {
        $text = "Aún no hay ingresos registrados en la institución — la jornada no ha arrancado o no hay datos de nodos.";
    } else {
        $text = $baselineDays >= 3
            ? "Todo en orden en {$scopeLabel} — las métricas siguen su patrón habitual."
            : "Todo en orden en {$scopeLabel} — aún no hay historia suficiente para comparar, pero nada fuera de lo esperado.";
    }

    return [
        'tone' => $tone,
        'text' => $text,
        'empty_scope' => $emptyScope,
        'nodes_down' => $nodesDown,
        'highlights' => array_slice($highlights, 0, 3),
        'streaks' => array_slice($streaks, 0, 2),
        'metrics' => $metrics,
        'baseline_days' => $baselineDays,
    ];
}
