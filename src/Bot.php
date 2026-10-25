<?php
declare(strict_types=1);

final class Bot
{
    private Telegram $tg;
    private ?array $user = null;

    private const BTN_RENT = '📱 Thuê sim';
    private const BTN_DEPOSIT = '💰 Nạp tiền';
    private const BTN_ACCOUNT = '👤 Tài khoản';
    private const BTN_HISTORY = '📜 Lịch sử';
    private const BTN_REF = '🎁 Giới thiệu';
    private const BTN_SUPPORT = '🆘 Hỗ trợ';
    private const BTN_PRICES = '🏷 Bảng giá';
    private const BTN_SMS = '✉️ Gửi tin nhắn';
    private const BTN_PKG = '📦 Gói tin nhắn';

    public function __construct()
    {
        $this->tg = new Telegram();
    }

    public function handle(array $update): void
    {
        try {
            if (isset($update['message'])) {
                $this->onMessage($update['message']);
            } elseif (isset($update['callback_query'])) {
                $this->onCallback($update['callback_query']);
            }
        } catch (Throwable $e) {
            app_log('bot', $e->getMessage() . "\n" . $e->getTraceAsString());
        }
    }

    // ------------------------------------------------------------------ users

    private function loadUser(array $from, ?string $refArg = null): array
    {
        return self::registerUser($from, $refArg);
    }

    /** Tạo hoặc cập nhật user từ dữ liệu Telegram (dùng chung cho bot và Mini App). */
    public static function registerUser(array $from, ?string $refArg = null): array
    {
        $u = DB::row('SELECT * FROM users WHERE tg_id = ?', [$from['id']]);
        if (!$u) {
            $refBy = null;
            if ($refArg && ctype_digit($refArg) && $refArg !== (string)$from['id'] && DB::value('SELECT 1 FROM users WHERE tg_id = ?', [$refArg])) {
                $refBy = $refArg;
            }
            do {
                $code = random_code(6);
            } while (DB::value('SELECT 1 FROM users WHERE deposit_code = ?', [$code]));
            DB::insert('users', [
                'tg_id' => $from['id'],
                'username' => $from['username'] ?? null,
                'first_name' => mb_substr($from['first_name'] ?? '', 0, 120),
                'ref_by' => $refBy,
                'deposit_code' => $code,
                'created_at' => now(),
                'last_active' => now(),
            ]);
            if ($refBy) {
                (new Telegram())->send($refBy, '👥 Có người mới tham gia qua link giới thiệu của bạn: ' . tg_escape($from['first_name'] ?? ''));
            }
        } else {
            DB::update('users', ['username' => $from['username'] ?? null, 'first_name' => mb_substr($from['first_name'] ?? '', 0, 120), 'last_active' => now()], 'id = ?', [$u['id']]);
        }
        return DB::row('SELECT * FROM users WHERE tg_id = ?', [$from['id']]);
    }

    private function isAdmin(): bool
    {
        return $this->user && in_array((string)$this->user['tg_id'], Settings::adminIds(), true);
    }

    private function setState(?string $state): void
    {
        DB::update('users', ['state' => $state], 'id = ?', [$this->user['id']]);
        $this->user['state'] = $state;
    }

    /** Link Mini App nếu đã bật (phải là https). */
    public static function miniAppUrl(): ?string
    {
        $url = trim((string)Settings::get('miniapp_url'));
        return Settings::get('miniapp_enabled') === '1' && str_starts_with($url, 'https://') ? $url : null;
    }

    private function mainKeyboard(): array
    {
        $app = self::miniAppUrl();
        return [
            'keyboard' => array_merge($app ? [[['text' => '📲 Mở ứng dụng', 'web_app' => ['url' => $app]]]] : [], [
                [['text' => self::BTN_RENT], ['text' => self::BTN_SMS]],
                [['text' => self::BTN_DEPOSIT], ['text' => self::BTN_PKG]],
                [['text' => self::BTN_ACCOUNT], ['text' => self::BTN_HISTORY]],
                [['text' => self::BTN_PRICES], ['text' => self::BTN_REF], ['text' => self::BTN_SUPPORT]],
            ]),
            'resize_keyboard' => true,
        ];
    }

    // --------------------------------------------------------------- messages

    private function onMessage(array $msg): void
    {
        if (($msg['chat']['type'] ?? '') !== 'private' || !isset($msg['from'])) {
            return;
        }
        $text = trim($msg['text'] ?? '');
        $chat = $msg['chat']['id'];

        $startArg = null;
        if (preg_match('/^\/start(?:\s+(\S+))?/', $text, $m)) {
            $startArg = $m[1] ?? null;
        }
        $this->user = $this->loadUser($msg['from'], $startArg);

        if ($this->user['is_banned']) {
            $this->tg->send($chat, '⛔ Tài khoản của bạn đã bị khóa. Liên hệ ' . tg_escape(Settings::get('support_contact')));
            return;
        }
        if (Settings::get('maintenance') === '1' && !$this->isAdmin()) {
            $this->tg->send($chat, (string)Settings::get('maintenance_message'));
            return;
        }

        // Lệnh admin
        if ($this->isAdmin() && str_starts_with($text, '/') && $this->adminCommand($chat, $text)) {
            return;
        }

        $cmd = strtolower(strtok($text, " @") ?: '');
        switch (true) {
            case $cmd === '/start':
                $this->setState(null);
                $welcome = str_replace('{shop}', tg_escape(Settings::get('shop_name')), (string)Settings::get('welcome_message'));
                $this->tg->send($chat, $welcome . "\n\nSố dư: <b>" . money($this->user['balance']) . "</b>\nChọn chức năng bên dưới 👇", $this->mainKeyboard());
                if ($app = self::miniAppUrl()) {
                    $this->tg->send($chat, '📲 Hoặc dùng <b>ứng dụng</b> ngay trong Telegram — giao diện dễ dùng, đầy đủ tính năng:', Telegram::inline([[['text' => '📲 Mở ứng dụng', 'web_app' => ['url' => $app]]]]));
                }
                return;
            case $text === self::BTN_RENT || $cmd === '/thuesim':
                $this->setState(null);
                $this->showServices($chat, 0);
                return;
            case $text === self::BTN_SMS || $cmd === '/guitin':
                $this->setState(null);
                $this->showSmsServices($chat);
                return;
            case $text === self::BTN_PKG || $cmd === '/goitin':
                $this->setState(null);
                $this->showPackages($chat);
                return;
            case $text === self::BTN_DEPOSIT || $cmd === '/naptien':
                $this->setState(null);
                $this->showDeposit($chat);
                return;
            case $text === self::BTN_ACCOUNT || $cmd === '/me':
                $this->setState(null);
                $this->showAccount($chat);
                return;
            case $text === self::BTN_HISTORY || $cmd === '/lichsu':
                $this->setState(null);
                $this->showHistory($chat);
                return;
            case $text === self::BTN_REF || $cmd === '/gioithieu':
                $this->showReferral($chat);
                return;
            case $text === self::BTN_PRICES || $cmd === '/banggia':
                $this->showPrices($chat);
                return;
            case $text === self::BTN_SUPPORT || $cmd === '/hotro' || $cmd === '/help':
                $this->showHelp($chat);
                return;
        }

        if ($this->handleSmsState($chat, $text)) {
            return;
        }

        if ($this->user['state'] === 'deposit_amount') {
            $amount = (int)preg_replace('/\D/', '', $text);
            if ($amount < Settings::int('min_deposit')) {
                $this->tg->send($chat, '⚠️ Số tiền tối thiểu là ' . money(Settings::int('min_deposit')) . '. Nhập lại:');
                return;
            }
            $this->setState(null);
            $this->sendDepositQr($chat, $amount);
            return;
        }

        // Mặc định: tìm dịch vụ theo tên
        if ($text !== '' && !str_starts_with($text, '/')) {
            $this->searchServices($chat, $text);
            return;
        }
        $this->tg->send($chat, 'Không hiểu lệnh. Chọn chức năng trên bàn phím 👇', $this->mainKeyboard());
    }

