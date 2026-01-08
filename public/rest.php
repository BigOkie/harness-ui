<?php

declare(strict_types=1);

session_start();
$config = require '/srv/harness-ui/bootstrap.php';

if (empty($_SESSION['csrf'])) {
  $_SESSION['csrf'] = bin2hex(random_bytes(16));
}

$envs = $config['rest_envs'] ?? ($config['envs'] ?? []); // prefer rest_envs, fallback to envs

$profiles = [];
$credsDir = $config['creds_dir'] ?? null;
if (is_string($credsDir) && is_dir($credsDir)) {
  foreach (glob($credsDir . '/*.php') ?: [] as $file) {
    $id = basename($file, '.php');
    $data = require $file;

    if (!is_array($data)) {
      continue;
    }

    $label = (string)($data['label'] ?? $id);

    // Basic
    $u = isset($data['username']) ? (string)$data['username'] : '';
    $p = isset($data['password']) ? (string)$data['password'] : '';

    // HMAC (NOTE: we do NOT decode; we pass through exactly as entered in the creds file)
    $hu = isset($data['hmac_username']) ? (string)$data['hmac_username'] : '';
    $hs = isset($data['hmac_secret']) ? (string)$data['hmac_secret'] : '';

    $profiles[$id] = [
      'label' => $label,
      'username' => $u,
      'password' => $p,
      'hmac_username' => $hu,
      'hmac_secret' => $hs,
    ];
  }
  ksort($profiles);
}

$scenarios = [];
$restHarnessDir = (string)($config['rest_harness_dir'] ?? '');
$scenarioDir = $restHarnessDir !== '' ? ($restHarnessDir . '/scenarios') : '';
if ($scenarioDir !== '' && is_dir($scenarioDir)) {
  foreach (glob($scenarioDir . '/*.json') ?: [] as $file) {
    $scenarios[] = basename($file);
  }
  sort($scenarios);
}
?>
<!doctype html>
<html>

<head>
  <meta charset="utf-8">
  <title>Harness UI - REST Harness</title>
  <style>
    body {
      font-family: sans-serif;
      margin: 24px;
      max-width: 980px;
    }

    label {
      display: block;
      margin-top: 12px;
      font-weight: 600;
    }

    input,
    select {
      width: 100%;
      padding: 8px;
    }

    button {
      margin-top: 16px;
      padding: 10px 14px;
    }

    .row {
      display: flex;
      gap: 12px;
    }

    .row>div {
      flex: 1;
    }

    .hint {
      color: #555;
      font-size: 0.95em;
      margin-top: 6px;
    }

    .nav {
      margin-top: 14px;
    }

    .card {
      border: 1px solid #ddd;
      border-radius: 10px;
      padding: 14px;
      margin-top: 14px;
    }

    code {
      background: #f5f5f5;
      padding: 2px 6px;
      border-radius: 6px;
    }
  </style>
</head>

