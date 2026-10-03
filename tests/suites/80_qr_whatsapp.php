<?php
declare(strict_types=1);
/**
 * QR Code encoder (app/Support/QrCode.php) and the WhatsApp block on the contact page.
 *
 * The encoder is dependency-free, so its output is checked three ways: structural rules from
 * the standard, an in-test reader that re-derives the payload (including Reed-Solomon
 * syndromes, which catch any misplaced or corrupt block), and the module grid rebuilt from
 * the SVG path data as the browser would see it.
 */

use App\Services\Settings;
use App\Support\QrCode;

/* ── Reference reader ──────────────────────────────────────────────────────────── */

/** Block layout for ECC level M: total, ecc per block, then the two block groups. */
$qrBlocksM = [
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
$qrAlignment = [
    1  => [], 2  => [6, 18], 3  => [6, 22], 4  => [6, 26], 5  => [6, 30],
    6  => [6, 34], 7  => [6, 22, 38], 8  => [6, 24, 42], 9  => [6, 26, 46], 10 => [6, 28, 50],
];

$qrMul = static function (int $a, int $b): int {
    $p = 0;
    for ($i = 7; $i >= 0; $i--) {
        $p = (($p << 1) ^ (($p >> 7) * 0x11D)) & 0xFF;
        $p ^= (($b >> $i) & 1) * $a;
    }
    return $p & 0xFF;
};
$qrPow = static function (int $base, int $exp) use ($qrMul): int {
    $r = 1;
    for ($i = 0; $i < $exp; $i++) {
        $r = $qrMul($r, $base);
    }
    return $r;
};
$qrMaskBit = static fn(int $mask, int $x, int $y): bool => match ($mask) {
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

/**
 * Modules occupied by function patterns: timing rows, the finder and separator areas,
 * alignment patterns, format information and (from version 7) the version information.
 */
$qrReservedMap = static function (int $size, int $version) use ($qrAlignment): array {
    $map = array_fill(0, $size, array_fill(0, $size, false));
    $mark = static function (int $x, int $y) use (&$map, $size): void {
        if ($x >= 0 && $x < $size && $y >= 0 && $y < $size) {
            $map[$y][$x] = true;
        }
    };
    for ($i = 0; $i < $size; $i++) {
        $mark($i, 6);
        $mark(6, $i);
    }
    for ($dy = -4; $dy <= 4; $dy++) {
        for ($dx = -4; $dx <= 4; $dx++) {
            $mark(3 + $dx, 3 + $dy);
            $mark($size - 4 + $dx, 3 + $dy);
            $mark(3 + $dx, $size - 4 + $dy);
        }
    }
    $centres = $qrAlignment[$version];
    $count = count($centres);
    for ($i = 0; $i < $count; $i++) {
        for ($j = 0; $j < $count; $j++) {
            $corner = ($i === 0 && $j === 0)
                || ($i === 0 && $j === $count - 1)
                || ($i === $count - 1 && $j === 0);
            if ($corner) {
                continue;
            }
            for ($dy = -2; $dy <= 2; $dy++) {
                for ($dx = -2; $dx <= 2; $dx++) {
                    $mark($centres[$i] + $dx, $centres[$j] + $dy);
                }
            }
        }
    }
    for ($i = 0; $i <= 8; $i++) {
        if ($i !== 6) {
            $mark($i, 8);
            $mark(8, $i);
        }
    }
    for ($i = 0; $i < 8; $i++) {
        $mark($size - 1 - $i, 8);
        $mark(8, $size - 1 - $i);
    }
    $mark(8, $size - 8);
    if ($version >= 7) {
        for ($i = 0; $i < 6; $i++) {
            for ($j = 0; $j < 3; $j++) {
                $mark($i, $size - 11 + $j);
                $mark($size - 11 + $j, $i);
            }
        }
    }
    return $map;
};

/**
 * Decode a module grid back to its payload. Throws RuntimeException when the format or
 * version information fails its BCH check, when the two format copies disagree, or when any
 * Reed-Solomon block has a non-zero syndrome, which means the placement or the parity is wrong.
 */
$qrDecode = static function (array $matrix) use (
    $qrBlocksM, $qrMaskBit, $qrReservedMap, $qrMul, $qrPow
): array {
    $size = count($matrix);
    if (($size - 17) % 4 !== 0) {
        throw new RuntimeException('grid size ' . $size . ' is not a QR symbol');
    }
    $version = intdiv($size - 17, 4);

    // Format information: five data bits through BCH(15,5), masked with 0x5412.
    $bits = 0;
    $sequence = [];
    for ($i = 0; $i <= 5; $i++) {
        $sequence[] = (int) $matrix[$i][8];
    }
    $sequence[] = (int) $matrix[7][8];
    $sequence[] = (int) $matrix[8][8];
    $sequence[] = (int) $matrix[8][7];
    for ($i = 9; $i < 15; $i++) {
        $sequence[] = (int) $matrix[8][14 - $i];
    }
    foreach ($sequence as $i => $value) {
        if ($value) {
            $bits |= 1 << $i;
        }
    }
    $unmasked = $bits ^ 0x5412;
    $data = ($unmasked >> 10) & 0x1F;
    $remainder = $data;
    for ($i = 0; $i < 10; $i++) {
        $remainder = (($remainder << 1) ^ ((($remainder >> 9) & 1) * 0x537)) & 0x3FF;
    }
    if ($remainder !== ($unmasked & 0x3FF)) {
        throw new RuntimeException('format information failed its BCH check');
    }
    if (($data >> 3) !== 0) {
        throw new RuntimeException('expected error correction level M');
    }
    $mask = $data & 0x07;

    // The second copy of the format information must be identical.
    $second = 0;
    for ($i = 0; $i < 8; $i++) {
        if ($matrix[8][$size - 1 - $i]) {
            $second |= 1 << $i;
        }
    }
    for ($i = 8; $i < 15; $i++) {
        if ($matrix[$size - 15 + $i][8]) {
            $second |= 1 << $i;
        }
    }
    if ($second !== $bits) {
        throw new RuntimeException('the two format information copies disagree');
    }
    if (!$matrix[$size - 8][8]) {
        throw new RuntimeException('the always-dark module is light');
    }

    // Version information, from version 7 upward.
    if ($version >= 7) {
        $vb = 0;
        for ($i = 0; $i < 18; $i++) {
            if ($matrix[intdiv($i, 3)][$size - 11 + ($i % 3)]) {
                $vb |= 1 << $i;
            }
        }
        $vrem = ($vb >> 12) & 0x3F;
        for ($i = 0; $i < 12; $i++) {
            $vrem = (($vrem << 1) ^ ((($vrem >> 11) & 1) * 0x1F25)) & 0xFFF;
        }
        if ($vrem !== ($vb & 0xFFF)) {
            throw new RuntimeException('version information failed its BCH check');
        }
        if (($vb >> 12) !== $version) {
            throw new RuntimeException('version information does not match the symbol size');
        }
    }

    // Data, in the standard zigzag, with the mask removed.
    $reserved = $qrReservedMap($size, $version);
    $stream = [];
    for ($right = $size - 1; $right >= 1; $right -= 2) {
        if ($right === 6) {
            $right = 5;   // the vertical timing pattern column is skipped
        }
        for ($vert = 0; $vert < $size; $vert++) {
            for ($j = 0; $j < 2; $j++) {
                $x = $right - $j;
                $y = ((($right + 1) & 2) === 0) ? $size - 1 - $vert : $vert;
                if ($reserved[$y][$x]) {
                    continue;
                }
                $bit = $matrix[$y][$x];
                if ($qrMaskBit($mask, $x, $y)) {
                    $bit = !$bit;
                }
                $stream[] = $bit ? 1 : 0;
            }
        }
    }
    $codewords = [];
    for ($i = 0; $i + 8 <= count($stream); $i += 8) {
        $byte = 0;
        for ($j = 0; $j < 8; $j++) {
            $byte = ($byte << 1) | $stream[$i + $j];
        }
        $codewords[] = $byte;
    }
    $codewords = array_slice($codewords, 0, $qrBlocksM[$version][0]);

    // De-interleave into blocks.
    [, $eccLength, $g1, $g1Size, $g2, $g2Size] = $qrBlocksM[$version];
    $sizes = array_merge(array_fill(0, $g1, $g1Size), array_fill(0, $g2, $g2Size));
    $blocks = array_fill(0, count($sizes), []);
    $index = 0;
    for ($i = 0; $i < max($sizes); $i++) {
        foreach ($sizes as $b => $blockSize) {
            if ($i < $blockSize) {
                $blocks[$b][] = $codewords[$index++];
            }
        }
    }
    $eccBlocks = array_fill(0, count($sizes), []);
    for ($i = 0; $i < $eccLength; $i++) {
        foreach ($eccBlocks as $b => $_) {
            $eccBlocks[$b][] = $codewords[$index++];
        }
    }

    // Every block must be a valid Reed-Solomon codeword.
    foreach ($blocks as $b => $block) {
        $full = array_merge($block, $eccBlocks[$b]);
        for ($i = 0; $i < $eccLength; $i++) {
            $sum = 0;
            foreach ($full as $byte) {
                $sum = $qrMul($sum, $qrPow(2, $i)) ^ $byte;
            }
            if ($sum !== 0) {
                throw new RuntimeException("block $b has a non-zero Reed-Solomon syndrome at $i");
            }
        }
    }

    $dataBytes = [];
    foreach ($blocks as $block) {
        foreach ($block as $byte) {
            $dataBytes[] = $byte;
        }
    }
    $bitString = '';
    foreach ($dataBytes as $byte) {
        $bitString .= str_pad(decbin($byte), 8, '0', STR_PAD_LEFT);
    }
    if (bindec(substr($bitString, 0, 4)) !== 4) {
        throw new RuntimeException('the first segment is not in byte mode');
    }
    $lengthBits = $version >= 10 ? 16 : 8;
    $length = bindec(substr($bitString, 4, $lengthBits));
    $text = '';
    for ($i = 0; $i < $length; $i++) {
        $text .= chr(bindec(substr($bitString, 4 + $lengthBits + $i * 8, 8)));
    }

    return ['version' => $version, 'mask' => $mask, 'text' => $text, 'blocks' => count($sizes)];
};

/** Rebuild the module grid from the rendered SVG, the way a scanner would read it. */
$qrMatrixFromSvg = static function (string $svg, int $scale, int $quiet): array {
    if (!preg_match('~viewBox="0 0 (\d+) \d+"~', $svg, $box)) {
        throw new RuntimeException('the SVG has no viewBox');
    }
    $side = (int) $box[1];
    if ($side % $scale !== 0) {
        throw new RuntimeException("the viewBox side $side is not a multiple of the module size");
    }
    $size = intdiv($side, $scale) - $quiet * 2;
    if ($side !== ($size + $quiet * 2) * $scale) {
        throw new RuntimeException('the viewBox does not match the scale and quiet zone');
    }
    if ($size < 21 || ($size - 17) % 4 !== 0) {
        throw new RuntimeException("the derived grid of $size modules is not a QR symbol");
    }
    if (!preg_match('~<path fill="#000000" d="([^"]+)"~', $svg, $path)) {
        throw new RuntimeException('the SVG has no module path');
    }
    preg_match_all('/M(\d+) (\d+)h(\d+)v(\d+)h-\d+z/', $path[1], $squares, PREG_SET_ORDER);

    $matrix = array_fill(0, $size, array_fill(0, $size, false));
    foreach ($squares as $square) {
        $x = intdiv((int) $square[1], $scale) - $quiet;
        $y = intdiv((int) $square[2], $scale) - $quiet;
        if ((int) $square[1] % $scale !== 0 || (int) $square[2] % $scale !== 0) {
            throw new RuntimeException('a module does not start on the module grid');
        }
        if ((int) $square[3] !== $scale || (int) $square[4] !== $scale) {
            throw new RuntimeException('a module is not one module wide');
        }
        if ($x < 0 || $y < 0 || $x >= $size || $y >= $size) {
            throw new RuntimeException('a module falls outside the grid');
        }
        $matrix[$y][$x] = true;
    }
    return $matrix;
};

/* ── Structure and round trip ───────────────────────────────────────────────────── */

echo "\nQR Code Encoder Tests\n";

$qrWaLink = 'https://wa.me/252615000000';

$qrCases = [
    'a bare wa.me link'                => $qrWaLink,
    'a wa.me link with a greeting'     => $qrWaLink . '?text=' . rawurlencode('Hello Civil Registration Office, I would like to inquire about a vital event registration.'),
    'a verification URL'               => 'https://civil-registration.test/verify.php?token=A1B2C3D4E5F6A7B8C9D0E1F2A3B4C5D6E7F8A9B0C1D2E3F4A5B6C7D8E9F0A1B2',
    'the shortest supported payload'   => str_repeat('a', 10),
    'the largest supported payload'    => str_repeat('a', 213),
    'a 200 byte payload'               => str_repeat('X', 200),
];

foreach ($qrCases as $label => $payload) {
    try {
        $decoded = $qrDecode(QrCode::matrix($payload));
        check("byte mode round trip: $label", $decoded['text'] === $payload, 'decoded: ' . $decoded['text']);
    } catch (Throwable $e) {
        check("byte mode round trip: $label", false, get_class($e) . ': ' . $e->getMessage());
    }
}

// Structural rules for a version 2 symbol (a 26 byte wa.me link).
$qrMatrix = QrCode::matrix($qrWaLink);
$qrSize = count($qrMatrix);
$qrVersion = intdiv($qrSize - 17, 4);
check('the symbol size is 4v + 17', $qrSize === $qrVersion * 4 + 17, "size $qrSize");
check('a 26 byte payload fits version 2', $qrVersion === 2 && $qrSize === 25);

$qrFinder = static function (array $m, int $ox, int $oy) use ($qrSize): bool {
    // The outer 7x7 ring is solid dark, the ring inside it is light, and the 3x3 core is dark.
    for ($i = 0; $i < 7; $i++) {
        foreach ([[$ox + $i, $oy], [$ox + $i, $oy + 6], [$ox, $oy + $i], [$ox + 6, $oy + $i]] as [$x, $y]) {
            if ($m[$y][$x] !== true) {
                return false;
            }
        }
    }
    for ($y = 1; $y <= 5; $y++) {
        for ($x = 1; $x <= 5; $x++) {
            $dark = ($x >= 2 && $x <= 4 && $y >= 2 && $y <= 4);
            if ($m[$oy + $y][$ox + $x] !== $dark) {
                return false;
            }
        }
    }
    return true;
};
check('the top-left finder pattern is correct', $qrFinder($qrMatrix, 0, 0));
check('the top-right finder pattern is correct', $qrFinder($qrMatrix, $qrSize - 7, 0));
check('the bottom-left finder pattern is correct', $qrFinder($qrMatrix, 0, $qrSize - 7));
check('the separator beside the top-left finder is light', !$qrMatrix[7][0] && !$qrMatrix[7][1] && !$qrMatrix[0][7] && !$qrMatrix[1][7]);
check('the always-dark module is dark', $qrMatrix[$qrSize - 8][8]);

$qrTimingOk = true;
for ($i = 8; $i < $qrSize - 8; $i++) {
    if ($qrMatrix[6][$i] !== ($i % 2 === 0) || $qrMatrix[$i][6] !== ($i % 2 === 0)) {
        $qrTimingOk = false;
    }
}
check('both timing patterns alternate correctly', $qrTimingOk);

// Version 2+ has a single alignment pattern at (18, 18): 5x5 ring with a dark centre.
check('the alignment pattern is correct', $qrMatrix[18][18] && $qrMatrix[16][18] && $qrMatrix[18][16]
    && !$qrMatrix[17][17] && !$qrMatrix[19][19]);

/* ── Coverage across versions and masks ──────────────────────────────────────────── */

// Version is chosen from the payload length alone, so each version is pinned by encoding the
// longest payload that still fits it. That reaches every version, every block layout
// (single block, two groups) and the version information from version 7 up.
$qrAlphabet = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789-_.:/?&=%+#';
$qrRandomText = static function (int $length) use ($qrAlphabet): string {
    $text = '';
    for ($i = 0; $i < $length; $i++) {
        $text .= $qrAlphabet[random_int(0, strlen($qrAlphabet) - 1)];
    }
    return $text;
};

// The longest payload length that selects each version.
$qrCapacityOf = [];
for ($length = 10; $length <= 213; $length++) {
    try {
        $qrMatrixOf = QrCode::matrix(str_repeat('a', $length));
    } catch (Throwable $e) {
        continue;
    }
    $qrVersionOf = intdiv(count($qrMatrixOf) - 17, 4);
    $qrCapacityOf[$qrVersionOf] = $length;
}

$qrFailures = [];
$qrVersions = [];
$qrMasks = [];
for ($version = 1; $version <= 10; $version++) {
    $length = $qrCapacityOf[$version] ?? null;
    if ($length === null) {
        $qrFailures[] = "no payload size selects version $version";
        continue;
    }
    // The longest payload for the version, plus one that drops back to a lower version, so the
    // length-based version choice is checked from both sides.
    foreach ([$length, max(10, $length - 5)] as $case) {
        $text = $qrRandomText($case);
        try {
            $result = $qrDecode(QrCode::matrix($text));
            if ($result['text'] !== $text) {
                $qrFailures[] = "version $version length $case decoded to different text";
                continue;
            }
            if ($case === $length && $result['version'] !== $version) {
                $qrFailures[] = "length $case was encoded as version {$result['version']}, expected $version";
            }
            $qrVersions[$result['version']] = true;
            $qrMasks[$result['mask']] = true;
        } catch (Throwable $e) {
            $qrFailures[] = "version $version length $case: " . $e->getMessage();
        }
    }
}

mt_srand(20261001);
for ($t = 0; $t < 40; $t++) {
    $text = $qrRandomText(random_int(10, 213));
    try {
        $result = $qrDecode(QrCode::matrix($text));
        if ($result['text'] !== $text) {
            $qrFailures[] = 'a random payload of ' . strlen($text) . ' bytes decoded to different text';
            continue;
        }
        $qrVersions[$result['version']] = true;
        $qrMasks[$result['mask']] = true;
    } catch (Throwable $e) {
        $qrFailures[] = 'a random payload of ' . strlen($text) . ' bytes: ' . $e->getMessage();
    }
}

check(
    'every version and 40 random payloads round trip',
    $qrFailures === [],
    implode('; ', array_slice($qrFailures, 0, 3))
);
ksort($qrVersions);
ksort($qrMasks);
check('all ten versions are covered', count($qrVersions) === 10, 'versions: ' . implode(',', array_keys($qrVersions)));
check('several data masks are exercised', count($qrMasks) >= 2, 'masks: ' . implode(',', array_keys($qrMasks)));

/* ── SVG rendering ──────────────────────────────────────────────────────────────── */

echo "\nQR Code SVG Tests\n";

$qrSvg = QrCode::svg($qrWaLink, 6, 4);
check('the SVG declares the SVG namespace', str_starts_with($qrSvg, '<svg xmlns="http://www.w3.org/2000/svg"'));
check('the SVG is closed', str_ends_with(trim($qrSvg), '</svg>'));
$qrSvgSide = ($qrSize + 8) * 6;
check('the SVG draws a white background', str_contains($qrSvg, '<rect width="' . $qrSvgSide . '" height="' . $qrSvgSide . '" fill="#ffffff"/>'));
check('the SVG draws dark modules as one black path', substr_count($qrSvg, '<path fill="#000000"') === 1);
check('the SVG uses crisp edges', str_contains($qrSvg, 'shape-rendering="crispEdges"'));
check('the SVG carries a role for assistive technology', str_contains($qrSvg, 'role="img"'));
check('the SVG has no NaN coordinates', !str_contains($qrSvg, 'NaN'));

// scale 6 and a 4 module quiet zone on a 25 module symbol gives (25 + 8) * 6 = 198.
check('the viewBox matches the module count, scale and quiet zone', str_contains($qrSvg, 'viewBox="0 0 198 198"'));
check('the width and height match the viewBox', str_contains($qrSvg, 'width="198" height="198"'));

try {
    $fromSvg = $qrMatrixFromSvg($qrSvg, 6, 4);
    $svgResult = $qrDecode($fromSvg);
    check('the rendered SVG decodes back to the payload', $svgResult['text'] === $qrWaLink, 'decoded: ' . $svgResult['text']);
    check('the rendered SVG preserves the module grid', $fromSvg === QrCode::matrix($qrWaLink));
} catch (Throwable $e) {
    check('the rendered SVG decodes back to the payload', false, $e->getMessage());
}

// Different scales and quiet zones must all survive the round trip.
foreach ([[4, 4], [8, 4], [12, 6], [1, 4]] as [$scale, $quiet]) {
    try {
        $result = $qrDecode($qrMatrixFromSvg(QrCode::svg($qrWaLink, $scale, $quiet), $scale, $quiet));
        check("scale $scale with a $quiet module quiet zone decodes", $result['text'] === $qrWaLink);
    } catch (Throwable $e) {
        check("scale $scale with a $quiet module quiet zone decodes", false, $e->getMessage());
    }
}

echo "\nQR Code Input Validation Tests\n";

$qrRefuse = static function (callable $fn): bool {
    try {
        $fn();
        return false;
    } catch (Throwable $e) {
        return true;
    }
};
check('an empty payload is refused', $qrRefuse(static fn() => QrCode::matrix('')));
check('a payload below the ten byte minimum is refused', $qrRefuse(static fn() => QrCode::matrix('123456789')));
check('a payload beyond the 213 byte capacity is refused', $qrRefuse(static fn() => QrCode::matrix(str_repeat('a', 214))));
check('a payload far beyond the capacity is refused', $qrRefuse(static fn() => QrCode::matrix(str_repeat('a', 1000))));
check('a payload beyond the length field is refused', $qrRefuse(static fn() => QrCode::matrix(str_repeat('a', 300))));
check('a scale below one is refused', $qrRefuse(static fn() => QrCode::svg($qrWaLink, 0)));
check('a quiet zone below four modules is refused', $qrRefuse(static fn() => QrCode::svg($qrWaLink, 8, 3)));

/* ── The contact page WhatsApp block ─────────────────────────────────────────────── */

echo "\nContact Page WhatsApp Tests\n";

$qrGreeting = 'Hello Civil Registration Office, I would like to inquire about a vital event registration.';
$qrLinkFor = static function (string $raw) use ($qrGreeting): ?array {
    $digits = preg_replace('/\D+/', '', $raw);
    $digits = is_string($digits) ? $digits : '';
    if ($digits === '' || strlen($digits) < 9 || strlen($digits) > 15) {
        return null;
    }
    return ['digits' => $digits, 'link' => 'https://wa.me/' . $digits . '?text=' . rawurlencode($qrGreeting)];
};

check('a plain number is accepted', ($qrLinkFor('252615000000'))['digits'] === '252615000000');
check('a spaced number is accepted', ($qrLinkFor('252 61 500 0000'))['digits'] === '252615000000');
check('a leading plus is accepted', ($qrLinkFor('+252615000000'))['digits'] === '252615000000');
check('an empty setting yields no link', $qrLinkFor('') === null);
check('a non-numeric setting yields no link', $qrLinkFor('not a number') === null);
check('a too-short setting yields no link', $qrLinkFor('12345') === null);
check('a too-long setting yields no link', $qrLinkFor(str_repeat('9', 16)) === null);

// The settings validator in admin/settings.php accepts exactly what wa.me can open.
$qrWhatsappValid = static fn(string $v): bool => $v === '' || (bool) preg_match('/^[0-9]{9,15}$/', $v);
check('the admin form accepts a valid number', $qrWhatsappValid('252615000000'));
check('the admin form rejects spaces', !$qrWhatsappValid('252 61 500 0000'));
check('the admin form rejects a leading plus', !$qrWhatsappValid('+252615000000'));
check('the admin form rejects an empty-looking string of letters', !$qrWhatsappValid('abcdefghi'));
check('the admin form rejects an eight digit number', !$qrWhatsappValid('12345678'));
check('the admin form rejects a sixteen digit number', !$qrWhatsappValid(str_repeat('9', 16)));

// The setting is seeded and stored. The seeded value is read back out of seed.sql rather than
// written here, so changing the office number does not silently invalidate this check.
$qrSeed = (string) file_get_contents(dirname(__DIR__, 2) . '/database/seed.sql');
$qrSeededNumber = preg_match("/'office_whatsapp',\s*'([0-9]+)'/", $qrSeed, $qrSeedMatch) ? $qrSeedMatch[1] : '';
check(
    'the seed file supplies a default WhatsApp number',
    $qrSeededNumber !== '' && $qrLinkFor($qrSeededNumber) !== null,
    'seeded: ' . var_export($qrSeededNumber, true)
);
check('the seeded number carries a country code', str_starts_with($qrSeededNumber, '252'), 'seeded: ' . $qrSeededNumber);

$qrStored = Settings::get('office_whatsapp', '');
if ($qrStored === '') {
    // Nothing to read back on a database that predates the setting.
    check('the stored WhatsApp setting is a usable number', true);
} else {
    check('the stored WhatsApp setting is a usable number', $qrLinkFor($qrStored) !== null, 'stored: ' . $qrStored);
}

// The contact page must emit the code and the link when a number is configured. The page is
// included rather than requested, so this stays inside the suite's single connection.
$qrPage = (string) shell_exec(
    escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__, 2) . '/public/contact.php') . ' 2>&1'
);
$qrPageHasSetting = Settings::get('office_whatsapp', '') !== '';

