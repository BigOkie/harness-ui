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

/**
 * Resolve an env selection into a URL.
 * Supports both simple and nested config arrays, and "env::Location" values.
 */
function harnessui_resolve_env_url(array $envs, string $selected): ?string
{
    $selected = trim($selected);
    if ($selected === '') return null;

    if (array_key_exists($selected, $envs) && is_string($envs[$selected]) && $envs[$selected] !== '') {
        return $envs[$selected];
    }

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

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    failHtml(405, 'Method not allowed', 'This endpoint expects a POST from the REST runner form.');
}

if (($_POST['csrf'] ?? '') !== ($_SESSION['csrf'] ?? '')) {
    fail(403, 'CSRF failed');
}

$env      = (string)($_POST['env'] ?? '');
$scenario = $_POST['scenario'] ?? '';

if (
    !is_string($scenario) ||
    !preg_match('/\Arest_scenarios_[a-zA-Z0-9_-]+\.json\z/', $scenario)
) {
    fail(400, 'Invalid scenario filename');
}

$authMode = strtolower(trim((string)($_POST['auth'] ?? 'basic')));
if (!in_array($authMode, ['basic', 'hmac'], true)) {
    fail(400, 'Invalid authentication mode');
}

// Manual authentication fields from rest.php
$basicUser = trim((string)($_POST['basic_user'] ?? ''));
$basicPass = (string)($_POST['basic_pass'] ?? '');

$hmacUser   = trim((string)($_POST['hmac_user'] ?? ''));
$hmacSecret = (string)($_POST['hmac_secret'] ?? '');
if ($env === '' || $scenario === '') {
    fail(400, 'Missing required fields');
}

// REST envs can now be nested (Pool/Atlanta/Tulsa)
$restEnvs = $config['rest_envs'] ?? [];
if (!is_array($restEnvs)) {
    fail(500, 'Config rest_envs is not an array');
}
$baseUrl = harnessui_resolve_env_url($restEnvs, $env);
if ($baseUrl === null) {
    fail(400, 'Invalid env');
}
// Extract the environment name for the backend CLI.
$envKey = trim(explode('::', $env, 2)[0]);
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

if (!is_file($runner)) {
    fail(500, "Runner not found: {$runner}");
}

// Resolve and validate the scenario directory.
$scenarioDir = realpath($restDir . '/scenarios');

if ($scenarioDir === false || !is_dir($scenarioDir)) {
    fail(500, 'Scenario directory not found');
}

// Resolve the requested scenario file.
$scenarioPath = realpath($scenarioDir . '/' . $scenario);

if (
    $scenarioPath === false ||
    !is_file($scenarioPath) ||
    !is_readable($scenarioPath) ||
    dirname($scenarioPath) !== $scenarioDir
) {
    fail(400, 'Invalid or inaccessible scenario file');
}

umask(007);

// Run folder
$runId   = gmdate('Ymd_His') . '_' . bin2hex(random_bytes(3));
$runPath = rtrim($runsDir, '/') . '/' . $runId;

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
    'runId'        => $runId,
    'startedAtUtc' => gmdate('c'),
    'harness'      => 'rest',
    'env'          => $env,
    'url'          => $baseUrl,
    'scenario'     => $scenario,
    'authMode'     => $authMode,
    'cwd'          => $restDir,
    'runner'       => $runner,
    'csvLog'       => $csvPath,
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
    "--env={$envKey}",
    "--base-url={$baseUrl}",
    "--auth={$authMode}",
    "--scenario=scenarios/{$scenario}",
    "--csv-log={$csvPath}",
    "--state-file=" . dirname($csvPath) . "/state.json",
];
if ($authMode === 'basic') {
    $cmd[] = "--basic-user={$basicUser}";
    $cmd[] = "--basic-password={$basicPass}";
} else {
    $cmd[] = "--hmac-user={$hmacUser}";
    $cmd[] = "--hmac-secret={$hmacSecret}";
}

$cmdString = implode(' ', array_map('escapeshellarg', $cmd));

// Work around proc_close() returning -1 by capturing $?
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
echo "<div>Env: <code>" . htmlspecialchars($env) . "</code> URL: <code>" . htmlspecialchars($baseUrl) . "</code></div>";
echo "<div>Scenario: <code>" . htmlspecialchars($scenario) . "</code></div>";
echo "<div>Auth: <code>" . htmlspecialchars($authMode) . "</code></div>";
echo "<div>CSV: <code>" . htmlspecialchars($csvPath) . "</code></div>";
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

    if (!$running) break;

    if ((time() - $start) > $maxRun) {
        proc_terminate($proc);
        break;
    }

    usleep(100000);
}

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
