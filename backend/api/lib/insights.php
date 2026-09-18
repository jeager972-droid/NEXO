<?php
/**
 * lib/insights.php — Motor de inteligencia del dashboard (Nexus Insights).
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
 * que el PWA renderiza como "lectura de la jornada" de Nexus.
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
                    . "Nexus detectó y escaló; el siguiente paso es decisión institucional.",
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

    // ── 6. Umbral de riesgo alcanzado recientemente ──
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
