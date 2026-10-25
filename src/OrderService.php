<?php
declare(strict_types=1);

final class OrderService
{
    /**
     * Tính năng của dịch vụ theo API BossOTP: cột trong bảng services => [trường API, nhãn].
     * Một dịch vụ có thể hỗ trợ nhiều tính năng cùng lúc.
     */
    public const FEATURES = [
        'sup_custom_sms' => ['is_support_send_custom_sms', '✉️ Gửi SMS tùy chọn'],
        'sup_default_sms' => ['is_support_send_default_sms', '📤 Gửi SMS cố định'],
        'sup_forward_call' => ['is_support_forward_call', '📞 Chuyển tiếp cuộc gọi'],
        'sup_call' => ['is_support_call', '☎️ OTP qua cuộc gọi'],
        'sup_call_from_number' => ['is_support_call_from_number', '📲 Gọi từ số thuê'],
    ];

    /** Nút trên đơn đang chờ: cột tính năng => [nhãn nút, callback]. */
    private const ORDER_ACTIONS = [
        'sup_custom_sms' => ['✉️ Gửi tin nhắn từ số này', 'smsc'],
        'sup_default_sms' => ['📤 Yêu cầu gửi SMS', 'smsf'],
        'sup_forward_call' => ['📞 Chuyển tiếp cuộc gọi', 'fwd'],
    ];

    private OtpProvider $api;
    private Telegram $tg;

    public function __construct()
    {
        $this->api = new OtpProvider();
        $this->tg = new Telegram();
    }

