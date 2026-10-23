<?php
require __DIR__ . '/_init.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $action = $_POST['action'] ?? '';
    $id = (int)($_POST['id'] ?? 0);
    if ($action === 'approve') {
        $amount = trim((string)($_POST['amount'] ?? ''));
        $ok = Wallet::approveDeposit($id, $amount !== '' ? (float)preg_replace('/[^\d.]/', '', $amount) : null);
        flash($ok ? "Đã duyệt yêu cầu #$id." : 'Yêu cầu đã được xử lý trước đó.', $ok ? 'success' : 'warning');
    } elseif ($action === 'reject') {
        if (DB::update('deposits', ['status' => 'rejected', 'updated_at' => now()], "id = ? AND status = 'pending'", [$id])) {
            $tgId = DB::value('SELECT u.tg_id FROM deposits d JOIN users u ON u.id = d.user_id WHERE d.id = ?', [$id]);
            (new Telegram())->send($tgId, "❌ Yêu cầu nạp #$id bị từ chối. Liên hệ " . tg_escape(Settings::get('support_contact')) . ' nếu có thắc mắc.');
            flash("Đã từ chối #$id.");
        }
    } elseif ($action === 'manual') {
        $q = trim($_POST['user']);
        $u = DB::row('SELECT * FROM users WHERE CAST(tg_id AS CHAR) = ? OR deposit_code = ?', [$q, strtoupper(str_ireplace((string)Settings::get('deposit_prefix'), '', $q))]);
        $amount = (float)preg_replace('/[^\d.]/', '', (string)$_POST['amount']);
        if (!$u || $amount <= 0) {
            flash('Không tìm thấy user hoặc số tiền không hợp lệ.', 'danger');
        } else {
            $depId = DB::insert('deposits', [
                'user_id' => $u['id'], 'amount' => $amount, 'method' => 'admin',
                'note' => trim($_POST['note'] ?? '') ?: 'Admin nạp thủ công', 'status' => 'pending',
                'created_at' => now(), 'updated_at' => now(),
            ]);
            Wallet::approveDeposit($depId);
            flash('Đã nạp ' . money($amount) . ' cho ' . h($u['tg_id']) . '.');
        }
    }
    redirect('deposits.php?' . http_build_query($_GET));
}

$status = $_GET['status'] ?? 'pending';
$page = max(1, (int)($_GET['p'] ?? 1));
$per = 50;
$where = in_array($status, ['pending', 'approved', 'rejected'], true) ? 'WHERE d.status = ?' : '';
$params = $where ? [$status] : [];
$total = (int)DB::value("SELECT COUNT(*) FROM deposits d $where", $params);
$rows = DB::all("SELECT d.*, u.tg_id, u.username, u.deposit_code FROM deposits d LEFT JOIN users u ON u.id = d.user_id $where ORDER BY d.id DESC LIMIT $per OFFSET " . (($page - 1) * $per), $params);

layout_start('Nạp tiền');
?>
<div class="row g-3">
<div class="col-lg-8">
    <ul class="nav nav-tabs mb-3">
        <?php foreach (['pending' => 'Chờ duyệt', 'approved' => 'Đã duyệt', 'rejected' => 'Từ chối', 'all' => 'Tất cả'] as $k => $l): ?>
            <li class="nav-item"><a class="nav-link <?= $status === $k ? 'active' : '' ?>" href="?status=<?= $k ?>"><?= $l ?></a></li>
        <?php endforeach; ?>
    </ul>
    <div class="card card-body"><div class="table-responsive">
    <table class="table table-sm table-hover">
        <tr><th>#</th><th>User</th><th>Nội dung CK</th><th class="text-end">Số tiền</th><th>Hình thức</th><th>Ghi chú</th><th>Trạng thái</th><th>Thời gian</th><th></th></tr>
        <?php foreach ($rows as $d): ?>
            <tr>
                <td><?= $d['id'] ?></td>
                <td><a href="users.php?id=<?= $d['user_id'] ?>"><?= h($d['username'] ? '@' . $d['username'] : $d['tg_id']) ?></a></td>
                <td><code><?= h(Settings::get('deposit_prefix') . $d['deposit_code']) ?></code></td>
                <td class="text-end fw-semibold"><?= money($d['amount']) ?></td><td><?= h($d['method']) ?></td>
                <td class="small"><?= h($d['note']) ?></td><td><?= status_badge($d['status']) ?></td><td class="small"><?= h($d['created_at']) ?></td>
                <td class="text-nowrap">
                    <?php if ($d['status'] === 'pending'): ?>
                        <form method="post" class="d-flex gap-1"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $d['id'] ?>">
                            <input name="amount" class="form-control form-control-sm" style="width:100px" placeholder="Số thực nhận" title="Để trống = dùng số tiền yêu cầu">
                            <button name="action" value="approve" class="btn btn-sm btn-success">Duyệt</button>
                            <button name="action" value="reject" class="btn btn-sm btn-outline-danger">Từ chối</button>
                        </form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?><tr><td colspan="9" class="text-muted">Không có dữ liệu.</td></tr><?php endif; ?>
    </table>
    </div>
    <?= paginate($total, $per, $page, ['status' => $status]) ?>
    </div>
</div>
<div class="col-lg-4">
    <form method="post" class="card card-body">
        <?= csrf_field() ?><input type="hidden" name="action" value="manual">
        <h6>Nạp thủ công cho user</h6>
        <input name="user" class="form-control form-control-sm mb-2" placeholder="Telegram ID hoặc mã nạp (NAPxxxxxx)" required>
        <input name="amount" class="form-control form-control-sm mb-2" placeholder="Số tiền" required>
        <input name="note" class="form-control form-control-sm mb-2" placeholder="Ghi chú">
        <button class="btn btn-sm btn-primary">Nạp tiền</button>
        <div class="form-text">Khuyến mãi nạp (<?= h(Settings::get('deposit_bonus_percent')) ?>%) được cộng tự động.</div>
    </form>
</div>
</div>
<?php layout_end();
