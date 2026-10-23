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
            'order_timeout' => '600',      // giây chờ OTP trước khi tự hủy + hoàn tiền
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

            // Giới thiệu
            'referral_percent' => '5',

            // Nhà cung cấp OTP (BossOTP)
            'api_base_url' => 'https://bossotp.net/api',
            'api_key' => '',
            'api_key_param' => 'api_key',  // tên tham số chứa API key
            'api_auth_mode' => 'query',    // query | bearer | header
            'api_ep_balance' => 'GET /user/balance',
            'api_ep_services' => 'GET /services',
            'api_ep_rent' => 'GET /rent?service_id={service_id}',
            'api_ep_check' => 'GET /rent/check?id={order_id}',
            'api_ep_cancel' => 'GET /rent/cancel?id={order_id}',
            // ánh xạ trường trong JSON trả về (nhiều lựa chọn cách nhau bởi "|")
            'api_map_success' => 'success|status|ok',
            'api_map_message' => 'message|msg|error',
            'api_map_balance' => 'data.balance|balance|data.money|money',
            'api_map_services_list' => 'data|services|data.services|result',
            'api_map_service_id' => 'id|service_id|serviceId|code',
            'api_map_service_name' => 'name|service_name|serviceName|title',
            'api_map_service_price' => 'price|cost|amount|rate',
            'api_map_order_id' => 'data.id|data.order_id|data.request_id|id|order_id|request_id',
            'api_map_phone' => 'data.phone|data.number|data.phone_number|phone|number|phone_number',
            'api_map_otp' => 'data.otp|data.code|otp|code|data.sms_code',
            'api_map_sms' => 'data.sms|data.content|data.message|sms|content',
            'api_map_status' => 'data.status|status_text|state',
            'api_status_expired' => 'expired|timeout|cancel|cancelled|canceled|refund|2|-1',
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
