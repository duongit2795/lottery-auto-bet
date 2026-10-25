<?php
declare(strict_types=1);

/**
 * Mã QR chuyển khoản VietQR (chuẩn NAPAS 247 / EMVCo).
 *
 * - payload(): tạo chuỗi dữ liệu QR — mọi app ngân hàng VN quét được.
 * - image(): lấy ảnh PNG: thử img.vietqr.io (đẹp, có logo ngân hàng) → nếu lỗi thì tự vẽ QR ngay
 *   trên server (QrEncoder, không cần internet). Ảnh được lưu đệm trong data/qr/ và gửi thẳng
 *   lên Telegram (upload), nên không phụ thuộc việc Telegram tự tải link ảnh.
 */
final class Qr
{
    /** Ngân hàng phổ biến: BIN => tên. Dùng cho ô chọn ngân hàng trong admin. */
    public const BANKS = [
        '970416' => 'ACB', '970422' => 'MB Bank', '970436' => 'Vietcombank', '970407' => 'Techcombank',
        '970415' => 'VietinBank', '970418' => 'BIDV', '970405' => 'Agribank', '970432' => 'VPBank',
        '970423' => 'TPBank', '970403' => 'Sacombank', '970437' => 'HDBank', '970441' => 'VIB',
        '970443' => 'SHB', '970448' => 'OCB', '970426' => 'MSB', '970431' => 'Eximbank',
        '970440' => 'SeABank', '970429' => 'SCB', '970454' => 'Viet Capital Bank', '970449' => 'LPBank',
        '970425' => 'ABBANK', '970427' => 'VietABank', '970428' => 'Nam A Bank', '970400' => 'Saigonbank',
        '970409' => 'Bac A Bank', '970412' => 'PVcomBank', '970419' => 'NCB', '970430' => 'PG Bank',
        '970433' => 'VietBank', '970438' => 'BaoViet Bank', '970452' => 'KienlongBank', '971005' => 'Viettel Money',
        '971011' => 'VNPT Money', '963388' => 'Timo', '546034' => 'CAKE by VPBank', '546035' => 'Ubank by VPBank',
    ];

    /** Bỏ dấu tiếng Việt, viết hoa — VietQR yêu cầu tên chủ tài khoản không dấu. */
    public static function plain(string $s): string
    {
        $map = ['à' => 'a', 'á' => 'a', 'ạ' => 'a', 'ả' => 'a', 'ã' => 'a', 'â' => 'a', 'ầ' => 'a', 'ấ' => 'a', 'ậ' => 'a', 'ẩ' => 'a', 'ẫ' => 'a',
            'ă' => 'a', 'ằ' => 'a', 'ắ' => 'a', 'ặ' => 'a', 'ẳ' => 'a', 'ẵ' => 'a', 'è' => 'e', 'é' => 'e', 'ẹ' => 'e', 'ẻ' => 'e', 'ẽ' => 'e',
            'ê' => 'e', 'ề' => 'e', 'ế' => 'e', 'ệ' => 'e', 'ể' => 'e', 'ễ' => 'e', 'ì' => 'i', 'í' => 'i', 'ị' => 'i', 'ỉ' => 'i', 'ĩ' => 'i',
            'ò' => 'o', 'ó' => 'o', 'ọ' => 'o', 'ỏ' => 'o', 'õ' => 'o', 'ô' => 'o', 'ồ' => 'o', 'ố' => 'o', 'ộ' => 'o', 'ổ' => 'o', 'ỗ' => 'o',
            'ơ' => 'o', 'ờ' => 'o', 'ớ' => 'o', 'ợ' => 'o', 'ở' => 'o', 'ỡ' => 'o', 'ù' => 'u', 'ú' => 'u', 'ụ' => 'u', 'ủ' => 'u', 'ũ' => 'u',
            'ư' => 'u', 'ừ' => 'u', 'ứ' => 'u', 'ự' => 'u', 'ử' => 'u', 'ữ' => 'u', 'ỳ' => 'y', 'ý' => 'y', 'ỵ' => 'y', 'ỷ' => 'y', 'ỹ' => 'y', 'đ' => 'd'];
        $s = strtr(mb_strtolower(trim($s)), $map);
        return strtoupper(preg_replace('/[^a-z0-9 ]/', '', preg_replace('/\s+/', ' ', $s)));
    }

