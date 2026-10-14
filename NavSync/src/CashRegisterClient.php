<?php
/**
 * Copyright (C) 2026 PatrikMol Solutions Kft. All rights reserved.
 *
 * SOAP/MTOM client for the NAV Online Cash Register (OPF) log file service.
 */

declare(strict_types=1);

namespace NavSync;

use ErrorHandling\ErrorHandler;

/**
 * Sends queryCashRegisterStatus and queryCashRegisterFile requests and unpacks the MTOM answers.
 *
 * Authentication is the Online Számla scheme (SHA-512 password hash, SHA3-512 request signature) and reuses
 * NavAuthHelper. Calls are spaced 2 seconds apart like the invoice client.
 */
class CashRegisterClient implements CashRegisterClientInterface
{
    /** The only NAV file validation result codes the store accepts. */
    private const VALIDATION_CODES = ['OK', 'WARN', 'ERROR'];

    /** Most bytes an unzipped log file may have (a hostile ZIP must not exhaust memory and stop the whole run). */
    private const MAX_UNZIPPED_BYTES = 67108864;

    private const URLS = [
        'production' => 'https://api-onlinepenztargep.nav.gov.hu/queryCashRegisterFile/v1',
        'test'       => 'https://api-test-onlinepenztargep.nav.gov.hu/queryCashRegisterFile/v1',
    ];

    private const SLEEP_US  = 2_000_000;
    private const TIMEOUT_S = 120;

    private const NS_SOAP   = 'http://www.w3.org/2003/05/soap-envelope';
    private const NS_API    = 'http://schemas.nav.gov.hu/OPF/1.0/api';
    private const NS_COMMON = 'http://schemas.nav.gov.hu/NTCA/1.0/common';
    private const NS_XOP    = 'http://www.w3.org/2004/08/xop/include';

    private string $baseUrl;

    /**
     * @param object               $company     Row from nav_companies (plain-text nav_sign_key).
     * @param array<string,string> $software    Software descriptor: softwareId, softwareName, softwareMainVersion, softwareDevName, softwareDevContact.
     * @param string               $environment 'production' or 'test'.
     * @throws \InvalidArgumentException On an unknown environment.
     */
    public function __construct(private readonly object $company, private readonly array $software, string $environment = 'production')
    {
        if (!isset(self::URLS[$environment])) {
            throw new \InvalidArgumentException("NavSync: cash register environment must be 'production' or 'test', got '{$environment}'.");
        }
        $this->baseUrl = self::URLS[$environment];
    }

    /**
     * {@inheritDoc}
     */
    public function queryStatus(): array
    {
        $body = $this->post('/queryCashRegisterStatus', $this->envelope('QueryCashRegisterStatusRequest', '<api:cashRegisterStatusQuery/>'));
        return self::parseStatusResponse($body);
    }

    /**
     * {@inheritDoc}
     *
     * @throws \InvalidArgumentException On an invalid AP number or file-number range (before any request is sent).
     */
    public function queryFiles(string $apNumber, int $from, int $to): array
    {
        if (!preg_match(self::AP_NUMBER_PATTERN, $apNumber) || $from < 1 || $to < $from) {
            throw new \InvalidArgumentException("NavSync: invalid cash register file query ({$apNumber}, {$from}-{$to}).");
        }
        $query = "<api:cashRegisterFileDataQuery><api:APNumber>{$apNumber}</api:APNumber>"
            . "<api:fileNumberStart>{$from}</api:fileNumberStart><api:fileNumberEnd>{$to}</api:fileNumberEnd></api:cashRegisterFileDataQuery>";
        $body = $this->post('/queryCashRegisterFile', $this->envelope('QueryCashRegisterFileDataRequest', $query));
        return self::parseFileResponse($body);
    }

    /**
     * Parses a queryCashRegisterStatus SOAP response.
     *
     * @param string $xml Raw response body.
     * @return list<array{ap_number:string, last_communication_at:?string, last_file_at:?string, min_file:int, max_file:int}>
     * @throws \RuntimeException On malformed XML or a NAV functional error.
     */
    public static function parseStatusResponse(string $xml): array
    {
        $doc = self::loadSoap(self::splitBody($xml)['root']);
        self::assertOkResponse($doc, 'QueryCashRegisterStatusResponse', 'queryCashRegisterStatus');
        $xp   = self::xpath($doc);
        $rows = [];
        foreach ($xp->query('//api:cashRegisterStatus') ?: [] as $node) {
            $rows[] = [
                'ap_number'             => trim($xp->evaluate('string(api:APNumber)', $node)),
                'last_communication_at' => self::utc($xp->evaluate('string(api:lastCommunicationDate)', $node)),
                'last_file_at'          => self::utc($xp->evaluate('string(api:lastFileDate)', $node)),
                'min_file'              => (int)$xp->evaluate('string(api:minAvailableFileNumber)', $node),
                'max_file'              => (int)$xp->evaluate('string(api:maxAvailableFileNumber)', $node),
            ];
        }
        return $rows;
    }

