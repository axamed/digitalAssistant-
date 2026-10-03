<?php
declare(strict_types=1);

namespace App\Support;

/**
 * A small QR Code encoder for byte-mode payloads, error correction level M, versions 1-10.
 *
 * The office has no Composer dependencies, so the encoder lives here rather than in
 * vendor/: byte mode, Reed-Solomon over GF(256), the eight data masks and the format and
 * version information blocks are all implemented directly. Output is SVG, which stays sharp
 * at any size and costs nothing to load.
 *
 * Version 10 at level M carries 213 bytes, which is far more than a wa.me link or a
 * verification URL needs. Anything longer is rejected rather than silently truncated,
 * because a QR code that decodes to the wrong text is worse than no QR code at all.
 */
final class QrCode
{
    /** Format-info level indicator for M (L=01, M=00, Q=11, H=10). */
    private const ECC_M_INDICATOR = 0b00;

    private const PENALTY_N1 = 3;
    private const PENALTY_N2 = 3;
    private const PENALTY_N3 = 40;
    private const PENALTY_N4 = 10;

    /**
     * Block structure for error correction level M, one row per version:
     * [total codewords, ecc codewords per block, blocks in group 1, data codewords per block
     *  in group 1, blocks in group 2, data codewords per block in group 2].
     *
     * Group 2 exists from version 8 onward, where the blocks are no longer all the same size.
     */
    private const BLOCKS_M = [
        1  => [26,  10, 1, 16, 0,  0],
        2  => [44,  16, 1, 28, 0,  0],
        3  => [70,  26, 1, 44, 0,  0],
        4  => [100, 18, 2, 32, 0,  0],
        5  => [134, 24, 2, 43, 0,  0],
        6  => [172, 16, 4, 27, 0,  0],
        7  => [196, 18, 4, 31, 0,  0],
        8  => [242, 22, 2, 38, 2,  39],
        9  => [292, 22, 3, 36, 2,  37],
        10 => [346, 26, 4, 43, 1,  44],
    ];

    /** Row and column centres of the alignment patterns, per version. Version 1 has none. */
    private const ALIGNMENT = [
        1  => [],
        2  => [6, 18],
        3  => [6, 22],
        4  => [6, 26],
        5  => [6, 30],
        6  => [6, 34],
        7  => [6, 22, 38],
        8  => [6, 24, 42],
        9  => [6, 26, 46],
        10 => [6, 28, 50],
    ];

    /**
     * Encode text and return the module grid as true (dark) and false (light).
     *
     * @return list<list<bool>> Row-major, indexed [row][column].
     */
    public static function matrix(string $text): array
    {
        if ($text === '') {
            throw new \InvalidArgumentException('QR payload cannot be empty.');
        }

        // The length field widens from 8 to 16 bits at version 10, which changes how many bits the
        // segment needs, so the version is settled before the segment is finalised.
        $wideLength = false;
        while (true) {
            $bits = self::encodeSegment($text, $wideLength);
            $version = self::chooseVersion($bits);
            if ($version >= 10 && !$wideLength) {
                $wideLength = true;
                continue;
            }
            break;
        }

        $codewords = self::bitsToCodewords($bits, $version);
        $interleaved = self::interleaveWithEcc($codewords, $version);

        return self::buildMatrix($interleaved, $version);
    }

