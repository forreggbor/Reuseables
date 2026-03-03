<?php

/**
 * Copyright (C) 2025 PatrikMol Solutions Kft. All rights reserved.
 *
 * Pure PHP QR code generator for MFA TOTP setup.
 * Core algorithms ported from Kazuhiko Arase's qrcode-generator (MIT license).
 */

declare(strict_types=1);

namespace MFA;

/**
 * QRCode - Pure PHP QR Code Generator
 *
 * Generates QR codes without external libraries or PHP extensions.
 * Implements byte-mode encoding with EC level M.
 * Core algorithms based on Kazuhiko Arase's qrcode-generator (MIT license).
 *
 * @package MFA
 * @version 1.0.1
 */
class QRCode
{
    /** @var int Pad byte 1 */
    private const PAD0 = 0xEC;

    /** @var int Pad byte 2 */
    private const PAD1 = 0x11;

    /**
     * Format info BCH generator polynomial G15:
     * x^10 + x^8 + x^5 + x^4 + x^2 + x + 1
     */
    private const G15 = (1 << 10) | (1 << 8) | (1 << 5) | (1 << 4) | (1 << 2) | (1 << 1) | (1 << 0);

    /**
     * Format info XOR mask:
     * x^14 + x^12 + x^10 + x^4 + x
     */
    private const G15_MASK = (1 << 14) | (1 << 12) | (1 << 10) | (1 << 4) | (1 << 1);

    /**
     * Version info BCH generator polynomial G18:
     * x^12 + x^11 + x^10 + x^9 + x^8 + x^5 + x^2 + 1
     * Used for versions 7+ only.
     */
    private const G18 = (1 << 12) | (1 << 11) | (1 << 10) | (1 << 9) | (1 << 8) | (1 << 5) | (1 << 2) | (1 << 0);

    /**
     * Alignment pattern center positions per version (1-indexed).
     * Version 1 has no alignment patterns.
     *
     * @var array<int, array<int>>
     */
    private const ALIGNMENT_POSITIONS = [
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
        11 => [6, 30, 54],
        12 => [6, 32, 58],
        13 => [6, 34, 62],
        14 => [6, 26, 46, 66],
        15 => [6, 26, 48, 70],
        16 => [6, 26, 50, 74],
        17 => [6, 30, 54, 78],
        18 => [6, 30, 56, 82],
        19 => [6, 30, 58, 86],
        20 => [6, 34, 62, 90],
    ];

    /**
     * Maximum bytes encodable per version at EC level M in byte mode.
     *
     * @var array<int, int>
     */
    private const VERSION_CAPACITY = [
        1  => 14,  2  => 26,  3  => 42,  4  => 62,  5  => 84,
        6  => 106, 7  => 122, 8  => 152, 9  => 180, 10 => 213,
        11 => 251, 12 => 287, 13 => 331, 14 => 362, 15 => 412,
        16 => 450, 17 => 504, 18 => 560, 19 => 624, 20 => 666,
    ];