    private static function tlv(string $id, string $value): string
    {
        return $id . str_pad((string)strlen($value), 2, '0', STR_PAD_LEFT) . $value;
    }

    private static function crc16(string $data): string
    {
        $crc = 0xFFFF;
        for ($i = 0, $n = strlen($data); $i < $n; $i++) {
            $crc ^= ord($data[$i]) << 8;
            for ($b = 0; $b < 8; $b++) {
                $crc = ($crc & 0x8000) ? (($crc << 1) ^ 0x1021) & 0xFFFF : ($crc << 1) & 0xFFFF;
            }
        }
        return strtoupper(str_pad(dechex($crc), 4, '0', STR_PAD_LEFT));
    }

    /** Chuỗi dữ liệu VietQR chuyển khoản tới số tài khoản (có sẵn số tiền + nội dung). */
    public static function payload(string $bin, string $account, int $amount, string $memo): string
    {
        $bank = self::tlv('00', $bin) . self::tlv('01', $account);
        $merchant = self::tlv('00', 'A000000727') . self::tlv('01', $bank) . self::tlv('02', 'QRIBFTTA');
        $data = self::tlv('00', '01')
            . self::tlv('01', $amount > 0 ? '12' : '11')
            . self::tlv('38', $merchant)
            . self::tlv('53', '704')
            . ($amount > 0 ? self::tlv('54', (string)$amount) : '')
            . self::tlv('58', 'VN')
            . ($memo !== '' ? self::tlv('62', self::tlv('08', mb_substr(self::plain($memo), 0, 50))) : '')
            . '6304';
        return $data . self::crc16($data);
    }

    /** Lỗi cấu hình ngân hàng (nếu có), để báo cho admin. */
    public static function configError(): ?string
    {
        $bin = trim((string)Settings::get('bank_bin'));
        $acc = trim((string)Settings::get('bank_account'));
        if ($acc === '') {
            return 'Chưa nhập số tài khoản.';
        }
        if (!preg_match('/^\d{6}$/', $bin)) {
            return 'Mã BIN ngân hàng phải gồm 6 chữ số (chọn ngân hàng trong danh sách).';
        }
        if (!preg_match('/^[0-9A-Za-z]{4,19}$/', $acc)) {
            return 'Số tài khoản chỉ gồm chữ số/chữ cái, không có dấu cách.';
        }
        return null;
    }

    /** Ảnh PNG của mã QR, hoặc null nếu mọi nguồn đều lỗi. Trả về ['bytes','path','source']. */
    /** Mẫu ảnh VietQR hợp lệ: compact2 (có logo + thông tin), compact (có logo), qr_only (chỉ mã), print (bản in). */
    public const TEMPLATES = ['compact2', 'compact', 'qr_only', 'print'];

