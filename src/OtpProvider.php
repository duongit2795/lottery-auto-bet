<?php
declare(strict_types=1);

/**
 * Client cho API thuê sim BossOTP (v4). Endpoint và cách đọc JSON trả về đều
 * cấu hình được trong trang admin (mục "API nhà cung cấp").
 *
 * Cú pháp endpoint: "METHOD /duong-dan?tham_so={bien}"
 *   Biến hỗ trợ: {api_key} {service_id} {order_id}
 */
final class OtpProvider
{
    public const NETWORKS = ['VIETTEL', 'MOBIFONE', 'VINAPHONE', 'VIETNAMOBILE', 'GMOBILE'];

    /** Mã lỗi BossOTP → thông báo cho khách (không lộ chuyện số dư của shop). */
    private const ERRORS = [
        'TOO_MANY_REQUEST' => 'Hệ thống đang bận, vui lòng thử lại sau ít phút.',
        'NOT_ENOUGH_BALANCE' => 'Hệ thống tạm thời gián đoạn, vui lòng liên hệ admin.',
        'NO_NUMBER_AVAILABLE' => 'Hiện đã hết số cho dịch vụ/nhà mạng này, vui lòng thử lại sau hoặc chọn nhà mạng khác.',
        'NOT_ONLINE' => 'Số muốn thuê lại hiện không online.',
        'LOCKING' => 'Số muốn thuê lại đang bị khóa.',
        'SERVICE_NOT_FOUND' => 'Dịch vụ không tồn tại.',
        'NOT_OWNER' => 'Số thuê lại phải là số bạn đã thuê trước đó.',
        'RENT_NOT_FOUND' => 'Không tìm thấy yêu cầu thuê.',
        'RENT_SENT_SMS' => 'Đơn này đã gửi SMS rồi (mỗi đơn chỉ gửi được 1 lần).',
        'RENT_NOT_PENDING' => 'Đơn không còn ở trạng thái chờ.',
        'TOO_CLOSE_RENT_WILL_FINISH' => 'Đơn sắp hết hạn (còn dưới 90 giây), không thể gửi.',
        'UNDEFINED_ERROR_234' => 'Lỗi không xác định từ nhà cung cấp.',
    ];