    /**
     * RS block table for all 40 versions × 4 EC levels.
     * Format per entry: [count1, total1, data1, count2, total2, data2, ...]
     * Index: (version - 1) * 4 + ec_level where L=0, M=1, Q=2, H=3
     *
     * Source: Kazuhiko Arase's qrcode-generator (MIT license)
     *
     * @var array<int, array<int>>
     */
    private const RS_BLOCK_TABLE = [
        // 1
        [1, 26, 19],  [1, 26, 16],  [1, 26, 13],  [1, 26, 9],
        // 2
        [1, 44, 34],  [1, 44, 28],  [1, 44, 22],  [1, 44, 16],
        // 3
        [1, 70, 55],  [1, 70, 44],  [2, 35, 17],  [2, 35, 13],
        // 4
        [1, 100, 80], [2, 50, 32],  [2, 50, 24],  [4, 25, 9],
        // 5
        [1, 134, 108], [2, 67, 43], [2, 33, 15, 2, 34, 16], [2, 33, 11, 2, 34, 12],
        // 6
        [2, 86, 68],  [4, 43, 27],  [4, 43, 19],  [4, 43, 15],
        // 7
        [2, 98, 78],  [4, 49, 31],  [2, 32, 14, 4, 33, 15], [4, 39, 13, 1, 40, 14],
        // 8
        [2, 121, 97], [2, 60, 38, 2, 61, 39], [4, 40, 18, 2, 41, 19], [4, 40, 14, 2, 41, 15],
        // 9
        [2, 146, 116], [3, 58, 36, 2, 59, 37], [4, 36, 16, 4, 37, 17], [4, 36, 12, 4, 37, 13],
        // 10
        [2, 86, 68, 2, 87, 69],   [4, 69, 43, 1, 70, 44],   [6, 43, 19, 2, 44, 20],  [6, 43, 15, 2, 44, 16],
        // 11
        [4, 101, 81], [1, 80, 50, 4, 81, 51], [4, 50, 22, 4, 51, 23], [3, 36, 12, 8, 37, 13],
        // 12
        [2, 116, 92, 2, 117, 93], [6, 58, 36, 2, 59, 37], [4, 46, 20, 6, 47, 21], [7, 42, 14, 4, 43, 15],
        // 13
        [4, 133, 107], [8, 59, 37, 1, 60, 38], [8, 44, 20, 4, 45, 21], [12, 33, 11, 4, 34, 12],
        // 14
        [3, 145, 115, 1, 146, 116], [4, 64, 40, 5, 65, 41], [11, 36, 16, 5, 37, 17], [11, 36, 12, 5, 37, 13],
        // 15
        [5, 109, 87, 1, 110, 88], [5, 65, 41, 5, 66, 42], [5, 54, 24, 7, 55, 25], [11, 36, 12, 7, 37, 13],
        // 16
        [5, 122, 98, 1, 123, 99], [7, 73, 45, 3, 74, 46], [15, 43, 19, 2, 44, 20], [3, 45, 15, 13, 46, 16],
        // 17
        [1, 135, 107, 5, 136, 108], [10, 74, 46, 1, 75, 47], [1, 50, 22, 15, 51, 23], [2, 42, 14, 17, 43, 15],
        // 18
        [5, 150, 120, 1, 151, 121], [9, 69, 43, 4, 70, 44], [17, 50, 22, 1, 51, 23], [2, 42, 14, 19, 43, 15],
        // 19
        [3, 141, 113, 4, 142, 114], [3, 70, 44, 11, 71, 45], [17, 47, 21, 4, 48, 22], [9, 39, 13, 16, 40, 14],
        // 20
        [3, 135, 107, 5, 136, 108], [3, 67, 41, 13, 68, 42], [15, 54, 24, 5, 55, 25], [15, 43, 15, 10, 44, 16],
    ];

    /** @var array<int> GF(256) exponent table */
    private static array $gfExp = [];

    /** @var array<int> GF(256) log table */
    private static array $gfLog = [];

    /**
     * Generate QR code as PNG binary.
     *
     * @param string $data Data to encode
     * @param int $moduleSize Pixel size per module (default: 4)
     * @param int $margin Quiet zone width in modules (default: 4)
     * @return string PNG binary data
     * @throws \InvalidArgumentException If data is too long for supported versions
     */
    public static function generate(string $data, int $moduleSize = 4, int $margin = 4): string
    {
        $version  = self::getVersion(strlen($data));
        $size     = $version * 4 + 17;
        $codewords = self::buildCodewords($data, $version);

        $bestPenalty = PHP_INT_MAX;
        $bestMatrix  = null;

        for ($mask = 0; $mask < 8; $mask++) {
            $matrix  = self::buildMatrix($size, $version, $codewords, $mask);
            $penalty = self::getLostPoint($matrix, $size);
            if ($penalty < $bestPenalty) {
                $bestPenalty = $penalty;
                $bestMatrix  = $matrix;
            }
        }

        return self::renderPng($bestMatrix, $size, $moduleSize, $margin);
    }

    /**
     * Generate QR code as base64 data URI.
     *
     * @param string $data Data to encode
     * @param int $moduleSize Pixel size per module (default: 4)
     * @param int $margin Quiet zone width in modules (default: 4)
     * @return string Data URI (data:image/png;base64,...)
     */
    public static function toDataUri(string $data, int $moduleSize = 4, int $margin = 4): string
    {
        return 'data:image/png;base64,' . base64_encode(self::generate($data, $moduleSize, $margin));
    }

