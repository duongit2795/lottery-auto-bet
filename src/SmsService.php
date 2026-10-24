<?php
declare(strict_types=1);

/**
 * Gửi tin nhắn theo đúng quy trình BossOTP: thuê số của dịch vụ hỗ trợ gửi SMS
 * (rents/create) → có số rồi mới gửi bằng rents/send-sms với rent_id của số đó.
 *
 * Lớp này lo phần dịch vụ gửi tin và gói tin nhắn: mỗi lượt trong gói = 1 lần thuê số
 * gửi tin (thay cho trả bằng số dư). Đơn thuê và việc gửi do OrderService xử lý.
 */
final class SmsService
{
    /** Dịch vụ hiện trong menu "✉️ Gửi tin nhắn". */
    public static function services(): array
    {
        return DB::all('SELECT * FROM services WHERE is_active = 1 AND (sup_custom_sms = 1 OR sup_default_sms = 1 OR sup_forward_call = 1) ORDER BY sort_order DESC, name');
    }

    // ---------------------------------------------------------------- gói tin

    /** Gói còn hạn, còn lượt, dùng được cho dịch vụ, sắp hết hạn dùng trước. */
    public static function activePackages(int $userId, ?int $serviceId = null): array
    {
        $sql = 'SELECT * FROM user_packages WHERE user_id = ? AND credits_left > 0 AND (expires_at IS NULL OR expires_at > ?)';
        $params = [$userId, now()];
        if ($serviceId !== null) {
            $sql .= ' AND (service_id IS NULL OR service_id = ?)';
            $params[] = $serviceId;
        }
        return DB::all($sql . ' ORDER BY expires_at ASC, id ASC', $params);
    }

    public static function credits(int $userId, ?int $serviceId = null): int
    {
        return array_sum(array_column(self::activePackages($userId, $serviceId), 'credits_left'));
    }

    /** Trừ 1 lượt gói dùng được cho dịch vụ. Trả về id gói đã trừ, hoặc null nếu hết lượt. */
    public static function useCredit(int $userId, int $serviceId): ?int
    {
        foreach (self::activePackages($userId, $serviceId) as $p) {
            if (DB::query('UPDATE user_packages SET credits_left = credits_left - 1 WHERE id = ? AND credits_left > 0', [$p['id']])->rowCount() === 1) {
                return (int)$p['id'];
            }
        }
        return null;
    }

    public static function returnCredit(int $userPackageId): void
    {
        DB::query('UPDATE user_packages SET credits_left = credits_left + 1 WHERE id = ?', [$userPackageId]);
    }

    /** Mua gói tin. Trả về ['ok'=>bool,'message'=>string]. */
    public static function buyPackage(array $user, int $packageId): array
    {
        $p = DB::row('SELECT * FROM sms_packages WHERE id = ? AND is_active = 1', [$packageId]);
        if (!$p) {
            return ['ok' => false, 'message' => 'Gói không tồn tại hoặc đã ngừng bán.'];
        }
        if (Wallet::change((int)$user['id'], -(float)$p['price'], 'package', 'Mua ' . $p['name']) === null) {
            return ['ok' => false, 'message' => 'Số dư không đủ. Cần ' . money($p['price']) . ', hiện có ' . money($user['balance']) . '.'];
        }
        self::grantPackage((int)$user['id'], $p);
        DB::query('UPDATE users SET total_spent = total_spent + ? WHERE id = ?', [$p['price'], $user['id']]);
        return ['ok' => true, 'message' => '✅ Đã mua <b>' . tg_escape($p['name']) . '</b>: +' . (int)$p['credits'] . ' lượt thuê số gửi tin'
            . ((int)$p['days_valid'] > 0 ? ', hạn dùng ' . (int)$p['days_valid'] . ' ngày.' : '.')];
    }

    /** Cấp gói cho user (khi mua hoặc admin tặng). */
    public static function grantPackage(int $userId, array $p, ?float $price = null): int
    {
        return DB::insert('user_packages', [
            'user_id' => $userId,
            'package_id' => $p['id'] ?? null,
            'name' => $p['name'],
            'service_id' => $p['service_id'] ?: null,
            'credits_total' => (int)$p['credits'],
            'credits_left' => (int)$p['credits'],
            'price' => $price ?? (float)($p['price'] ?? 0),
            'expires_at' => (int)$p['days_valid'] > 0 ? date('Y-m-d H:i:s', time() + 86400 * (int)$p['days_valid']) : null,
            'created_at' => now(),
        ]);
    }

    // ------------------------------------------------------------- nội dung

    /** Kiểm tra nội dung tin nhắn. Trả về thông báo lỗi hoặc null. */
    public static function validateContent(string $content): ?string
    {
        if (trim($content) === '') {
            return 'Nội dung không được để trống.';
        }
        $max = Settings::int('sms_max_length');
        if ($max > 0 && mb_strlen($content) > $max) {
            return "Nội dung quá dài (tối đa $max ký tự, hiện " . mb_strlen($content) . ').';
        }
        foreach (array_filter(array_map('trim', explode(',', (string)Settings::get('sms_blacklist')))) as $w) {
            if (mb_stripos($content, $w) !== false) {
                return 'Nội dung chứa từ bị cấm: "' . $w . '".';
            }
        }
        return null;
    }
}
