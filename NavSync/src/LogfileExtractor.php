<?php
/**
 * Copyright (C) 2026 PatrikMol Solutions Kft. All rights reserved.
 *
 * Extracts the log XML from a NAV cash register P7B file.
 */

declare(strict_types=1);

namespace NavSync;

/**
 * Reads the XML out of a P7B log file.
 *
 * NAV's P7B is a PKCS#7 signedData whose encapsulated content type is digestData, which OpenSSL and PHP cannot read.
 * The XML is a plain OCTET STRING inside, so a small DER walk finds it. The signature is not verified (see the design spec).
 */
final class LogfileExtractor
{
    /** Deepest DER nesting followed; a real file nests about five levels. */
    private const MAX_DEPTH = 12;

    /** DER tag of an OCTET STRING. */
    private const TAG_OCTET_STRING = 0x04;

    /** Bit set in the tag of a constructed (container) element. */
    private const CONSTRUCTED = 0x20;

    /**
     * Returns the XML text of a P7B file as UTF-8.
     *
     * NAV declares UTF-8 but sample files contain single-byte Hungarian letters; such content is converted from
     * Windows-1250 and reported through "recoded" so the caller can log it. A leading UTF-8 byte order mark is
     * stripped from the returned XML.
     *
     * @param string $p7b Raw DER bytes of the P7B file.
     * @return array{xml:string, recoded:bool}
     * @throws \RuntimeException When the data is not DER or holds no XML content.
     */
    public function extract(string $p7b): array
    {
        $xml = $this->findXml($p7b, 0, strlen($p7b), 0);
        if ($xml === null) {
            throw new \RuntimeException('NavSync: no XML content found in the P7B file');
        }
        $recoded = false;
        // Strip a UTF-8 byte order mark first: converting it as Windows-1250 would turn it into visible garbage.
        if (str_starts_with($xml, "\xEF\xBB\xBF")) {
            $xml = substr($xml, 3);
        }
        if (!mb_check_encoding($xml, 'UTF-8')) {
            // PHP's mbstring has no Windows-1250; iconv does. The sample bytes cannot tell CP1250 from ISO-8859-2 (same Hungarian letters).
            // iconv() raises E_NOTICE on bytes undefined in CP1250. "@" does not stop a host error handler that turns
            // notices into exceptions, so a scoped handler swallows it; the false return is checked right below.
            set_error_handler(static fn (): bool => true);
            try {
                $converted = iconv('CP1250', 'UTF-8', $xml);
            } finally {
                restore_error_handler();
            }
            if ($converted === false) {
                throw new \RuntimeException('NavSync: the log XML is not valid UTF-8 and could not be converted from Windows-1250');
            }
            $xml     = $converted;
            $recoded = true;
        }
        return ['xml' => $xml, 'recoded' => $recoded];
    }

    /**
     * Walks the DER elements between $start and $end depth-first and returns the first OCTET STRING that looks like XML.
     *
     * @param string $data  The whole DER blob.
     * @param int    $start First byte of the region.
     * @param int    $end   Byte after the region.
     * @param int    $depth Current nesting depth.
     * @return string|null The XML bytes, or null when the region holds none.
     */
    private function findXml(string $data, int $start, int $end, int $depth): ?string
    {
        if ($depth > self::MAX_DEPTH) {
            return null;
        }
        $pos = $start;
        while ($pos < $end) {
            $header = $this->readHeader($data, $pos, $end);
            if ($header === null) {
                return null;
            }
            [$tag, $headerLength, $length] = $header;
            $contentStart = $pos + $headerLength;
            $contentEnd   = $contentStart + $length;
            if ($contentEnd > $end) {
                return null;
            }
            if ($tag === self::TAG_OCTET_STRING) {
                $content = substr($data, $contentStart, $length);
                if (str_starts_with(ltrim($content, "\xEF\xBB\xBF \t\r\n"), '<')) {
                    return $content;
                }
            } elseif (($tag & self::CONSTRUCTED) !== 0) {
                $found = $this->findXml($data, $contentStart, $contentEnd, $depth + 1);
                if ($found !== null) {
                    return $found;
                }
            }
            $pos = $contentEnd;
        }
        return null;
    }

    /**
     * Reads one DER tag-length header.
     *
     * @param string $data Whole DER blob.
     * @param int    $pos  Offset of the tag byte.
     * @param int    $end  Byte after the readable region.
     * @return array{0:int, 1:int, 2:int}|null [tag, header length, content length]; null when the header is
     *         truncated or uses a form this reader does not support (indefinite length).
     */
    private function readHeader(string $data, int $pos, int $end): ?array
    {
        if ($pos + 2 > $end) {
            return null;
        }
        $tag  = ord($data[$pos]);
        $byte = ord($data[$pos + 1]);
        if ($byte < 0x80) {
            return [$tag, 2, $byte];
        }
        $count = $byte & 0x7F;
        if ($count === 0 || $count > 4 || $pos + 2 + $count > $end) {
            return null;
        }
        $length = 0;
        for ($i = 0; $i < $count; $i++) {
            $length = ($length << 8) | ord($data[$pos + 2 + $i]);
        }
        return [$tag, 2 + $count, $length];
    }
}