    /**
     * Render the payload as an SVG document.
     *
     * $scale is the size of one module in pixels and $quiet is the margin in modules, which
     * the standard requires so scanners can find the code's edge.
     */
    public static function svg(string $text, int $scale = 8, int $quiet = 4): string
    {
        if ($scale < 1) {
            throw new \InvalidArgumentException('QR scale must be at least 1.');
        }
        if ($quiet < 4) {
            throw new \InvalidArgumentException('QR quiet zone must be at least 4 modules.');
        }

        $matrix = self::matrix($text);
        $size = count($matrix);
        $side = ($size + $quiet * 2) * $scale;

        $path = '';
        foreach ($matrix as $y => $row) {
            foreach ($row as $x => $dark) {
                if (!$dark) {
                    continue;
                }
                $path .= sprintf(
                    'M%d %dh%dv%dh-%dz',
                    ($x + $quiet) * $scale,
                    ($y + $quiet) * $scale,
                    $scale,
                    $scale,
                    $scale
                );
            }
        }

        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $side . ' ' . $side . '"'
            . ' width="' . $side . '" height="' . $side . '" role="img" shape-rendering="crispEdges">'
            . '<rect width="' . $side . '" height="' . $side . '" fill="#ffffff"/>'
            . '<path fill="#000000" d="' . $path . '"/>'
            . '</svg>';
    }

    /**
     * Byte-mode segment: a 4-bit mode indicator, a length, then the bytes themselves.
     *
     * The length field widens from 8 to 16 bits at version 10, which is the boundary this
     * encoder supports.
     */
    private static function encodeSegment(string $text, bool $wideLength): array
    {
        $bits = self::toBits(0b0100, 4);      // byte mode
        $length = strlen($text);

        if ($length < 10) {
            throw new \RuntimeException('QR payloads must be at least 10 bytes for this encoder.');
        }
        if ($length > 255) {
            throw new \RuntimeException('QR payload is too long for this encoder.');
        }

        $bits = array_merge($bits, self::toBits($length, $wideLength ? 16 : 8));

        for ($i = 0; $i < $length; $i++) {
            $bits = array_merge($bits, self::toBits(ord($text[$i]), 8));
        }

        return $bits;
    }

    /** @return list<int> */
    private static function toBits(int $value, int $count): array
    {
        $bits = [];
        for ($i = $count - 1; $i >= 0; $i--) {
            $bits[] = ($value >> $i) & 1;
        }
        return $bits;
    }

    /** The smallest version whose data codewords can hold the segment. */
    private static function chooseVersion(array $bits): int
    {
        $needed = (int) ceil(count($bits) / 8);

        foreach (self::BLOCKS_M as $version => $spec) {
            if ($needed <= self::dataCodewords($version)) {
                return $version;
            }
        }

        throw new \RuntimeException(
            'QR payload does not fit in version 10; it needs more than ' . self::dataCodewords(10) . ' bytes.'
        );
    }

    /** @return list<int> */
    private static function dataCodewords(int $version): int
    {
        [$total, $eccPerBlock, $blocksG1, $sizeG1, $blocksG2, $sizeG2] = self::BLOCKS_M[$version];
        return $blocksG1 * $sizeG1 + $blocksG2 * $sizeG2;
    }

    /**
     * Pad the segment to the version's data capacity, then pack it into codewords: a
     * terminator of up to four zero bits, zero to the next byte boundary, and alternating
     * 0xEC/0x11 pad codewords until the capacity is reached.
     *
     * @param list<int> $bits
     * @return list<int>
     */
    private static function bitsToCodewords(array $bits, int $version): array
    {
        $capacity = self::dataCodewords($version);
        $capacityBits = $capacity * 8;

        $terminator = min(4, $capacityBits - count($bits));
        for ($i = 0; $i < $terminator; $i++) {
            $bits[] = 0;
        }
        while (count($bits) % 8 !== 0) {
            $bits[] = 0;
        }

        $codewords = [];
        for ($i = 0; $i < count($bits); $i += 8) {
            $byte = 0;
            for ($j = 0; $j < 8; $j++) {
                $byte = ($byte << 1) | $bits[$i + $j];
            }
            $codewords[] = $byte;
        }

        $pad = [0xEC, 0x11];
        $i = 0;
        while (count($codewords) < $capacity) {
            $codewords[] = $pad[$i % 2];
            $i++;
        }

        return $codewords;
    }

