<?php
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

set_error_handler(function($severity, $message, $file, $line) {
    throw new ErrorException($message, 0, $severity, $file, $line);
});
/**
 * Thermal Printer API
 * Translates HTTP requests into ESC/POS commands sent over TCP to a network printer.
 *
 * Auth:   Bearer token (set PRINTER_TOKEN below)
 * Access: IP allowlist (CIDR notation; default 0.0.0.0/0 = everyone)
 */

// ============================================================
//  CONFIGURATION
// ============================================================

define('PRINTER_HOST', '192.168.1.100');   // Printer IP
define('PRINTER_PORT', 9100);              // Printer port (default 9100 for ESC/POS)
define('PRINTER_TIMEOUT', 5);             // TCP timeout in seconds

define('BEARER_TOKEN', 'your-secret-token'); // Bearer token for auth

// IP allowlist: CIDR ranges or exact IPs.
// '0.0.0.0/0' and '::/0' allow all IPv4 / IPv6.
define('ALLOWED_IPS', [
    '192.168.1.0/24',
]);

// ============================================================
//  BOOTSTRAP
// ============================================================

header('Content-Type: application/json');

function json_response(int $status, array $body): void {
    http_response_code($status);
    echo json_encode($body);
    exit;
}

// ── IP filter ────────────────────────────────────────────────

function ip_in_cidr(string $ip, string $cidr): bool {
    if (strpos($cidr, '/') === false) {
        return $ip === $cidr;                       // exact match
    }
    [$subnet, $bits] = explode('/', $cidr, 2);
    $bits = (int)$bits;

    // IPv6
    if (strpos($ip, ':') !== false) {
        $ip_bin     = inet_pton($ip);
        $subnet_bin = inet_pton($subnet);
        if ($ip_bin === false || $subnet_bin === false) return false;
        $full = 128;
        $mask = str_repeat("\xff", intdiv($bits, 8));
        if ($bits % 8) $mask .= chr(0xff & (0xff << (8 - ($bits % 8))));
        $mask = str_pad($mask, $full / 8, "\x00");
        return ($ip_bin & $mask) === ($subnet_bin & $mask);
    }

    // IPv4
    $ip_long     = ip2long($ip);
    $subnet_long = ip2long($subnet);
    if ($ip_long === false || $subnet_long === false) return false;
    $mask = $bits === 0 ? 0 : (~0 << (32 - $bits));
    return ($ip_long & $mask) === ($subnet_long & $mask);
}

function check_ip(): void {
    $fwd = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
    $client_ip = $fwd ? trim(explode(',', $fwd)[0]) : ($_SERVER['REMOTE_ADDR'] ?? '');

    foreach (ALLOWED_IPS as $cidr) {
        if (ip_in_cidr($client_ip, $cidr)) return;
    }
    json_response(403, ['error' => "IP not allowed: $client_ip"]);
}

// ── Bearer token ─────────────────────────────────────────────

function check_auth(): void {
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (!preg_match('/^Bearer\s+(.+)$/i', $header, $m) || $m[1] !== BEARER_TOKEN) {
        header('WWW-Authenticate: Bearer realm="ThermalPrinter"');
        json_response(401, ['error' => 'Unauthorized']);
    }
}

check_ip();
check_auth();

// ── Routing ──────────────────────────────────────────────────

$method = $_SERVER['REQUEST_METHOD'];
$path   = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
// Strip the script filename so /dir/thermal_printer_api.php/batch -> /batch
$script = $_SERVER['SCRIPT_NAME'];
if (substr($path, 0, strlen($script)) === $script) {
    $path = substr($path, strlen($script));
}
$path = '/' . trim($path, '/');

if ($method !== 'POST') {
    json_response(405, ['error' => 'Method not allowed']);
}

$body = json_decode(file_get_contents('php://input'), true);
if ($body === null) {
    json_response(400, ['error' => 'Invalid JSON body']);
}

// ============================================================
//  THERMAL PRINTER CLASS
// ============================================================

class ThermalPrinter {

    private string $host;
    private int    $port;
    private int    $timeout;
    /** @var resource|null */
    private $sock = null;

