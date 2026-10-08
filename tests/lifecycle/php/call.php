<?php
// Calls one lifecycle method of the module directly: install, uninstall, enable, disable, purgeData...
require __DIR__ . '/_boot.php';
$m = Module::getInstanceByName(LC_MODULE);
$method = $argv[1];
try {
    $r = $m->$method();
    echo json_encode(array('method' => $method, 'returned' => $r, 'errors' => $m->getErrors())), "\n";
} catch (Throwable $e) {
    echo json_encode(array('method' => $method, 'exception' => substr(strip_tags($e->getMessage()), 0, 200))), "\n";
}
