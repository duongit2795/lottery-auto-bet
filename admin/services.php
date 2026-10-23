<?php
require __DIR__ . '/_init.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $action = $_POST['action'] ?? '';
    if ($action === 'sync') {
        $r = (new OtpProvider())->services();
        if (!$r['ok']) {
            flash('Lỗi đồng bộ: ' . h($r['message']), 'danger');
        } elseif (!$r['services']) {
            flash('API trả về 0 dịch vụ — kiểm tra ánh xạ JSON ở trang <a href="api.php">API nhà cung cấp</a>.', 'warning');
        } else {
            $new = $upd = 0;
            foreach ($r['services'] as $s) {
                $row = DB::row('SELECT * FROM services WHERE provider_id = ?', [$s['id']]);
                if ($row) {
                    $data = ['cost' => $s['price'], 'updated_at' => now()];
                    if (!$row['custom_price']) {
                        $data['price'] = Settings::sellPrice($s['price']);
                    }
                    DB::update('services', $data, 'id = ?', [$row['id']]);
                    $upd++;
                } else {
                    DB::insert('services', [
                        'provider_id' => $s['id'],
                        'name' => $s['name'],
                        'cost' => $s['price'],
                        'price' => Settings::sellPrice($s['price']),
                        'is_active' => isset($_POST['new_active']) ? 1 : 0,
                        'updated_at' => now(),
                    ]);
                    $new++;
                }
            }
            flash("Đồng bộ xong: thêm $new, cập nhật $upd dịch vụ.");
        }
    } elseif ($action === 'recalc') {
        foreach (DB::all('SELECT * FROM services WHERE custom_price = 0') as $s) {
            DB::update('services', ['price' => Settings::sellPrice((float)$s['cost'])], 'id = ?', [$s['id']]);
        }
        flash('Đã tính lại giá bán theo mức lãi hiện tại.');
    } elseif ($action === 'save') {
        foreach ($_POST['svc'] ?? [] as $id => $s) {
            $row = DB::row('SELECT * FROM services WHERE id = ?', [(int)$id]);
            if (!$row) {
                continue;
            }
            $price = (float)preg_replace('/[^\d.]/', '', (string)$s['price']);
            DB::update('services', [
                'name' => trim($s['name']) ?: $row['name'],
                'price' => $price,
                'custom_price' => $price != (float)$row['price'] || $row['custom_price'] ? 1 : 0,
                'is_active' => isset($s['active']) ? 1 : 0,
                'sort_order' => (int)$s['sort'],
                'sms_mode' => array_key_exists($s['sms_mode'] ?? '', OrderService::SMS_MODES) ? $s['sms_mode'] : 'none',
            ], 'id = ?', [(int)$id]);
        }
        flash('Đã lưu.');
    } elseif ($action === 'auto_price') {
        $s = DB::row('SELECT * FROM services WHERE id = ?', [(int)$_POST['id']]);
        if ($s) {
            DB::update('services', ['custom_price' => 0, 'price' => Settings::sellPrice((float)$s['cost'])], 'id = ?', [$s['id']]);
        }
        flash('Đã chuyển về giá tự động.');
    } elseif ($action === 'bulk') {
        $active = $_POST['bulk'] === 'on' ? 1 : 0;
        DB::query('UPDATE services SET is_active = ?', [$active]);
        flash($active ? 'Đã bật tất cả.' : 'Đã tắt tất cả.');
    } elseif ($action === 'add') {
        $cost = (float)($_POST['cost'] ?? 0);
        try {
            DB::insert('services', [
                'provider_id' => trim($_POST['provider_id']),
                'name' => trim($_POST['name']),
                'cost' => $cost,
                'price' => $_POST['price'] !== '' ? (float)$_POST['price'] : Settings::sellPrice($cost),
                'custom_price' => $_POST['price'] !== '' ? 1 : 0,
                'is_active' => 1,
                'updated_at' => now(),
            ]);
            flash('Đã thêm dịch vụ.');
        } catch (PDOException $e) {
            flash('ID dịch vụ đã tồn tại.', 'danger');
        }
    } elseif ($action === 'delete') {
        DB::query('DELETE FROM services WHERE id = ?', [(int)$_POST['id']]);
        flash('Đã xóa dịch vụ.');
    }
    redirect('services.php?' . http_build_query(array_filter(['q' => $_GET['q'] ?? '', 'p' => $_GET['p'] ?? ''])));
}

$q = trim($_GET['q'] ?? '');
$page = max(1, (int)($_GET['p'] ?? 1));
$per = 50;
$where = $q !== '' ? 'WHERE LOWER(name) LIKE ? OR provider_id = ?' : '';
$params = $q !== '' ? ['%' . mb_strtolower($q) . '%', $q] : [];
$total = (int)DB::value("SELECT COUNT(*) FROM services $where", $params);
$rows = DB::all("SELECT * FROM services $where ORDER BY sort_order DESC, name LIMIT $per OFFSET " . (($page - 1) * $per), $params);