    // -------------------------------------------------------------- callbacks

    private function onCallback(array $cb): void
    {
        $this->user = $this->loadUser($cb['from']);
        $data = $cb['data'] ?? '';
        $chat = $cb['message']['chat']['id'] ?? $cb['from']['id'];
        $mid = $cb['message']['message_id'] ?? null;

        if ($this->user['is_banned']) {
            $this->tg->answer($cb['id'], 'Tài khoản đã bị khóa', true);
            return;
        }
        if (Settings::get('maintenance') === '1' && !$this->isAdmin()) {
            $this->tg->answer($cb['id'], 'Bot đang bảo trì', true);
            return;
        }

        $parts = explode(':', $data);
        $action = $parts[0];
        $arg = $parts[1] ?? '';

        switch ($action) {
            case 'svc':
                $this->tg->answer($cb['id']);
                $this->showServices($chat, (int)$arg, $mid);
                return;
            case 'buy':
                $this->tg->answer($cb['id']);
                $this->confirmBuy($chat, (int)$arg, $mid);
                return;
            case 'cfm':
                $net = OtpProvider::NETWORKS[(int)($parts[2] ?? -1)] ?? null;
                $this->doBuy($cb, $chat, (int)$arg, $mid, isset($parts[2]) && $net ? ['network' => $net] : []);
                return;
            case 'net':
                $this->tg->answer($cb['id']);
                $this->chooseNetwork($chat, (int)$arg, $mid);
                return;
            case 'bnet':
                $this->tg->answer($cb['id']);
                $this->confirmBuy($chat, (int)$arg, $mid, OtpProvider::NETWORKS[(int)($parts[2] ?? 0)] ?? '');
                return;
            case 're':
                $this->tg->answer($cb['id']);
                $this->confirmReNumber($chat, (int)$arg, $mid);
                return;
            case 'cfr':
                $old = DB::row('SELECT * FROM orders WHERE id = ? AND user_id = ?', [(int)$arg, $this->user['id']]);
                if (!$old || Settings::get('allow_re_number') !== '1') {
                    $this->tg->answer($cb['id'], 'Không thể thuê lại', true);
                    return;
                }
                $this->doBuy($cb, $chat, (int)$old['service_id'], $mid, ['re_number' => $old['phone']]);
                return;
            case 'smsf':
                $this->startSms($cb, $chat, 'fixed', (int)$arg);
                return;
            case 'smsc':
                $this->startSms($cb, $chat, 'custom', (int)$arg);
                return;
            case 'fwd':
                $this->startSms($cb, $chat, 'forward', (int)$arg);
                return;
            case 'chk':
                $this->checkOrder($cb, (int)$arg);
                return;
            case 'cxl':
                $this->cancelOrder($cb, (int)$arg);
                return;
            case 'ord':
                $this->tg->answer($cb['id']);
                $o = DB::row('SELECT * FROM orders WHERE id = ? AND user_id = ?', [(int)$arg, $this->user['id']]);
                if ($o) {
                    $this->tg->send($chat, OrderService::orderText($o), OrderService::orderKeyboard($o));
                }
                return;
            case 'dep':
                $this->tg->answer($cb['id']);
                if ($arg === 'custom') {
                    $this->setState('deposit_amount');
                    $this->tg->send($chat, '✍️ Nhập số tiền muốn nạp (tối thiểu ' . money(Settings::int('min_deposit')) . '):');
                } else {
                    $this->sendDepositQr($chat, (int)$arg);
                }
                return;
            case 'depdone':
                $this->depositClaim($cb, $chat, (int)$arg);
                return;
            case 'adep':
                $this->adminDepositAction($cb, $arg, (int)($parts[2] ?? 0));
                return;
            case 'smss':
                $this->tg->answer($cb['id']);
                $this->confirmSmsRent($chat, (int)$arg, $mid);
                return;
            case 'smsr':
                $this->doSmsRent($cb, $chat, (int)$arg, $mid);
                return;
            case 'smsend':
                $o = DB::row("SELECT * FROM orders WHERE id = ? AND user_id = ? AND status = 'pending' AND kind = 'sms'", [(int)$arg, $this->user['id']]);
                if (!$o) {
                    $this->tg->answer($cb['id'], 'Đơn đã kết thúc', true);
                    return;
                }
                $this->tg->answer($cb['id'], 'Đã kết thúc đơn');
                $this->setState(null);
                (new OrderService())->closeSmsRental($o, 'Khách kết thúc');
                return;
            case 'smsh':
                $this->tg->answer($cb['id']);
                $this->showSmsHistory($chat);
                return;
            case 'pkgs':
                $this->tg->answer($cb['id']);
                $this->showPackages($chat);
                return;
            case 'pkg':
                $this->tg->answer($cb['id']);
                $this->showPackage($chat, (int)$arg, $mid);
                return;
            case 'pkgb':
                $r = SmsService::buyPackage($this->user, (int)$arg);
                $this->tg->answer($cb['id'], $r['ok'] ? 'Mua gói thành công' : 'Không mua được gói', !$r['ok']);
                $kb = $r['ok'] ? Telegram::inline([[Telegram::btn('✉️ Gửi tin ngay', 'smsm')]]) : Telegram::inline([[Telegram::btn('💰 Nạp tiền', 'dep:custom')]]);
                $mid ? $this->tg->edit($chat, $mid, $r['message'], $kb) : $this->tg->send($chat, $r['message'], $kb);
                return;
            case 'smsm':
                $this->tg->answer($cb['id']);
                $this->showSmsServices($chat);
                return;
            case 'close':
                $this->tg->answer($cb['id']);
                if ($mid) {
                    $this->tg->call('deleteMessage', ['chat_id' => $chat, 'message_id' => $mid]);
                }
                return;
        }
        $this->tg->answer($cb['id']);
    }

    // --------------------------------------------------------------- features

