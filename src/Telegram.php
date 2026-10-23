<?php
declare(strict_types=1);

final class Telegram
{
    private string $token;

    public function __construct(?string $token = null)
    {
        $this->token = $token ?? (string)Settings::get('bot_token');
    }

    public function call(string $method, array $params = [], int $timeout = 30): array
    {
        if ($this->token === '') {
            return ['ok' => false, 'description' => 'Chưa cấu hình bot token'];
        }
        foreach ($params as $k => $v) {
            if (is_array($v)) {
                $params[$k] = json_encode($v, JSON_UNESCAPED_UNICODE);
            }
        }
        $ch = curl_init("https://api.telegram.org/bot{$this->token}/{$method}");
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $params,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);
        $res = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);
        if ($res === false) {
            app_log('telegram', "$method error: $err");
            return ['ok' => false, 'description' => $err];
        }
        $data = json_decode($res, true) ?: ['ok' => false, 'description' => $res];
        if (empty($data['ok'])) {
            app_log('telegram', "$method failed: " . ($data['description'] ?? $res));
        }
        return $data;
    }

    public function send($chatId, string $text, ?array $keyboard = null, array $extra = []): array
    {
        $p = ['chat_id' => $chatId, 'text' => $text, 'parse_mode' => 'HTML', 'disable_web_page_preview' => 'true'] + $extra;
        if ($keyboard !== null) {
            $p['reply_markup'] = $keyboard;
        }
        return $this->call('sendMessage', $p);
    }

    public function edit($chatId, $messageId, string $text, ?array $keyboard = null): array
    {
        $p = ['chat_id' => $chatId, 'message_id' => $messageId, 'text' => $text, 'parse_mode' => 'HTML', 'disable_web_page_preview' => 'true'];
        if ($keyboard !== null) {
            $p['reply_markup'] = $keyboard;
        }
        return $this->call('editMessageText', $p);
    }

    public function photo($chatId, string $photoUrl, string $caption, ?array $keyboard = null): array
    {
        $p = ['chat_id' => $chatId, 'photo' => $photoUrl, 'caption' => $caption, 'parse_mode' => 'HTML'];
        if ($keyboard !== null) {
            $p['reply_markup'] = $keyboard;
        }
        return $this->call('sendPhoto', $p);
    }

    public function answer(string $callbackId, string $text = '', bool $alert = false): void
    {
        $this->call('answerCallbackQuery', ['callback_query_id' => $callbackId, 'text' => $text, 'show_alert' => $alert ? 'true' : 'false']);
    }

    public static function inline(array $rows): array
    {
        return ['inline_keyboard' => $rows];
    }

    public static function btn(string $text, string $data): array
    {
        return ['text' => $text, 'callback_data' => $data];
    }

    public static function url(string $text, string $url): array
    {
        return ['text' => $text, 'url' => $url];
    }
}
