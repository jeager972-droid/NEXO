<?php
/**
 * lib/calculator.php — Calculadora científica del chatbot.
 * Evalúa las operaciones estructuradas por math_ner.py — NUNCA usa eval().
 * La expresión literal pasa por un parser propio (shunting-yard → RPN).
 */

/** Evalúa la operación estructurada por el NER matemático. */
function nxCalc(array $op): array {
    $f = $op['function'] ?? null;
    $a = $op['a'] ?? null; $b = $op['b'] ?? null; $c = $op['c'] ?? null;
    $fmt = fn($n) => (is_numeric($n) && $n == (int)$n) ? (string)(int)$n : rtrim(rtrim(number_format((float)$n, 6, '.', ''), '0'), '.');

    switch ($f) {
        case 'eval':   return nxCalcExpr((string)($op['expr'] ?? ''));
        case 'add':    return nxOk($a + $b, "$a + $b = " . $fmt($a + $b));
        case 'sub':    return nxOk($a - $b, "$a − $b = " . $fmt($a - $b));
        case 'mul':    return nxOk($a * $b, "$a × $b = " . $fmt($a * $b));
        case 'div':
            if (!$b) return nxErr('No puedo dividir entre cero — el infinito no cabe en una hoja.');
            return nxOk($a / $b, "$a ÷ $b = " . $fmt($a / $b));
        case 'pow':    return nxOk($a ** $b, "$a^$b = " . $fmt($a ** $b));
        case 'root':   $i = $op['index'] ?? 2; return nxOk($a ** (1/$i), "√{$i}($a) = " . $fmt($a ** (1/$i)));
        case 'percent':$r = $a * $b / 100; return nxOk($r, "$a% de $b = " . $fmt($r));
        case 'fact':   if ($a > 170) return nxErr('Eso desborda hasta las calculadoras — factorial de ' . $a);
            $r = 1; for ($i = 2; $i <= $a; $i++) $r *= $i;
            return nxOk($r, "$a! = " . $fmt($r));
        case 'ln':     return nxOk(log($a), "ln($a) = " . $fmt(log($a)));
        case 'log':    return nxOk(log($a) / log($b ?: 10), "log_{$b}($a) = " . $fmt(log($a) / log($b ?: 10)));
        case 'sin': case 'cos': case 'tan':
            $rad = ($op['unit'] ?? 'deg') === 'deg' ? deg2rad($a) : $a;
            $r = call_user_func($f, $rad);
            return nxOk(round($r, 6), "$f($a°) = " . $fmt($r));
        case 'sec': case 'csc': case 'cot':
            $rad = ($op['unit'] ?? 'deg') === 'deg' ? deg2rad($a) : $a;
            $base = ['sec'=>'cos','csc'=>'sin','cot'=>'tan'][$f];
            $v = call_user_func($base, $rad);
            if (abs($v) < 1e-12) return nxErr("$f($a°) tiende a infinito — no existe.");
            return nxOk(1/$v, "$f($a°) = " . $fmt(1/$v));
        case 'lcm':    return nxOk(nxLcm((int)$a,(int)$b), "mcm($a, $b) = " . nxLcm((int)$a,(int)$b));
        case 'gcd':    return nxOk(nxGcd((int)$a,(int)$b), "mcd($a, $b) = " . nxGcd((int)$a,(int)$b));
        case 'rule3':  $r = $b * $c / $a; return nxOk($r, "Si $a → $b, entonces $c → " . $fmt($r));
        case 'area_circle':  return nxOk(M_PI * $a ** 2, "A = π·$a² = " . $fmt(M_PI * $a * $a));
        case 'area_triangle':return nxOk($a * $b / 2, "A = ($a × $b) / 2 = " . $fmt($a * $b / 2));
        case 'area_square':  return nxOk($a ** 2, "A = $a² = " . $fmt($a ** 2));
        case 'area_rect':    return nxOk($a * $b, "A = $a × $b = " . $fmt($a * $b));
        case 'hypot':  return nxOk(sqrt($a**2 + $b**2), "h = √($a² + $b²) = " . $fmt(sqrt($a**2 + $b**2)));
        case 'quadratic':
            $d = $b ** 2 - 4 * $a * $c;
            if ($d < 0) return nxErr("Raíces complejas: discriminante negativo ($d)");
            $x1 = (-$b + sqrt($d)) / (2 * $a); $x2 = (-$b - sqrt($d)) / (2 * $a);
            return nxOk([$x1, $x2], "x₁ = {$fmt($x1)}, x₂ = {$fmt($x2)}");
        case 'abs':    return nxOk(abs($a), "|$a| = " . $fmt(abs($a)));
        case 'round':  return nxOk(round($a), "round($a) = " . (int)round($a));
        case 'km_to_m':return nxOk($a * 1000, "$a km = " . $fmt($a * 1000) . " m");
        case 'deg_to_rad': return nxOk(deg2rad($a), "$a° = " . $fmt(deg2rad($a)) . " rad");
        case 'rad_to_deg': return nxOk(rad2deg($a), "$a rad = " . $fmt(rad2deg($a)) . "°");
        case 'time_conv':
            $sec = match($op['from'] ?? '') {
                'horas' => $a * 3600, 'minutos' => $a * 60, 'dias' => $a * 86400, default => $a };
            $out = match($op['to'] ?? '') {
                'horas' => $sec / 3600, 'minutos' => $sec / 60, 'segundos' => $sec, default => $sec };
            return nxOk($out, "$a {$op['from']} = " . $fmt($out) . " {$op['to']}");
    }
    return nxErr('Operación no reconocida — intenta de otra forma.');
}

