<?php
// Read-only: what the module shows for orders on the BO order page and the delivery slip
require __DIR__ . '/_boot.php';
$m = Module::getInstanceByName(LC_MODULE);
$res = array();
foreach (array_slice($argv, 1) as $idOrder) {
    $idOrder = (int) $idOrder;
    $html = '';
    try {
        foreach (array('hookDisplayAdminOrderMain', 'hookDisplayAdminOrder') as $h) {
            if (method_exists($m, $h)) {
                $html = (string) $m->$h(array('id_order' => $idOrder));
                break;
            }
        }
        $txt = trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($html))));
        $pos = strpos($txt, 'Pickup');
        $res[$idOrder] = $txt === '' ? '' : substr($txt, $pos === false ? 0 : $pos, 120);
    } catch (Throwable $e) {
        $res[$idOrder] = 'EXCEPTION ' . substr($e->getMessage(), 0, 120);
    }
}
echo json_encode($res, JSON_UNESCAPED_UNICODE), "\n";
