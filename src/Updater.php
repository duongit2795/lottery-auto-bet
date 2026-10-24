<?php
declare(strict_types=1);

/**
 * Cập nhật mã nguồn từ trang admin: tải bản mới từ GitHub hoặc upload file zip.
 *
 * Quy trình: giải nén vào data/update → kiểm tra đúng mã nguồn bot → sao lưu bản
 * hiện tại vào data/backups → chép đè (giữ config.php và data/) → kiểm tra trang
 * admin còn chạy, lỗi thì tự khôi phục bản sao lưu.
 */
final class Updater
{
    /** Đường dẫn không bao giờ bị ghi đè / sao lưu. */
    private const KEEP = ['config.php', 'data', '.git'];
    /** File bắt buộc phải có trong gói cập nhật. */
    private const REQUIRED = ['bootstrap.php', 'src/Bot.php', 'src/DB.php', 'admin/_init.php'];
    private const MAX_BACKUPS = 5;

    public static function available(): ?string
    {
        if (!class_exists('ZipArchive')) {
            return 'Hosting chưa bật extension PHP "zip". Vào aaPanel → PHP → Cài extension "zip" (chỉ cần làm 1 lần).';
        }
        foreach (['', '/src', '/admin', '/data'] as $dir) {
            if (!is_writable(APP_ROOT . $dir)) {
                return 'Thư mục ' . (APP_ROOT . $dir) . ' không ghi được. Cấp quyền ghi cho user chạy PHP (thường là www) — vd: chown -R www:www ' . APP_ROOT;
            }
        }
        return null;
    }

    private static function dir(string $name): string
    {
        $d = APP_ROOT . '/data/' . $name;
        if (!is_dir($d)) {
            mkdir($d, 0775, true);
        }
        return $d;
    }

    // ---------------------------------------------------------------- GitHub

