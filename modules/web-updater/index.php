<?php
/**
 * Trang cập nhật mã nguồn — mở bằng trình duyệt: https://ten-mien.com/updater/
 * Có đăng nhập riêng bằng mật khẩu trong config.php, không phụ thuộc hệ thống tài khoản của dự án.
 */
declare(strict_types=1);
require __DIR__ . '/Updater.php';

ini_set('display_errors', '0');
session_name('webupdater');
session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax', 'secure' => !empty($_SERVER['HTTPS'])]);
session_start();

function h($s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function page(string $title, string $body): void
{
    echo '<!doctype html><html lang="vi"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>' . h($title) . '</title><style>
        body{margin:0;background:#f3f5f8;font:15px/1.5 -apple-system,Segoe UI,Roboto,sans-serif;color:#1f2937}
        .wrap{max-width:560px;margin:0 auto;padding:16px}
        .card{background:#fff;border-radius:14px;padding:16px;margin-bottom:14px;box-shadow:0 1px 2px rgba(0,0,0,.05)}
        h1{font-size:20px;margin:6px 0 14px} h2{font-size:16px;margin:0 0 10px}
        input{width:100%;box-sizing:border-box;padding:12px;border:1px solid #d1d5db;border-radius:10px;font-size:16px;margin:4px 0 10px}
        button{width:100%;padding:13px;border:0;border-radius:10px;background:#2563eb;color:#fff;font-size:16px;font-weight:600}
        button.light{background:#eef2ff;color:#1e40af} button.warn{background:#fff7ed;color:#9a3412}
        button:disabled{opacity:.5}
        .ok{background:#dcfce7;color:#166534}.err{background:#fee2e2;color:#991b1b}.info{background:#e0f2fe;color:#075985}
        .msg{padding:12px;border-radius:10px;margin-bottom:14px}
        .muted{color:#6b7280;font-size:13px} .row{display:flex;gap:8px;align-items:center}
        .item{border-top:1px solid #f1f5f9;padding:10px 0} code{background:#f1f5f9;padding:2px 5px;border-radius:5px;word-break:break-all}
        a{color:#2563eb}
    </style></head><body><div class="wrap">' . $body . '</div></body></html>';
    exit;
}

// ---------------------------------------------------------------- cấu hình
if (!is_file(__DIR__ . '/config.php')) {
    page('Chưa cấu hình', '<div class="card"><h1>⚙️ Chưa có config.php</h1><p>Chép <code>config.sample.php</code> thành <code>config.php</code> trong thư mục <code>' . h(__DIR__) . '</code> rồi điền mật khẩu và repo GitHub.</p></div>');
}
$config = require __DIR__ . '/config.php';
if (empty($config['password_hash']) && empty($config['password'])) {
    $suggest = password_hash(bin2hex(random_bytes(4)), PASSWORD_DEFAULT);
    page('Chưa đặt mật khẩu', '<div class="card"><h1>🔐 Chưa đặt mật khẩu</h1><p>Mở <code>config.php</code>, điền <code>\'password\' =&gt; \'mật-khẩu-của-bạn\'</code>, hoặc dùng mã hash (an toàn hơn).</p>'
        . '<p class="muted">Tạo hash tại máy chủ: <code>php -r "echo password_hash(\'mat-khau\', PASSWORD_DEFAULT);"</code></p></div>');
}
$updater = new WebUpdater($config);

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
}
$csrf = '<input type="hidden" name="csrf" value="' . h($_SESSION['csrf']) . '">';

// ---------------------------------------------------------------- đăng nhập
function check_password(array $c, string $p): bool
{
    return !empty($c['password_hash']) ? password_verify($p, $c['password_hash']) : hash_equals((string)$c['password'], $p);
}

if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: ./');
    exit;
}
if (empty($_SESSION['auth'])) {
    $error = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $fails = $_SESSION['fails'] ?? 0;
        if ($fails >= 5 && time() - ($_SESSION['fail_at'] ?? 0) < 300) {
            $error = 'Sai quá nhiều lần, thử lại sau 5 phút.';
        } elseif (hash_equals($_SESSION['csrf'], (string)($_POST['csrf'] ?? '')) && check_password($config, (string)($_POST['password'] ?? ''))) {
            session_regenerate_id(true);
            $_SESSION['auth'] = true;
            header('Location: ./');
            exit;
        } else {
            $_SESSION['fails'] = $fails + 1;
            $_SESSION['fail_at'] = time();
            $error = 'Sai mật khẩu.';
        }
    }
    page('Đăng nhập', '<div class="card"><h1>🔐 Cập nhật mã nguồn</h1>' . ($error ? '<div class="msg err">' . h($error) . '</div>' : '')
        . '<form method="post">' . $csrf . '<input type="password" name="password" placeholder="Mật khẩu" autofocus required><button>Đăng nhập</button></form></div>');
}

// ---------------------------------------------------------------- thao tác
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf'], (string)($_POST['csrf'] ?? ''))) {
        $_SESSION['flash'] = [false, 'Phiên hết hạn, thử lại.'];
    } else {
        ignore_user_abort(true);
        set_time_limit(300);
        $action = $_POST['action'] ?? '';
        if ($action === 'github') {
            $r = $updater->updateFromGithub();
        } elseif ($action === 'upload') {
            $f = $_FILES['zip'] ?? null;
            $r = $f && $f['error'] === UPLOAD_ERR_OK
                ? $updater->applyZip($f['tmp_name'], 'Upload ' . basename((string)$f['name']))
                : ['ok' => false, 'message' => 'Chưa chọn file hoặc upload lỗi. Giới hạn upload: ' . ini_get('upload_max_filesize')];
        } elseif ($action === 'restore') {
            $r = $updater->restore((string)($_POST['backup'] ?? ''));
        } elseif ($action === 'backup') {
            $r = ['ok' => true, 'message' => 'Đã tạo ' . basename($updater->backup())];
        } else {
            $r = ['ok' => false, 'message' => 'Thao tác không hợp lệ.'];
        }
        $_SESSION['flash'] = [$r['ok'], $r['message']];
    }
    header('Location: ./');
    exit;
}

// ---------------------------------------------------------------- giao diện
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
$problem = $updater->problem();
$state = $updater->state();
$gh = $config['github'] ?? [];
$latest = !empty($gh['repo']) && $gh['repo'] !== 'ten-tai-khoan/ten-repo' ? $updater->latest() : null;
$busy = 'onsubmit="var b=this.querySelector(\'button\');b.disabled=true;b.innerText=\'⏳ Đang xử lý, đừng tắt trang...\'"';

$html = '<div class="row" style="justify-content:space-between"><h1>🔄 Cập nhật mã nguồn</h1><a href="?logout=1" class="muted">Đăng xuất</a></div>';
if ($flash) {
    $html .= '<div class="msg ' . ($flash[0] ? 'ok' : 'err') . '">' . h($flash[1]) . '</div>';
}
if ($problem) {
    $html .= '<div class="msg err">⚠️ ' . h($problem) . '</div>';
}
$html .= '<div class="card"><div class="muted">Phiên bản đang chạy</div><b>' . h($state['label'] ?? 'Chưa rõ (chưa cập nhật qua trang này)') . '</b>'
    . (!empty($state['updated_at']) ? '<div class="muted">Cập nhật lúc ' . h($state['updated_at']) . '</div>' : '') . '</div>';

// GitHub
$html .= '<div class="card"><h2>☁️ Từ GitHub</h2>';
if (!$latest) {
    $html .= '<p class="muted">Chưa cấu hình <code>github.repo</code> trong config.php.</p>';
} elseif (isset($latest['error'])) {
    $html .= '<div class="msg err">' . h($latest['error']) . '</div>';
} else {
    $same = ($state['version'] ?? '') === $latest['sha'];
    $html .= '<p>Bản mới nhất: <b>' . h($latest['short']) . '</b> — ' . h($latest['message']) . ' <span class="muted">(' . h($latest['date']) . ')</span></p>'
        . '<div class="msg ' . ($same ? 'ok' : 'info') . '">' . ($same ? '✅ Đang dùng bản mới nhất.' : '🆕 Có bản mới, bấm nút để cập nhật.') . '</div>'
        . '<form method="post" ' . $busy . '>' . $csrf . '<input type="hidden" name="action" value="github"><button ' . ($problem ? 'disabled' : '') . '>🔄 Cập nhật từ GitHub</button></form>';
}
$html .= '<p class="muted">Repo: <code>' . h($gh['repo'] ?? '') . '</code> · nhánh <code>' . h($gh['branch'] ?? '') . '</code></p></div>';

// Zip
$html .= '<div class="card"><h2>📦 Từ file zip</h2><form method="post" enctype="multipart/form-data" ' . $busy . '>' . $csrf
    . '<input type="hidden" name="action" value="upload"><input type="file" name="zip" accept=".zip" required><button class="light" ' . ($problem ? 'disabled' : '') . '>⬆️ Tải lên &amp; cập nhật</button></form>'
    . '<p class="muted">Giới hạn upload: ' . h(ini_get('upload_max_filesize')) . '. Không ghi đè: <code>' . h(implode(', ', $updater->config('keep'))) . '</code></p></div>';

// Sao lưu
$html .= '<div class="card"><h2>🗂 Bản sao lưu</h2>';
$backups = $problem ? [] : $updater->backups();
foreach ($backups as $b) {
    $html .= '<div class="item"><div><b>' . h($b['time']) . '</b> · ' . round($b['size'] / 1024) . ' KB' . ($b['label'] ? ' · ' . h($b['label']) : '') . '</div>'
        . '<form method="post" onsubmit="return confirm(\'Khôi phục về bản này?\')">' . $csrf . '<input type="hidden" name="action" value="restore"><input type="hidden" name="backup" value="' . h($b['name']) . '">'
        . '<button class="warn" style="margin-top:6px">↩️ Khôi phục</button></form></div>';
}
if (!$backups) {
    $html .= '<p class="muted">Chưa có bản sao lưu — sẽ tự tạo trước mỗi lần cập nhật.</p>';
}
$html .= '<form method="post" style="margin-top:8px">' . $csrf . '<input type="hidden" name="action" value="backup"><button class="light" ' . ($problem ? 'disabled' : '') . '>💾 Sao lưu ngay</button></form></div>';

// Nhật ký
if ($hist = $updater->history()) {
    $html .= '<div class="card"><h2>📜 Lịch sử</h2>';
    foreach ($hist as $x) {
        $html .= '<div class="item muted">' . h($x['time']) . ' — ' . h($x['msg']) . '</div>';
    }
    $html .= '</div>';
}
page('Cập nhật mã nguồn', $html);
