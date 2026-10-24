<?php
/** Verify the migration admission policy independently from DDL execution. */
define('ABSPATH', __DIR__ . '/');
require __DIR__ . '/../includes/class-esp-migrator.php';
$m = new ESP_Migrator();
$d = (new ReflectionMethod($m, 'definitions'))->invoke($m)['limit'];
$validate = new ReflectionMethod($m, 'validate_existing');
$table = ['engine' => 'InnoDB', 'columns' => [], 'indexes' => []];
foreach ($d['columns'] as $name => $sql) {
    $table['columns'][$name] = ['Type' => preg_replace('/ (?:NOT )?NULL.*/', '', $sql),
        'Null' => strpos($sql, 'NOT NULL') === false ? 'YES' : 'NO', 'Extra' => ''];
    if (preg_match("/ DEFAULT ('[^']*'|[0-9]+)/", $sql, $value)) {
        $table['columns'][$name]['Default'] = trim($value[1], "'");
    }
}
foreach ($d['indexes'] as $name => $columns) {
    foreach ($columns as $seq => $col) {
        $table['indexes'][$name][$seq + 1] = ['Column_name' => $col, 'Non_unique' => $name === 'PRIMARY' ? 0 : 1, 'Sub_part' => null];
    }
}
$validate->invoke($m, 'limit', $table, $d);
echo "PASS: canonical InnoDB schema accepted\n";
$bad = $table; $bad['engine'] = 'MyISAM'; $cases[] = $bad;
$bad = $table; $bad['columns']['attempts']['Type'] = 'varchar(20)'; $cases[] = $bad;
$bad = $table; unset($bad['columns']['ip_address']); $cases[] = $bad;
$bad = $table; $bad['indexes']['PRIMARY'] = array_reverse($bad['indexes']['PRIMARY']); $cases[] = $bad;
$bad = $table; $bad['indexes']['PRIMARY'][1]['Sub_part'] = 10; $cases[] = $bad;
foreach ($cases as $case) {
    try { $validate->invoke($m, 'limit', $case, $d); throw new LogicException('incompatible schema accepted'); }
    catch (RuntimeException $e) {
        if ($e->getMessage() !== 'schema_incompatible') { throw $e; }
        echo "PASS: incompatible engine/type/key rejected before mutation\n";
    }
}