    /**
     * Determine minimum QR version for given data length.
     *
     * @param int $length Data length in bytes
     * @return int QR version (1-20)
     * @throws \InvalidArgumentException If data is too long
     */
    private static function getVersion(int $length): int
    {
        foreach (self::VERSION_CAPACITY as $version => $capacity) {
            if ($length <= $capacity) {
                return $version;
            }
        }
        throw new \InvalidArgumentException('Data too long for QR code (max 666 bytes at EC level M)');
    }

    /**
     * Build a complete QR matrix for the given mask pattern.
     *
     * @param int $size Matrix dimension (modules)
     * @param int $version QR version
     * @param array<int> $codewords Data + EC codewords
     * @param int $maskPattern Mask pattern (0-7)
     * @return array<array<bool|null>> QR matrix (true=dark, false=light, null=unset)
     */
    private static function buildMatrix(int $size, int $version, array $codewords, int $maskPattern): array
    {
        $matrix = array_fill(0, $size, array_fill(0, $size, null));

        self::setupPositionProbePattern($matrix, $size, 0, 0);
        self::setupPositionProbePattern($matrix, $size, $size - 7, 0);
        self::setupPositionProbePattern($matrix, $size, 0, $size - 7);
        self::setupPositionAdjustPattern($matrix, $version);
        self::setupTimingPattern($matrix, $size);

        // Reserve format info areas (test mode = all false/light)
        self::setupTypeInfo($matrix, $size, true, 0);

        // Reserve version info areas for QR versions 7+ (test mode)
        if ($version >= 7) {
            self::setupVersionInfo($matrix, $size, $version, true);
        }

        // Place data with masking applied inline
        self::mapData($matrix, $size, $codewords, $maskPattern);

        // Write actual format information
        self::setupTypeInfo($matrix, $size, false, $maskPattern);

        // Write actual version information for QR versions 7+
        if ($version >= 7) {
            self::setupVersionInfo($matrix, $size, $version, false);
        }

        return $matrix;
    }

    /**
     * Place finder pattern and separator at given corner.
     * Ported from Kazuhiko Arase's reference implementation.
     *
     * @param array<array<bool|null>> $matrix QR matrix
     * @param int $size Matrix dimension
     * @param int $row Top-left row of finder pattern
     * @param int $col Top-left column of finder pattern
     */
    private static function setupPositionProbePattern(array &$matrix, int $size, int $row, int $col): void
    {
        for ($r = -1; $r <= 7; $r++) {
            for ($c = -1; $c <= 7; $c++) {
                $rr = $row + $r;
                $cc = $col + $c;
                if ($rr < 0 || $size <= $rr || $cc < 0 || $size <= $cc) {
                    continue;
                }
                $matrix[$rr][$cc] = (
                    (0 <= $r && $r <= 6 && ($c === 0 || $c === 6)) ||
                    (0 <= $c && $c <= 6 && ($r === 0 || $r === 6)) ||
                    (2 <= $r && $r <= 4 && 2 <= $c && $c <= 4)
                );
            }
        }
    }

    /**
     * Place alignment patterns for versions 2+.
     *
     * @param array<array<bool|null>> $matrix QR matrix
     * @param int $version QR version
     */
    private static function setupPositionAdjustPattern(array &$matrix, int $version): void
    {
        $pos   = self::ALIGNMENT_POSITIONS[$version] ?? [];
        $count = count($pos);

        for ($i = 0; $i < $count; $i++) {
            for ($j = 0; $j < $count; $j++) {
                $row = $pos[$i];
                $col = $pos[$j];
                if ($matrix[$row][$col] !== null) {
                    continue;
                }
                for ($r = -2; $r <= 2; $r++) {
                    for ($c = -2; $c <= 2; $c++) {
                        $matrix[$row + $r][$col + $c] =
                            ($r === -2 || $r === 2 || $c === -2 || $c === 2 || ($r === 0 && $c === 0));
                    }
                }
            }
        }
    }

    /**
     * Place timing patterns along row 6 and column 6.
     *
     * @param array<array<bool|null>> $matrix QR matrix
     * @param int $size Matrix dimension
     */
    private static function setupTimingPattern(array &$matrix, int $size): void
    {
        for ($i = 8; $i < $size - 8; $i++) {
            if ($matrix[$i][6] === null) {
                $matrix[$i][6] = ($i % 2 === 0);
            }
            if ($matrix[6][$i] === null) {
                $matrix[6][$i] = ($i % 2 === 0);
            }
        }
    }

