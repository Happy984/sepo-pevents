<?php

declare(strict_types=1);

namespace SepoEvents\Tools;

defined('ABSPATH') || exit;

/**
 * QR codes drawn as SVG, in PHP, with no library and no outside service.
 *
 * Follows ISO/IEC 18004: byte mode, versions 1 to 40, error correction L, M,
 * Q or H, Reed–Solomon over GF(256), and the mask with the lowest penalty.
 * The smallest version that fits the text is used.
 */
final class Qr
{
    private const ECL_INDEX = ['L' => 0, 'M' => 1, 'Q' => 2, 'H' => 3];
    private const ECL_FORMAT = ['L' => 1, 'M' => 0, 'Q' => 3, 'H' => 2];

    private const ECC_PER_BLOCK = [
        [-1, 7, 10, 15, 20, 26, 18, 20, 24, 30, 18, 20, 24, 26, 30, 22, 24, 28, 30, 28, 28, 28, 28, 30, 30, 26, 28, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30],
        [-1, 10, 16, 26, 18, 24, 16, 18, 22, 22, 26, 30, 22, 22, 24, 24, 28, 28, 26, 26, 26, 26, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28],
        [-1, 13, 22, 18, 26, 18, 24, 18, 22, 20, 24, 28, 26, 24, 20, 30, 24, 28, 28, 26, 30, 28, 30, 30, 30, 30, 28, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30],
        [-1, 17, 28, 22, 16, 22, 28, 26, 26, 24, 28, 24, 28, 22, 24, 24, 30, 28, 28, 26, 28, 30, 24, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30],
    ];

    private const BLOCKS = [
        [-1, 1, 1, 1, 1, 1, 2, 2, 2, 2, 4, 4, 4, 4, 4, 6, 6, 6, 6, 7, 8, 8, 9, 9, 10, 12, 12, 12, 13, 14, 15, 16, 17, 18, 19, 19, 20, 21, 22, 24, 25],
        [-1, 1, 1, 1, 2, 2, 4, 4, 4, 5, 5, 5, 8, 9, 9, 10, 10, 11, 13, 14, 16, 17, 17, 18, 20, 21, 23, 25, 26, 28, 29, 31, 33, 35, 37, 38, 40, 43, 45, 47, 49],
        [-1, 1, 1, 2, 2, 4, 4, 6, 6, 8, 8, 8, 10, 12, 16, 12, 17, 16, 18, 21, 20, 23, 23, 25, 27, 29, 34, 34, 35, 38, 40, 43, 45, 48, 51, 53, 56, 59, 62, 65, 68],
        [-1, 1, 1, 2, 4, 4, 4, 5, 6, 8, 8, 11, 11, 16, 16, 18, 16, 19, 21, 25, 25, 25, 34, 30, 32, 35, 37, 40, 42, 45, 48, 51, 54, 57, 60, 63, 66, 70, 74, 77, 81],
    ];

    private int $version;
    private int $size;
    private string $ecl;
    /** @var array<int,array<int,bool>> */
    private array $modules = [];
    /** @var array<int,array<int,bool>> */
    private array $isFunction = [];

    /** An SVG image of the text as a QR code, with the standard four-module quiet zone. */
    public static function svg(string $text, int $pixels = 220, string $ecl = 'M', string $label = ''): string
    {
        $qr = self::encode($text, $ecl);
        $n = $qr->size;
        $path = '';
        for ($y = 0; $y < $n; $y++) {
            for ($x = 0; $x < $n; $x++) {
                if ($qr->modules[$y][$x]) {
                    $path .= 'M' . ($x + 4) . ' ' . ($y + 4) . 'h1v1h-1z';
                }
            }
        }
        $box = $n + 8;
        $aria = $label !== '' ? ' role="img" aria-label="' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '"' : ' aria-hidden="true"';
        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $box . ' ' . $box . '" width="' . $pixels . '" height="' . $pixels . '" shape-rendering="crispEdges"' . $aria . '>'
            . '<rect width="' . $box . '" height="' . $box . '" fill="#ffffff"/><path d="' . $path . '" fill="#000000"/></svg>';
    }

