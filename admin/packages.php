<?php
require __DIR__ . '/_init.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $action = $_POST['action'] ?? '';
    $data = fn() => [
        'name' => trim($_POST['name'] ?? ''),
        'credits' => max(1, (int)($_POST['credits'] ?? 0)),
        'price' => (float)preg_replace('/[^\d.]/', '', (string)($_POST['price'] ?? '0')),
        'days_valid' => max(0, (int)($_POST['days_valid'] ?? 0)),
        'service_id' => (int)($_POST['service_id'] ?? 0) ?: null,
        'description' => mb_substr(trim($_POST['description'] ?? ''), 0, 250),
        'sort_order' => (int)($_POST['sort_order'] ?? 0),
        'is_active' => isset($_POST['is_active']) ? 1 : 0,
    ];
    if ($action === 'add') {
        DB::insert('sms_packages', $data() + ['created_at' => now()]);
        flash('Đã thêm gói.');
    } elseif ($action === 'update') {
        DB::update('sms_packages', $data(), 'id = ?', [(int)$_POST['id']]);
        flash('Đã lưu gói.');
    } elseif ($action === 'delete') {
        DB::query('DELETE FROM sms_packages WHERE id = ?', [(int)$_POST['id']]);
        flash('Đã xóa gói (gói khách đã mua vẫn giữ nguyên).');
    } elseif ($action === 'grant') {
        $u = DB::row('SELECT * FROM users WHERE CAST(tg_id AS CHAR) = ?', [trim($_POST['tg_id'] ?? '')]);
        $p = DB::row('SELECT * FROM sms_packages WHERE id = ?', [(int)$_POST['package_id']]);
        if (!$u || !$p) {
            flash('Không tìm thấy user hoặc gói.', 'danger');
        } else {
            SmsService::grantPackage((int)$u['id'], $p, 0);
            (new Telegram())->send($u['tg_id'], '🎁 Bạn được tặng <b>' . tg_escape($p['name']) . '</b>: +' . (int)$p['credits'] . ' lượt gửi tin.');
            flash('Đã tặng gói cho ' . h($u['tg_id']) . '.');
        }
    } elseif ($action === 'adjust') {
        $up = DB::row('SELECT * FROM user_packages WHERE id = ?', [(int)$_POST['id']]);
        if ($up) {
            DB::update('user_packages', [
                'credits_left' => max(0, (int)$_POST['credits_left']),
                'expires_at' => trim($_POST['expires_at'] ?? '') !== '' ? date('Y-m-d H:i:s', strtotime($_POST['expires_at'])) : null,
            ], 'id = ?', [$up['id']]);
            flash('Đã cập nhật gói của khách.');
        }
    }
    redirect('packages.php');
}

$services = SmsService::services();
$svcName = array_column($services, 'name', 'id');
$packages = DB::all('SELECT p.*, (SELECT COUNT(*) FROM user_packages u WHERE u.package_id = p.id) sold FROM sms_packages p ORDER BY p.sort_order DESC, p.price');
$owned = DB::all('SELECT up.*, u.tg_id, u.username FROM user_packages up LEFT JOIN users u ON u.id = up.user_id ORDER BY up.id DESC LIMIT 100');

$svcSelect = function ($selected) use ($services) {
    $o = '<option value="0">Tất cả dịch vụ gửi tin</option>';
    foreach ($services as $s) {
        $o .= '<option value="' . $s['id'] . '"' . ((int)$selected === (int)$s['id'] ? ' selected' : '') . '>' . h($s['name']) . '</option>';
    }
    return $o;
};

layout_start('Gói tin nhắn');
?>
<?php if (!$services): ?>
    <div class="alert alert-warning">Chưa có dịch vụ nào bật gửi tin. Vào <a href="services.php">Dịch vụ &amp; giá</a>, đặt cột <b>Gửi SMS</b> = "Gửi SMS tùy chọn" hoặc "Gửi SMS cố định" cho dịch vụ hỗ trợ.</div>
<?php endif; ?>

<div class="card card-body mb-4">
    <h6>Danh sách gói</h6>
    <div class="table-responsive"><table class="table table-sm align-middle">
        <tr><th>Bán</th><th>Tên gói</th><th>Số tin</th><th>Giá</th><th>Hạn (ngày, 0 = vĩnh viễn)</th><th>Dịch vụ</th><th>Mô tả</th><th>Ưu tiên</th><th>Đã bán</th><th></th></tr>
        <?php foreach ($packages as $p): ?>
            <tr>
                <td><input form="pk<?= $p['id'] ?>" type="checkbox" class="form-check-input" name="is_active" <?= $p['is_active'] ? 'checked' : '' ?>></td>
                <td><input form="pk<?= $p['id'] ?>" name="name" value="<?= h($p['name']) ?>" class="form-control form-control-sm" required></td>
                <td style="width:90px"><input form="pk<?= $p['id'] ?>" name="credits" value="<?= (int)$p['credits'] ?>" class="form-control form-control-sm"></td>
                <td style="width:120px"><input form="pk<?= $p['id'] ?>" name="price" value="<?= (int)$p['price'] ?>" class="form-control form-control-sm"></td>
                <td style="width:90px"><input form="pk<?= $p['id'] ?>" name="days_valid" value="<?= (int)$p['days_valid'] ?>" class="form-control form-control-sm"></td>
                <td><select form="pk<?= $p['id'] ?>" name="service_id" class="form-select form-select-sm"><?= $svcSelect($p['service_id']) ?></select></td>
                <td><input form="pk<?= $p['id'] ?>" name="description" value="<?= h($p['description']) ?>" class="form-control form-control-sm"></td>
                <td style="width:70px"><input form="pk<?= $p['id'] ?>" name="sort_order" value="<?= (int)$p['sort_order'] ?>" class="form-control form-control-sm"></td>
                <td><?= (int)$p['sold'] ?></td>
                <td class="text-nowrap">
                    <form method="post" id="pk<?= $p['id'] ?>"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $p['id'] ?>"></form>
                    <button form="pk<?= $p['id'] ?>" name="action" value="update" class="btn btn-sm btn-success">Lưu</button>
                    <button form="pk<?= $p['id'] ?>" name="action" value="delete" class="btn btn-sm btn-outline-danger" onclick="return confirm('Xóa gói?')">Xóa</button>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$packages): ?><tr><td colspan="10" class="text-muted">Chưa có gói nào.</td></tr><?php endif; ?>
    </table></div>