    public function request(string $endpointKey, array $vars = [], array $extra = []): array
    {
        $spec = trim((string)Settings::get('api_ep_' . $endpointKey));
        if ($spec === '') {
            return ['ok' => false, 'code' => '', 'message' => "Chưa cấu hình endpoint $endpointKey", 'data' => [], 'raw' => '', 'http' => 0];
        }
        [$method, $path] = array_pad(preg_split('/\s+/', $spec, 2), 2, '');
        if ($path === '') {
            [$method, $path] = ['GET', $method];
        }
        $method = strtoupper($method);

        $apiKey = (string)Settings::get('api_key');
        $vars['api_key'] = $apiKey;
        $path = preg_replace_callback('/\{(\w+)\}/', fn($m) => rawurlencode((string)($vars[$m[1]] ?? '')), $path);

        $url = str_starts_with($path, 'http') ? $path : rtrim((string)Settings::get('api_base_url'), '/') . '/' . ltrim($path, '/');
        $parts = parse_url($url);
        parse_str($parts['query'] ?? '', $query);
        $query = array_merge($query, array_filter($extra, fn($v) => $v !== null && $v !== ''));
        $base = strtok($url, '?');

        $headers = ['Accept: application/json'];
        $mode = Settings::get('api_auth_mode');
        $keyParam = (string)Settings::get('api_key_param') ?: 'api_token';
        if ($mode === 'bearer') {
            $headers[] = 'Authorization: Bearer ' . $apiKey;
        } elseif ($mode === 'header') {
            $headers[] = $keyParam . ': ' . $apiKey;
        } elseif (!isset($query[$keyParam])) {
            $query[$keyParam] = $apiKey;
        }

        $ch = curl_init();
        if ($method === 'GET') {
            curl_setopt($ch, CURLOPT_URL, $base . ($query ? '?' . http_build_query($query) : ''));
        } else {
            curl_setopt($ch, CURLOPT_URL, $base);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($query));
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_USERAGENT => 'SimOtpBot/1.0',
        ]);
        $raw = curl_exec($ch);
        $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            app_log('provider', "$endpointKey curl error: $err");
            return ['ok' => false, 'code' => '', 'message' => 'Lỗi kết nối nhà cung cấp: ' . $err, 'data' => [], 'raw' => '', 'http' => 0];
        }
        app_log('provider', "$endpointKey [$http] " . mb_substr($raw, 0, 1000));

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            // send-sms trả HTTP 200 có thể không kèm JSON
            $ok = $http >= 200 && $http < 300;
            return ['ok' => $ok, 'code' => '', 'message' => $ok ? '' : 'Phản hồi không hợp lệ (HTTP ' . $http . ')', 'data' => [], 'raw' => $raw, 'http' => $http];
        }
        $code = is_scalar($data['code'] ?? null) ? (string)$data['code'] : '';
        $msg = array_path($data, (string)Settings::get('api_map_message'));
        if (!is_scalar($msg) && $http >= 400) {
            // Lỗi tham số: {"tham_so_loi": "mô tả lỗi"}
            $msg = implode('; ', array_map(fn($k, $v) => "$k: " . (is_scalar($v) ? $v : json_encode($v)), array_keys($data), $data));
        }
        return [
            'ok' => $http < 400 && $this->isSuccess($data),
            'code' => $code,
            'message' => is_scalar($msg) ? (string)$msg : '',
            'data' => $data,
            'raw' => $raw,
            'http' => $http,
        ];
    }

    /** Thông báo lỗi thân thiện cho khách. */
    public static function friendlyError(array $r, string $fallback): string
    {
        if ($r['code'] !== '' && isset(self::ERRORS[$r['code']])) {
            return self::ERRORS[$r['code']];
        }
        return $r['message'] !== '' && $r['http'] < 500 && $r['http'] > 0 ? $fallback . ' (' . $r['message'] . ')' : $fallback;
    }

    private function isSuccess(array $data): bool
    {
        $path = (string)Settings::get('api_map_success');
        $v = $path === '' ? null : array_path($data, $path);
        if ($v === null) {
            return true; // không có cờ thành công → dựa vào HTTP code
        }
        if (is_bool($v)) {
            return $v;
        }
        $s = strtolower(trim((string)$v));
        return in_array($s, ['1', 'true', 'ok', 'success', 'succeeded', '200'], true);
    }

    private function map(array $data, string $key)
    {
        $path = (string)Settings::get('api_map_' . $key);
        return $path === '' ? null : array_path($data, $path);
    }

    private function phone($raw): ?string
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        $p = (string)$raw;
        if (Settings::get('api_phone_add_zero') === '1' && !str_starts_with($p, '0') && !str_starts_with($p, '+') && strlen($p) === 9) {
            $p = '0' . $p;
        }
        return $p;
    }

    public function balance(): ?float
    {
        $r = $this->request('balance');
        if (!$r['ok']) {
            return null;
        }
        $b = $this->map($r['data'], 'balance');
        return is_numeric($b) ? (float)$b : null;
    }

    /** @return array{ok:bool,message:string,services:array<int,array{id:string,name:string,price:float}>} */
    public function services(): array
    {
        $r = $this->request('services');
        if (!$r['ok']) {
            return ['ok' => false, 'message' => $r['message'] ?: 'Lỗi lấy danh sách dịch vụ', 'services' => []];
        }
        $list = $this->map($r['data'], 'services_list');
        if (!is_array($list)) {
            $list = array_is_list($r['data']) ? $r['data'] : [];
        }
        $out = [];
        foreach ($list as $key => $item) {
            if (!is_array($item)) {
                continue;
            }
            $id = $this->map($item, 'service_id') ?? (is_string($key) ? $key : null);
            $name = $this->map($item, 'service_name');
            if ($id === null || $name === null) {
                continue;
            }
            $price = $this->map($item, 'service_price');
            $out[] = ['id' => (string)$id, 'name' => (string)$name, 'price' => is_numeric($price) ? (float)$price : 0.0];
        }
        return ['ok' => true, 'message' => '', 'services' => $out];
    }

    /**
     * Thuê số. $opts: network, prefixs, exclude_prefixs, re_number.
     * @return array{ok:bool,code:string,message:string,order_id:?string,phone:?string}
     */
    public function rent(string $serviceId, array $opts = []): array
    {
        $extra = [
            'network' => in_array($opts['network'] ?? '', self::NETWORKS, true) ? $opts['network'] : null,
            'prefixs' => $opts['prefixs'] ?? null,
            'exclude_prefixs' => $opts['exclude_prefixs'] ?? null,
            're_number' => isset($opts['re_number']) ? ltrim((string)$opts['re_number'], '0') : null,
            'language' => Settings::get('api_language') ?: null,
        ];
        $r = $this->request('rent', ['service_id' => $serviceId], $extra);
        $orderId = $this->map($r['data'], 'order_id');
        $phone = $this->phone($this->map($r['data'], 'phone'));
        $ok = $r['ok'] && $orderId !== null && $phone !== null;
        return [
            'ok' => $ok,
            'code' => $r['code'],
            'message' => $ok ? '' : self::friendlyError($r, 'Không thuê được số, vui lòng thử lại'),
            'order_id' => $orderId !== null ? (string)$orderId : null,
            'phone' => $phone,
        ];
    }

    /** @return array{ok:bool,otp:?string,sms:?string,success:bool,expired:bool,cost:?float,call_logs:array,message:string} */
    public function check(string $orderId): array
    {
        $r = $this->request('check', ['order_id' => $orderId]);
        $otp = $this->map($r['data'], 'otp');
        $sms = $this->map($r['data'], 'sms');
        $cost = $this->map($r['data'], 'cost');
        $calls = $this->map($r['data'], 'call_logs');
        $status = strtolower((string)($this->map($r['data'], 'status') ?? ''));
        $list = fn(string $k) => array_filter(array_map('trim', explode('|', strtolower((string)Settings::get($k)))));
        return [
            'ok' => $r['ok'],
            'otp' => is_scalar($otp) && $otp !== '' ? (string)$otp : null,
            'sms' => is_scalar($sms) && $sms !== '' ? (string)$sms : null,
            'success' => $status !== '' && in_array($status, $list('api_status_success'), true),
            // RENT_NOT_FOUND cũng coi như đơn đã hết hiệu lực
            'expired' => ($status !== '' && in_array($status, $list('api_status_expired'), true)) || $r['code'] === 'RENT_NOT_FOUND',
            'cost' => is_numeric($cost) ? (float)$cost : null,
            'call_logs' => is_array($calls) ? $calls : [],
            'message' => $r['message'],
        ];
    }

    /**
     * Gửi SMS / chuyển tiếp cuộc gọi cho đơn đang chờ (mỗi đơn 1 lần).
     * $params: [] (SMS cố định) | ['sms'=>..., 'number_receiver'=>...] | ['number_forward_call'=>...]
     */
    public function sendSms(string $orderId, array $params = []): array
    {
        $r = $this->request('send_sms', ['order_id' => $orderId], $params);
        return ['ok' => $r['ok'], 'message' => $r['ok'] ? '' : self::friendlyError($r, 'Gửi yêu cầu thất bại')];
    }

    public function canCancel(): bool
    {
        return trim((string)Settings::get('api_ep_cancel')) !== '';
    }

    public function cancel(string $orderId): array
    {
        if (!$this->canCancel()) {
            return ['ok' => true, 'message' => ''];
        }
        $r = $this->request('cancel', ['order_id' => $orderId]);
        return ['ok' => $r['ok'], 'message' => $r['message']];
    }
}
