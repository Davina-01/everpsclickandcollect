<?php
// Saves a "Pick up later" choice for a cart, as the checkout does
require __DIR__ . '/_boot.php';
$m = Module::getInstanceByName(LC_MODULE);
$P = 'EverpsclickandcollectPickup';
$date = isset($argv[2]) ? $argv[2] : date('Y-m-d', strtotime('+1 day'));
try {
    $r = $m->savePickupChoice((int) $argv[1], 1, $P::MODE_LATER, array(array('date' => $date, 'start' => 600, 'end' => 720)));
    echo json_encode(array('returned' => $r)), "\n";
} catch (Throwable $e) {
    echo json_encode(array('exception' => substr(strip_tags($e->getMessage()), 0, 160))), "\n";
}
