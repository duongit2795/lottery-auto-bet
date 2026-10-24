<?php
require __DIR__ . '/_init.php';
require_once APP_ROOT . '/src/Updater.php'; // nạp trực tiếp để trang vẫn chạy kể cả khi bootstrap.php là bản cũ
require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $action = $_POST['action'] ?? '';
    if ($action === 'config') {
        Settings::set('update_repo', trim((string)$_POST['update_repo']));
        Settings::set('update_branch', trim((string)$_POST['update_branch']));
        if (trim((string)($_POST['update_token'] ?? '')) !== '' || isset($_POST['clear_token'])) {
            Settings::set('update_token', isset($_POST['clear_token']) ? '' : trim((string)$_POST['update_token']));
        }
        flash('Đã lưu cấu hình GitHub.');
        redirect('update.php');
    }

    // Các thao tác thay đổi mã nguồn đều phải nhập lại mật khẩu admin
    $admin = DB::row('SELECT * FROM admins WHERE id = ?', [$_SESSION['admin_id']]);
    if (!$admin || !password_verify((string)($_POST['password'] ?? ''), $admin['password_hash'])) {
        flash('Sai mật khẩu admin.', 'danger');
        redirect('update.php');
    }
    ignore_user_abort(true);
    set_time_limit(300);

    if ($action === 'github') {
        $r = Updater::updateFromGithub();
    } elseif ($action === 'upload') {
        $f = $_FILES['zip'] ?? null;
        if (!$f || $f['error'] !== UPLOAD_ERR_OK) {
            $r = ['ok' => false, 'message' => 'Chưa chọn file hoặc upload lỗi (mã ' . ($f['error'] ?? '-') . '). File quá lớn? Giới hạn upload: ' . ini_get('upload_max_filesize')];
        } else {
            $r = Updater::apply($f['tmp_name'], 'Upload ' . basename((string)$f['name']));
        }
    } elseif ($action === 'restore') {
        $r = Updater::restore((string)$_POST['backup']);
    } elseif ($action === 'backup') {
        $r = ['ok' => true, 'message' => 'Đã tạo bản sao lưu ' . basename(Updater::backup())];
    } else {
        $r = ['ok' => false, 'message' => 'Thao tác không hợp lệ'];
    }
    flash(h($r['message']), $r['ok'] ? 'success' : 'danger');
    redirect('update.php');
}

$problem = Updater::available();
$latest = isset($_GET['check']) || Settings::get('app_version') ? Updater::latest() : null;
$current = (string)Settings::get('app_version');
$upToDate = $latest && !isset($latest['error']) && $current !== '' && $latest['sha'] === $current;

layout_start('Cập nhật mã nguồn');
?>
<style>.btn-xl{padding:.9rem 1rem;font-size:1.1rem;width:100%}</style>
<?php if ($problem): ?><div class="alert alert-danger">⚠️ <?= h($problem) ?></div><?php endif; ?>

<div class="card card-body mb-3">
    <div class="small text-muted">Phiên bản đang chạy</div>
    <div class="fw-semibold"><?= h(Settings::get('app_version_label') ?: 'Chưa rõ (chưa cập nhật lần nào qua trang này)') ?></div>
    <?php if (Settings::get('app_updated_at')): ?><div class="small text-muted">Cập nhật lúc <?= h(Settings::get('app_updated_at')) ?></div><?php endif; ?>
</div>

<div class="card card-body mb-3">
    <h6>☁️ Cập nhật từ GitHub <span class="small text-muted">(khuyên dùng)</span></h6>
    <?php if ($latest && isset($latest['error'])): ?>
        <div class="alert alert-warning small mb-2"><?= h($latest['error']) ?></div>
    <?php elseif ($latest): ?>
        <div class="mb-2">Bản mới nhất: <b><?= h($latest['short']) ?></b> — <?= h($latest['message']) ?> <span class="small text-muted">(<?= h($latest['date']) ?>)</span></div>
        <?php if ($upToDate): ?><div class="alert alert-success py-2 small">✅ Bạn đang dùng bản mới nhất.</div>
        <?php else: ?><div class="alert alert-info py-2 small">🆕 Có bản mới — bấm nút bên dưới để cập nhật.</div><?php endif; ?>
    <?php else: ?>
        <a href="?check=1" class="btn btn-outline-primary mb-2">🔍 Kiểm tra bản mới</a>
    <?php endif; ?>
    <form method="post" onsubmit="this.querySelector('button').disabled=true;this.querySelector('button').innerText='⏳ Đang cập nhật, đừng tắt trang...'">
        <?= csrf_field() ?><input type="hidden" name="action" value="github">
        <input type="password" name="password" class="form-control mb-2" placeholder="Mật khẩu admin để xác nhận" required autocomplete="current-password">
        <button class="btn btn-primary btn-xl" <?= $problem ? 'disabled' : '' ?>>🔄 Cập nhật từ GitHub</button>
    </form>
    <div class="form-text">Tự sao lưu bản hiện tại trước khi cập nhật. Nếu sau khi cập nhật web bị lỗi sẽ tự quay về bản cũ. Không đụng tới config.php và dữ liệu (thư mục data/).</div>
