<?php
require __DIR__ . '/_init.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $o = DB::row('SELECT * FROM orders WHERE id = ?', [(int)$_POST['id']]);
    $svc = new OrderService();
    if ($o && $_POST['action'] === 'check') {
        $o = $svc->poll($o);
        flash("Đơn #{$o['id']}: " . h($o['status']) . ($o['otp'] ? ' — OTP ' . h($o['otp']) : ''));
    } elseif ($o && $_POST['action'] === 'refund') {
        if ($o['status'] === 'pending') {
            $svc->refund($o, 'refunded', 'Admin hủy');
            flash("Đã hủy & hoàn tiền đơn #{$o['id']}.");
        } elseif ($o['status'] === 'success') {
            // Hoàn tiền cho đơn đã thành công (khiếu nại OTP sai...)
            if (DB::update('orders', ['status' => 'refunded', 'updated_at' => now()], "id = ? AND status = 'success'", [$o['id']])) {
                $after = Wallet::change((int)$o['user_id'], (float)$o['price'], 'refund', "Admin hoàn tiền đơn #{$o['id']}");
                DB::query('UPDATE users SET total_spent = total_spent - ? WHERE id = ?', [$o['price'], $o['user_id']]);
                $tgId = DB::value('SELECT tg_id FROM users WHERE id = ?', [$o['user_id']]);
                (new Telegram())->send($tgId, "↩️ Admin đã hoàn <b>" . money($o['price']) . "</b> cho đơn #{$o['id']}. Số dư: <b>" . money($after) . '</b>');
                flash("Đã hoàn tiền đơn #{$o['id']}.");
            }
        }
    }
    redirect('orders.php?' . http_build_query(array_diff_key($_GET, ['x' => 1])));
}

$status = $_GET['status'] ?? '';
$q = trim($_GET['q'] ?? '');
$page = max(1, (int)($_GET['p'] ?? 1));
$per = 50;
$conds = [];
$params = [];
if (in_array($status, ['pending', 'success', 'refunded', 'cancelled'], true)) {
    $conds[] = 'o.status = ?';
    $params[] = $status;
}
if ($q !== '') {
    $conds[] = '(o.phone LIKE ? OR CAST(u.tg_id AS CHAR) = ? OR o.provider_order_id = ? OR CAST(o.id AS CHAR) = ? OR LOWER(o.service_name) LIKE ?)';
    array_push($params, '%' . $q . '%', $q, $q, ltrim($q, '#'), '%' . mb_strtolower($q) . '%');
}
$where = $conds ? 'WHERE ' . implode(' AND ', $conds) : '';
$total = (int)DB::value("SELECT COUNT(*) FROM orders o LEFT JOIN users u ON u.id = o.user_id $where", $params);
$rows = DB::all("SELECT o.*, u.tg_id, u.username FROM orders o LEFT JOIN users u ON u.id = o.user_id $where ORDER BY o.id DESC LIMIT $per OFFSET " . (($page - 1) * $per), $params);

layout_start('Đơn thuê sim');
?>
<form class="d-flex flex-wrap gap-2 mb-3">
    <select name="status" class="form-select form-select-sm" style="max-width:180px">
        <option value="">Tất cả trạng thái</option>
        <?php foreach (['pending' => 'Đang chờ', 'success' => 'Thành công', 'refunded' => 'Hoàn tiền', 'cancelled' => 'User hủy'] as $k => $l): ?>
            <option value="<?= $k ?>" <?= $status === $k ? 'selected' : '' ?>><?= $l ?></option>
        <?php endforeach; ?>
    </select>
    <input name="q" value="<?= h($q) ?>" class="form-control form-control-sm" style="max-width:300px" placeholder="Mã đơn, SĐT, TG ID, dịch vụ">
    <button class="btn btn-sm btn-secondary">Lọc</button>
</form>
<div class="card card-body"><div class="table-responsive">
<table class="table table-sm table-hover">
    <tr><th>#</th><th>User</th><th>Dịch vụ</th><th>SĐT</th><th>OTP / SMS</th><th class="text-end">Giá</th><th class="text-end">Gốc</th><th>Trạng thái</th><th>Tạo lúc</th><th>Mã API</th><th></th></tr>
    <?php foreach ($rows as $o): ?>
        <tr>
            <td><?= $o['id'] ?></td>
            <td><a href="users.php?id=<?= $o['user_id'] ?>"><?= h($o['username'] ? '@' . $o['username'] : $o['tg_id']) ?></a></td>
            <td><?= h($o['service_name']) ?></td><td><?= h($o['phone']) ?></td>
            <td><code><?= h($o['otp']) ?></code><?php if ($o['sms']): ?><div class="small text-muted text-truncate" style="max-width:220px" title="<?= h($o['sms']) ?>"><?= h($o['sms']) ?></div><?php endif; ?></td>
            <td class="text-end"><?= money($o['price']) ?></td><td class="text-end text-muted"><?= money($o['cost']) ?></td>
            <td><?= status_badge($o['status']) ?></td><td class="small"><?= h($o['created_at']) ?></td><td class="small"><code><?= h($o['provider_order_id']) ?></code></td>
            <td class="text-nowrap">
                <form method="post" class="d-inline"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $o['id'] ?>">
                    <?php if ($o['status'] === 'pending'): ?><button name="action" value="check" class="btn btn-sm btn-outline-primary">Check</button><?php endif; ?>
                    <?php if (in_array($o['status'], ['pending', 'success'], true)): ?><button name="action" value="refund" class="btn btn-sm btn-outline-danger" onclick="return confirm('Hoàn tiền đơn #<?= $o['id'] ?>?')">Hoàn tiền</button><?php endif; ?>
                </form>
            </td>
        </tr>
    <?php endforeach; ?>
</table>
</div>
<?= paginate($total, $per, $page, ['status' => $status, 'q' => $q]) ?>
</div>
<?php layout_end();