    /**
     * Split the data into blocks, give each its own Reed-Solomon parity, and interleave
     * both sets the way the standard requires.
     *
     * @param list<int> $data
     * @return list<int>
     */
    private static function interleaveWithEcc(array $data, int $version): array
    {
        [$total, $eccPerBlock, $blocksG1, $sizeG1, $blocksG2, $sizeG2] = self::BLOCKS_M[$version];

        $dataBlocks = [];
        $eccBlocks = [];
        $offset = 0;

        for ($b = 0; $b < $blocksG1; $b++) {
            $block = array_slice($data, $offset, $sizeG1);
            $offset += $sizeG1;
            $dataBlocks[] = $block;
            $eccBlocks[] = self::reedSolomon($block, $eccPerBlock);
        }
        for ($b = 0; $b < $blocksG2; $b++) {
            $block = array_slice($data, $offset, $sizeG2);
            $offset += $sizeG2;
            $dataBlocks[] = $block;
            $eccBlocks[] = self::reedSolomon($block, $eccPerBlock);
        }

        $result = [];
        $longest = max(array_map('count', $dataBlocks));
        for ($i = 0; $i < $longest; $i++) {
            foreach ($dataBlocks as $block) {
                if (isset($block[$i])) {
                    $result[] = $block[$i];
                }
            }
        }
        for ($i = 0; $i < $eccPerBlock; $i++) {
            foreach ($eccBlocks as $block) {
                if (isset($block[$i])) {
                    $result[] = $block[$i];
                }
            }
        }

        return $result;
    }

    /**
     * Reed-Solomon remainder over GF(256) with the QR primitive polynomial 0x11D.
     *
     * @param list<int> $data
     * @return list<int>
     */
    private static function reedSolomon(array $data, int $eccCount): array
    {
        $divisor = self::rsDivisor($eccCount);
        $result = array_fill(0, $eccCount, 0);

        foreach ($data as $byte) {
            $factor = ($byte ^ $result[0]) & 0xFF;
            array_shift($result);
            $result[] = 0;
            for ($i = 0; $i < $eccCount; $i++) {
                $result[$i] ^= self::gfMultiply($divisor[$i], $factor);
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
                $result[$j] = self::gfMultiply($result[$j], $root);
                if ($j + 1 < $degree) {
                    $result[$j] ^= $result[$j + 1];
                }
            }
            $root = self::gfMultiply($root, 0x02);
        }

        return $result;
    }

    private static function gfMultiply(int $a, int $b): int
    {
        $product = 0;
        for ($i = 7; $i >= 0; $i--) {
            $product = (($product << 1) ^ (($product >> 7) * 0x11D)) & 0xFF;
            $product ^= (($b >> $i) & 1) * $a;
        }
        return $product & 0xFF;
    }

    /**
     * Lay the interleaved codewords into a module grid: function patterns first, then the
     * data in the standard zigzag, then the best data mask and the format/version blocks.
     *
     * @param list<int> $codewords
     * @return list<list<bool>>
     */
    private static function buildMatrix(array $codewords, int $version): array
    {
        $size = $version * 4 + 17;
        $modules = array_fill(0, $size, array_fill(0, $size, false));
        $reserved = array_fill(0, $size, array_fill(0, $size, false));

        self::placeFunctionPatterns($modules, $reserved, $size, $version);

        self::placeData($modules, $reserved, $size, $codewords);

        // Each mask is tried and scored; the standard requires the least-penalty one.
        $best = null;
        $bestPenalty = PHP_INT_MAX;
        for ($mask = 0; $mask < 8; $mask++) {
            $candidate = $modules;
            self::applyMask($candidate, $reserved, $size, $mask);
            self::placeFormatBits($candidate, $size, $mask);
            if ($version >= 7) {
                self::placeVersionBits($candidate, $size, $version);
            }
            $penalty = self::penalty($candidate, $size);
            if ($penalty < $bestPenalty) {
                $bestPenalty = $penalty;
                $best = $candidate;
            }
        }

        return $best;
    }

