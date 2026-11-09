<?php
/**
 * EPS checkout HAR analyzer.
 *
 * Takes a browser HAR export and reports the first failing request in an EPS
 * checkout flow, with all session identifiers redacted.
 *
 * Usage:
 *   php tests/tools/analyze_eps_har.php path/to/capture.har
 *
 * Verdicts:
 *   PIPRAPAY  - the payment page document itself produced the data=null URL
 *               (only possible with stale/old application code)
 *   EPS       - an EPS response (30x/Location) or EPS JavaScript sent the
 *               browser to data=null after a non-null URL was requested
 *   NO_NULL   - no data=null navigation found in the capture
 *
 * The file is read locally only; nothing is uploaded.
 */

declare(strict_types=1);

$path = $argv[1] ?? '';
if ($path === '' || !is_file($path)) {
    fwrite(STDERR, "Usage: php tests/tools/analyze_eps_har.php <capture.har>\n");
    exit(1);
}

$raw = file_get_contents($path);
if ($raw === false) {
    fwrite(STDERR, "Cannot read {$path}\n");
    exit(1);
}

$har = json_decode($raw, true);
if (!is_array($har) || !isset($har['log']['entries']) || !is_array($har['log']['entries'])) {
    fwrite(STDERR, "Not a valid HAR file\n");
    exit(1);
}

/**
 * Redact anything that looks like a session token, payment id or credential.
 */
function redact_url(string $url): string
{
    $parts = parse_url($url);
    if ($parts === false) {
        return preg_replace('/[A-Za-z0-9_\-+\/=]{12,}/', '<redacted>', $url);
    }

    $rebuild = ($parts['scheme'] ?? 'http') . '://' . ($parts['host'] ?? '');
    if (!empty($parts['port'])) {
        $rebuild .= ':' . $parts['port'];
    }
    $rebuild .= $parts['path'] ?? '';

    if (!empty($parts['query'])) {
        $pairs = [];
        foreach (explode('&', $parts['query']) as $pair) {
            if ($pair === '') {
                continue;
            }
            $kv = explode('=', $pair, 2);
            $key = urldecode($kv[0]);
            $value = isset($kv[1]) ? urldecode($kv[1]) : '';
            $sensitive = in_array(strtolower($key), ['data', 'epstransactionid', 'merchanttransactionid', 'token', 'ref'], true);
            if ($sensitive) {
                $pairs[] = $key . '=<redacted:' . strlen($value) . '>';
            } else {
                $pairs[] = $key . '=' . $value;
            }
        }
        $rebuild .= '?' . implode('&', $pairs);
    }

    return $rebuild;
}

function host_of(string $url): string
{
    $h = parse_url($url, PHP_URL_HOST);
    return is_string($h) ? strtolower($h) : '';
}

function header_value(array $headers, string $name): string
{
    foreach ($headers as $header) {
        if (!isset($header['name'], $header['value'])) {
            continue;
        }
        if (strcasecmp((string) $header['name'], $name) === 0) {
            return (string) $header['value'];
        }
    }
    return '';
}

$entries = [];
$index = 0;
foreach ($har['log']['entries'] as $entry) {
    $request = $entry['request'] ?? [];
    $response = $entry['response'] ?? [];
    $url = (string) ($request['url'] ?? '');
    if ($url === '') {
        continue;
    }

    $entries[] = [
        'n'          => ++$index,
        'started'    => (string) ($entry['startedDateTime'] ?? ''),
        'method'     => (string) ($request['method'] ?? ''),
        'url'        => $url,
        'host'       => host_of($url),
        'status'     => (int) ($response['status'] ?? 0),
        'location'   => header_value($response['headers'] ?? [], 'location'),
        'type'       => (string) ($response['content']['mimeType'] ?? ''),
        'time_ms'    => (float) ($entry['time'] ?? 0),
        'initiator'  => (string) ($entry['_initiator']['type'] ?? ''),
    ];
}

if (empty($entries)) {
    fwrite(STDERR, "HAR contains no requests\n");
    exit(1);
}

echo "EPS checkout HAR analysis\n";
echo "=========================\n";
echo 'requests: ' . count($entries) . "\n\n";

