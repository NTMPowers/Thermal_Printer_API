# Thermal Printer API

A lightweight PHP API that sends ESC/POS commands over TCP to a network-connected thermal printer.

## Requirements

- PHP 7.4+
- lighttpd / Apache / nginx with PHP-FPM
- Network-connected ESC/POS printer (port 9100)
- No PHP extensions required

---

## Installation

1. Copy `thermal_printer_api.php` to your web root, e.g. `/var/www/html/printer/`
2. Edit the configuration constants at the top of the file:

```php
define('PRINTER_HOST', '192.168.1.100');  // Your printer's IP
define('PRINTER_PORT', 9100);
define('PRINTER_TIMEOUT', 5);
define('BEARER_TOKEN', 'your-secret-token');
define('ALLOWED_IPS', [
    '0.0.0.0/0',        // Allow all (default)
    // '192.168.1.0/24' // Or restrict to a subnet
    // '10.0.0.5'       // Or an exact IP
]);
```

---

## Authentication

Every request must include a Bearer token header:

```
Authorization: Bearer your-secret-token
```

Requests without a valid token return `401 Unauthorized`.

---

## IP Filtering

`ALLOWED_IPS` accepts any mix of exact IPs and CIDR ranges, both IPv4 and IPv6.

```php
define('ALLOWED_IPS', [
    '192.168.1.0/24',   // Entire subnet
    '10.0.0.5',         // Single IP
    '::1',              // IPv6 localhost
]);
```

Requests from unlisted IPs return `403 Forbidden`.

---

## Endpoints

All endpoints accept `POST` with a JSON body and return JSON.

### `POST /print`

Print a single line of text.

| Field | Type | Default | Description |
|-------|------|---------|-------------|
| `text` | string | **required** | Text to print |
| `align` | string | `left` | `left`, `center`, `right` |
| `size` | string | `1,1` | `width,height` — each 1–8 |
| `bold` | bool | `false` | Bold text |
| `cut` | int | `0` | `0` = no cut, `1` = full cut, `2` = partial cut |

Text is automatically word-wrapped to the printer's line width — long lines
break between words and explicit newlines are preserved as paragraph breaks.
Inline `<b>...</b>` and `<u>...</u>` tags toggle bold and underline within
the line, and combine with the `bold` flag:

```powershell
$body = '{ "text": "Subtotal <b>$9.99</b> — <u>paid</u>", "align": "right", "cut": 1 }'

curl.exe -X POST http://192.168.1.5/thermal_printer_api.php/print `
  -H "Authorization: Bearer your-secret-token" `
  -H "Content-Type: application/json" `
  -d $body
```

```json
{ "ok": true, "action": "print" }
```

---

### `POST /qr`

Print a QR code.

| Field | Type | Default | Description |
|-------|------|---------|-------------|
| `data` | string | **required** | URL or text to encode |
| `align` | string | `center` | `left`, `center`, `right` |
| `size` | int | `6` | Cell size 1–16 |
| `cut` | int | `0` | `0` = no cut, `1` = full cut, `2` = partial cut |

```powershell
$body = '{ "data": "https://example.com", "size": 8, "cut": 1 }'

curl.exe -X POST http://192.168.1.5/thermal_printer_api.php/qr `
  -H "Authorization: Bearer your-secret-token" `
  -H "Content-Type: application/json" `
  -d $body
```

```json
{ "ok": true, "action": "qr" }
```

---

### `POST /barcode`

Print a barcode.

| Field | Type | Default | Description |
|-------|------|---------|-------------|
| `data` | string | **required** | Barcode data |
| `barcode_type` | string | `EAN13` | See supported types below |
| `hri` | string | `below` | Human-readable text: `none`, `above`, `below`, `both` |
| `height` | int | `64` | Bar height in dots (1–255) |
| `cut` | int | `0` | `0` = no cut, `1` = full cut, `2` = partial cut |

**Supported barcode types:** `UPCA`, `UPCE`, `EAN13`, `EAN8`, `CODE39`, `ITF`, `CODABAR`, `CODE93`, `CODE128`

```powershell
$body = '{ "data": "123456789012", "barcode_type": "EAN13", "hri": "below", "height": 80, "cut": 1 }'

curl.exe -X POST http://192.168.1.5/thermal_printer_api.php/barcode `
  -H "Authorization: Bearer your-secret-token" `
  -H "Content-Type: application/json" `
  -d $body
```

```json
{ "ok": true, "action": "barcode" }
```

---

### `POST /batch`

Send multiple commands in one request. This is the recommended way to print
receipts — everything is buffered and sent in a single TCP write.

The command type is inferred from the key:
- `"text"` → print text
- `"qr"` → QR code
- `"barcode"` → barcode

Each command accepts the same fields as its individual endpoint. Text commands
are word-wrapped and honor inline `<b>...</b>` and `<u>...</u>` tags exactly as
described under `/print`.

```powershell
$body = @'
{
  "commands": [
    { "text": "ACME STORE",          "align": "center", "size": "2,2", "bold": true },
    { "text": "123 Main St",         "align": "center" },
    { "text": "──────────────────────────────────────────" },
    { "text": "1x Widget Pro",       "align": "left" },
    { "text": "$9.99",               "align": "right" },
    { "text": "──────────────────────────────────────────" },
    { "text": "TOTAL: $9.99",        "align": "right", "bold": true },
    { "text": "" },
    { "qr": "https://acme.com/receipt/12345", "size": 6 },
    { "text": "Thank you!",          "align": "center", "cut": 2 }
  ]
}
'@

