<?php
declare(strict_types=1);

// Where the JSON endpoint lives relative to this page:
$jsonUrl = 'portal_status.json.php';
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Portal Pool Status</title>
  <style>
    body { font-family: system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif; margin: 24px; }
    .card { max-width: 700px; padding: 16px 18px; border: 1px solid #ddd; border-radius: 10px; }
    .row { margin: 8px 0; }
    .k { color: #555; width: 110px; display: inline-block; }
    .badge { display: inline-block; padding: 2px 10px; border-radius: 999px; border: 1px solid #ccc; }
    .ok { border-color: #6cc070; }
    .warn { border-color: #d6b44a; }
    .err { border-color: #d86a6a; }
    .small { color: #666; font-size: 0.9em; }
    button { padding: 7px 12px; border-radius: 8px; border: 1px solid #ccc; background: #fff; cursor: pointer; }
    button:hover { background: #f6f6f6; }
  </style>
</head>
<body>

<h2>Portal Pool Status</h2>

<div class="card">
  <div class="row"><span class="k">Location</span> <span id="location" class="badge">Loading…</span></div>
  <div class="row"><span class="k">Host</span> <span id="host">—</span></div>
  <div class="row"><span class="k">Backend</span> <span id="backend">—</span></div>
  <div class="row"><span class="k">IP</span> <span id="ip">—</span></div>
  <div class="row small">Last checked: <span id="ts">—</span></div>

  <div style="margin-top: 12px; display: flex; gap: 10px; align-items: center;">
    <button onclick="refreshNow()">Refresh</button>
    <label class="small">
      Auto-refresh:
      <select id="interval" onchange="setAutoRefresh()">
        <option value="0">Off</option>
        <option value="5">5s</option>
        <option value="10" selected>10s</option>
        <option value="30">30s</option>
        <option value="60">60s</option>
      </select>
    </label>
    <span id="err" class="small" style="color:#b00;"></span>
  </div>
</div>

<script>
let timer = null;

function badgeClass(location) {
  const l = (location || '').toLowerCase();
  if (l.includes('tulsa') || l.includes('atlanta')) return 'badge ok';
  if (l.includes('unknown')) return 'badge warn';
  return 'badge err';
}

async function refreshNow() {
  const errEl = document.getElementById('err');
  errEl.textContent = '';
  try {
    const res = await fetch('<?php echo htmlspecialchars($jsonUrl, ENT_QUOTES); ?>?t=' + Date.now(), { cache: 'no-store' });
    if (!res.ok) throw new Error('HTTP ' + res.status);
    const data = await res.json();

    document.getElementById('host').textContent = data.host ?? '—';
    document.getElementById('backend').textContent = data.backend ?? '—';
    document.getElementById('ip').textContent = data.ip ?? '—';

    const locEl = document.getElementById('location');
    locEl.textContent = data.location ?? 'Unknown';
    locEl.className = badgeClass(data.location);

    document.getElementById('ts').textContent = data.timestamp ?? '—';
  } catch (e) {
    errEl.textContent = 'Refresh failed: ' + (e?.message || e);
  }
}

function setAutoRefresh() {
  if (timer) clearInterval(timer);
  timer = null;

  const seconds = parseInt(document.getElementById('interval').value, 10);
  if (seconds > 0) {
    timer = setInterval(refreshNow, seconds * 1000);
  }
}

refreshNow();
setAutoRefresh();
</script>

</body>
</html>
