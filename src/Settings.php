<?php
declare(strict_types=1);

final class Settings
{
    private static ?array $cache = null;

    /** Giá trị mặc định cho mọi cấu hình có thể chỉnh trong trang admin. */
    public static function defaults(): array
    {
        return [
            // Bot
            'bot_token' => '',
            'bot_username' => '',
            'shop_name' => 'SIM OTP Shop',
            'admin_tg_ids' => '',          // nhiều ID cách nhau bởi dấu phẩy
            'support_contact' => '@admin',
            'channel_url' => '',
            'welcome_message' => "Chào mừng bạn đến với <b>{shop}</b>!\nDịch vụ thuê sim nhận OTP tự động 24/7.",
            'maintenance' => '0',
            'maintenance_message' => '🛠 Bot đang bảo trì, vui lòng quay lại sau.',

            // Giá
            'price_mode' => 'percent',     // percent | fixed
            'price_markup' => '30',        // % hoặc số tiền cộng thêm
            'price_round' => '100',        // làm tròn lên bội số
            'min_price' => '0',

            // Đơn hàng
            'order_timeout' => '900',      // giây chờ OTP trước khi tự hủy + hoàn tiền
            'allow_user_cancel' => '0',    // cho khách tự hủy khi nhà cung cấp không có API hủy
            'allow_network_select' => '1', // cho khách chọn nhà mạng khi thuê
            'allow_re_number' => '1',      // cho khách thuê lại số cũ
            'max_pending_orders' => '3',
            'allow_cancel_after' => '0',   // giây tối thiểu trước khi cho phép hủy
            'services_per_page' => '10',

            // Nạp tiền
            'min_deposit' => '10000',
            'deposit_prefix' => 'NAP',
            'bank_name' => 'MB',
            'bank_bin' => '970422',
            'bank_account' => '',
            'bank_owner' => '',
            'deposit_bonus_percent' => '0',
            'payment_webhook_key' => '',   // key xác thực webhook SePay/Casso
            'bank_api_url' => '',          // link API lịch sử giao dịch Web2M (chứa mật khẩu, chỉ lưu trong CSDL)
            'bank_api_interval' => '20',   // giây giữa 2 lần quét lịch sử
            'bank_api_baseline' => '',     // đánh dấu đã ghi nhận giao dịch cũ cho link hiện tại

            // Cập nhật mã nguồn từ GitHub (trang Admin → Cập nhật)
            'update_repo' => 'duongit2795/lottery-auto-bet',
            'update_branch' => 'claude/telegram-sim-service-bot-9so4of',
            'update_token' => '',          // GitHub token, chỉ cần khi repo private

            // Gửi tin nhắn
            'sms_enabled' => '1',
            'sms_rent_max_hours' => '6',      // số đã gửi tin được giữ tối đa bao lâu để gửi tiếp (BossOTP báo hết thì đóng sớm hơn)
            'sms_refund_after_send' => '0',   // 1 = vẫn hoàn tiền nếu số đã gửi tin rồi mới hết hạn/FAILED
            'sms_max_length' => '300',     // độ dài nội dung tối đa
            'sms_blacklist' => '',         // từ cấm trong nội dung, cách nhau dấu phẩy

            // Giới thiệu
            'referral_percent' => '5',

            // Nhà cung cấp OTP (BossOTP API v4 — https://bossotp.net/rent-api)
            'api_base_url' => 'https://bossotp.net',
            'api_key' => '',                // nhập trong Admin → Cài đặt (không lưu trong mã nguồn)
            'api_key_param' => 'api_token', // tên tham số chứa API key
            'api_auth_mode' => 'query',     // query | bearer | header
            'api_language' => 'vn',         // ngôn ngữ cuộc gọi cho dịch vụ trả OTP bằng cuộc gọi: vn | en
            'api_ep_balance' => 'GET /api/v4/users/me/balance',
            'api_ep_services' => 'GET /api/v4/service-manager/services',
            'api_ep_rent' => 'GET /api/v4/rents/create?service_id={service_id}',
            'api_ep_check' => 'GET /api/v4/rents/check?_id={order_id}',
            'api_ep_send_sms' => 'GET /api/v4/rents/send-sms?_id={order_id}',
            'api_ep_cancel' => '',          // BossOTP không có API hủy — để trống
            // ánh xạ trường trong JSON trả về (nhiều lựa chọn cách nhau bởi "|")
            'api_map_success' => '',        // để trống = dựa vào HTTP code (BossOTP trả 400 khi lỗi)
            'api_map_message' => 'code|error|message',
            'api_map_balance' => 'balance',
            'api_map_services_list' => '',  // để trống = JSON trả về là mảng
            'api_map_service_id' => '_id|id',
            'api_map_service_name' => 'name',
            'api_map_service_price' => 'price',
            'sms_mode_auto' => '1',          // tự cập nhật tính năng (gửi SMS, chuyển tiếp...) theo API khi đồng bộ
            'api_map_order_id' => 'rent_id',
            'api_map_phone' => 'number',
            'api_map_otp' => 'otp',
            'api_map_sms' => 'sms_content',
            'api_map_status' => 'status',
            'api_map_cost' => 'price',
            'api_map_call_logs' => 'call_logs',
            'api_status_success' => 'success',
            'api_status_expired' => 'failed|expired|cancelled',
            'api_phone_add_zero' => '1',    // BossOTP trả số không có số 0 đầu
        ];
    }

    private static function load(): void
    {
        if (self::$cache !== null) {
            return;
        }
        self::$cache = self::defaults();
        try {
            foreach (DB::all('SELECT k, v FROM settings') as $r) {
                self::$cache[$r['k']] = $r['v'];
            }
        } catch (PDOException $e) {
            // chưa migrate
        }
    }

    public static function get(string $key, $default = null)
    {
        self::load();
        return self::$cache[$key] ?? $default;
    }

    public static function int(string $key): int
    {
        return (int)self::get($key, 0);
    }

    public static function set(string $key, $value): void
    {
        self::load();
        $value = (string)$value;
        if (DB::value('SELECT COUNT(*) FROM settings WHERE k = ?', [$key])) {
            DB::query('UPDATE settings SET v = ? WHERE k = ?', [$value, $key]);
        } else {
            DB::query('INSERT INTO settings (k, v) VALUES (?, ?)', [$key, $value]);
        }
        self::$cache[$key] = $value;
    }

    public static function adminIds(): array
    {
        return array_values(array_filter(array_map('trim', explode(',', (string)self::get('admin_tg_ids')))));
    }

    /** Tính giá bán từ giá gốc theo cấu hình lợi nhuận. */
    public static function sellPrice(float $cost): int
    {
        $markup = (float)self::get('price_markup');
        $price = self::get('price_mode') === 'fixed' ? $cost + $markup : $cost * (1 + $markup / 100);
        $round = max(1, self::int('price_round'));
        $price = (int)(ceil($price / $round) * $round);
        return max($price, self::int('min_price'));
    }
}
