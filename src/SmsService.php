<?php
declare(strict_types=1);

/**
 * Gửi tin nhắn qua số thuê của BossOTP (API rents/send-sms) và quản lý gói tin nhắn.
 *
 * Mỗi tin = 1 job trong hàng đợi sms_jobs. Xử lý 1 job: thuê số → gửi SMS ngay.
 * Lỗi tạm thời (hết số, quá tải) sẽ thử lại tối đa sms_max_attempts lần, sau đó hoàn tiền/hoàn lượt.
 * Tiền được trừ khi tạo job: ưu tiên lượt trong gói còn hạn, phần còn lại trừ vào số dư.
 */
final class SmsService
{
    private const RETRY_CODES = ['NO_NUMBER_AVAILABLE', 'TOO_MANY_REQUEST', 'NOT_ENOUGH_BALANCE', 'TOO_CLOSE_RENT_WILL_FINISH', 'UNDEFINED_ERROR_234', ''];

    private OtpProvider $api;
    private Telegram $tg;

    public function __construct()
    {
        $this->api = new OtpProvider();
        $this->tg = new Telegram();
    }

    /** Dịch vụ dùng được cho chức năng gửi tin nhắn. */
    public static function services(): array
    {
        return DB::all("SELECT * FROM services WHERE is_active = 1 AND sms_mode IN ('custom', 'fixed') ORDER BY sort_order DESC, name");
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
        return ['ok' => true, 'message' => '✅ Đã mua <b>' . tg_escape($p['name']) . '</b>: +' . (int)$p['credits'] . ' lượt gửi tin'
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

    // ------------------------------------------------------------- tạo lệnh

    /** Tách danh sách số điện thoại từ văn bản. Trả về [hợp lệ[], không hợp lệ[]]. */
    public static function parseNumbers(string $text): array
    {
        $ok = $bad = [];
        foreach (preg_split('/[\s,;]+/', $text, -1, PREG_SPLIT_NO_EMPTY) as $raw) {
            $n = preg_replace('/[.\-()]/', '', $raw);
            if (str_starts_with($n, '+84')) {
                $n = '0' . substr($n, 3);
            } elseif (str_starts_with($n, '84') && strlen($n) === 11) {
                $n = '0' . substr($n, 2);
            }
            if (preg_match('/^0\d{9,10}$/', $n)) {
                $ok[$n] = true;
            } else {
                $bad[] = $raw;
            }
        }
        return [array_keys($ok), $bad];
    }

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

    /** Báo giá: dùng bao nhiêu lượt gói, bao nhiêu tin trả bằng tiền. */
    public static function quote(array $user, array $service, int $count): array
    {
        $credits = self::credits((int)$user['id'], (int)$service['id']);
        $useCredits = min($credits, $count);
        $paid = $count - $useCredits;
        return ['count' => $count, 'credits' => $credits, 'use_credits' => $useCredits, 'paid' => $paid, 'amount' => $paid * (float)$service['price']];
    }

    /**
     * Tạo lệnh gửi (1 job / người nhận) và trừ tiền/lượt.
     * Với dịch vụ SMS cố định: $receivers = [''] (1 tin, không cần số nhận/nội dung).
     */
    public function createBatch(array $user, array $service, array $receivers, string $content): array
    {
        $count = count($receivers);
        if ($count === 0) {
            return ['ok' => false, 'message' => 'Chưa có số nhận tin.'];
        }
        $q = self::quote($user, $service, $count);
        if ($q['amount'] > 0 && Wallet::change((int)$user['id'], -$q['amount'], 'sms', "Gửi {$q['paid']} tin nhắn ({$service['name']})") === null) {
            return ['ok' => false, 'message' => 'Số dư không đủ. Cần ' . money($q['amount']) . ', hiện có ' . money($user['balance']) . '.'];
        }

        // Trừ lượt trong gói (gói sắp hết hạn dùng trước)
        $packageIds = [];
        $need = $q['use_credits'];
        foreach (self::activePackages((int)$user['id'], (int)$service['id']) as $p) {
            while ($need > 0 && DB::query('UPDATE user_packages SET credits_left = credits_left - 1 WHERE id = ? AND credits_left > 0', [$p['id']])->rowCount() === 1) {
                $packageIds[] = (int)$p['id'];
                $need--;
            }
            if ($need === 0) {
                break;
            }
        }
        // Gói vừa bị dùng hết ở request khác → phần thiếu trả bằng tiền
        if ($need > 0) {
            $extra = $need * (float)$service['price'];
            if (Wallet::change((int)$user['id'], -$extra, 'sms', "Gửi $need tin nhắn ({$service['name']})") === null) {
                foreach ($packageIds as $pid) {
                    DB::query('UPDATE user_packages SET credits_left = credits_left + 1 WHERE id = ?', [$pid]);
                }
                if ($q['amount'] > 0) {
                    Wallet::change((int)$user['id'], $q['amount'], 'refund', 'Hoàn tiền: không tạo được lệnh gửi tin');
                }
                return ['ok' => false, 'message' => 'Số dư không đủ, vui lòng thử lại.'];
            }
        }

        $batch = date('ymdHis') . random_code(4);
        foreach (array_values($receivers) as $i => $to) {
            $pid = $packageIds[$i] ?? null;
            DB::insert('sms_jobs', [
                'batch' => $batch,
                'user_id' => $user['id'],
                'service_id' => $service['id'],
                'receiver' => $to !== '' ? $to : null,
                'content' => $content !== '' ? $content : null,
                'status' => 'queued',
                'price' => $pid ? 0 : $service['price'],
                'user_package_id' => $pid,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        return ['ok' => true, 'message' => '', 'batch' => $batch, 'quote' => $q];
    }

    // -------------------------------------------------------------- xử lý

    /** Còn chỗ để thuê số mới không (BossOTP giới hạn số yêu cầu PENDING đồng thời). */
    private function hasCapacity(): bool
    {
        $max = Settings::int('sms_concurrency');
        return $max <= 0 || (int)DB::value("SELECT COUNT(*) FROM orders WHERE status = 'pending'") < $max;
    }

    /** Xử lý tối đa $limit job trong hàng đợi. $batch: chỉ xử lý 1 lệnh. */
    public function processQueue(int $limit = 10, ?string $batch = null): int
    {
        // Job bị treo do tiến trình chết giữa chừng → đưa lại hàng đợi
        DB::query("UPDATE sms_jobs SET status = 'queued' WHERE status = 'processing' AND updated_at < ?", [date('Y-m-d H:i:s', time() - 300)]);
        $done = 0;
        $sql = "SELECT * FROM sms_jobs WHERE status = 'queued'" . ($batch ? ' AND batch = ?' : '') . ' ORDER BY id LIMIT ' . $limit;
        foreach (DB::all($sql, $batch ? [$batch] : []) as $job) {
            if (!$this->hasCapacity()) {
                break;
            }
            try {
                $this->processJob($job);
                $done++;
            } catch (Throwable $e) {
                app_log('sms', "job {$job['id']}: " . $e->getMessage());
                DB::update('sms_jobs', ['status' => 'queued', 'updated_at' => now()], "id = ? AND status = 'processing'", [$job['id']]);
            }
            usleep(1100000); // BossOTP: 1 request/giây
        }
        return $done;
    }

    private function processJob(array $job): void
    {
        if (DB::update('sms_jobs', ['status' => 'processing', 'updated_at' => now()], "id = ? AND status = 'queued'", [$job['id']]) === 0) {
            return; // tiến trình khác đã nhận
        }
        $service = DB::row('SELECT * FROM services WHERE id = ?', [$job['service_id']]);
        if (!$service) {
            $this->fail($job, 'Dịch vụ không còn tồn tại');
            return;
        }
        $attempt = (int)$job['attempts'] + 1;

        $rent = $this->api->rent((string)$service['provider_id']);
        if (!$rent['ok']) {
            if ($rent['code'] === 'NOT_ENOUGH_BALANCE') {
                foreach (Settings::adminIds() as $admin) {
                    $this->tg->send($admin, '⚠️ Tài khoản BossOTP <b>hết số dư</b>, hàng đợi gửi tin đang bị treo. Vui lòng nạp thêm!');
                }
            }
            $this->retryOrFail($job, $attempt, $rent['code'], $rent['message']);
            return;
        }

        $orderId = DB::insert('orders', [
            'user_id' => $job['user_id'],
            'service_id' => $service['id'],
            'service_name' => $service['name'],
            'provider_order_id' => $rent['order_id'],
            'phone' => $rent['phone'],
            'price' => $job['price'],
            'cost' => $service['cost'],
            'status' => 'pending',
            'kind' => 'sms',
            'sms_sent' => 1,
            'expires_at' => date('Y-m-d H:i:s', time() + Settings::int('order_timeout')),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $params = $service['sms_mode'] === 'custom' ? ['sms' => $job['content'], 'number_receiver' => $job['receiver']] : [];
        $send = $this->api->sendSms((string)$rent['order_id'], $params);

        if (!$send['ok']) {
            // Đơn thuê này không dùng được nữa; không tính tiền khách, thử lại bằng số khác
            DB::update('orders', ['status' => 'cancelled', 'sms_sent' => 0, 'sms_info' => mb_substr('Gửi lỗi: ' . $send['message'], 0, 250), 'updated_at' => now()], 'id = ?', [$orderId]);
            DB::update('sms_jobs', ['order_id' => $orderId], 'id = ?', [$job['id']]);
            $this->retryOrFail($job, $attempt, '', $send['message']);
            return;
        }

        $info = $job['receiver'] ? 'Đã gửi tới ' . $job['receiver'] : 'Đã gửi SMS';
        DB::update('orders', ['status' => 'success', 'sms_info' => $info, 'updated_at' => now()], 'id = ?', [$orderId]);
        DB::update('sms_jobs', ['status' => 'sent', 'order_id' => $orderId, 'attempts' => $attempt, 'error' => null, 'updated_at' => now()], 'id = ?', [$job['id']]);
        DB::query('UPDATE users SET total_spent = total_spent + ? WHERE id = ?', [$job['price'], $job['user_id']]);
        $this->afterJob($job['batch']);
    }

    private function retryOrFail(array $job, int $attempt, string $code, string $message): void
    {
        $max = max(1, Settings::int('sms_max_attempts'));
        if ($attempt < $max && in_array($code, self::RETRY_CODES, true)) {
            DB::update('sms_jobs', ['status' => 'queued', 'attempts' => $attempt, 'error' => mb_substr($message, 0, 250), 'updated_at' => now()], 'id = ?', [$job['id']]);
            return;
        }
        $job['attempts'] = $attempt;
        $this->fail($job, $message ?: 'Gửi thất bại');
    }

    /** Đánh dấu thất bại và hoàn tiền/lượt. Dùng được cho admin hủy job đang chờ. */
    public function fail(array $job, string $reason): bool
    {
        $n = DB::update('sms_jobs', ['status' => 'failed', 'attempts' => (int)$job['attempts'], 'error' => mb_substr($reason, 0, 250), 'updated_at' => now()], "id = ? AND status IN ('queued', 'processing')", [$job['id']]);
        if ($n === 0) {
            return false;
        }
        if ($job['user_package_id']) {
            DB::query('UPDATE user_packages SET credits_left = credits_left + 1 WHERE id = ?', [$job['user_package_id']]);
        } elseif ((float)$job['price'] > 0) {
            Wallet::change((int)$job['user_id'], (float)$job['price'], 'refund', "Hoàn tiền tin nhắn #{$job['id']}");
        }
        $this->afterJob($job['batch']);
        return true;
    }

    /** Khi cả lệnh xử lý xong: gửi báo cáo cho khách. */
    private function afterJob(string $batch): void
    {
        $rows = DB::all('SELECT * FROM sms_jobs WHERE batch = ?', [$batch]);
        foreach ($rows as $r) {
            if (in_array($r['status'], ['queued', 'processing'], true)) {
                return;
            }
        }
        $tgId = DB::value('SELECT tg_id FROM users WHERE id = ?', [$rows[0]['user_id']]);
        $this->tg->send($tgId, self::batchReport($rows));
    }

    public static function batchReport(array $rows): string
    {
        $sent = array_filter($rows, fn($r) => $r['status'] === 'sent');
        $failed = array_filter($rows, fn($r) => $r['status'] === 'failed');
        $pending = count($rows) - count($sent) - count($failed);
        $t = '✉️ <b>Kết quả gửi tin</b> (mã <code>' . tg_escape($rows[0]['batch']) . "</code>)\n\n"
            . '✅ Thành công: <b>' . count($sent) . '/' . count($rows) . "</b>\n";
        if ($failed) {
            $refund = array_sum(array_map(fn($r) => (float)$r['price'], $failed));
            $credits = count(array_filter($failed, fn($r) => $r['user_package_id']));
            $t .= '❌ Thất bại: <b>' . count($failed) . '</b> — đã hoàn ' . ($refund > 0 ? money($refund) : '') . ($refund > 0 && $credits ? ' + ' : '') . ($credits ? "$credits lượt gói" : '') . "\n";
        }
        if ($pending) {
            $t .= "⏳ Đang xử lý: $pending\n";
        }
        if (count($rows) <= 20) {
            $t .= "\n";
            foreach ($rows as $r) {
                $icon = ['sent' => '✅', 'failed' => '❌'][$r['status']] ?? '⏳';
                $t .= $icon . ' ' . tg_escape($r['receiver'] ?: 'SMS cố định') . ($r['status'] === 'failed' && $r['error'] ? ' — ' . tg_escape(mb_substr($r['error'], 0, 80)) : '') . "\n";
            }
        }
        return $t;
    }
}
