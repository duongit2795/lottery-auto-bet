<?php
/**
 * Chạy bot bằng long-polling (dùng khi không có HTTPS/webhook, ví dụ VPS/máy local).
 *   php polling.php
 * Script tự kiểm tra OTP mỗi ~10 giây, không cần cron.
 */
require __DIR__ . '/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    exit('CLI only');
}

$tg = new Telegram();
$tg->call('deleteWebhook');
$me = $tg->call('getMe');
if (empty($me['ok'])) {
    fwrite(STDERR, 'Token không hợp lệ: ' . ($me['description'] ?? '') . "\n");
    exit(1);
}
echo 'Bot @' . $me['result']['username'] . " đang chạy (polling)...\n";

$bot = new Bot();
$orders = new OrderService();
$offset = 0;
$lastPoll = 0;
while (true) {
    $res = $tg->call('getUpdates', ['offset' => $offset, 'timeout' => 10], 20);
    foreach ($res['result'] ?? [] as $u) {
        $offset = $u['update_id'] + 1;
        $bot->handle($u);
    }
    if (time() - $lastPoll >= 10) {
        $orders->pollAll();
        $lastPoll = time();
    }
    if (empty($res['ok'])) {
        sleep(3);
    }
}
