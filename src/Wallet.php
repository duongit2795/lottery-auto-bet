<?php
declare(strict_types=1);

final class Wallet
{
    /** Cộng tiền (amount > 0) hoặc trừ tiền (amount < 0). Trả về số dư mới, hoặc null nếu không đủ tiền. */
    public static function change(int $userId, float $amount, string $type, string $note = ''): ?float
    {
        return DB::transaction(function () use ($userId, $amount, $type, $note) {
            if ($amount < 0) {
                $n = DB::query('UPDATE users SET balance = balance + ? WHERE id = ? AND balance >= ?', [$amount, $userId, -$amount])->rowCount();
                if ($n === 0) {
                    return null;
                }
            } else {
                DB::query('UPDATE users SET balance = balance + ? WHERE id = ?', [$amount, $userId]);
            }
            $after = (float)DB::value('SELECT balance FROM users WHERE id = ?', [$userId]);
            DB::insert('transactions', [
                'user_id' => $userId,
                'amount' => $amount,
                'balance_after' => $after,
                'type' => $type,
                'note' => mb_substr($note, 0, 250),
                'created_at' => now(),
            ]);
            return $after;
        });
    }

    /** Duyệt một yêu cầu nạp tiền (dùng cho admin & webhook ngân hàng). Trả về false nếu đã xử lý. */
    public static function approveDeposit(int $depositId, ?float $realAmount = null): bool
    {
        $dep = DB::row('SELECT * FROM deposits WHERE id = ?', [$depositId]);
        if (!$dep || DB::update('deposits', ['status' => 'approved', 'updated_at' => now()] + ($realAmount !== null ? ['amount' => $realAmount] : []), "id = ? AND status = 'pending'", [$depositId]) === 0) {
            return false;
        }
        $amount = $realAmount ?? (float)$dep['amount'];
        $bonus = floor($amount * (float)Settings::get('deposit_bonus_percent') / 100);
        $after = self::change((int)$dep['user_id'], $amount, 'deposit', 'Nạp tiền #' . $depositId);
        if ($bonus > 0) {
            $after = self::change((int)$dep['user_id'], $bonus, 'bonus', 'Khuyến mãi nạp #' . $depositId);
        }
        DB::query('UPDATE users SET total_deposit = total_deposit + ? WHERE id = ?', [$amount, $dep['user_id']]);

        $user = DB::row('SELECT * FROM users WHERE id = ?', [$dep['user_id']]);
        if ($user) {
            $msg = "✅ <b>Nạp tiền thành công</b>\nSố tiền: <b>" . money($amount) . "</b>";
            if ($bonus > 0) {
                $msg .= "\nKhuyến mãi: <b>+" . money($bonus) . "</b>";
            }
            $msg .= "\nSố dư hiện tại: <b>" . money($after) . "</b>";
            (new Telegram())->send($user['tg_id'], $msg);
        }
        return true;
    }
}