<body>
  <h2>REST Harness Runner</h2>

  <div class="nav">
    <a href="/runs.php">View past runs</a> | <a href="/index.php">WS harness</a>
  </div>

  <form method="post" action="/run_rest.php" autocomplete="off">
    <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf'], ENT_QUOTES) ?>">

    <div class="card">
      <label>Environment</label>
      <select name="env" required>
        <?php foreach ($envs as $k => $url): ?>
          <option value="<?= htmlspecialchars((string)$k, ENT_QUOTES) ?>">
            <?= htmlspecialchars((string)$k) ?> (<?= htmlspecialchars((string)$url) ?>)
          </option>
        <?php endforeach; ?>
      </select>

      <label>Scenario</label>
      <select name="scenario" required>
        <?php if ($scenarios): ?>
          <?php foreach ($scenarios as $s): ?>
            <option value="<?= htmlspecialchars($s, ENT_QUOTES) ?>"><?= htmlspecialchars($s) ?></option>
          <?php endforeach; ?>
        <?php else: ?>
          <option value="rest_scenarios_basic.json">rest_scenarios_basic.json</option>
        <?php endif; ?>
      </select>

      <label>Auth method</label>
      <select id="auth_mode" name="auth_mode" required>
        <option value="basic">basic</option>
        <option value="hmac">hmac</option>
      </select>

      <label>Credential profile</label>
      <select id="profile" name="profile">
        <option value="">(manual)</option>
        <?php foreach ($profiles as $id => $p): ?>
          <option
            value="<?= htmlspecialchars($id, ENT_QUOTES) ?>"
            data-basic-u="<?= htmlspecialchars((string)$p['username'], ENT_QUOTES) ?>"
            data-basic-p="<?= htmlspecialchars((string)$p['password'], ENT_QUOTES) ?>"
            data-hmac-u="<?= htmlspecialchars((string)$p['hmac_username'], ENT_QUOTES) ?>"
            data-hmac-s="<?= htmlspecialchars((string)$p['hmac_secret'], ENT_QUOTES) ?>"><?= htmlspecialchars((string)$p['label']) ?></option>
        <?php endforeach; ?>
      </select>
      <div class="hint">
        Profile selection populates fields for the selected auth mode.
      </div>
    </div>

    <div class="card" id="basic_fields">
      <div class="row">
        <div>
          <label>Basic username</label>
          <input id="basic_username" name="basic_username" placeholder="e.g. bluefin">
        </div>
        <div>
          <label>Basic password</label>
          <input id="basic_password" name="basic_password" type="password">
        </div>
      </div>
    </div>

    <div class="card" id="hmac_fields" style="display:none">
      <div class="row">
        <div>
          <label>HMAC username</label>
          <input id="hmac_username" name="hmac_username" placeholder="e.g. bluefin_qa_hmac">
        </div>
        <div>
          <label>HMAC secret</label>
          <input id="hmac_secret" name="hmac_secret" type="password" placeholder="Paste the secret exactly as provided (no decoding)">
        </div>
      </div>
      <div class="hint">
        Note: the UI passes this value through as-is to the backend runner. (If your backend expects base64, keep base64.)
      </div>
    </div>

    <button type="submit">Run REST Suite</button>
  </form>

  <script>
    (function() {
      const auth = document.getElementById('auth_mode');
      const prof = document.getElementById('profile');

      const basicBox = document.getElementById('basic_fields');
      const hmacBox = document.getElementById('hmac_fields');

      const bu = document.getElementById('basic_username');
      const bp = document.getElementById('basic_password');
      const hu = document.getElementById('hmac_username');
      const hs = document.getElementById('hmac_secret');

      function selectedOpt() {
        return prof.options[prof.selectedIndex] || null;
      }

      function populateFromProfile() {
        const opt = selectedOpt();
        if (!opt) return;

        const mode = auth.value || 'basic';

        if (mode === 'basic') {
          const u = opt.getAttribute('data-basic-u') || '';
          const p = opt.getAttribute('data-basic-p') || '';
          if (u) bu.value = u;
          if (p) bp.value = p;
        } else {
          const u = opt.getAttribute('data-hmac-u') || '';
          const s = opt.getAttribute('data-hmac-s') || '';
          if (u) hu.value = u;
          if (s) hs.value = s;
        }
      }

      function toggleMode() {
        const mode = auth.value || 'basic';

        if (mode === 'basic') {
          basicBox.style.display = '';
          hmacBox.style.display = 'none';
          bu.required = true;
          bp.required = true;
          hu.required = false;
          hs.required = false;
        } else {
          basicBox.style.display = 'none';
          hmacBox.style.display = '';
          bu.required = false;
          bp.required = false;
          hu.required = true;
          hs.required = true;
        }

        // When switching modes, re-populate from the chosen profile (if any)
        if (prof.value) {
          populateFromProfile();
        }
      }

      auth.addEventListener('change', toggleMode);
      prof.addEventListener('change', populateFromProfile);

      toggleMode();
    })();
  </script>
</body>

</html>