    /**
     * Parses a queryCashRegisterFile MTOM response and unzips each attachment.
     *
     * The body is either a multipart/related message (first line = boundary) or, for errors, a plain SOAP document.
     *
     * @param string $body Raw response body.
     * @return array{files: list<array{file_number:int, p7b:?string, validation_code:string, validation_error:?string, error:?string}>, all_sent:bool}
     * @throws \RuntimeException On a non-SOAP body, malformed XML or a NAV functional error.
     */
    public static function parseFileResponse(string $body): array
    {
        ['root' => $root, 'attachments' => $attachments] = self::splitBody($body);

        $doc = self::loadSoap($root);
        self::assertOkResponse($doc, 'QueryCashRegisterFileDataResponse', 'queryCashRegisterFile');
        $xp = self::xpath($doc);

        $files = [];
        foreach ($xp->query('//api:cashRegisterFileData') ?: [] as $node) {
            $name   = trim($xp->evaluate('string(api:cashRegisterFileName)', $node));
            $number = self::fileNumber($name);
            $file   = [
                'file_number' => $number, 'p7b' => null,
                'validation_code' => trim($xp->evaluate('string(api:fileValidationResultCode)', $node)),
                'validation_error' => ($e = trim($xp->evaluate('string(api:fileValidationErrorCode)', $node))) !== '' ? $e : null,
                'error' => null,
            ];
            $href = $xp->evaluate('string(api:cashRegisterFile/xop:Include/@href)', $node);
            $cid  = $href !== '' ? rawurldecode(preg_replace('/^cid:/', '', $href) ?? '') : '';
            if ($number < 1) {
                $file['error'] = "cannot read the file number from '{$name}'";
            } elseif (!in_array($file['validation_code'], self::VALIDATION_CODES, true)) {
                $file['error'] = "unknown validation code '{$file['validation_code']}'";
            } elseif ($cid === '' || !isset($attachments[$cid])) {
                $file['error'] = 'attachment missing from the response';
            } else {
                [$file['p7b'], $file['error']] = self::unzipSingle($attachments[$cid]);
            }
            $files[] = $file;
        }
        usort($files, static fn(array $a, array $b): int => $a['file_number'] <=> $b['file_number']);

        return ['files' => $files, 'all_sent' => strtolower(trim($xp->evaluate('string(//api:allFilesSent)'))) === 'true'];
    }

    /**
     * Splits an OPF answer into its SOAP root part and its attachments.
     *
     * Every OPF answer is a multipart/related (MTOM) message whose first line is the boundary; a body that does not
     * start with "--" is taken as the SOAP document itself. Attachments are keyed by their Content-ID (without angle brackets).
     *
     * @param string $body Raw response body.
     * @return array{root:string, attachments:array<string,string>}
     * @throws \RuntimeException When a multipart body has no SOAP part.
     */
    private static function splitBody(string $body): array
    {
        if (!str_starts_with($body, '--')) {
            return ['root' => $body, 'attachments' => []];
        }
        $attachments = [];
        $boundary    = trim((string)strtok($body, "\r\n"));
        $parts       = explode("\r\n" . $boundary, "\r\n" . $body);
        array_shift($parts);
        $root = null;
        foreach ($parts as $part) {
            if (str_starts_with($part, '--')) {
                break;
            }
            $split = strpos($part, "\r\n\r\n");
            if ($split === false) {
                continue;
            }
            $headers = substr($part, 0, $split);
            $content = substr($part, $split + 4);
            if ($root === null) {
                $root = $content;
            } elseif (preg_match('/^Content-ID:\s*<([^>]+)>/mi', $headers, $m)) {
                $attachments[$m[1]] = $content;
            }
        }
        if ($root === null) {
            throw new \RuntimeException('NavSync: cash register response has no SOAP part');
        }
        return ['root' => $root, 'attachments' => $attachments];
    }

