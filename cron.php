<?php
/**
 * Kiểm tra OTP cho các đơn đang chờ + tự hủy/hoàn tiền đơn hết hạn.
 * (Không bắt buộc: worker.php đã tự chạy nền. Cron chỉ là lớp dự phòng.)
 *
 * Chạy mỗi phút:  * * * * * php /duong-dan/cron.php
 * Muốn quét dày hơn (mặc định lặp 4 lần × 15 giây trong 1 phút): php cron.php --loop=4
 * Hoặc gọi qua URL: https://domain/cron.php?secret=...
 */
require __DIR__ . '/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    if (!hash_equals((string)cfg('secret'), (string)($_GET['secret'] ?? ''))) {
        http_response_code(403);
        exit('forbidden');
    }
    header('Content-Type: text/plain; charset=utf-8');
}

$lockFile = APP_ROOT . '/data/cron.lock';
$lock = fopen($lockFile, 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    exit("Cron đang chạy\n");
}

// Ghi lại lần chạy để trang Tổng quan theo dõi cron
@file_put_contents(APP_ROOT . '/data/last_cron', time() . '|' . (PHP_SAPI === 'cli' ? 'cli' : 'url'));

$opts = PHP_SAPI === 'cli' ? getopt('', ['loop::']) : [];
$loops = max(1, (int)($opts['loop'] ?? $_GET['loop'] ?? 4));
$interval = (int)floor(55 / $loops);

for ($i = 0; $i < $loops; $i++) {
    $start = time();
    $n = Scheduler::tick();
    echo '[' . now() . '] ' . ($n === null ? 'Tiến trình tự động đang chạy, bỏ qua lượt này' : "Đã kiểm tra $n đơn") . "\n";
    if ($i < $loops - 1) {
        sleep(max(0, $interval - (time() - $start)));
    }
}
flock($lock, LOCK_UN);
