<?php
/**
 * API cho Telegram Mini App (app/index.html). Mọi request gửi kèm initData của Telegram
 * trong header X-Init-Data; server kiểm tra chữ ký bằng bot token để biết đúng user.
 * Dùng chung dữ liệu, số dư, đơn hàng với bot.
 */
require dirname(__DIR__) . '/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function out(array $data, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function fail(string $message, int $code = 400): void
{
    out(['ok' => false, 'message' => $message], $code);
}

/** Kiểm tra initData theo hướng dẫn của Telegram. Trả về user Telegram hoặc null. */
function verify_init_data(string $initData): ?array
{
    $token = (string)Settings::get('bot_token');
    if ($initData === '' || $token === '') {
        return null;
    }
    parse_str($initData, $data);
    $hash = (string)($data['hash'] ?? '');
    unset($data['hash']);
    ksort($data);
    $check = implode("\n", array_map(fn($k, $v) => "$k=$v", array_keys($data), $data));
    $secret = hash_hmac('sha256', $token, 'WebAppData', true);
    if (!hash_equals(hash_hmac('sha256', $check, $secret), $hash)) {
        return null;
    }
    if (time() - (int)($data['auth_date'] ?? 0) > 86400) {
        return null; // phiên quá cũ, mở lại app
    }
    $user = json_decode((string)($data['user'] ?? ''), true);
    return is_array($user) && !empty($user['id']) ? $user + ['start_param' => $data['start_param'] ?? null] : null;
}

function service_out(array $s, int $credits = 0): array
{
    $features = [];
    foreach (OrderService::FEATURES as $col => [, $label]) {
        if (!empty($s[$col])) {
            $features[$col] = $label;
        }
    }
    return ['id' => (int)$s['id'], 'name' => $s['name'], 'price' => (float)$s['price'], 'features' => $features, 'credits' => $credits];
}

function order_out(array $o): array
{
    $svc = DB::row('SELECT * FROM services WHERE id = ?', [$o['service_id']]) ?? [];
    $actions = [];
    if ($o['status'] === 'pending' && empty($o['sms_closed'])) {
        foreach (['sup_custom_sms' => 'custom', 'sup_default_sms' => 'fixed', 'sup_forward_call' => 'forward'] as $col => $kind) {
            if (!empty($svc[$col])) {
                $actions[] = $kind;
            }
        }
    }
    $sends = (int)$o['sms_sent'] > 0 || ($o['kind'] ?? '') === 'sms'
        ? DB::all('SELECT kind, receiver, content, ok, error, created_at FROM sms_sends WHERE order_id = ? ORDER BY id DESC LIMIT 30', [$o['id']])
        : [];
    return [
        'id' => (int)$o['id'],
        'kind' => $o['kind'] ?? 'otp',
        'service_id' => (int)$o['service_id'],
        'service_name' => $o['service_name'],
        'phone' => $o['phone'],
        'network' => $o['network'],
        'otp' => $o['otp'],
        'sms' => $o['sms'],
        'price' => (float)$o['price'],
        'by_package' => !empty($o['user_package_id']),
        'status' => $o['status'],
        'sms_sent' => (int)$o['sms_sent'],
        'sms_closed' => !empty($o['sms_closed']),
        'actions' => $actions,
        'sends' => $sends,
        'call_logs' => $o['call_logs'] ? (json_decode($o['call_logs'], true) ?: []) : [],
        'seconds_left' => $o['status'] === 'pending' ? max(0, strtotime($o['expires_at']) - time()) : 0,
        'can_cancel' => $o['status'] === 'pending' && (int)$o['sms_sent'] === 0 && OrderService::userCanCancel(),
        'can_finish' => $o['status'] === 'pending' && ($o['kind'] ?? '') === 'sms' && (int)$o['sms_sent'] > 0,
        'created_at' => $o['created_at'],
    ];
}

function user_out(array $u): array
{
    $botName = (string)Settings::get('bot_username');
    return [
        'tg_id' => (string)$u['tg_id'],
        'name' => $u['first_name'],
        'username' => $u['username'],
        'balance' => (float)$u['balance'],
        'total_deposit' => (float)$u['total_deposit'],
        'total_spent' => (float)$u['total_spent'],
        'ref_earned' => (float)$u['ref_earned'],
        'credits' => SmsService::credits((int)$u['id']),
        'ref_link' => $botName ? "https://t.me/{$botName}?start={$u['tg_id']}" : '',
        'ref_count' => (int)DB::value('SELECT COUNT(*) FROM users WHERE ref_by = ?', [$u['tg_id']]),
        'created_at' => $u['created_at'],
    ];
}

// ------------------------------------------------------------------ auth