</div>

<div class="row g-3 mb-4">
    <div class="col-lg-7">
        <form method="post" class="card card-body">
            <?= csrf_field() ?><input type="hidden" name="action" value="add">
            <h6>➕ Thêm gói mới</h6>
            <div class="row g-2">
                <div class="col-md-6"><input name="name" class="form-control form-control-sm" placeholder="Tên gói (vd: Gói 100 tin)" required></div>
                <div class="col-md-3"><input name="credits" class="form-control form-control-sm" placeholder="Số tin" required></div>
                <div class="col-md-3"><input name="price" class="form-control form-control-sm" placeholder="Giá" required></div>
                <div class="col-md-3"><input name="days_valid" class="form-control form-control-sm" placeholder="Hạn (ngày)" value="30"></div>
                <div class="col-md-5"><select name="service_id" class="form-select form-select-sm"><?= $svcSelect(0) ?></select></div>
                <div class="col-md-4"><input name="sort_order" class="form-control form-control-sm" placeholder="Ưu tiên" value="0"></div>
                <div class="col-12"><input name="description" class="form-control form-control-sm" placeholder="Mô tả ngắn (hiện trong bot)"></div>
                <div class="col-12"><label class="small"><input type="checkbox" name="is_active" checked> Mở bán ngay</label></div>
            </div>
            <button class="btn btn-sm btn-primary mt-2">Thêm gói</button>
        </form>
    </div>
    <div class="col-lg-5">
        <form method="post" class="card card-body">
            <?= csrf_field() ?><input type="hidden" name="action" value="grant">
            <h6>🎁 Tặng gói cho khách</h6>
            <input name="tg_id" class="form-control form-control-sm mb-2" placeholder="Telegram ID" required>
            <select name="package_id" class="form-select form-select-sm mb-2" required>
                <?php foreach ($packages as $p): ?><option value="<?= $p['id'] ?>"><?= h($p['name']) ?></option><?php endforeach; ?>
            </select>
            <button class="btn btn-sm btn-outline-primary">Tặng (không trừ tiền)</button>
        </form>
    </div>
</div>

<div class="card card-body">
    <h6>Gói khách đã mua (100 gần nhất)</h6>
    <div class="table-responsive"><table class="table table-sm align-middle">
        <tr><th>#</th><th>User</th><th>Gói</th><th>Dịch vụ</th><th>Lượt còn / tổng</th><th>Giá</th><th>Hết hạn</th><th>Mua lúc</th><th></th></tr>
        <?php foreach ($owned as $o): $expired = $o['expires_at'] && strtotime($o['expires_at']) < time(); ?>
            <tr class="<?= $expired || !$o['credits_left'] ? 'text-muted' : '' ?>">                <td><?= $o['id'] ?></td>
                <td><a href="users.php?id=<?= $o['user_id'] ?>"><?= h($o['username'] ? '@' . $o['username'] : $o['tg_id']) ?></a></td>
                <td><?= h($o['name']) ?></td>
                <td class="small"><?= h($svcName[$o['service_id']] ?? 'Tất cả') ?></td>
                <td style="width:140px"><div class="input-group input-group-sm"><input form="up<?= $o['id'] ?>" name="credits_left" value="<?= (int)$o['credits_left'] ?>" class="form-control"><span class="input-group-text">/<?= (int)$o['credits_total'] ?></span></div></td>
                <td><?= money($o['price']) ?></td>
                <td style="width:190px"><input form="up<?= $o['id'] ?>" type="datetime-local" name="expires_at" value="<?= $o['expires_at'] ? date('Y-m-d\TH:i', strtotime($o['expires_at'])) : '' ?>" class="form-control form-control-sm <?= $expired ? 'text-danger' : '' ?>"></td>
                <td class="small"><?= h($o['created_at']) ?></td>
                <td><form method="post" id="up<?= $o['id'] ?>"><?= csrf_field() ?><input type="hidden" name="action" value="adjust"><input type="hidden" name="id" value="<?= $o['id'] ?>"></form><button form="up<?= $o['id'] ?>" class="btn btn-sm btn-outline-success">Lưu</button></td>
            </tr>
        <?php endforeach; ?>
    </table></div>
</div>
<?php layout_end();