    /**
     * Place format information (15-bit BCH encoded).
     * Ported directly from Kazuhiko Arase's reference implementation.
     *
     * EC level M = 0b00, so data = (0b00 << 3) | maskPattern.
     * In test mode all modules are set to false (light) to reserve the area.
     *
     * @param array<array<bool|null>> $matrix QR matrix
     * @param int $size Matrix dimension
     * @param bool $test True to reserve areas (set light), false to write real bits
     * @param int $maskPattern Mask pattern (0-7)
     */
    private static function setupTypeInfo(array &$matrix, int $size, bool $test, int $maskPattern): void
    {
        $data = (0b00 << 3) | $maskPattern; // EC level M = 0b00
        $bits = self::getBCHTypeInfo($data);

        for ($i = 0; $i < 15; $i++) {
            $mod = !$test && (($bits >> $i) & 1) === 1;

            // Copy 1 — vertical section (column 8)
            if ($i < 6) {
                $matrix[$i][8] = $mod;
            } elseif ($i < 8) {
                $matrix[$i + 1][8] = $mod; // skip row 6 (timing pattern)
            } else {
                $matrix[$size - 15 + $i][8] = $mod; // bottom-left
            }

            // Copy 1 horizontal (row 8, cols 0-8) + Copy 2 top-right (row 8, from right)
            if ($i < 8) {
                $matrix[8][$size - $i - 1] = $mod; // top-right copy
            } elseif ($i < 9) {
                $matrix[8][15 - $i] = $mod; // col 7 (skip col 6 timing): 15-8=7
            } else {
                $matrix[8][15 - $i - 1] = $mod; // cols 5 down to 0
            }
        }

        // Dark module — always dark
        $matrix[$size - 8][8] = !$test;
    }

    /**
     * Place version information for QR versions 7+ (18-bit BCH encoded).
     * Required for QR codes version 7 and above.
     * Ported from Kazuhiko Arase's reference implementation.
     *
     * Version info occupies two 6×3 blocks:
     *   - Bottom-left: rows size-11..size-9, cols 0-5
     *   - Top-right:   rows 0-5, cols size-11..size-9
     *
     * @param array<array<bool|null>> $matrix QR matrix
     * @param int $size Matrix dimension
     * @param int $version QR version (7-40)
     * @param bool $test True to reserve areas (set light), false to write real bits
     */
    private static function setupVersionInfo(array &$matrix, int $size, int $version, bool $test): void
    {
        $bits = self::getBCHTypeNumber($version);

        for ($i = 0; $i < 18; $i++) {
            $mod = !$test && (($bits >> $i) & 1) === 1;
            $matrix[(int) floor($i / 3)][$i % 3 + $size - 8 - 3] = $mod;
            $matrix[$i % 3 + $size - 8 - 3][(int) floor($i / 3)] = $mod;
        }
    }

    /**
     * Compute BCH-encoded version information word (18,6 code).
     * Ported from Kazuhiko Arase's reference implementation.
     *
     * @param int $data 6-bit version number (7-40)
     * @return int 18-bit version information word
     */
    private static function getBCHTypeNumber(int $data): int
    {
        $d = $data << 12;
        while (self::getBCHDigit($d) - self::getBCHDigit(self::G18) >= 0) {
            $d ^= self::G18 << (self::getBCHDigit($d) - self::getBCHDigit(self::G18));
        }
        return ($data << 12) | $d;
    }

    /**
     * Place data codewords into the matrix with masking applied.
     * Ported directly from Kazuhiko Arase's reference implementation.
     *
     * @param array<array<bool|null>> $matrix QR matrix
     * @param int $size Matrix dimension
     * @param array<int> $codewords Data + EC codewords
     * @param int $maskPattern Mask pattern (0-7)
     */
    private static function mapData(array &$matrix, int $size, array $codewords, int $maskPattern): void
    {
        $inc       = -1;
        $row       = $size - 1;
        $bitIndex  = 7;
        $byteIndex = 0;
        $count     = count($codewords);

        for ($col = $size - 1; $col > 0; $col -= 2) {
            if ($col === 6) {
                $col--;
            }

            while (true) {
                for ($c = 0; $c < 2; $c++) {
                    $cc = $col - $c;
                    if ($matrix[$row][$cc] === null) {
                        $dark = false;
                        if ($byteIndex < $count) {
                            $dark = (($codewords[$byteIndex] >> $bitIndex) & 1) === 1;
                        }
                        if (self::shouldApplyMask($maskPattern, $row, $cc)) {
                            $dark = !$dark;
                        }
                        $matrix[$row][$cc] = $dark;
                        $bitIndex--;
                        if ($bitIndex === -1) {
                            $byteIndex++;
                            $bitIndex = 7;
                        }
                    }
                }

                $row += $inc;
                if ($row < 0 || $size <= $row) {
                    $row -= $inc;
                    $inc  = -$inc;
                    break;
                }
            }
        }
    }

