<?php
declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

session_name('simbot_admin');
session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax', 'secure' => !empty($_SERVER['HTTPS'])]);
session_start();

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
}

function require_login(): void
{
    if (empty($_SESSION['admin_id'])) {
        header('Location: login.php');
        exit;
    }
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . h($_SESSION['csrf']) . '">';
}

function check_csrf(): void
{
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && !hash_equals($_SESSION['csrf'], (string)($_POST['csrf'] ?? ''))) {
        http_response_code(400);
        exit('CSRF token không hợp lệ, tải lại trang và thử lại.');
    }
}

function flash(?string $msg = null, string $type = 'success'): ?array
{
    if ($msg !== null) {
        $_SESSION['flash'] = [$msg, $type];
        return null;
    }
    $f = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $f;
}

function redirect(string $to): void
{
    header('Location: ' . $to);
    exit;
}

function base_url(): string
{
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https' ? 'https' : 'http';
    $dir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'], 2)), '/');
    return $scheme . '://' . $_SERVER['HTTP_HOST'] . $dir;
}

function paginate(int $total, int $per, int $page, array $query): string
{
    $pages = (int)ceil($total / $per);
    if ($pages <= 1) {
        return '';
    }
    $out = '<nav><ul class="pagination pagination-sm flex-wrap">';
    for ($i = 1; $i <= $pages; $i++) {
        if ($pages > 12 && abs($i - $page) > 3 && $i !== 1 && $i !== $pages) {
            if ($i === 2 || $i === $pages - 1) {
                $out .= '<li class="page-item disabled"><span class="page-link">…</span></li>';
            }
            continue;
        }
        $q = http_build_query(array_merge($query, ['p' => $i]));
        $out .= '<li class="page-item' . ($i === $page ? ' active' : '') . '"><a class="page-link" href="?' . h($q) . '">' . $i . '</a></li>';
    }
    return $out . '</ul></nav>';
}

function status_badge(string $s): string
{
    $map = [
        'pending' => 'warning', 'success' => 'success', 'approved' => 'success',
        'refunded' => 'secondary', 'cancelled' => 'secondary', 'rejected' => 'danger',
    ];
    return '<span class="badge text-bg-' . ($map[$s] ?? 'light') . '">' . h($s) . '</span>';
}

function layout_start(string $title): void
{
    $nav = [
        'index.php' => '📊 Tổng quan',
        'orders.php' => '📱 Đơn thuê',
        'deposits.php' => '💰 Nạp tiền',
        'users.php' => '👥 Người dùng',
        'services.php' => '🏷 Dịch vụ & giá',
        'broadcast.php' => '📢 Thông báo',
        'settings.php' => '⚙️ Cài đặt',
        'api.php' => '🔌 API nhà cung cấp',
    ];
    $cur = basename($_SERVER['SCRIPT_NAME']);
    $pendingDep = (int)DB::value("SELECT COUNT(*) FROM deposits WHERE status = 'pending'");
    ?>
<!doctype html>
<html lang="vi">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($title) ?> — <?= h(Settings::get('shop_name')) ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
body{background:#f4f6f9}
.sidebar{min-height:100vh;background:#1f2937}
.sidebar a{color:#cbd5e1;text-decoration:none;display:block;padding:.6rem 1rem;border-radius:.4rem}
.sidebar a.active,.sidebar a:hover{background:#374151;color:#fff}
.stat{font-size:1.5rem;font-weight:600}
.table td,.table th{vertical-align:middle;font-size:.9rem}
</style>
</head>
<body>
<div class="container-fluid">
<div class="row">
<nav class="col-md-2 sidebar p-3 d-none d-md-block">
    <div class="text-white fw-bold mb-3"><?= h(Settings::get('shop_name')) ?></div>
    <?php foreach ($nav as $file => $label): ?>
        <a href="<?= $file ?>" class="<?= $cur === $file ? 'active' : '' ?>"><?= $label ?>
            <?php if ($file === 'deposits.php' && $pendingDep): ?><span class="badge text-bg-danger"><?= $pendingDep ?></span><?php endif; ?>
        </a>
    <?php endforeach; ?>
    <a href="logout.php" class="mt-4">🚪 Đăng xuất</a>
</nav>
<div class="d-md-none p-2 bg-dark">
    <select class="form-select" onchange="location=this.value">
        <?php foreach ($nav as $file => $label): ?><option value="<?= $file ?>" <?= $cur === $file ? 'selected' : '' ?>><?= $label ?></option><?php endforeach; ?>
        <option value="logout.php">🚪 Đăng xuất</option>
    </select>
</div>
<main class="col-md-10 p-4">
<h4 class="mb-4"><?= h($title) ?></h4>
<?php if ($f = flash()): ?><div class="alert alert-<?= h($f[1]) ?>"><?= $f[0] ?></div><?php endif; ?>
<?php
}

function layout_end(): void
{
    ?>
</main>
</div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
<?php
}
