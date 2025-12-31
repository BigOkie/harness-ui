<?php
declare(strict_types=1);

session_start();

$config = require '/srv/harness-ui/bootstrap.php';

function fail(int $code, string $msg): never {
    http_response_code($code);
    header('Content-Type: text/plain; charset=utf-8');
    echo $msg . "\n";
    exit;
}

if (($_POST['csrf'] ?? '') !== ($_SESSION['csrf'] ?? '')) {
    fail(403, 'CSRF failed');
}

$env = (string)($_POST['env'] ?? '');
$user = trim((string)($_POST['username'] ?? ''));
$pass = (string)($_POST['password'] ?? '');

if ($user === '' || $pass === '') {
    fail(400, 'Missing username/password');
}

if (!isset($config['envs'][$env])) {
    fail(400, 'Invalid env');
}

$url = $config['envs'][$env];

// Very conservative username validation (tune if needed)
if (!preg_match('/^[a-zA-Z0-9._-]{1,64}$/', $user)) {
    fail(400, 'Invalid username format');
}

// Avoid logging password anywhere; keep it out of meta.
$creds = $user . ':' . $pass;

$harnessDir = $config['ws_harness_dir'];
$phpBin = $config['php_bin'];
$runsDir = $config['runs_dir'];
$maxRun = (int)$config['max_run_seconds'];

$runner = $harnessDir . '/bin/run_ws_suite.php';
if (!is_dir($harnessDir) || !is_file($runner)) {
    fail(500, "Harness not found: {$runner}");
}

umask(007);

$runId = gmdate('Ymd_His') . '_' . bin2hex(random_bytes(3));
$runPath = $runsDir . '/' . $runId;

if (!mkdir($runPath, 0770, true) && !is_dir($runPath)) {
    fail(500, 'Failed to create run directory');
}

$stdoutFile = $runPath . '/stdout.log';
$stderrFile = $runPath . '/stderr.log';
$metaFile   = $runPath . '/meta.json';

$meta = [
    'runId' => $runId,
    'startedAtUtc' => gmdate('c'),
    'env' => $env,
    'url' => $url,
    'username' => $user,
    'cwd' => $harnessDir,
    'runner' => $runner,
];
file_put_contents($metaFile, json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

$cmd = [
    $phpBin,
    $runner,
    '--url', $url,
    '--creds', $creds,
];

// proc_open requires a string on some systems for PATH handling; we avoid shell expansion.
$cmdString = implode(' ', array_map('escapeshellarg', $cmd));

$descriptors = [
    0 => ['pipe', 'r'],
    1 => ['pipe', 'w'],
    2 => ['pipe', 'w'],
];

$proc = proc_open($cmdString, $descriptors, $pipes, $harnessDir);
if (!is_resource($proc)) {
    fail(500, 'Failed to start process');
}

fclose($pipes[0]);
stream_set_blocking($pipes[1], false);
stream_set_blocking($pipes[2], false);

$outFp = fopen($stdoutFile, 'ab');
$errFp = fopen($stderrFile, 'ab');

header('Content-Type: text/html; charset=utf-8');
echo "<!doctype html><html><head><meta charset='utf-8'><title>Run {$runId}</title>
<style>body{font-family:sans-serif;margin:20px} pre{background:#111;color:#eee;padding:12px;white-space:pre-wrap}</style>
</head><body>";
echo "<h3>Running WS Suite</h3>";
echo "<div>Run: <code>" . htmlspecialchars($runId) . "</code></div>";
echo "<div>Env: <code>" . htmlspecialchars($env) . "</code> URL: <code>" . htmlspecialchars($url) . "</code></div>";
echo "<div><a href='/runs.php'>Back to runs</a></div>";
echo "<h4>Live output</h4><pre>";

@ob_flush(); @flush();

$start = time();
$buffers = ['out' => '', 'err' => ''];

while (true) {
    $status = proc_get_status($proc);
    $running = $status['running'];

    $o = stream_get_contents($pipes[1]);
    if ($o !== false && $o !== '') {
        fwrite($outFp, $o);
        echo htmlspecialchars($o, ENT_NOQUOTES);
        @ob_flush(); @flush();
    }

    $e = stream_get_contents($pipes[2]);
    if ($e !== false && $e !== '') {
        fwrite($errFp, $e);
        echo htmlspecialchars($e, ENT_NOQUOTES);
        @ob_flush(); @flush();
    }

    if (!$running) {
        break;
    }

    if ((time() - $start) > $maxRun) {
        // Best-effort terminate
        proc_terminate($proc);
        break;
    }

    usleep(100000); // 100ms
}

$exit = proc_close($proc);

fclose($outFp);
fclose($errFp);

$meta['finishedAtUtc'] = gmdate('c');
$meta['exitCode'] = $exit;
file_put_contents($metaFile, json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

echo "\n</pre>";
echo "<h4>Result</h4>";
echo "<div>Exit code: <code>" . htmlspecialchars((string)$exit) . "</code></div>";
echo "<div><a href='/view.php?id=" . urlencode($runId) . "'>View run details</a></div>";
echo "</body></html>";