$tgUser = verify_init_data((string)($_SERVER['HTTP_X_INIT_DATA'] ?? ''));
if (!$tgUser) {
    fail('Phiên không hợp lệ. Hãy mở ứng dụng từ trong Telegram.', 401);
}
$user = Bot::registerUser($tgUser, $tgUser['start_param'] ?? null);
if ($user['is_banned']) {
    fail('Tài khoản của bạn đã bị khóa. Liên hệ ' . Settings::get('support_contact'), 403);
}
$isAdmin = in_array((string)$user['tg_id'], Settings::adminIds(), true);
if (Settings::get('maintenance') === '1' && !$isAdmin) {
    fail(strip_tags((string)Settings::get('maintenance_message')), 503);
}

$in = json_decode(file_get_contents('php://input') ?: '', true) ?: [];
$action = (string)($in['action'] ?? $_GET['action'] ?? '');
$orders = new OrderService();

function my_order(array $user, int $id): array
{
    $o = DB::row('SELECT * FROM orders WHERE id = ? AND user_id = ?', [$id, $user['id']]);
    if (!$o) {
        fail('Không tìm thấy đơn.', 404);
    }
    return $o;
}

switch ($action) {
    case 'init':
        out(['ok' => true, 'user' => user_out($user), 'config' => [
            'shop_name' => Settings::get('shop_name'),
            'support' => Settings::get('support_contact'),
            'channel' => Settings::get('channel_url'),
            'networks' => OtpProvider::NETWORKS,
            'allow_network' => Settings::get('allow_network_select') === '1',
            'sms_enabled' => Settings::get('sms_enabled') === '1',
            'min_deposit' => Settings::int('min_deposit'),
            'deposit_bonus' => (float)Settings::get('deposit_bonus_percent'),
            'referral_percent' => (float)Settings::get('referral_percent'),
            'order_timeout' => Settings::int('order_timeout'),
            'sms_max_length' => Settings::int('sms_max_length'),
            'bank_ready' => (string)Settings::get('bank_account') !== '',
        ]]);

    case 'services':
        $sms = ($in['kind'] ?? '') === 'sms';
        $rows = $sms ? SmsService::services() : DB::all('SELECT * FROM services WHERE is_active = 1 ORDER BY sort_order DESC, name');
        out(['ok' => true, 'services' => array_map(fn($s) => service_out($s, $sms ? SmsService::credits((int)$user['id'], (int)$s['id']) : 0), $rows)]);

    case 'rent':
        $sms = ($in['kind'] ?? '') === 'sms';
        $s = DB::row('SELECT * FROM services WHERE id = ? AND is_active = 1', [(int)($in['service_id'] ?? 0)]);
        if (!$s || ($sms && !($s['sup_custom_sms'] || $s['sup_default_sms'] || $s['sup_forward_call']))) {
            fail('Dịch vụ không tồn tại hoặc đã tắt.');
        }
        $opts = ['kind' => $sms ? 'sms' : 'otp'];
        if (!empty($in['network']) && in_array($in['network'], OtpProvider::NETWORKS, true) && Settings::get('allow_network_select') === '1') {
            $opts['network'] = $in['network'];
        }
        if (!empty($in['re_order_id']) && Settings::get('allow_re_number') === '1') {
            $old = my_order($user, (int)$in['re_order_id']);
            $opts['re_number'] = $old['phone'];
        }
        $r = $orders->create($user, $s, $opts);
        if (!$r['ok']) {
            fail(strip_tags($r['message']));
        }
        $orders->notify($r['order']); // gửi tin đơn hàng trong bot để nhận thông báo song song
        out(['ok' => true, 'order' => order_out(DB::row('SELECT * FROM orders WHERE id = ?', [$r['order']['id']]))]);

    case 'order':
        $o = my_order($user, (int)($in['id'] ?? 0));
        // Kiểm tra trạng thái với nhà cung cấp, tối đa mỗi 4 giây/đơn (BossOTP giới hạn 1 request/giây)
        if ($o['status'] === 'pending' && time() - (int)$o['checked_at'] >= 4) {
            $o = $orders->poll($o);
        }
        out(['ok' => true, 'order' => order_out($o), 'balance' => (float)DB::value('SELECT balance FROM users WHERE id = ?', [$user['id']])]);

    case 'orders':
        $kind = ($in['kind'] ?? '') === 'sms' ? "AND (kind = 'sms' OR sms_sent > 0)" : '';
        $rows = DB::all("SELECT * FROM orders WHERE user_id = ? $kind ORDER BY id DESC LIMIT 50", [$user['id']]);
        out(['ok' => true, 'orders' => array_map('order_out', $rows)]);

    case 'cancel':
        $o = my_order($user, (int)($in['id'] ?? 0));
        if ($o['status'] !== 'pending' || (int)$o['sms_sent'] > 0 || !OrderService::userCanCancel()) {
            fail('Đơn không thể hủy. Hệ thống sẽ tự hoàn tiền nếu không nhận được OTP.');
        }
        $wait = Settings::int('allow_cancel_after') - (time() - strtotime($o['created_at']));
        if ($wait > 0) {
            fail("Vui lòng đợi thêm {$wait} giây nữa mới có thể hủy.");
        }
        $o = $orders->poll($o);
        if ($o['status'] === 'pending') {
            $orders->refund($o, 'cancelled', 'Người dùng hủy');
        }
        out(['ok' => true, 'order' => order_out(DB::row('SELECT * FROM orders WHERE id = ?', [$o['id']]))]);

    case 'send':
        $o = my_order($user, (int)($in['id'] ?? 0));
        $kind = (string)($in['type'] ?? '');
        $col = ['custom' => 'sup_custom_sms', 'fixed' => 'sup_default_sms', 'forward' => 'sup_forward_call'][$kind] ?? null;
        if (!$col || !DB::value("SELECT $col FROM services WHERE id = ?", [$o['service_id']])) {
            fail('Dịch vụ không hỗ trợ kiểu gửi này.');
        }
        $number = '';
        $content = '';
        if ($kind !== 'fixed') {
            $number = preg_replace('/[\s.\-]/', '', (string)($in['number'] ?? ''));
            if (str_starts_with($number, '+84')) {
                $number = '0' . substr($number, 3);
            }
            if (!preg_match('/^0\d{9,10}$/', $number)) {
                fail('Số điện thoại không hợp lệ (vd: 0987654321).');
            }
        }
        if ($kind === 'custom') {
            $content = trim((string)($in['content'] ?? ''));
            if ($err = SmsService::validateContent($content)) {
                fail($err);
            }
        }
        $r = $orders->sendSms($o, $kind, $number, $content);
        out(['ok' => $r['ok'], 'message' => strip_tags($r['message']), 'order' => order_out(DB::row('SELECT * FROM orders WHERE id = ?', [$o['id']]))]);

    case 'finish':
        $o = my_order($user, (int)($in['id'] ?? 0));
        if ($o['status'] === 'pending' && ($o['kind'] ?? '') === 'sms') {
            $orders->closeSmsRental($o, 'Khách kết thúc');
        }
        out(['ok' => true, 'order' => order_out(DB::row('SELECT * FROM orders WHERE id = ?', [$o['id']]))]);

    case 'deposit':
        if ((string)Settings::get('bank_account') === '') {
            fail('Chức năng nạp tiền chưa được cấu hình.');
        }
        out(['ok' => true, 'info' => Wallet::depositInfo($user, (int)($in['amount'] ?? 0))]);

    case 'deposit_claim':
        $amount = (int)($in['amount'] ?? 0);
        if ($amount < Settings::int('min_deposit')) {
            fail('Số tiền tối thiểu là ' . money(Settings::int('min_deposit')));
        }
        $r = Wallet::claimDeposit($user, $amount);
        out(['ok' => $r['ok'], 'message' => strip_tags($r['message'])]);

    case 'deposits':
        out(['ok' => true, 'deposits' => DB::all('SELECT id, amount, method, status, created_at FROM deposits WHERE user_id = ? ORDER BY id DESC LIMIT 20', [$user['id']])]);

    case 'packages':
        $pkgs = DB::all('SELECT p.id, p.name, p.credits, p.price, p.days_valid, p.description, s.name AS service_name FROM sms_packages p LEFT JOIN services s ON s.id = p.service_id WHERE p.is_active = 1 ORDER BY p.sort_order DESC, p.price');
        $mine = DB::all('SELECT name, credits_left, credits_total, expires_at FROM user_packages WHERE user_id = ? AND credits_left > 0 AND (expires_at IS NULL OR expires_at > ?) ORDER BY expires_at', [$user['id'], now()]);
        out(['ok' => true, 'packages' => $pkgs, 'mine' => $mine]);

    case 'buy_package':
        $r = SmsService::buyPackage($user, (int)($in['id'] ?? 0));
        out(['ok' => $r['ok'], 'message' => strip_tags($r['message']), 'user' => user_out(DB::row('SELECT * FROM users WHERE id = ?', [$user['id']]))]);

    case 'transactions':
        out(['ok' => true, 'transactions' => DB::all('SELECT amount, balance_after, type, note, created_at FROM transactions WHERE user_id = ? ORDER BY id DESC LIMIT 50', [$user['id']])]);
}

fail('Thao tác không hợp lệ.');
