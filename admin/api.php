<?php
require __DIR__ . '/_init.php';
require_login();

$fields = [
    'Kết nối' => [
        ['api_base_url', 'Base URL', 'vd: https://bossotp.net/api'],
        ['api_key', 'API key', 'Lấy trong trang tài khoản BossOTP'],
        ['api_key_param', 'Tên tham số API key', 'vd: api_key, token, apikey'],
        ['api_auth_mode', 'Cách gửi API key', 'query = trên URL/body, bearer = header Authorization: Bearer, header = header có tên ở trên'],
        ['api_language', 'Ngôn ngữ cuộc gọi (language)', 'vn hoặc en — bắt buộc với dịch vụ trả OTP bằng cuộc gọi'],
        ['api_phone_add_zero', 'Thêm số 0 đầu SĐT', '1 = có (BossOTP trả số không có số 0 đầu), 0 = không'],
    ],
    'Endpoint (dạng "METHOD /đường-dẫn?tham_số={biến}", biến: {api_key} {service_id} {order_id})' => [
        ['api_ep_balance', 'Lấy số dư', ''],
        ['api_ep_services', 'Danh sách dịch vụ', ''],
        ['api_ep_rent', 'Thuê số', ''],
        ['api_ep_check', 'Kiểm tra OTP', ''],
        ['api_ep_send_sms', 'Gửi SMS / chuyển tiếp cuộc gọi', 'Bot tự thêm sms, number_receiver hoặc number_forward_call khi cần'],
        ['api_ep_cancel', 'Hủy số', 'BossOTP không có — để trống'],
    ],
    'Ánh xạ JSON trả về (đường dẫn dạng a.b.0.c, nhiều lựa chọn cách nhau "|")' => [
        ['api_map_success', 'Cờ thành công', 'Giá trị true/1/"success"/"ok"/200 được coi là thành công'],
        ['api_map_message', 'Thông báo lỗi', ''],
        ['api_map_balance', 'Số dư', ''],
        ['api_map_services_list', 'Mảng danh sách dịch vụ', ''],
        ['api_map_service_id', 'ID dịch vụ (trong mỗi phần tử)', ''],
        ['api_map_service_name', 'Tên dịch vụ (trong mỗi phần tử)', ''],
        ['api_map_service_price', 'Giá gốc (trong mỗi phần tử)', ''],
        ['api_map_service_timeout', 'Thời gian thuê (trong mỗi phần tử)', 'Mặc định: timeout'],
        ['api_timeout_unit', 'Đơn vị thời gian thuê', 'ms = mili giây (180000 = 3 phút), s = giây, min = phút. Xem cột Thời gian ở trang Dịch vụ để kiểm tra'],
        ['api_map_order_id', 'ID đơn thuê', ''],
        ['api_map_phone', 'Số điện thoại', ''],
        ['api_map_otp', 'Mã OTP', ''],
        ['api_map_sms', 'Nội dung SMS', ''],
        ['api_map_status', 'Trạng thái đơn', ''],
        ['api_map_cost', 'Giá gốc thực tế của đơn', 'Dùng để tính lợi nhuận chính xác'],
        ['api_map_call_logs', 'Danh sách cuộc gọi đến', ''],
        ['api_status_success', 'Các trạng thái = thành công', 'Phân tách "|"'],
        ['api_status_expired', 'Các trạng thái = hết hạn/hủy', 'Phân tách "|". Gặp trạng thái này bot sẽ hoàn tiền'],
    ],
];

$test = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    if (($_POST['action'] ?? '') === 'save') {
        foreach ($fields as $group) {
            foreach ($group as [$key]) {
                if (array_key_exists($key, $_POST)) {
                    Settings::set($key, trim((string)$_POST[$key]));
                }
            }
        }
        flash('Đã lưu cấu hình API.');
        redirect('api.php');
    }
    if (($_POST['action'] ?? '') === 'reset') {
        foreach ($fields as $group) {
            foreach ($group as [$key]) {
                if ($key !== 'api_key') {
                    Settings::set($key, Settings::defaults()[$key]);
                }
            }
        }
        flash('Đã khôi phục cấu hình mặc định (giữ nguyên API key).');
        redirect('api.php');
    }
    $p = new OtpProvider();
    $ep = $_POST['test'] ?? '';
    $vars = ['service_id' => trim($_POST['service_id'] ?? ''), 'order_id' => trim($_POST['order_id'] ?? '')];
    if (in_array($ep, ['balance', 'services', 'rent', 'check', 'send_sms', 'cancel'], true)) {
        $extra = $ep === 'rent' ? ['network' => $_POST['network'] ?? '', 'language' => Settings::get('api_language')] : [];
        $test = ['ep' => $ep, 'res' => $p->request($ep, $vars, $extra)];
        if ($ep === 'balance') {
            $test['parsed'] = ['balance' => $p->balance()];
        } elseif ($ep === 'services') {
            $s = $p->services();
            $test['parsed'] = ['count' => count($s['services']), 'first' => array_slice($s['services'], 0, 5)];
        } elseif ($ep === 'check') {
            $test['parsed'] = $p->check($vars['order_id']);
        }
    }
}

