<?php
require __DIR__ . '/_init.php';
require_login();

// [key, nhãn, kiểu, ghi chú]
$groups = [
    '🤖 Bot Telegram' => [
        ['bot_token', 'Bot token', 'password', 'Lấy từ @BotFather'],
        ['shop_name', 'Tên shop', 'text', ''],
        ['admin_tg_ids', 'Telegram ID admin', 'text', 'Nhiều ID cách nhau dấu phẩy. Admin nhận thông báo nạp tiền và dùng được lệnh /admin trong bot.'],
        ['support_contact', 'Liên hệ hỗ trợ', 'text', 'vd: @admin hoặc số Zalo'],
        ['channel_url', 'Link kênh thông báo', 'text', ''],
        ['welcome_message', 'Lời chào /start', 'textarea', 'Hỗ trợ HTML Telegram (<b>, <i>, <code>). {shop} = tên shop'],
        ['maintenance', 'Chế độ bảo trì', 'bool', 'Bật: chỉ admin dùng được bot'],
        ['maintenance_message', 'Thông báo bảo trì', 'text', ''],
    ],
    '🏷 Giá bán' => [
        ['price_mode', 'Kiểu cộng lãi', 'select:percent=Theo %|fixed=Cộng số tiền cố định', ''],
        ['price_markup', 'Mức lãi', 'number', '% hoặc số tiền tùy kiểu ở trên'],
        ['price_round', 'Làm tròn lên bội số', 'number', 'vd: 100 → 1.234đ thành 1.300đ'],
        ['min_price', 'Giá bán tối thiểu', 'number', ''],
    ],
    '📱 Đơn thuê sim' => [
        ['order_timeout', 'Thời gian chờ OTP (giây)', 'number', 'Hết thời gian chưa có OTP → hoàn tiền. Nên đặt ≥ thời gian thuê của BossOTP để tránh hoàn tiền rồi OTP mới về'],
        ['max_pending_orders', 'Số đơn chờ tối đa / user', 'number', '0 = không giới hạn'],
        ['allow_cancel_after', 'Cho phép hủy sau (giây)', 'number', 'Chống thuê/hủy liên tục. 0 = hủy ngay được'],
        ['services_per_page', 'Số dịch vụ mỗi trang trong bot', 'number', ''],
        ['allow_network_select', 'Cho khách chọn nhà mạng', 'bool', 'Viettel, Mobifone, Vinaphone, Vietnamobile, Gmobile'],
        ['allow_re_number', 'Cho khách thuê lại số cũ', 'bool', 'Nút "Thuê lại số này" trên đơn đã thành công'],
        ['allow_user_cancel', 'Cho khách tự hủy đơn', 'bool', 'BossOTP không có API hủy: nếu bật, khách hủy sẽ được hoàn tiền nhưng shop vẫn có thể bị tính phí nếu OTP về sau đó'],
    ],
    '💰 Nạp tiền' => [
        ['bank_name', 'Tên ngân hàng', 'text', 'vd: MB Bank'],
        ['bank_bin', 'Mã BIN ngân hàng (VietQR)', 'text', 'MB=970422, VCB=970436, TCB=970407, ACB=970416, VPB=970432, TPB=970423, BIDV=970418, VTB=970415'],
        ['bank_account', 'Số tài khoản', 'text', ''],
        ['bank_owner', 'Chủ tài khoản', 'text', 'Viết HOA không dấu'],
        ['deposit_prefix', 'Tiền tố nội dung CK', 'text', 'Nội dung = tiền tố + mã 6 ký tự của user (vd: NAPAB12CD)'],
        ['min_deposit', 'Nạp tối thiểu', 'number', ''],
        ['deposit_bonus_percent', 'Khuyến mãi nạp (%)', 'number', ''],
        ['payment_webhook_key', 'Key webhook SePay/Casso', 'text', ''],
    ],
    '🎁 Giới thiệu' => [
        ['referral_percent', 'Hoa hồng giới thiệu (%)', 'number', 'Tính trên giá mỗi đơn thành công của người được giới thiệu'],
        ['bot_username', 'Username bot (không có @)', 'text', 'Dùng tạo link giới thiệu. Tự điền khi bấm "Kiểm tra bot"'],
    ],
];

