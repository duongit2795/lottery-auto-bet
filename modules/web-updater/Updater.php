<?php
declare(strict_types=1);

/**
 * WebUpdater — cập nhật mã nguồn website PHP ngay trên trình duyệt.
 *
 * Không phụ thuộc framework hay database: mọi thiết lập nằm trong config.php,
 * dữ liệu riêng (bản sao lưu, nhật ký, phiên bản) nằm trong thư mục data/ của module.
 *
 * Quy trình mỗi lần cập nhật (từ GitHub hoặc file zip):
 *   1. Giải nén vào thư mục tạm
 *   2. Kiểm tra gói có đúng là mã nguồn dự án (các file bắt buộc)
 *   3. Kiểm tra lỗi cú pháp PHP (nếu hosting cho chạy `php -l`)
 *   4. Sao lưu mã nguồn đang chạy
 *   5. Chép đè file mới — BỎ QUA các đường dẫn trong `keep` (config, dữ liệu...)
 *   6. Mở thử trang web; nếu lỗi 500 → tự khôi phục bản sao lưu
 *
 * Cách dùng nhanh:
 *   $u = new WebUpdater(require 'config.php');
 *   $r = $u->updateFromGithub();          // hoặc $u->applyZip('/duong-dan/file.zip', 'Tên bản')
 *   echo $r['message'];
 */
final class WebUpdater
{
    private array $cfg;

    public function __construct(array $config)
    {
        $this->cfg = $config + [
            'root' => dirname(__DIR__),     // thư mục gốc dự án
            'data_dir' => __DIR__ . '/data',
            'keep' => ['config.php', 'data', '.git', '.env'],
            'required' => [],
            'github' => ['repo' => '', 'branch' => 'main', 'token' => ''],
            'max_backups' => 5,
            'health_url' => '',
            'lint' => true,
            'after_update' => null,
        ];
        $this->cfg['root'] = rtrim(str_replace('\\', '/', (string)realpath($this->cfg['root'])), '/');
        // Luôn giữ nguyên cấu hình và dữ liệu của chính module
        $self = ltrim(substr(str_replace('\\', '/', __DIR__), strlen($this->cfg['root'])), '/');
        $this->cfg['keep'][] = $self . '/config.php';
        $this->cfg['keep'][] = $self . '/data';
        if (!is_dir($this->cfg['data_dir'])) {
            @mkdir($this->cfg['data_dir'], 0775, true);
        }
        // Chặn truy cập web vào bản sao lưu (Apache). Nginx: xem README.
        if (is_dir($this->cfg['data_dir']) && !is_file($this->cfg['data_dir'] . '/.htaccess')) {
            @file_put_contents($this->cfg['data_dir'] . '/.htaccess', "Require all denied\nDeny from all\n");
            @file_put_contents($this->cfg['data_dir'] . '/index.html', '');
        }
    }

    public function config(string $key)
    {
        return $this->cfg[$key] ?? null;
    }

    /** Kiểm tra môi trường. Trả về thông báo lỗi, hoặc null nếu dùng được. */
    public function problem(): ?string
    {
        if (!class_exists('ZipArchive')) {
            return 'Hosting chưa bật extension PHP "zip". aaPanel: App Store → PHP → Settings → Install extensions → zip.';
        }
        if (!is_writable($this->cfg['root'])) {
            return 'Thư mục ' . $this->cfg['root'] . ' không ghi được. Cấp quyền ghi cho user chạy PHP (thường là www).';
        }
        if (!is_writable($this->cfg['data_dir'])) {
            return 'Thư mục ' . $this->cfg['data_dir'] . ' không ghi được.';
        }
        return null;
    }

    // ================================================================ GitHub

