<?php
declare(strict_types=1);

// Trình cài đặt: tạo config.php, bảng dữ liệu và tài khoản admin.
define('APP_ROOT', __DIR__);
require APP_ROOT . '/src/helpers.php';
require APP_ROOT . '/src/DB.php';
require APP_ROOT . '/src/Settings.php';

if (file_exists(APP_ROOT . '/config.php')) {
    exit('Đã cài đặt. Xóa file config.php nếu muốn cài lại. <a href="admin/">Vào trang quản trị</a>');
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $config = [
        'db_driver' => $_POST['db_driver'] === 'mysql' ? 'mysql' : 'sqlite',
        'sqlite_path' => APP_ROOT . '/data/bot.sqlite',
        'mysql_host' => trim($_POST['mysql_host'] ?? '127.0.0.1'),
        'mysql_port' => (int)($_POST['mysql_port'] ?? 3306),
        'mysql_db' => trim($_POST['mysql_db'] ?? ''),
        'mysql_user' => trim($_POST['mysql_user'] ?? ''),
        'mysql_pass' => $_POST['mysql_pass'] ?? '',
        'secret' => bin2hex(random_bytes(16)),
    ];
    $user = trim($_POST['admin_user'] ?? '');
    $pass = $_POST['admin_pass'] ?? '';
    try {
        if ($user === '' || strlen($pass) < 6) {
            throw new RuntimeException('Tên đăng nhập không được trống, mật khẩu tối thiểu 6 ký tự.');
        }
        DB::init($config);
        DB::migrate();
        DB::insert('admins', ['username' => $user, 'password_hash' => password_hash($pass, PASSWORD_DEFAULT), 'created_at' => now()]);
        foreach (['bot_token', 'api_key', 'shop_name', 'admin_tg_ids'] as $k) {
            if (trim($_POST[$k] ?? '') !== '') {
                Settings::set($k, trim($_POST[$k]));
            }
        }
        $php = "<?php\nreturn " . var_export($config, true) . ";\n";
        if (file_put_contents(APP_ROOT . '/config.php', $php) === false) {
            throw new RuntimeException('Không ghi được config.php — kiểm tra quyền ghi thư mục.');
        }
        header('Location: admin/login.php?installed=1');
        exit;
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}
?>
<!doctype html>
<html lang="vi">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Cài đặt Bot SIM OTP</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container py-5" style="max-width:640px">
<h3 class="mb-4">⚙️ Cài đặt Bot bán SIM OTP</h3>
<?php if ($error): ?><div class="alert alert-danger"><?= h($error) ?></div><?php endif; ?>
<form method="post" class="card card-body shadow-sm">
    <h5>Cơ sở dữ liệu</h5>
    <select name="db_driver" class="form-select mb-2" onchange="document.getElementById('my').style.display=this.value==='mysql'?'block':'none'">
        <option value="sqlite">SQLite (đơn giản, không cần cấu hình)</option>
        <option value="mysql">MySQL / MariaDB</option>
    </select>
    <div id="my" style="display:none">
        <div class="row g-2 mb-2">
            <div class="col-8"><input name="mysql_host" class="form-control" placeholder="Host" value="127.0.0.1"></div>
            <div class="col-4"><input name="mysql_port" class="form-control" placeholder="Port" value="3306"></div>
        </div>
        <input name="mysql_db" class="form-control mb-2" placeholder="Tên database">
        <input name="mysql_user" class="form-control mb-2" placeholder="User">
        <input name="mysql_pass" type="password" class="form-control mb-2" placeholder="Mật khẩu">
    </div>
    <h5 class="mt-3">Tài khoản quản trị</h5>
    <input name="admin_user" class="form-control mb-2" placeholder="Tên đăng nhập" required>
    <input name="admin_pass" type="password" class="form-control mb-2" placeholder="Mật khẩu (≥ 6 ký tự)" required>
    <h5 class="mt-3">Cấu hình nhanh (có thể sửa sau)</h5>
    <input name="shop_name" class="form-control mb-2" placeholder="Tên shop" value="SIM OTP Shop">
    <input name="bot_token" class="form-control mb-2" placeholder="Bot token từ @BotFather">
    <input name="api_key" class="form-control mb-2" placeholder="API key BossOTP">
    <input name="admin_tg_ids" class="form-control mb-2" placeholder="Telegram ID của admin (cách nhau dấu phẩy)">
    <button class="btn btn-primary mt-3">Cài đặt</button>
</form>
</div>
</body>
</html>
