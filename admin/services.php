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
    } elseif ($action === 'save_one') {
        $id = (int)($_POST['id'] ?? 0);
        $row = DB::row('SELECT * FROM services WHERE id = ?', [$id]);
        if ($row) {
            $price = (float)preg_replace('/[^\d.]/', '', (string)($_POST['price'] ?? $row['price']));
            $flags = [];
            foreach (array_keys(OrderService::FEATURES) as $col) {
                $flags[$col] = isset($_POST[$col]) ? 1 : 0;
            }
            $changed = false;
            foreach ($flags as $col => $v) {
                $changed = $changed || (int)$row[$col] !== $v;
            }
            DB::update('services', $flags + [
                'sms_manual' => $row['sms_manual'] || $changed ? 1 : 0,
                'name' => trim((string)($_POST['name'] ?? '')) ?: $row['name'],
                'price' => $price,
                'custom_price' => $price != (float)$row['price'] || $row['custom_price'] ? 1 : 0,
                'is_active' => isset($_POST['active']) ? 1 : 0,
                'sort_order' => (int)($_POST['sort'] ?? 0),
                // Thời gian (phút): để trống / 0 = theo API
                'timeout_custom' => max(0, (int)round((float)str_replace(',', '.', (string)($_POST['timeout_min'] ?? '')) * 60)),
            ], 'id = ?', [$id]);
            flash('Đã lưu <b>' . h($_POST['name'] ?? $row['name']) . '</b>.');
        }
    } elseif ($action === 'toggle') {
        $row = DB::row('SELECT * FROM services WHERE id = ?', [(int)$_POST['id']]);
        if ($row) {
            DB::update('services', ['is_active' => $row['is_active'] ? 0 : 1], 'id = ?', [$row['id']]);
            flash(($row['is_active'] ? '⏸ Đã tắt ' : '▶️ Đã bật ') . '<b>' . h($row['name']) . '</b>.');
        }
    } elseif ($action === 'timeout_api') {
        DB::update('services', ['timeout_custom' => 0], 'id = ?', [(int)$_POST['id']]);
        flash('Đã dùng thời gian theo API.');
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
    $anchor = !empty($_POST['id']) && in_array($action, ['save_one', 'toggle', 'auto_price', 'sms_api', 'timeout_api'], true) ? '#svc-' . (int)$_POST['id'] : '';
    redirect('services.php?' . http_build_query(array_filter(['q' => $_GET['q'] ?? '', 'f' => $_GET['f'] ?? '', 'sort' => $_GET['sort'] ?? '', 'p' => $_GET['p'] ?? ''])) . $anchor);
}

$q = trim($_GET['q'] ?? '');
$filter = $_GET['f'] ?? '';
$sort = $_GET['sort'] ?? 'priority';
$page = max(1, (int)($_GET['p'] ?? 1));
$per = 30;

$smsCond = '(s.sup_custom_sms = 1 OR s.sup_default_sms = 1 OR s.sup_forward_call = 1)';
$filters = [
    '' => ['Tất cả', '1 = 1'],
    'on' => ['Đang bán', 's.is_active = 1'],
    'off' => ['Đã tắt', 's.is_active = 0'],
    'sms' => ['Gửi SMS', $smsCond],
    'loss' => ['Đang lỗ', 's.price < s.cost'],
    'manual' => ['Chỉnh tay', '(s.custom_price = 1 OR s.timeout_custom > 0 OR s.sms_manual = 1)'],
];
$sorts = ['priority' => 'Ưu tiên', 'name' => 'Tên A→Z', 'sold' => 'Bán chạy', 'profit' => 'Lãi cao', 'price' => 'Giá thấp'];
$orderBy = [
    'priority' => 's.sort_order DESC, s.name',
    'name' => 's.name',
    'sold' => 'sold DESC, s.name',
    'profit' => '(s.price - s.cost) DESC, s.name',
    'price' => 's.price, s.name',
][$sort] ?? 's.sort_order DESC, s.name';