    /** @param list<list<bool>> $modules */
    private static function placeFunctionPatterns(array &$modules, array &$reserved, int $size, int $version): void
    {
        // Timing patterns.
        for ($i = 0; $i < $size; $i++) {
            self::setModule($modules, $reserved, $i, 6, $i % 2 === 0);
            self::setModule($modules, $reserved, 6, $i, $i % 2 === 0);
        }

        // The three finder patterns and their separators.
        self::placeFinder($modules, $reserved, $size, 3, 3);
        self::placeFinder($modules, $reserved, $size, $size - 4, 3);
        self::placeFinder($modules, $reserved, $size, 3, $size - 4);

        // Alignment patterns, skipping the corners the finders already occupy.
        $centres = self::ALIGNMENT[$version];
        $count = count($centres);
        for ($i = 0; $i < $count; $i++) {
            for ($j = 0; $j < $count; $j++) {
                $isFinderCorner = ($i === 0 && $j === 0)
                    || ($i === 0 && $j === $count - 1)
                    || ($i === $count - 1 && $j === 0);
                if (!$isFinderCorner) {
                    self::placeAlignment($modules, $reserved, $centres[$i], $centres[$j]);
                }
            }
        }

        // The format information areas, and the always-dark module.
        self::reserveFormatAreas($modules, $reserved, $size);

        // Reserve the version information areas from version 7 upward.
        if ($version >= 7) {
            for ($i = 0; $i < 6; $i++) {
                for ($j = 0; $j < 3; $j++) {
                    self::setModule($modules, $reserved, $i, $size - 11 + $j, false);
                    self::setModule($modules, $reserved, $size - 11 + $j, $i, false);
                }
            }
        }
    }

    /** @param list<list<bool>> $modules */
    private static function placeFinder(array &$modules, array &$reserved, int $size, int $cx, int $cy): void
    {
        for ($dy = -4; $dy <= 4; $dy++) {
            for ($dx = -4; $dx <= 4; $dx++) {
                $x = $cx + $dx;
                $y = $cy + $dy;
                if ($x < 0 || $x >= $size || $y < 0 || $y >= $size) {
                    continue;
                }
                $distance = max(abs($dx), abs($dy));
                self::setModule($modules, $reserved, $x, $y, $distance !== 2 && $distance <= 3);
            }
        }
    }

    /** @param list<list<bool>> $modules */
    private static function placeAlignment(array &$modules, array &$reserved, int $cx, int $cy): void
    {
        for ($dy = -2; $dy <= 2; $dy++) {
            for ($dx = -2; $dx <= 2; $dx++) {
                self::setModule(
                    $modules,
                    $reserved,
                    $cx + $dx,
                    $cy + $dy,
                    max(abs($dx), abs($dy)) !== 1
                );
            }
        }
    }

    /** @param list<list<bool>> $modules */
    private static function reserveFormatAreas(array &$modules, array &$reserved, int $size): void
    {
        for ($i = 0; $i <= 8; $i++) {
            if ($i !== 6) {
                self::setModule($modules, $reserved, $i, 8, false);
                self::setModule($modules, $reserved, 8, $i, false);
            }
        }
        for ($i = 0; $i < 8; $i++) {
            self::setModule($modules, $reserved, $size - 1 - $i, 8, false);
            self::setModule($modules, $reserved, 8, $size - 1 - $i, false);
        }
        // The dark module, which is never part of the data.
        self::setModule($modules, $reserved, 8, $size - 8, true);
    }

    /**
     * @param list<list<bool>> $modules
     * @param list<list<bool>> $reserved
     * @param list<int> $codewords
     */
    private static function placeData(array &$modules, array $reserved, int $size, array $codewords): void
    {
        $bitIndex = 0;
        $totalBits = count($codewords) * 8;

        for ($right = $size - 1; $right >= 1; $right -= 2) {
            if ($right === 6) {
                $right = 5;   // the vertical timing pattern is skipped entirely
            }
            for ($vert = 0; $vert < $size; $vert++) {
                for ($j = 0; $j < 2; $j++) {
                    $x = $right - $j;
                    $upward = ((($right + 1) & 2) === 0);
                    $y = $upward ? $size - 1 - $vert : $vert;

                    if (!$reserved[$y][$x] && $bitIndex < $totalBits) {
                        $modules[$y][$x] = (($codewords[$bitIndex >> 3] >> (7 - ($bitIndex & 7))) & 1) !== 0;
                        $bitIndex++;
                    }
                }
            }
        }
    }

