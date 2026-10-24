<?php
declare(strict_types=1);

/**
 * Nạp tiền tự động bằng API lịch sử giao dịch (Web2M và các API tương tự).
 *
 * Cron gọi sync() định kỳ: tải lịch sử, ghi mọi giao dịch mới vào bảng bank_tx
 * (để không xử lý lại), giao dịch tiền vào có mã nạp hợp lệ thì cộng tiền cho user.
 * Lần đầu với 1 link mới chỉ ghi nhận giao dịch cũ (status = baseline), không cộng tiền.
 */
final class BankSync
{
    /** Các khóa thường gặp trong JSON trả về (Web2M thay đổi theo ngân hàng). */
    private const LIST_KEYS = ['transactions', 'data', 'TranList', 'tranList', 'transactionHistoryList', 'history', 'result', 'records'];
    private const ID_KEYS = ['transactionID', 'transactionId', 'tranId', 'TransactionID', 'refNo', 'reference', 'transactionNumber', 'id', 'Reference'];
    private const AMOUNT_KEYS = ['amount', 'Amount', 'creditAmount', 'CreditAmount', 'money', 'transferAmount'];
    private const DEBIT_KEYS = ['debitAmount', 'DebitAmount'];
    private const DESC_KEYS = ['description', 'Description', 'content', 'addDescription', 'remark', 'transactionContent', 'Remark'];
    private const TIME_KEYS = ['transactionDate', 'TransactionDate', 'postingDate', 'when', 'date', 'time', 'transactionTime'];
    private const TYPE_KEYS = ['type', 'Type', 'transactionType', 'cd'];

    public static function enabled(): bool
    {
        return trim((string)Settings::get('bank_api_url')) !== '';
    }

