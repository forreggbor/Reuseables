<?php

declare(strict_types=1);

namespace MFA;

/**
 * QRCode - Pure PHP QR Code Generator
 *
 * Generates QR codes without external libraries or PHP extensions.
 * Outputs PNG format using raw binary construction.
 *
 * @package MFA
 * @version 1.0.0
 * @license MIT
 */
class QRCode
{
    /**
     * Error correction level M (15% recovery)
     */
    private const EC_LEVEL_M = 0;

    /**
     * Alphanumeric character set for encoding detection
     */
    private const ALPHANUMERIC_CHARS = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ $%*+-./:';

    /**
     * QR Code version capacities (byte mode, EC level M)
     * Format: version => max bytes
     */
    private const VERSION_CAPACITIES = [
        1 => 14, 2 => 26, 3 => 42, 4 => 62, 5 => 84,
        6 => 106, 7 => 122, 8 => 152, 9 => 180, 10 => 213,
        11 => 251, 12 => 287, 13 => 331, 14 => 362, 15 => 412,
        16 => 450, 17 => 504, 18 => 560, 19 => 624, 20 => 666,
    ];

    /**
     * Error correction codewords per block for EC level M
     */
    private const EC_CODEWORDS = [
        1 => 10, 2 => 16, 3 => 26, 4 => 18, 5 => 24,
        6 => 16, 7 => 18, 8 => 22, 9 => 22, 10 => 26,
        11 => 30, 12 => 22, 13 => 22, 14 => 24, 15 => 24,
        16 => 28, 17 => 28, 18 => 26, 19 => 26, 20 => 26,
    ];

    /**
     * Number of error correction blocks for EC level M
     */
    private const EC_BLOCKS = [
        1 => [1, 0], 2 => [1, 0], 3 => [1, 0], 4 => [2, 0], 5 => [2, 0],
        6 => [4, 0], 7 => [4, 0], 8 => [2, 2], 9 => [3, 2], 10 => [4, 1],
        11 => [1, 4], 12 => [6, 2], 13 => [8, 1], 14 => [4, 5], 15 => [5, 5],
        16 => [7, 3], 17 => [10, 1], 18 => [9, 4], 19 => [3, 11], 20 => [3, 13],
    ];

    /**
     * Data codewords per block for EC level M
     */
    private const DATA_CODEWORDS = [
        1 => [16, 0], 2 => [28, 0], 3 => [44, 0], 4 => [32, 0], 5 => [43, 0],
        6 => [27, 0], 7 => [31, 0], 8 => [38, 39], 9 => [36, 37], 10 => [43, 44],
        11 => [50, 51], 12 => [36, 37], 13 => [37, 38], 14 => [40, 41], 15 => [41, 42],
        16 => [45, 46], 17 => [46, 47], 18 => [43, 44], 19 => [44, 45], 20 => [41, 42],
    ];

