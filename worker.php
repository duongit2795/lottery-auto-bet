<?php
/**
 * Tiến trình tự động chạy nền (không cần cron): kiểm tra OTP, hoàn tiền đơn hết hạn,
 * quét nạp tiền. Chạy ~50 giây rồi tự gọi lại chính nó để chạy liên tục.
 * Được khởi động tự động bởi Scheduler::kick() — không cần gọi tay.
 */
require __DIR__ . '/bootstrap.php';

if (!hash_equals((string)cfg('secret'), (string)($_GET['secret'] ?? ''))) {
    http_response_code(403);
    exit('forbidden');
}

ignore_user_abort(true);
set_time_limit(0);

// Trả lời ngay để request gọi tới không phải chờ
echo 'ok';
if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
} else {
    header('Connection: close');
    header('Content-Length: 2');
    @ob_end_flush();
    flush();
}

// Chỉ 1 worker chạy tại 1 thời điểm (chờ tối đa 10 giây cho worker trước bàn giao)
$lock = fopen(APP_ROOT . '/data/worker.lock', 'c');
$got = false;
for ($i = 0; $i < 10 && !$got; $i++) {
    $got = flock($lock, LOCK_EX | LOCK_NB);
    if (!$got) {
        sleep(1);
    }
}
if (!$got || !Scheduler::enabled()) {
    exit;
}

$end = time() + Scheduler::WORKER_SECONDS;
while (time() < $end) {
    $start = time();
    Scheduler::tick();
    $sleep = Scheduler::interval() - (time() - $start);
    if ($sleep > 0 && time() + $sleep < $end) {
        sleep($sleep);
    } elseif (time() + $sleep >= $end) {
        break;
    }
}

flock($lock, LOCK_UN);
fclose($lock);

// Tự gọi lại để chạy tiếp
if (Scheduler::enabled()) {
    Scheduler::spawn();
}