    /** The module grid, true for dark: for tests and other renderers. @return array<int,array<int,bool>> */
    public static function matrix(string $text, string $ecl = 'M'): array
    {
        return self::encode($text, $ecl)->modules;
    }

    private static function encode(string $text, string $ecl): self
    {
        $ecl = isset(self::ECL_INDEX[$ecl]) ? $ecl : 'M';
        $bytes = array_values(unpack('C*', $text) ?: []);
        $len = count($bytes);

        $version = 0;
        for ($v = 1; $v <= 40; $v++) {
            $capacityBits = self::dataCodewords($v, $ecl) * 8;
            $needed = 4 + ($v <= 9 ? 8 : 16) + $len * 8;
            if ($needed <= $capacityBits) {
                $version = $v;
                break;
            }
        }
        if ($version === 0) {
            throw new \InvalidArgumentException('The text is too long for a QR code.');
        }

        $bits = [];
        $push = static function (int $val, int $n) use (&$bits): void {
            for ($i = $n - 1; $i >= 0; $i--) {
                $bits[] = ($val >> $i) & 1;
            }
        };
        $push(0b0100, 4);
        $push($len, $version <= 9 ? 8 : 16);
        foreach ($bytes as $b) {
            $push($b, 8);
        }
        $capacityBits = self::dataCodewords($version, $ecl) * 8;
        $push(0, min(4, $capacityBits - count($bits)));
        $push(0, (8 - count($bits) % 8) % 8);
        for ($pad = 0xEC; count($bits) < $capacityBits; $pad ^= 0xEC ^ 0x11) {
            $push($pad, 8);
        }
        $data = [];
        foreach (array_chunk($bits, 8) as $chunk) {
            $byte = 0;
            foreach ($chunk as $bit) {
                $byte = ($byte << 1) | $bit;
            }
            $data[] = $byte;
        }

        $qr = new self();
        $qr->version = $version;
        $qr->size = $version * 4 + 17;
        $qr->ecl = $ecl;
        $row = array_fill(0, $qr->size, false);
        $qr->modules = array_fill(0, $qr->size, $row);
        $qr->isFunction = array_fill(0, $qr->size, $row);

        $qr->drawFunctionPatterns();
        $qr->drawCodewords($qr->addEccAndInterleave($data));

        $best = 0;
        $bestPenalty = PHP_INT_MAX;
        for ($m = 0; $m < 8; $m++) {
            $qr->applyMask($m);
            $qr->drawFormatBits($m);
            $p = $qr->penalty();
            if ($p < $bestPenalty) {
                $best = $m;
                $bestPenalty = $p;
            }
            $qr->applyMask($m);
        }
        $qr->applyMask($best);
        $qr->drawFormatBits($best);
        return $qr;
    }

    private static function rawDataModules(int $ver): int
    {
        $result = (16 * $ver + 128) * $ver + 64;
        if ($ver >= 2) {
            $numAlign = intdiv($ver, 7) + 2;
            $result -= (25 * $numAlign - 10) * $numAlign - 55;
            if ($ver >= 7) {
                $result -= 36;
            }
        }
        return $result;
    }

    private static function dataCodewords(int $ver, string $ecl): int
    {
        $e = self::ECL_INDEX[$ecl];
        return intdiv(self::rawDataModules($ver), 8) - self::ECC_PER_BLOCK[$e][$ver] * self::BLOCKS[$e][$ver];
    }

    private function set(int $x, int $y, bool $dark): void
    {
        $this->modules[$y][$x] = $dark;
        $this->isFunction[$y][$x] = true;
    }

