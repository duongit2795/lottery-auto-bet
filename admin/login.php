<?php
require __DIR__ . '/_init.php';

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $key = 'login_fail_' . md5($_SERVER['REMOTE_ADDR'] ?? '');
    $fails = $_SESSION[$key] ?? 0;
    if ($fails >= 5 && time() - ($_SESSION[$key . '_t'] ?? 0) < 300) {
        $error = 'Sai quá nhiều lần, thử lại sau 5 phút.';
    } else {
        $a = DB::row('SELECT * FROM admins WHERE username = ?', [trim($_POST['username'] ?? '')]);
        if ($a && password_verify($_POST['password'] ?? '', $a['password_hash'])) {
            session_regenerate_id(true);
            $_SESSION['admin_id'] = $a['id'];
            $_SESSION['admin_name'] = $a['username'];
            unset($_SESSION[$key]);
            redirect('index.php');
        }
        $_SESSION[$key] = $fails + 1;
        $_SESSION[$key . '_t'] = time();
        $error = 'Sai tên đăng nhập hoặc mật khẩu.';
    }
}
?>
<!doctype html>
<html lang="vi">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Đăng nhập quản trị</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light d-flex align-items-center" style="min-height:100vh">
<div class="container" style="max-width:380px">
    <form method="post" class="card card-body shadow-sm">
        <h4 class="mb-3 text-center">🔐 Quản trị Bot</h4>
        <?php if (isset($_GET['installed'])): ?><div class="alert alert-success">Cài đặt thành công! Đăng nhập để tiếp tục.</div><?php endif; ?>
        <?php if ($error): ?><div class="alert alert-danger"><?= h($error) ?></div><?php endif; ?>
        <?= csrf_field() ?>
        <input name="username" class="form-control mb-2" placeholder="Tên đăng nhập" required autofocus>
        <input name="password" type="password" class="form-control mb-3" placeholder="Mật khẩu" required>
        <button class="btn btn-primary w-100">Đăng nhập</button>
    </form>
</div>
</body>
</html>
