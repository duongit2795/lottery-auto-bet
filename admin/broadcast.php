<?php
require __DIR__ . '/_init.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $msg = trim((string)($_POST['message'] ?? ''));
    if ($msg !== '') {
        if (isset($_POST['test'])) {
            $tg = new Telegram();
            foreach (Settings::adminIds() as $id) {
                $tg->send($id, $msg);
            }
            flash('Đã gửi thử tới admin.');
        } else {
            ignore_user_abort(true);
            set_time_limit(0);
            [$ok, $fail] = Bot::broadcast($msg);
            flash("Đã gửi thông báo: thành công $ok, lỗi $fail.");
        }
    }
    redirect('broadcast.php');
}

$history = DB::all('SELECT * FROM broadcasts ORDER BY id DESC LIMIT 20');
$count = (int)DB::value('SELECT COUNT(*) FROM users WHERE is_banned = 0');

layout_start('Gửi thông báo');
?>
<form method="post" class="card card-body mb-4">
    <?= csrf_field() ?>
    <label class="form-label">Nội dung (HTML Telegram: &lt;b&gt;, &lt;i&gt;, &lt;code&gt;, &lt;a href=""&gt;)</label>
    <textarea name="message" rows="6" class="form-control mb-2" required></textarea>
    <div class="d-flex gap-2">
        <button name="test" value="1" class="btn btn-outline-secondary">Gửi thử cho admin</button>
        <button class="btn btn-primary" onclick="return confirm('Gửi tới <?= $count ?> người dùng?')">📢 Gửi tới <?= $count ?> người dùng</button>
    </div>
</form>
<div class="card card-body">
    <h6>Lịch sử</h6>
    <table class="table table-sm">
        <tr><th>Thời gian</th><th>Nội dung</th><th>Thành công</th><th>Lỗi</th></tr>
        <?php foreach ($history as $b): ?><tr><td class="small"><?= h($b['created_at']) ?></td><td class="small"><?= nl2br(h(mb_substr($b['message'], 0, 200))) ?></td><td><?= $b['sent'] ?></td><td><?= $b['failed'] ?></td></tr><?php endforeach; ?>
    </table>
</div>
<?php layout_end();