    private function showServices($chat, int $page, $mid = null): void
    {
        $per = max(1, Settings::int('services_per_page'));
        $total = (int)DB::value('SELECT COUNT(*) FROM services WHERE is_active = 1');
        if ($total === 0) {
            $this->tg->send($chat, '😔 Hiện chưa có dịch vụ nào. Vui lòng quay lại sau.');
            return;
        }
        $pages = (int)ceil($total / $per);
        $page = max(0, min($page, $pages - 1));
        $rows = DB::all('SELECT * FROM services WHERE is_active = 1 ORDER BY sort_order DESC, name ASC LIMIT ' . $per . ' OFFSET ' . ($page * $per));

        $kb = [];
        foreach ($rows as $s) {
            $kb[] = [Telegram::btn($s['name'] . ' — ' . money($s['price']), 'buy:' . $s['id'])];
        }
        $nav = [];
        if ($page > 0) {
            $nav[] = Telegram::btn('⬅️ Trước', 'svc:' . ($page - 1));
        }
        $nav[] = Telegram::btn(($page + 1) . '/' . $pages, 'svc:' . $page);
        if ($page < $pages - 1) {
            $nav[] = Telegram::btn('Sau ➡️', 'svc:' . ($page + 1));
        }
        $kb[] = $nav;

        $text = "📱 <b>Chọn dịch vụ cần nhận OTP</b>\nSố dư: <b>" . money($this->user['balance']) . "</b>\n\n<i>💡 Mẹo: gõ tên dịch vụ (vd: facebook, zalo) để tìm nhanh.</i>";
        if ($mid) {
            $this->tg->edit($chat, $mid, $text, Telegram::inline($kb));
        } else {
            $this->tg->send($chat, $text, Telegram::inline($kb));
        }
    }

    private function searchServices($chat, string $q): void
    {
        $rows = DB::all('SELECT * FROM services WHERE is_active = 1 AND LOWER(name) LIKE ? ORDER BY sort_order DESC, name LIMIT 20', ['%' . mb_strtolower($q) . '%']);
        if (!$rows) {
            $this->tg->send($chat, '🔍 Không tìm thấy dịch vụ "' . tg_escape($q) . '". Bấm ' . self::BTN_RENT . ' để xem tất cả.', $this->mainKeyboard());
            return;
        }
        $kb = array_map(fn($s) => [Telegram::btn($s['name'] . ' — ' . money($s['price']), 'buy:' . $s['id'])], $rows);
        $this->tg->send($chat, '🔍 Kết quả cho "<b>' . tg_escape($q) . '</b>":', Telegram::inline($kb));
    }

    private function showPrices($chat): void
    {
        $rows = DB::all('SELECT name, price FROM services WHERE is_active = 1 ORDER BY sort_order DESC, name');
        if (!$rows) {
            $this->tg->send($chat, 'Chưa có dịch vụ.');
            return;
        }
        $lines = ["🏷 <b>BẢNG GIÁ DỊCH VỤ</b>\n"];
        foreach ($rows as $r) {
            $lines[] = '• ' . tg_escape($r['name']) . ': <b>' . money($r['price']) . '</b>';
        }
        foreach (array_chunk($lines, 80) as $chunk) {
            $this->tg->send($chat, implode("\n", $chunk));
        }
    }

    private function confirmBuy($chat, int $serviceId, $mid, string $network = ''): void
    {
        $s = DB::row('SELECT * FROM services WHERE id = ? AND is_active = 1', [$serviceId]);
        if (!$s) {
            $this->tg->send($chat, 'Dịch vụ không tồn tại hoặc đã tắt.');
            return;
        }
        $enough = (float)$this->user['balance'] >= (float)$s['price'];
        $features = array_filter(OrderService::FEATURES, fn($col) => !empty($s[$col]), ARRAY_FILTER_USE_KEY);
        $smsNote = $features ? "\nHỗ trợ: " . implode(', ', array_column($features, 1)) : '';
        $text = "🛒 <b>Xác nhận thuê sim</b>\n\nDịch vụ: <b>" . tg_escape($s['name']) . "</b>\nGiá: <b>" . money($s['price']) . "</b>\n"
            . 'Nhà mạng: <b>' . ($network !== '' ? tg_escape($network) : 'Bất kỳ') . "</b>\n"
            . 'Số dư: <b>' . money($this->user['balance']) . "</b>\n"
            . 'Thời gian chờ OTP: ' . OrderService::duration(OrderService::serviceTimeout($s)) . "$smsNote\n\n<i>Không nhận được OTP sẽ tự động hoàn tiền 100%.</i>";
        if ($enough) {
            $netIdx = array_search($network, OtpProvider::NETWORKS, true);
            $kb = [[Telegram::btn('✅ Xác nhận thuê', 'cfm:' . $s['id'] . ($netIdx !== false ? ':' . $netIdx : ''))]];
            if (Settings::get('allow_network_select') === '1') {
                $kb[] = [Telegram::btn('📶 Chọn nhà mạng', 'net:' . $s['id'])];
            }
        } else {
            $text .= "\n\n⚠️ Số dư không đủ, vui lòng nạp thêm.";
            $kb = [[Telegram::btn('💰 Nạp tiền', 'dep:custom')]];
        }
        $kb[] = [Telegram::btn('⬅️ Quay lại', 'svc:0')];
        if ($mid) {
            $this->tg->edit($chat, $mid, $text, Telegram::inline($kb));
        } else {
            $this->tg->send($chat, $text, Telegram::inline($kb));
        }
    }

    private function chooseNetwork($chat, int $serviceId, $mid): void
    {
        $kb = [[Telegram::btn('🌐 Bất kỳ', 'buy:' . $serviceId)]];
        foreach (array_chunk(OtpProvider::NETWORKS, 2, true) as $chunk) {
            $kb[] = array_map(fn($i) => Telegram::btn(OtpProvider::NETWORKS[$i], 'bnet:' . $serviceId . ':' . $i), array_keys($chunk));
        }
        $this->tg->edit($chat, $mid, '📶 <b>Chọn nhà mạng muốn thuê:</b>', Telegram::inline($kb));
    }

    /** Xác nhận thuê lại đúng số của đơn cũ. */
    private function confirmReNumber($chat, int $orderId, $mid): void
    {
        $o = DB::row('SELECT * FROM orders WHERE id = ? AND user_id = ?', [$orderId, $this->user['id']]);
        $s = $o ? DB::row('SELECT * FROM services WHERE id = ? AND is_active = 1', [$o['service_id']]) : null;
        if (!$o || !$s || Settings::get('allow_re_number') !== '1') {
            $this->tg->send($chat, 'Không thể thuê lại số này.');
            return;
        }
        $text = "♻️ <b>Thuê lại số cũ</b>\n\nSố: <code>" . tg_escape($o['phone']) . "</code>\nDịch vụ: <b>" . tg_escape($s['name']) . "</b>\nGiá: <b>" . money($s['price']) . "</b>\nSố dư: <b>" . money($this->user['balance']) . '</b>';
        $kb = (float)$this->user['balance'] >= (float)$s['price']
            ? [[Telegram::btn('✅ Xác nhận thuê lại', 'cfr:' . $o['id'])]]
            : [[Telegram::btn('💰 Nạp tiền', 'dep:custom')]];
        $this->tg->send($chat, $text, Telegram::inline($kb));
    }