    // PC437 character map (same as original Python)
    private array $charMap = [
        // Swedish
        "Ä" => "\x8E", "Å" => "\x8F", "Ö" => "\x99",
        "ä" => "\x84", "å" => "\x86", "ö" => "\x94",
        // Ligatures
        "œ" => "oe",   "Œ" => "OE",
        "æ" => "\x91", "Æ" => "\x92",
        // Math & Measures
        "°" => "\xF8", "½" => "\xAB", "¼" => "\xAC",
        "²" => "\xFD", "±" => "\xF1", "÷" => "\xF6",
        "≈" => "\xF7", "√" => "\xFB",
        // French/European
        "é" => "\x82", "à" => "\x85", "ç" => "\x87",
        "ê" => "\x88", "ë" => "\x89", "è" => "\x8A",
        "ï" => "\x8B", "î" => "\x8C", "ì" => "\x8D",
        "ü" => "\x81", "û" => "\x96", "ù" => "\x97",
        "ÿ" => "\x98", "ô" => "\x93", "ò" => "\x95",
        "ñ" => "\xA4", "Ñ" => "\xA5", "ß" => "\xE1",
        // Symbols
        "£" => "\x9C", "¥" => "\x9D", "¢" => "\x9B",
        "·" => "\xFA", "■" => "\xFE", "€" => "\xD5",
        "█" => "\xDB", "▀" => "\xDF", "▄" => "\xDC",
        "─" => "\xC4", "═" => "\xCD",
        "–" => "-",    "—" => "-",
    ];

    public function __construct(string $host, int $port = 9100, int $timeout = 5) {
        $this->host    = $host;
        $this->port    = $port;
        $this->timeout = $timeout;
    }

    // ── Low-level ─────────────────────────────────────────────

    private function connect(): bool {
        if ($this->sock) return true;
        $this->sock = @fsockopen($this->host, $this->port, $errno, $errstr, $this->timeout);
        if (!$this->sock) {
            $this->sock = null;
            throw new RuntimeException("Printer connection failed: $errstr ($errno)");
        }
        stream_set_timeout($this->sock, $this->timeout);
        return true;
    }

    private function send(string $data): void {
        $this->connect();
        $written = fwrite($this->sock, $data);
        if ($written === false) {
            fclose($this->sock);
            $this->sock = null;
            throw new RuntimeException("Failed to send data to printer");
        }
    }

    private function read(int $count = 1): ?string {
        $this->connect();
        stream_set_timeout($this->sock, 1);
        $buf = fread($this->sock, $count);
        stream_set_timeout($this->sock, $this->timeout);
        return ($buf !== false && strlen($buf) === $count) ? $buf : null;
    }

    public function close(): void {
        if ($this->sock) {
            fclose($this->sock);
            $this->sock = null;
        }
    }

    // ── Status ────────────────────────────────────────────────

    public function checkReady(): bool {
        $this->send("\x10\x04\x02");   // DLE EOT 2
        $resp = $this->read(1);
        if ($resp === null) return true;
        $byte = ord($resp[0]);
        if ($byte & 0b00100000) throw new RuntimeException("Printer error: Out of paper");
        if ($byte & 0b00000100) throw new RuntimeException("Printer error: Cover open");
        if ($byte & 0b01000000) throw new RuntimeException("Printer error: Generic error");
        return true;
    }

    // ── Helpers ───────────────────────────────────────────────

    private function parseSize(string $size): string {
        [$w, $h] = array_map('intval', explode(',', $size) + [1, 1]);
        $w = max(1, min(8, $w));
        $h = max(1, min(8, $h));
        $n = (($w - 1) << 4) | ($h - 1);
        return "\x1D\x21" . chr($n);
    }

    // Unicode-safe character count (mirrors the preg_split('//u') approach
    // already used in encodeText, so this doesn't add an mbstring dependency).
    private function uLen(string $str): int {
        return count(preg_split('//u', $str, -1, PREG_SPLIT_NO_EMPTY));
    }

    // Chars-per-line for a given size string. 42 chars is the measured
    // width at normal size (w=1); wider fonts fit proportionally fewer.
    private function getLineWidth(string $size): int {
        [$w, ] = array_map('intval', explode(',', $size) + [1, 1]);
        $w = max(1, min(8, $w));
        return max(1, intdiv(42, $w));
    }

