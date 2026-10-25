<?php
declare(strict_types=1);

/**
 * Bộ tạo mã QR thuần PHP (không cần thư viện, không cần internet) và xuất ảnh PNG.
 * Hỗ trợ chế độ byte, mức sửa lỗi M, phiên bản 1–15 (đủ cho chuỗi VietQR ~150 ký tự).
 * Thuật toán theo chuẩn ISO/IEC 18004.
 */
final class QrEncoder
{
    /** Mức sửa lỗi M: phiên bản => [số codeword sửa lỗi mỗi khối, [số khối, số codeword dữ liệu mỗi khối], ...]. */
    private const ECC_M = [
        1 => [10, [1, 16]], 2 => [16, [1, 28]], 3 => [26, [1, 44]], 4 => [18, [2, 32]], 5 => [24, [2, 43]],
        6 => [16, [4, 27]], 7 => [18, [4, 31]], 8 => [22, [2, 38], [2, 39]], 9 => [22, [3, 36], [2, 37]],
        10 => [26, [4, 43], [1, 44]], 11 => [30, [1, 50], [4, 51]], 12 => [22, [6, 36], [2, 37]],
        13 => [22, [8, 37], [1, 38]], 14 => [24, [4, 40], [5, 41]], 15 => [24, [5, 41], [5, 42]],
    ];
    private const ALIGN = [
        1 => [], 2 => [6, 18], 3 => [6, 22], 4 => [6, 26], 5 => [6, 30], 6 => [6, 34], 7 => [6, 22, 38],
        8 => [6, 24, 42], 9 => [6, 26, 46], 10 => [6, 28, 50], 11 => [6, 30, 54], 12 => [6, 32, 58],
        13 => [6, 34, 62], 14 => [6, 26, 46, 66], 15 => [6, 26, 48, 70],
    ];

    private int $size;
    private array $m = [];     // [y][x] => bool (true = ô đen)
    private array $fn = [];    // [y][x] => ô thuộc mẫu cố định

    /** Ma trận QR cho chuỗi $text. */
    public static function matrix(string $text): array
    {
        return (new self())->build($text);
    }

    /** Ảnh PNG (nhị phân) của mã QR. $scale: số pixel mỗi ô, $quiet: viền trắng (ô). */
    public static function png(string $text, int $scale = 10, int $quiet = 4): string
    {
        $mx = self::matrix($text);
        $n = count($mx);
        $px = ($n + 2 * $quiet) * $scale;
        $raw = '';
        for ($y = 0; $y < $px; $y++) {
            $my = intdiv($y, $scale) - $quiet;
            $row = "\0"; // filter: none
            for ($x = 0; $x < $px; $x++) {
                $mxx = intdiv($x, $scale) - $quiet;
                $dark = $my >= 0 && $my < $n && $mxx >= 0 && $mxx < $n && $mx[$my][$mxx];
                $row .= $dark ? "\x00" : "\xFF";
            }
            $raw .= $row;
        }
        $chunk = fn(string $type, string $data) => pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
        return "\x89PNG\r\n\x1a\n"
            . $chunk('IHDR', pack('NNCCCCC', $px, $px, 8, 0, 0, 0, 0))
            . $chunk('IDAT', gzcompress($raw, 9))
            . $chunk('IEND', '');
    }

    private function build(string $text): array
    {
        $bytes = array_values(unpack('C*', $text) ?: []);
        $version = 0;
        foreach (self::ECC_M as $v => $spec) {
            $capacity = $this->dataCodewords($v) * 8;
            $needed = 4 + ($v < 10 ? 8 : 16) + 8 * count($bytes);
            if ($needed <= $capacity) {
                $version = $v;
                break;
            }
        }
        if ($version === 0) {
            throw new RuntimeException('Dữ liệu quá dài cho mã QR');
        }
        $this->size = 17 + 4 * $version;
        $this->m = array_fill(0, $this->size, array_fill(0, $this->size, false));
        $this->fn = $this->m;

        $this->drawFunctionPatterns($version);
        $this->drawCodewords($this->addEcc($this->encodeData($bytes, $version), $version));

        // Chọn mặt nạ có điểm phạt thấp nhất
        $best = 0;
        $bestScore = PHP_INT_MAX;
        for ($mask = 0; $mask < 8; $mask++) {
            $this->applyMask($mask);
            $this->drawFormat($mask);
            $score = $this->penalty();
            if ($score < $bestScore) {
                $best = $mask;
                $bestScore = $score;
            }
            $this->applyMask($mask); // bỏ mặt nạ (XOR lần 2)
        }
        $this->applyMask($best);
        $this->drawFormat($best);
        return $this->m;
    }

    private function dataCodewords(int $v): int
    {
        $spec = self::ECC_M[$v];
        $n = 0;
        foreach (array_slice($spec, 1) as [$blocks, $len]) {
            $n += $blocks * $len;
        }
        return $n;
    }