    /** Gọi GitHub API. $saveTo: lưu thẳng nội dung vào file (dùng khi tải zip). */
    private function github(string $path, ?string $saveTo = null): array
    {
        $headers = ['User-Agent: WebUpdater', 'Accept: application/vnd.github+json'];
        if (!empty($this->cfg['github']['token'])) {
            $headers[] = 'Authorization: Bearer ' . $this->cfg['github']['token'];
        }
        $ch = curl_init('https://api.github.com' . $path);
        curl_setopt_array($ch, [CURLOPT_HTTPHEADER => $headers, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 120, CURLOPT_CONNECTTIMEOUT => 15]);
        $fh = null;
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

    /** Commit mới nhất của nhánh: ['sha','short','message','date'] hoặc ['error' => ...]. */
    public function latest(): array
    {
        $repo = trim((string)$this->cfg['github']['repo']);
        $branch = trim((string)$this->cfg['github']['branch']);
        if ($repo === '' || $branch === '') {
            return ['error' => 'Chưa cấu hình github.repo / github.branch trong config.php.'];
        }
        $r = $this->github('/repos/' . $repo . '/commits?per_page=1&sha=' . rawurlencode($branch));
        $data = json_decode($r['body'], true);
        if ($r['code'] !== 200 || !isset($data[0]['sha'])) {
            $msg = $r['error'] ?: ($data['message'] ?? 'HTTP ' . $r['code']);
            return ['error' => 'Không đọc được GitHub: ' . $msg . ($r['code'] === 404 ? ' (sai repo/nhánh, hoặc repo private chưa có token)' : '')];
        }
        $c = $data[0];
        return [
            'sha' => $c['sha'],
            'short' => substr($c['sha'], 0, 7),
            'message' => strtok((string)$c['commit']['message'], "\n"),
            'date' => date('Y-m-d H:i', strtotime((string)$c['commit']['committer']['date'])),
        ];
    }

    /** Tải bản mới nhất từ GitHub và cập nhật. */
    public function updateFromGithub(): array
    {
        $latest = $this->latest();
        if (isset($latest['error'])) {
            return ['ok' => false, 'message' => $latest['error']];
        }
        $zip = $this->cfg['data_dir'] . '/github-' . $latest['short'] . '.zip';
        $r = $this->github('/repos/' . $this->cfg['github']['repo'] . '/zipball/' . $latest['sha'], $zip);
        if ($r['code'] !== 200 || !is_file($zip) || filesize($zip) < 100) {
            @unlink($zip);
            return ['ok' => false, 'message' => 'Tải bản cập nhật thất bại: ' . ($r['error'] ?: 'HTTP ' . $r['code'])];
        }
        $res = $this->applyZip($zip, $latest['short'] . ' — ' . $latest['message'], $latest['sha']);
        @unlink($zip);
        return $res;
    }

    // ============================================================ cập nhật

    /**
     * Cập nhật từ 1 file zip.
     * $label: tên hiển thị của bản, $version: mã phiên bản (vd sha commit), $backup: có sao lưu trước không.
     */
    public function applyZip(string $zipPath, string $label, string $version = '', bool $backup = true): array
    {
        if ($p = $this->problem()) {
            return ['ok' => false, 'message' => $p];
        }
        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            return ['ok' => false, 'message' => 'File không phải zip hợp lệ.'];
        }
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string)$zip->getNameIndex($i);
            if (str_contains($name, '..') || str_starts_with($name, '/')) {
                $zip->close();
                return ['ok' => false, 'message' => 'File zip chứa đường dẫn không an toàn: ' . $name];
            }
        }
        $work = $this->cfg['data_dir'] . '/tmp-' . bin2hex(random_bytes(4));
        $zip->extractTo($work);
        $zip->close();

        try {
            $src = $this->findRoot($work);
            if ($src === null) {
                return ['ok' => false, 'message' => 'File zip không đúng mã nguồn dự án (thiếu: ' . implode(', ', $this->cfg['required']) . ').'];
            }
            if ($err = $this->lint($src)) {
                return ['ok' => false, 'message' => 'Bản cập nhật có lỗi cú pháp, đã hủy: ' . $err];
            }
            $backupFile = $backup ? $this->backup() : null;
            $count = 0;
            $this->copyTree($src, $this->cfg['root'], $count);
            if (function_exists('opcache_reset')) {
                @opcache_reset();
            }
            if ($this->healthy() === false && $backupFile) {
                $this->applyZip($backupFile, 'Tự khôi phục', '', false);
                $this->log('⚠️ Cập nhật "' . $label . '" làm web lỗi → đã tự khôi phục bản cũ');
                return ['ok' => false, 'message' => 'Sau khi cập nhật trang web bị lỗi → đã tự khôi phục bản cũ.'];
            }
            if (is_callable($this->cfg['after_update'])) {
                ($this->cfg['after_update'])();
            }
            if ($backup) {
                $this->saveState(['version' => $version, 'label' => $label, 'updated_at' => date('Y-m-d H:i:s')]);
                $this->log("✅ $label ($count file thay đổi)");
            }
            return ['ok' => true, 'message' => "Đã cập nhật $count file: $label"];
        } finally {
            $this->rmTree($work);
        }
    }

    /** Thư mục chứa mã nguồn trong gói (zip GitHub có thêm 1 cấp thư mục bọc ngoài). */
    private function findRoot(string $dir, int $depth = 0): ?string
    {
        $ok = true;
        foreach ($this->cfg['required'] as $f) {
            $ok = $ok && file_exists($dir . '/' . $f);
        }
        // Không khai báo file bắt buộc: lấy thư mục con duy nhất nếu zip chỉ có 1 thư mục
        if (!$this->cfg['required']) {
            $items = array_values(array_diff(scandir($dir) ?: [], ['.', '..']));
            return count($items) === 1 && is_dir($dir . '/' . $items[0]) ? $dir . '/' . $items[0] : $dir;
        }
        if ($ok) {
            return $dir;
        }
        if ($depth < 2) {
            foreach (glob($dir . '/*', GLOB_ONLYDIR) ?: [] as $sub) {
                if ($r = $this->findRoot($sub, $depth + 1)) {
                    return $r;
                }
            }
        }
        return null;
    }

    /** Đường dẫn (tương đối) có nằm trong danh sách giữ nguyên không. */
    private function isKept(string $rel): bool
    {
        foreach ($this->cfg['keep'] as $k) {
            $k = trim(str_replace('\\', '/', $k), '/');
            if ($rel === $k || str_starts_with($rel, $k . '/')) {
                return true;
            }
        }
        return false;
    }

    /** Chép đè thư mục, bỏ qua đường dẫn giữ nguyên, chỉ ghi file có thay đổi. */
    private function copyTree(string $src, string $dst, int &$count, string $rel = ''): void
    {
        foreach (scandir($src) ?: [] as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $path = ltrim($rel . '/' . $name, '/');
            if ($this->isKept($path)) {
                continue;
            }
            $from = $src . '/' . $name;
            $to = $dst . '/' . $name;
            if (is_dir($from)) {
                if (!is_dir($to)) {
                    mkdir($to, 0775, true);
                }
                $this->copyTree($from, $to, $count, $path);
            } elseif (!is_file($to) || md5_file($from) !== md5_file($to)) {
                if (!copy($from, $to)) {
                    throw new RuntimeException('Không ghi được file ' . $path);
                }
                $count++;
            }
        }
    }

    /** Kiểm tra cú pháp bằng `php -l` nếu hosting cho phép. Trả về lỗi đầu tiên hoặc null. */
    private function lint(string $dir): ?string
    {
        $disabled = array_map('trim', explode(',', (string)ini_get('disable_functions')));
        if (!$this->cfg['lint'] || !function_exists('exec') || in_array('exec', $disabled, true)) {
            return null;
        }
        $php = PHP_BINARY && !str_contains(PHP_BINARY, 'fpm') ? PHP_BINARY : PHP_BINDIR . '/php';
        if (!@is_executable($php)) {
            return null;
        }
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if ($f->getExtension() === 'php') {
                $out = [];
                @exec(escapeshellarg($php) . ' -l ' . escapeshellarg($f->getPathname()) . ' 2>&1', $out, $code);
                if ($code !== 0 && $out) {
                    return str_replace($dir . '/', '', $out[0]);
                }
            }
        }
        return null;
    }

    /** true = web chạy bình thường, false = lỗi 500, null = không kiểm tra (chưa cấu hình health_url). */
    private function healthy(): ?bool
    {
        if (empty($this->cfg['health_url'])) {
            return null;
        }
        $ch = curl_init($this->cfg['health_url']);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_CONNECTTIMEOUT => 5]);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($body === false || $code === 0) {
            return null;
        }
        return $code < 500 && !str_contains((string)$body, 'Fatal error');
    }

    private function rmTree(string $dir): void
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

    // ============================================================= sao lưu

    /** Nén mã nguồn hiện tại (trừ đường dẫn giữ nguyên). Trả về đường dẫn file zip. */
    public function backup(): string
    {
        $dir = $this->cfg['data_dir'] . '/backups';
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $file = $dir . '/backup-' . date('Ymd-His') . '.zip';
        $zip = new ZipArchive();
        $zip->open($file, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $root = $this->cfg['root'];
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            $rel = ltrim(str_replace('\\', '/', substr($f->getPathname(), strlen($root))), '/');
            if ($f->isFile() && !$this->isKept($rel)) {
                $zip->addFile($f->getPathname(), $rel);
            }
        }
        $zip->setArchiveComment((string)($this->state()['label'] ?? ''));
        $zip->close();
        foreach (array_slice($this->backups(), (int)$this->cfg['max_backups']) as $old) {
            @unlink($dir . '/' . $old['name']);
        }
        return $file;
    }

    /** Danh sách bản sao lưu, mới nhất trước: [name, size, time, label]. */
    public function backups(): array
    {
        $out = [];
        foreach (glob($this->cfg['data_dir'] . '/backups/backup-*.zip') ?: [] as $f) {
            $z = new ZipArchive();
            $label = $z->open($f) === true ? (string)$z->getArchiveComment() : '';
            $z->close();
            $out[] = ['name' => basename($f), 'size' => filesize($f), 'time' => date('Y-m-d H:i:s', filemtime($f)), 'label' => $label];
        }
        usort($out, fn($a, $b) => strcmp($b['name'], $a['name']));
        return $out;
    }

    /** Khôi phục 1 bản sao lưu (có sao lưu bản hiện tại trước khi khôi phục). */
    public function restore(string $name): array
    {
        $file = $this->cfg['data_dir'] . '/backups/' . basename($name);
        if (!preg_match('/^backup-[\d-]+\.zip$/', basename($name)) || !is_file($file)) {
            return ['ok' => false, 'message' => 'Không tìm thấy bản sao lưu.'];
        }
        return $this->applyZip($file, 'Khôi phục ' . basename($name));
    }

    // ================================================== phiên bản & nhật ký

    /** Phiên bản đang chạy: ['version','label','updated_at']. */
    public function state(): array
    {
        return json_decode((string)@file_get_contents($this->cfg['data_dir'] . '/state.json'), true) ?: [];
    }

    private function saveState(array $s): void
    {
        file_put_contents($this->cfg['data_dir'] . '/state.json', json_encode($s, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }

    public function log(string $msg): void
    {
        $log = $this->history();
        array_unshift($log, ['time' => date('Y-m-d H:i:s'), 'msg' => $msg]);
        file_put_contents($this->cfg['data_dir'] . '/history.json', json_encode(array_slice($log, 0, 30), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }

    public function history(): array
    {
        return json_decode((string)@file_get_contents($this->cfg['data_dir'] . '/history.json'), true) ?: [];
    }
}
