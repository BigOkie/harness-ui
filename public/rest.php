<?php
declare(strict_types=1);

session_start();
$config = require '/srv/harness-ui/bootstrap.php';

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
}

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

$restEnvs    = $config['rest_envs'] ?? [];
$envOptions  = harnessui_env_options($restEnvs);

// Discover and validate REST scenario files.
$scenarios = [];
$restDir = (string)($config['rest_harness_dir'] ?? '');
$scenarioDir = $restDir !== ''
    ? realpath($restDir . '/scenarios')
    : false;

if ($scenarioDir !== false && is_dir($scenarioDir)) {
    foreach (glob($scenarioDir . '/rest_scenarios_*.json') ?: [] as $file) {
        $filename = basename($file);
        $resolved = realpath($file);

        if (
            preg_match('/\Arest_scenarios_[a-zA-Z0-9_-]+\.json\z/', $filename) &&
            $resolved !== false &&
            is_file($resolved) &&
            is_readable($resolved) &&
            dirname($resolved) === $scenarioDir
        ) {
            $scenarios[] = $filename;
        }
    }

    sort($scenarios, SORT_STRING);
}

// Load credential profiles (basic + optional hmac fields)
$profiles = [];
$credsDir = $config['creds_dir'] ?? null;
if (is_string($credsDir) && is_dir($credsDir)) {
    foreach (glob($credsDir . '/*.php') ?: [] as $file) {
        $id = basename($file, '.php');
        $data = require $file;
        if (!is_array($data)) continue;

        $u  = isset($data['username']) ? (string)$data['username'] : '';
        $pw = isset($data['password']) ? (string)$data['password'] : '';

        $hu = isset($data['hmac_username']) ? (string)$data['hmac_username'] : '';
        $hs = isset($data['hmac_secret'])   ? (string)$data['hmac_secret']   : '';

        if ($u === '' && $pw === '' && $hu === '' && $hs === '') continue;

        $profiles[$id] = [
            'label'         => (string)($data['label'] ?? $id),
            'username'      => $u,
            'password'      => $pw,
            'hmac_username' => $hu,
            'hmac_secret'   => $hs,
        ];
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
    body { font-family: sans-serif; margin: 24px; max-width: 1000px; }
    label { display:block; margin-top: 12px; }
    input, select { width: 100%; padding: 8px; }
    button { margin-top: 16px; padding: 10px 14px; }
    .row { display:flex; gap: 12px; }
    .row > div { flex: 1; }
    .hint { color:#555; font-size: 0.95em; }
    .nav { margin-top: 14px; }
    .hidden { display:none; }
  </style>
</head>
<body>
  <h2>REST Harness Runner</h2>

  <div class="nav">
    <a href="/runs.php">View past runs</a> | <a href="/index.php">WS harness</a>
  </div>

  <form method="post" action="/run_rest.php" autocomplete="off">
    <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf'], ENT_QUOTES) ?>">

    <label>Environment</label>
    <select name="env" required>
      <?php foreach ($envOptions as $opt): ?>
        <option value="<?= htmlspecialchars($opt['value'], ENT_QUOTES) ?>">
          <?= htmlspecialchars($opt['label']) ?> (<?= htmlspecialchars($opt['url']) ?>)
        </option>
      <?php endforeach; ?>
    </select>

    <label>Default auth method</label>
    <select id="auth" name="auth">
      <option value="basic">basic</option>
      <option value="hmac">hmac</option>
    </select>

    <label>Scenario file</label>
    <select name="scenario" required>
      <?php foreach ($scenarios as $scenario): ?>
        <option value="<?= htmlspecialchars($scenario, ENT_QUOTES, 'UTF-8') ?>">
          <?= htmlspecialchars($scenario, ENT_QUOTES, 'UTF-8') ?>
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
          data-hu="<?= htmlspecialchars($p['hmac_username'], ENT_QUOTES) ?>"
          data-hs="<?= htmlspecialchars($p['hmac_secret'], ENT_QUOTES) ?>"
        ><?= htmlspecialchars($p['label']) ?></option>
      <?php endforeach; ?>
    </select>

    <div id="basic_fields" class="row">
      <div>
        <label>Basic username</label>
        <input id="basic_user" name="basic_user">
      </div>
      <div>
        <label>Basic password</label>
        <input id="basic_pass" name="basic_pass" type="password">
      </div>
    </div>

    <div id="hmac_fields" class="row hidden">
      <div>
        <label>HMAC username</label>
        <input id="hmac_user" name="hmac_user">
      </div>
      <div>
        <label>HMAC secret (base64)</label>
        <input id="hmac_secret" name="hmac_secret" type="password">
      </div>
    </div>

    <div class="hint" style="margin-top:10px;">
      Selecting a profile fills fields for the currently selected auth method.
      Switch auth and it will re-apply from the selected profile (if present).
    </div>

    <button type="submit">Run REST Tests</button>
  </form>

  <script>
    (function () {
      const selProfile = document.getElementById('profile');
      const selAuth    = document.getElementById('auth');

      const basicWrap  = document.getElementById('basic_fields');
      const hmacWrap   = document.getElementById('hmac_fields');

      const basicUser  = document.getElementById('basic_user');
      const basicPass  = document.getElementById('basic_pass');

      const hmacUser   = document.getElementById('hmac_user');
      const hmacSecret = document.getElementById('hmac_secret');

      function applyVisibility() {
        const mode = (selAuth.value || 'basic').toLowerCase();
        if (mode === 'hmac') {
          basicWrap.classList.add('hidden');
          hmacWrap.classList.remove('hidden');
        } else {
          hmacWrap.classList.add('hidden');
          basicWrap.classList.remove('hidden');
        }
      }

      function applyFromProfile() {
        const opt = selProfile.options[selProfile.selectedIndex];
        if (!opt) return;

        const mode = (selAuth.value || 'basic').toLowerCase();

        if (mode === 'hmac') {
          const hu = opt.getAttribute('data-hu') || '';
          const hs = opt.getAttribute('data-hs') || '';
          hmacUser.value = hu;
          hmacSecret.value = hs;
        } else {
          const u = opt.getAttribute('data-u') || '';
          const p = opt.getAttribute('data-p') || '';
          basicUser.value = u;
          basicPass.value = p;
        }
      }

      selProfile.addEventListener('change', () => {
        applyFromProfile();
      });

      selAuth.addEventListener('change', () => {
        applyVisibility();
        applyFromProfile();
      });

      // init
      applyVisibility();
    })();
  </script>
</body>
</html>