layout_start('API nhà cung cấp (BossOTP)');
?>
<div class="alert alert-info small">
    Tài liệu API: <a href="https://bossotp.net/rent-api" target="_blank" rel="noopener">bossotp.net/rent-api</a>.
    Nếu tài liệu khác với cấu hình mặc định, sửa endpoint &amp; ánh xạ JSON bên dưới rồi dùng các nút <b>Test</b> để xem phản hồi thật — không cần sửa code.
</div>

<div class="card card-body mb-4">
    <h6>🧪 Test API</h6>
    <form method="post" class="row g-2 align-items-end">
        <?= csrf_field() ?>
        <div class="col-md-3"><label class="form-label small">service_id (_id)</label><input name="service_id" class="form-control form-control-sm" value="<?= h($_POST['service_id'] ?? '') ?>"></div>
        <div class="col-md-2"><label class="form-label small">order_id (rent_id)</label><input name="order_id" class="form-control form-control-sm" value="<?= h($_POST['order_id'] ?? '') ?>"></div>
        <div class="col-md-2"><label class="form-label small">network</label><select name="network" class="form-select form-select-sm"><option value="">Bất kỳ</option><?php foreach (OtpProvider::NETWORKS as $n): ?><option <?= ($_POST['network'] ?? '') === $n ? 'selected' : '' ?>><?= $n ?></option><?php endforeach; ?></select></div>
        <div class="col-md-5 d-flex flex-wrap gap-1">
            <button name="test" value="balance" class="btn btn-sm btn-outline-primary">Số dư</button>
            <button name="test" value="services" class="btn btn-sm btn-outline-primary">Dịch vụ</button>
            <button name="test" value="rent" class="btn btn-sm btn-outline-warning" onclick="return confirm('Thuê số thật sẽ trừ tiền tài khoản API. Tiếp tục?')">Thuê số</button>
            <button name="test" value="check" class="btn btn-sm btn-outline-primary">Check OTP</button>
            <button name="test" value="send_sms" class="btn btn-sm btn-outline-warning" onclick="return confirm('Mỗi rent_id chỉ gửi SMS được 1 lần. Tiếp tục?')">Gửi SMS cố định</button>
        </div>
    </form>
    <?php if ($test): ?>
        <hr>
        <div class="small mb-1">Endpoint: <b><?= h($test['ep']) ?></b> — HTTP <?= (int)$test['res']['http'] ?> — <?= $test['res']['ok'] ? '<span class="text-success">thành công</span>' : '<span class="text-danger">thất bại: ' . h($test['res']['message']) . '</span>' ?></div>
        <?php if (isset($test['parsed'])): ?>
            <div class="small fw-semibold">Bot đọc được:</div>
            <pre class="bg-light p-2 small"><?= h(json_encode($test['parsed'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?></pre>
        <?php endif; ?>
        <div class="small fw-semibold">Phản hồi gốc:</div>
        <pre class="bg-dark text-light p-2 small" style="max-height:400px;overflow:auto"><?= h(json_encode(json_decode($test['res']['raw'], true), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) ?: $test['res']['raw']) ?></pre>
    <?php endif; ?>
</div>

<form method="post">
    <?= csrf_field() ?>
    <?php foreach ($fields as $title => $group): ?>
        <div class="card card-body mb-4">
            <h6 class="mb-3"><?= h($title) ?></h6>
            <div class="row g-3">
                <?php foreach ($group as [$key, $label, $hint]): ?>
                    <div class="col-md-6">
                        <label class="form-label small fw-semibold"><?= h($label) ?></label>
                        <?php if ($key === 'api_auth_mode'): ?>
                            <select name="<?= $key ?>" class="form-select form-select-sm">
                                <?php foreach (['query', 'bearer', 'header'] as $m): ?><option <?= Settings::get($key) === $m ? 'selected' : '' ?>><?= $m ?></option><?php endforeach; ?>
                            </select>
                        <?php else: ?>
                            <input name="<?= $key ?>" value="<?= h(Settings::get($key)) ?>" class="form-control form-control-sm font-monospace" type="<?= $key === 'api_key' ? 'password' : 'text' ?>" autocomplete="off">
                        <?php endif; ?>
                        <?php if ($hint): ?><div class="form-text"><?= h($hint) ?></div><?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endforeach; ?>
    <button name="action" value="save" class="btn btn-success">💾 Lưu</button>
    <button name="action" value="reset" class="btn btn-outline-secondary" onclick="return confirm('Khôi phục mặc định?')">Khôi phục mặc định</button>
</form>
<?php layout_end();