    /**
     * Determine whether mask pattern applies at given position.
     *
     * @param int $mask Mask pattern (0-7)
     * @param int $row Row index
     * @param int $col Column index
     * @return bool True if mask should flip this module
     */
    private static function shouldApplyMask(int $mask, int $row, int $col): bool
    {
        return match ($mask) {
            0 => ($row + $col) % 2 === 0,
            1 => $row % 2 === 0,
            2 => $col % 3 === 0,
            3 => ($row + $col) % 3 === 0,
            4 => (intdiv($row, 2) + intdiv($col, 3)) % 2 === 0,
            5 => ($row * $col) % 2 + ($row * $col) % 3 === 0,
            6 => (($row * $col) % 2 + ($row * $col) % 3) % 2 === 0,
            7 => (($row * $col) % 3 + ($row + $col) % 2) % 2 === 0,
            default => false,
        };
    }

    /**
     * Build codeword array: encode data in byte mode, compute RS error correction,
     * and interleave data and EC blocks.
     *
     * @param string $data Data to encode
     * @param int $version QR version
     * @return array<int> Final codeword sequence
     */
    private static function buildCodewords(string $data, int $version): array
    {
        $rsBlocks = self::getRSBlocks($version);

        // Bit buffer
        $buffer = [];
        $length = 0;

        $putBits = static function (int $num, int $bits) use (&$buffer, &$length): void {
            for ($i = 0; $i < $bits; $i++) {
                $byteIndex = intdiv($length, 8);
                if (!isset($buffer[$byteIndex])) {
                    $buffer[$byteIndex] = 0;
                }
                if ((($num >> ($bits - $i - 1)) & 1) === 1) {
                    $buffer[$byteIndex] |= (0x80 >> ($length % 8));
                }
                $length++;
            }
        };

        // Mode indicator: byte mode = 0b0100
        $putBits(0b0100, 4);

        // Character count indicator (8 bits for versions 1-9, 16 for 10+)
        $dataLen = strlen($data);
        $putBits($dataLen, $version <= 9 ? 8 : 16);

        // Data bytes
        for ($i = 0; $i < $dataLen; $i++) {
            $putBits(ord($data[$i]), 8);
        }

        // Compute total data codeword capacity
        $totalDataCount = 0;
        foreach ($rsBlocks as [$totalCount, $dataCount]) {
            $totalDataCount += $dataCount;
        }

        // Terminator (up to 4 zero bits)
        $putBits(0, min(4, $totalDataCount * 8 - $length));

        // Pad to byte boundary
        while ($length % 8 !== 0) {
            $putBits(0, 1);
        }

        // Pad with alternating 0xEC / 0x11 bytes
        $padIndex = 0;
        while ($length < $totalDataCount * 8) {
            $putBits($padIndex % 2 === 0 ? self::PAD0 : self::PAD1, 8);
            $padIndex++;
        }

        // Generate ECC and interleave
        self::initGaloisField();

        $offset     = 0;
        $dataBlocks = [];
        $ecBlocks   = [];

        foreach ($rsBlocks as [$totalCount, $dataCount]) {
            $ecCount = $totalCount - $dataCount;
            $block   = [];
            for ($i = 0; $i < $dataCount; $i++) {
                $block[] = 0xFF & ($buffer[$offset + $i] ?? 0);
            }
            $offset       += $dataCount;
            $dataBlocks[]  = $block;
            $ecBlocks[]    = self::computeECC($block, $ecCount);
        }

        $result = [];

        // Interleave data codewords
        $maxDc = max(array_map(static fn($b) => count($b), $dataBlocks));
        for ($i = 0; $i < $maxDc; $i++) {
            foreach ($dataBlocks as $block) {
                if (isset($block[$i])) {
                    $result[] = $block[$i];
                }
            }
        }

        // Interleave EC codewords
        $maxEc = max(array_map(static fn($b) => count($b), $ecBlocks));
        for ($i = 0; $i < $maxEc; $i++) {
            foreach ($ecBlocks as $block) {
                if (isset($block[$i])) {
                    $result[] = $block[$i];
                }
            }
        }

        return $result;
    }