    // --------------------------------------------------------- dữ liệu + RS

    private function encodeData(array $bytes, int $v): array
    {
        $bits = [];
        $put = function (int $val, int $len) use (&$bits) {
            for ($i = $len - 1; $i >= 0; $i--) {
                $bits[] = ($val >> $i) & 1;
            }
        };
        $put(0b0100, 4); // chế độ byte
        $put(count($bytes), $v < 10 ? 8 : 16);
        foreach ($bytes as $b) {
            $put($b, 8);
        }
        $capacity = $this->dataCodewords($v) * 8;
        $put(0, min(4, $capacity - count($bits)));
        while (count($bits) % 8) {
            $bits[] = 0;
        }
        $out = [];
        foreach (array_chunk($bits, 8) as $byte) {
            $out[] = bindec(implode('', $byte));
        }
        for ($pad = 0xEC; count($out) < $capacity / 8; $pad ^= 0xEC ^ 0x11) {
            $out[] = $pad;
        }
        return $out;
    }

    private static function gfMul(int $x, int $y): int
    {
        $z = 0;
        for ($i = 7; $i >= 0; $i--) {
            $z = (($z << 1) ^ (($z >> 7) * 0x11D)) & 0xFF;
            $z ^= (($y >> $i) & 1) * $x;
        }
        return $z;
    }

    private static function rsDivisor(int $degree): array
    {
        $result = array_fill(0, $degree, 0);
        $result[$degree - 1] = 1;
        $root = 1;
        for ($i = 0; $i < $degree; $i++) {
            for ($j = 0; $j < $degree; $j++) {
                $result[$j] = self::gfMul($result[$j], $root);
                if ($j + 1 < $degree) {
                    $result[$j] ^= $result[$j + 1];
                }
            }
            $root = self::gfMul($root, 0x02);
        }
        return $result;
    }

    private static function rsRemainder(array $data, array $divisor): array
    {
        $result = array_fill(0, count($divisor), 0);
        foreach ($data as $b) {
            $factor = $b ^ array_shift($result);
            $result[] = 0;
            foreach ($divisor as $i => $coef) {
                $result[$i] ^= self::gfMul($coef, $factor);
            }
        }
        return $result;
    }

    private function addEcc(array $data, int $v): array
    {
        $spec = self::ECC_M[$v];
        $eccLen = $spec[0];
        $divisor = self::rsDivisor($eccLen);
        $blocks = [];
        $pos = 0;
        foreach (array_slice($spec, 1) as [$count, $len]) {
            for ($i = 0; $i < $count; $i++) {
                $d = array_slice($data, $pos, $len);
                $pos += $len;
                $blocks[] = [$d, self::rsRemainder($d, $divisor)];
            }
        }
        $out = [];
        $maxLen = max(array_map(fn($b) => count($b[0]), $blocks));
        for ($i = 0; $i < $maxLen; $i++) {
            foreach ($blocks as $b) {
                if ($i < count($b[0])) {
                    $out[] = $b[0][$i];
                }
            }
        }
        for ($i = 0; $i < $eccLen; $i++) {
            foreach ($blocks as $b) {
                $out[] = $b[1][$i];
            }
        }
        return $out;
    }

    // ------------------------------------------------------------ ma trận

    private function set(int $x, int $y, bool $dark): void
    {
        $this->m[$y][$x] = $dark;
        $this->fn[$y][$x] = true;
    }

    private function drawFunctionPatterns(int $v): void
    {
        for ($i = 0; $i < $this->size; $i++) {
            $this->set(6, $i, $i % 2 === 0);
            $this->set($i, 6, $i % 2 === 0);
        }
        foreach ([[3, 3], [$this->size - 4, 3], [3, $this->size - 4]] as [$cx, $cy]) {
            for ($dy = -4; $dy <= 4; $dy++) {
                for ($dx = -4; $dx <= 4; $dx++) {
                    $x = $cx + $dx;
                    $y = $cy + $dy;
                    if ($x >= 0 && $x < $this->size && $y >= 0 && $y < $this->size) {
                        $d = max(abs($dx), abs($dy));
                        $this->set($x, $y, $d !== 2 && $d !== 4);
                    }
                }
            }
        }
        $pos = self::ALIGN[$v];
        $n = count($pos);
        for ($i = 0; $i < $n; $i++) {
            for ($j = 0; $j < $n; $j++) {
                if (($i === 0 && $j === 0) || ($i === 0 && $j === $n - 1) || ($i === $n - 1 && $j === 0)) {
                    continue;
                }
                for ($dy = -2; $dy <= 2; $dy++) {
                    for ($dx = -2; $dx <= 2; $dx++) {
                        $this->set($pos[$i] + $dx, $pos[$j] + $dy, max(abs($dx), abs($dy)) !== 1);
                    }
                }
            }
        }
        $this->drawFormat(0); // giữ chỗ, vẽ lại sau khi chọn mặt nạ
        if ($v >= 7) {
            $rem = $v;
            for ($i = 0; $i < 12; $i++) {
                $rem = ($rem << 1) ^ (($rem >> 11) * 0x1F25);
            }
            $bits = ($v << 12) | $rem;
            for ($i = 0; $i < 18; $i++) {
                $bit = (($bits >> $i) & 1) === 1;
                $a = $this->size - 11 + $i % 3;
                $b = intdiv($i, 3);
                $this->set($a, $b, $bit);
                $this->set($b, $a, $bit);
            }
        }
    }