$conds = [$filters[$filter][1] ?? '1 = 1'];
$params = [];
if ($q !== '') {
    $conds[] = '(LOWER(s.name) LIKE ? OR s.provider_id = ?)';
    array_push($params, '%' . mb_strtolower($q) . '%', $q);
}
$where = 'WHERE ' . implode(' AND ', $conds);
$soldJoin = "LEFT JOIN (SELECT service_id, COUNT(*) c FROM orders WHERE status = 'success' GROUP BY service_id) x ON x.service_id = s.id";
$total = (int)DB::value("SELECT COUNT(*) FROM services s $where", $params);
$rows = DB::all("SELECT s.*, COALESCE(x.c, 0) AS sold FROM services s $soldJoin $where ORDER BY $orderBy LIMIT $per OFFSET " . (($page - 1) * $per), $params);

$stats = [
    ['Tổng dịch vụ', (int)DB::value('SELECT COUNT(*) FROM services'), 'primary', ''],
    ['Đang bán', (int)DB::value('SELECT COUNT(*) FROM services WHERE is_active = 1'), 'success', 'on'],
    ['Gửi SMS', (int)DB::value('SELECT COUNT(*) FROM services s WHERE ' . $smsCond), 'info', 'sms'],
    ['Đang lỗ', (int)DB::value('SELECT COUNT(*) FROM services WHERE price < cost'), 'danger', 'loss'],
];
$featureIcons = ['sup_custom_sms' => '✉️', 'sup_default_sms' => '📤', 'sup_forward_call' => '📞', 'sup_call' => '☎️', 'sup_call_from_number' => '📲'];
$qs = fn(array $over) => '?' . http_build_query(array_filter(array_merge(['q' => $q, 'f' => $filter, 'sort' => $sort === 'priority' ? '' : $sort], $over), fn($v) => $v !== '' && $v !== null));
$markup = h(Settings::get('price_markup')) . (Settings::get('price_mode') === 'percent' ? '%' : 'đ');