    private function doBuy(array $cb, $chat, int $serviceId, $mid, array $opts = []): void
    {
        $s = DB::row('SELECT * FROM services WHERE id = ? AND is_active = 1', [$serviceId]);
        if (!$s) {
            $this->tg->answer($cb['id'], 'Dịch vụ không tồn tại', true);
            return;
        }
        $this->tg->answer($cb['id'], '⏳ Đang lấy số...');
        if ($mid) {
            $this->tg->edit($chat, $mid, '⏳ Đang lấy số cho <b>' . tg_escape($s['name']) . '</b>...');
        }
        $svc = new OrderService();
        $r = $svc->create($this->user, $s, $opts);
        if (!$r['ok']) {
            $text = $r['message'];
            $mid ? $this->tg->edit($chat, $mid, $text, Telegram::inline([[Telegram::btn('🔁 Thử lại', 'buy:' . $s['id'])]])) : $this->tg->send($chat, $text);
            return;
        }
        $o = $r['order'];
        $text = OrderService::orderText($o);
        $kb = OrderService::orderKeyboard($o);
        if ($mid) {
            $this->tg->edit($chat, $mid, $text, $kb);
            DB::update('orders', ['tg_message_id' => $mid], 'id = ?', [$o['id']]);
        } else {
            $res = $this->tg->send($chat, $text, $kb);
            DB::update('orders', ['tg_message_id' => $res['result']['message_id'] ?? null], 'id = ?', [$o['id']]);
        }
    }

    /** Bắt đầu gửi SMS / chuyển tiếp cuộc gọi từ nút trên đơn. */
    private function startSms(array $cb, $chat, string $kind, int $orderId): void
    {
        $col = ['fixed' => 'sup_default_sms', 'custom' => 'sup_custom_sms', 'forward' => 'sup_forward_call'][$kind];
        $o = DB::row("SELECT o.*, s.$col AS supported FROM orders o JOIN services s ON s.id = o.service_id WHERE o.id = ? AND o.user_id = ?", [$orderId, $this->user['id']]);
        if (!$o || $o['status'] !== 'pending' || !empty($o['sms_closed']) || !$o['supported']) {
            $this->tg->answer($cb['id'], 'Không thể thực hiện với đơn này', true);
            return;
        }
        if ($kind === 'fixed') {
            $r = (new OrderService())->sendSms($o, 'fixed');
            $this->tg->answer($cb['id'], $r['ok'] ? '✅ ' . $r['message'] : $r['message'], !$r['ok']);
            return;
        }
        $this->tg->answer($cb['id']);
        $this->setState(($kind === 'custom' ? 'sms_to:' : 'fwd_to:') . $o['id']);
        $this->tg->send($chat, $kind === 'custom'
            ? "✉️ Đơn #{$o['id']} — nhập <b>số điện thoại nhận tin nhắn</b>:\n<i>(gõ /huy để hủy thao tác)</i>"
            : "📞 Đơn #{$o['id']} — nhập <b>số điện thoại nhận cuộc gọi chuyển tiếp</b>:\n<i>(gõ /huy để hủy thao tác)</i>");
    }

    /** Báo kết quả gửi, kèm nút gửi tiếp nếu số còn gửi được. */
    private function sendResult($chat, array $o, string $again, array $r): void
    {
        $fresh = DB::row('SELECT * FROM orders WHERE id = ?', [$o['id']]);
        $kb = null;
        if ($fresh && $fresh['status'] === 'pending' && empty($fresh['sms_closed'])) {
            $kb = Telegram::inline([[
                Telegram::btn($again === 'fwd' ? '📞 Chuyển tiếp số khác' : '✉️ Gửi tin tiếp', $again . ':' . $o['id']),
                Telegram::btn('✅ Xong, kết thúc', 'smsend:' . $o['id']),
            ]]);
        }
        $this->tg->send($chat, ($r['ok'] ? '✅ ' : '❌ ') . tg_escape($r['message']), $kb ?? $this->mainKeyboard());
    }

    /** Xử lý các bước nhập liệu gửi SMS / chuyển tiếp. Trả về true nếu đã xử lý. */
    private function handleSmsState($chat, string $text): bool
    {
        $state = (string)$this->user['state'];
        if (!preg_match('/^(sms_to|sms_text|fwd_to):(\d+)(?::(\d+))?$/', $state, $m)) {
            return false;
        }
        if ($text === '/huy') {
            $this->setState(null);
            $this->tg->send($chat, 'Đã hủy thao tác.', $this->mainKeyboard());
            return true;
        }
        $o = DB::row('SELECT * FROM orders WHERE id = ? AND user_id = ?', [(int)$m[2], $this->user['id']]);
        if (!$o || $o['status'] !== 'pending') {
            $this->setState(null);
            $this->tg->send($chat, 'Đơn không còn ở trạng thái chờ.');
            return true;
        }
        if ($m[1] === 'sms_to' || $m[1] === 'fwd_to') {
            $num = preg_replace('/[\s.\-]/', '', $text);
            if (str_starts_with($num, '+84')) {
                $num = '0' . substr($num, 3);
            }
            if (!preg_match('/^0\d{9,10}$/', $num)) {
                $this->tg->send($chat, '⚠️ Số điện thoại không hợp lệ (vd: 0987654321). Nhập lại hoặc /huy:');
                return true;
            }
            if ($m[1] === 'fwd_to') {
                $this->setState(null);
                $r = (new OrderService())->sendSms($o, 'forward', $num);
                $this->sendResult($chat, $o, 'fwd', $r);
                return true;
            }
            $this->setState('sms_text:' . $o['id'] . ':' . $num);
            $this->tg->send($chat, "Nhập <b>nội dung tin nhắn</b> gửi tới <code>$num</code>:\n<i>(gõ /huy để hủy)</i>");
            return true;
        }
        // sms_text
        if ($err = SmsService::validateContent($text)) {
            $this->tg->send($chat, '⚠️ ' . tg_escape($err) . ' Nhập lại hoặc /huy:');
            return true;
        }
        $this->setState(null);
        $r = (new OrderService())->sendSms($o, 'custom', '0' . ltrim($m[3], '0'), $text);
        $this->sendResult($chat, $o, 'smsc', $r);
        return true;
    }

    private function checkOrder(array $cb, int $orderId): void
    {
        $o = DB::row('SELECT * FROM orders WHERE id = ? AND user_id = ?', [$orderId, $this->user['id']]);
        if (!$o) {
            $this->tg->answer($cb['id'], 'Không tìm thấy đơn', true);
            return;
        }
        if ($cb['message']['message_id'] ?? null) {
            DB::update('orders', ['tg_message_id' => $cb['message']['message_id']], 'id = ?', [$o['id']]);
            $o['tg_message_id'] = $cb['message']['message_id'];
        }
        $before = $o['status'];
        $o = (new OrderService())->poll($o);
        if ($o['status'] === 'pending') {
            $this->tg->answer($cb['id'], '⏳ Chưa có OTP, vui lòng đợi thêm...');
            (new OrderService())->notify($o); // cập nhật thời gian còn lại
        } else {
            $this->tg->answer($cb['id'], $o['status'] === 'success' ? '✅ Đã có OTP!' : 'Đơn đã kết thúc');
            if ($before !== 'pending') {
                (new OrderService())->notify($o);
            }
        }
    }

