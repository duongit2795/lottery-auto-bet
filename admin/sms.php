<?php
require __DIR__ . '/_init.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $action = $_POST['action'] ?? '';
    $svc = new SmsService();
    if ($action === 'cancel') {
        $job = DB::row('SELECT * FROM sms_jobs WHERE id = ?', [(int)$_POST['id']]);
        $ok = $job && $svc->fail($job, 'Admin hủy');
        flash($ok ? 'Đã hủy và hoàn tiền/lượt.' : 'Tin này không còn trong hàng đợi.', $ok ? 'success' : 'warning');
    } elseif ($action === 'retry') {
        // Gửi lại tin thất bại: trừ lại tiền/lượt như ban đầu rồi đưa vào hàng đợi
        $job = DB::row("SELECT * FROM sms_jobs WHERE id = ? AND status = 'failed'", [(int)$_POST['id']]);
        if ($job) {
            $charged = $job['user_package_id']
                ? DB::query('UPDATE user_packages SET credits_left = credits_left - 1 WHERE id = ? AND credits_left > 0', [$job['user_package_id']])->rowCount() === 1
                : ((float)$job['price'] <= 0 || Wallet::change((int)$job['user_id'], -(float)$job['price'], 'sms', "Gửi lại tin nhắn #{$job['id']}") !== null);
            if ($charged) {
                DB::update('sms_jobs', ['status' => 'queued', 'attempts' => 0, 'error' => null, 'updated_at' => now()], 'id = ?', [$job['id']]);
                flash('Đã đưa tin vào hàng đợi gửi lại.');
            } else {
                flash('Khách không đủ số dư/lượt để gửi lại.', 'danger');
            }
        }
    } elseif ($action === 'run') {
        ignore_user_abort(true);
        set_time_limit(120);
        $n = $svc->processQueue(20);
        flash("Đã xử lý $n tin trong hàng đợi.");
    }
    redirect('sms.php?' . http_build_query($_GET));
}

$status = $_GET['status'] ?? '';
$q = trim($_GET['q'] ?? '');
$page = max(1, (int)($_GET['p'] ?? 1));
$per = 50;
$conds = [];
$params = [];
if (in_array($status, ['queued', 'processing', 'sent', 'failed'], true)) {
    $conds[] = 'j.status = ?';
    $params[] = $status;
}
if ($q !== '') {
    $conds[] = '(j.receiver LIKE ? OR j.batch = ? OR CAST(u.tg_id AS CHAR) = ? OR LOWER(j.content) LIKE ?)';
    array_push($params, '%' . $q . '%', $q, $q, '%' . mb_strtolower($q) . '%');
}
$where = $conds ? 'WHERE ' . implode(' AND ', $conds) : '';
$total = (int)DB::value("SELECT COUNT(*) FROM sms_jobs j LEFT JOIN users u ON u.id = j.user_id $where", $params);
$rows = DB::all("SELECT j.*, u.tg_id, u.username, s.name AS service_name, o.phone FROM sms_jobs j
    LEFT JOIN users u ON u.id = j.user_id LEFT JOIN services s ON s.id = j.service_id LEFT JOIN orders o ON o.id = j.order_id
    $where ORDER BY j.id DESC LIMIT $per OFFSET " . (($page - 1) * $per), $params);
$counts = [];
foreach (DB::all('SELECT status, COUNT(*) c FROM sms_jobs GROUP BY status') as $r) {
    $counts[$r['status']] = (int)$r['c'];
}

layout_start('Tin nhắn đã gửi');
?>
<div class="row g-3 mb-3">
    <?php foreach (['queued' => ['Đang chờ', 'warning'], 'sent' => ['Đã gửi', 'success'], 'failed' => ['Thất bại', 'danger']] as $k => [$l, $c]): ?>
        <div class="col-4 col-lg-2"><div class="card card-body border-start border-4 border-<?= $c ?>"><div class="small text-muted"><?= $l ?></div><div class="stat"><?= $counts[$k] ?? 0 ?></div></div></div>
    <?php endforeach; ?>
    <div class="col-lg-6 d-flex align-items-center">
        <form method="post"><?= csrf_field() ?><button name="action" value="run" class="btn btn-outline-primary btn-sm">▶️ Xử lý hàng đợi ngay</button></form>
        <span class="small text-muted ms-2">Bình thường cron tự xử lý mỗi phút.</span>
    </div>
</div>
<form class="d-flex flex-wrap gap-2 mb-3">
    <select name="status" class="form-select form-select-sm" style="max-width:180px">
        <option value="">Tất cả trạng thái</option>
        <?php foreach (['queued' => 'Đang chờ', 'processing' => 'Đang xử lý', 'sent' => 'Đã gửi', 'failed' => 'Thất bại'] as $k => $l): ?>
            <option value="<?= $k ?>" <?= $status === $k ? 'selected' : '' ?>><?= $l ?></option>
        <?php endforeach; ?>
    </select>
    <input name="q" value="<?= h($q) ?>" class="form-control form-control-sm" style="max-width:300px" placeholder="Số nhận, mã lệnh, TG ID, nội dung">
    <button class="btn btn-sm btn-secondary">Lọc</button>
</form>
<div class="card card-body"><div class="table-responsive">
<table class="table table-sm table-hover">
    <tr><th>#</th><th>Mã lệnh</th><th>User</th><th>Dịch vụ</th><th>Số gửi → nhận</th><th>Nội dung</th><th>Thanh toán</th><th>Trạng thái</th><th>Lần thử</th><th>Thời gian</th><th></th></tr>
    <?php foreach ($rows as $j): ?>
        <tr>
            <td><?= $j['id'] ?></td><td class="small"><code><?= h($j['batch']) ?></code></td>
            <td><a href="users.php?id=<?= $j['user_id'] ?>"><?= h($j['username'] ? '@' . $j['username'] : $j['tg_id']) ?></a></td>
            <td><?= h($j['service_name']) ?></td>
            <td class="small"><?= h($j['phone'] ?: '-') ?> → <b><?= h($j['receiver'] ?: 'cố định') ?></b></td>
            <td class="small text-truncate" style="max-width:240px" title="<?= h($j['content']) ?>"><?= h($j['content']) ?></td>
            <td class="small"><?= $j['user_package_id'] ? 'Gói #' . (int)$j['user_package_id'] : money($j['price']) ?></td>
            <td><?= status_badge($j['status']) ?>
                <?php if ($j['error']): ?><div class="small text-danger"><?= h($j['error']) ?></div><?php endif; ?></td>
            <td><?= (int)$j['attempts'] ?></td>
            <td class="small"><?= h($j['created_at']) ?></td>
            <td class="text-nowrap">
                <form method="post" class="d-inline"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $j['id'] ?>">
                    <?php if ($j['status'] === 'queued'): ?><button name="action" value="cancel" class="btn btn-sm btn-outline-danger" onclick="return confirm('Hủy và hoàn tiền?')">Hủy</button><?php endif; ?>
                    <?php if ($j['status'] === 'failed'): ?><button name="action" value="retry" class="btn btn-sm btn-outline-primary" onclick="return confirm('Gửi lại? Khách sẽ bị trừ tiền/lượt lại.')">Gửi lại</button><?php endif; ?>
                </form>
            </td>
        </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="11" class="text-muted">Chưa có tin nhắn.</td></tr><?php endif; ?>
</table>
</div>
<?= paginate($total, $per, $page, ['status' => $status, 'q' => $q]) ?>
</div>
<?php layout_end();