curl.exe -X POST http://192.168.1.5/thermal_printer_api.php/batch `
  -H "Authorization: Bearer your-secret-token" `
  -H "Content-Type: application/json" `
  -d $body
```

```json
{ "ok": true, "action": "batch", "count": 10 }
```

---

### `POST /status`

Check printer status (paper, cover, errors). Body can be empty `{}`.

```powershell
curl.exe -X POST http://192.168.1.5/thermal_printer_api.php/status `
  -H "Authorization: Bearer your-secret-token" `
  -H "Content-Type: application/json" `
  -d '{}'
```

```json
{ "ok": true, "ready": true }
```

---

## Text Encoding
 
The API uses PC437 encoding internally. The following characters are mapped automatically — no special handling needed in your JSON:
 
| Category | Characters |
|----------|-----------|
| Swedish | Ä Å Ö ä å ö |
| French | é à ç ê ë è ï î ì ü û ù ÿ ô ò ñ Ñ ß |
| Ligatures | æ Æ œ→oe Œ→OE |
| Math | ° ½ ¼ ² ± ÷ ≈ √ |
| Currency | £ ¥ ¢ € |
| Dashes | – — → - |
| Symbols | · ■ █ ▀ ▄ |
| Lines | ─ (single) ═ (double) |
 
Characters outside this map that are not plain ASCII are replaced with `?`.
 
The line and block characters are useful for dividers and headers:
 
```
─────────────────────────────────   single rule
═════════════════════════════════   double rule
█████████████████████████████████   solid block (thickest)
```
 
---

## Error Responses

| Status | Meaning |
|--------|---------|
| `400` | Bad request — missing required field or invalid JSON |
| `401` | Missing or invalid Bearer token |
| `403` | Client IP not in allowlist |
| `404` | Unknown endpoint |
| `405` | Method not allowed (only POST is accepted) |
| `502` | Could not connect to printer, or printer reported an error |
| `500` | Internal server error |

Error responses always include a JSON body:

```json
{ "error": "Printer error: Out of paper" }
```

---

## Example Print — Daily Home Automation Report

A complete daily report with temperatures, energy usage, and system status:

```powershell
$culture = [System.Globalization.CultureInfo]::new("sv-SE")
$date = (Get-Date).ToString("dddd d MMMM yyyy", $culture)
$date = $culture.TextInfo.ToTitleCase($date)
$time = Get-Date -Format "HH:mm:ss"

$body = @"
{
  "commands": [
    { "text": "══════════════════════════════════════════" },
    { "text": "DAGLIG RAPPORT", "align": "center", "size": "2,1", "bold": true },
    { "text": "$date", "align": "center" },
    { "text": "══════════════════════════════════════════" },

    { "text": "" },
    { "text": "TEMPERATURER", "bold": true },
    { "text": "──────────────────────────────────────────" },
    { "text": "Vardagsrum        21.4°C" },
    { "text": "Sovrum            19.8°C" },
    { "text": "Kök               22.1°C" },
    { "text": "Badrum            20.5°C" },
    { "text": "Garage             8.3°C" },
    { "text": "Utomhus           14.7°C" },

    { "text": "" },
    { "text": "ENERGIFÖRBRUKNING", "bold": true },
    { "text": "──────────────────────────────────────────" },
    { "text": "Idag hittills      4.2 kWh" },
    { "text": "Igår              11.8 kWh" },
    { "text": "Denna månad      187.4 kWh" },
    { "text": "Solpaneler idag    2.1 kWh" },
    { "text": "Nettoanvändning    2.1 kWh" },

    { "text": "" },
    { "text": "SYSTEMSTATUS", "bold": true },
    { "text": "──────────────────────────────────────────" },
    { "text": "Larm              FRÅNKOPPLAT" },
    { "text": "Ytterdörr         LÅST" },
    { "text": "Garagedörr        STÄNGD" },
    { "text": "Tvättmaskin       KLAR" },
    { "text": "Diskmaskinen      KÖR  40 min" },

    { "text": "" },
    { "text": "VÄDER IDAG", "bold": true },
    { "text": "──────────────────────────────────────────" },
    { "text": "Delvis molnigt, 15-18°C" },
    { "text": "Vind: SV 4 m/s" },
    { "text": "Soluppgång: 04:18  Ned: 22:04" },

    { "text": "" },
    { "text": "══════════════════════════════════════════" },
    { "text": "Utskriven: $time", "align": "center" },
    { "text": "══════════════════════════════════════════", "cut": 2 }
  ]
}
"@

curl.exe -X POST http://192.168.1.5/thermal_printer_api.php/batch `
  -H "Authorization: Bearer your-secret-token" `
  -H "Content-Type: application/json" `
  -d $body
```