    private function cancelOrder(array $cb, int $orderId): void
    {
        $o = DB::row('SELECT * FROM orders WHERE id = ? AND user_id = ?', [$orderId, $this->user['id']]);
        if (!$o || $o['status'] !== 'pending' || !OrderService::userCanCancel()) {
            $this->tg->answer($cb['id'], 'Đơn không thể hủy. Hệ thống sẽ tự hoàn tiền nếu không nhận được OTP.', true);
            return;
        }
        $wait = Settings::int('allow_cancel_after') - (time() - strtotime($o['created_at']));
        if ($wait > 0) {
            $this->tg->answer($cb['id'], "Vui lòng đợi thêm {$wait} giây nữa mới có thể hủy.", true);
            return;
        }
        // Kiểm tra lần cuối xem OTP đã về chưa trước khi hủy
        $svc = new OrderService();
        $o = $svc->poll($o);
        if ($o['status'] !== 'pending') {
            $this->tg->answer($cb['id'], $o['status'] === 'success' ? 'OTP đã về, không thể hủy' : 'Đơn đã kết thúc', true);
            return;
        }
        $svc->refund($o, 'cancelled', 'Người dùng hủy');
        $this->tg->answer($cb['id'], 'Đã hủy và hoàn tiền');
    }

    private function showDeposit($chat): void
    {
        if (!Settings::get('bank_account')) {
            $this->tg->send($chat, 'Chức năng nạp tiền chưa được cấu hình. Liên hệ ' . tg_escape(Settings::get('support_contact')));
            return;
        }
        $amounts = [20000, 50000, 100000, 200000, 500000, 1000000];
        $amounts = array_values(array_filter($amounts, fn($a) => $a >= Settings::int('min_deposit')));
        $kb = array_map(fn($row) => array_map(fn($a) => Telegram::btn(money($a), 'dep:' . $a), $row), array_chunk($amounts, 3));
        $kb[] = [Telegram::btn('✍️ Nhập số khác', 'dep:custom')];
        $text = "💰 <b>NẠP TIỀN</b>\n\nSố dư: <b>" . money($this->user['balance']) . "</b>\nTối thiểu: " . money(Settings::int('min_deposit'));
        if ((float)Settings::get('deposit_bonus_percent') > 0) {
            $text .= "\n🎉 Khuyến mãi: +" . Settings::get('deposit_bonus_percent') . '% mỗi lần nạp';
        }
        $this->tg->send($chat, $text . "\n\nChọn số tiền muốn nạp:", Telegram::inline($kb));
    }

    private function sendDepositQr($chat, int $amount): void
    {
        if ($amount < Settings::int('min_deposit')) {
            $amount = Settings::int('min_deposit');
        }
        $info = Wallet::depositInfo($this->user, $amount);
        $memo = $info['memo'];
        $qr = $info['qr'];
        $caption = "🏦 <b>Chuyển khoản ngân hàng</b>\n\n"
            . 'Ngân hàng: <b>' . tg_escape(Settings::get('bank_name')) . "</b>\n"
            . 'Số tài khoản: <code>' . tg_escape(Settings::get('bank_account')) . "</code>\n"
            . 'Chủ tài khoản: <b>' . tg_escape($info['bank_owner']) . "</b>\n"
            . 'Số tiền: <b>' . money($info['amount']) . "</b>\n"
            . 'Nội dung: <code>' . tg_escape($memo) . "</code>\n\n"
            . "⚠️ Ghi <b>đúng nội dung</b> để được cộng tiền tự động.\nSau khi chuyển, bấm nút bên dưới nếu sau 5 phút chưa được cộng.";
        $kb = Telegram::inline([[Telegram::btn('✅ Tôi đã chuyển khoản', 'depdone:' . $info['amount'])]]);
        // Upload ảnh QR từ server (ổn định hơn để Telegram tự tải link). Lỗi thì gửi link, cuối cùng gửi chữ.
        $r = !empty($info['qr_file']) ? $this->tg->photo($chat, new CURLFile($info['qr_file'], 'image/png', 'qr.png'), $caption, $kb) : ['ok' => false];
        if (empty($r['ok'])) {
            $r = $this->tg->photo($chat, $qr, $caption, $kb);
        }
        if (empty($r['ok'])) {
            $this->tg->send($chat, $caption . "\n\n<i>(Không tạo được ảnh QR — vui lòng chuyển khoản theo thông tin trên)</i>", $kb);
            app_log('qr', 'Gửi QR thất bại: ' . ($r['description'] ?? ''));
        }
    }

    private function depositClaim(array $cb, $chat, int $amount): void
    {
        $r = Wallet::claimDeposit($this->user, $amount);
        $this->tg->answer($cb['id'], $r['ok'] ? 'Đã gửi yêu cầu, admin sẽ kiểm tra sớm!' : $r['message'], true);
        if ($r['ok']) {
            $this->tg->send($chat, $r['message']);
        }
    }

    private function adminDepositAction(array $cb, string $what, int $id): void
    {
        if (!$this->isAdmin()) {
            $this->tg->answer($cb['id'], 'Không có quyền', true);
            return;
        }
        $chat = $cb['message']['chat']['id'];
        $mid = $cb['message']['message_id'];
        if ($what === 'ok') {
            $ok = Wallet::approveDeposit($id);
            $this->tg->answer($cb['id'], $ok ? 'Đã duyệt' : 'Đã xử lý trước đó', !$ok);
            $this->tg->edit($chat, $mid, ($cb['message']['text'] ?? '') . "\n\n" . ($ok ? '✅ ĐÃ DUYỆT' : 'ℹ️ Đã xử lý trước đó'));
        } else {
            $n = DB::update('deposits', ['status' => 'rejected', 'updated_at' => now()], "id = ? AND status = 'pending'", [$id]);
            $this->tg->answer($cb['id'], $n ? 'Đã từ chối' : 'Đã xử lý trước đó');
            if ($n) {
                $uid = DB::value('SELECT u.tg_id FROM deposits d JOIN users u ON u.id = d.user_id WHERE d.id = ?', [$id]);
                $this->tg->send($uid, "❌ Yêu cầu nạp #$id bị từ chối. Liên hệ " . tg_escape(Settings::get('support_contact')) . ' nếu có thắc mắc.');
            }
            $this->tg->edit($chat, $mid, ($cb['message']['text'] ?? '') . "\n\n❌ ĐÃ TỪ CHỐI");
        }
    }

    // ------------------------------------------------------- gửi tin nhắn

