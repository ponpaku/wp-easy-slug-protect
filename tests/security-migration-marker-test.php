<?php
/** V1-specific ID detection and existing path identity contract. */
define('ABSPATH', __DIR__ . '/');
require __DIR__ . '/../includes/class-esp-migrator.php';
$m = new ESP_Migrator();
$map = new ReflectionMethod($m, 'path_map');
function check_marker($ok, $label) {
    if (!$ok) { throw new RuntimeException($label); }
    echo "PASS: $label\n";
}
$original = ['path_existing' => ['id' => 'path_existing', 'path' => '/members/', 'password' => 'existing-hash', 'password_version' => 9]];
$r = $map->invoke($m, $original);
check_marker($r['paths'] === $original, 'existing IDs, password hashes and password versions remain byte-identical');
$legacy = [['path' => '/legacy/', 'password' => 'legacy-hash']];
try { $map->invoke($m, $legacy); throw new LogicException('V1 IDs were auto-generated'); }
catch (RuntimeException $e) {
    check_marker($e->getMessage() === 'v1_migration_required',
        'V1-only missing IDs require separate migration without guessing');
}
check_marker($map->invoke($m, $original)['paths'] === $original,
    'retry with assigned IDs is idempotent');
foreach ([
    ['path_existing' => ['id' => 'different', 'path' => '/members/']],
    [0 => ['id' => 'path_existing', 'path' => '/members/']],
    [['path' => '/']],
    ['a' => ['id' => 'a', 'path' => '/same/'], 'b' => ['id' => 'b', 'path' => '/same/']],
] as $bad) {
    try { $map->invoke($m, $bad); throw new LogicException('ambiguous IDs accepted'); }
    catch (RuntimeException $e) {
        check_marker($e->getMessage() === 'path_mapping_ambiguous',
            'ambiguous mapping is rejected without guessing');
    }
}