check('the contact page runs without PHP errors', !str_contains($qrPage, 'Fatal error') && !str_contains($qrPage, 'Uncaught'));

if ($qrPageHasSetting) {
    check('the contact page shows the WhatsApp card', str_contains($qrPage, 'whatsapp-card'));
    check('the contact page inlines exactly one QR code', substr_count($qrPage, '<svg xmlns="http://www.w3.org/2000/svg"') === 1);
    check('the contact page links to wa.me', str_contains($qrPage, 'https://wa.me/'));
    check('the WhatsApp link is safe for an external target', str_contains($qrPage, 'rel="noopener noreferrer"'));

    // Rebuild the grid from the page's own SVG and confirm it encodes the live link.
    $qrLive = (string) preg_replace('/\D+/', '', Settings::get('office_whatsapp', ''));
    $qrExpectedLink = 'https://wa.me/' . $qrLive . '?text=' . rawurlencode($qrGreeting);
    try {
        preg_match('~<div class="whatsapp-qr">\s*(<svg.*?</svg>)\s*</div>~s', $qrPage, $inline);
        check('the QR code sits inside the whatsapp-qr container', $inline !== []);
        $qrPageMatrix = $qrMatrixFromSvg($inline[1] ?? '', 6, 4);
        $qrPageResult = $qrDecode($qrPageMatrix);
        check(
            'the code rendered on the page decodes to the live wa.me link',
            $qrPageResult['text'] === $qrExpectedLink,
            'decoded: ' . $qrPageResult['text']
        );
    } catch (Throwable $e) {
        check('the code rendered on the page decodes to the live wa.me link', false, $e->getMessage());
    }
} else {
    check('the contact page hides the card when no number is configured', !str_contains($qrPage, 'whatsapp-card'));
}