    private function showSmsServices($chat): void
    {
        if (Settings::get('sms_enabled') !== '1') {
            $this->tg->send($chat, 'Chức năng gửi tin nhắn đang tạm tắt.');
            return;
        }
        $services = SmsService::services();
        if (!$services) {
            $this->tg->send($chat, '😔 Hiện chưa có dịch vụ gửi tin nhắn. Vui lòng quay lại sau.');
            return;
        }
        $uid = (int)$this->user['id'];
        $kb = array_map(fn($s) => [Telegram::btn($s['name'] . ' — ' . (SmsService::credits($uid, (int)$s['id']) > 0 ? '1 lượt gói' : money($s['price'])), 'smss:' . $s['id'])], $services);
        $kb[] = [Telegram::btn('📦 Mua gói tin', 'pkgs'), Telegram::btn('📜 Lịch sử gửi', 'smsh')];
        $this->tg->send($chat, "✉️ <b>GỬI TIN NHẮN</b>\n\n"
            . "<b>Cách dùng:</b>\n1️⃣ Chọn dịch vụ → bot <b>thuê 1 số</b> cho bạn\n2️⃣ Có số rồi, bấm <b>✉️ Gửi tin nhắn từ số này</b>\n3️⃣ Nhập số nhận + nội dung → tin được gửi từ số đã thuê\n4️⃣ Bấm <b>Gửi tin tiếp</b> để gửi thêm, xong bấm <b>✅ Xong</b>\n\n"
            . '<i>1 số thuê gửi được nhiều tin cho tới khi hệ thống báo không gửi thêm được. Chưa gửi được tin nào mà hết hạn → tự hoàn tiền (hoặc hoàn lượt gói).</i>' . "\n\n"
            . 'Số dư: <b>' . money($this->user['balance']) . '</b> · Lượt gói: <b>' . SmsService::credits($uid) . "</b>\n\nChọn dịch vụ:", Telegram::inline($kb));
    }

    private function confirmSmsRent($chat, int $serviceId, $mid): void
    {
        $s = DB::row('SELECT * FROM services WHERE id = ? AND is_active = 1 AND (sup_custom_sms = 1 OR sup_default_sms = 1 OR sup_forward_call = 1)', [$serviceId]);
        if (!$s) {
            $this->tg->send($chat, 'Dịch vụ không tồn tại hoặc không hỗ trợ gửi tin.');
            return;
        }
        $credits = SmsService::credits((int)$this->user['id'], (int)$s['id']);
        $features = array_filter(OrderService::FEATURES, fn($col) => !empty($s[$col]) && in_array($col, ['sup_custom_sms', 'sup_default_sms', 'sup_forward_call'], true), ARRAY_FILTER_USE_KEY);
        $text = "✉️ <b>Thuê số gửi tin</b>\n\nDịch vụ: <b>" . tg_escape($s['name']) . "</b>\n"
            . 'Hỗ trợ: ' . implode(', ', array_column($features, 1)) . "\n"
            . 'Thanh toán: <b>' . ($credits > 0 ? "1 lượt gói</b> (còn $credits)" : money($s['price']) . '</b>') . "\n"
            . 'Số dư: ' . money($this->user['balance']) . "\n"
            . 'Thời gian giữ số: ' . OrderService::duration(OrderService::serviceTimeout($s)) . "\n\n"
            . '<i>Bấm Thuê số → bot lấy số cho bạn, sau đó bạn nhập số nhận và nội dung để gửi.</i>';
        $kb = $credits > 0 || (float)$this->user['balance'] >= (float)$s['price']
            ? [[Telegram::btn('✅ Thuê số', 'smsr:' . $s['id'])]]
            : [[Telegram::btn('💰 Nạp tiền', 'dep:custom'), Telegram::btn('📦 Mua gói', 'pkgs')]];
        $kb[] = [Telegram::btn('⬅️ Quay lại', 'smsm')];
        if (!($credits > 0 || (float)$this->user['balance'] >= (float)$s['price'])) {
            $text .= "\n\n⚠️ Số dư không đủ, vui lòng nạp thêm hoặc mua gói tin.";
        }
        $mid ? $this->tg->edit($chat, $mid, $text, Telegram::inline($kb)) : $this->tg->send($chat, $text, Telegram::inline($kb));
    }

    private function doSmsRent(array $cb, $chat, int $serviceId, $mid): void
    {
        $s = DB::row('SELECT * FROM services WHERE id = ? AND is_active = 1 AND (sup_custom_sms = 1 OR sup_default_sms = 1 OR sup_forward_call = 1)', [$serviceId]);
        if (!$s) {
            $this->tg->answer($cb['id'], 'Dịch vụ không hỗ trợ gửi tin', true);
            return;
        }
        $this->tg->answer($cb['id'], '⏳ Đang thuê số...');
        if ($mid) {
            $this->tg->edit($chat, $mid, '⏳ Đang thuê số <b>' . tg_escape($s['name']) . '</b>...');
        }
        $r = (new OrderService())->create($this->user, $s, ['kind' => 'sms']);
        if (!$r['ok']) {
            $kb = Telegram::inline([[Telegram::btn('🔁 Thử lại', 'smss:' . $s['id'])]]);
            $mid ? $this->tg->edit($chat, $mid, $r['message'], $kb) : $this->tg->send($chat, $r['message'], $kb);
            return;
        }
        $o = $r['order'];
        $text = OrderService::orderText($o);
        $kb = OrderService::orderKeyboard($o);
        if ($mid) {
            $this->tg->edit($chat, $mid, $text, $kb);
            DB::update('orders', ['tg_message_id' => $mid], 'id = ?', [$o['id']]);
        } else {
            $res = $this->tg->send($chat, $text, $kb);
            DB::update('orders', ['tg_message_id' => $res['result']['message_id'] ?? null], 'id = ?', [$o['id']]);
        }
        // Dịch vụ chỉ có SMS tùy chọn → hỏi luôn số nhận cho nhanh
        if ($s['sup_custom_sms'] && !$s['sup_default_sms'] && !$s['sup_forward_call']) {
            $this->setState('sms_to:' . $o['id']);
            $this->tg->send($chat, "✉️ Đã có số <code>" . tg_escape($o['phone']) . "</code>. Nhập <b>số điện thoại nhận tin nhắn</b>:\n<i>(gõ /huy để làm sau — bấm nút trên đơn để gửi)</i>");
        }
    }

    private function showSmsHistory($chat): void
    {
        $rows = DB::all("SELECT * FROM orders WHERE user_id = ? AND (kind = 'sms' OR sms_sent > 0) ORDER BY id DESC LIMIT 15", [$this->user['id']]);
        if (!$rows) {
            $this->tg->send($chat, 'Bạn chưa thuê số gửi tin nào.');
            return;
        }
        $icon = ['pending' => '⏳', 'success' => '✅', 'refunded' => '↩️', 'cancelled' => '❌'];
        $t = "📜 <b>LỊCH SỬ GỬI TIN</b>\n";
        $kb = [];
        foreach ($rows as $o) {
            $t .= "\n" . ($icon[$o['status']] ?? '•') . " #{$o['id']} " . date('d/m H:i', strtotime($o['created_at'])) . ' · <code>' . tg_escape($o['phone']) . '</code> · '
                . ((int)$o['sms_sent'] > 0 ? 'đã gửi ' . (int)$o['sms_sent'] . ' lần' : ($o['status'] === 'pending' ? 'chưa gửi' : 'không gửi'));
            $kb[] = Telegram::btn('#' . $o['id'], 'ord:' . $o['id']);
        }
        $this->tg->send($chat, $t, Telegram::inline(array_chunk($kb, 5)));
    }

