<?php
declare(strict_types=1);

$config = require '/srv/harness-ui/bootstrap.php';
$runsDir = (string)($config['runs_dir'] ?? '/srv/harness-ui/var/runs');

$run = basename((string)($_GET['run'] ?? ''));
$file = basename((string)($_GET['file'] ?? ''));

if ($run === '' || $file === '') {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Missing run or file\n";
    exit;
}

// keep consistent with your run id format
if (!preg_match('/^[0-9]{8}_[0-9]{6}_[a-fA-F0-9]{6,32}$/', $run)) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Invalid run id\n";
    exit;
}

// very conservative file name allowlist
if (!preg_match('/^[a-zA-Z0-9._-]{1,200}$/', $file)) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Invalid file name\n";
    exit;
}

$path = $runsDir . '/' . $run . '/artifacts/' . $file;

if (!is_file($path) || !is_readable($path)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Not found\n";
    exit;
}

$size = filesize($path);
$mime = 'application/octet-stream';

// basic mime hints
$ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
if ($ext === 'csv') $mime = 'text/csv';
if ($ext === 'json') $mime = 'application/json';
if ($ext === 'txt' || $ext === 'log') $mime = 'text/plain';

header('Content-Type: ' . $mime);
header('Content-Length: ' . (string)$size);
header('Content-Disposition: attachment; filename="' . $file . '"');
header('X-Content-Type-Options: nosniff');

readfile($path);