    /** @param list<list<bool>> $modules */
    private static function setModule(array &$modules, array &$reserved, int $x, int $y, bool $dark): void
    {
        $modules[$y][$x] = $dark;
        $reserved[$y][$x] = true;
    }

    /**
     * @param list<list<bool>> $modules
     * @param list<list<bool>> $reserved
     */
    private static function applyMask(array &$modules, array $reserved, int $size, int $mask): void
    {
        for ($y = 0; $y < $size; $y++) {
            for ($x = 0; $x < $size; $x++) {
                if ($reserved[$y][$x]) {
                    continue;
                }
                if (self::maskBit($mask, $x, $y)) {
                    $modules[$y][$x] = !$modules[$y][$x];
                }
            }
        }
    }

    private static function maskBit(int $mask, int $x, int $y): bool
    {
        return match ($mask) {
            0 => ($x + $y) % 2 === 0,
            1 => $y % 2 === 0,
            2 => $x % 3 === 0,
            3 => ($x + $y) % 3 === 0,
            4 => ((intdiv($x, 3) + intdiv($y, 2)) % 2) === 0,
            5 => ($x * $y) % 2 + ($x * $y) % 3 === 0,
            6 => (($x * $y) % 2 + ($x * $y) % 3) % 2 === 0,
            7 => ((($x + $y) % 2 + ($x * $y) % 3) % 2) === 0,
            default => false,
        };
    }

    /**
     * The 15-bit format information: a 5-bit payload (error level plus mask) through a
     * BCH(15,5) code, then masked with 0x5412 so a reader never mistakes it for data.
     */
    private static function formatBits(int $mask): int
    {
        $data = (self::ECC_M_INDICATOR << 3) | $mask;
        $remainder = $data;
        for ($i = 0; $i < 10; $i++) {
            $remainder = (($remainder << 1) ^ ((($remainder >> 9) & 1) * 0x537)) & 0x3FF;
        }
        return (($data << 10) | $remainder) ^ 0x5412;
    }

    /** @param list<list<bool>> $modules */
    private static function placeFormatBits(array &$modules, int $size, int $mask): void
    {
        $bits = self::formatBits($mask);
        $bit = static fn(int $i): bool => (($bits >> $i) & 1) !== 0;

        // First copy, around the top-left finder.
        for ($i = 0; $i <= 5; $i++) {
            $modules[$i][8] = $bit($i);
        }
        $modules[7][8] = $bit(6);
        $modules[8][8] = $bit(7);
        $modules[8][7] = $bit(8);
        for ($i = 9; $i < 15; $i++) {
            $modules[8][14 - $i] = $bit($i);
        }

        // Second copy, split between the other two finders.
        for ($i = 0; $i < 8; $i++) {
            $modules[8][$size - 1 - $i] = $bit($i);
        }
        for ($i = 8; $i < 15; $i++) {
            $modules[$size - 15 + $i][8] = $bit($i);
        }

        $modules[$size - 8][8] = true;   // the always-dark module
    }

    /**
     * The 18-bit version information, a BCH(18,6) code, written in the two 3x6 blocks beside
     * the bottom-left and top-right finders.
     */
    private static function placeVersionBits(array &$modules, int $size, int $version): void
    {
        $remainder = $version;
        for ($i = 0; $i < 12; $i++) {
            $remainder = (($remainder << 1) ^ ((($remainder >> 11) & 1) * 0x1F25)) & 0xFFF;
        }
        $bits = ($version << 12) | $remainder;

        // Bit i maps to row intdiv($i, 3), column size - 11 + ($i % 3), then mirrored.
        for ($i = 0; $i < 18; $i++) {
            $bit = (($bits >> $i) & 1) !== 0;
            $col = $size - 11 + ($i % 3);
            $row = intdiv($i, 3);
            $modules[$row][$col] = $bit;
            $modules[$col][$row] = $bit;
        }
    }