    /**
     * Word-wraps text to $width visible characters per line so words
     * aren't cut mid-word. Explicit newlines are preserved as paragraph
     * breaks and wrapped independently. Whitespace is preserved exactly
     * as typed except at the point a line wraps (where it's dropped, as
     * with normal word wrap). <b>/<u> tags don't count toward the width.
     * A single word longer than $width is left intact on its own line
     * for the printer to hard-wrap, same as the previous behavior.
     */
    private function wrapText(string $text, int $width): string {
        $paragraphs = explode("\n", $text);
        $wrapped = array_map(fn($p) => $this->wrapParagraph($p, $width), $paragraphs);
        return implode("\n", $wrapped);
    }

    private function wrapParagraph(string $paragraph, int $width): string {
        if ($paragraph === '') return '';

        $tokens = preg_split('/(\s+)/', $paragraph, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);

        $lines       = [];
        $currentLine = '';
        $currentLen  = 0;
        $pendingWs   = '';

        foreach ($tokens as $token) {
            if (trim($token) === '') {
                $pendingWs = $token;
                continue;
            }

            $visLen = $this->uLen(preg_replace('/<\/?[bu]>/i', '', $token));
            $sepLen = $this->uLen($pendingWs);

            if ($currentLen === 0 || $currentLen + $sepLen + $visLen <= $width) {
                $currentLine .= $pendingWs . $token;
                $currentLen  += $sepLen + $visLen;
            } else {
                $lines[]     = $currentLine;
                $currentLine = $token;
                $currentLen  = $visLen;
            }
            $pendingWs = '';
        }

        $lines[] = $currentLine . $pendingWs;
        return implode("\n", $lines);
    }

    private function getCutBytes(int $mode): string {
        if ($mode === 0) return '';
        $b  = "\x0A\x0A";
        if ($mode === 1) $b .= "\x1D\x56\x41\x00";
        elseif ($mode === 2) $b .= "\x1D\x56\x42\x00";
        return $b;
    }