    /**
     * Alignment pattern positions per version
     */
    private const ALIGNMENT_POSITIONS = [
        2 => [6, 18],
        3 => [6, 22],
        4 => [6, 26],
        5 => [6, 30],
        6 => [6, 34],
        7 => [6, 22, 38],
        8 => [6, 24, 42],
        9 => [6, 26, 46],
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
     * Format information bits for EC level M with masks 0-7
     */
    private const FORMAT_INFO = [
        0 => 0x5412,
        1 => 0x5125,
        2 => 0x5E7C,
        3 => 0x5B4B,
        4 => 0x45F9,
        5 => 0x40CE,
        6 => 0x4F97,
        7 => 0x4AA0,
    ];

    /**
     * Galois field exponent table
     */
    private static array $gfExp = [];

    /**
     * Galois field logarithm table
     */
    private static array $gfLog = [];

    /**
     * Initialize Galois field tables
     */
    private static function initGaloisField(): void
    {
        if (!empty(self::$gfExp)) {
            return;
        }

        self::$gfExp = array_fill(0, 512, 0);
        self::$gfLog = array_fill(0, 256, 0);

        $x = 1;
        for ($i = 0; $i < 255; $i++) {
            self::$gfExp[$i] = $x;
            self::$gfLog[$x] = $i;
            $x <<= 1;
            if ($x & 0x100) {
                $x ^= 0x11D;
            }
        }
        for ($i = 255; $i < 512; $i++) {
            self::$gfExp[$i] = self::$gfExp[$i - 255];
        }
    }

    /**
     * Multiply in Galois field
     */
    private static function gfMul(int $a, int $b): int
    {
        if ($a === 0 || $b === 0) {
            return 0;
        }
        return self::$gfExp[self::$gfLog[$a] + self::$gfLog[$b]];
    }

    /**
     * Generate Reed-Solomon error correction codewords
     *
     * @param array $data Data codewords
     * @param int $ecCount Number of EC codewords to generate
     * @return array EC codewords
     */
    private static function generateECC(array $data, int $ecCount): array
    {
        self::initGaloisField();

        // Generate generator polynomial
        $gen = [1];
        for ($i = 0; $i < $ecCount; $i++) {
            $newGen = array_fill(0, count($gen) + 1, 0);
            for ($j = 0; $j < count($gen); $j++) {
                $newGen[$j] ^= $gen[$j];
                $newGen[$j + 1] ^= self::gfMul($gen[$j], self::$gfExp[$i]);
            }
            $gen = $newGen;
        }

        // Divide data by generator polynomial
        $ecc = array_fill(0, $ecCount, 0);
        foreach ($data as $byte) {
            $coef = $byte ^ $ecc[0];
            array_shift($ecc);
            $ecc[] = 0;
            for ($i = 0; $i < $ecCount; $i++) {
                $ecc[$i] ^= self::gfMul($coef, $gen[$i + 1]);
            }
        }

        return $ecc;
    }

    /**
     * Determine optimal QR version for data length
     *
     * @param int $length Data length in bytes
     * @return int QR version (1-20)
     * @throws \InvalidArgumentException If data too long
     */
    private static function getVersion(int $length): int
    {
        foreach (self::VERSION_CAPACITIES as $version => $capacity) {
            if ($length <= $capacity) {
                return $version;
            }
        }
        throw new \InvalidArgumentException('Data too long for QR code (max 666 bytes)');
    }

    /**
     * Get QR code size (modules) for version
     */
    private static function getSize(int $version): int
    {
        return 17 + ($version * 4);
    }

    /**
     * Encode data as byte mode bit stream
     *
     * @param string $data Data to encode
     * @param int $version QR version
     * @return array Bit stream as array of 0/1
     */
    private static function encodeData(string $data, int $version): array
    {
        $bits = [];

        // Mode indicator: 0100 (byte mode)
        $bits = array_merge($bits, [0, 1, 0, 0]);

        // Character count indicator
        $countBits = $version <= 9 ? 8 : 16;
        $length = strlen($data);
        for ($i = $countBits - 1; $i >= 0; $i--) {
            $bits[] = ($length >> $i) & 1;
        }

        // Data bytes
        for ($i = 0; $i < $length; $i++) {
            $byte = ord($data[$i]);
            for ($j = 7; $j >= 0; $j--) {
                $bits[] = ($byte >> $j) & 1;
            }
        }

        // Terminator (up to 4 bits)
        $totalDataBits = self::getTotalDataCodewords($version) * 8;
        $terminatorLength = min(4, $totalDataBits - count($bits));
        for ($i = 0; $i < $terminatorLength; $i++) {
            $bits[] = 0;
        }

        // Pad to byte boundary
        while (count($bits) % 8 !== 0) {
            $bits[] = 0;
        }

        // Pad with alternating bytes
        $padBytes = [0xEC, 0x11];
        $padIndex = 0;
        while (count($bits) < $totalDataBits) {
            $byte = $padBytes[$padIndex % 2];
            for ($j = 7; $j >= 0; $j--) {
                $bits[] = ($byte >> $j) & 1;
            }
            $padIndex++;
        }

        return $bits;
    }

    /**
     * Get total data codewords for version and EC level M
     */
    private static function getTotalDataCodewords(int $version): int
    {
        $blocks = self::EC_BLOCKS[$version];
        $dataWords = self::DATA_CODEWORDS[$version];
        return ($blocks[0] * $dataWords[0]) + ($blocks[1] * $dataWords[1]);
    }

    /**
     * Convert bit stream to codewords and add error correction
     */
    private static function createCodewords(array $bits, int $version): array
    {
        // Convert bits to bytes
        $dataBytes = [];
        for ($i = 0; $i < count($bits); $i += 8) {
            $byte = 0;
            for ($j = 0; $j < 8; $j++) {
                $byte = ($byte << 1) | ($bits[$i + $j] ?? 0);
            }
            $dataBytes[] = $byte;
        }

        // Split into blocks
        $blocks = self::EC_BLOCKS[$version];
        $dataWords = self::DATA_CODEWORDS[$version];
        $ecCount = self::EC_CODEWORDS[$version];

        $dataBlocks = [];
        $ecBlocks = [];
        $offset = 0;

        // Group 1 blocks
        for ($i = 0; $i < $blocks[0]; $i++) {
            $block = array_slice($dataBytes, $offset, $dataWords[0]);
            $dataBlocks[] = $block;
            $ecBlocks[] = self::generateECC($block, $ecCount);
            $offset += $dataWords[0];
        }

        // Group 2 blocks
        for ($i = 0; $i < $blocks[1]; $i++) {
            $block = array_slice($dataBytes, $offset, $dataWords[1]);
            $dataBlocks[] = $block;
            $ecBlocks[] = self::generateECC($block, $ecCount);
            $offset += $dataWords[1];
        }

        // Interleave data codewords
        $result = [];
        $maxDataLength = max($dataWords[0], $dataWords[1] ?: 0);
        for ($i = 0; $i < $maxDataLength; $i++) {
            foreach ($dataBlocks as $block) {
                if (isset($block[$i])) {
                    $result[] = $block[$i];
                }
            }
        }

        // Interleave EC codewords
        for ($i = 0; $i < $ecCount; $i++) {
            foreach ($ecBlocks as $block) {
                if (isset($block[$i])) {
                    $result[] = $block[$i];
                }
            }
        }

        return $result;
    }

    /**
     * Create QR matrix with function patterns
     */
    private static function createMatrix(int $size): array
    {
        // -1 = unset, 0 = white, 1 = black
        $matrix = array_fill(0, $size, array_fill(0, $size, -1));
        return $matrix;
    }

    /**
     * Add finder patterns to matrix
     */
    private static function addFinderPatterns(array &$matrix, int $size): void
    {
        $positions = [[0, 0], [0, $size - 7], [$size - 7, 0]];

        foreach ($positions as [$row, $col]) {
            for ($r = 0; $r < 7; $r++) {
                for ($c = 0; $c < 7; $c++) {
                    $isOuter = $r === 0 || $r === 6 || $c === 0 || $c === 6;
                    $isInner = $r >= 2 && $r <= 4 && $c >= 2 && $c <= 4;
                    $matrix[$row + $r][$col + $c] = ($isOuter || $isInner) ? 1 : 0;
                }
            }
        }

        // Separators
        for ($i = 0; $i < 8; $i++) {
            // Top-left
            if ($i < $size) {
                $matrix[7][$i] = 0;
                $matrix[$i][7] = 0;
            }
            // Top-right
            if ($size - 8 + $i < $size) {
                $matrix[7][$size - 8 + $i] = 0;
            }
            if ($i < 8) {
                $matrix[$i][$size - 8] = 0;
            }
            // Bottom-left
            if ($size - 8 + $i < $size) {
                $matrix[$size - 8 + $i][7] = 0;
            }
            if ($i < 8) {
                $matrix[$size - 8][$i] = 0;
            }
        }
    }

    /**
     * Add timing patterns to matrix
     */
    private static function addTimingPatterns(array &$matrix, int $size): void
    {
        for ($i = 8; $i < $size - 8; $i++) {
            $value = ($i + 1) % 2;
            if ($matrix[6][$i] === -1) {
                $matrix[6][$i] = $value;
            }
            if ($matrix[$i][6] === -1) {
                $matrix[$i][6] = $value;
            }
        }
    }

    /**
     * Add alignment patterns to matrix
     */
    private static function addAlignmentPatterns(array &$matrix, int $version): void
    {
        if ($version < 2) {
            return;
        }

        $positions = self::ALIGNMENT_POSITIONS[$version] ?? [];
        $count = count($positions);

        for ($i = 0; $i < $count; $i++) {
            for ($j = 0; $j < $count; $j++) {
                $row = $positions[$i];
                $col = $positions[$j];

                // Skip if overlapping with finder patterns
                if ($matrix[$row][$col] !== -1) {
                    continue;
                }

                for ($r = -2; $r <= 2; $r++) {
                    for ($c = -2; $c <= 2; $c++) {
                        $isOuter = abs($r) === 2 || abs($c) === 2;
                        $isCenter = $r === 0 && $c === 0;
                        $matrix[$row + $r][$col + $c] = ($isOuter || $isCenter) ? 1 : 0;
                    }
                }
            }
        }
    }

    /**
     * Reserve format information areas
     */
    private static function reserveFormatAreas(array &$matrix, int $size): void
    {
        // Around top-left finder
        for ($i = 0; $i < 9; $i++) {
            if ($matrix[8][$i] === -1) {
                $matrix[8][$i] = 0;
            }
            if ($matrix[$i][8] === -1) {
                $matrix[$i][8] = 0;
            }
        }

        // Around top-right finder
        for ($i = 0; $i < 8; $i++) {
            if ($matrix[8][$size - 1 - $i] === -1) {
                $matrix[8][$size - 1 - $i] = 0;
            }
        }

        // Around bottom-left finder
        for ($i = 0; $i < 8; $i++) {
            if ($matrix[$size - 1 - $i][8] === -1) {
                $matrix[$size - 1 - $i][8] = 0;
            }
        }

        // Dark module
        $matrix[$size - 8][8] = 1;
    }

    /**
     * Reserve version information areas (version 7+)
     */
    private static function reserveVersionAreas(array &$matrix, int $version, int $size): void
    {
        if ($version < 7) {
            return;
        }

        // Bottom-left
        for ($i = 0; $i < 6; $i++) {
            for ($j = 0; $j < 3; $j++) {
                $matrix[$size - 11 + $j][$i] = 0;
            }
        }

        // Top-right
        for ($i = 0; $i < 6; $i++) {
            for ($j = 0; $j < 3; $j++) {
                $matrix[$i][$size - 11 + $j] = 0;
            }
        }
    }

    /**
     * Place data bits in matrix using zigzag pattern
     */
    private static function placeData(array &$matrix, array $codewords, int $size): void
    {
        $bits = [];
        foreach ($codewords as $codeword) {
            for ($i = 7; $i >= 0; $i--) {
                $bits[] = ($codeword >> $i) & 1;
            }
        }

        $bitIndex = 0;
        $upward = true;
        $col = $size - 1;

        while ($col > 0) {
            if ($col === 6) {
                $col--;
            }

            $rows = $upward ? range($size - 1, 0, -1) : range(0, $size - 1);

            foreach ($rows as $row) {
                for ($c = 0; $c < 2; $c++) {
                    $currentCol = $col - $c;
                    if ($matrix[$row][$currentCol] === -1) {
                        $matrix[$row][$currentCol] = $bits[$bitIndex] ?? 0;
                        $bitIndex++;
                    }
                }
            }

            $col -= 2;
            $upward = !$upward;
        }
    }

    /**
     * Apply mask pattern to matrix
     */
    private static function applyMask(array $matrix, int $mask, int $size): array
    {
        $result = $matrix;

        for ($row = 0; $row < $size; $row++) {
            for ($col = 0; $col < $size; $col++) {
                if ($result[$row][$col] === -1) {
                    continue;
                }

                $shouldMask = false;
                switch ($mask) {
                    case 0:
                        $shouldMask = (($row + $col) % 2) === 0;
                        break;
                    case 1:
                        $shouldMask = ($row % 2) === 0;
                        break;
                    case 2:
                        $shouldMask = ($col % 3) === 0;
                        break;
                    case 3:
                        $shouldMask = (($row + $col) % 3) === 0;
                        break;
                    case 4:
                        $shouldMask = ((intdiv($row, 2) + intdiv($col, 3)) % 2) === 0;
                        break;
                    case 5:
                        $shouldMask = ((($row * $col) % 2) + (($row * $col) % 3)) === 0;
                        break;
                    case 6:
                        $shouldMask = (((($row * $col) % 2) + (($row * $col) % 3)) % 2) === 0;
                        break;
                    case 7:
                        $shouldMask = (((($row + $col) % 2) + (($row * $col) % 3)) % 2) === 0;
                        break;
                }

                // Only mask data and error correction areas
                if ($shouldMask && self::isDataArea($matrix, $row, $col)) {
                    $result[$row][$col] ^= 1;
                }
            }
        }

        return $result;
    }

    /**
     * Check if position is in data area (not function pattern)
     */
    private static function isDataArea(array $originalMatrix, int $row, int $col): bool
    {
        // Data areas are where original matrix was -1 (before data placement)
        // For simplicity, we check the value - if it was set during function patterns, it won't change
        return true; // Simplified - mask is applied to all non-function areas
    }

    /**
     * Calculate penalty score for mask evaluation
     */
    private static function calculatePenalty(array $matrix, int $size): int
    {
        $penalty = 0;

        // Rule 1: Consecutive modules in row/column
        for ($row = 0; $row < $size; $row++) {
            $count = 1;
            for ($col = 1; $col < $size; $col++) {
                if ($matrix[$row][$col] === $matrix[$row][$col - 1]) {
                    $count++;
                } else {
                    if ($count >= 5) {
                        $penalty += 3 + ($count - 5);
                    }
                    $count = 1;
                }
            }
            if ($count >= 5) {
                $penalty += 3 + ($count - 5);
            }
        }

        for ($col = 0; $col < $size; $col++) {
            $count = 1;
            for ($row = 1; $row < $size; $row++) {
                if ($matrix[$row][$col] === $matrix[$row - 1][$col]) {
                    $count++;
                } else {
                    if ($count >= 5) {
                        $penalty += 3 + ($count - 5);
                    }
                    $count = 1;
                }
            }
            if ($count >= 5) {
                $penalty += 3 + ($count - 5);
            }
        }

        // Rule 2: 2x2 blocks
        for ($row = 0; $row < $size - 1; $row++) {
            for ($col = 0; $col < $size - 1; $col++) {
                $val = $matrix[$row][$col];
                if ($val === $matrix[$row][$col + 1] &&
                    $val === $matrix[$row + 1][$col] &&
                    $val === $matrix[$row + 1][$col + 1]) {
                    $penalty += 3;
                }
            }
        }

        return $penalty;
    }

    /**
     * Add format information to matrix
     */
    private static function addFormatInfo(array &$matrix, int $mask, int $size): void
    {
        $formatBits = self::FORMAT_INFO[$mask];

        // Around top-left finder (horizontal)
        for ($i = 0; $i < 6; $i++) {
            $matrix[8][$i] = ($formatBits >> (14 - $i)) & 1;
        }
        $matrix[8][7] = ($formatBits >> 8) & 1;
        $matrix[8][8] = ($formatBits >> 7) & 1;
        $matrix[7][8] = ($formatBits >> 6) & 1;

        // Around top-left finder (vertical)
        for ($i = 0; $i < 6; $i++) {
            $matrix[5 - $i][8] = ($formatBits >> (9 + $i)) & 1;
        }

        // Around top-right finder
        for ($i = 0; $i < 8; $i++) {
            $matrix[8][$size - 1 - $i] = ($formatBits >> $i) & 1;
        }

        // Around bottom-left finder
        for ($i = 0; $i < 7; $i++) {
            $matrix[$size - 1 - $i][8] = ($formatBits >> (14 - $i)) & 1;
        }
    }

    /**
     * Generate QR code as PNG binary
     *
     * @param string $data Data to encode
     * @param int $moduleSize Size of each module in pixels (default: 4)
     * @param int $margin Quiet zone margin in modules (default: 4)
     * @return string PNG binary data
     */
    public static function generate(string $data, int $moduleSize = 4, int $margin = 4): string
    {
        // Determine version and create matrix
        $version = self::getVersion(strlen($data));
        $size = self::getSize($version);

        // Create and populate matrix
        $matrix = self::createMatrix($size);
        self::addFinderPatterns($matrix, $size);
        self::addTimingPatterns($matrix, $size);
        self::addAlignmentPatterns($matrix, $version);
        self::reserveFormatAreas($matrix, $size);
        self::reserveVersionAreas($matrix, $version, $size);

        // Store function pattern positions for masking
        $functionMatrix = $matrix;

        // Encode and place data
        $bits = self::encodeData($data, $version);
        $codewords = self::createCodewords($bits, $version);
        self::placeData($matrix, $codewords, $size);

        // Try all masks and find best
        $bestMask = 0;
        $bestPenalty = PHP_INT_MAX;
        $bestMatrix = null;

        for ($mask = 0; $mask < 8; $mask++) {
            $maskedMatrix = $matrix;

            // Apply mask only to data areas
            for ($row = 0; $row < $size; $row++) {
                for ($col = 0; $col < $size; $col++) {
                    if ($functionMatrix[$row][$col] === -1) {
                        $shouldMask = self::shouldApplyMask($mask, $row, $col);
                        if ($shouldMask) {
                            $maskedMatrix[$row][$col] ^= 1;
                        }
                    }
                }
            }

            self::addFormatInfo($maskedMatrix, $mask, $size);

            $penalty = self::calculatePenalty($maskedMatrix, $size);
            if ($penalty < $bestPenalty) {
                $bestPenalty = $penalty;
                $bestMask = $mask;
                $bestMatrix = $maskedMatrix;
            }
        }

        return self::renderPng($bestMatrix, $size, $moduleSize, $margin);
    }

    /**
     * Check if mask should be applied at position
     */
    private static function shouldApplyMask(int $mask, int $row, int $col): bool
    {
        return match ($mask) {
            0 => (($row + $col) % 2) === 0,
            1 => ($row % 2) === 0,
            2 => ($col % 3) === 0,
            3 => (($row + $col) % 3) === 0,
            4 => ((intdiv($row, 2) + intdiv($col, 3)) % 2) === 0,
            5 => ((($row * $col) % 2) + (($row * $col) % 3)) === 0,
            6 => (((($row * $col) % 2) + (($row * $col) % 3)) % 2) === 0,
            7 => (((($row + $col) % 2) + (($row * $col) % 3)) % 2) === 0,
            default => false,
        };
    }

    /**
     * Render matrix as PNG binary
     */
    private static function renderPng(array $matrix, int $qrSize, int $moduleSize, int $margin): string
    {
        $imageSize = ($qrSize + ($margin * 2)) * $moduleSize;

        // PNG header
        $png = pack('C8', 137, 80, 78, 71, 13, 10, 26, 10);

        // IHDR chunk
        $ihdr = pack('N', $imageSize) . pack('N', $imageSize);
        $ihdr .= pack('C5', 1, 0, 0, 0, 0); // 1-bit depth, grayscale, no compression, no filter, no interlace
        $png .= self::pngChunk('IHDR', $ihdr);

        // IDAT chunk (image data)
        $rawData = '';
        for ($y = 0; $y < $imageSize; $y++) {
            $rawData .= chr(0); // Filter type: None
            $rowData = '';
            $byte = 0;
            $bitCount = 0;

            for ($x = 0; $x < $imageSize; $x++) {
                $matrixY = intdiv($y, $moduleSize) - $margin;
                $matrixX = intdiv($x, $moduleSize) - $margin;

                $pixel = 1; // White by default (margin)
                if ($matrixY >= 0 && $matrixY < $qrSize && $matrixX >= 0 && $matrixX < $qrSize) {
                    $pixel = $matrix[$matrixY][$matrixX] === 1 ? 0 : 1; // Black module = 0, white = 1
                }

                $byte = ($byte << 1) | $pixel;
                $bitCount++;

                if ($bitCount === 8) {
                    $rowData .= chr($byte);
                    $byte = 0;
                    $bitCount = 0;
                }
            }

            // Pad last byte if necessary
            if ($bitCount > 0) {
                $byte <<= (8 - $bitCount);
                $rowData .= chr($byte);
            }

            $rawData .= $rowData;
        }

        $compressed = gzcompress($rawData, 9);
        $png .= self::pngChunk('IDAT', $compressed);

        // IEND chunk
        $png .= self::pngChunk('IEND', '');

        return $png;
    }

    /**
     * Create PNG chunk
     */
    private static function pngChunk(string $type, string $data): string
    {
        $chunk = $type . $data;
        $crc = crc32($chunk);
        return pack('N', strlen($data)) . $chunk . pack('N', $crc);
    }

    /**
     * Generate QR code as base64 data URI
     *
     * @param string $data Data to encode
     * @param int $moduleSize Size of each module in pixels (default: 4)
     * @param int $margin Quiet zone margin in modules (default: 4)
     * @return string Data URI (data:image/png;base64,...)
     */
    public static function toDataUri(string $data, int $moduleSize = 4, int $margin = 4): string
    {
        $png = self::generate($data, $moduleSize, $margin);
        return 'data:image/png;base64,' . base64_encode($png);
    }
}
