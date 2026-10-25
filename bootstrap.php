<?php
declare(strict_types=1);

date_default_timezone_set('Asia/Ho_Chi_Minh');
mb_internal_encoding('UTF-8');

define('APP_ROOT', __DIR__);

// Không hiện cảnh báo/lỗi PHP ra trang web; ghi vào data/logs/php-error.log
ini_set('display_errors', '0');
ini_set('log_errors', '1');
if (is_dir(APP_ROOT . '/data') && is_writable(APP_ROOT . '/data')) {
    @mkdir(APP_ROOT . '/data/logs', 0775, true);
    ini_set('error_log', APP_ROOT . '/data/logs/php-error.log');
}

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
require_once APP_ROOT . '/src/Qr.php';
require_once APP_ROOT . '/src/QrEncoder.php';
require_once APP_ROOT . '/src/OrderService.php';
require_once APP_ROOT . '/src/SmsService.php';
require_once APP_ROOT . '/src/Bot.php';
require_once APP_ROOT . '/src/Updater.php';
require_once APP_ROOT . '/src/BankSync.php';
require_once APP_ROOT . '/src/Scheduler.php';

DB::init($GLOBALS['config']);

// Tự nâng cấp CSDL khi có phiên bản mới
const DB_VERSION = '10';
if (Settings::get('db_version') !== DB_VERSION) {
    $oldVersion = (int)Settings::get('db_version');
    DB::migrate();
    if ($oldVersion > 0 && $oldVersion < 5) {
        // Chuyển cột sms_mode cũ sang cờ tính năng
        DB::query("UPDATE services SET sup_custom_sms = 1 WHERE sms_mode = 'custom'");
        DB::query("UPDATE services SET sup_default_sms = 1 WHERE sms_mode = 'fixed'");
        DB::query("UPDATE services SET sup_forward_call = 1 WHERE sms_mode = 'forward'");
    }
    Settings::set('db_version', DB_VERSION);
}