$tg = new Telegram();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $action = $_POST['action'] ?? 'save';
    if ($action === 'save') {
        foreach ($groups as $fields) {
            foreach ($fields as [$key, , $type]) {
                if ($type === 'bool') {
                    Settings::set($key, isset($_POST[$key]) ? '1' : '0');
                } elseif (array_key_exists($key, $_POST)) {
                    Settings::set($key, trim((string)$_POST[$key]));
                }
            }
        }
        flash('Đã lưu cài đặt.');
    } elseif ($action === 'set_webhook') {
        $url = base_url() . '/webhook.php';
        $r = (new Telegram())->call('setWebhook', [
            'url' => $url,
            'secret_token' => cfg('secret'),
            'allowed_updates' => ['message', 'callback_query'],
            'drop_pending_updates' => 'true',
        ]);
        flash(!empty($r['ok']) ? 'Đã đặt webhook: ' . h($url) : 'Lỗi: ' . h($r['description'] ?? ''), !empty($r['ok']) ? 'success' : 'danger');
    } elseif ($action === 'delete_webhook') {
        $r = $tg->call('deleteWebhook');
        flash(!empty($r['ok']) ? 'Đã xóa webhook.' : 'Lỗi: ' . h($r['description'] ?? ''), !empty($r['ok']) ? 'success' : 'danger');
    } elseif ($action === 'check_bot') {
        $me = $tg->call('getMe');
        if (!empty($me['ok'])) {
            Settings::set('bot_username', $me['result']['username']);
            $tg->call('setMyCommands', ['commands' => [
                ['command' => 'start', 'description' => 'Bắt đầu'],
                ['command' => 'thuesim', 'description' => 'Thuê sim nhận OTP'],
                ['command' => 'naptien', 'description' => 'Nạp tiền'],
                ['command' => 'me', 'description' => 'Thông tin tài khoản'],
                ['command' => 'lichsu', 'description' => 'Lịch sử đơn'],
                ['command' => 'banggia', 'description' => 'Bảng giá'],
                ['command' => 'gioithieu', 'description' => 'Giới thiệu bạn bè'],
                ['command' => 'hotro', 'description' => 'Hỗ trợ'],
            ]]);
            flash('Bot hoạt động: @' . h($me['result']['username']) . ' (đã cập nhật menu lệnh).');
        } else {
            flash('Token không hợp lệ: ' . h($me['description'] ?? ''), 'danger');
        }
    } elseif ($action === 'password') {
        $a = DB::row('SELECT * FROM admins WHERE id = ?', [$_SESSION['admin_id']]);
        if (!password_verify($_POST['old'] ?? '', $a['password_hash'])) {
            flash('Mật khẩu cũ không đúng.', 'danger');
        } elseif (strlen($_POST['new'] ?? '') < 6) {
            flash('Mật khẩu mới tối thiểu 6 ký tự.', 'danger');
        } else {
            DB::update('admins', ['password_hash' => password_hash($_POST['new'], PASSWORD_DEFAULT)], 'id = ?', [$a['id']]);
            flash('Đã đổi mật khẩu.');
        }
    }
    redirect('settings.php');
}

$webhookInfo = Settings::get('bot_token') ? $tg->call('getWebhookInfo') : null;

