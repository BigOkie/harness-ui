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

/**
 * Resolve an env selection into a URL.
 *
 * Supported config formats:
 *  1) ['staging' => 'wss://...', 'prod' => 'wss://...']
 *  2) [
 *       'staging' => ['Pool' => 'wss://...', 'Atlanta' => 'wss://...'],
 *       'prod'    => ['Pool' => 'wss://...']
 *     ]
 *
 * Supported UI values:
 *  - "staging" (string env key)
 *  - "staging::Pool" (env + location)
 */
function harnessui_resolve_env_url(array $envs, string $selected): ?string
{
    $selected = trim($selected);
    if ($selected === '') return null;

    // Exact match for simple string envs (and also allows "cert" etc)
    if (array_key_exists($selected, $envs) && is_string($envs[$selected]) && $envs[$selected] !== '') {
        return $envs[$selected];
    }

    // Nested "env::Location"
    if (str_contains($selected, '::')) {
        [$envKey, $locLabel] = explode('::', $selected, 2);
        $envKey = trim($envKey);
        $locLabel = trim($locLabel);

        if ($envKey !== '' && $locLabel !== '' && isset($envs[$envKey]) && is_array($envs[$envKey])) {
            $v = $envs[$envKey];
            if (array_key_exists($locLabel, $v) && is_string($v[$locLabel]) && $v[$locLabel] !== '') {
                return $v[$locLabel];
            }
        }
    }

    return null;
}

if (($_POST['csrf'] ?? '') !== ($_SESSION['csrf'] ?? '')) {
    fail(403, 'CSRF failed');
}

$env  = (string)($_POST['env'] ?? '');
$user = trim((string)($_POST['username'] ?? ''));
$pass = (string)($_POST['password'] ?? '');

if ($env === '') {
    fail(400, 'Missing env');
}
if ($user === '' || $pass === '') {
    fail(400, 'Missing username/password');
}

// Resolve URL from config (supports new multi-location format)
$envs = $config['envs'] ?? [];
if (!is_array($envs)) {
    fail(500, 'Config envs is not an array');
}

$url = harnessui_resolve_env_url($envs, $env);
if ($url === null) {
    fail(400, 'Invalid env');
}

// conservative username validation
if (!preg_match('/^[a-zA-Z0-9._-]{1,64}$/', $user)) {
    fail(400, 'Invalid username format');
}

$creds = $user . ':' . $pass;

$harnessDir = (string)($config['ws_harness_dir'] ?? '');
$phpBin     = (string)($config['php_bin'] ?? '/usr/bin/php');
$runsDir    = (string)($config['runs_dir'] ?? '/srv/harness-ui/var/runs');
$maxRun     = (int)($config['max_run_seconds'] ?? 300);

$runner = $harnessDir . '/bin/run_ws_suite.php';
if (!is_dir($harnessDir) || !is_file($runner)) {
    fail(500, "Harness not found: {$runner}");
}

// ensure group-writable artifacts
umask(007);

$runId   = gmdate('Ymd_His') . '_' . bin2hex(random_bytes(3));
$runPath = rtrim($runsDir, '/') . '/' . $runId;

if (!mkdir($runPath, 0770, true) && !is_dir($runPath)) {
    fail(500, "Failed to create run directory: {$runPath}");
}

$artDir = $runPath . '/artifacts';
if (!mkdir($artDir, 0770, true) && !is_dir($artDir)) {
    fail(500, "Failed to create artifacts directory: {$artDir}");
}

$stdoutFile = $runPath . '/stdout.log';
$stderrFile = $runPath . '/stderr.log';
$metaFile   = $runPath . '/meta.json';

// Always force WS outputs into artifacts (absolute paths)
$csvLog    = $artDir . '/ws_suite.csv';
$stateFile = $artDir . '/ws_state.json';

$meta = [
    'runId'        => $runId,
    'startedAtUtc' => gmdate('c'),
    'harness'      => 'ws',
    'env'          => $env,
    'url'          => $url,
    'username'     => $user,
    'cwd'          => $harnessDir,
    'runner'       => $runner,
    'csvLog'       => $csvLog,
    'stateFile'    => $stateFile,
];
file_put_contents($metaFile, json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

$cmd = [
    $phpBin,
    $runner,
    '--url', $url,
    '--creds', $creds,
    '--csv-log', $csvLog,
    '--state-file', $stateFile,
];

$cmdString = implode(' ', array_map('escapeshellarg', $cmd));

// Work around proc_close() returning -1 by capturing $?
$exitFile = $runPath . '/exit_code.txt';
$cmdString .= ' ; echo $? > ' . escapeshellarg($exitFile);

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
echo "<div>CSV: <code>" . htmlspecialchars($csvLog) . "</code></div>";
echo "<div>State: <code>" . htmlspecialchars($stateFile) . "</code></div>";
echo "<div><a href='/runs.php'>Back to runs</a></div>";
echo "<h4>Live output</h4><pre>";

@ob_flush(); @flush();

$start = time();
while (true) {
    $status  = proc_get_status($proc);
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

    if (!$running) break;

    if ((time() - $start) > $maxRun) {
        proc_terminate($proc);
        break;
    }

    usleep(100000);
}

// close + read captured exit code
$closeExit = proc_close($proc);

$exit = $closeExit;
if (is_file($exitFile)) {
    $s = trim((string)@file_get_contents($exitFile));
    if ($s !== '' && preg_match('/^-?\d+$/', $s)) {
        $exit = (int)$s;
    }
}

fclose($outFp);
fclose($errFp);

$meta['finishedAtUtc'] = gmdate('c');
$meta['exitCode']      = $exit;
file_put_contents($metaFile, json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

echo "\n</pre>";
echo "<h4>Result</h4>";
echo "<div>Exit code: <code>" . htmlspecialchars((string)$exit) . "</code></div>";
echo "<div><a href='/view.php?id=" . urlencode($runId) . "'>View run details</a></div>";
echo "</body></html>";