    private function encodeText(string $text): string {
        $out = '';
        // mb_str_split requires PHP 7.4+
        $chars = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY);
        foreach ($chars as $char) {
            if (isset($this->charMap[$char])) {
                $out .= $this->charMap[$char];
            } else {
                // ASCII passthrough; replace unmappable chars with '?'
                $out .= (strlen($char) === 1 && ord($char) < 128) ? $char : '?';
            }
        }
        return $out;
    }

    /**
     * Encodes text while honoring inline <b>...</b> and <u>...</u> tags,
     * toggling ESC/POS bold (\x1B\x45) and underline (\x1B\x2D) as
     * they're encountered. Unclosed tags apply to the rest of the string.
     * Unrecognized tags (anything not <b>, </b>, <u>, </u>) are stripped.
     */
    private function encodeTextWithTags(string $text, bool $baseBold): string {
        $out       = '';
        $bold      = $baseBold;
        $underline = false;

        // Split on <b>, </b>, <u>, </u> (case-insensitive), keeping delimiters
        $parts = preg_split(
            '/(<b>|<\/b>|<u>|<\/u>)/i',
            $text, -1,
            PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY
        );

        foreach ($parts as $part) {
            $lower = strtolower($part);

            if ($lower === '<b>') {
                if (!$bold) {
                    $out .= "\x1B\x45\x01";
                    $bold = true;
                }
                continue;
            }
            if ($lower === '</b>') {
                if ($bold !== $baseBold) {
                    $out .= "\x1B\x45" . ($baseBold ? "\x01" : "\x00");
                    $bold = $baseBold;
                }
                continue;
            }
            if ($lower === '<u>') {
                if (!$underline) {
                    $out .= "\x1B\x2D\x01";
                    $underline = true;
                }
                continue;
            }
            if ($lower === '</u>') {
                if ($underline) {
                    $out .= "\x1B\x2D\x00";
                    $underline = false;
                }
                continue;
            }

            // Strip any other stray tags, then encode normally
            $clean = strip_tags($part);
            $out  .= $this->encodeText($clean);
        }

        // Reset underline at end of string so it doesn't bleed into the
        // next print command (bold is already reset to $baseBold above)
        if ($underline) {
            $out .= "\x1B\x2D\x00";
        }

        return $out;
    }

    // ── Byte generators ───────────────────────────────────────

    public function buildPrintBytes(
        string $text,
        string $align = 'left',
        string $size  = '1,1',
        bool   $bold  = false,
        int    $cut   = 0
    ): string {
        $alignVal = ['center' => 1, 'right' => 2][$align] ?? 0;
        $cmd  = "\x1B\x40";                                        // init
        $cmd .= "\x1B\x61" . chr($alignVal);                      // align
        $cmd .= "\x1B\x45" . ($bold ? "\x01" : "\x00");           // bold
        $cmd .= $this->parseSize($size);
        $wrapped = $this->wrapText($text, $this->getLineWidth($size));
        $cmd .= $this->encodeTextWithTags($wrapped, $bold);
        $cmd .= "\x0A";                                            // newline
        $cmd .= $this->getCutBytes($cut);
        return $cmd;
    }

    public function buildQrBytes(
        string $data,
        string $align = 'center',
        int    $size  = 6,
        int    $cut   = 0
    ): string {
        $alignVal = ['center' => 1, 'right' => 2][$align] ?? 0;
        $cmd  = "\x1B\x40";
        $cmd .= "\x1B\x61" . chr($alignVal);
        $cmd .= "\x1D\x28\x6B\x04\x00\x31\x41\x32\x00";         // model 2
        $cmd .= "\x1D\x28\x6B\x03\x00\x31\x43" . chr($size);    // cell size
        $d     = preg_replace('/[\x80-\xFF]/', '', $data);
        $l     = strlen($d) + 3;
        $cmd .= "\x1D\x28\x6B" . chr($l % 256) . chr(intdiv($l, 256)) . "\x31\x50\x30";
        $cmd .= $d;
        $cmd .= "\x1D\x28\x6B\x03\x00\x31\x51\x30";              // print
        $cmd .= "\x0A";
        $cmd .= $this->getCutBytes($cut);
        return $cmd;
    }

    public function buildBarcodeBytes(
        string $data,
        string $type   = 'EAN13',
        string $hri    = 'below',
        int    $height = 64,
        int    $cut    = 0
    ): string {
        $hriMap  = ['none' => 0, 'below' => 2, 'above' => 1, 'both' => 3];
        $typeMap = [
            'UPCA' => 65, 'UPCE' => 66, 'EAN13' => 67, 'EAN8' => 68,
            'CODE39' => 69, 'ITF' => 70, 'CODABAR' => 71, 'CODE93' => 72, 'CODE128' => 73,
        ];
        $m = $typeMap[strtoupper($type)] ?? 73;
        $d = preg_replace('/[\x80-\xFF]/', '', $data);

        $cmd  = "\x1B\x40\x1B\x61\x01";                                   // center
        $cmd .= "\x1D\x48" . chr($hriMap[$hri] ?? 2);                     // HRI pos
        $cmd .= "\x1D\x68" . chr(max(1, min(255, $height)));               // height
        $cmd .= "\x1D\x77\x02";                                            // width
        $cmd .= "\x1D\x6B" . chr($m) . chr(strlen($d)) . $d;
        $cmd .= "\x0A";
        $cmd .= $this->getCutBytes($cut);
        return $cmd;
    }

    // ── Public print actions ──────────────────────────────────

    public function printText(string $text, string $align, string $size, bool $bold, int $cut): void {
        $this->checkReady();
        $this->send($this->buildPrintBytes($text, $align, $size, $bold, $cut));
    }

    public function printQr(string $data, string $align, int $size, int $cut): void {
        $this->checkReady();
        $this->send($this->buildQrBytes($data, $align, $size, $cut));
    }

    public function printBarcode(string $data, string $type, string $hri, int $height, int $cut): void {
        $this->checkReady();
        $this->send($this->buildBarcodeBytes($data, $type, $hri, $height, $cut));
    }

    /**
     * Batch: sends all commands in one TCP write.
     * Type is inferred from which key is present:
     *   { "text": "Hello", ... }           → print text
     *   { "qr": "https://...", ... }        → QR code
     *   { "barcode": "123456789012", ... }  → barcode
     */
    public function printBatch(array $commands): void {
        $this->checkReady();
        $buf = '';
        foreach ($commands as $i => $cmd) {
            if (isset($cmd['text'])) {
                $buf .= $this->buildPrintBytes(
                    $cmd['text'],
                    $cmd['align'] ?? 'left',
                    $cmd['size']  ?? '1,1',
                    (bool)($cmd['bold'] ?? false),
                    (int)($cmd['cut']   ?? 0)
                );
            } elseif (isset($cmd['qr'])) {
                $buf .= $this->buildQrBytes(
                    $cmd['qr'],
                    $cmd['align'] ?? 'center',
                    (int)($cmd['size'] ?? 6),
                    (int)($cmd['cut']  ?? 0)
                );
            } elseif (isset($cmd['barcode'])) {
                $buf .= $this->buildBarcodeBytes(
                    $cmd['barcode'],
                    $cmd['barcode_type'] ?? 'EAN13',
                    $cmd['hri']          ?? 'below',
                    (int)($cmd['height'] ?? 64),
                    (int)($cmd['cut']    ?? 0)
                );
            } else {
                throw new InvalidArgumentException(
                    "Command at index $i must have a 'text', 'qr', or 'barcode' key"
                );
            }
        }
        $this->send($buf);
    }
}

