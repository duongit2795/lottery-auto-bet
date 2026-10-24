<?php
// Endpoint nhận update từ Telegram: https://domain/webhook.php?secret=...
require __DIR__ . '/bootstrap.php';

$secret = (string)cfg('secret');
$header = $_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '';
if ($secret !== '' && !hash_equals($secret, $header) && !hash_equals($secret, (string)($_GET['secret'] ?? ''))) {
    http_response_code(403);
    exit('forbidden');
}

$update = json_decode(file_get_contents('php://input') ?: '', true);
http_response_code(200);
if (!is_array($update)) {
    exit('ok');
}

// Trả lời Telegram ngay rồi mới xử lý, tránh bị gửi lại update khi gọi API chậm
if (function_exists('fastcgi_finish_request')) {
    echo 'ok';
    fastcgi_finish_request();
}
(new Bot())->handle($update);

// Giữ cho tiến trình tự động (kiểm tra OTP, hoàn tiền) luôn chạy
Scheduler::rememberSiteUrl();
Scheduler::kick();
