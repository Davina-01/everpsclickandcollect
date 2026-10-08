<?php
// Runs upgrade functions again (repeated upgrade). Args: versions, e.g. 3.2.0 3.4.0
require __DIR__ . '/_boot.php';
$m = Module::getInstanceByName(LC_MODULE);
$res = array();
foreach (array_slice($argv, 1) as $v) {
    require_once _PS_MODULE_DIR_ . LC_MODULE . '/upgrade/upgrade-' . $v . '.php';
    $fn = 'upgrade_module_' . str_replace('.', '_', $v);
    try {
        $res[$v] = $fn($m);
    } catch (Throwable $e) {
        $res[$v] = 'EXCEPTION ' . substr($e->getMessage(), 0, 120);
    }
}
echo json_encode($res), "\n";