    private function drawFunctionPatterns(): void
    {
        for ($i = 0; $i < $this->size; $i++) {
            $this->set(6, $i, $i % 2 === 0);
            $this->set($i, 6, $i % 2 === 0);
        }
        foreach ([[3, 3], [$this->size - 4, 3], [3, $this->size - 4]] as [$cx, $cy]) {
            for ($dy = -4; $dy <= 4; $dy++) {
                for ($dx = -4; $dx <= 4; $dx++) {
                    $dist = max(abs($dx), abs($dy));
                    $xx = $cx + $dx;
                    $yy = $cy + $dy;
                    if ($xx >= 0 && $xx < $this->size && $yy >= 0 && $yy < $this->size) {
                        $this->set($xx, $yy, $dist !== 2 && $dist !== 4);
                    }
                }
            }
        }
        $pos = $this->alignmentPositions();
        $num = count($pos);
        for ($i = 0; $i < $num; $i++) {
            for ($j = 0; $j < $num; $j++) {
                if (($i === 0 && $j === 0) || ($i === 0 && $j === $num - 1) || ($i === $num - 1 && $j === 0)) {
                    continue;
                }
                for ($dy = -2; $dy <= 2; $dy++) {
                    for ($dx = -2; $dx <= 2; $dx++) {
                        $this->set($pos[$i] + $dx, $pos[$j] + $dy, max(abs($dx), abs($dy)) !== 1);
                    }
                }
            }
        }
        $this->drawFormatBits(0);
        if ($this->version >= 7) {
            $rem = $this->version;
            for ($i = 0; $i < 12; $i++) {
                $rem = ($rem << 1) ^ (($rem >> 11) * 0x1F25);
            }
            $bits = ($this->version << 12) | $rem;
            for ($i = 0; $i < 18; $i++) {
                $bit = (($bits >> $i) & 1) === 1;
                $a = $this->size - 11 + $i % 3;
                $b = intdiv($i, 3);
                $this->set($a, $b, $bit);
                $this->set($b, $a, $bit);
            }
        }
    }

    /** @return list<int> */
    private function alignmentPositions(): array
    {
        if ($this->version === 1) {
            return [];
        }
        $numAlign = intdiv($this->version, 7) + 2;
        $step = $this->version === 32 ? 26 : (int) ceil(($this->version * 4 + 4) / ($numAlign * 2 - 2)) * 2;
        $result = [6];
        for ($p = $this->size - 7; count($result) < $numAlign; $p -= $step) {
            array_splice($result, 1, 0, [$p]);
        }
        return $result;
    }

    private function drawFormatBits(int $mask): void
    {
        $data = (self::ECL_FORMAT[$this->ecl] << 3) | $mask;
        $rem = $data;
        for ($i = 0; $i < 10; $i++) {
            $rem = ($rem << 1) ^ (($rem >> 9) * 0x537);
        }
        $bits = (($data << 10) | $rem) ^ 0x5412;
        $bit = static fn(int $i): bool => (($bits >> $i) & 1) === 1;
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
        $this->set(8, $this->size - 8, true);
    }

    /** @param list<int> $data @return list<int> */
    private function addEccAndInterleave(array $data): array
    {
        $e = self::ECL_INDEX[$this->ecl];
        $numBlocks = self::BLOCKS[$e][$this->version];
        $eccLen = self::ECC_PER_BLOCK[$e][$this->version];
        $rawCodewords = intdiv(self::rawDataModules($this->version), 8);
        $numShort = $numBlocks - $rawCodewords % $numBlocks;
        $shortLen = intdiv($rawCodewords, $numBlocks);

        $divisor = self::rsDivisor($eccLen);
        $blocks = [];
        $k = 0;
        for ($i = 0; $i < $numBlocks; $i++) {
            $datLen = $shortLen - $eccLen + ($i < $numShort ? 0 : 1);
            $dat = array_slice($data, $k, $datLen);
            $k += $datLen;
            $ecc = self::rsRemainder($dat, $divisor);
            if ($i < $numShort) {
                $dat[] = 0;
            }
            $blocks[] = array_merge($dat, $ecc);
        }
        $result = [];
        $blockLen = count($blocks[0]);
        for ($i = 0; $i < $blockLen; $i++) {
            foreach ($blocks as $j => $block) {
                if ($i !== $shortLen - $eccLen || $j >= $numShort) {
                    $result[] = $block[$i];
                }
            }
        }
        return $result;
    }