    /**
     * Builds the SOAP envelope with the authentication header and the operation body.
     *
     * @param string $root      Request element name, e.g. "QueryCashRegisterFileDataRequest".
     * @param string $queryXml  The operation specific query element (already namespaced with "api:").
     * @return string Complete SOAP 1.2 document.
     */
    private function envelope(string $root, string $queryXml): string
    {
        $requestId = NavAuthHelper::requestId();
        $timestamp = NavAuthHelper::timestamp();
        $e         = static fn(string $v): string => htmlspecialchars($v, ENT_XML1);
        $signature = NavAuthHelper::requestSignature($requestId, $timestamp, $this->company->nav_sign_key);
        $software  = $this->software;

        $devTax = isset($software['softwareDevTaxNumber']) && $software['softwareDevTaxNumber'] !== ''
            ? '<api:softwareDevTaxNumber>' . $e($software['softwareDevTaxNumber']) . '</api:softwareDevTaxNumber>' : '';

        return '<?xml version="1.0" encoding="UTF-8"?>'
            . '<soap:Envelope xmlns:soap="' . self::NS_SOAP . '" xmlns:api="' . self::NS_API . '" xmlns:com="' . self::NS_COMMON . '">'
            . '<soap:Header/><soap:Body><api:' . $root . '>'
            . '<com:header><com:requestId>' . $requestId . '</com:requestId><com:timestamp>' . $timestamp . '</com:timestamp>'
            . '<com:requestVersion>1.0</com:requestVersion><com:headerVersion>1.0</com:headerVersion></com:header>'
            . '<com:user><com:login>' . $e((string)$this->company->nav_login) . '</com:login>'
            . '<com:passwordHash cryptoType="SHA-512">' . $e((string)$this->company->nav_password) . '</com:passwordHash>'
            . '<com:taxNumber>' . $e((string)$this->company->tax_number) . '</com:taxNumber>'
            . '<com:requestSignature cryptoType="SHA3-512">' . $signature . '</com:requestSignature></com:user>'
            . '<api:software><api:softwareId>' . $e((string)($software['softwareId'] ?? '')) . '</api:softwareId>'
            . '<api:softwareName>' . $e((string)($software['softwareName'] ?? '')) . '</api:softwareName>'
            . '<api:softwareOperation>LOCAL_SOFTWARE</api:softwareOperation>'
            . '<api:softwareMainVersion>' . $e((string)($software['softwareMainVersion'] ?? '')) . '</api:softwareMainVersion>'
            . '<api:softwareDevName>' . $e((string)($software['softwareDevName'] ?? '')) . '</api:softwareDevName>'
            . '<api:softwareDevContact>' . $e((string)($software['softwareDevContact'] ?? '')) . '</api:softwareDevContact>'
            . '<api:softwareDevCountryCode>HU</api:softwareDevCountryCode>' . $devTax . '</api:software>'
            . $queryXml
            . '</api:' . $root . '></soap:Body></soap:Envelope>';
    }