layout_start('Cài đặt');
?>
<div class="card card-body mb-4">
    <h6>Kết nối Telegram</h6>
    <p class="mb-2 small">
        Webhook hiện tại: <code><?= h($webhookInfo['result']['url'] ?? '(chưa đặt)') ?></code>
        <?php if (!empty($webhookInfo['result']['last_error_message'])): ?><br><span class="text-danger">Lỗi gần nhất: <?= h($webhookInfo['result']['last_error_message']) ?></span><?php endif; ?>
        <?php if (isset($webhookInfo['result']['pending_update_count'])): ?><br>Update đang chờ: <?= (int)$webhookInfo['result']['pending_update_count'] ?><?php endif; ?>
    </p>
    <form method="post" class="d-flex flex-wrap gap-2">
        <?= csrf_field() ?>
        <button name="action" value="check_bot" class="btn btn-outline-primary btn-sm">Kiểm tra bot</button>
        <button name="action" value="set_webhook" class="btn btn-primary btn-sm">Đặt webhook → <?= h(base_url()) ?>/webhook.php</button>
        <button name="action" value="delete_webhook" class="btn btn-outline-danger btn-sm">Xóa webhook (dùng polling)</button>
    </form>
    <div class="small text-muted mt-2">
        Webhook yêu cầu HTTPS. Cron kiểm tra OTP (bắt buộc khi dùng webhook):<br>
        <code>* * * * * php <?= h(APP_ROOT) ?>/cron.php</code><br>
        hoặc gọi URL mỗi phút: <code><?= h(base_url()) ?>/cron.php?secret=<?= h(cfg('secret')) ?></code><br>
        Webhook nạp tiền tự động (SePay/Casso): <code><?= h(base_url()) ?>/payment_webhook.php</code>
    </div>
</div>

<form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save">
    <?php foreach ($groups as $title => $fields): ?>
        <div class="card card-body mb-4">
            <h6 class="mb-3"><?= h($title) ?></h6>
            <div class="row g-3">
            <?php foreach ($fields as [$key, $label, $type, $hint]): $val = (string)Settings::get($key); ?>
                <div class="<?= $type === 'textarea' ? 'col-12' : 'col-md-6' ?>">
                    <label class="form-label fw-semibold small"><?= h($label) ?></label>
                    <?php if ($type === 'textarea'): ?>
                        <textarea name="<?= $key ?>" class="form-control" rows="3"><?= h($val) ?></textarea>
                    <?php elseif ($type === 'bool'): ?>
                        <div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="<?= $key ?>" <?= $val === '1' ? 'checked' : '' ?>></div>
                    <?php elseif (str_starts_with($type, 'select:')): ?>
                        <select name="<?= $key ?>" class="form-select">
                            <?php foreach (explode('|', substr($type, 7)) as $opt): [$ov, $ol] = explode('=', $opt); ?>
                                <option value="<?= h($ov) ?>" <?= $val === $ov ? 'selected' : '' ?>><?= h($ol) ?></option>
                            <?php endforeach; ?>
                        </select>
                    <?php else: ?>
                        <input type="<?= $type === 'number' ? 'text' : $type ?>" name="<?= $key ?>" value="<?= h($val) ?>" class="form-control" <?= $type === 'number' ? 'inputmode="decimal"' : '' ?> autocomplete="off">
                    <?php endif; ?>
                    <?php if ($hint): ?><div class="form-text"><?= h($hint) ?></div><?php endif; ?>
                </div>
            <?php endforeach; ?>
            </div>
        </div>
    <?php endforeach; ?>
    <button class="btn btn-success mb-4">💾 Lưu cài đặt</button>
</form>

<form method="post" class="card card-body mb-4" style="max-width:420px">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="password">
    <h6>Đổi mật khẩu admin (<?= h($_SESSION['admin_name']) ?>)</h6>
    <input type="password" name="old" class="form-control mb-2" placeholder="Mật khẩu cũ" required>
    <input type="password" name="new" class="form-control mb-2" placeholder="Mật khẩu mới" required>
    <button class="btn btn-outline-primary">Đổi mật khẩu</button>
</form>
<?php layout_end();
