<?php
declare(strict_types=1);

session_start();
$config = require '/srv/harness-ui/bootstrap.php';

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
}

$envs = $config['rest_envs'] ?? [];
$restDir = (string)($config['rest_harness_dir'] ?? '');
$scDir = $restDir !== '' ? ($restDir . '/scenarios') : '';
$scenarios = [];

if (is_dir($scDir)) {
    foreach (glob($scDir . '/*') ?: [] as $p) {
        if (is_file($p)) {
            $scenarios[] = basename($p);
        }
    }
    sort($scenarios);
}

// load cred profiles (same as index.php)
$profiles = [];
$credsDir = $config['creds_dir'] ?? null;
if (is_string($credsDir) && is_dir($credsDir)) {
    foreach (glob($credsDir . '/*.php') ?: [] as $file) {
        $id = basename($file, '.php');
        $data = require $file;
        if (is_array($data) && isset($data['username'], $data['password'])) {
            $profiles[$id] = [
                'label' => (string)($data['label'] ?? $id),
                'username' => (string)$data['username'],
                'password' => (string)$data['password'],
            ];
        }
    }
    ksort($profiles);
}
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>Harness UI - REST</title>
  <style>
    body { font-family: sans-serif; margin: 24px; max-width: 900px; }
    label { display:block; margin-top: 12px; }
    input, select { width: 100%; padding: 8px; }
    button { margin-top: 16px; padding: 10px 14px; }
    .row { display:flex; gap: 12px; }
    .row > div { flex: 1; }
    .nav { margin-bottom: 14px; }
    .hint { color:#555; font-size: 0.95em; }
  </style>
</head>
<body>
  <h2>REST Harness</h2>

  <div class="nav">
    <a href="/">WS runner</a> · <a href="/runs.php">Past runs</a>
  </div>

  <form method="post" action="/run_rest.php" autocomplete="off">
    <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf'], ENT_QUOTES) ?>">

    <label>Environment</label>
    <select name="env" required>
      <?php foreach ($envs as $k => $url): ?>
        <option value="<?= htmlspecialchars($k, ENT_QUOTES) ?>"><?= htmlspecialchars($k) ?> (<?= htmlspecialchars($url) ?>)</option>
      <?php endforeach; ?>
    </select>

    <label>Scenario file</label>
    <select name="scenario" required>
      <?php foreach ($scenarios as $s): ?>
        <option value="<?= htmlspecialchars($s, ENT_QUOTES) ?>"><?= htmlspecialchars($s) ?></option>
      <?php endforeach; ?>
    </select>

    <label>Credential profile</label>
    <select id="profile" name="profile">
      <option value="">(manual)</option>
      <?php foreach ($profiles as $id => $p): ?>
        <option
          value="<?= htmlspecialchars($id, ENT_QUOTES) ?>"
          data-u="<?= htmlspecialchars($p['username'], ENT_QUOTES) ?>"
          data-p="<?= htmlspecialchars($p['password'], ENT_QUOTES) ?>"
        ><?= htmlspecialchars($p['label']) ?></option>
      <?php endforeach; ?>
    </select>

    <div class="row">
      <div>
        <label>Username</label>
        <input id="username" name="username" required>
      </div>
      <div>
        <label>Password</label>
        <input id="password" name="password" type="password" required>
      </div>
    </div>

    <div class="hint">
      This runs <code>bin/run_rest_tests.php</code> for the selected scenario.
    </div>

    <button type="submit">Run REST Test</button>
  </form>

  <script>
    (function () {
      const sel = document.getElementById('profile');
      const u = document.getElementById('username');
      const p = document.getElementById('password');

      sel.addEventListener('change', () => {
        const opt = sel.options[sel.selectedIndex];
        const user = opt.getAttribute('data-u') || '';
        const pass = opt.getAttribute('data-p') || '';
        if (user) u.value = user;
        if (pass) p.value = pass;
      });
    })();
  </script>
</body>
</html>
