<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

$host = 'portal.shieldconex.com';

// Resolve DNS records (CNAME + A). We’ll infer location from the CNAME target if present.
$records = dns_get_record($host, DNS_CNAME | DNS_A);

$cname = null;
$ip = null;

foreach ($records as $r) {
    if (($r['type'] ?? '') === 'CNAME' && !empty($r['target'])) {
        $cname = rtrim((string)$r['target'], '.') . '.';
        break;
    }
}

foreach ($records as $r) {
    if (($r['type'] ?? '') === 'A' && !empty($r['ip'])) {
        $ip = (string)$r['ip'];
        break;
    }
}

// If we didn’t get an A from the combined query, ask specifically.
if ($ip === null) {
    $a = dns_get_record($host, DNS_A);
    if (!empty($a[0]['ip'])) {
        $ip = (string)$a[0]['ip'];
    }
}

$backend = $cname ?? ($ip ?? $host);

// Determine location from backend string
$blow = strtolower($backend);
if (str_contains($blow, 'portal-tul') || str_contains($blow, '.tul.') || str_contains($blow, '-tul.')) {
    $location = 'Tulsa';
} elseif (str_contains($blow, 'portal-atl') || str_contains($blow, '.atl.') || str_contains($blow, '-atl.')) {
    $location = 'Atlanta';
} else {
    $location = 'Unknown';
}

echo json_encode([
    'host'      => $host,
    'backend'   => $cname,     // keep this explicit (null if none)
    'ip'        => $ip,        // null if none
    'location'  => $location,
    'timestamp' => (new DateTimeImmutable('now'))->format(DateTimeInterface::ATOM),
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
