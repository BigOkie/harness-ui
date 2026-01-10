<?php

declare(strict_types=1);

session_start();
$config = require '/srv/harness-ui/bootstrap.php';

function fail(int $code, string $msg): never
{
    http_response_code($code);
    header('Content-Type: text/plain; charset=utf-8');
    echo $msg . "\n";
    exit;
}

function failHtml(int $code, string $title, string $msg): never
{
    http_response_code($code);
    header('Content-Type: text/html; charset=utf-8');
    echo "<!doctype html><html><head><meta charset='utf-8'><title>"
        . htmlspecialchars($title)
        . "</title></head><body style='font-family:sans-serif;margin:24px'>"
        . "<h3>" . htmlspecialchars($title) . "</h3>"
        . "<p>" . htmlspecialchars($msg) . "</p>"
        . "<p><a href='/rest.php'>Back to REST runner</a></p>"
        . "</body></html>";
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    failHtml(405, 'Method not allowed', 'This endpoint expects a POST from the REST runner form.');
}

if (($_POST['csrf'] ?? '') !== ($_SESSION['csrf'] ?? '')) {
    fail(403, 'CSRF failed');
}

$env      = (string)($_POST['env'] ?? '');
$scenario = basename((string)($_POST['scenario'] ?? ''));
$profile  = (string)($_POST['profile'] ?? '');

$authMode = strtolower(trim((string)($_POST['auth_mode'] ?? 'basic')));
if (!in_array($authMode, ['basic', 'hmac'], true)) {
    $authMode = 'basic';
}

// Manual fields (we keep both sets; which set is required depends on authMode)
$basicUser = trim((string)($_POST['basic_username'] ?? ''));
$basicPass = (string)($_POST['basic_password'] ?? '');

$hmacUser  = trim((string)($_POST['hmac_username'] ?? ''));
$hmacSecret = (string)($_POST['hmac_secret'] ?? '');

// Optional: load profile if selected (populate whichever keys exist)
if ($profile !== '') {
    $credsDir    = (string)($config['creds_dir'] ?? '');
    $profileFile = $credsDir . '/' . basename($profile) . '.php';
    if (is_file($profileFile)) {
        $p = require $profileFile;
        if (is_array($p)) {
            if (isset($p['username'], $p['password'])) {
                $basicUser = (string)$p['username'];
                $basicPass = (string)$p['password'];
            }
            if (isset($p['hmac_username'], $p['hmac_secret'])) {
                $hmacUser   = (string)$p['hmac_username'];
                $hmacSecret = (string)$p['hmac_secret']; // pass-through as-is (no decoding)
            }
        }
    }
}

$restEnvs = $config['rest_envs'] ?? null;
if (!is_array($restEnvs) || !isset($restEnvs[$env])) {
    fail(400, 'Invalid env');
}
if ($scenario === '') {
    fail(400, 'Missing scenario');
}

if ($authMode === 'basic') {
    if ($basicUser === '' || $basicPass === '') {
        fail(400, 'Missing basic username/password');
    }
} else {
    if ($hmacUser === '' || $hmacSecret === '') {
        fail(400, 'Missing HMAC username/secret');
    }
}

// Paths
$restDir = (string)($config['rest_harness_dir'] ?? '');
$phpBin  = (string)($config['php_bin'] ?? '/usr/bin/php');
$runsDir = (string)($config['runs_dir'] ?? '/srv/harness-ui/var/runs');
$maxRun  = (int)($config['max_run_seconds'] ?? 300);

$runner = $restDir . '/bin/run_rest_tests.php';
$scenarioPath = $restDir . '/scenarios/' . $scenario;

if (!is_file($runner)) {
    fail(500, "Runner not found: {$runner}");
}
if (!is_file($scenarioPath)) {
    fail(400, "Scenario not found: {$scenario}");
}

umask(007);

// Run folder
$runId   = gmdate('Ymd_His') . '_' . bin2hex(random_bytes(3));
$runPath = $runsDir . '/' . $runId;

if (!mkdir($runPath, 0770, true) && !is_dir($runPath)) {
    fail(500, 'Failed to create run directory');
}

$artifactsDir = $runPath . '/artifacts';
if (!mkdir($artifactsDir, 0770, true) && !is_dir($artifactsDir)) {
    fail(500, 'Failed to create artifacts directory');
}

$stdoutFile = $runPath . '/stdout.log';
$stderrFile = $runPath . '/stderr.log';
$metaFile   = $runPath . '/meta.json';

// Put CSV in per-run artifacts folder
$csvPath = $artifactsDir . '/rest_results.csv';

