<?php
declare(strict_types=1);

function cfg(string $key, $default = null)
{
    return $GLOBALS['config'][$key] ?? $default;
}

function money($amount): string
{
    return number_format((float)$amount, 0, ',', '.') . 'đ';
}

function now(): string
{
    return date('Y-m-d H:i:s');
}

function h($s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

/** Escape cho Telegram parse_mode=HTML */
function tg_escape($s): string
{
    return htmlspecialchars((string)$s, ENT_NOQUOTES, 'UTF-8');
}

function app_log(string $channel, string $message): void
{
    $dir = APP_ROOT . '/data/logs';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    @file_put_contents($dir . '/' . $channel . '-' . date('Y-m-d') . '.log', '[' . now() . '] ' . $message . "\n", FILE_APPEND);
}

/**
 * Lấy giá trị trong mảng theo đường dẫn "a.b.0.c". Hỗ trợ nhiều đường dẫn
 * phân tách bằng "|" — trả về giá trị đầu tiên tìm thấy.
 */
function array_path(array $data, string $paths)
{
    foreach (explode('|', $paths) as $path) {
        $path = trim($path);
        if ($path === '') {
            continue;
        }
        $cur = $data;
        $found = true;
        foreach (explode('.', $path) as $seg) {
            if (is_array($cur) && array_key_exists($seg, $cur)) {
                $cur = $cur[$seg];
            } else {
                $found = false;
                break;
            }
        }
        if ($found && $cur !== null && $cur !== '') {
            return $cur;
        }
    }
    return null;
}

function random_code(int $len = 6): string
{
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $out = '';
    for ($i = 0; $i < $len; $i++) {
        $out .= $chars[random_int(0, strlen($chars) - 1)];
    }
    return $out;
}