// ============================================================
//  ROUTE HANDLERS
// ============================================================

try {
    $printer = new ThermalPrinter(PRINTER_HOST, PRINTER_PORT, PRINTER_TIMEOUT);

    switch ($path) {

        // ── POST /print ──────────────────────────────────────
        // Body: { "text": "Hello", "align": "left", "size": "1,1", "bold": false, "cut": 0 }
        case '/print':
            $text  = $body['text']  ?? '';
            $align = $body['align'] ?? 'left';
            $size  = $body['size']  ?? '1,1';
            $bold  = (bool)($body['bold'] ?? false);
            $cut   = (int)($body['cut']   ?? 0);

            if ($text === '') json_response(400, ['error' => '"text" is required']);

            $printer->printText($text, $align, $size, $bold, $cut);
            json_response(200, ['ok' => true, 'action' => 'print']);
            break;

        // ── POST /qr ─────────────────────────────────────────
        // Body: { "data": "https://example.com", "align": "center", "size": 6, "cut": 0 }
        case '/qr':
            $data  = $body['data']  ?? '';
            $align = $body['align'] ?? 'center';
            $size  = (int)($body['size'] ?? 6);
            $cut   = (int)($body['cut']  ?? 0);

            if ($data === '') json_response(400, ['error' => '"data" is required']);

            $printer->printQr($data, $align, $size, $cut);
            json_response(200, ['ok' => true, 'action' => 'qr']);
            break;

        // ── POST /barcode ─────────────────────────────────────
        // Body: { "data": "123456789012", "barcode_type": "EAN13", "hri": "below", "height": 64, "cut": 0 }
        case '/barcode':
            $data   = $body['data']          ?? '';
            $type   = $body['barcode_type']  ?? 'EAN13';
            $hri    = $body['hri']           ?? 'below';
            $height = (int)($body['height']  ?? 64);
            $cut    = (int)($body['cut']     ?? 0);

            if ($data === '') json_response(400, ['error' => '"data" is required']);

            $printer->printBarcode($data, $type, $hri, $height, $cut);
            json_response(200, ['ok' => true, 'action' => 'barcode']);
            break;

        // ── POST /batch ───────────────────────────────────────
        // Body: { "commands": [ { "type": "text", "text": "Hi", "cut": 2 }, ... ] }
        case '/batch':
            $commands = $body['commands'] ?? [];
            if (!is_array($commands) || empty($commands)) {
                json_response(400, ['error' => '"commands" must be a non-empty array']);
            }
            $printer->printBatch($commands);
            json_response(200, ['ok' => true, 'action' => 'batch', 'count' => count($commands)]);
            break;

        // ── POST /status ──────────────────────────────────────
        case '/status':
            $ready = $printer->checkReady();
            json_response(200, ['ok' => true, 'ready' => $ready]);
            break;

        default:
            json_response(404, ['error' => "Unknown endpoint: $path"]);
    }

    $printer->close();

} catch (InvalidArgumentException $e) {
    json_response(400, ['error' => $e->getMessage()]);
} catch (RuntimeException $e) {
    json_response(502, ['error' => $e->getMessage()]);
} catch (Throwable $e) {
    json_response(500, ['error' => 'Internal server error', 'detail' => $e->getMessage()]);
}