<?php
/* test/readonly_guard.php — §13 READ-ONLY REAL.
 * Prueba estructural: NINGÚN handler chat_* puede contener SQL mutativo.
 * Si mañana alguien agrega un INSERT en un handler, este test lo detecta
 * antes del merge — la conversación informativa no puede mutar por accidente.
 * Además: los handlers de operación deben responder con chips/acciones de
 * navegación, nunca efectos directos.
 */
$src = file_get_contents(__DIR__ . '/../backend/api/routes/chat.php');
preg_match_all('/function (chat_\w+)\s*\([^)]*\)\s*(?::\s*array\s*)?\{(.*?)(?=\nfunction |\n\/\* ═|\z)/s', $src, $mm, PREG_SET_ORDER);
$WRITES = '/\b(INSERT INTO|UPDATE \w+ SET|DELETE FROM|DROP TABLE|ALTER TABLE|TRUNCATE|CREATE TABLE|GRANT |REVOKE )\b/i';
$fail = 0; $checked = 0;
foreach ($mm as $h) {
    [$all, $name, $body] = $h;
    if (preg_match($WRITES, $body)) {
        printf("  ✗ %s contiene SQL mutativo\n", $name); $fail++;
    }
    $checked++;
}
printf("  %d handlers chat_* auditados · %d con escritura\n", $checked, $fail);

/* los intents de operación producen chips de navegación, no ejecución */
$bodies = array_column($mm, 2, 1);
foreach (['chat_derive_action','chat_start_operation','chat_export_data'] as $fn) {
    $b = $bodies[$fn] ?? '';
    $chip = str_contains($b, 'actions') || str_contains($b, 'chatActionChip') || str_contains($b, "'to'=>");
    printf("  %-22s → %s\n", $fn, $chip ? 'chip/nav (no ejecuta)' : '⚠ sin chip');
    if (!$chip) $fail++;
}
/* ningún intent mutativo existe en la taxonomía conversacional */
$mutative = ['delete_event','update_student','insert_event','modify_record',
             'edit_record','remove_event','write_db','execute_op','run_sql'];
$hit = array_filter($mutative, fn($i) => function_exists('chat_' . $i)
          || str_contains($src, "'$i'"));
printf("  intents mutativos en taxonomía: %s\n", $hit ? 'ENCONTRADOS ✗' : 'ninguno ✓');
if ($hit) $fail++;
echo $fail === 0 ? "\n  RESULTADO: READ-ONLY GARANTIZADO ({$checked} handlers)\n"
                 : "\n  RESULTADO: VIOLACIÓN READ-ONLY — {$fail} hallazgos\n";
exit($fail === 0 ? 0 : 1);
