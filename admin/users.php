<?php
require __DIR__ . '/_init.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $u = DB::row('SELECT * FROM users WHERE id = ?', [(int)$_POST['id']]);
    $action = $_POST['action'] ?? '';
    if ($u && $action === 'balance') {
        $amount = (float)preg_replace('/[^\d.]/', '', (string)$_POST['amount']);
        $delta = ($_POST['op'] ?? '+') === '-' ? -$amount : $amount;
        $note = trim($_POST['note'] ?? '') ?: 'Admin điều chỉnh';
        if ($amount > 0) {
            $after = Wallet::change((int)$u['id'], $delta, 'admin', $note);
            if ($after === null) {
                flash('Số dư không đủ để trừ.', 'danger');
            } else {
                if (isset($_POST['count_deposit']) && $delta > 0) {
                    DB::query('UPDATE users SET total_deposit = total_deposit + ? WHERE id = ?', [$delta, $u['id']]);
                }
                (new Telegram())->send($u['tg_id'], ($delta > 0 ? '➕ Bạn được cộng ' : '➖ Bạn bị trừ ') . money($amount) . ' (' . tg_escape($note) . ")\nSố dư: <b>" . money($after) . '</b>');
                flash('Đã cập nhật số dư: ' . money($after));
            }
        }
    } elseif ($u && $action === 'ban') {
        DB::update('users', ['is_banned' => $u['is_banned'] ? 0 : 1], 'id = ?', [$u['id']]);
        flash($u['is_banned'] ? 'Đã mở khóa.' : 'Đã khóa người dùng.');
    } elseif ($u && $action === 'message') {
        $r = (new Telegram())->send($u['tg_id'], (string)$_POST['text']);
        flash(!empty($r['ok']) ? 'Đã gửi tin nhắn.' : 'Lỗi: ' . h($r['description'] ?? ''), !empty($r['ok']) ? 'success' : 'danger');
    }
    redirect('users.php?' . http_build_query(['id' => $_GET['id'] ?? null, 'q' => $_GET['q'] ?? null]));
}

// Chi tiết 1 user
if (!empty($_GET['id'])) {
    $u = DB::row('SELECT * FROM users WHERE id = ?', [(int)$_GET['id']]);
    if (!$u) {
        redirect('users.php');
    }
    $tx = DB::all('SELECT * FROM transactions WHERE user_id = ? ORDER BY id DESC LIMIT 50', [$u['id']]);
    $orders = DB::all('SELECT * FROM orders WHERE user_id = ? ORDER BY id DESC LIMIT 30', [$u['id']]);
    $refs = (int)DB::value('SELECT COUNT(*) FROM users WHERE ref_by = ?', [$u['tg_id']]);
    layout_start('Người dùng: ' . ($u['first_name'] ?: $u['tg_id']));
    ?>
    <a href="users.php" class="btn btn-sm btn-link mb-2">← Danh sách</a>
    <div class="row g-3">
        <div class="col-lg-4">
            <div class="card card-body mb-3">
                <table class="table table-sm mb-0">
                    <tr><th>Telegram ID</th><td><code><?= h($u['tg_id']) ?></code></td></tr>
                    <tr><th>Username</th><td><?= $u['username'] ? '<a href="https://t.me/' . h($u['username']) . '" target="_blank">@' . h($u['username']) . '</a>' : '-' ?></td></tr>
                    <tr><th>Tên</th><td><?= h($u['first_name']) ?></td></tr>
                    <tr><th>Số dư</th><td class="fw-bold"><?= money($u['balance']) ?></td></tr>
                    <tr><th>Tổng nạp</th><td><?= money($u['total_deposit']) ?></td></tr>
                    <tr><th>Tổng chi</th><td><?= money($u['total_spent']) ?></td></tr>
                    <tr><th>Mã nạp</th><td><code><?= h(Settings::get('deposit_prefix') . $u['deposit_code']) ?></code></td></tr>
                    <tr><th>Người giới thiệu</th><td><?= h($u['ref_by'] ?: '-') ?></td></tr>
                    <tr><th>Đã giới thiệu</th><td><?= $refs ?> (<?= money($u['ref_earned']) ?>)</td></tr>
                    <tr><th>Tham gia</th><td><?= h($u['created_at']) ?></td></tr>
                    <tr><th>Hoạt động</th><td><?= h($u['last_active']) ?></td></tr>
                    <tr><th>Trạng thái</th><td><?= $u['is_banned'] ? '<span class="badge text-bg-danger">Bị khóa</span>' : '<span class="badge text-bg-success">Hoạt động</span>' ?></td></tr>
                </table>
            </div>
            <form method="post" class="card card-body mb-3">
                <?= csrf_field() ?><input type="hidden" name="id" value="<?= $u['id'] ?>"><input type="hidden" name="action" value="balance">
                <h6>Cộng / trừ tiền</h6>
                <div class="input-group input-group-sm mb-2">
                    <select name="op" class="form-select" style="max-width:70px"><option>+</option><option>-</option></select>
                    <input name="amount" class="form-control" placeholder="Số tiền" required>
                </div>
                <input name="note" class="form-control form-control-sm mb-2" placeholder="Ghi chú (gửi kèm cho user)">
                <label class="small mb-2"><input type="checkbox" name="count_deposit"> Tính vào tổng nạp</label>
                <button class="btn btn-sm btn-primary">Xác nhận</button>
            </form>
            <form method="post" class="card card-body mb-3">
                <?= csrf_field() ?><input type="hidden" name="id" value="<?= $u['id'] ?>"><input type="hidden" name="action" value="message">
                <h6>Nhắn tin cho user</h6>
                <textarea name="text" class="form-control form-control-sm mb-2" rows="3" required></textarea>
                <button class="btn btn-sm btn-outline-primary">Gửi</button>
            </form>
            <form method="post"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $u['id'] ?>">
                <button name="action" value="ban" class="btn btn-sm <?= $u['is_banned'] ? 'btn-success' : 'btn-danger' ?>"><?= $u['is_banned'] ? 'Mở khóa' : 'Khóa người dùng' ?></button>
            </form>
        </div>
        <div class="col-lg-8">
            <div class="card card-body mb-3"><h6>Biến động số dư</h6><div class="table-responsive"><table class="table table-sm">
                <tr><th>Thời gian</th><th>Loại</th><th class="text-end">Số tiền</th><th class="text-end">Số dư sau</th><th>Ghi chú</th></tr>
                <?php foreach ($tx as $t): ?><tr><td><?= h($t['created_at']) ?></td><td><?= h($t['type']) ?></td>
                    <td class="text-end <?= $t['amount'] >= 0 ? 'text-success' : 'text-danger' ?>"><?= ($t['amount'] >= 0 ? '+' : '') . money($t['amount']) ?></td>
                    <td class="text-end"><?= money($t['balance_after']) ?></td><td><?= h($t['note']) ?></td></tr><?php endforeach; ?>
            </table></div></div>
            <div class="card card-body"><h6>Đơn thuê</h6><div class="table-responsive"><table class="table table-sm">
                <tr><th>#</th><th>Dịch vụ</th><th>SĐT</th><th>OTP</th><th>Giá</th><th>Trạng thái</th><th>Thời gian</th></tr>
                <?php foreach ($orders as $o): ?><tr><td><?= $o['id'] ?></td><td><?= h($o['service_name']) ?></td><td><?= h($o['phone']) ?></td><td><code><?= h($o['otp']) ?></code></td><td><?= money($o['price']) ?></td><td><?= status_badge($o['status']) ?></td><td><?= h($o['created_at']) ?></td></tr><?php endforeach; ?>
            </table></div></div>
        </div>
    </div>
    <?php
    layout_end();
    exit;
}