    /**
     * The four penalty rules from the standard. Lower is better; the mask with the lowest
     * total is the one that scans most reliably.
     *
     * @param list<list<bool>> $modules
     */
    private static function penalty(array $modules, int $size): int
    {
        $score = 0;

        // Rule 1: runs of five or more modules of the same colour, per row and column.
        for ($y = 0; $y < $size; $y++) {
            $run = 1;
            for ($x = 1; $x < $size; $x++) {
                if ($modules[$y][$x] === $modules[$y][$x - 1]) {
                    $run++;
                } else {
                    $score += $run >= 5 ? self::PENALTY_N1 + ($run - 5) : 0;
                    $run = 1;
                }
            }
            $score += $run >= 5 ? self::PENALTY_N1 + ($run - 5) : 0;
        }
        for ($x = 0; $x < $size; $x++) {
            $run = 1;
            for ($y = 1; $y < $size; $y++) {
                if ($modules[$y][$x] === $modules[$y - 1][$x]) {
                    $run++;
                } else {
                    $score += $run >= 5 ? self::PENALTY_N1 + ($run - 5) : 0;
                    $run = 1;
                }
            }
            $score += $run >= 5 ? self::PENALTY_N1 + ($run - 5) : 0;
        }

        // Rule 2: every 2x2 block of one colour.
        for ($y = 0; $y < $size - 1; $y++) {
            for ($x = 0; $x < $size - 1; $x++) {
                $c = $modules[$y][$x];
                if ($c === $modules[$y][$x + 1] && $c === $modules[$y + 1][$x] && $c === $modules[$y + 1][$x + 1]) {
                    $score += self::PENALTY_N2;
                }
            }
        }

        // Rule 3: finder-like 1:1:3:1:1 patterns with four light modules beside them.
        $pattern = [true, false, true, true, true, false, true];
        for ($y = 0; $y < $size; $y++) {
            for ($x = 0; $x + 7 <= $size; $x++) {
                $matches = true;
                for ($i = 0; $i < 7; $i++) {
                    if ($modules[$y][$x + $i] !== $pattern[$i]) {
                        $matches = false;
                        break;
                    }
                }
                if ($matches) {
                    $score += self::PENALTY_N3;
                }
                $matches = true;
                for ($i = 0; $i < 7; $i++) {
                    if ($modules[$y][$x + $i] !== $pattern[6 - $i]) {
                        $matches = false;
                        break;
                    }
                }
                if ($matches) {
                    $score += self::PENALTY_N3;
                }
            }
        }
        for ($x = 0; $x < $size; $x++) {
            for ($y = 0; $y + 7 <= $size; $y++) {
                $matches = true;
                for ($i = 0; $i < 7; $i++) {
                    if ($modules[$y + $i][$x] !== $pattern[$i]) {
                        $matches = false;
                        break;
                    }
                }
                if ($matches) {
                    $score += self::PENALTY_N3;
                }
                $matches = true;
                for ($i = 0; $i < 7; $i++) {
                    if ($modules[$y + $i][$x] !== $pattern[6 - $i]) {
                        $matches = false;
                        break;
                    }
                }
                if ($matches) {
                    $score += self::PENALTY_N3;
                }
            }
        }

        // Rule 4: how far the dark/light balance strays from half.
        $dark = 0;
        foreach ($modules as $row) {
            foreach ($row as $value) {
                if ($value) {
                    $dark++;
                }
            }
        }
        $total = $size * $size;
        $percent = ($dark * 100) / $total;
        $score += (int) (floor(abs($percent - 50) / 5) * self::PENALTY_N4);

        return $score;
    }
}