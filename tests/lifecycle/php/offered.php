<?php
// Read-only: carriers PrestaShop offers for the cart of the most recent order
require __DIR__ . '/_boot.php';
$idCart = (int) Db::getInstance()->getValue('SELECT id_cart FROM ' . _DB_PREFIX_ . 'orders ORDER BY id_order DESC');
$cart = new Cart($idCart);
Context::getContext()->cart = $cart;
Context::getContext()->customer = new Customer($cart->id_customer);
$ids = array();
foreach ($cart->getDeliveryOptionList(null, true) as $options) {
    foreach ($options as $o) {
        foreach ($o['carrier_list'] as $idc => $c) {
            $ids[] = (int) $idc;
        }
    }
}
echo json_encode(array('cart' => $idCart, 'offered' => array_values(array_unique($ids)))), "\n";