$q = trim($_GET['q'] ?? '');
$sort = in_array($_GET['sort'] ?? '', ['balance', 'total_deposit', 'total_spent', 'id'], true) ? $_GET['sort'] : 'id';
$page = max(1, (int)($_GET['p'] ?? 1));
$per = 50;
$where = $q !== '' ? 'WHERE CAST(tg_id AS CHAR) = ? OR LOWER(username) LIKE ? OR LOWER(first_name) LIKE ? OR deposit_code = ?' : '';
$params = $q !== '' ? [$q, '%' . mb_strtolower(ltrim($q, '@')) . '%', '%' . mb_strtolower($q) . '%', strtoupper(str_ireplace((string)Settings::get('deposit_prefix'), '', $q))] : [];
$total = (int)DB::value("SELECT COUNT(*) FROM users $where", $params);
$rows = DB::all("SELECT * FROM users $where ORDER BY $sort DESC LIMIT $per OFFSET " . (($page - 1) * $per), $params);

layout_start('Người dùng (' . number_format($total) . ')');
?>
<form class="d-flex flex-wrap gap-2 mb-3">
    <input name="q" value="<?= h($q) ?>" class="form-control form-control-sm" style="max-width:300px" placeholder="Tìm ID, username, tên, mã nạp">
    <select name="sort" class="form-select form-select-sm" style="max-width:200px">
        <?php foreach (['id' => 'Mới nhất', 'balance' => 'Số dư', 'total_deposit' => 'Tổng nạp', 'total_spent' => 'Tổng chi'] as $k => $l): ?>
            <option value="<?= $k ?>" <?= $sort === $k ? 'selected' : '' ?>><?= $l ?></option>
        <?php endforeach; ?>
    </select>
    <button class="btn btn-sm btn-secondary">Lọc</button>
</form>
<div class="card card-body"><div class="table-responsive">
<table class="table table-sm table-hover">
    <tr><th>ID TG</th><th>Username</th><th>Tên</th><th class="text-end">Số dư</th><th class="text-end">Tổng nạp</th><th class="text-end">Tổng chi</th><th>Tham gia</th><th></th></tr>
    <?php foreach ($rows as $u): ?>
        <tr class="<?= $u['is_banned'] ? 'table-danger' : '' ?>">
            <td><code><?= h($u['tg_id']) ?></code></td><td><?= $u['username'] ? '@' . h($u['username']) : '' ?></td><td><?= h($u['first_name']) ?></td>
            <td class="text-end fw-semibold"><?= money($u['balance']) ?></td><td class="text-end"><?= money($u['total_deposit']) ?></td><td class="text-end"><?= money($u['total_spent']) ?></td>
            <td><?= h(substr((string)$u['created_at'], 0, 10)) ?></td><td><a href="?id=<?= $u['id'] ?>" class="btn btn-sm btn-outline-primary">Chi tiết</a></td>
        </tr>
    <?php endforeach; ?>
</table>
</div>
<?= paginate($total, $per, $page, ['q' => $q, 'sort' => $sort]) ?>
</div>
<?php layout_end();
