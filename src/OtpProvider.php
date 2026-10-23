<?php
declare(strict_types=1);

/**
 * Client cho API thuê sim (BossOTP). Endpoint và cách đọc JSON trả về đều
 * cấu hình được trong trang admin (mục "API nhà cung cấp"), nên có thể khớp
 * với tài liệu https://bossotp.net/rent-api mà không phải sửa code.
 *
 * Cú pháp endpoint: "METHOD /duong-dan?tham_so={bien}"
 *   Biến hỗ trợ: {api_key} {service_id} {order_id}
 */
final class OtpProvider
{
    public function request(string $endpointKey, array $vars = []): array
    {
        $spec = trim((string)Settings::get('api_ep_' . $endpointKey));
        if ($spec === '') {
            return ['ok' => false, 'message' => "Chưa cấu hình endpoint $endpointKey", 'data' => [], 'raw' => '', 'http' => 0];
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
        $base = strtok($url, '?');

        $headers = ['Accept: application/json'];
        $mode = Settings::get('api_auth_mode');
        $keyParam = (string)Settings::get('api_key_param') ?: 'api_key';
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
            return ['ok' => false, 'message' => 'Lỗi kết nối nhà cung cấp: ' . $err, 'data' => [], 'raw' => '', 'http' => 0];
        }
        app_log('provider', "$endpointKey [$http] " . mb_substr($raw, 0, 1000));

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return ['ok' => false, 'message' => 'Phản hồi không phải JSON', 'data' => [], 'raw' => $raw, 'http' => $http];
        }
        $msg = array_path($data, (string)Settings::get('api_map_message'));
        return [
            'ok' => $http < 400 && $this->isSuccess($data),
            'message' => is_scalar($msg) ? (string)$msg : '',
            'data' => $data,
            'raw' => $raw,
            'http' => $http,
        ];
    }

    private function isSuccess(array $data): bool
    {
        $v = array_path($data, (string)Settings::get('api_map_success'));
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
        return array_path($data, (string)Settings::get('api_map_' . $key));
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

    /** @return array{ok:bool,message:string,order_id:?string,phone:?string} */
    public function rent(string $serviceId): array
    {
        $r = $this->request('rent', ['service_id' => $serviceId]);
        $orderId = $this->map($r['data'], 'order_id');
        $phone = $this->map($r['data'], 'phone');
        $ok = $r['ok'] && $orderId !== null && $phone !== null;
        return [
            'ok' => $ok,
            'message' => $ok ? '' : ($r['message'] ?: 'Không thuê được số, vui lòng thử lại'),
            'order_id' => $orderId !== null ? (string)$orderId : null,
            'phone' => $phone !== null ? (string)$phone : null,
        ];
    }

    /** @return array{ok:bool,otp:?string,sms:?string,expired:bool,message:string} */
    public function check(string $orderId): array
    {
        $r = $this->request('check', ['order_id' => $orderId]);
        $otp = $this->map($r['data'], 'otp');
        $sms = $this->map($r['data'], 'sms');
        $status = strtolower((string)($this->map($r['data'], 'status') ?? ''));
        $expiredList = array_map('trim', explode('|', strtolower((string)Settings::get('api_status_expired'))));
        return [
            'ok' => $r['ok'],
            'otp' => is_scalar($otp) && $otp !== '' ? (string)$otp : null,
            'sms' => is_scalar($sms) && $sms !== '' ? (string)$sms : null,
            'expired' => $status !== '' && in_array($status, $expiredList, true),
            'message' => $r['message'],
        ];
    }

    public function cancel(string $orderId): array
    {
        $r = $this->request('cancel', ['order_id' => $orderId]);
        return ['ok' => $r['ok'], 'message' => $r['message']];
    }
}
