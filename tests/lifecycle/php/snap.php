<?php
// Read-only snapshot of everything the module owns or touches
require __DIR__ . '/_boot.php';
$db = Db::getInstance();
$p = _DB_PREFIX_;
$out = array('db' => _DB_NAME_);
$out['module'] = $db->getRow("SELECT id_module, version, active FROM {$p}module WHERE name='" . LC_MODULE . "'") ?: null;
$out['carrier_id_cfg'] = Configuration::getGlobalValue('EVERPSCLICKANDCOLLECT_CARRIER_ID');
$out['carriers'] = $db->executeS("SELECT id_carrier, id_reference, deleted, active FROM {$p}carrier WHERE external_module_name='" . LC_MODULE . "' ORDER BY id_carrier");
foreach (array('everpsclickandcollect', 'everpsclickandcollect_store_stock', 'everpsclickandcollect_store') as $t) {
    $exists = (bool) $db->executeS("SHOW TABLES LIKE '{$p}{$t}'");
    $out['tables'][$t] = $exists ? (int) $db->getValue("SELECT COUNT(*) FROM {$p}{$t}") : 'MISSING';
}
$out['columns'] = $out['tables']['everpsclickandcollect'] === 'MISSING' ? array()
    : array_column($db->executeS("SHOW COLUMNS FROM {$p}everpsclickandcollect"), 'Field');
$out['orders_total'] = (int) $db->getValue("SELECT COUNT(*) FROM {$p}orders");
$out['orders_on_module_carriers'] = (int) $db->getValue("SELECT COUNT(*) FROM {$p}orders o JOIN {$p}carrier c ON c.id_carrier = o.id_carrier WHERE c.external_module_name='" . LC_MODULE . "'");
$out['orders_with_missing_carrier'] = (int) $db->getValue("SELECT COUNT(*) FROM {$p}orders o LEFT JOIN {$p}carrier c ON c.id_carrier = o.id_carrier WHERE c.id_carrier IS NULL");
$out['config'] = array();
foreach ($db->executeS("SELECT name, value FROM {$p}configuration WHERE name LIKE 'EVERPSCLICKANDCOLLECT%' ORDER BY name") as $r) {
    $out['config'][$r['name']] = $r['value'];
}
$out['config_lang_rows'] = (int) $db->getValue("SELECT COUNT(*) FROM {$p}configuration_lang cl JOIN {$p}configuration c ON c.id_configuration = cl.id_configuration WHERE c.name LIKE 'EVERPSCLICKANDCOLLECT%'");
// Merchant text used to check that settings survive (NOTES since 3.4.5, T2 before)
$out['text_t2_lang1'] = $db->getValue("SELECT cl.value FROM {$p}configuration_lang cl JOIN {$p}configuration c ON c.id_configuration = cl.id_configuration WHERE c.name IN ('EVERPSCLICKANDCOLLECT_TEXT_NOTES', 'EVERPSCLICKANDCOLLECT_TEXT_T2') AND cl.id_lang = 1 ORDER BY c.name");
$out['tabs'] = array_column($db->executeS("SELECT class_name FROM {$p}tab WHERE module='" . LC_MODULE . "' ORDER BY class_name"), 'class_name');
$out['hooks'] = $out['module'] ? array_column($db->executeS("SELECT h.name FROM {$p}hook_module hm JOIN {$p}hook h ON h.id_hook = hm.id_hook WHERE hm.id_module = " . (int) $out['module']['id_module'] . ' ORDER BY h.name'), 'name') : array();
$out['addresses'] = (int) $db->getValue("SELECT COUNT(*) FROM {$p}address");
// PrestaShop 9 returns typed values: compare everything as strings like PrestaShop 8
foreach (array('module', 'carriers') as $key) {
    if (is_array($out[$key])) {
        array_walk_recursive($out[$key], function (&$v) {
            if ($v !== null) {
                $v = (string) (is_bool($v) ? (int) $v : $v);
            }
        });
    }
}
echo json_encode($out, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";
