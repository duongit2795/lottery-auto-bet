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
    } elseif ($action === 'assign') {
        $u = DB::row('SELECT * FROM users WHERE CAST(tg_id AS CHAR) = ? OR deposit_code = ?', [trim($_POST['user']), strtoupper(str_ireplace((string)Settings::get('deposit_prefix'), '', trim($_POST['user'])))]);
        $ok = $u && BankSync::assign((string)$_POST['ref'], $u);
        flash($ok ? 'Đã cộng tiền giao dịch cho ' . h($u['tg_id']) . '.' : 'Không gán được (sai user hoặc giao dịch đã xử lý).', $ok ? 'success' : 'danger');
    } elseif ($action === 'sync_bank') {
        $r = BankSync::sync(true);
        flash(h($r['message']), $r['ok'] ? 'success' : 'danger');
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

<?php $bank = DB::all('SELECT t.*, u.tg_id, u.username FROM bank_tx t LEFT JOIN users u ON u.id = t.user_id ORDER BY t.created_at DESC LIMIT 50'); ?>
<div class="card card-body mt-3">
    <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
        <h6 class="mb-0">🏦 Giao dịch ngân hàng (nạp tự động)</h6>
        <?php if (BankSync::enabled()): ?>
            <span class="small text-muted">Quét lần cuối OK: <?= h(Settings::get('bank_api_last_ok') ?: '—') ?></span>
            <form method="post" class="ms-auto"><?= csrf_field() ?><button name="action" value="sync_bank" class="btn btn-sm btn-outline-success">🔄 Quét ngay</button></form>
        <?php else: ?>
            <span class="small">Chưa bật — nhập link API Web2M ở <a href="settings.php">Cài đặt → Nạp tiền</a>.</span>
        <?php endif; ?>
    </div>
    <?php if ($err = Settings::get('bank_api_last_error')): ?><div class="alert alert-danger py-1 small">Lỗi gần nhất: <?= h($err) ?></div><?php endif; ?>
    <div class="table-responsive"><table class="table table-sm">
        <tr><th>Thời gian GD</th><th class="text-end">Số tiền</th><th>Nội dung</th><th>Kết quả</th><th></th></tr>
        <?php
        $labels = ['credited' => ['Đã cộng', 'success'], 'unmatched' => ['Không có mã nạp', 'warning'], 'too_small' => ['Dưới mức tối thiểu', 'warning'],
            'baseline' => ['GD cũ (bỏ qua)', 'secondary'], 'out' => ['Tiền ra', 'light'], 'duplicate' => ['Đã xử lý trước đó', 'secondary'], 'processing' => ['Đang xử lý', 'info']];
        foreach ($bank as $t): [$l, $c] = $labels[$t['status']] ?? [$t['status'], 'light']; ?>
            <tr>
                <td class="small"><?= h($t['tx_time'] ?: $t['created_at']) ?></td>
                <td class="text-end fw-semibold"><?= money($t['amount']) ?></td>
                <td class="small" style="max-width:260px"><?= h($t['content']) ?></td>
                <td><span class="badge text-bg-<?= $c ?>"><?= $l ?></span><?php if ($t['tg_id']): ?> <a class="small" href="users.php?id=<?= (int)$t['user_id'] ?>"><?= h($t['username'] ? '@' . $t['username'] : $t['tg_id']) ?></a><?php endif; ?></td>
                <td>
                    <?php if (in_array($t['status'], ['unmatched', 'too_small'], true)): ?>
                        <form method="post" class="input-group input-group-sm" style="min-width:200px" onsubmit="return confirm('Cộng <?= money($t['amount']) ?> cho user này?')">
                            <?= csrf_field() ?><input type="hidden" name="action" value="assign"><input type="hidden" name="ref" value="<?= h($t['ref']) ?>">
                            <input name="user" class="form-control" placeholder="TG ID / mã nạp" required>
                            <button class="btn btn-outline-primary">Cộng</button>
                        </form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$bank): ?><tr><td colspan="5" class="text-muted small">Chưa có giao dịch.</td></tr><?php endif; ?>
    </table></div>
    <div class="form-text">Khách chuyển khoản sai/thiếu nội dung → giao dịch hiện "Không có mã nạp", nhập Telegram ID của khách và bấm Cộng.</div>
</div>
<?php layout_end();