    /**
     * Get RS block configurations for a version at EC level M.
     * Returns array of [totalCount, dataCount] pairs.
     *
     * @param int $version QR version (1-20)
     * @return array<array{int, int}> List of [totalCount, dataCount]
     */
    private static function getRSBlocks(int $version): array
    {
        // EC level M = index 1 in the 4-EC-level grouping
        $entry  = self::RS_BLOCK_TABLE[($version - 1) * 4 + 1];
        $count  = intdiv(count($entry), 3);
        $blocks = [];

        for ($i = 0; $i < $count; $i++) {
            $num   = $entry[$i * 3];
            $total = $entry[$i * 3 + 1];
            $data  = $entry[$i * 3 + 2];
            for ($j = 0; $j < $num; $j++) {
                $blocks[] = [$total, $data];
            }
        }

        return $blocks;
    }

    /**
     * Initialize GF(256) exponent and log tables.
     * Uses primitive polynomial x^8 + x^4 + x^3 + x^2 + 1.
     * Ported from Kazuhiko Arase's reference implementation.
     */
    private static function initGaloisField(): void
    {
        if (!empty(self::$gfExp)) {
            return;
        }

        self::$gfExp = array_fill(0, 256, 0);
        self::$gfLog = array_fill(0, 256, 0);

        for ($i = 0; $i < 8; $i++) {
            self::$gfExp[$i] = 1 << $i;
        }
        for ($i = 8; $i < 256; $i++) {
            self::$gfExp[$i] = self::$gfExp[$i - 4]
                ^ self::$gfExp[$i - 5]
                ^ self::$gfExp[$i - 6]
                ^ self::$gfExp[$i - 8];
        }
        for ($i = 0; $i < 255; $i++) {
            self::$gfLog[self::$gfExp[$i]] = $i;
        }
    }

    /**
     * GF(256) exponent with wrap-around (handles negative and >= 256).
     *
     * @param int $n Exponent (any integer)
     * @return int GF element
     */
    private static function gfExp(int $n): int
    {
        while ($n < 0) {
            $n += 255;
        }
        while ($n >= 256) {
            $n -= 255;
        }
        return self::$gfExp[$n];
    }

    /**
     * Multiply two GF(256) polynomials (coefficient arrays, leading term first).
     *
     * @param array<int> $p First polynomial
     * @param array<int> $q Second polynomial
     * @return array<int> Product polynomial
     */
    private static function polyMultiply(array $p, array $q): array
    {
        $num = array_fill(0, count($p) + count($q) - 1, 0);
        for ($i = 0; $i < count($p); $i++) {
            if ($p[$i] === 0) {
                continue;
            }
            $vi = self::$gfLog[$p[$i]];
            for ($j = 0; $j < count($q); $j++) {
                if ($q[$j] !== 0) {
                    $num[$i + $j] ^= self::gfExp($vi + self::$gfLog[$q[$j]]);
                }
            }
        }
        return $num;
    }

    /**
     * Compute remainder of dividend / divisor in GF(256) polynomial arithmetic.
     * Ported from Kazuhiko Arase's QRPolynomial::mod().
     *
     * @param array<int> $dividend Coefficient array (leading term first)
     * @param array<int> $divisor  Coefficient array (leading term first)
     * @return array<int> Remainder polynomial
     */
    private static function polyMod(array $dividend, array $divisor): array
    {
        // Strip leading zeros from dividend
        while (!empty($dividend) && $dividend[0] === 0) {
            array_shift($dividend);
        }

        while (count($dividend) >= count($divisor)) {
            $ratio = self::$gfLog[$dividend[0]] - self::$gfLog[$divisor[0]];
            for ($i = 0; $i < count($divisor); $i++) {
                if ($divisor[$i] !== 0) {
                    $dividend[$i] ^= self::gfExp(self::$gfLog[$divisor[$i]] + $ratio);
                }
            }
            // Strip leading zero produced by subtraction
            while (!empty($dividend) && $dividend[0] === 0) {
                array_shift($dividend);
            }
        }

        return $dividend;
    }

