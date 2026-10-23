<?php
require __DIR__ . '/_init.php';
require_login();

$today = date('Y-m-d 00:00:00');
$month = date('Y-m-01 00:00:00');
$sum = fn(string $col, string $from) => (float)DB::value("SELECT COALESCE(SUM($col),0) FROM orders WHERE status = 'success' AND created_at >= ?", [$from]);

$stats = [
    ['Người dùng', number_format((int)DB::value('SELECT COUNT(*) FROM users')), 'primary'],
    ['Đơn thành công hôm nay', number_format((int)DB::value("SELECT COUNT(*) FROM orders WHERE status = 'success' AND created_at >= ?", [$today])), 'success'],
    ['Doanh thu hôm nay', money($sum('price', $today)), 'success'],
    ['Lợi nhuận hôm nay', money($sum('price - cost', $today)), 'success'],
    ['Doanh thu tháng', money($sum('price', $month)), 'info'],
    ['Lợi nhuận tháng', money($sum('price - cost', $month)), 'info'],
    ['Tin nhắn gửi hôm nay', number_format((int)DB::value("SELECT COUNT(*) FROM sms_jobs WHERE status = 'sent' AND updated_at >= ?", [$today])), 'primary'],
    ['Bán gói tin hôm nay', money(-(float)DB::value("SELECT COALESCE(SUM(amount),0) FROM transactions WHERE type = 'package' AND created_at >= ?", [$today])), 'primary'],
    ['Nạp tiền hôm nay', money(DB::value("SELECT COALESCE(SUM(amount),0) FROM deposits WHERE status = 'approved' AND updated_at >= ?", [$today])), 'warning'],
    ['Tổng số dư người dùng', money(DB::value('SELECT COALESCE(SUM(balance),0) FROM users')), 'danger'],
];
$providerBalance = Settings::get('api_key') ? (new OtpProvider())->balance() : null;

// Doanh thu 14 ngày
$chart = [];
for ($i = 13; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-$i day"));
    $chart[$d] = (float)DB::value("SELECT COALESCE(SUM(price),0) FROM orders WHERE status = 'success' AND created_at >= ? AND created_at < ?", [$d . ' 00:00:00', date('Y-m-d', strtotime($d . ' +1 day')) . ' 00:00:00']);
}
$max = max(1, max($chart));

$top = DB::all("SELECT service_name, COUNT(*) c, SUM(price) s FROM orders WHERE status = 'success' AND created_at >= ? GROUP BY service_name ORDER BY c DESC LIMIT 8", [$month]);
$recent = DB::all('SELECT o.*, u.tg_id, u.username FROM orders o LEFT JOIN users u ON u.id = o.user_id ORDER BY o.id DESC LIMIT 10');

layout_start('Tổng quan');
?>
<?php if (!Settings::get('bot_token') || !Settings::get('api_key')): ?>
    <div class="alert alert-warning">⚠️ Chưa cấu hình đủ <b>Bot token</b> / <b>API key</b>. Vào <a href="settings.php">Cài đặt</a> và <a href="api.php">API nhà cung cấp</a>.</div>
<?php endif; ?>
<div class="row g-3 mb-4">
    <?php foreach ($stats as [$label, $val, $color]): ?>
        <div class="col-6 col-lg-3"><div class="card card-body border-start border-4 border-<?= $color ?>">
            <div class="text-muted small"><?= h($label) ?></div><div class="stat"><?= h($val) ?></div>
        </div></div>
    <?php endforeach; ?>
    <div class="col-6 col-lg-3"><div class="card card-body border-start border-4 border-dark">
        <div class="text-muted small">Số dư tài khoản API</div>
        <div class="stat"><?= $providerBalance === null ? '<span class="text-danger fs-6">Không lấy được</span>' : money($providerBalance) ?></div>
    </div></div>
    <div class="col-6 col-lg-3"><div class="card card-body border-start border-4 border-warning">
        <div class="text-muted small">Đơn đang chờ OTP / Nạp chờ duyệt</div>
        <div class="stat"><?= (int)DB::value("SELECT COUNT(*) FROM orders WHERE status = 'pending'") ?> / <?= (int)DB::value("SELECT COUNT(*) FROM deposits WHERE status = 'pending'") ?></div>
    </div></div>
</div>

<div class="row g-3">
    <div class="col-lg-8"><div class="card card-body">
        <h6>Doanh thu 14 ngày</h6>
        <div class="d-flex align-items-end gap-1" style="height:180px">
            <?php foreach ($chart as $d => $v): ?>
                <div class="flex-fill text-center" title="<?= h($d . ': ' . money($v)) ?>">
                    <div class="bg-primary rounded-top" style="height:<?= max(2, (int)($v / $max * 150)) ?>px"></div>
                    <div class="small text-muted"><?= date('d/m', strtotime($d)) ?></div>
                </div>
            <?php endforeach; ?>
        </div>
    </div></div>
    <div class="col-lg-4"><div class="card card-body">
        <h6>Dịch vụ bán chạy (tháng)</h6>
        <table class="table table-sm mb-0">
            <?php foreach ($top as $t): ?><tr><td><?= h($t['service_name']) ?></td><td class="text-end"><?= (int)$t['c'] ?></td><td class="text-end"><?= money($t['s']) ?></td></tr><?php endforeach; ?>
            <?php if (!$top): ?><tr><td class="text-muted">Chưa có dữ liệu</td></tr><?php endif; ?>
        </table>
    </div></div>
</div>

<div class="card card-body mt-3">
    <h6>Đơn mới nhất</h6>
    <div class="table-responsive"><table class="table table-sm table-hover">
        <tr><th>#</th><th>User</th><th>Dịch vụ</th><th>SĐT</th><th>OTP</th><th>Giá</th><th>Trạng thái</th><th>Thời gian</th></tr>
        <?php foreach ($recent as $o): ?>
            <tr><td><?= $o['id'] ?></td><td><?= h($o['username'] ? '@' . $o['username'] : $o['tg_id']) ?></td><td><?= h($o['service_name']) ?></td>
            <td><?= h($o['phone']) ?></td><td><code><?= h($o['otp']) ?></code></td><td><?= money($o['price']) ?></td><td><?= status_badge($o['status']) ?></td><td><?= h($o['created_at']) ?></td></tr>
        <?php endforeach; ?>
    </table></div>
</div>
<?php layout_end();
