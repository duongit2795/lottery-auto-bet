<?php
require __DIR__ . '/_init.php';
require_login();

// [key, nhãn, kiểu, ghi chú]
$groups = [
    '🔑 BossOTP' => [
        ['api_key', 'API key BossOTP', 'password', 'Lấy trong trang tài khoản bossotp.net. Endpoint và các cấu hình nâng cao ở trang "API nhà cung cấp"'],
    ],
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
        ['order_timeout', 'Thời gian chờ mặc định (giây)', 'number', 'Mỗi dịch vụ dùng thời gian riêng theo API (trường timeout) hoặc chỉnh tay ở trang Dịch vụ. Giá trị này chỉ dùng khi dịch vụ không có thời gian'],
        ['max_pending_orders', 'Số đơn chờ tối đa / user', 'number', '0 = không giới hạn'],
        ['allow_cancel_after', 'Cho phép hủy sau (giây)', 'number', 'Chống thuê/hủy liên tục. 0 = hủy ngay được'],
        ['services_per_page', 'Số dịch vụ mỗi trang trong bot', 'number', ''],
        ['allow_network_select', 'Cho khách chọn nhà mạng', 'bool', 'Viettel, Mobifone, Vinaphone, Vietnamobile, Gmobile'],
        ['allow_re_number', 'Cho khách thuê lại số cũ', 'bool', 'Nút "Thuê lại số này" trên đơn đã thành công'],
        ['allow_user_cancel', 'Cho khách tự hủy đơn', 'bool', 'BossOTP không có API hủy: nếu bật, khách hủy sẽ được hoàn tiền nhưng shop vẫn có thể bị tính phí nếu OTP về sau đó'],
    ],
    '💰 Nạp tiền' => [
        ['bank_name', 'Tên ngân hàng', 'text', 'vd: MB Bank'],
        ['bank_bin', 'Ngân hàng nhận tiền (mã QR)', 'select:' . implode('|', array_map(fn($bin, $name) => "$bin=$name ($bin)", array_keys(Qr::BANKS), Qr::BANKS)), 'Chọn đúng ngân hàng của số tài khoản — mã QR dùng mã ngân hàng này. Bấm "Xem thử mã QR" ở đầu trang để kiểm tra'],
        ['bank_account', 'Số tài khoản', 'text', ''],
        ['bank_owner', 'Chủ tài khoản', 'text', 'Viết có dấu cũng được, bot tự chuyển sang HOA không dấu'],
        ['qr_source', 'Cách tạo mã QR', 'select:auto=Tự động (API VietQR → Link nhanh → tự vẽ)|api=API tạo mã QR của VietQR|link=Link tạo mã nhanh (img.vietqr.io)|local=Tự vẽ trên server (không cần internet)', 'Mọi cách đều tự chuyển sang tự vẽ khi VietQR lỗi'],
        ['qr_template', 'Mẫu ảnh QR', 'select:compact2=compact2 — có logo + thông tin TK|compact=compact — có logo|qr_only=qr_only — chỉ mã QR|print=print — bản in', 'Áp dụng cho ảnh từ VietQR'],
        ['vietqr_client_id', 'VietQR Client ID', 'text', 'Không bắt buộc. Đăng ký tại my.vietqr.io để dùng API tạo mã QR ổn định hơn'],
        ['vietqr_api_key', 'VietQR API Key', 'password', ''],
        ['deposit_prefix', 'Tiền tố nội dung CK', 'text', 'Nội dung = tiền tố + mã 6 ký tự của user (vd: NAPAB12CD)'],
        ['min_deposit', 'Nạp tối thiểu', 'number', ''],
        ['deposit_bonus_percent', 'Khuyến mãi nạp (%)', 'number', ''],
        ['bank_api_url', 'Link API lịch sử giao dịch (Web2M)', 'password', 'Dán link dạng https://api.web2m.com/historyapi.../MậtKhẩu/SốTK/Token. Bot quét mỗi ~20 giây (cần cron), thấy giao dịch có mã nạp sẽ tự cộng tiền. Lần đầu chỉ ghi nhận giao dịch cũ, không cộng. Bấm "Kiểm tra API ngân hàng" ở đầu trang để thử'],
        ['bank_api_interval', 'Quét lịch sử mỗi (giây)', 'number', 'Tối thiểu 5. Đừng đặt quá thấp để tránh bị Web2M giới hạn'],
        ['payment_webhook_key', 'Key webhook SePay/Casso', 'text', ''],
    ],
    '✉️ Gửi tin nhắn' => [
        ['sms_mode_auto', 'Tự cập nhật tính năng dịch vụ theo API khi đồng bộ', 'bool', 'Đọc is_support_send_custom_sms, is_support_send_default_sms, is_support_forward_call, is_support_call... Dịch vụ gửi được SMS sẽ tự hiện trong menu gửi tin'],
        ['sms_enabled', 'Bật chức năng gửi tin nhắn', 'bool', 'Menu "✉️ Gửi tin nhắn": khách thuê số của dịch vụ hỗ trợ gửi SMS rồi gửi tin từ số đó. Giá = giá bán dịch vụ, hoặc 1 lượt gói tin'],
        ['sms_max_length', 'Độ dài nội dung tối đa (ký tự)', 'number', ''],
        ['sms_rent_max_hours', 'Giữ số đã gửi tin tối đa (giờ)', 'number', 'Số thuê gửi được nhiều lần cho tới khi BossOTP báo không gửi thêm được, khách bấm Xong, hoặc hết số giờ này'],
        ['sms_refund_after_send', 'Hoàn tiền dù đã gửi tin', 'bool', 'Tắt (khuyên dùng): số đã gửi ít nhất 1 tin thì không hoàn khi hết hạn. Bật: vẫn hoàn nếu BossOTP báo FAILED/hết hạn'],
        ['sms_blacklist', 'Từ cấm trong nội dung', 'textarea', 'Cách nhau dấu phẩy. Chặn tin rác/lừa đảo, vd: otp,mat khau,chuyen khoan'],
    ],
    '🎁 Giới thiệu' => [
        ['referral_percent', 'Hoa hồng giới thiệu (%)', 'number', 'Tính trên giá mỗi đơn thành công của người được giới thiệu'],
        ['bot_username', 'Username bot (không có @)', 'text', 'Dùng tạo link giới thiệu. Tự điền khi bấm "Kiểm tra bot"'],
    ],
    '⚙️ Tự động hóa' => [
        ['auto_worker', 'Tiến trình tự động (không cần cron)', 'bool', 'Tự kiểm tra OTP, hoàn tiền đơn hết hạn, quét nạp tiền. Tự khởi động lại khi có người dùng bot/app/admin. Trạng thái xem ở trang Tổng quan'],
    ],
    '📲 Mini App' => [
        ['miniapp_enabled', 'Bật Mini App', 'bool', 'Ứng dụng mở ngay trong Telegram, dùng chung số dư/đơn hàng với bot. Sau khi đổi, bấm "Kiểm tra bot" để cập nhật nút menu'],
        ['miniapp_url', 'Link Mini App', 'text', 'Để trống = tự điền https://tên-miền/app/ khi bấm "Kiểm tra bot". Bắt buộc https'],
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
            $appNote = '';
            if (Settings::get('miniapp_enabled') === '1') {
                if (trim((string)Settings::get('miniapp_url')) === '') {
                    Settings::set('miniapp_url', base_url() . '/app/');
                }
                if ($app = Bot::miniAppUrl()) {
                    $tg->call('setChatMenuButton', ['menu_button' => ['type' => 'web_app', 'text' => 'Mở app', 'web_app' => ['url' => $app]]]);
                    $appNote = ' Đã cài nút Mini App: ' . h($app);
                } else {
                    $appNote = ' ⚠️ Mini App cần link https — kiểm tra ô "Link Mini App".';
                }
            } else {
                $tg->call('setChatMenuButton', ['menu_button' => ['type' => 'commands']]);
            }
            flash('Bot hoạt động: @' . h($me['result']['username']) . ' (đã cập nhật menu lệnh).' . $appNote);
        } else {
            flash('Token không hợp lệ: ' . h($me['description'] ?? ''), 'danger');
        }
    } elseif ($action === 'check_qr') {
        if ($err = Qr::configError()) {
            flash('❌ Mã QR: ' . h($err), 'danger');
        } else {
            @array_map('unlink', glob(APP_ROOT . '/data/qr/*.png') ?: []); // xóa ảnh cũ để tạo lại theo cấu hình mới
            $img = Qr::image(max(10000, Settings::int('min_deposit')), Settings::get('deposit_prefix') . 'TEST01');
            flash($img
                ? '✅ Tạo mã QR thành công (nguồn: ' . h($img['source']) . '). Quét thử bằng app ngân hàng — phải hiện đúng tên <b>' . h(Qr::plain((string)Settings::get('bank_owner'))) . '</b>:<br><img src="data:image/png;base64,' . base64_encode($img['bytes']) . '" style="max-width:260px;margin-top:8px;border-radius:8px">'
                : '❌ Không tạo được ảnh QR từ mọi nguồn — server có thể bị chặn kết nối ra ngoài. Xem data/logs/qr-*.log', $img ? 'success' : 'danger');
        }
    } elseif ($action === 'check_bank') {
        $r = BankSync::fetch();
        if (!$r['ok']) {
            flash('❌ API ngân hàng: ' . h($r['message']) . ($r['raw'] !== '' ? '<pre class="small mt-2 mb-0" style="white-space:pre-wrap">' . h(mb_substr($r['raw'], 0, 800)) . '</pre>' : ''), 'danger');
        } else {
            $in = array_values(array_filter($r['txs'], fn($t) => $t['in']));
            $lines = array_map(fn($t) => h(($t['time'] ?: '?') . ' | +' . money($t['amount']) . ' | ' . mb_substr($t['content'], 0, 80) . (($u = Wallet::userFromTransferContent($t['content'])) ? ' → user ' . $u['tg_id'] : '')), array_slice($in, 0, 5));
            flash('✅ Đọc được <b>' . count($r['txs']) . '</b> giao dịch (' . count($in) . ' tiền vào). 5 giao dịch tiền vào gần nhất:<br><small>' . (implode('<br>', $lines) ?: 'không có') . '</small>'
                . '<details class="mt-2"><summary class="small">Dữ liệu gốc</summary><pre class="small" style="white-space:pre-wrap">' . h(mb_substr($r['raw'], 0, 1500)) . '</pre></details>');
        }
    } elseif ($action === 'sync_bank') {
        $r = BankSync::sync(true);
        flash(h($r['message']), $r['ok'] ? 'success' : 'danger');
    } elseif ($action === 'check_api') {
        $r = (new OtpProvider())->request('balance');
        $bal = (new OtpProvider())->balance();
        flash($r['ok'] && $bal !== null ? '✅ API key hợp lệ. Số dư BossOTP: <b>' . money($bal) . '</b>' : '❌ API key không dùng được: ' . h($r['message'] ?: 'HTTP ' . $r['http']), $r['ok'] && $bal !== null ? 'success' : 'danger');
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
        <button name="action" value="check_api" class="btn btn-outline-primary btn-sm">Kiểm tra API key</button>
        <button name="action" value="check_qr" class="btn btn-outline-success btn-sm">Xem thử mã QR</button>
        <button name="action" value="check_bank" class="btn btn-outline-success btn-sm">Kiểm tra API ngân hàng</button>
        <button name="action" value="sync_bank" class="btn btn-outline-success btn-sm">Quét nạp tiền ngay</button>
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
                            <?php $opts = explode('|', substr($type, 7)); if ($val !== '' && !in_array($val, array_map(fn($o) => explode('=', $o)[0], $opts), true)) { $opts[] = $val . '=' . $val . ' (đang dùng)'; } ?>
                            <?php foreach ($opts as $opt): [$ov, $ol] = explode('=', $opt, 2); ?>
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