    /**
     * Build the Reed-Solomon generator polynomial for ecCount EC codewords.
     *
     * @param int $ecCount Number of error correction codewords
     * @return array<int> Generator polynomial coefficients (leading term first)
     */
    private static function getGeneratorPoly(int $ecCount): array
    {
        $gen = [1];
        for ($i = 0; $i < $ecCount; $i++) {
            $gen = self::polyMultiply($gen, [1, self::gfExp($i)]);
        }
        return $gen;
    }

    /**
     * Compute Reed-Solomon error correction codewords for a data block.
     *
     * @param array<int> $data Data bytes for this block
     * @param int $ecCount Number of EC codewords to generate
     * @return array<int> EC codewords
     */
    private static function computeECC(array $data, int $ecCount): array
    {
        $gen = self::getGeneratorPoly($ecCount);

        // Multiply data polynomial by x^ecCount (append zeros), then mod by generator
        $dividend  = array_merge($data, array_fill(0, $ecCount, 0));
        $remainder = self::polyMod($dividend, $gen);

        // Align remainder into a fixed-length array of size ecCount
        $result = array_fill(0, $ecCount, 0);
        $offset = $ecCount - count($remainder);
        for ($i = 0; $i < count($remainder); $i++) {
            $result[$offset + $i] = $remainder[$i];
        }

        return $result;
    }

    /**
     * Compute BCH-encoded format information word (15,5 code).
     * Ported from Kazuhiko Arase's reference implementation.
     *
     * @param int $data 5-bit data (EC level << 3 | mask pattern)
     * @return int 15-bit format information word
     */
    private static function getBCHTypeInfo(int $data): int
    {
        $d = $data << 10;
        while (self::getBCHDigit($d) - self::getBCHDigit(self::G15) >= 0) {
            $d ^= self::G15 << (self::getBCHDigit($d) - self::getBCHDigit(self::G15));
        }
        return (($data << 10) | $d) ^ self::G15_MASK;
    }

    /**
     * Return the bit length (position of highest set bit + 1) of an integer.
     *
     * @param int $data Non-negative integer
     * @return int Bit length
     */
    private static function getBCHDigit(int $data): int
    {
        $digit = 0;
        while ($data !== 0) {
            $digit++;
            $data >>= 1;
        }
        return $digit;
    }

    /**
     * Calculate QR mask penalty score (all 4 rules from ISO 18004).
     * Ported from Kazuhiko Arase's reference implementation.
     *
     * @param array<array<bool|null>> $matrix QR matrix
     * @param int $size Matrix dimension
     * @return int Penalty score (lower is better)
     */
    private static function getLostPoint(array $matrix, int $size): int
    {
        $lostPoint = 0;

        // Rule 1: modules with 5+ same-color neighbors
        for ($row = 0; $row < $size; $row++) {
            for ($col = 0; $col < $size; $col++) {
                $sameCount = 0;
                $dark      = $matrix[$row][$col];
                for ($r = -1; $r <= 1; $r++) {
                    if ($row + $r < 0 || $size <= $row + $r) {
                        continue;
                    }
                    for ($c = -1; $c <= 1; $c++) {
                        if ($col + $c < 0 || $size <= $col + $c) {
                            continue;
                        }
                        if ($r === 0 && $c === 0) {
                            continue;
                        }
                        if ($dark === $matrix[$row + $r][$col + $c]) {
                            $sameCount++;
                        }
                    }
                }
                if ($sameCount > 5) {
                    $lostPoint += 3 + $sameCount - 5;
                }
            }
        }

        // Rule 2: 2×2 blocks of same color
        for ($row = 0; $row < $size - 1; $row++) {
            for ($col = 0; $col < $size - 1; $col++) {
                $count = 0;
                if ($matrix[$row][$col])         $count++;
                if ($matrix[$row + 1][$col])     $count++;
                if ($matrix[$row][$col + 1])     $count++;
                if ($matrix[$row + 1][$col + 1]) $count++;
                if ($count === 0 || $count === 4) {
                    $lostPoint += 3;
                }
            }
        }

        // Rule 3: 1:1:3:1:1 finder-like patterns
        for ($row = 0; $row < $size; $row++) {
            for ($col = 0; $col < $size - 6; $col++) {
                if ( $matrix[$row][$col]
                  && !$matrix[$row][$col + 1]
                  &&  $matrix[$row][$col + 2]
                  &&  $matrix[$row][$col + 3]
                  &&  $matrix[$row][$col + 4]
                  && !$matrix[$row][$col + 5]
                  &&  $matrix[$row][$col + 6]) {
                    $lostPoint += 40;
                }
            }
        }
        for ($col = 0; $col < $size; $col++) {
            for ($row = 0; $row < $size - 6; $row++) {
                if ( $matrix[$row][$col]
                  && !$matrix[$row + 1][$col]
                  &&  $matrix[$row + 2][$col]
                  &&  $matrix[$row + 3][$col]
                  &&  $matrix[$row + 4][$col]
                  && !$matrix[$row + 5][$col]
                  &&  $matrix[$row + 6][$col]) {
                    $lostPoint += 40;
                }
            }
        }

        // Rule 4: dark module ratio deviation from 50%
        $darkCount = 0;
        for ($row = 0; $row < $size; $row++) {
            for ($col = 0; $col < $size; $col++) {
                if ($matrix[$row][$col]) {
                    $darkCount++;
                }
            }
        }
        $ratio      = abs(100 * $darkCount / ($size * $size) - 50) / 5;
        $lostPoint += (int) ($ratio * 10);

        return $lostPoint;
    }