function nxOk($r, string $display): array { return ['ok' => true, 'result' => $r, 'display' => $display]; }
function nxErr(string $m): array { return ['ok' => false, 'error' => $m]; }

function nxGcd(int $a, int $b): int { while ($b) { [$a, $b] = [$b, $a % $b]; } return abs($a); }
function nxLcm(int $a, int $b): int { return abs($a * $b) / max(1, nxGcd($a, $b)); }

/* ── Parser de expresiones literal: shunting-yard → RPN ─────────────────── */
function nxCalcExpr(string $expr): array {
    $expr = preg_replace('/\s+/', '', $expr);
    if ($expr === '' || !preg_match('/^[\d+\-*\/^().,%\s]+$/', $expr))
        return nxErr('Esa expresión tiene caracteres que no calculo.');
    $tokens = preg_split('/([+\-*\/^()%])/', $expr, -1,
                         PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
    $prec = ['+' => 1, '-' => 1, '*' => 2, 'x' => 2, '/' => 2, '%' => 2, '^' => 3];
    $out = []; $ops = [];
    $prev = null;
    foreach ($tokens as $t) {
        $t = trim($t);
        if ($t === '') continue;
        if (is_numeric($t)) { $out[] = (float)$t; $prev = 'n'; continue; }
        if ($t === '(') { $ops[] = $t; $prev = null; continue; }
        if ($t === ')') {
            while ($ops && end($ops) !== '(') $out[] = array_pop($ops);
            array_pop($ops); $prev = 'n'; continue;
        }
        if (isset($prec[$t]) || $t === 'x') {
            $op = $t === 'x' ? '*' : $t;
            // menos unario
            if ($op === '-' && ($prev !== 'n' && $prev !== null)) { $out[] = 0.0; }
            while ($ops && ($top = end($ops)) !== '(' && $prec[$top] >= $prec[$op] && $op !== '^')
                $out[] = array_pop($ops);
            $ops[] = $op; $prev = 'op'; continue;
        }
        return nxErr("Carácter inesperado: $t");
    }
    while ($ops) $out[] = array_pop($ops);
    // evaluar RPN
    $stack = [];
    foreach ($out as $t) {
        if (is_numeric($t)) { $stack[] = $t; continue; }
        if (count($stack) < 2) return nxErr('Expresión malformada.');
        $b = array_pop($stack); $a = array_pop($stack);
        $stack[] = match($t) {
            '+' => $a + $b, '-' => $a - $b, '*' => $a * $b,
            '/' => $b == 0 ? INF : $a / $b, '%' => $b == 0 ? NAN : fmod($a, $b),
            '^' => $a ** $b, default => 0 };
        if (!is_finite(end($stack))) return nxErr('División por cero — el infinito no cabe.');
    }
    if (count($stack) !== 1) return nxErr('Expresión malformada.');
    $r = $stack[0];
    $fmt = fn($n) => (is_numeric($n) && $n == (int)$n) ? (string)(int)$n : rtrim(rtrim(number_format($n, 6, '.', ''), '0'), '.');
    return nxOk($r, "$expr = " . $fmt($r));
}
