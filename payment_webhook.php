<?php
/**
 * Webhook nạp tiền tự động từ SePay hoặc Casso.
 *  - SePay: URL https://domain/payment_webhook.php, kiểu xác thực "API Key" = payment_webhook_key
 *  - Casso: URL như trên, "secure token" = payment_webhook_key
 *  - Hoặc thêm ?key=payment_webhook_key vào URL.
 * Nội dung chuyển khoản phải chứa mã nạp của user (vd: NAPAB12CD).
 */
require __DIR__ . '/bootstrap.php';
header('Content-Type: application/json');

$key = (string)Settings::get('payment_webhook_key');
$given = $_GET['key']
    ?? $_SERVER['HTTP_SECURE_TOKEN']
    ?? preg_replace('/^(Apikey|Bearer)\s+/i', '', $_SERVER['HTTP_AUTHORIZATION'] ?? '');
if ($key === '' || !hash_equals($key, (string)$given)) {
    http_response_code(401);
    exit(json_encode(['success' => false, 'message' => 'unauthorized']));
}

$body = json_decode(file_get_contents('php://input') ?: '', true) ?: [];
app_log('payment', json_encode($body, JSON_UNESCAPED_UNICODE));

// Chuẩn hóa về danh sách giao dịch [ref, content, amount]
$txs = [];
if (isset($body['data']) && is_array($body['data'])) { // Casso
    foreach ($body['data'] as $t) {
        $txs[] = ['ref' => 'casso_' . ($t['id'] ?? $t['tid'] ?? ''), 'content' => $t['description'] ?? '', 'amount' => (float)($t['amount'] ?? 0)];
    }
} elseif (isset($body['transferAmount'])) { // SePay
    if (($body['transferType'] ?? 'in') === 'in') {
        $txs[] = ['ref' => 'sepay_' . ($body['id'] ?? $body['referenceCode'] ?? ''), 'content' => $body['content'] ?? '', 'amount' => (float)$body['transferAmount']];
    }
}

$prefix = preg_quote((string)Settings::get('deposit_prefix'), '/');
$done = 0;
foreach ($txs as $t) {
    if ($t['amount'] <= 0 || !preg_match('/' . $prefix . '\s*([A-Z0-9]{6})/i', $t['content'], $m)) {
        continue;
    }
    $user = DB::row('SELECT * FROM users WHERE deposit_code = ?', [strtoupper($m[1])]);
    if (!$user || DB::value('SELECT 1 FROM deposits WHERE ref = ?', [$t['ref']])) {
        continue;
    }
    // Nếu user đã bấm "Tôi đã chuyển khoản" thì dùng luôn yêu cầu đó
    $depId = DB::value("SELECT id FROM deposits WHERE user_id = ? AND status = 'pending' AND method = 'manual' ORDER BY id DESC", [$user['id']]);
    if ($depId) {
        DB::update('deposits', ['ref' => $t['ref'], 'method' => 'auto', 'note' => mb_substr($t['content'], 0, 250)], 'id = ?', [$depId]);
    } else {
        $depId = DB::insert('deposits', [
            'user_id' => $user['id'],
            'amount' => $t['amount'],
            'method' => 'auto',
            'ref' => $t['ref'],
            'note' => mb_substr($t['content'], 0, 250),
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
    if (Wallet::approveDeposit((int)$depId, $t['amount'])) {
        $done++;
    }
}

echo json_encode(['success' => true, 'processed' => $done]);