    /**
     * Tạo đơn thuê sim. $opts: network, re_number.
     * Trả về ['ok'=>bool,'message'=>string,'order'=>?array].
     */
    public function create(array $user, array $service, array $opts = []): array
    {
        $pending = (int)DB::value("SELECT COUNT(*) FROM orders WHERE user_id = ? AND status = 'pending'", [$user['id']]);
        $max = Settings::int('max_pending_orders');
        if ($max > 0 && $pending >= $max) {
            return ['ok' => false, 'message' => "Bạn đang có $pending số chờ OTP. Vui lòng đợi đơn cũ kết thúc (tối đa $max)."];
        }
        $kind = ($opts['kind'] ?? 'otp') === 'sms' ? 'sms' : 'otp';
        $price = (float)$service['price'];
        // Thuê số gửi tin: ưu tiên trừ 1 lượt trong gói tin nhắn
        $packageId = $kind === 'sms' ? SmsService::useCredit((int)$user['id'], (int)$service['id']) : null;
        if ($packageId) {
            $price = 0;
        } elseif (Wallet::change((int)$user['id'], -$price, 'order', ($kind === 'sms' ? 'Thuê số gửi tin ' : 'Thuê sim ') . $service['name']) === null) {
            return ['ok' => false, 'message' => 'Số dư không đủ. Cần ' . money($price) . ', hiện có ' . money($user['balance']) . '.'];
        }

        $res = $this->api->rent((string)$service['provider_id'], $opts + ['call' => !empty($service['sup_call'])]);
        if (!$res['ok']) {
            $packageId ? SmsService::returnCredit($packageId) : Wallet::change((int)$user['id'], $price, 'refund', 'Hoàn tiền: thuê sim lỗi');
            if ($res['code'] === 'NOT_ENOUGH_BALANCE') {
                foreach (Settings::adminIds() as $admin) {
                    $this->tg->send($admin, '⚠️ Tài khoản BossOTP <b>hết số dư</b>, khách không thuê được số. Vui lòng nạp thêm!');
                }
            }
            return ['ok' => false, 'message' => '❌ ' . $res['message'] . "\nĐã hoàn " . ($packageId ? '1 lượt gói' : money($price)) . ' vào tài khoản.'];
        }

        $id = DB::insert('orders', [
            'user_id' => $user['id'],
            'service_id' => $service['id'],
            'service_name' => $service['name'],
            'provider_order_id' => $res['order_id'],
            'phone' => $res['phone'],
            'price' => $price,
            'cost' => $service['cost'],
            'status' => 'pending',
            'kind' => $kind,
            'user_package_id' => $packageId,
            'network' => $opts['network'] ?? null,
            'expires_at' => date('Y-m-d H:i:s', time() + self::serviceTimeout($service)),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        return ['ok' => true, 'message' => '', 'order' => DB::row('SELECT * FROM orders WHERE id = ?', [$id])];
    }

    /**
     * Thời gian giữ số (giây) của dịch vụ: chỉnh tay trong admin > thời gian API trả về > mặc định.
     * API BossOTP trả "timeout" theo đơn vị cấu hình ở api_timeout_unit (mặc định mili giây).
     */
    public static function serviceTimeout(array $service): int
    {
        if ((int)($service['timeout_custom'] ?? 0) > 0) {
            return (int)$service['timeout_custom'];
        }
        $raw = (int)($service['timeout_raw'] ?? 0);
        if ($raw > 0) {
            $sec = match ((string)Settings::get('api_timeout_unit')) {
                's' => $raw,
                'min' => $raw * 60,
                default => intdiv($raw, 1000),
            };
            if ($sec >= 30) {
                return $sec;
            }
        }
        return max(60, Settings::int('order_timeout'));
    }

    /** "3 phút", "1 giờ 30 phút", "45 giây". */
    public static function duration(int $sec): string
    {
        if ($sec < 60) {
            return $sec . ' giây';
        }
        $h = intdiv($sec, 3600);
        $m = intdiv($sec % 3600, 60);
        return trim(($h ? $h . ' giờ ' : '') . ($m ? $m . ' phút' : ''));
    }

    public static function orderText(array $o): string
    {
        $icons = ['pending' => '⏳ Đang chờ OTP', 'success' => '✅ Thành công', 'refunded' => '↩️ Đã hoàn tiền', 'cancelled' => '❌ Đã hủy'];
        $t = "📱 <b>Đơn #{$o['id']}</b> — " . tg_escape($o['service_name']) . "\n"
            . "Số điện thoại: <code>" . tg_escape($o['phone']) . "</code>\n"
            . ($o['network'] ? 'Nhà mạng: ' . tg_escape($o['network']) . "\n" : '')
            . 'Giá: <b>' . (!empty($o['user_package_id']) ? '1 lượt gói' : money($o['price'])) . "</b>\n"
            . "Trạng thái: " . ($icons[$o['status']] ?? $o['status']) . "\n";
        if ($o['otp']) {
            $t .= "\n🔑 OTP: <code>" . tg_escape($o['otp']) . "</code>\n";
        }
        if ($o['sms']) {
            $t .= "💬 SMS: " . tg_escape(mb_substr($o['sms'], 0, 500)) . "\n";
        }
        $calls = $o['call_logs'] ? (json_decode($o['call_logs'], true) ?: []) : [];
        if ($calls) {
            $t .= "\n📞 <b>Cuộc gọi đến:</b>\n";
            foreach (array_slice($calls, 0, 10) as $c) {
                $time = isset($c['time']) ? date('H:i:s d/m', strtotime((string)$c['time'])) : '';
                $t .= '• ' . tg_escape($c['number'] ?? '?') . ' — ' . (int)($c['duration'] ?? 0) . "s ($time)\n";
            }
        }
        if ((int)$o['sms_sent'] > 0) {
            $t .= "\n📤 <b>Đã gửi {$o['sms_sent']} lần</b>" . ($o['sms_info'] ? ' — gần nhất: ' . tg_escape($o['sms_info']) : '') . "\n";
        } elseif ($o['sms_info']) {
            $t .= '📤 ' . tg_escape($o['sms_info']) . "\n";
        }
        if ($o['status'] === 'pending') {
            $left = max(0, strtotime($o['expires_at']) - time());
            $t .= "\n⏱ Còn lại: " . floor($left / 60) . ' phút ' . ($left % 60) . " giây\n"
                . (($o['kind'] ?? 'otp') === 'sms'
                    ? (empty($o['sms_closed'])
                        ? '<i>👇 Bấm nút bên dưới để gửi tin từ số này. Gửi được nhiều lần cho tới khi hệ thống báo không gửi thêm được.</i>'
                        : '<i>Số này đã hết lượt gửi.</i>')
                    : '<i>OTP sẽ tự động gửi về khi có.</i>');
        }
        return $t;
    }

    public static function userCanCancel(): bool
    {
        return (new OtpProvider())->canCancel() || Settings::get('allow_user_cancel') === '1';
    }

    public static function orderKeyboard(array $o): ?array
    {
        if ($o['status'] === 'pending') {
            $rows = [];
            $svc = DB::row('SELECT * FROM services WHERE id = ?', [$o['service_id']]);
            if ($svc && empty($o['sms_closed'])) {
                // Gửi được nhiều lần cho tới khi BossOTP báo không gửi thêm được
                foreach (self::ORDER_ACTIONS as $col => [$label, $cbName]) {
                    if (!empty($svc[$col])) {
                        $rows[] = [Telegram::btn($label, $cbName . ':' . $o['id'])];
                    }
                }
            }
            $row = [Telegram::btn(($o['kind'] ?? 'otp') === 'sms' ? '🔄 Cập nhật' : '🔄 Kiểm tra OTP', 'chk:' . $o['id'])];
            if (($o['kind'] ?? 'otp') === 'sms' && (int)$o['sms_sent'] > 0) {
                $row[] = Telegram::btn('✅ Xong, kết thúc', 'smsend:' . $o['id']);
            } elseif (self::userCanCancel()) {
                $row[] = Telegram::btn('❌ Hủy & hoàn tiền', 'cxl:' . $o['id']);
            }
            $rows[] = $row;
            return Telegram::inline($rows);
        }
        if ($o['status'] === 'success' && ($o['kind'] ?? 'otp') === 'sms') {
            return Telegram::inline([[Telegram::btn('✉️ Thuê số mới gửi tiếp', 'smss:' . $o['service_id'])]]);
        }
        if ($o['status'] === 'success') {
            $row = [Telegram::btn('🔁 Thuê lại dịch vụ', 'buy:' . $o['service_id'])];
            if (Settings::get('allow_re_number') === '1') {
                $row[] = Telegram::btn('♻️ Thuê lại số này', 're:' . $o['id']);
            }
            return Telegram::inline([$row]);
        }
        return null;
    }

    /** Cập nhật tin nhắn đơn hàng trên Telegram. */
    public function notify(array $o): void
    {
        $tgId = DB::value('SELECT tg_id FROM users WHERE id = ?', [$o['user_id']]);
        if (!$tgId) {
            return;
        }
        $text = self::orderText($o);
        $kb = self::orderKeyboard($o) ?? Telegram::inline([]);
        if ($o['tg_message_id']) {
            $this->tg->edit($tgId, $o['tg_message_id'], $text, $kb);
        } else {
            // Đơn tạo từ Mini App: gửi 1 tin trong bot rồi các lần sau sửa tin đó
            $r = $this->tg->send($tgId, $text, $kb);
            if (!empty($r['result']['message_id'])) {
                DB::update('orders', ['tg_message_id' => $r['result']['message_id']], 'id = ?', [$o['id']]);
            }
        }
    }

    /** Kiểm tra OTP cho 1 đơn. Trả về đơn đã cập nhật. */
    public function poll(array $o): array
    {
        if ($o['status'] !== 'pending') {
            return $o;
        }
        DB::update('orders', ['checked_at' => time()], 'id = ?', [$o['id']]);
        $r = $this->api->check((string)$o['provider_order_id']);
        $callsJson = $r['call_logs'] ? json_encode($r['call_logs'], JSON_UNESCAPED_UNICODE) : null;
        if ($callsJson !== null && $callsJson !== $o['call_logs']) {
            DB::update('orders', ['call_logs' => $callsJson, 'updated_at' => now()], 'id = ?', [$o['id']]);
            $o['call_logs'] = $callsJson;
            if (!$r['otp'] && !$r['success']) {
                $this->notify($o);
            }
        }
        if (($o['kind'] ?? 'otp') === 'sms') {
            $this->pollSmsRental($o, $r);
            return DB::row('SELECT * FROM orders WHERE id = ?', [$o['id']]);
        }
        if ($r['ok'] && ($r['otp'] !== null || $r['success'])) {
            $this->markSuccess($o, $r['otp'] ?? '', $r['sms'], $r['cost']);
        } elseif ($r['expired'] || strtotime($o['expires_at']) <= time()) {
            $this->refund($o, 'refunded', $r['expired'] ? 'Không nhận được OTP' : 'Hết thời gian chờ OTP');
        }
        return DB::row('SELECT * FROM orders WHERE id = ?', [$o['id']]);
    }

    /**
     * Đơn thuê số gửi tin: giữ mở để gửi nhiều lần. Chỉ kết thúc khi BossOTP báo hết hạn/FAILED,
     * khi khách bấm "Xong", hoặc hết thời gian (chưa gửi: thời gian của dịch vụ; đã gửi: sms_rent_max_hours).
     */
    private function pollSmsRental(array $o, array $r): void
    {
        // Lưu tin nhắn / OTP nhận được trên số thuê (nếu có)
        if ($r['ok'] && (($r['otp'] && $r['otp'] !== $o['otp']) || ($r['sms'] && $r['sms'] !== $o['sms']))) {
            DB::update('orders', ['otp' => $r['otp'] ?? $o['otp'], 'sms' => $r['sms'] ?? $o['sms'], 'updated_at' => now()], 'id = ?', [$o['id']]);
            $this->notify(DB::row('SELECT * FROM orders WHERE id = ?', [$o['id']]));
        }
        $sent = (int)$o['sms_sent'] > 0;
        $maxAge = max(1, Settings::int('sms_rent_max_hours')) * 3600;
        $timedOut = $sent ? time() - strtotime($o['created_at']) >= $maxAge : strtotime($o['expires_at']) <= time();
        if ($r['expired'] || $timedOut) {
            $this->closeSmsRental($o, $r['expired'] ? 'Số thuê đã hết hạn' : 'Hết thời gian giữ số', $r['cost']);
        }
    }

    /** Kết thúc đơn thuê gửi tin: đã gửi ít nhất 1 tin → tính phí; chưa gửi → hoàn tiền. */
    public function closeSmsRental(array $o, string $reason, ?float $cost = null): void
    {
        $o = DB::row('SELECT * FROM orders WHERE id = ?', [$o['id']]) ?? $o;
        if ($o['status'] !== 'pending') {
            return;
        }
        if ((int)$o['sms_sent'] === 0 || Settings::get('sms_refund_after_send') === '1') {
            $this->refund($o, 'refunded', $reason);
            return;
        }
        $this->markSuccess($o, (string)($o['otp'] ?? ''), $o['sms'], $cost);
    }

    private function markSuccess(array $o, string $otp, ?string $sms, ?float $cost): void
    {
        $data = ['status' => 'success', 'otp' => $otp, 'sms' => $sms, 'updated_at' => now()];
        if ($cost !== null) {
            $data['cost'] = $cost;
        }
        if (DB::update('orders', $data, "id = ? AND status = 'pending'", [$o['id']]) === 0) {
            return;
        }
        DB::query('UPDATE users SET total_spent = total_spent + ? WHERE id = ?', [$o['price'], $o['user_id']]);

        // Hoa hồng giới thiệu
        $refPct = (float)Settings::get('referral_percent');
        $refBy = DB::value('SELECT ref_by FROM users WHERE id = ?', [$o['user_id']]);
        if ($refPct > 0 && $refBy) {
            $ref = DB::row('SELECT * FROM users WHERE tg_id = ?', [$refBy]);
            $amount = floor((float)$o['price'] * $refPct / 100);
            if ($ref && $amount > 0) {
                Wallet::change((int)$ref['id'], $amount, 'referral', 'Hoa hồng đơn #' . $o['id']);
                DB::query('UPDATE users SET ref_earned = ref_earned + ? WHERE id = ?', [$amount, $ref['id']]);
                $this->tg->send($ref['tg_id'], '🎁 Bạn nhận <b>' . money($amount) . '</b> hoa hồng giới thiệu.');
            }
        }
        $updated = DB::row('SELECT * FROM orders WHERE id = ?', [$o['id']]);
        $this->notify($updated);
        // Tin nhắn mới để người dùng nhận được thông báo đẩy
        $tgId = DB::value('SELECT tg_id FROM users WHERE id = ?', [$o['user_id']]);
        if (($o['kind'] ?? 'otp') === 'sms') {
            $this->tg->send($tgId, "✅ <b>Đơn #{$o['id']} kết thúc</b> — số <code>" . tg_escape($o['phone']) . '</code> đã gửi <b>' . (int)$updated['sms_sent'] . '</b> lần.');
            return;
        }
        $this->tg->send($tgId, "🔑 <b>Đơn #{$o['id']} hoàn thành</b> (" . tg_escape($o['service_name']) . ")\nSĐT: <code>" . tg_escape($o['phone']) . '</code>'
            . ($otp !== '' ? "\nOTP: <code>" . tg_escape($otp) . '</code>' : '')
            . ($sms ? "\nSMS: " . tg_escape(mb_substr($sms, 0, 500)) : ''));
    }

    /** Hủy đơn + hoàn tiền. $status: refunded | cancelled. */
    public function refund(array $o, string $status, string $reason): bool
    {
        $n = DB::update('orders', ['status' => $status, 'updated_at' => now()], "id = ? AND status = 'pending'", [$o['id']]);
        if ($n === 0) {
            return false;
        }
        $this->api->cancel((string)$o['provider_order_id']);
        $tgId = DB::value('SELECT tg_id FROM users WHERE id = ?', [$o['user_id']]);
        if (!empty($o['user_package_id'])) {
            SmsService::returnCredit((int)$o['user_package_id']);
            $refundText = '1 lượt gói tin';
        } else {
            $after = Wallet::change((int)$o['user_id'], (float)$o['price'], 'refund', "Hoàn tiền đơn #{$o['id']}: $reason");
            $refundText = money($o['price']) . '</b>. Số dư: <b>' . money($after);
        }
        $this->notify(DB::row('SELECT * FROM orders WHERE id = ?', [$o['id']]));
        $this->tg->send($tgId, "↩️ Đơn #{$o['id']} ({$reason}). Đã hoàn <b>" . $refundText . '</b>');
        return true;
    }

    /**
     * Gửi SMS / chuyển tiếp cuộc gọi cho đơn đang chờ.
     * $kind: fixed | custom | forward
     */
    public function sendSms(array $o, string $kind, string $number = '', string $content = ''): array
    {
        $o = DB::row('SELECT * FROM orders WHERE id = ?', [$o['id']]) ?? $o;
        if ($o['status'] !== 'pending') {
            return ['ok' => false, 'message' => 'Đơn đã kết thúc.'];
        }
        if (!empty($o['sms_closed'])) {
            return ['ok' => false, 'message' => 'Số này không gửi thêm được nữa. Hãy thuê số mới.'];
        }
        // Khóa ngắn để không gửi trùng khi bấm liên tục
        $now = time();
        if (DB::update('orders', ['sms_lock' => $now], 'id = ? AND sms_lock < ?', [$o['id'], $now - 15]) === 0) {
            return ['ok' => false, 'message' => 'Đang gửi tin trước, vui lòng đợi vài giây.'];
        }
        $params = match ($kind) {
            'custom' => ['sms' => $content, 'number_receiver' => $number],
            'forward' => ['number_forward_call' => $number],
            default => [],
        };
        $r = $this->api->sendSms((string)$o['provider_order_id'], $params);
        DB::insert('sms_sends', [
            'order_id' => $o['id'],
            'user_id' => $o['user_id'],
            'kind' => $kind,
            'receiver' => $number !== '' ? $number : null,
            'content' => $content !== '' ? $content : null,
            'ok' => $r['ok'] ? 1 : 0,
            'error' => $r['ok'] ? null : mb_substr($r['message'], 0, 250),
            'created_at' => now(),
        ]);
        if (!$r['ok']) {
            DB::update('orders', ['sms_lock' => 0], 'id = ?', [$o['id']]);
            // BossOTP báo số này không gửi thêm được → đóng chức năng gửi
            if (in_array($r['code'] ?? '', ['RENT_SENT_SMS', 'RENT_NOT_PENDING', 'TOO_CLOSE_RENT_WILL_FINISH', 'RENT_NOT_FOUND'], true)) {
                DB::update('orders', ['sms_closed' => 1, 'updated_at' => now()], 'id = ?', [$o['id']]);
                $fresh = DB::row('SELECT * FROM orders WHERE id = ?', [$o['id']]);
                if (($o['kind'] ?? 'otp') === 'sms') {
                    $this->closeSmsRental($fresh, 'Số thuê không gửi thêm được');
                } else {
                    $this->notify($fresh);
                }
                return ['ok' => false, 'closed' => true, 'message' => $r['message'] . ((int)$o['sms_sent'] > 0 ? ' Đơn đã kết thúc sau ' . (int)$o['sms_sent'] . ' lần gửi.' : '')];
            }
            return $r;
        }
        $info = match ($kind) {
            'custom' => 'SMS tới ' . $number . ': "' . mb_substr($content, 0, 80) . '"',
            'forward' => 'Chuyển tiếp cuộc gọi tới ' . $number,
            default => 'Gửi SMS cố định',
        };
        DB::query('UPDATE orders SET sms_sent = sms_sent + 1, sms_lock = 0, sms_info = ?, updated_at = ? WHERE id = ?', [mb_substr($info, 0, 250), now(), $o['id']]);
        $fresh = DB::row('SELECT * FROM orders WHERE id = ?', [$o['id']]);
        $this->notify($fresh);
        return ['ok' => true, 'message' => "Lần {$fresh['sms_sent']}: $info"];
    }

    /** Chạy bởi cron: kiểm tra tất cả đơn đang chờ. */
    public function pollAll(): int
    {
        $count = 0;
        foreach (DB::all("SELECT * FROM orders WHERE status = 'pending' ORDER BY id") as $o) {
            try {
                $this->poll($o);
                $count++;
                usleep(200000); // BossOTP giới hạn 1 request/giây cho mỗi rent_id
            } catch (Throwable $e) {
                app_log('cron', "order {$o['id']}: " . $e->getMessage());
            }
        }
        return $count;
    }
}