    // ----------------------------------------------------------- gói tin

    private function showPackages($chat): void
    {
        $pkgs = DB::all('SELECT p.*, s.name AS service_name FROM sms_packages p LEFT JOIN services s ON s.id = p.service_id WHERE p.is_active = 1 ORDER BY p.sort_order DESC, p.price');
        $mine = SmsService::activePackages((int)$this->user['id']);
        $t = "📦 <b>GÓI TIN NHẮN</b>\n\nMua gói để gửi tin với giá rẻ hơn. Mỗi lượt = 1 lần thuê số gửi tin.\n";
        if ($mine) {
            $t .= "\n<b>Gói của bạn:</b>\n";
            foreach ($mine as $m) {
                $t .= '• ' . tg_escape($m['name']) . ": <b>{$m['credits_left']}/{$m['credits_total']}</b> lượt"
                    . ($m['expires_at'] ? ' — HSD ' . date('d/m/Y H:i', strtotime($m['expires_at'])) : '') . "\n";
            }
        }
        if (!$pkgs) {
            $this->tg->send($chat, $t . "\nHiện chưa có gói nào được mở bán.");
            return;
        }
        $kb = [];
        foreach ($pkgs as $p) {
            $kb[] = [Telegram::btn($p['name'] . ' — ' . money($p['price']), 'pkg:' . $p['id'])];
        }
        $kb[] = [Telegram::btn('✉️ Gửi tin nhắn', 'smsm')];
        $this->tg->send($chat, $t . "\nChọn gói muốn mua:", Telegram::inline($kb));
    }

    private function showPackage($chat, int $id, $mid): void
    {
        $p = DB::row('SELECT p.*, s.name AS service_name, s.price AS unit FROM sms_packages p LEFT JOIN services s ON s.id = p.service_id WHERE p.id = ? AND p.is_active = 1', [$id]);
        if (!$p) {
            $this->tg->send($chat, 'Gói không tồn tại.');
            return;
        }
        $per = (int)$p['credits'] > 0 ? (float)$p['price'] / (int)$p['credits'] : 0;
        $t = '📦 <b>' . tg_escape($p['name']) . "</b>\n\n"
            . 'Số lượt: <b>' . (int)$p['credits'] . " lần thuê số gửi tin</b>\n"
            . 'Giá: <b>' . money($p['price']) . '</b> (~' . money($per) . "/tin)\n"
            . 'Hạn dùng: <b>' . ((int)$p['days_valid'] > 0 ? (int)$p['days_valid'] . ' ngày' : 'Vĩnh viễn') . "</b>\n"
            . 'Áp dụng: <b>' . tg_escape($p['service_name'] ?: 'Tất cả dịch vụ gửi tin') . "</b>\n"
            . ($p['description'] ? "\n" . tg_escape($p['description']) . "\n" : '')
            . "\nSố dư của bạn: " . money($this->user['balance']);
        $kb = (float)$this->user['balance'] >= (float)$p['price']
            ? [[Telegram::btn('🛒 Mua gói này', 'pkgb:' . $p['id'])], [Telegram::btn('⬅️ Quay lại', 'pkgs')]]
            : [[Telegram::btn('💰 Nạp tiền', 'dep:custom')], [Telegram::btn('⬅️ Quay lại', 'pkgs')]];
        $mid ? $this->tg->edit($chat, $mid, $t, Telegram::inline($kb)) : $this->tg->send($chat, $t, Telegram::inline($kb));
    }

    private function showAccount($chat): void
    {
        $u = $this->user;
        $orders = (int)DB::value("SELECT COUNT(*) FROM orders WHERE user_id = ? AND status = 'success'", [$u['id']]);
        $text = "👤 <b>THÔNG TIN TÀI KHOẢN</b>\n\n"
            . "ID: <code>{$u['tg_id']}</code>\n"
            . 'Tên: ' . tg_escape($u['first_name']) . ($u['username'] ? ' (@' . tg_escape($u['username']) . ')' : '') . "\n"
            . '💰 Số dư: <b>' . money($u['balance']) . "</b>\n"
            . '📥 Tổng nạp: ' . money($u['total_deposit']) . "\n"
            . '📤 Tổng chi: ' . money($u['total_spent']) . "\n"
            . "✅ Đơn thành công: $orders\n"
            . '🎁 Hoa hồng: ' . money($u['ref_earned']) . "\n"
            . '📦 Lượt gói tin còn lại: ' . SmsService::credits((int)$u['id']) . "\n"
            . '📅 Tham gia: ' . date('d/m/Y', strtotime($u['created_at']));
        $tx = DB::all('SELECT * FROM transactions WHERE user_id = ? ORDER BY id DESC LIMIT 5', [$u['id']]);
        if ($tx) {
            $text .= "\n\n<b>Biến động số dư gần đây:</b>";
            foreach ($tx as $t) {
                $text .= "\n" . ((float)$t['amount'] >= 0 ? '➕' : '➖') . ' ' . money(abs((float)$t['amount'])) . ' — ' . tg_escape($t['note']) . ' <i>(' . date('d/m H:i', strtotime($t['created_at'])) . ')</i>';
            }
        }
        $this->tg->send($chat, $text);
    }

    private function showHistory($chat): void
    {
        $rows = DB::all('SELECT * FROM orders WHERE user_id = ? ORDER BY id DESC LIMIT 10', [$this->user['id']]);
        if (!$rows) {
            $this->tg->send($chat, 'Bạn chưa có đơn hàng nào.');
            return;
        }
        $icon = ['pending' => '⏳', 'success' => '✅', 'refunded' => '↩️', 'cancelled' => '❌'];
        $text = "📜 <b>10 ĐƠN GẦN NHẤT</b>\n";
        $kb = [];
        foreach ($rows as $o) {
            $text .= "\n" . ($icon[$o['status']] ?? '•') . " #{$o['id']} " . tg_escape($o['service_name']) . ' | <code>' . tg_escape($o['phone']) . '</code>'
                . ($o['otp'] ? ' | OTP <code>' . tg_escape($o['otp']) . '</code>' : '')
                . ' | ' . date('d/m H:i', strtotime($o['created_at']));
            $kb[] = Telegram::btn('#' . $o['id'], 'ord:' . $o['id']);
        }
        $this->tg->send($chat, $text, Telegram::inline(array_chunk($kb, 5)));
    }

