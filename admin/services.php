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
            $new = $upd = $smsCount = $known = 0;
            $auto = Settings::get('sms_mode_auto') === '1';
            foreach ($r['services'] as $s) {
                $row = DB::row('SELECT * FROM services WHERE provider_id = ?', [$s['id']]);
                $f = $s['features'];
                $known += $f !== null ? 1 : 0;
                $smsCount += $f && ($f['sup_custom_sms'] || $f['sup_default_sms']) ? 1 : 0;
                $data = ['cost' => $s['price'], 'timeout_raw' => $s['timeout'], 'api_raw' => json_encode($s['raw'], JSON_UNESCAPED_UNICODE), 'updated_at' => now()];
                if ($auto && $f !== null && (!$row || !$row['sms_manual'])) {
                    $data += $f;
                }
                if ($row) {
                    if (!$row['custom_price']) {
                        $data['price'] = Settings::sellPrice($s['price']);
                    }
                    DB::update('services', $data, 'id = ?', [$row['id']]);
                    $upd++;
                } else {
                    DB::insert('services', $data + [
                        'provider_id' => $s['id'],
                        'name' => $s['name'],
                        'price' => Settings::sellPrice($s['price']),
                        'is_active' => isset($_POST['new_active']) ? 1 : 0,
                    ]);
                    $new++;
                }
            }
            $msg = "Đồng bộ xong: thêm $new, cập nhật $upd dịch vụ.";
            if ($known === 0) {
                flash($msg . '<br>⚠️ API không trả về các trường is_support_* — chọn tính năng thủ công ở từng dịch vụ.', 'warning');
            } else {
                flash($msg . "<br>📤 Theo API có <b>$smsCount</b> dịch vụ gửi được SMS" . ($auto ? ' — đã cập nhật tính năng (trừ dịch vụ bạn đã chỉnh tay).' : '.'));
            }
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
            $flags = [];
            foreach (array_keys(OrderService::FEATURES) as $col) {
                $flags[$col] = isset($s[$col]) ? 1 : 0;
            }
            $changed = false;
            foreach ($flags as $col => $v) {
                $changed = $changed || (int)$row[$col] !== $v;
            }
            DB::update('services', $flags + [
                'sms_manual' => $row['sms_manual'] || $changed ? 1 : 0,
                'name' => trim($s['name']) ?: $row['name'],
                'price' => $price,
                'custom_price' => $price != (float)$row['price'] || $row['custom_price'] ? 1 : 0,
                'is_active' => isset($s['active']) ? 1 : 0,
                'sort_order' => (int)$s['sort'],
                // Ô "Thời gian (phút)": để trống / 0 = theo API
                'timeout_custom' => max(0, (int)round((float)str_replace(',', '.', (string)($s['timeout_min'] ?? '')) * 60)),
            ], 'id = ?', [(int)$id]);
        }
        flash('Đã lưu.');
    } elseif ($action === 'auto_price') {
        $s = DB::row('SELECT * FROM services WHERE id = ?', [(int)$_POST['id']]);
        if ($s) {
            DB::update('services', ['custom_price' => 0, 'price' => Settings::sellPrice((float)$s['cost'])], 'id = ?', [$s['id']]);
        }
        flash('Đã chuyển về giá tự động.');
    } elseif ($action === 'sms_api') {
        // Bỏ chỉnh tay, dùng lại tính năng theo dữ liệu API đã lưu
        $list = !empty($_POST['id']) ? DB::all('SELECT * FROM services WHERE id = ?', [(int)$_POST['id']]) : DB::all('SELECT * FROM services');
        foreach ($list as $row) {
            $f = $row['api_raw'] ? OtpProvider::features(json_decode($row['api_raw'], true) ?: []) : null;
            DB::update('services', ['sms_manual' => 0] + ($f ?? []), 'id = ?', [$row['id']]);
        }
        flash('Đã áp dụng tính năng theo API.');
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
$conds = [];
$params = [];
if ($q !== '') {
    $conds[] = '(LOWER(name) LIKE ? OR provider_id = ?)';
    array_push($params, '%' . mb_strtolower($q) . '%', $q);
}
if (($_GET['sms'] ?? '') === '1') {
    $conds[] = '(sup_custom_sms = 1 OR sup_default_sms = 1 OR sup_forward_call = 1)';
}
$where = $conds ? 'WHERE ' . implode(' AND ', $conds) : '';
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
        <form method="post"><?= csrf_field() ?><button name="action" value="sms_api" class="btn btn-outline-warning btn-sm" onclick="return confirm('Đặt lại tính năng của tất cả dịch vụ theo API?')">📤 Tính năng theo API (tất cả)</button></form>
        <form class="ms-auto d-flex gap-1"><select name="sms" class="form-select form-select-sm" style="max-width:170px"><option value="">Tất cả dịch vụ</option><option value="1" <?= ($_GET['sms'] ?? '') === '1' ? 'selected' : '' ?>>Chỉ dịch vụ gửi SMS</option></select><input name="q" value="<?= h($q) ?>" class="form-control form-control-sm" placeholder="Tìm dịch vụ"><button class="btn btn-sm btn-secondary">Tìm</button></form>
    </div>
    <div class="form-text">Cột <b>Tính năng</b> lấy đúng theo API khi đồng bộ (<code>is_support_send_custom_sms</code>, <code>is_support_send_default_sms</code>, <code>is_support_forward_call</code>, <code>is_support_call</code>, <code>is_support_call_from_number</code>). Dịch vụ có "Gửi SMS tùy chọn" hoặc "Gửi SMS cố định" sẽ hiện trong menu <b>✉️ Gửi tin nhắn</b>; các nút gửi SMS / chuyển tiếp cuộc gọi hiện trên đơn thuê tương ứng. Nếu bạn chỉnh tay (<span class="badge text-bg-secondary">tay</span>), lần đồng bộ sau không ghi đè — bấm "Theo API" để bỏ. ⚠ = khác với API.<br>
        Cột <b>Thời gian</b>: thời gian giữ số / chờ OTP của từng dịch vụ, lấy từ trường <code>timeout</code> của API (đơn vị chỉnh ở trang <a href="api.php">API nhà cung cấp</a>). Nhập số phút để đặt riêng, để trống = theo API.<br>Giá đã sửa tay (<span class="badge text-bg-info">tay</span>) sẽ không bị ghi đè khi đồng bộ/tính lại.</div>
</div>

<form method="post">
    <?= csrf_field() ?>
    <div class="card card-body mb-3"><div class="table-responsive">
    <table class="table table-sm table-hover">
        <tr><th>Bật</th><th>ID API</th><th>Tên hiển thị</th><th>Giá gốc</th><th>Giá bán</th><th>Lãi</th><th>Ưu tiên</th><th>Tính năng (theo API)</th><th title="Thời gian giữ số / chờ OTP">Thời gian</th><th>Đã bán</th><th></th></tr>
        <?php foreach ($rows as $s): $sold = (int)DB::value("SELECT COUNT(*) FROM orders WHERE service_id = ? AND status = 'success'", [$s['id']]); ?>
            <tr>
                <td><input type="checkbox" class="form-check-input" name="svc[<?= $s['id'] ?>][active]" <?= $s['is_active'] ? 'checked' : '' ?>></td>
                <td><code><?= h($s['provider_id']) ?></code></td>
                <td><input name="svc[<?= $s['id'] ?>][name]" value="<?= h($s['name']) ?>" class="form-control form-control-sm"></td>
                <td><?= money($s['cost']) ?></td>
                <td style="width:140px"><div class="input-group input-group-sm"><input name="svc[<?= $s['id'] ?>][price]" value="<?= (int)$s['price'] ?>" class="form-control"><?php if ($s['custom_price']): ?><span class="input-group-text"><span class="badge text-bg-info">tay</span></span><?php endif; ?></div></td>
                <td class="<?= $s['price'] - $s['cost'] < 0 ? 'text-danger' : 'text-success' ?>"><?= money($s['price'] - $s['cost']) ?></td>
                <td style="width:80px"><input name="svc[<?= $s['id'] ?>][sort]" value="<?= (int)$s['sort_order'] ?>" class="form-control form-control-sm"></td>
                <td class="small" style="min-width:210px">
                    <?php $api = $s['api_raw'] ? OtpProvider::features(json_decode($s['api_raw'], true) ?: []) : null; ?>
                    <?php foreach (OrderService::FEATURES as $col => [$field, $label]): ?>
                        <label class="d-block text-nowrap" title="<?= h($field) ?><?= $api !== null ? ' — API: ' . ($api[$col] ? 'true' : 'false') : '' ?>">
                            <input type="checkbox" class="form-check-input" name="svc[<?= $s['id'] ?>][<?= $col ?>]" <?= $s[$col] ? 'checked' : '' ?>>
                            <?= $label ?><?php if ($api !== null && (int)$api[$col] !== (int)$s[$col]): ?> <span class="text-warning" title="Khác với API">⚠</span><?php endif; ?>
                        </label>
                    <?php endforeach; ?>
                    <?php if ($api === null): ?><i class="text-muted">API chưa có dữ liệu</i><?php endif; ?>
                    <?php if ($s['sms_manual']): ?><span class="badge text-bg-secondary">tay</span> <button form="f-smsapi" name="id" value="<?= $s['id'] ?>" class="btn btn-link btn-sm p-0">Theo API</button><?php endif; ?>
                </td>
                <td class="small" style="min-width:120px">
                    <?php $apiSec = $s['timeout_raw'] !== null ? OrderService::serviceTimeout(['timeout_raw' => $s['timeout_raw']]) : null; ?>
                    <div class="input-group input-group-sm"><input name="svc[<?= $s['id'] ?>][timeout_min]" value="<?= $s['timeout_custom'] ? round($s['timeout_custom'] / 60, 1) : '' ?>" class="form-control" placeholder="<?= $apiSec ? round($apiSec / 60, 1) : '' ?>" inputmode="decimal"><span class="input-group-text">phút</span></div>
                    <div class="text-muted">API: <?= $s['timeout_raw'] !== null ? h($s['timeout_raw']) . ' → ' . h(OrderService::duration($apiSec)) : '<i>không có</i>' ?><?= $s['timeout_custom'] ? ' · <span class="badge text-bg-secondary">tay</span>' : '' ?></div>
                </td>
                <td><?= $sold ?></td>
                <td class="text-nowrap">
                    <?php if ($s['custom_price']): ?><button form="f-auto" name="id" value="<?= $s['id'] ?>" class="btn btn-sm btn-link p-0">Giá tự động</button><?php endif; ?>
                    <?php if ($s['api_raw']): ?><a href="#" class="small ms-2" onclick="alert(this.dataset.raw);return false" data-raw="<?= h(json_encode(json_decode($s['api_raw'], true), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?>">Xem dữ liệu API</a><?php endif; ?>
                    <button form="f-del" name="id" value="<?= $s['id'] ?>" class="btn btn-sm btn-link text-danger p-0 ms-2" onclick="return confirm('Xóa dịch vụ này?')">Xóa</button>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?><tr><td colspan="11" class="text-muted">Chưa có dịch vụ. Bấm "Đồng bộ từ API" hoặc thêm thủ công bên dưới.</td></tr><?php endif; ?>
    </table>
    </div>
    <?= paginate($total, $per, $page, ['q' => $q, 'sms' => $_GET['sms'] ?? '']) ?>
    <div><button name="action" value="save" class="btn btn-success btn-sm">💾 Lưu thay đổi</button></div>
    </div>
</form>
<form method="post" id="f-auto"><?= csrf_field() ?><input type="hidden" name="action" value="auto_price"></form>
<form method="post" id="f-smsapi"><?= csrf_field() ?><input type="hidden" name="action" value="sms_api"></form>
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