$isNullUrl = static function (string $url): bool {
    if (!preg_match('/(?:\?|&)data=([^&]*)/i', $url, $m)) {
        return false;
    }
    $value = strtolower(urldecode($m[1]));
    return $value === '' || in_array($value, ['null', 'undefined', 'none', 'nil', 'false', '0'], true);
};

$firstNull = null;
foreach ($entries as $pos => $entry) {
    if ($isNullUrl($entry['url'])) {
        $firstNull = $pos;
        break;
    }
}

echo "Sequence (EPS-facing and document requests):\n";
$shown = 0;
foreach ($entries as $pos => $entry) {
    $isEps = str_ends_with($entry['host'], 'eps.com.bd');
    $isDocument = in_array($entry['type'], ['text/html'], true) || $entry['initiator'] === 'parser';
    if (!$isEps && !$isDocument) {
        continue;
    }

    echo sprintf(
        "  #%d %-6s %-3d %6.0fms init=%-8s %s\n",
        $entry['n'],
        $entry['method'],
        $entry['status'],
        $entry['time_ms'],
        $entry['initiator'] !== '' ? $entry['initiator'] : '-',
        redact_url($entry['url'])
    );

    if ($entry['location'] !== '') {
        echo '        → Location: ' . redact_url($entry['location']) . "\n";
    }

    if (++$shown >= 25) {
        echo "  …\n";
        break;
    }
}

$slowest = $entries[0];
foreach ($entries as $entry) {
    if ($entry['time_ms'] > $slowest['time_ms']) {
        $slowest = $entry;
    }
}

echo "\nSlowest request: #{$slowest['n']} " . round($slowest['time_ms']) . "ms "
    . redact_url($slowest['url']) . "\n";

$serverErrors = array_values(array_filter($entries, static fn ($e) => $e['status'] >= 500));
if (!empty($serverErrors)) {
    echo "Server errors (5xx): " . count($serverErrors) . "\n";
    foreach (array_slice($serverErrors, 0, 5) as $entry) {
        echo "  #{$entry['n']} {$entry['status']} " . redact_url($entry['url']) . "\n";
    }
} else {
    echo "Server errors (5xx): none\n";
}

echo "\n";

if ($firstNull === null) {
    echo "VERDICT: NO_NULL — this capture does not contain a data=null navigation.\n";
    exit(0);
}

$nullEntry = $entries[$firstNull];
$previous = $firstNull > 0 ? $entries[$firstNull - 1] : null;

echo "First data=null navigation: #{$nullEntry['n']} "
    . ($nullEntry['initiator'] !== '' ? "initiator={$nullEntry['initiator']} " : '')
    . "status={$nullEntry['status']}\n";

if ($previous === null) {
    echo "VERDICT: INCONCLUSIVE — data=null is the first request in the capture.\n";
    exit(0);
}

echo 'Previous request: #' . $previous['n'] . " {$previous['method']} {$previous['status']} "
    . redact_url($previous['url']) . "\n";

$prevIsEps = str_ends_with($previous['host'], 'eps.com.bd');
$prevRedirectsToNull = $previous['location'] !== '' && $isNullUrl($previous['location']);
$initiatorIsScript = in_array($nullEntry['initiator'], ['script', 'xhr', 'fetch'], true);

if (!$prevIsEps) {
    echo "VERDICT: PIPRAPAY — the browser navigated to data=null directly from a PipraPay-served document.\n";
    echo "         Commit 1e52b66 rejects data=null before redirect, so the deployed code is not the\n";
    echo "         reviewed build (stale file or stale OPcache).\n";
    exit(2);
}

if ($prevRedirectsToNull) {
    echo "VERDICT: EPS — the provider answered with a redirect to data=null for the requested URL.\n";
    echo "         PipraPay handed over a non-null URL; the provider invalidated the session.\n";
    exit(0);
}

if ($initiatorIsScript) {
    echo "VERDICT: EPS — provider-side JavaScript/XHR navigated the browser to data=null.\n";
    exit(0);
}

echo "VERDICT: EPS — the data=null request originates from the provider host.\n";
exit(0);