    private function drawFormat(int $mask): void
    {
        $data = (0b00 << 3) | $mask; // mức M = 00
        $rem = $data;
        for ($i = 0; $i < 10; $i++) {
            $rem = ($rem << 1) ^ (($rem >> 9) * 0x537);
        }
        $bits = (($data << 10) | $rem) ^ 0x5412;
        $bit = fn(int $i) => (($bits >> $i) & 1) === 1;
        for ($i = 0; $i <= 5; $i++) {
            $this->set(8, $i, $bit($i));
        }
        $this->set(8, 7, $bit(6));
        $this->set(8, 8, $bit(7));
        $this->set(7, 8, $bit(8));
        for ($i = 9; $i < 15; $i++) {
            $this->set(14 - $i, 8, $bit($i));
        }
        for ($i = 0; $i < 8; $i++) {
            $this->set($this->size - 1 - $i, 8, $bit($i));
        }
        for ($i = 8; $i < 15; $i++) {
            $this->set(8, $this->size - 15 + $i, $bit($i));
        }
        $this->set(8, $this->size - 8, true); // ô đen cố định
    }

    private function drawCodewords(array $data): void
    {
        $i = 0;
        $total = count($data) * 8;
        for ($right = $this->size - 1; $right >= 1; $right -= 2) {
            if ($right === 6) {
                $right = 5;
            }
            for ($vert = 0; $vert < $this->size; $vert++) {
                for ($j = 0; $j < 2; $j++) {
                    $x = $right - $j;
                    $upward = (($right + 1) & 2) === 0;
                    $y = $upward ? $this->size - 1 - $vert : $vert;
                    if (!$this->fn[$y][$x] && $i < $total) {
                        $this->m[$y][$x] = (($data[$i >> 3] >> (7 - ($i & 7))) & 1) === 1;
                        $i++;
                    }
                }
            }
        }
    }

    private function applyMask(int $mask): void
    {
        for ($y = 0; $y < $this->size; $y++) {
            for ($x = 0; $x < $this->size; $x++) {
                if ($this->fn[$y][$x]) {
                    continue;
                }
                $invert = match ($mask) {
                    0 => ($x + $y) % 2 === 0,
                    1 => $y % 2 === 0,
                    2 => $x % 3 === 0,
                    3 => ($x + $y) % 3 === 0,
                    4 => (intdiv($x, 3) + intdiv($y, 2)) % 2 === 0,
                    5 => ($x * $y % 2 + $x * $y % 3) === 0,
                    6 => (($x * $y % 2 + $x * $y % 3) % 2) === 0,
                    default => ((($x + $y) % 2 + $x * $y % 3) % 2) === 0,
                };
                if ($invert) {
                    $this->m[$y][$x] = !$this->m[$y][$x];
                }
            }
        }
    }

    /** Điểm phạt theo chuẩn (đơn giản hóa) — chỉ ảnh hưởng độ dễ quét, không ảnh hưởng đúng/sai. */
    private function penalty(): int
    {
        $n = $this->size;
        $score = 0;
        $dark = 0;
        $lines = [];
        for ($i = 0; $i < $n; $i++) {
            $row = '';
            $col = '';
            for ($j = 0; $j < $n; $j++) {
                $row .= $this->m[$i][$j] ? '1' : '0';
                $col .= $this->m[$j][$i] ? '1' : '0';
            }
            $lines[] = $row;
            $lines[] = $col;
            $dark += substr_count($row, '1');
        }
        foreach ($lines as $line) {
            if (preg_match_all('/0{5,}|1{5,}/', $line, $mm)) {
                foreach ($mm[0] as $run) {
                    $score += 3 + strlen($run) - 5;
                }
            }
            $score += 40 * (substr_count($line, '10111010000') + substr_count($line, '00001011101'));
        }
        for ($y = 0; $y < $n - 1; $y++) {
            for ($x = 0; $x < $n - 1; $x++) {
                $c = $this->m[$y][$x];
                if ($c === $this->m[$y][$x + 1] && $c === $this->m[$y + 1][$x] && $c === $this->m[$y + 1][$x + 1]) {
                    $score += 3;
                }
            }
        }
        $score += 10 * intdiv(abs($dark * 20 - $n * $n * 10), $n * $n);
        return $score;
    }
}