</div>

<div class="card card-body mb-3">
    <h6>📦 Cập nhật bằng file zip</h6>
    <form method="post" enctype="multipart/form-data" onsubmit="this.querySelector('button').disabled=true;this.querySelector('button').innerText='⏳ Đang cập nhật...'">
        <?= csrf_field() ?><input type="hidden" name="action" value="upload">
        <input type="file" name="zip" accept=".zip,application/zip" class="form-control mb-2" required>
        <input type="password" name="password" class="form-control mb-2" placeholder="Mật khẩu admin để xác nhận" required autocomplete="current-password">
        <button class="btn btn-outline-primary btn-xl" <?= $problem ? 'disabled' : '' ?>>⬆️ Tải lên &amp; cập nhật</button>
    </form>
    <div class="form-text">Dùng file zip Claude gửi hoặc tải từ GitHub (Code → Download ZIP). Giới hạn upload của hosting: <?= h(ini_get('upload_max_filesize')) ?>.</div>
</div>

<div class="card card-body mb-3">
    <h6>🗂 Bản sao lưu (giữ 5 bản gần nhất)</h6>
    <?php $backups = Updater::backups(); ?>
    <?php if (!$backups): ?><div class="small text-muted mb-2">Chưa có bản sao lưu.</div><?php endif; ?>
    <?php foreach ($backups as $b): ?>
        <form method="post" class="border rounded p-2 mb-2" onsubmit="return confirm('Khôi phục về bản <?= h($b['time']) ?>?')">
            <?= csrf_field() ?><input type="hidden" name="action" value="restore"><input type="hidden" name="backup" value="<?= h($b['name']) ?>">
            <div class="small"><b><?= h($b['time']) ?></b> · <?= round($b['size'] / 1024) ?> KB<?= $b['label'] ? ' · ' . h($b['label']) : '' ?></div>
            <div class="input-group input-group-sm mt-1">
                <input type="password" name="password" class="form-control" placeholder="Mật khẩu admin" required>
                <button class="btn btn-outline-warning">↩️ Khôi phục</button>
            </div>
        </form>
    <?php endforeach; ?>
    <form method="post" class="input-group input-group-sm">
        <?= csrf_field() ?><input type="hidden" name="action" value="backup">
        <input type="password" name="password" class="form-control" placeholder="Mật khẩu admin" required>
        <button class="btn btn-outline-secondary" <?= $problem ? 'disabled' : '' ?>>💾 Sao lưu ngay</button>
    </form>
</div>

<?php if ($history = Updater::history()): ?>
<div class="card card-body mb-3">
    <h6>📜 Lịch sử cập nhật</h6>
    <?php foreach ($history as $h): ?><div class="small border-bottom py-1"><?= h($h['time']) ?> — <?= h($h['msg']) ?><?= $h['by'] ? ' <span class="text-muted">(' . h($h['by']) . ')</span>' : '' ?></div><?php endforeach; ?>
</div>
<?php endif; ?>

<form method="post" class="card card-body mb-3">
    <?= csrf_field() ?><input type="hidden" name="action" value="config">
    <h6>⚙️ Cấu hình GitHub</h6>
    <label class="form-label small mb-0">Repo (chủ/tên-repo)</label>
    <input name="update_repo" value="<?= h(Settings::get('update_repo')) ?>" class="form-control form-control-sm mb-2">
    <label class="form-label small mb-0">Nhánh</label>
    <input name="update_branch" value="<?= h(Settings::get('update_branch')) ?>" class="form-control form-control-sm mb-2">
    <label class="form-label small mb-0">GitHub token <?= Settings::get('update_token') ? '<span class="badge text-bg-success">đã lưu</span>' : '' ?></label>
    <input name="update_token" type="password" class="form-control form-control-sm mb-1" placeholder="<?= Settings::get('update_token') ? 'Để trống = giữ token cũ' : 'Chỉ cần khi repo để Private' ?>" autocomplete="off">
    <?php if (Settings::get('update_token')): ?><label class="small mb-2"><input type="checkbox" name="clear_token"> Xóa token</label><?php endif; ?>
    <div class="form-text mb-2">Repo Private: tạo token tại GitHub → Settings → Developer settings → Fine-grained tokens, chọn repo này, quyền <b>Contents: Read-only</b>.</div>
    <button class="btn btn-sm btn-success">Lưu</button>
</form>
<?php layout_end();