    /**
     * Sends a SOAP request and returns the raw response body, then waits for the rate limit.
     *
     * @param string $path Operation path, e.g. "/queryCashRegisterFile".
     * @param string $xml  SOAP envelope.
     * @return string Raw response body.
     * @throws \RuntimeException On cURL error or a non-200 HTTP status.
     */
    private function post(string $path, string $xml): string
    {
        $ch = curl_init($this->baseUrl . $path);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $xml,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => self::TIMEOUT_S,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/soap+xml; charset=utf-8', 'Accept: multipart/related, application/soap+xml', 'Referer:'],
        ]);
        $response  = curl_exec($ch);
        $httpCode  = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);
        usleep(self::SLEEP_US);

        if ($curlError !== '') {
            throw new \RuntimeException("NavSync: curl error on cash register {$path}: {$curlError}");
        }
        if ($response === false || $httpCode !== 200) {
            $detail = is_string($response) ? NavApiClient::summarizeError($response) : '';
            throw new \RuntimeException("NavSync: HTTP {$httpCode} on cash register {$path}" . ($detail !== '' ? " — {$detail}" : ''));
        }
        return $response;
    }

    /**
     * Loads a SOAP document.
     *
     * @param string $xml Raw XML.
     * @return \DOMDocument
     * @throws \RuntimeException When the text is not XML or has no SOAP envelope.
     */
    private static function loadSoap(string $xml): \DOMDocument
    {
        if (trim($xml) === '') {
            throw new \RuntimeException('NavSync: cash register response is empty');
        }
        $doc      = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            $ok = $doc->loadXML($xml, LIBXML_NONET);
        } finally {
            if ($previous === false) {
                libxml_clear_errors();   // only drop what this call collected; a host that collects errors keeps its own
            }
            libxml_use_internal_errors($previous);   // never leave the host's libxml error mode changed
        }
        if (!$ok || $doc->documentElement === null || $doc->documentElement->localName !== 'Envelope') {
            throw new \RuntimeException('NavSync: cash register response is not a SOAP document: ' . mb_substr(trim((string)preg_replace('/\s+/', ' ', strip_tags($xml))), 0, 200));
        }
        return $doc;
    }

    /**
     * XPath context with the prefixes used in this class.
     *
     * @param \DOMDocument $doc SOAP document.
     * @return \DOMXPath
     */
    private static function xpath(\DOMDocument $doc): \DOMXPath
    {
        $xp = new \DOMXPath($doc);
        $xp->registerNamespace('api', self::NS_API);
        $xp->registerNamespace('com', self::NS_COMMON);
        $xp->registerNamespace('xop', self::NS_XOP);
        return $xp;
    }

    /**
     * Requires the expected response element and a funcCode of OK; anything else is an error.
     *
     * Guards against a SOAP Fault, a changed namespace/operation or a missing funcCode silently parsing as an empty result.
     *
     * @param \DOMDocument $doc      SOAP document.
     * @param string       $expected Local name of the expected response element, e.g. "QueryCashRegisterStatusResponse".
     * @param string       $endpoint Operation name for the message.
     * @return void
     * @throws \RuntimeException On a Fault, an unexpected element, funcCode ERROR (with NAV's code and message) or a funcCode other than OK.
     */
    private static function assertOkResponse(\DOMDocument $doc, string $expected, string $endpoint): void
    {
        $xp = self::xpath($doc);
        $xp->registerNamespace('soap', self::NS_SOAP);
        $first = $xp->query('/soap:Envelope/soap:Body/*')->item(0);
        if (!$first instanceof \DOMElement) {
            throw new \RuntimeException("NavSync: cash register {$endpoint} response has no SOAP body element");
        }
        if ($first->localName === 'Fault') {
            throw new \RuntimeException("NavSync: cash register {$endpoint} answered with a SOAP Fault");
        }
        $funcCode = trim($xp->evaluate('string(com:result/com:funcCode)', $first));
        if ($funcCode === 'ERROR') {
            $code = trim($xp->evaluate('string(com:result/com:errorCode)', $first));
            $msg  = trim($xp->evaluate('string(com:result/com:message)', $first));
            throw new \RuntimeException("NavSync: NAV error on cash register {$endpoint} — [{$code}] {$msg}");
        }
        if ($first->localName !== $expected || $first->namespaceURI !== self::NS_API) {
            throw new \RuntimeException(sprintf(
                'NavSync: cash register %s answered with an unexpected element "%s" in namespace "%s"',
                $endpoint,
                mb_substr((string)$first->localName, 0, 80),
                mb_substr((string)$first->namespaceURI, 0, 120)
            ));
        }
        if ($funcCode !== 'OK') {
            throw new \RuntimeException(sprintf(
                'NavSync: cash register %s response has %s funcCode%s',
                $endpoint,
                $funcCode === '' ? 'no' : 'an unknown',
                $funcCode === '' ? '' : ' "' . mb_substr($funcCode, 0, 40) . '"'
            ));
        }
    }

    /**
     * File number of a NAV file name "<AP>_<tax>_<yyyyMMddHHmmss>_<number>.P7B"; 0 when unreadable.
     *
     * @param string $name File name from the response.
     * @return int
     */
    private static function fileNumber(string $name): int
    {
        return preg_match('/_(\d+)\.p7b$/i', $name, $m) === 1 ? (int)$m[1] : 0;
    }

    /**
     * Normalises a NAV UTC timestamp to "Y-m-d H:i:s", or null when empty/unreadable.
     *
     * @param string $value ISO 8601 text.
     * @return string|null
     */
    private static function utc(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        try {
            return (new \DateTimeImmutable($value, new \DateTimeZone('UTC')))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        } catch (\Throwable $e) {
            ErrorHandler::warning('[NavSync] unreadable cash register timestamp', ['module' => 'NavSync', 'step' => 'cash_register_status', 'value' => $value, 'reason' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Unzips a ZIP archive that holds exactly one file.
     *
     * @param string $zip      ZIP bytes.
     * @param int    $maxBytes Largest accepted unzipped size.
     * @return array{0:?string, 1:?string} [file bytes, null] or [null, error text].
     */
    private static function unzipSingle(string $zip, int $maxBytes = self::MAX_UNZIPPED_BYTES): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'navcr');
        if ($tmp === false) {
            return [null, 'cannot create a temporary file'];
        }
        try {
            if (file_put_contents($tmp, $zip) === false) {
                return [null, 'cannot write the temporary file'];
            }
            $archive = new \ZipArchive();
            if ($archive->open($tmp) !== true) {
                return [null, 'attachment is not a valid ZIP archive'];
            }
            try {
                if ($archive->numFiles !== 1) {
                    return [null, "ZIP archive holds {$archive->numFiles} files, expected 1"];
                }
                $stat = $archive->statIndex(0);
                if ($stat === false) {
                    return [null, 'cannot read the file entry of the ZIP archive'];
                }
                if ($stat['size'] > $maxBytes) {
                    return [null, "the file inside the ZIP archive is too large ({$stat['size']} bytes, limit {$maxBytes})"];
                }
                $bytes = $archive->getFromIndex(0);
                return $bytes === false ? [null, 'cannot read the file inside the ZIP archive'] : [$bytes, null];
            } finally {
                $archive->close();
            }
        } finally {
            if (is_file($tmp)) {
                unlink($tmp);
            }
        }
    }
}