    /**
     * Cách lấy ảnh, đặt trong Cài đặt (qr_source):
     *   auto  = API tạo mã QR (api.vietqr.io/v2/generate) → Link tạo mã nhanh (img.vietqr.io) → tự vẽ
     *   api   = chỉ API, lỗi thì tự vẽ
     *   link  = chỉ link nhanh, lỗi thì tự vẽ
     *   local = luôn tự vẽ trên server (không cần internet)
     */
    public static function image(int $amount, string $memo): ?array
    {
        $bin = trim((string)Settings::get('bank_bin'));
        $acc = preg_replace('/\s+/', '', (string)Settings::get('bank_account'));
        $owner = self::plain((string)Settings::get('bank_owner'));
        $memo = self::plain($memo);
        $template = in_array(Settings::get('qr_template'), self::TEMPLATES, true) ? (string)Settings::get('qr_template') : 'compact2';
        $source = (string)(Settings::get('qr_source') ?: 'auto');
        if (Settings::get('qr_use_vietqr') === '0') {
            $source = 'local'; // tương thích cài đặt cũ
        }
        $dir = APP_ROOT . '/data/qr';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $path = $dir . '/' . md5("$bin|$acc|$owner|$amount|$memo|$template|$source") . '.png';
        if (is_file($path) && filesize($path) > 100) {
            return ['bytes' => (string)file_get_contents($path), 'path' => $path, 'source' => 'cache'];
        }

        $tries = ['auto' => ['api', 'link'], 'api' => ['api'], 'link' => ['link'], 'local' => []][$source] ?? ['api', 'link'];
        foreach ($tries as $t) {
            $bytes = $t === 'api'
                ? self::fromApi($bin, $acc, $owner, $amount, $memo, $template)
                : self::download(sprintf('https://img.vietqr.io/image/%s-%s-%s.png?amount=%d&addInfo=%s&accountName=%s',
                    rawurlencode($bin), rawurlencode($acc), $template, $amount, rawurlencode($memo), rawurlencode($owner)));
            if ($bytes !== null) {
                @file_put_contents($path, $bytes);
                return ['bytes' => $bytes, 'path' => $path, 'source' => $t === 'api' ? 'vietqr-api' : 'vietqr-link'];
            }
        }
        try {
            $bytes = QrEncoder::png(self::payload($bin, $acc, $amount, $memo), 10, 4);
        } catch (Throwable $e) {
            app_log('qr', 'Tự vẽ QR lỗi: ' . $e->getMessage());
            return null;
        }
        @file_put_contents($path, $bytes);
        return ['bytes' => $bytes, 'path' => $path, 'source' => 'local'];
    }

    /**
     * API tạo mã QR của VietQR: POST https://api.vietqr.io/v2/generate
     * Header x-client-id / x-api-key (lấy tại my.vietqr.io, không bắt buộc), body JSON:
     * accountNo, accountName, acqId (BIN), amount, addInfo, format=text, template.
     * Trả về data.qrDataURL = "data:image/png;base64,...".
     */
    private static function fromApi(string $bin, string $acc, string $owner, int $amount, string $memo, string $template): ?string
    {
        $headers = ['Content-Type: application/json', 'Accept: application/json'];
        if ($id = trim((string)Settings::get('vietqr_client_id'))) {
            $headers[] = 'x-client-id: ' . $id;
        }
        if ($key = trim((string)Settings::get('vietqr_api_key'))) {
            $headers[] = 'x-api-key: ' . $key;
        }
        $body = json_encode([
            'accountNo' => $acc,
            'accountName' => $owner,
            'acqId' => (int)$bin,
            'amount' => $amount,
            'addInfo' => $memo,
            'format' => 'text',
            'template' => $template,
        ]);
        $ch = curl_init((string)(Settings::get('vietqr_api_url') ?: 'https://api.vietqr.io/v2/generate'));
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_HTTPHEADER => $headers, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_CONNECTTIMEOUT => 5]);
        $raw = curl_exec($ch);
        $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        $res = json_decode((string)$raw, true);
        $url = (string)($res['data']['qrDataURL'] ?? '');
        if (($res['code'] ?? '') !== '00' || !preg_match('#^data:image/(png|jpeg);base64,(.+)$#s', $url, $m)) {
            app_log('qr', 'API tạo mã QR lỗi: ' . ($err ?: "HTTP $http " . mb_substr((string)$raw, 0, 200)));
            return null;
        }
        $bytes = base64_decode($m[2], true);
        return $bytes !== false && strlen($bytes) > 100 && (str_starts_with($bytes, "\x89PNG") || str_starts_with($bytes, "\xFF\xD8")) ? $bytes : null;
    }

    private static function download(string $url): ?string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 8, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_USERAGENT => 'Mozilla/5.0 SimOtpBot']);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $type = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        curl_close($ch);
        // Chỉ nhận ảnh PNG/JPEG thật (VietQR trả trang lỗi HTML/JSON khi sai thông tin)
        if ($code !== 200 || !is_string($body) || strlen($body) < 100 || !str_starts_with($type, 'image/')) {
            app_log('qr', "Link tạo mã nhanh lỗi (HTTP $code): $url");
            return null;
        }
        return (str_starts_with($body, "\x89PNG") || str_starts_with($body, "\xFF\xD8")) ? $body : null;
    }
}
