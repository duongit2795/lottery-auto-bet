<?php
declare(strict_types=1);

/**
 * Tự động hóa không cần cron: kiểm tra OTP, tự hủy + hoàn tiền đơn hết hạn,
 * đóng đơn gửi tin, quét nạp tiền ngân hàng.
 *
 * - tick(): chạy 1 lượt công việc (có khóa để không chạy chồng).
 * - worker.php: tiến trình nền chạy ~50 giây rồi tự gọi lại chính nó (vòng lặp liên tục).
 * - kick(): gọi ở webhook / Mini App / trang admin; nếu worker đã dừng thì khởi động lại.
 * Nếu hosting có cron (cron.php) thì vẫn chạy song song được, khóa sẽ tránh trùng lặp.
 */
final class Scheduler
{
    public const WORKER_SECONDS = 50;

    private static function file(string $name): string
    {
        return APP_ROOT . '/data/' . $name;
    }

    /** Lần chạy gần nhất (unix time), 0 nếu chưa chạy. */
    public static function lastTick(): int
    {
        return (int)@file_get_contents(self::file('last_tick'));
    }

    /** Chạy 1 lượt. Trả về số đơn đã kiểm tra, hoặc null nếu tiến trình khác đang chạy. */
    public static function tick(): ?int
    {
        $lock = @fopen(self::file('tick.lock'), 'c');
        if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
            return null;
        }
        $n = 0;
        try {
            $n = (new OrderService())->pollAll();
            if (BankSync::enabled()) {
                BankSync::sync();
            }
        } catch (Throwable $e) {
            app_log('scheduler', $e->getMessage());
        } finally {
            @file_put_contents(self::file('last_tick'), (string)time());
            flock($lock, LOCK_UN);
            fclose($lock);
        }
        return $n;
    }

    /** Khoảng nghỉ giữa 2 lượt: có đơn đang chờ thì kiểm tra dày hơn. */
    public static function interval(): int
    {
        $pending = (int)DB::value("SELECT COUNT(*) FROM orders WHERE status = 'pending'");
        return $pending > 0 ? 5 : 15;
    }

    /** Lần cron.php chạy gần nhất: ['time' => unix|0, 'via' => cli|url|'']. */
    public static function lastCron(): array
    {
        [$t, $via] = array_pad(explode('|', (string)@file_get_contents(self::file('last_cron'))), 2, '');
        return ['time' => (int)$t, 'via' => $via];
    }

    /** Worker đang sống nếu vừa chạy trong vòng 30 giây. */
    public static function alive(): bool
    {
        return time() - self::lastTick() < 30;
    }

    public static function enabled(): bool
    {
        return Settings::get('auto_worker') !== '0';
    }

    /** Gọi ở các request thường: worker chết thì khởi động lại (tối đa 1 lần / 20 giây). */
    public static function kick(): void
    {
        if (!self::enabled() || self::alive()) {
            return;
        }
        $mark = self::file('last_kick');
        if (time() - (int)@file_get_contents($mark) < 20) {
            return;
        }
        @file_put_contents($mark, (string)time());
        self::spawn();
    }

    /** Gửi request không chờ tới worker.php để chạy nền. */
    public static function spawn(): bool
    {
        $base = rtrim(trim((string)Settings::get('site_url')), '/');
        if ($base === '') {
            return false;
        }
        $url = $base . '/worker.php?secret=' . rawurlencode((string)cfg('secret'));
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT_MS => 2500,
            CURLOPT_CONNECTTIMEOUT_MS => 2000,
            CURLOPT_NOSIGNAL => true,
            CURLOPT_USERAGENT => 'SimOtpBot-Worker',
        ]);
        curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_errno($ch);
        curl_close($ch);
        // Hết thời gian chờ (28) là bình thường: worker vẫn đang chạy phía server
        $ok = $code === 200 || $err === 28;
        if (!$ok) {
            app_log('scheduler', "spawn $url thất bại: HTTP $code, curl $err");
        }
        return $ok;
    }

    /** Kick sau khi đã trả lời request (không làm chậm người dùng). */
    public static function kickAfterResponse(): void
    {
        register_shutdown_function(function () {
            if (function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            }
            try {
                self::kick();
            } catch (Throwable $e) {
                app_log('scheduler', $e->getMessage());
            }
        });
    }

    /** Lưu địa chỉ web (để worker tự gọi lại chính nó). $force = cập nhật cả khi đã có. */
    public static function rememberSiteUrl(bool $force = false): void
    {
        if (empty($_SERVER['HTTP_HOST']) || (!$force && Settings::get('site_url'))) {
            return;
        }
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
        $script = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? '/'));
        // Thư mục gốc của bot = thư mục chứa bootstrap.php
        $rel = trim(substr(str_replace('\\', '/', dirname($_SERVER['SCRIPT_FILENAME'] ?? '')), strlen(str_replace('\\', '/', APP_ROOT))), '/');
        $dir = rtrim(dirname($script), '/');
        if ($rel !== '') {
            $dir = rtrim(substr($dir, 0, -strlen($rel)), '/');
        }
        $url = ($https ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . $dir;
        if ($url !== Settings::get('site_url')) {
            Settings::set('site_url', $url);
        }
    }
}