    /**
     * Render QR matrix as a PNG binary string (no GD required).
     *
     * @param array<array<bool|null>> $matrix QR matrix (true=dark, false/null=light)
     * @param int $qrSize Matrix dimension in modules
     * @param int $moduleSize Pixel size per module
     * @param int $margin Quiet zone width in modules
     * @return string PNG binary data
     */
    private static function renderPng(array $matrix, int $qrSize, int $moduleSize, int $margin): string
    {
        $imageSize = ($qrSize + $margin * 2) * $moduleSize;

        // PNG signature
        $png = pack('C8', 137, 80, 78, 71, 13, 10, 26, 10);

        // IHDR chunk: 1-bit grayscale
        $ihdr  = pack('N', $imageSize) . pack('N', $imageSize);
        $ihdr .= pack('C5', 1, 0, 0, 0, 0); // bit depth, grayscale, no interlace
        $png  .= self::pngChunk('IHDR', $ihdr);

        // IDAT chunk
        $rawData = '';
        for ($y = 0; $y < $imageSize; $y++) {
            $rawData .= chr(0); // filter type: None
            $byte     = 0;
            $bitCount = 0;
            $rowData  = '';

            for ($x = 0; $x < $imageSize; $x++) {
                $matrixY = intdiv($y, $moduleSize) - $margin;
                $matrixX = intdiv($x, $moduleSize) - $margin;

                // In 1-bit grayscale PNG: 0=black, 1=white
                $pixel = 1; // white (margin / default)
                if ($matrixY >= 0 && $matrixY < $qrSize && $matrixX >= 0 && $matrixX < $qrSize) {
                    $pixel = $matrix[$matrixY][$matrixX] ? 0 : 1; // dark module = black = 0
                }

                $byte = ($byte << 1) | $pixel;
                $bitCount++;

                if ($bitCount === 8) {
                    $rowData  .= chr($byte);
                    $byte      = 0;
                    $bitCount  = 0;
                }
            }

            if ($bitCount > 0) {
                $byte    <<= (8 - $bitCount);
                $rowData  .= chr($byte);
            }

            $rawData .= $rowData;
        }

        $png .= self::pngChunk('IDAT', gzcompress($rawData, 9));
        $png .= self::pngChunk('IEND', '');

        return $png;
    }

    /**
     * Build a PNG chunk with length, type, data, and CRC.
     *
     * @param string $type 4-byte chunk type
     * @param string $data Chunk data
     * @return string PNG chunk binary
     */
    private static function pngChunk(string $type, string $data): string
    {
        $chunk = $type . $data;
        return pack('N', strlen($data)) . $chunk . pack('N', crc32($chunk));
    }
}