    /** Tải lịch sử. Trả về ['ok','message','txs'=>[[ref,amount,content,time,in]],'raw']. */
    public static function fetch(): array
    {
        $url = trim((string)Settings::get('bank_api_url'));
        if ($url === '') {
            return ['ok' => false, 'message' => 'Chưa nhập link API lịch sử giao dịch.', 'txs' => [], 'raw' => ''];
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_USERAGENT => 'Mozilla/5.0 SimOtpBot',
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
        ]);
        $raw = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($raw === false) {
            return ['ok' => false, 'message' => 'Lỗi kết nối: ' . $err, 'txs' => [], 'raw' => ''];
        }
        $data = json_decode((string)$raw, true);
        if (!is_array($data)) {
            return ['ok' => false, 'message' => "Phản hồi không phải JSON (HTTP $code)", 'txs' => [], 'raw' => (string)$raw];
        }
        if (($data['status'] ?? true) === false || ($data['success'] ?? true) === false) {
            return ['ok' => false, 'message' => 'API báo lỗi: ' . ($data['message'] ?? $data['msg'] ?? 'không rõ'), 'txs' => [], 'raw' => (string)$raw];
        }
        $list = array_is_list($data) ? $data : null;
        foreach (self::LIST_KEYS as $k) {
            if ($list === null && isset($data[$k]) && is_array($data[$k])) {
                $list = array_is_list($data[$k]) ? $data[$k] : (self::firstList($data[$k]) ?? null);
            }
        }
        if ($list === null) {
            return ['ok' => false, 'message' => 'Không tìm thấy danh sách giao dịch trong phản hồi.', 'txs' => [], 'raw' => (string)$raw];
        }
        $txs = [];
        foreach ($list as $item) {
            if (is_array($item) && ($t = self::normalize($item)) !== null) {
                $txs[] = $t;
            }
        }
        return ['ok' => true, 'message' => '', 'txs' => $txs, 'raw' => (string)$raw];
    }

    private static function firstList(array $a): ?array
    {
        foreach ($a as $v) {
            if (is_array($v) && array_is_list($v)) {
                return $v;
            }
        }
        return null;
    }

    private static function pick(array $item, array $keys)
    {
        foreach ($keys as $k) {
            if (isset($item[$k]) && $item[$k] !== '') {
                return $item[$k];
            }
        }
        return null;
    }

    private static function number($v): float
    {
        if (is_int($v) || is_float($v)) {
            return (float)$v;
        }
        $s = trim((string)$v);
        $neg = str_starts_with($s, '-');
        $digits = preg_replace('/[^\d]/', '', preg_replace('/[.,]\d{1,2}$/', '', $s)); // bỏ phần thập phân ",00"
        return ($neg ? -1 : 1) * (float)$digits;
    }

    /** Chuẩn hóa 1 giao dịch. in = true nếu là tiền vào. */
    private static function normalize(array $item): ?array
    {
        $amount = self::number(self::pick($item, self::AMOUNT_KEYS) ?? 0);
        $debit = self::number(self::pick($item, self::DEBIT_KEYS) ?? 0);
        $type = strtoupper(trim((string)(self::pick($item, self::TYPE_KEYS) ?? '')));
        $content = trim((string)(self::pick($item, self::DESC_KEYS) ?? ''));
        $time = trim((string)(self::pick($item, self::TIME_KEYS) ?? ''));
        $id = self::pick($item, self::ID_KEYS);

        $in = $amount > 0 && $debit <= 0 && !in_array($type, ['OUT', 'D', 'DEBIT', 'DR', '-1'], true);
        if ($amount == 0 && $debit == 0) {
            return null;
        }
        // Không có mã giao dịch → tạo mã từ nội dung + số tiền + thời gian
        $ref = 'bank_' . ($id !== null && $id !== '' ? preg_replace('/[^\w.-]/', '', (string)$id) : md5($content . '|' . $amount . '|' . $time));
        return ['ref' => mb_substr($ref, 0, 120), 'amount' => abs($amount ?: $debit), 'content' => $content, 'time' => $time, 'in' => $in];
    }

    /**
     * Quét lịch sử và cộng tiền. $force = bỏ qua khoảng cách thời gian tối thiểu.
     * Trả về ['ok','message','new','credited'].
     */
    public static function sync(bool $force = false): array
    {
        if (!self::enabled()) {
            return ['ok' => false, 'message' => 'Chưa bật', 'new' => 0, 'credited' => 0];
        }
        $interval = max(5, Settings::int('bank_api_interval'));
        if (!$force && time() - (int)Settings::get('bank_api_last_run') < $interval) {
            return ['ok' => true, 'message' => 'Chưa tới lượt quét', 'new' => 0, 'credited' => 0];
        }
        Settings::set('bank_api_last_run', (string)time());

        $r = self::fetch();
        if (!$r['ok']) {
            Settings::set('bank_api_last_error', now() . ' — ' . $r['message']);
            app_log('bank', $r['message'] . ' ' . mb_substr($r['raw'], 0, 300));
            return ['ok' => false, 'message' => $r['message'], 'new' => 0, 'credited' => 0];
        }
        Settings::set('bank_api_last_error', '');
        Settings::set('bank_api_last_ok', now());

        // Lần đầu với link này: chỉ ghi nhận giao dịch cũ, không cộng tiền
        $fingerprint = md5(trim((string)Settings::get('bank_api_url')));
        $baseline = Settings::get('bank_api_baseline') !== $fingerprint;

        $new = $credited = 0;
        foreach ($r['txs'] as $t) {
            if (DB::value('SELECT 1 FROM bank_tx WHERE ref = ?', [$t['ref']])) {
                continue;
            }
            $new++;
            $row = [
                'ref' => $t['ref'],
                'amount' => $t['amount'],
                'content' => mb_substr($t['content'], 0, 250),
                'tx_time' => mb_substr($t['time'], 0, 40),
                'created_at' => now(),
            ];
            if ($baseline) {
                DB::insert('bank_tx', $row + ['status' => 'baseline']);
                continue;
            }
            if (!$t['in']) {
                DB::insert('bank_tx', $row + ['status' => 'out']);
                continue;
            }
            $user = Wallet::userFromTransferContent($t['content']);
            $min = Settings::int('min_deposit');
            if (!$user) {
                DB::insert('bank_tx', $row + ['status' => 'unmatched']);
                continue;
            }
            if ($t['amount'] < $min) {
                DB::insert('bank_tx', $row + ['status' => 'too_small', 'user_id' => $user['id']]);
                continue;
            }
            // Ghi trước để không cộng 2 lần nếu 2 tiến trình chạy cùng lúc (ref là khóa chính)
            try {
                DB::insert('bank_tx', $row + ['status' => 'processing', 'user_id' => $user['id']]);
            } catch (PDOException $e) {
                continue;
            }
            $depId = Wallet::creditBankTransfer($t['ref'], $t['content'], $t['amount'], $user);
            DB::update('bank_tx', ['status' => $depId ? 'credited' : 'duplicate', 'deposit_id' => $depId], 'ref = ?', [$t['ref']]);
            $credited += $depId ? 1 : 0;
        }
        if ($baseline) {
            Settings::set('bank_api_baseline', $fingerprint);
            return ['ok' => true, 'message' => "Lần đầu: đã ghi nhận $new giao dịch cũ (không cộng tiền). Từ giờ giao dịch mới sẽ được cộng tự động.", 'new' => $new, 'credited' => 0];
        }
        return ['ok' => true, 'message' => "Có $new giao dịch mới, đã cộng tiền $credited giao dịch.", 'new' => $new, 'credited' => $credited];
    }

    /** Admin gán thủ công 1 giao dịch chưa khớp cho user. */
    public static function assign(string $ref, array $user): bool
    {
        $tx = DB::row("SELECT * FROM bank_tx WHERE ref = ? AND status IN ('unmatched', 'too_small', 'baseline')", [$ref]);
        if (!$tx) {
            return false;
        }
        $depId = Wallet::creditBankTransfer($tx['ref'], (string)$tx['content'], (float)$tx['amount'], $user);
        if (!$depId) {
            return false;
        }
        DB::update('bank_tx', ['status' => 'credited', 'user_id' => $user['id'], 'deposit_id' => $depId], 'ref = ?', [$ref]);
        return true;
    }
}