layout_start('Dịch vụ & giá bán');
?>
<div class="card card-body mb-3">
    <div class="d-flex flex-wrap gap-2 align-items-center">
        <form method="post" class="d-flex gap-2 align-items-center">
            <?= csrf_field() ?>
            <button name="action" value="sync" class="btn btn-primary btn-sm">🔄 Đồng bộ từ API</button>
            <label class="small"><input type="checkbox" name="new_active" checked> Bật luôn dịch vụ mới</label>
        </form>
        <form method="post"><?= csrf_field() ?><button name="action" value="recalc" class="btn btn-outline-primary btn-sm">🧮 Tính lại giá (lãi <?= h(Settings::get('price_markup')) ?><?= Settings::get('price_mode') === 'percent' ? '%' : 'đ' ?>)</button></form>
        <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="bulk">
            <button name="bulk" value="on" class="btn btn-outline-success btn-sm">Bật tất cả</button>
            <button name="bulk" value="off" class="btn btn-outline-secondary btn-sm">Tắt tất cả</button>
        </form>
        <form class="ms-auto d-flex gap-1"><input name="q" value="<?= h($q) ?>" class="form-control form-control-sm" placeholder="Tìm dịch vụ"><button class="btn btn-sm btn-secondary">Tìm</button></form>
    </div>
    <div class="form-text">Cột <b>Gửi SMS</b>: bật cho các dịch vụ BossOTP hỗ trợ gửi SMS (cố định / tùy chọn) hoặc chuyển tiếp cuộc gọi — khách sẽ thấy nút tương ứng trên đơn.<br>Giá đã sửa tay (<span class="badge text-bg-info">tay</span>) sẽ không bị ghi đè khi đồng bộ/tính lại.</div>
</div>

<form method="post">
    <?= csrf_field() ?>
    <div class="card card-body mb-3"><div class="table-responsive">
    <table class="table table-sm table-hover">
        <tr><th>Bật</th><th>ID API</th><th>Tên hiển thị</th><th>Giá gốc</th><th>Giá bán</th><th>Lãi</th><th>Ưu tiên</th><th title="Dịch vụ hỗ trợ gửi SMS / chuyển tiếp cuộc gọi">Gửi SMS</th><th>Đã bán</th><th></th></tr>
        <?php foreach ($rows as $s): $sold = (int)DB::value("SELECT COUNT(*) FROM orders WHERE service_id = ? AND status = 'success'", [$s['id']]); ?>
            <tr>
                <td><input type="checkbox" class="form-check-input" name="svc[<?= $s['id'] ?>][active]" <?= $s['is_active'] ? 'checked' : '' ?>></td>
                <td><code><?= h($s['provider_id']) ?></code></td>
                <td><input name="svc[<?= $s['id'] ?>][name]" value="<?= h($s['name']) ?>" class="form-control form-control-sm"></td>
                <td><?= money($s['cost']) ?></td>
                <td style="width:140px"><div class="input-group input-group-sm"><input name="svc[<?= $s['id'] ?>][price]" value="<?= (int)$s['price'] ?>" class="form-control"><?php if ($s['custom_price']): ?><span class="input-group-text"><span class="badge text-bg-info">tay</span></span><?php endif; ?></div></td>
                <td class="<?= $s['price'] - $s['cost'] < 0 ? 'text-danger' : 'text-success' ?>"><?= money($s['price'] - $s['cost']) ?></td>
                <td style="width:80px"><input name="svc[<?= $s['id'] ?>][sort]" value="<?= (int)$s['sort_order'] ?>" class="form-control form-control-sm"></td>
                <td><select name="svc[<?= $s['id'] ?>][sms_mode]" class="form-select form-select-sm" style="min-width:150px">
                    <?php foreach (OrderService::SMS_MODES as $k => $l): ?><option value="<?= $k ?>" <?= $s['sms_mode'] === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?>
                </select></td>
                <td><?= $sold ?></td>
                <td class="text-nowrap">
                    <?php if ($s['custom_price']): ?><button form="f-auto" name="id" value="<?= $s['id'] ?>" class="btn btn-sm btn-link p-0">Giá tự động</button><?php endif; ?>
                    <button form="f-del" name="id" value="<?= $s['id'] ?>" class="btn btn-sm btn-link text-danger p-0 ms-2" onclick="return confirm('Xóa dịch vụ này?')">Xóa</button>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?><tr><td colspan="10" class="text-muted">Chưa có dịch vụ. Bấm "Đồng bộ từ API" hoặc thêm thủ công bên dưới.</td></tr><?php endif; ?>
    </table>
    </div>
    <?= paginate($total, $per, $page, ['q' => $q]) ?>
    <div><button name="action" value="save" class="btn btn-success btn-sm">💾 Lưu thay đổi</button></div>
    </div>
</form>
<form method="post" id="f-auto"><?= csrf_field() ?><input type="hidden" name="action" value="auto_price"></form>
<form method="post" id="f-del"><?= csrf_field() ?><input type="hidden" name="action" value="delete"></form>

<form method="post" class="card card-body">
    <?= csrf_field() ?>
    <h6>➕ Thêm dịch vụ thủ công</h6>
    <div class="row g-2">
        <div class="col-md-2"><input name="provider_id" class="form-control form-control-sm" placeholder="ID dịch vụ bên API" required></div>
        <div class="col-md-4"><input name="name" class="form-control form-control-sm" placeholder="Tên hiển thị" required></div>
        <div class="col-md-2"><input name="cost" class="form-control form-control-sm" placeholder="Giá gốc" required></div>
        <div class="col-md-2"><input name="price" class="form-control form-control-sm" placeholder="Giá bán (trống = tự tính)"></div>
        <div class="col-md-2"><button name="action" value="add" class="btn btn-sm btn-primary w-100">Thêm</button></div>
    </div>
</form>
<?php layout_end();
