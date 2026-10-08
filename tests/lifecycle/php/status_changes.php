<?php
// Fires the module's order status hook 3 times for one order (store e-mail option on)
require __DIR__ . '/_boot.php';
Configuration::updateValue('EVERPSCLICKANDCOLLECT_MAIL', 1);
$m = Module::getInstanceByName(LC_MODULE);
$id = (int) $argv[1];
$count = function () { return (int) Db::getInstance()->getValue('SELECT COUNT(*) FROM ' . _DB_PREFIX_ . 'address'); };
$before = $count();
$errors = array();
foreach (array(3, 4, 5) as $st) {
    try {
        $m->hookActionOrderStatusUpdate(array('newOrderStatus' => new OrderState($st), 'id_order' => $id));
    } catch (Throwable $e) {
        $errors[] = substr($e->getMessage(), 0, 100);
    }
}
echo json_encode(array('before' => $before, 'after' => $count(), 'errors' => $errors)), "\n";