    private function showReferral($chat): void
    {
        $botName = Settings::get('bot_username');
        $link = $botName ? "https://t.me/{$botName}?start={$this->user['tg_id']}" : '(admin chưa cấu hình username bot)';
        $count = (int)DB::value('SELECT COUNT(*) FROM users WHERE ref_by = ?', [$this->user['tg_id']]);
        $this->tg->send($chat, "🎁 <b>GIỚI THIỆU BẠN BÈ</b>\n\nNhận <b>" . Settings::get('referral_percent') . "%</b> giá trị mỗi đơn thành công của người bạn giới thiệu!\n\n"
            . "🔗 Link của bạn:\n<code>$link</code>\n\n👥 Đã giới thiệu: $count người\n💵 Hoa hồng đã nhận: " . money($this->user['ref_earned']));
    }

    private function showHelp($chat): void
    {
        $text = "🆘 <b>HƯỚNG DẪN</b>\n\n"
            . "1️⃣ Nạp tiền qua chuyển khoản (" . self::BTN_DEPOSIT . ")\n"
            . "2️⃣ Chọn " . self::BTN_RENT . " → chọn dịch vụ → xác nhận\n"
            . "3️⃣ Dùng số được cấp để đăng ký, OTP sẽ tự gửi về bot\n"
            . "4️⃣ Không nhận được OTP → tự động hoàn tiền\n"
            . "5️⃣ Dịch vụ có hỗ trợ: gửi SMS / chuyển tiếp cuộc gọi bằng nút trên đơn, chọn nhà mạng, thuê lại số cũ\n\n"
            . "<b>Lệnh:</b>\n/thuesim — Thuê sim\n/guitin — Gửi tin nhắn\n/goitin — Gói tin nhắn\n/naptien — Nạp tiền\n/me — Tài khoản\n/lichsu — Lịch sử\n/banggia — Bảng giá\n/gioithieu — Giới thiệu\n\n"
            . '📞 Hỗ trợ: ' . tg_escape(Settings::get('support_contact'));
        $kb = Settings::get('channel_url') ? Telegram::inline([[Telegram::url('📢 Kênh thông báo', (string)Settings::get('channel_url'))]]) : null;
        $this->tg->send($chat, $text, $kb);
    }

    // ------------------------------------------------------------ admin cmds

    private function adminCommand($chat, string $text): bool
    {
        $args = preg_split('/\s+/', $text);
        $cmd = strtolower(explode('@', $args[0])[0]);
        switch ($cmd) {
            case '/admin':
                $stats = [
                    'Người dùng' => DB::value('SELECT COUNT(*) FROM users'),
                    'Đơn hôm nay' => DB::value("SELECT COUNT(*) FROM orders WHERE status = 'success' AND created_at >= ?", [date('Y-m-d 00:00:00')]),
                    'Doanh thu hôm nay' => money(DB::value("SELECT COALESCE(SUM(price),0) FROM orders WHERE status = 'success' AND created_at >= ?", [date('Y-m-d 00:00:00')])),
                    'Lãi hôm nay' => money(DB::value("SELECT COALESCE(SUM(price - cost),0) FROM orders WHERE status = 'success' AND created_at >= ?", [date('Y-m-d 00:00:00')])),
                    'Đơn đang chờ' => DB::value("SELECT COUNT(*) FROM orders WHERE status = 'pending'"),
                    'Nạp chờ duyệt' => DB::value("SELECT COUNT(*) FROM deposits WHERE status = 'pending'"),
                    'Tổng số dư user' => money(DB::value('SELECT COALESCE(SUM(balance),0) FROM users')),
                ];
                $bal = (new OtpProvider())->balance();
                $stats['Số dư API'] = $bal === null ? 'lỗi' : money($bal);
                $t = "🛠 <b>ADMIN</b>\n";
                foreach ($stats as $k => $v) {
                    $t .= "\n$k: <b>$v</b>";
                }
                $t .= "\n\n<b>Lệnh:</b>\n/cong &lt;tg_id&gt; &lt;tiền&gt; — cộng tiền\n/tru &lt;tg_id&gt; &lt;tiền&gt; — trừ tiền\n/ban &lt;tg_id&gt; | /unban &lt;tg_id&gt;\n/user &lt;tg_id&gt; — xem user\n/thongbao &lt;nội dung&gt; — gửi tất cả\n/baotri on|off";
                $this->tg->send($chat, $t);
                return true;
            case '/cong':
            case '/tru':
                $u = isset($args[1]) ? DB::row('SELECT * FROM users WHERE tg_id = ?', [$args[1]]) : null;
                $amount = (int)preg_replace('/\D/', '', $args[2] ?? '');
                if (!$u || $amount <= 0) {
                    $this->tg->send($chat, "Cú pháp: $cmd &lt;tg_id&gt; &lt;số tiền&gt;");
                    return true;
                }
                $delta = $cmd === '/cong' ? $amount : -$amount;
                $after = Wallet::change((int)$u['id'], $delta, 'admin', 'Admin điều chỉnh');
                if ($after === null) {
                    $this->tg->send($chat, 'Số dư user không đủ để trừ.');
                    return true;
                }
                $this->tg->send($chat, "OK. Số dư mới của {$u['tg_id']}: " . money($after));
                $this->tg->send($u['tg_id'], ($delta > 0 ? '➕ Bạn được cộng ' : '➖ Bạn bị trừ ') . money($amount) . '. Số dư: <b>' . money($after) . '</b>');
                return true;
            case '/ban':
            case '/unban':
                $n = DB::update('users', ['is_banned' => $cmd === '/ban' ? 1 : 0], 'tg_id = ?', [$args[1] ?? '']);
                $this->tg->send($chat, $n ? 'Đã cập nhật.' : 'Không tìm thấy user.');
                return true;
            case '/user':
                $u = DB::row('SELECT * FROM users WHERE tg_id = ?', [$args[1] ?? '']);
                $this->tg->send($chat, $u ? '<pre>' . tg_escape(print_r(array_diff_key($u, ['state' => 1]), true)) . '</pre>' : 'Không tìm thấy.');
                return true;
            case '/thongbao':
                $msg = trim(substr($text, strlen($args[0])));
                if ($msg === '') {
                    $this->tg->send($chat, 'Cú pháp: /thongbao &lt;nội dung HTML&gt;');
                    return true;
                }
                [$ok, $fail] = self::broadcast($msg);
                $this->tg->send($chat, "Đã gửi: $ok, lỗi: $fail");
                return true;
            case '/baotri':
                Settings::set('maintenance', ($args[1] ?? '') === 'on' ? '1' : '0');
                $this->tg->send($chat, 'Bảo trì: ' . (Settings::get('maintenance') === '1' ? 'BẬT' : 'TẮT'));
                return true;
        }
        return false;
    }

    /** Gửi thông báo tới toàn bộ user. Trả về [thành công, thất bại]. */
    public static function broadcast(string $message): array
    {
        $tg = new Telegram();
        $ok = $fail = 0;
        foreach (DB::all('SELECT tg_id FROM users WHERE is_banned = 0') as $u) {
            $r = $tg->send($u['tg_id'], $message);
            !empty($r['ok']) ? $ok++ : $fail++;
            usleep(40000); // ~25 tin/giây, dưới giới hạn của Telegram
        }
        DB::insert('broadcasts', ['message' => $message, 'sent' => $ok, 'failed' => $fail, 'created_at' => now()]);
        return [$ok, $fail];
    }
}
