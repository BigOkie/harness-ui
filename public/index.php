<?php
declare(strict_types=1);

session_start();
$config = require '/srv/harness-ui/bootstrap.php';

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
}

/**
 * Normalize env config into a flat list of options.
 *
 * Supported config formats:
 *   1) ['staging' => 'wss://...', 'prod' => 'wss://...']
 *   2) [
 *        'staging' => ['Pool' => 'wss://...', 'Atlanta' => 'wss://...'],
 *        'prod'    => ['Pool' => 'wss://...']
 *      ]
 */
function harnessui_env_options(array $envs): array
{
    $out = [];
    foreach ($envs as $envKey => $v) {
        if (is_string($v)) {
            $out[] = ['value' => (string)$envKey, 'label' => (string)$envKey, 'url' => $v];
            continue;
        }
        if (is_array($v)) {
            foreach ($v as $locLabel => $url) {
                if (!is_string($url) || $url === '') continue;
                $value = (string)$envKey . '::' . (string)$locLabel;
                $label = (string)$envKey . ' - ' . (string)$locLabel;
                $out[] = ['value' => $value, 'label' => $label, 'url' => $url];
            }
        }
    }
    usort($out, static fn($a, $b) => strcmp($a['label'], $b['label']));
    return $out;
}

$envOptions = harnessui_env_options($config['envs'] ?? []);

// Load Basic credential profiles for WS (WS uses basic only)
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
  <title>Harness UI - WS Suite</title>
  <style>
    body { font-family: sans-serif; margin: 24px; max-width: 900px; }
    label { display:block; margin-top: 12px; }
    input, select { width: 100%; padding: 8px; }
    button { margin-top: 16px; padding: 10px 14px; }
    .row { display:flex; gap: 12px; }
    .row > div { flex: 1; }
    .hint { color:#555; font-size: 0.95em; }
    .nav { margin-top: 14px; }
  </style>
</head>
<body>
  <h2>WS Suite Runner</h2>

  <div class="nav">
    <a href="/runs.php">View past runs</a> | <a href="/rest.php">REST harness</a>
  </div>

  <form method="post" action="/run.php" autocomplete="off">
    <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf'], ENT_QUOTES) ?>">

    <label>Environment</label>
    <select name="env" required>
      <?php foreach ($envOptions as $opt): ?>
        <option value="<?= htmlspecialchars($opt['value'], ENT_QUOTES) ?>">
          <?= htmlspecialchars($opt['label']) ?> (<?= htmlspecialchars($opt['url']) ?>)
        </option>
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
      Selecting a profile fills username/password. You can still edit them manually.
    </div>

    <button type="submit">Run WS Suite</button>
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
