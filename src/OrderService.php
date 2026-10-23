<?php
declare(strict_types=1);

final class OrderService
{
    private OtpProvider $api;
    private Telegram $tg;

    public function __construct()
    {
        $this->api = new OtpProvider();
        $this->tg = new Telegram();
    }

    /** Tạo đơn thuê sim. Trả về ['ok'=>bool,'message'=>string,'order'=>?array]. */
    public function create(array $user, array $service): array
    {
        $pending = (int)DB::value("SELECT COUNT(*) FROM orders WHERE user_id = ? AND status = 'pending'", [$user['id']]);
        $max = Settings::int('max_pending_orders');
        if ($max > 0 && $pending >= $max) {
            return ['ok' => false, 'message' => "Bạn đang có $pending số chờ OTP. Vui lòng đợi hoặc hủy bớt (tối đa $max)."];
        }
        $price = (float)$service['price'];
        if (Wallet::change((int)$user['id'], -$price, 'order', 'Thuê sim ' . $service['name']) === null) {
            return ['ok' => false, 'message' => 'Số dư không đủ. Cần ' . money($price) . ', hiện có ' . money($user['balance']) . '.'];
        }

        $res = $this->api->rent((string)$service['provider_id']);
        if (!$res['ok']) {
            Wallet::change((int)$user['id'], $price, 'refund', 'Hoàn tiền: thuê sim lỗi');
            return ['ok' => false, 'message' => '❌ ' . $res['message'] . "\nĐã hoàn " . money($price) . ' vào tài khoản.'];
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
            'expires_at' => date('Y-m-d H:i:s', time() + Settings::int('order_timeout')),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        return ['ok' => true, 'message' => '', 'order' => DB::row('SELECT * FROM orders WHERE id = ?', [$id])];
    }

    public static function orderText(array $o): string
    {
        $icons = ['pending' => '⏳ Đang chờ OTP', 'success' => '✅ Thành công', 'refunded' => '↩️ Đã hoàn tiền', 'cancelled' => '❌ Đã hủy'];
        $t = "📱 <b>Đơn #{$o['id']}</b> — " . tg_escape($o['service_name']) . "\n"
            . "Số điện thoại: <code>" . tg_escape($o['phone']) . "</code>\n"
            . "Giá: <b>" . money($o['price']) . "</b>\n"
            . "Trạng thái: " . ($icons[$o['status']] ?? $o['status']) . "\n";
        if ($o['otp']) {
            $t .= "\n🔑 OTP: <code>" . tg_escape($o['otp']) . "</code>\n";
        }
        if ($o['sms']) {
            $t .= "💬 SMS: " . tg_escape(mb_substr($o['sms'], 0, 500)) . "\n";
        }
        if ($o['status'] === 'pending') {
            $left = max(0, strtotime($o['expires_at']) - time());
            $t .= "\n⏱ Còn lại: " . floor($left / 60) . " phút " . ($left % 60) . " giây\n<i>OTP sẽ tự động gửi về khi có.</i>";
        }
        return $t;
    }

    public static function orderKeyboard(array $o): ?array
    {
        if ($o['status'] === 'pending') {
            return Telegram::inline([[
                Telegram::btn('🔄 Kiểm tra OTP', 'chk:' . $o['id']),
                Telegram::btn('❌ Hủy & hoàn tiền', 'cxl:' . $o['id']),
            ]]);
        }
        if ($o['status'] === 'success') {
            return Telegram::inline([[Telegram::btn('🔁 Thuê lại dịch vụ này', 'buy:' . $o['service_id'])]]);
        }
        return null;
    }

    /** Cập nhật tin nhắn đơn hàng trên Telegram. */
    public function notify(array $o, bool $newMessage = false): void
    {
        $tgId = DB::value('SELECT tg_id FROM users WHERE id = ?', [$o['user_id']]);
        if (!$tgId) {
            return;
        }
        $text = self::orderText($o);
        $kb = self::orderKeyboard($o) ?? Telegram::inline([]);
        if ($o['tg_message_id'] && !$newMessage) {
            $this->tg->edit($tgId, $o['tg_message_id'], $text, $kb);
        }
        if ($newMessage || !$o['tg_message_id']) {
            $this->tg->send($tgId, $text, $kb);
        }
    }

    /** Kiểm tra OTP cho 1 đơn. Trả về đơn đã cập nhật. */
    public function poll(array $o): array
    {
        if ($o['status'] !== 'pending') {
            return $o;
        }
        $r = $this->api->check((string)$o['provider_order_id']);
        if ($r['ok'] && $r['otp'] !== null) {
            $this->markSuccess($o, $r['otp'], $r['sms']);
        } elseif ($r['expired'] || strtotime($o['expires_at']) <= time()) {
            $this->refund($o, 'refunded', 'Hết thời gian chờ OTP');
        }
        return DB::row('SELECT * FROM orders WHERE id = ?', [$o['id']]);
    }

    private function markSuccess(array $o, string $otp, ?string $sms): void
    {
        $n = DB::update('orders', ['status' => 'success', 'otp' => $otp, 'sms' => $sms, 'updated_at' => now()], "id = ? AND status = 'pending'", [$o['id']]);
        if ($n === 0) {
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
        $this->tg->send($tgId, "🔑 <b>OTP đơn #{$o['id']}</b> (" . tg_escape($o['service_name']) . ")\nSĐT: <code>" . tg_escape($o['phone']) . "</code>\nOTP: <code>" . tg_escape($otp) . '</code>');
    }

    /** Hủy đơn + hoàn tiền. $status: refunded | cancelled. */
    public function refund(array $o, string $status, string $reason): bool
    {
        $n = DB::update('orders', ['status' => $status, 'updated_at' => now()], "id = ? AND status = 'pending'", [$o['id']]);
        if ($n === 0) {
            return false;
        }
        $this->api->cancel((string)$o['provider_order_id']);
        $after = Wallet::change((int)$o['user_id'], (float)$o['price'], 'refund', "Hoàn tiền đơn #{$o['id']}: $reason");
        $updated = DB::row('SELECT * FROM orders WHERE id = ?', [$o['id']]);
        $this->notify($updated);
        $tgId = DB::value('SELECT tg_id FROM users WHERE id = ?', [$o['user_id']]);
        $this->tg->send($tgId, "↩️ Đơn #{$o['id']} ({$reason}). Đã hoàn <b>" . money($o['price']) . '</b>. Số dư: <b>' . money($after) . '</b>');
        return true;
    }

    /** Chạy bởi cron: kiểm tra tất cả đơn đang chờ. */
    public function pollAll(): int
    {
        $count = 0;
        foreach (DB::all("SELECT * FROM orders WHERE status = 'pending' ORDER BY id") as $o) {
            try {
                $this->poll($o);
                $count++;
            } catch (Throwable $e) {
                app_log('cron', "order {$o['id']}: " . $e->getMessage());
            }
        }
        return $count;
    }
}