layout_start('Dịch vụ & giá bán');
?>
<style>
.svc { border-bottom: 1px solid #eef0f3; padding: 12px 4px; }
.svc:last-child { border-bottom: 0; }
.svc.off { opacity: .55; }
.svc .name { font-weight: 600; font-size: 15px; }
.svc .price { font-weight: 700; font-size: 16px; white-space: nowrap; }
.svc .meta { font-size: 12px; color: #6b7280; }
.feat-icon { font-size: 14px; margin-right: 2px; }
.chip { display: inline-block; padding: 5px 12px; border-radius: 20px; border: 1px solid #d1d5db; color: #374151; text-decoration: none; font-size: 13px; margin: 0 4px 6px 0; background: #fff; }
.chip.active { background: #2563eb; border-color: #2563eb; color: #fff; }
.stat-link { text-decoration: none; color: inherit; }
.edit-box { background: #f8fafc; border-radius: 10px; padding: 14px; margin-top: 10px; }
.form-switch .form-check-input { width: 2.6em; height: 1.4em; cursor: pointer; }
.form-switch .form-check-label { margin-left: .5em; padding-top: .1em; }
.stat-card { border: 0; border-left: 4px solid; }
@media (max-width: 576px) { .svc .edit-btn span { display: none; } .svc .price { font-size: 15px; } }
</style>

<div class="row g-2 mb-3">
    <?php foreach ($stats as [$label, $val, $color, $f]): ?>
        <div class="col-6 col-md-3"><a class="stat-link" href="<?= h($qs(['f' => $f, 'p' => ''])) ?>"><div class="card card-body py-2 stat-card border-<?= $color ?><?= $filter === $f ? ' shadow-sm' : '' ?>">
            <div class="small text-muted"><?= $label ?></div><div class="fs-4 fw-bold"><?= $val ?></div>
        </div></a></div>
    <?php endforeach; ?>
</div>

<div class="card card-body mb-3">
    <div class="d-flex flex-wrap gap-2 align-items-center mb-2">
        <form method="post" class="d-flex gap-2 align-items-center" onsubmit="this.querySelector('button').disabled=true;this.querySelector('button').innerText='⏳ Đang đồng bộ...'">
            <?= csrf_field() ?><input type="hidden" name="action" value="sync">
            <button class="btn btn-primary">🔄 Đồng bộ từ API</button>
            <div class="form-check form-switch small mb-0"><input class="form-check-input" type="checkbox" name="new_active" id="newActive" checked><label class="form-check-label" for="newActive">Bật luôn dịch vụ mới</label></div>
        </form>
        <div class="dropdown ms-auto">
            <button class="btn btn-outline-secondary dropdown-toggle" data-bs-toggle="dropdown">⚙️ Thao tác khác</button>
            <ul class="dropdown-menu dropdown-menu-end">
                <li><form method="post"><?= csrf_field() ?><button name="action" value="recalc" class="dropdown-item">🧮 Tính lại giá theo lãi <?= $markup ?></button></form></li>
                <li><form method="post" onsubmit="return confirm('Đặt lại tính năng của tất cả dịch vụ theo API?')"><?= csrf_field() ?><button name="action" value="sms_api" class="dropdown-item">📤 Tính năng theo API (tất cả)</button></form></li>
                <li><hr class="dropdown-divider"></li>
                <li><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="bulk"><button name="bulk" value="on" class="dropdown-item">▶️ Bật tất cả</button></form></li>
                <li><form method="post" onsubmit="return confirm('Tắt tất cả dịch vụ?')"><?= csrf_field() ?><input type="hidden" name="action" value="bulk"><button name="bulk" value="off" class="dropdown-item">⏸ Tắt tất cả</button></form></li>
                <li><hr class="dropdown-divider"></li>
                <li><a class="dropdown-item" href="#addBox" data-bs-toggle="collapse">➕ Thêm dịch vụ thủ công</a></li>
            </ul>
        </div>
    </div>

    <div class="collapse" id="addBox">
        <form method="post" class="edit-box mb-2">
            <?= csrf_field() ?><input type="hidden" name="action" value="add">
            <div class="fw-semibold mb-2">➕ Thêm dịch vụ thủ công</div>
            <div class="row g-2">
                <div class="col-6 col-md-3"><input name="provider_id" class="form-control" placeholder="ID dịch vụ bên API" required></div>
                <div class="col-6 col-md-3"><input name="name" class="form-control" placeholder="Tên hiển thị" required></div>
                <div class="col-6 col-md-2"><input name="cost" class="form-control" placeholder="Giá gốc" inputmode="numeric" required></div>
                <div class="col-6 col-md-2"><input name="price" class="form-control" placeholder="Giá bán (trống = tự tính)" inputmode="numeric"></div>
                <div class="col-12 col-md-2"><button class="btn btn-primary w-100">Thêm</button></div>
            </div>
        </form>
    </div>

    <form class="d-flex gap-2 mb-2">
        <input type="hidden" name="f" value="<?= h($filter) ?>">
        <input name="q" value="<?= h($q) ?>" class="form-control" placeholder="🔍 Tìm tên hoặc ID dịch vụ">
        <select name="sort" class="form-select" style="max-width:150px" onchange="this.form.submit()">
            <?php foreach ($sorts as $k => $l): ?><option value="<?= $k ?>" <?= $sort === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?>
        </select>
        <button class="btn btn-secondary">Tìm</button>
    </form>
    <div>
        <?php foreach ($filters as $k => [$l]): ?><a class="chip <?= $filter === $k ? 'active' : '' ?>" href="<?= h($qs(['f' => $k, 'p' => ''])) ?>"><?= $l ?></a><?php endforeach; ?>
    </div>
</div>

<div class="card card-body py-1 mb-3">
    <?php if (!$rows): ?>
        <div class="text-center text-muted py-4"><?= $total === 0 && $q === '' && $filter === '' ? 'Chưa có dịch vụ. Bấm <b>🔄 Đồng bộ từ API</b> để lấy danh sách.' : 'Không có dịch vụ phù hợp.' ?></div>
    <?php endif; ?>
    <?php foreach ($rows as $s):
        $profit = (float)$s['price'] - (float)$s['cost'];
        $timeout = OrderService::serviceTimeout($s);
        $apiSec = $s['timeout_raw'] !== null ? OrderService::serviceTimeout(['timeout_raw' => $s['timeout_raw']]) : null;
        $api = $s['api_raw'] ? OtpProvider::features(json_decode($s['api_raw'], true) ?: []) : null;
        $auto = Settings::sellPrice((float)$s['cost']);
    ?>
        <div class="svc <?= $s['is_active'] ? '' : 'off' ?>" id="svc-<?= $s['id'] ?>">
            <div class="d-flex align-items-center gap-3">
                <form method="post" class="form-check form-switch m-0" title="<?= $s['is_active'] ? 'Đang bán — bấm để tắt' : 'Đã tắt — bấm để bật' ?>">
                    <?= csrf_field() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= $s['id'] ?>">
                    <input class="form-check-input" type="checkbox" <?= $s['is_active'] ? 'checked' : '' ?> onchange="this.form.submit()">
                </form>
                <div class="flex-grow-1" style="min-width:0">
                    <div class="name text-truncate"><?= h($s['name']) ?></div>
                    <div class="meta">
                        <code><?= h($s['provider_id']) ?></code> · ⏱ <?= h(OrderService::duration($timeout)) ?><?= $s['timeout_custom'] ? '*' : '' ?> · đã bán <?= (int)$s['sold'] ?>
                        <?php foreach ($featureIcons as $col => $icon): if (!empty($s[$col])): ?><span class="feat-icon" title="<?= h(OrderService::FEATURES[$col][1]) ?>"><?= $icon ?></span><?php endif; endforeach; ?>
                    </div>
                </div>
                <div class="text-end">
                    <div class="price"><?= money($s['price']) ?><?= $s['custom_price'] ? ' <span class="badge text-bg-info" title="Giá chỉnh tay">tay</span>' : '' ?></div>
                    <div class="meta">gốc <?= money($s['cost']) ?> · <span class="<?= $profit < 0 ? 'text-danger fw-bold' : 'text-success' ?>"><?= ($profit >= 0 ? '+' : '') . money($profit) ?></span></div>
                </div>
                <button class="btn btn-sm btn-outline-primary edit-btn" data-bs-toggle="collapse" data-bs-target="#edit-<?= $s['id'] ?>">✏️<span> Sửa</span></button>
            </div>

            <div class="collapse" id="edit-<?= $s['id'] ?>">
                <form method="post" class="edit-box">
                    <?= csrf_field() ?><input type="hidden" name="action" value="save_one"><input type="hidden" name="id" value="<?= $s['id'] ?>">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold mb-1">Tên hiển thị</label>
                            <input name="name" value="<?= h($s['name']) ?>" class="form-control">
                        </div>
                        <div class="col-6 col-md-3">
                            <label class="form-label small fw-semibold mb-1">Giá bán</label>
                            <div class="input-group"><input name="price" value="<?= (int)$s['price'] ?>" class="form-control" inputmode="numeric"><span class="input-group-text">đ</span></div>
                            <div class="form-text">Tự động: <?= money($auto) ?> (lãi <?= $markup ?>)</div>
                        </div>
                        <div class="col-6 col-md-3">
                            <label class="form-label small fw-semibold mb-1">Thời gian</label>
                            <div class="input-group"><input name="timeout_min" value="<?= $s['timeout_custom'] ? round($s['timeout_custom'] / 60, 1) : '' ?>" placeholder="<?= $apiSec ? round($apiSec / 60, 1) : '' ?>" class="form-control" inputmode="decimal"><span class="input-group-text">phút</span></div>
                            <div class="form-text">API: <?= $s['timeout_raw'] !== null ? h(OrderService::duration((int)$apiSec)) : 'không có' ?> · trống = theo API</div>
                        </div>
                        <div class="col-12">
                            <label class="form-label small fw-semibold mb-1">Tính năng <?= $s['sms_manual'] ? '<span class="badge text-bg-secondary">chỉnh tay</span>' : '<span class="badge text-bg-light text-dark">theo API</span>' ?></label>
                            <div class="d-flex flex-wrap gap-3">
                                <?php foreach (OrderService::FEATURES as $col => [$field, $label]): ?>
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" name="<?= $col ?>" id="f-<?= $s['id'] . $col ?>" <?= $s[$col] ? 'checked' : '' ?>>
                                        <label class="form-check-label small" for="f-<?= $s['id'] . $col ?>"><?= $label ?><?php if ($api !== null && (int)$api[$col] !== (int)$s[$col]): ?> <span class="text-warning" title="Khác với API (<?= h($field) ?>)">⚠</span><?php endif; ?></label>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <div class="col-6 col-md-3">
                            <label class="form-label small fw-semibold mb-1">Ưu tiên hiển thị</label>
                            <input name="sort" value="<?= (int)$s['sort_order'] ?>" class="form-control" inputmode="numeric">
                            <div class="form-text">Số lớn hiện trước</div>
                        </div>
                        <div class="col-6 col-md-3 d-flex align-items-center">
                            <div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="active" id="a-<?= $s['id'] ?>" <?= $s['is_active'] ? 'checked' : '' ?>><label class="form-check-label" for="a-<?= $s['id'] ?>">Đang bán</label></div>
                        </div>
                    </div>
                    <div class="d-flex flex-wrap gap-2 mt-3">
                        <button class="btn btn-success">💾 Lưu</button>
                        <?php if ($s['custom_price']): ?><button form="act-<?= $s['id'] ?>" name="action" value="auto_price" class="btn btn-outline-secondary">Giá tự động</button><?php endif; ?>
                        <?php if ($s['timeout_custom']): ?><button form="act-<?= $s['id'] ?>" name="action" value="timeout_api" class="btn btn-outline-secondary">Thời gian theo API</button><?php endif; ?>
                        <?php if ($s['sms_manual']): ?><button form="act-<?= $s['id'] ?>" name="action" value="sms_api" class="btn btn-outline-secondary">Tính năng theo API</button><?php endif; ?>
                        <?php if ($s['api_raw']): ?><button type="button" class="btn btn-outline-secondary" data-bs-toggle="collapse" data-bs-target="#raw-<?= $s['id'] ?>">Dữ liệu API</button><?php endif; ?>
                        <button form="act-<?= $s['id'] ?>" name="action" value="delete" class="btn btn-outline-danger ms-auto" onclick="return confirm('Xóa dịch vụ <?= h(addslashes($s['name'])) ?>?')">🗑 Xóa</button>
                    </div>
                    <?php if ($s['api_raw']): ?><pre class="collapse small bg-white border rounded p-2 mt-2 mb-0" id="raw-<?= $s['id'] ?>"><?= h(json_encode(json_decode($s['api_raw'], true), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?></pre><?php endif; ?>
                </form>
                <form method="post" id="act-<?= $s['id'] ?>"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $s['id'] ?>"></form>
            </div>
        </div>
    <?php endforeach; ?>
</div>
<?= paginate($total, $per, $page, array_filter(['q' => $q, 'f' => $filter, 'sort' => $sort === 'priority' ? '' : $sort])) ?>
<div class="small text-muted mb-4">
    Bấm công tắc để bật/tắt nhanh. <b>tay</b> = giá chỉnh tay (đồng bộ không ghi đè). <b>⏱ …*</b> = thời gian chỉnh tay.
    Biểu tượng: ✉️ SMS tùy chọn · 📤 SMS cố định · 📞 chuyển tiếp cuộc gọi · ☎️ OTP qua cuộc gọi · 📲 gọi từ số thuê.
</div>
<script>
// Mở sẵn khung sửa khi quay lại sau khi lưu (vd: services.php#svc-12)
if (location.hash.startsWith('#svc-')) {
    const el = document.querySelector(location.hash);
    if (el) el.scrollIntoView({ block: 'center' });
}
</script>
<?php layout_end();
