<?php
/**
 * Lifecycle test tools - common bootstrap.
 * CLI only, and only against the database named in the LC_DB environment variable
 * (the runner refuses any name that does not end with "_lc"). Never run these on a live shop.
 */
if (PHP_SAPI !== 'cli') {
    exit(1);
}
$lcExpected = (string) getenv('LC_DB');
if (substr($lcExpected, -3) !== '_lc') {
    fwrite(STDERR, "refusing: LC_DB must name an isolated test database ending with _lc\n");
    exit(2);
}
if (!defined('_PS_ADMIN_DIR_')) {
    define('_PS_ADMIN_DIR_', __DIR__);
}
require dirname(__DIR__) . '/config/config.inc.php';
if (_DB_NAME_ !== $lcExpected) {
    fwrite(STDERR, 'refusing: shop database is ' . _DB_NAME_ . ", not LC_DB\n");
    exit(2);
}
$lcCtx = Context::getContext();
$lcCtx->employee = new Employee(1);
$lcCtx->language = new Language((int) Configuration::get('PS_LANG_DEFAULT'));
$lcCtx->currency = new Currency((int) Configuration::get('PS_CURRENCY_DEFAULT'));
const LC_MODULE = 'everpsclickandcollect';
