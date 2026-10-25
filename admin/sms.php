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
    redirect('sms.php?' . http_build_query($_GET + ['tab' => 'orders']));
}

$tab = ($_GET['tab'] ?? 'history') === 'orders' ? 'orders' : 'history';
$tabs = '<ul class="nav nav-pills mb-3">'
    . '<li class="nav-item"><a class="nav-link ' . ($tab === 'history' ? 'active' : '') . '" href="sms.php">📜 Lịch sử gửi tin</a></li>'
    . '<li class="nav-item"><a class="nav-link ' . ($tab === 'orders' ? 'active' : '') . '" href="sms.php?tab=orders">📱 Đơn thuê số</a></li></ul>';

if ($tab === 'history') {
    $res = $_GET['r'] ?? '';          // ok | fail
    $kind = $_GET['k'] ?? '';         // custom | fixed | forward
    $q = trim($_GET['q'] ?? '');
    $from = $_GET['from'] ?? '';
    $to = $_GET['to'] ?? '';
    $page = max(1, (int)($_GET['p'] ?? 1));
    $per = 50;

    $conds = ['1 = 1'];
    $params = [];
    if ($res === 'ok' || $res === 'fail') {
        $conds[] = 'x.ok = ' . ($res === 'ok' ? 1 : 0);
    }
    if (in_array($kind, ['custom', 'fixed', 'forward'], true)) {
        $conds[] = 'x.kind = ?';
        $params[] = $kind;
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
        $conds[] = 'x.created_at >= ?';
        $params[] = $from . ' 00:00:00';
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
        $conds[] = 'x.created_at <= ?';
        $params[] = $to . ' 23:59:59';
    }
    if ($q !== '') {
        $conds[] = '(x.receiver LIKE ? OR x.content LIKE ? OR o.phone LIKE ? OR CAST(u.tg_id AS CHAR) = ? OR LOWER(u.username) LIKE ? OR CAST(x.order_id AS CHAR) = ?)';
        array_push($params, "%$q%", "%$q%", "%$q%", $q, '%' . mb_strtolower(ltrim($q, '@')) . '%', ltrim($q, '#'));
    }
    $where = 'WHERE ' . implode(' AND ', $conds);
    $join = 'FROM sms_sends x LEFT JOIN orders o ON o.id = x.order_id LEFT JOIN users u ON u.id = x.user_id';
    $select = "SELECT x.*, o.phone AS from_phone, o.service_name, o.status AS order_status, u.tg_id, u.username, u.first_name $join $where ORDER BY x.id DESC";
    $kindLabel = ['custom' => '✉️ SMS tùy chọn', 'fixed' => '📤 SMS cố định', 'forward' => '📞 Chuyển cuộc gọi'];

    // Xuất CSV theo bộ lọc hiện tại
    if (isset($_GET['export'])) {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="lich-su-gui-tin-' . date('Ymd-His') . '.csv"');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF"); // để Excel đọc đúng tiếng Việt
        fputcsv($out, ['Thời gian', 'Kết quả', 'Kiểu', 'Telegram ID', 'Username', 'Dịch vụ', 'Số gửi (thuê)', 'Số nhận', 'Nội dung', 'Lỗi', 'Đơn']);
        foreach (DB::all($select . ' LIMIT 20000', $params) as $x) {
            fputcsv($out, [$x['created_at'], $x['ok'] ? 'Thành công' : 'Lỗi', strip_tags($kindLabel[$x['kind']] ?? $x['kind']), $x['tg_id'], $x['username'], $x['service_name'], $x['from_phone'], $x['receiver'], $x['content'], $x['error'], $x['order_id']]);
        }
        fclose($out);
        exit;
    }

    $total = (int)DB::value("SELECT COUNT(*) $join $where", $params);
    $rows = DB::all($select . " LIMIT $per OFFSET " . (($page - 1) * $per), $params);
    $today = date('Y-m-d 00:00:00');
    $week = date('Y-m-d 00:00:00', strtotime('-6 days'));
    $stats = [
        ['Gửi thành công hôm nay', (int)DB::value('SELECT COUNT(*) FROM sms_sends WHERE ok = 1 AND created_at >= ?', [$today]), 'success'],
        ['Lỗi hôm nay', (int)DB::value('SELECT COUNT(*) FROM sms_sends WHERE ok = 0 AND created_at >= ?', [$today]), 'danger'],
        ['Thành công 7 ngày', (int)DB::value('SELECT COUNT(*) FROM sms_sends WHERE ok = 1 AND created_at >= ?', [$week]), 'primary'],
        ['Tổng đã lưu', (int)DB::value('SELECT COUNT(*) FROM sms_sends'), 'secondary'],
    ];
    $qs = fn(array $over) => '?' . http_build_query(array_filter(array_merge(['r' => $res, 'k' => $kind, 'q' => $q, 'from' => $from, 'to' => $to], $over), fn($v) => $v !== '' && $v !== null));

    layout_start('Tin nhắn đã gửi');
    echo $tabs;
    ?>
    <style>
    .sendrow { border-bottom: 1px solid #eef0f3; padding: 10px 2px; }
    .sendrow:last-child { border-bottom: 0; }
    .sendrow .content { white-space: pre-wrap; word-break: break-word; background: #f8fafc; border-radius: 8px; padding: 6px 10px; margin-top: 6px; font-size: 14px; }
    .stat-card { border: 0; border-left: 4px solid; }
    .chip { display: inline-block; padding: 5px 12px; border-radius: 20px; border: 1px solid #d1d5db; color: #374151; text-decoration: none; font-size: 13px; margin: 0 4px 6px 0; background: #fff; }
    .chip.active { background: #2563eb; border-color: #2563eb; color: #fff; }
    </style>
    <div class="row g-2 mb-3">
        <?php foreach ($stats as [$l, $v, $c]): ?>
            <div class="col-6 col-md-3"><div class="card card-body py-2 stat-card border-<?= $c ?>"><div class="small text-muted"><?= $l ?></div><div class="fs-4 fw-bold"><?= number_format($v) ?></div></div></div>
        <?php endforeach; ?>
    </div>

    <div class="card card-body mb-3">
        <form class="row g-2 align-items-end mb-2">
            <input type="hidden" name="r" value="<?= h($res) ?>"><input type="hidden" name="k" value="<?= h($kind) ?>">
            <div class="col-12 col-md-5"><input name="q" value="<?= h($q) ?>" class="form-control" placeholder="🔍 Số nhận, nội dung, số thuê, TG ID, @username, #đơn"></div>
            <div class="col-6 col-md-2"><label class="small text-muted">Từ ngày</label><input type="date" name="from" value="<?= h($from) ?>" class="form-control"></div>
            <div class="col-6 col-md-2"><label class="small text-muted">Đến ngày</label><input type="date" name="to" value="<?= h($to) ?>" class="form-control"></div>
            <div class="col-6 col-md-1"><button class="btn btn-secondary w-100">Lọc</button></div>
            <div class="col-6 col-md-2"><a class="btn btn-outline-success w-100" href="<?= h($qs(['export' => 1])) ?>">⬇️ Xuất CSV</a></div>
        </form>
        <div>
            <?php foreach (['' => 'Tất cả', 'ok' => '✅ Thành công', 'fail' => '❌ Lỗi'] as $k2 => $l): ?><a class="chip <?= $res === $k2 ? 'active' : '' ?>" href="<?= h($qs(['r' => $k2, 'p' => ''])) ?>"><?= $l ?></a><?php endforeach; ?>
            <span class="text-muted mx-1">|</span>
            <?php foreach (['' => 'Mọi kiểu'] + $kindLabel as $k2 => $l): ?><a class="chip <?= $kind === $k2 ? 'active' : '' ?>" href="<?= h($qs(['k' => $k2, 'p' => ''])) ?>"><?= $l ?></a><?php endforeach; ?>
        </div>
    </div>

    <div class="card card-body py-1 mb-2">
        <div class="small text-muted pt-2"><?= number_format($total) ?> tin<?= $total > $per ? ' · trang ' . $page : '' ?></div>
        <?php if (!$rows): ?><div class="text-center text-muted py-4">Chưa có tin nhắn nào<?= $q !== '' || $res !== '' || $kind !== '' || $from !== '' || $to !== '' ? ' phù hợp bộ lọc' : '' ?>.</div><?php endif; ?>
        <?php foreach ($rows as $x): ?>
            <div class="sendrow">
                <div class="d-flex flex-wrap align-items-center gap-2">
                    <span class="badge text-bg-<?= $x['ok'] ? 'success' : 'danger' ?>"><?= $x['ok'] ? '✅ Đã gửi' : '❌ Lỗi' ?></span>
                    <span class="small"><?= h($kindLabel[$x['kind']] ?? $x['kind']) ?></span>
                    <span class="small text-muted"><?= h(date('H:i:s d/m/Y', strtotime($x['created_at']))) ?></span>
                    <span class="ms-auto small">
                        <a href="users.php?id=<?= (int)$x['user_id'] ?>"><?= h($x['username'] ? '@' . $x['username'] : ($x['first_name'] ?: $x['tg_id'])) ?></a>
                        · <a href="sms.php?tab=orders&q=<?= h($x['from_phone']) ?>">đơn #<?= (int)$x['order_id'] ?></a>
                    </span>
                </div>
                <div class="mt-1">
                    <span class="text-muted small">Từ</span> <code><?= h($x['from_phone'] ?: '?') ?></code>
                    <span class="text-muted small"><?= h($x['service_name']) ?></span>
                    <span class="mx-1">→</span>
                    <b><?= h($x['receiver'] ?: ($x['kind'] === 'fixed' ? 'SMS cố định' : '—')) ?></b>
                </div>
                <?php if ($x['content']): ?><div class="content"><?= h($x['content']) ?></div><?php endif; ?>
                <?php if ($x['error']): ?><div class="small text-danger mt-1">⚠️ <?= h($x['error']) ?></div><?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>
    <?= paginate($total, $per, $page, array_filter(['r' => $res, 'k' => $kind, 'q' => $q, 'from' => $from, 'to' => $to])) ?>
    <div class="small text-muted mb-4">Mỗi lần khách bấm gửi (bot hoặc Mini App) đều được lưu lại ở đây, kể cả lần bị lỗi.</div>
    <?php
    layout_end();
    exit;
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

layout_start('Tin nhắn đã gửi');
echo $tabs;
?>
<div class="row g-3 mb-3">
    <?php foreach ($stats as [$l, $v, $c]): ?>
        <div class="col-4"><div class="card card-body border-start border-4 border-<?= $c ?>"><div class="small text-muted"><?= $l ?></div><div class="stat"><?= $v ?></div></div></div>
    <?php endforeach; ?>
</div>
<form class="d-flex flex-wrap gap-2 mb-3"><input type="hidden" name="tab" value="orders">
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
<?= paginate($total, $per, $page, ['tab' => 'orders', 'f' => $filter, 'q' => $q]) ?>
</div>
<?php layout_end();