$meta = [
    'runId' => $runId,
    'startedAtUtc' => gmdate('c'),
    'harness' => 'rest',
    'env' => $env,
    'url' => (string)($restEnvs[$env] ?? ''),
    'scenario' => $scenario,
    'authMode' => $authMode,
    'cwd' => $restDir,
    'runner' => $runner,
    'csvLog' => $csvPath,
];

// Store *only* non-secret identity info
if ($authMode === 'basic') {
    $meta['username'] = $basicUser;
} else {
    $meta['hmacUsername'] = $hmacUser;
}

file_put_contents($metaFile, json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

// Build command matching run_rest_tests.php options
$cmd = [
    $phpBin,
    $runner,
    "--env={$env}",
    "--auth={$authMode}",
    "--scenario=scenarios/{$scenario}",
    "--csv-log={$csvPath}",
];

if ($authMode === 'basic') {
    $cmd[] = "--basic-user={$basicUser}";
    $cmd[] = "--basic-password={$basicPass}";
} else {
    // These option names must match your run_rest_tests.php parser
    $cmd[] = "--hmac-user={$hmacUser}";
    $cmd[] = "--hmac-secret={$hmacSecret}";
}

$cmdString = implode(' ', array_map('escapeshellarg', $cmd));


$exitFile = $runPath . '/exit_code.txt';
$cmdString .= ' ; echo $? > ' . escapeshellarg($exitFile);
$descriptors = [
    0 => ['pipe', 'r'],
    1 => ['pipe', 'w'],
    2 => ['pipe', 'w'],
];

$proc = proc_open($cmdString, $descriptors, $pipes, $restDir);
if (!is_resource($proc)) {
    fail(500, 'Failed to start process');
}

fclose($pipes[0]);
stream_set_blocking($pipes[1], false);
stream_set_blocking($pipes[2], false);

$outFp = fopen($stdoutFile, 'ab');
$errFp = fopen($stderrFile, 'ab');

header('Content-Type: text/html; charset=utf-8');
echo "<!doctype html><html><head><meta charset='utf-8'><title>REST Run {$runId}</title>
<style>body{font-family:sans-serif;margin:20px} pre{background:#111;color:#eee;padding:12px;white-space:pre-wrap}</style>
</head><body>";
echo "<h3>Running REST test</h3>";
echo "<div>Run: <code>" . htmlspecialchars($runId) . "</code></div>";
echo "<div>Env: <code>" . htmlspecialchars($env) . "</code></div>";
echo "<div>Scenario: <code>" . htmlspecialchars($scenario) . "</code></div>";
echo "<div>Auth: <code>" . htmlspecialchars($authMode) . "</code></div>";
echo "<div><a href='/runs.php'>Back to runs</a></div>";
echo "<h4>Live output</h4><pre>";

@ob_flush();
@flush();

$start = time();
while (true) {
    $status = proc_get_status($proc);
    $running = $status['running'];

    $o = stream_get_contents($pipes[1]);
    if ($o !== false && $o !== '') {
        fwrite($outFp, $o);
        echo htmlspecialchars($o, ENT_NOQUOTES);
        @ob_flush();
        @flush();
    }

    $e = stream_get_contents($pipes[2]);
    if ($e !== false && $e !== '') {
        fwrite($errFp, $e);
        echo htmlspecialchars($e, ENT_NOQUOTES);
        @ob_flush();
        @flush();
    }

    if (!$running) {
        break;
    }

    if ((time() - $start) > $maxRun) {
        proc_terminate($proc);
        break;
    }

    usleep(100000);
}

$exit = proc_close($proc);


$exitFromFile = null;
if (is_file($exitFile)) {
    $s = trim((string)@file_get_contents($exitFile));
    if ($s !== '' && preg_match('/^-?\d+$/', $s)) {
        $exitFromFile = (int)$s;
    }
}
if ($exitFromFile !== null) {
    $exit = $exitFromFile;
}
fclose($outFp);
fclose($errFp);

$meta['finishedAtUtc'] = gmdate('c');
$meta['exitCode'] = $exit;
file_put_contents($metaFile, json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

echo "\n</pre>";
echo "<h4>Result</h4>";
echo "<div>Exit code: <code>" . htmlspecialchars((string)$exit) . "</code></div>";
if (is_file($csvPath)) {
    echo "<div>CSV: <code>" . htmlspecialchars($csvPath) . "</code></div>";
}
echo "<div><a href='/view.php?id=" . urlencode($runId) . "'>View run details</a></div>";
echo "</body></html>";
