<?php
declare(strict_types=1);

date_default_timezone_set('Asia/Ho_Chi_Minh');
mb_internal_encoding('UTF-8');

define('APP_ROOT', __DIR__);

if (!file_exists(APP_ROOT . '/config.php')) {
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, "Chưa có config.php. Chạy install.php trước.\n");
        exit(1);
    }
    header('Location: ' . (str_contains($_SERVER['SCRIPT_NAME'] ?? '', '/admin/') ? '../' : '') . 'install.php');
    exit;
}

$GLOBALS['config'] = require APP_ROOT . '/config.php';

require_once APP_ROOT . '/src/helpers.php';
require_once APP_ROOT . '/src/DB.php';
require_once APP_ROOT . '/src/Settings.php';
require_once APP_ROOT . '/src/Telegram.php';
require_once APP_ROOT . '/src/OtpProvider.php';
require_once APP_ROOT . '/src/Wallet.php';
require_once APP_ROOT . '/src/OrderService.php';
require_once APP_ROOT . '/src/Bot.php';

DB::init($GLOBALS['config']);