    private static function github(string $path, ?string $saveTo = null): array
    {
        $headers = ['User-Agent: SimOtpBot-Updater', 'Accept: application/vnd.github+json'];
        if ($token = trim((string)Settings::get('update_token'))) {
            $headers[] = 'Authorization: Bearer ' . $token;
        }
        $ch = curl_init('https://api.github.com' . $path);
        $fh = null;
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 120,
            CURLOPT_CONNECTTIMEOUT => 15,
        ]);
        if ($saveTo) {
            $fh = fopen($saveTo, 'wb');
            curl_setopt($ch, CURLOPT_FILE, $fh);
        } else {
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        }
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($fh) {
            fclose($fh);
        }
        return ['code' => $code, 'body' => $saveTo ? '' : (string)$body, 'error' => $err];
    }

    /** Commit mới nhất trên nhánh cấu hình: ['sha','short','message','date'] hoặc ['error'=>...]. */
    public static function latest(): array
    {
        $repo = trim((string)Settings::get('update_repo'));
        $branch = trim((string)Settings::get('update_branch'));
        if ($repo === '' || $branch === '') {
            return ['error' => 'Chưa cấu hình repo / nhánh GitHub.'];
        }
        $r = self::github('/repos/' . $repo . '/commits?per_page=1&sha=' . rawurlencode($branch));
        $data = json_decode($r['body'], true);
        if ($r['code'] !== 200 || !isset($data[0]['sha'])) {
            $msg = $r['error'] ?: ($data['message'] ?? 'HTTP ' . $r['code']);
            if ($r['code'] === 404) {
                $msg .= ' — sai repo/nhánh, hoặc repo private mà chưa nhập token.';
            }
            return ['error' => 'Không đọc được GitHub: ' . $msg];
        }
        $c = $data[0];
        return [
            'sha' => $c['sha'],
            'short' => substr($c['sha'], 0, 7),
            'message' => strtok((string)$c['commit']['message'], "\n"),
            'date' => date('Y-m-d H:i', strtotime((string)$c['commit']['committer']['date'])),
        ];
    }

    public static function updateFromGithub(): array
    {
        $latest = self::latest();
        if (isset($latest['error'])) {
            return ['ok' => false, 'message' => $latest['error']];
        }
        $zip = self::dir('update') . '/github-' . $latest['short'] . '.zip';
        $r = self::github('/repos/' . trim((string)Settings::get('update_repo')) . '/zipball/' . $latest['sha'], $zip);
        if ($r['code'] !== 200 || !is_file($zip) || filesize($zip) < 1000) {
            @unlink($zip);
            return ['ok' => false, 'message' => 'Tải bản cập nhật thất bại: ' . ($r['error'] ?: 'HTTP ' . $r['code'])];
        }
        $res = self::apply($zip, $latest['short'] . ' — ' . $latest['message'], $latest['sha']);
        @unlink($zip);
        return $res;
    }

    // ------------------------------------------------------------- áp dụng

    /** Áp dụng file zip. $version: sha commit (nếu biết). */
    public static function apply(string $zipPath, string $label, ?string $version = null, bool $makeBackup = true): array
    {
        if ($err = self::available()) {
            return ['ok' => false, 'message' => $err];
        }
        $work = self::dir('update') . '/x' . bin2hex(random_bytes(4));
        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            return ['ok' => false, 'message' => 'File không phải zip hợp lệ.'];
        }
        // Chặn đường dẫn kiểu ../ trong zip
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string)$zip->getNameIndex($i);
            if (str_contains($name, '..') || str_starts_with($name, '/')) {
                $zip->close();
                return ['ok' => false, 'message' => 'File zip chứa đường dẫn không hợp lệ: ' . $name];
            }
        }
        $zip->extractTo($work);
        $zip->close();

        try {
            $root = self::findRoot($work);
            if ($root === null) {
                return ['ok' => false, 'message' => 'File zip không phải mã nguồn bot (thiếu ' . implode(', ', self::REQUIRED) . ').'];
            }
            $syntax = self::lint($root);
            if ($syntax) {
                return ['ok' => false, 'message' => 'Bản cập nhật có lỗi cú pháp, đã hủy: ' . $syntax];
            }
            $backup = $makeBackup ? self::backup() : null;
            $count = 0;
            self::copyTree($root, APP_ROOT, $count);
            if (function_exists('opcache_reset')) {
                @opcache_reset();
            }

            // Kiểm tra trang admin còn chạy sau khi cập nhật
            $health = self::healthCheck();
            if ($health === false && $backup) {
                self::restore(basename($backup), false);
                return ['ok' => false, 'message' => 'Sau khi cập nhật trang web bị lỗi → đã tự khôi phục bản cũ. Không có gì thay đổi.'];
            }
            Settings::set('app_version', $version ?? '');
            Settings::set('app_version_label', $label);
            Settings::set('app_updated_at', now());
            self::log("✅ Cập nhật: $label ($count file)");
            return ['ok' => true, 'message' => "Đã cập nhật $count file: " . $label . ($health === null ? ' (không tự kiểm tra được trang web — hãy mở thử vài trang)' : '')];
        } finally {
            self::rmTree($work);
        }
    }

    /** Thư mục chứa mã nguồn bên trong gói (zip GitHub có thêm 1 cấp thư mục). */
    private static function findRoot(string $dir, int $depth = 0): ?string
    {
        $ok = true;
        foreach (self::REQUIRED as $f) {
            $ok = $ok && is_file($dir . '/' . $f);
        }
        if ($ok) {
            return $dir;
        }
        if ($depth >= 2) {
            return null;
        }
        foreach (glob($dir . '/*', GLOB_ONLYDIR) ?: [] as $sub) {
            if ($r = self::findRoot($sub, $depth + 1)) {
                return $r;
            }
        }
        return null;
    }

    /** Kiểm tra cú pháp các file PHP nếu hosting cho chạy lệnh php -l. Trả về lỗi đầu tiên hoặc null. */
    private static function lint(string $root): ?string
    {
        if (!function_exists('exec') || in_array('exec', array_map('trim', explode(',', (string)ini_get('disable_functions'))), true)) {
            return null;
        }
        $php = PHP_BINARY && !str_contains(PHP_BINARY, 'fpm') ? PHP_BINARY : (PHP_BINDIR . '/php');
        if (!is_executable($php)) {
            return null;
        }
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if ($f->getExtension() !== 'php') {
                continue;
            }
            $out = [];
            $code = 0;
            @exec(escapeshellarg($php) . ' -l ' . escapeshellarg($f->getPathname()) . ' 2>&1', $out, $code);
            if ($code !== 0 && $out) {
                return str_replace([$root . '/', 'Errors parsing ' . $f->getPathname()], '', implode(' ', $out));
            }
        }
        return null;
    }

    /** true = trang admin chạy bình thường, false = lỗi 500, null = không kiểm tra được. */
    private static function healthCheck(): ?bool
    {
        if (empty($_SERVER['HTTP_HOST'])) {
            return null;
        }
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
        $dir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/admin/x', 2)), '/');
        $ch = curl_init(($https ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . $dir . '/admin/login.php');
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_CONNECTTIMEOUT => 5]);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($body === false || $code === 0) {
            return null;
        }
        return $code < 500 && !str_contains((string)$body, 'Fatal error');
    }

    private static function copyTree(string $src, string $dst, int &$count, string $rel = ''): void
    {
        foreach (scandir($src) ?: [] as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $path = ltrim($rel . '/' . $name, '/');
            $from = $src . '/' . $name;
            $top = explode('/', $path)[0];
            // Giữ nguyên config.php và dữ liệu trong data/ (chỉ cập nhật data/.htaccess)
            if ($top === 'config.php' || $top === '.git' || ($top === 'data' && is_file($from) && $path !== 'data/.htaccess')) {
                continue;
            }
            $to = $dst . '/' . $name;
            if (is_dir($from)) {
                if (!is_dir($to)) {
                    mkdir($to, 0775, true);
                }
                self::copyTree($from, $to, $count, $path);
            } elseif (!is_file($to) || md5_file($from) !== md5_file($to)) {
                if (!copy($from, $to)) {
                    throw new RuntimeException('Không ghi được file ' . $path);
                }
                $count++;
            }
        }
    }

    private static function rmTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($dir);
    }

    // ------------------------------------------------------------- sao lưu

    /** Nén mã nguồn hiện tại (trừ config.php, data/). Trả về đường dẫn file. */
    public static function backup(): string
    {
        $file = self::dir('backups') . '/backup-' . date('Ymd-His') . '.zip';
        $zip = new ZipArchive();
        $zip->open($file, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(APP_ROOT, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            $rel = str_replace('\\', '/', substr($f->getPathname(), strlen(APP_ROOT) + 1));
            if ($f->isFile() && !in_array(explode('/', $rel)[0], self::KEEP, true)) {
                $zip->addFile($f->getPathname(), $rel);
            }
        }
        $zip->setArchiveComment((string)Settings::get('app_version_label'));
        $zip->close();

        // Giữ lại vài bản gần nhất
        $all = self::backups();
        foreach (array_slice($all, self::MAX_BACKUPS) as $old) {
            @unlink(self::dir('backups') . '/' . $old['name']);
        }
        return $file;
    }

    /** Danh sách bản sao lưu, mới nhất trước. */
    public static function backups(): array
    {
        $out = [];
        foreach (glob(self::dir('backups') . '/backup-*.zip') ?: [] as $f) {
            $z = new ZipArchive();
            $label = $z->open($f) === true ? (string)$z->getArchiveComment() : '';
            $z->close();
            $out[] = ['name' => basename($f), 'size' => filesize($f), 'time' => date('Y-m-d H:i:s', filemtime($f)), 'label' => $label];
        }
        usort($out, fn($a, $b) => strcmp($b['name'], $a['name']));
        return $out;
    }

    public static function restore(string $name, bool $makeBackup = true): array
    {
        if (!preg_match('/^backup-[\d-]+\.zip$/', $name) || !is_file(self::dir('backups') . '/' . $name)) {
            return ['ok' => false, 'message' => 'Không tìm thấy bản sao lưu.'];
        }
        $label = 'Khôi phục ' . $name;
        $res = self::apply(self::dir('backups') . '/' . $name, $label, null, $makeBackup);
        if ($res['ok']) {
            self::log('↩️ ' . $label);
        }
        return $res;
    }

    // ------------------------------------------------------------------ log

    private static function log(string $msg): void
    {
        $log = json_decode((string)Settings::get('update_log'), true) ?: [];
        array_unshift($log, ['time' => now(), 'msg' => $msg, 'by' => $_SESSION['admin_name'] ?? '']);
        Settings::set('update_log', json_encode(array_slice($log, 0, 20), JSON_UNESCAPED_UNICODE));
    }

    public static function history(): array
    {
        return json_decode((string)Settings::get('update_log'), true) ?: [];
    }
}
