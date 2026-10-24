<?php
require __DIR__ . '/_init.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $o = DB::row("SELECT * FROM orders WHERE id = ? AND status = 'pending'", [(int)$_POST['id']]);
    if ($o && ($_POST['action'] ?? '') === 'refund') {
        (new OrderService())->refund($o, 'refunded', 'Admin hủy');
        flash("Đã hủy & hoàn đơn #{$o['id']}.");
    } elseif ($o && ($_POST['action'] ?? '') === 'check') {
        $o = (new OrderService())->poll($o);
        flash("Đơn #{$o['id']}: " . h($o['status']));
    }
    redirect('sms.php?' . http_build_query($_GET));
}

$filter = $_GET['f'] ?? '';
$q = trim($_GET['q'] ?? '');
$page = max(1, (int)($_GET['p'] ?? 1));
$per = 50;
$conds = ["(o.kind = 'sms' OR o.sms_sent > 0)"];
$params = [];
if ($filter === 'sent') {
    $conds[] = 'o.sms_sent > 0';
} elseif ($filter === 'waiting') {
    $conds[] = "o.status = 'pending' AND o.sms_sent = 0";
} elseif ($filter === 'refunded') {
    $conds[] = "o.status IN ('refunded', 'cancelled')";
}
if ($q !== '') {
    $conds[] = '(o.phone LIKE ? OR o.sms_info LIKE ? OR CAST(u.tg_id AS CHAR) = ?)';
    array_push($params, '%' . $q . '%', '%' . $q . '%', $q);
}
$where = 'WHERE ' . implode(' AND ', $conds);
$total = (int)DB::value("SELECT COUNT(*) FROM orders o LEFT JOIN users u ON u.id = o.user_id $where", $params);
$rows = DB::all("SELECT o.*, u.tg_id, u.username FROM orders o LEFT JOIN users u ON u.id = o.user_id $where ORDER BY o.id DESC LIMIT $per OFFSET " . (($page - 1) * $per), $params);
$today = date('Y-m-d 00:00:00');
$stats = [
    ['Tin gửi thành công hôm nay', (int)DB::value('SELECT COUNT(*) FROM sms_sends WHERE ok = 1 AND created_at >= ?', [$today]), 'success'],
    ['Đã thuê, chưa gửi', (int)DB::value("SELECT COUNT(*) FROM orders WHERE kind = 'sms' AND status = 'pending' AND sms_sent = 0"), 'warning'],
    ['Thuê bằng gói hôm nay', (int)DB::value("SELECT COUNT(*) FROM orders WHERE kind = 'sms' AND user_package_id IS NOT NULL AND created_at >= ?", [$today]), 'primary'],
];

layout_start('Tin nhắn (thuê số gửi tin)');
?>
<div class="row g-3 mb-3">
    <?php foreach ($stats as [$l, $v, $c]): ?>
        <div class="col-4"><div class="card card-body border-start border-4 border-<?= $c ?>"><div class="small text-muted"><?= $l ?></div><div class="stat"><?= $v ?></div></div></div>
    <?php endforeach; ?>
</div>
<form class="d-flex flex-wrap gap-2 mb-3">
    <select name="f" class="form-select form-select-sm" style="max-width:200px">
        <?php foreach (['' => 'Tất cả', 'sent' => 'Đã gửi tin', 'waiting' => 'Đã thuê, chưa gửi', 'refunded' => 'Đã hoàn'] as $k => $l): ?>
            <option value="<?= $k ?>" <?= $filter === $k ? 'selected' : '' ?>><?= $l ?></option>
        <?php endforeach; ?>
    </select>
    <input name="q" value="<?= h($q) ?>" class="form-control form-control-sm" style="max-width:280px" placeholder="SĐT, số nhận, TG ID">
    <button class="btn btn-sm btn-secondary">Lọc</button>
</form>
<div class="card card-body"><div class="table-responsive">
<table class="table table-sm table-hover">
    <tr><th>#</th><th>User</th><th>Dịch vụ</th><th>Số thuê</th><th>Đã gửi</th><th>Thanh toán</th><th>Trạng thái</th><th>Thời gian</th><th></th></tr>
    <?php foreach ($rows as $o): ?>
        <tr>
            <td><?= $o['id'] ?></td>
            <td><a href="users.php?id=<?= $o['user_id'] ?>"><?= h($o['username'] ? '@' . $o['username'] : $o['tg_id']) ?></a></td>
            <td><?= h($o['service_name']) ?></td>
            <td><code><?= h($o['phone']) ?></code></td>
            <td class="small" style="max-width:320px">
                <?php $sends = DB::all('SELECT * FROM sms_sends WHERE order_id = ? ORDER BY id', [$o['id']]); ?>
                <?php if ($sends): ?>
                    <details><summary><b><?= (int)$o['sms_sent'] ?></b> lần gửi<?= count($sends) > (int)$o['sms_sent'] ? ' · ' . (count($sends) - (int)$o['sms_sent']) . ' lỗi' : '' ?><?= $o['sms_closed'] ? ' · <span class="text-danger">hết lượt</span>' : '' ?></summary>
                        <?php foreach ($sends as $x): ?>
                            <div><?= $x['ok'] ? '✅' : '❌' ?> <?= h(date('H:i d/m', strtotime($x['created_at']))) ?> → <?= h($x['receiver'] ?: ($x['kind'] === 'fixed' ? 'SMS cố định' : '')) ?> <?= $x['content'] ? '"' . h(mb_substr($x['content'], 0, 60)) . '"' : '' ?><?= $x['error'] ? ' <span class="text-danger">' . h($x['error']) . '</span>' : '' ?></div>
                        <?php endforeach; ?>
                    </details>
                <?php else: ?><span class="text-muted">—</span><?php endif; ?>
                <?php if ($o['otp'] || $o['sms']): ?><div class="text-muted">Nhận: <?= h($o['otp'] ?: mb_substr((string)$o['sms'], 0, 60)) ?></div><?php endif; ?></td>
            <td class="small"><?= $o['user_package_id'] ? 'Gói #' . (int)$o['user_package_id'] : money($o['price']) ?></td>
            <td><?= status_badge($o['status']) ?></td>
            <td class="small"><?= h($o['created_at']) ?></td>
            <td class="text-nowrap">
                <?php if ($o['status'] === 'pending'): ?>
                    <form method="post" class="d-inline"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $o['id'] ?>">
                        <button name="action" value="check" class="btn btn-sm btn-outline-primary">Check</button>
                        <button name="action" value="refund" class="btn btn-sm btn-outline-danger" onclick="return confirm('Hủy và hoàn tiền/lượt?')">Hoàn</button>
                    </form>
                <?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="9" class="text-muted">Chưa có đơn thuê số gửi tin.</td></tr><?php endif; ?>
</table>
</div>
<?= paginate($total, $per, $page, ['f' => $filter, 'q' => $q]) ?>
</div>
<?php layout_end();