    /** @return list<int> */
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

    /** @param list<int> $data @param list<int> $divisor @return list<int> */
    private static function rsRemainder(array $data, array $divisor): array
    {
        $n = count($divisor);
        $result = array_fill(0, $n, 0);
        foreach ($data as $b) {
            $factor = $b ^ array_shift($result);
            $result[] = 0;
            for ($i = 0; $i < $n; $i++) {
                $result[$i] ^= self::gfMul($divisor[$i], $factor);
            }
        }
        return $result;
    }

    private static function gfMul(int $x, int $y): int
    {
        $z = 0;
        for ($i = 7; $i >= 0; $i--) {
            $z = (($z << 1) ^ (($z >> 7) * 0x11D)) & 0xFF;
            $z ^= (($y >> $i) & 1) * $x;
        }
        return $z & 0xFF;
    }

    /** @param list<int> $data */
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
                    if (!$this->isFunction[$y][$x] && $i < $total) {
                        $this->modules[$y][$x] = (($data[$i >> 3] >> (7 - ($i & 7))) & 1) === 1;
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
                if ($this->isFunction[$y][$x]) {
                    continue;
                }
                $invert = match ($mask) {
                    0 => ($x + $y) % 2 === 0,
                    1 => $y % 2 === 0,
                    2 => $x % 3 === 0,
                    3 => ($x + $y) % 3 === 0,
                    4 => (intdiv($x, 3) + intdiv($y, 2)) % 2 === 0,
                    5 => ($x * $y) % 2 + ($x * $y) % 3 === 0,
                    6 => (($x * $y) % 2 + ($x * $y) % 3) % 2 === 0,
                    default => (($x + $y) % 2 + ($x * $y) % 3) % 2 === 0,
                };
                if ($invert) {
                    $this->modules[$y][$x] = !$this->modules[$y][$x];
                }
            }
        }
    }

    /** The standard's four penalty rules; the mask with the lowest total is used. */
    private function penalty(): int
    {
        $n = $this->size;
        $m = $this->modules;
        $score = 0;
        $finder = [[true, false, true, true, true, false, true, false, false, false, false], [false, false, false, false, true, false, true, true, true, false, true]];
        $lines = [];
        for ($i = 0; $i < $n; $i++) {
            $lines[] = $m[$i];
            $lines[] = array_column($m, $i);
        }
        foreach ($lines as $line) {
            $run = 1;
            for ($k = 1; $k <= $n; $k++) {
                if ($k < $n && $line[$k] === $line[$k - 1]) {
                    $run++;
                    continue;
                }
                if ($run >= 5) {
                    $score += 3 + ($run - 5);
                }
                $run = 1;
            }
            for ($k = 0; $k + 11 <= $n; $k++) {
                $slice = array_slice($line, $k, 11);
                if ($slice === $finder[0] || $slice === $finder[1]) {
                    $score += 40;
                }
            }
        }
        $dark = 0;
        for ($y = 0; $y < $n; $y++) {
            for ($x = 0; $x < $n; $x++) {
                if ($m[$y][$x]) {
                    $dark++;
                }
                if ($x < $n - 1 && $y < $n - 1) {
                    $c = $m[$y][$x];
                    if ($c === $m[$y][$x + 1] && $c === $m[$y + 1][$x] && $c === $m[$y + 1][$x + 1]) {
                        $score += 3;
                    }
                }
            }
        }
        $score += intdiv(abs($dark * 20 - $n * $n * 10), $n * $n) * 10;
        return $score;
    }
}
