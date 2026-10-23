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
                $this->tg->send($refBy, '👥 Có người mới tham gia qua link giới thiệu của bạn: ' . tg_escape($from['first_name'] ?? ''));
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

    private function mainKeyboard(): array
    {
        return [
            'keyboard' => [
                [['text' => self::BTN_RENT], ['text' => self::BTN_DEPOSIT]],
                [['text' => self::BTN_ACCOUNT], ['text' => self::BTN_HISTORY]],
                [['text' => self::BTN_PRICES], ['text' => self::BTN_REF], ['text' => self::BTN_SUPPORT]],
            ],
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
                return;
            case $text === self::BTN_RENT || $cmd === '/thuesim':
                $this->setState(null);
                $this->showServices($chat, 0);
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
                $this->doBuy($cb, $chat, (int)$arg, $mid);
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

    private function confirmBuy($chat, int $serviceId, $mid): void
    {
        $s = DB::row('SELECT * FROM services WHERE id = ? AND is_active = 1', [$serviceId]);
        if (!$s) {
            $this->tg->send($chat, 'Dịch vụ không tồn tại hoặc đã tắt.');
            return;
        }
        $enough = (float)$this->user['balance'] >= (float)$s['price'];
        $text = "🛒 <b>Xác nhận thuê sim</b>\n\nDịch vụ: <b>" . tg_escape($s['name']) . "</b>\nGiá: <b>" . money($s['price']) . "</b>\nSố dư: <b>" . money($this->user['balance']) . "</b>\n"
            . 'Thời gian chờ OTP: ' . round(Settings::int('order_timeout') / 60) . " phút\n\n<i>Không nhận được OTP sẽ tự động hoàn tiền 100%.</i>";
        $kb = $enough
            ? [[Telegram::btn('✅ Xác nhận thuê', 'cfm:' . $s['id'])], [Telegram::btn('⬅️ Quay lại', 'svc:0')]]
            : [[Telegram::btn('💰 Nạp tiền', 'dep:custom')], [Telegram::btn('⬅️ Quay lại', 'svc:0')]];
        if (!$enough) {
            $text .= "\n\n⚠️ Số dư không đủ, vui lòng nạp thêm.";
        }
        if ($mid) {
            $this->tg->edit($chat, $mid, $text, Telegram::inline($kb));
        } else {
            $this->tg->send($chat, $text, Telegram::inline($kb));
        }
    }

    private function doBuy(array $cb, $chat, int $serviceId, $mid): void
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
        $r = $svc->create($this->user, $s);
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
        if (!$o || $o['status'] !== 'pending') {
            $this->tg->answer($cb['id'], 'Đơn không thể hủy', true);
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

    private function depositMemo(): string
    {
        return Settings::get('deposit_prefix') . $this->user['deposit_code'];
    }

    private function sendDepositQr($chat, int $amount): void
    {
        if ($amount < Settings::int('min_deposit')) {
            $amount = Settings::int('min_deposit');
        }
        $memo = $this->depositMemo();
        $qr = sprintf(
            'https://img.vietqr.io/image/%s-%s-compact2.png?amount=%d&addInfo=%s&accountName=%s',
            rawurlencode((string)Settings::get('bank_bin')),
            rawurlencode((string)Settings::get('bank_account')),
            $amount,
            rawurlencode($memo),
            rawurlencode((string)Settings::get('bank_owner'))
        );
        $caption = "🏦 <b>Chuyển khoản ngân hàng</b>\n\n"
            . 'Ngân hàng: <b>' . tg_escape(Settings::get('bank_name')) . "</b>\n"
            . 'Số tài khoản: <code>' . tg_escape(Settings::get('bank_account')) . "</code>\n"
            . 'Chủ tài khoản: <b>' . tg_escape(Settings::get('bank_owner')) . "</b>\n"
            . 'Số tiền: <b>' . money($amount) . "</b>\n"
            . 'Nội dung: <code>' . tg_escape($memo) . "</code>\n\n"
            . "⚠️ Ghi <b>đúng nội dung</b> để được cộng tiền tự động.\nSau khi chuyển, bấm nút bên dưới nếu sau 5 phút chưa được cộng.";
        $kb = Telegram::inline([[Telegram::btn('✅ Tôi đã chuyển khoản', 'depdone:' . $amount)]]);
        $r = $this->tg->photo($chat, $qr, $caption, $kb);
        if (empty($r['ok'])) {
            $this->tg->send($chat, $caption, $kb);
        }
    }

    private function depositClaim(array $cb, $chat, int $amount): void
    {
        $exists = DB::value("SELECT id FROM deposits WHERE user_id = ? AND status = 'pending' AND method = 'manual'", [$this->user['id']]);
        if ($exists) {
            $this->tg->answer($cb['id'], 'Bạn đã có yêu cầu đang chờ duyệt. Vui lòng đợi admin kiểm tra.', true);
            return;
        }
        $id = DB::insert('deposits', [
            'user_id' => $this->user['id'],
            'amount' => $amount,
            'method' => 'manual',
            'note' => 'Người dùng báo đã chuyển: ' . $this->depositMemo(),
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->tg->answer($cb['id'], 'Đã gửi yêu cầu, admin sẽ kiểm tra sớm!', true);
        $this->tg->send($chat, "📨 Yêu cầu nạp #$id (" . money($amount) . ') đã được gửi. Bạn sẽ nhận thông báo khi được duyệt.');

        $u = $this->user;
        $text = "💵 <b>Yêu cầu nạp tiền #$id</b>\nUser: " . tg_escape($u['first_name']) . ($u['username'] ? ' @' . tg_escape($u['username']) : '') . " (<code>{$u['tg_id']}</code>)\n"
            . 'Số tiền: <b>' . money($amount) . "</b>\nNội dung CK: <code>" . tg_escape($this->depositMemo()) . '</code>';
        $kb = Telegram::inline([[Telegram::btn('✅ Duyệt', 'adep:ok:' . $id), Telegram::btn('❌ Từ chối', 'adep:no:' . $id)]]);
        foreach (Settings::adminIds() as $admin) {
            $this->tg->send($admin, $text, $kb);
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
            . "4️⃣ Không nhận được OTP → tự động hoàn tiền\n\n"
            . "<b>Lệnh:</b>\n/thuesim — Thuê sim\n/naptien — Nạp tiền\n/me — Tài khoản\n/lichsu — Lịch sử\n/banggia — Bảng giá\n/gioithieu — Giới thiệu\n\n"
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
