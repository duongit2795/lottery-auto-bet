<?php
require __DIR__ . '/_init.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'restart_worker') {
    check_csrf();
    Settings::set('auto_worker', '1');
    @unlink(APP_ROOT . '/data/last_kick');
    $ok = Scheduler::spawn();
    flash($ok ? 'Đã khởi động tiến trình tự động. Tải lại trang sau vài giây để xem trạng thái.' : 'Không khởi động được — xem hướng dẫn trong ô Tự động hóa.', $ok ? 'success' : 'danger');
    redirect('index.php');
}

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
    ['Tin nhắn gửi hôm nay', number_format((int)DB::value('SELECT COUNT(*) FROM sms_sends WHERE ok = 1 AND created_at >= ?', [$today])), 'primary'],
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
$last = Scheduler::lastTick();
$ago = $last ? time() - $last : null;
?>
<?php
$cron = Scheduler::lastCron();
$cronAgo = $cron['time'] ? time() - $cron['time'] : null;
$cronOk = $cronAgo !== null && $cronAgo < 150;
$cronCmd = 'php ' . APP_ROOT . '/cron.php';
$cronUrl = rtrim((string)Settings::get('site_url'), '/') . '/cron.php?secret=' . cfg('secret');
?>
<div class="row g-3 mb-3">
    <div class="col-lg-6">
        <div class="card card-body h-100 border-start border-4 border-<?= Scheduler::alive() ? 'success' : 'danger' ?>">
            <div class="d-flex align-items-center gap-2 mb-1">
                <b>⚙️ Tiến trình tự động</b>
                <?php if (!Scheduler::enabled()): ?><span class="badge text-bg-secondary">Đã tắt</span>
                <?php elseif (Scheduler::alive()): ?><span class="badge text-bg-success">Đang chạy</span>
                <?php else: ?><span class="badge text-bg-danger">Đang dừng</span><?php endif; ?>
                <form method="post" class="ms-auto"><?= csrf_field() ?><button name="action" value="restart_worker" class="btn btn-sm btn-outline-primary">🔄 Khởi động lại</button></form>
            </div>
            <div class="small text-muted">Lần chạy gần nhất: <b><?= $ago === null ? 'chưa chạy' : $ago . ' giây trước' ?></b></div>
            <div class="small text-muted">Tự kiểm tra OTP, hoàn tiền đơn hết hạn, đóng đơn gửi tin, quét nạp tiền — mỗi 5 giây khi có đơn đang chờ. Không cần cron, tự khởi động lại khi có người dùng bot/app.</div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card card-body h-100 border-start border-4 border-<?= $cronOk ? 'success' : ($cronAgo === null ? 'warning' : 'danger') ?>">
            <div class="d-flex align-items-center gap-2 mb-1">
                <b>⏰ Cron (dự phòng)</b>
                <?php if ($cronOk): ?><span class="badge text-bg-success">Đang hoạt động</span>
                <?php elseif ($cronAgo === null): ?><span class="badge text-bg-warning">Chưa cài</span>
                <?php else: ?><span class="badge text-bg-danger">Đã dừng</span><?php endif; ?>
            </div>
            <div class="small text-muted">Lần chạy gần nhất: <b><?= $cronAgo === null ? 'chưa từng chạy' : ($cronAgo < 120 ? $cronAgo . ' giây trước' : round($cronAgo / 60) . ' phút trước') ?></b><?= $cron['via'] ? ' (' . ($cron['via'] === 'cli' ? 'lệnh Shell' : 'gọi URL') . ')' : '' ?></div>
            <?php if (!$cronOk): ?>
                <div class="small mt-1"><?= $cronAgo === null ? 'Nên cài cron để bot vẫn tự chạy cả khi không có ai dùng bot/app.' : '⚠️ Cron đã ngừng chạy hơn 2 phút — kiểm tra lại tác vụ cron trong aaPanel.' ?>
                    <a href="#cronGuide" data-bs-toggle="collapse">Xem hướng dẫn cài</a></div>
            <?php else: ?>
                <div class="small text-muted">Cron chạy mỗi phút, kết hợp tiến trình tự động để không bỏ sót đơn nào.</div>
            <?php endif; ?>
        </div>
    </div>
</div>
<div class="collapse <?= $cronAgo === null ? '' : '' ?> mb-3" id="cronGuide">
    <div class="card card-body small">
        <h6>📘 Cài cron trên aaPanel (làm 1 lần, dùng điện thoại được)</h6>
        <ol class="mb-2">
            <li>Mở aaPanel → menu <b>Cron</b> (Tác vụ định kỳ) → <b>Add Task</b>.</li>
            <li><b>Type of Task</b>: chọn <b>Shell Script</b>.</li>
            <li><b>Name</b>: <code>simbot</code>.</li>
            <li><b>Period</b>: chọn <b>N Minutes</b>, điền <b>1</b> (chạy mỗi phút).</li>
            <li><b>Script content</b>: dán dòng dưới rồi bấm <b>Add Task</b>:
                <div class="input-group input-group-sm mt-1"><input class="form-control font-monospace" readonly value="<?= h($cronCmd) ?>" id="cronCmd"><button class="btn btn-outline-secondary" type="button" onclick="navigator.clipboard.writeText(document.getElementById('cronCmd').value);this.innerText='Đã chép'">Chép</button></div>
                <div class="text-muted">Nếu báo không tìm thấy <code>php</code>, dùng đường dẫn đầy đủ, vd: <code>/www/server/php/83/bin/php <?= h(APP_ROOT) ?>/cron.php</code> (83 = PHP 8.3, đổi theo bản PHP của site).</div>
            </li>
            <li>Bấm <b>Execute</b> ở tác vụ vừa tạo để chạy thử, rồi quay lại trang này: ô Cron chuyển <span class="badge text-bg-success">Đang hoạt động</span> là xong.</li>
        </ol>
        <div><b>Cách khác — không có quyền Shell:</b> chọn Type <b>Access URL</b> (hoặc dùng dịch vụ miễn phí cron-job.org), gọi mỗi phút URL:
            <div class="input-group input-group-sm mt-1"><input class="form-control font-monospace" readonly value="<?= h($cronUrl) ?>" id="cronUrl"><button class="btn btn-outline-secondary" type="button" onclick="navigator.clipboard.writeText(document.getElementById('cronUrl').value);this.innerText='Đã chép'">Chép</button></div>
            <div class="text-danger">Không chia sẻ URL này cho người khác (chứa mã bí mật).</div>
        </div>
    </div>
</div>
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
