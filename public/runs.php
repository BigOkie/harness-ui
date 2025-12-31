<?php
declare(strict_types=1);

$config = require '/srv/harness-ui/bootstrap.php';
$runsDir = $config['runs_dir'];

$items = [];
if (is_dir($runsDir)) {
    foreach (scandir($runsDir) as $d) {
        if ($d === '.' || $d === '..') {
            continue;
        }
        $p = $runsDir . '/' . $d;
        if (!is_dir($p)) {
            continue;
        }
        $meta = $p . '/meta.json';
        $row = ['id' => $d, 'started' => '', 'env' => '', 'url' => '', 'exit' => ''];
        if (is_file($meta)) {
            $j = json_decode((string)file_get_contents($meta), true);
            if (is_array($j)) {
                $row['started'] = (string)($j['startedAtUtc'] ?? '');
                $row['env'] = (string)($j['env'] ?? '');
                $row['url'] = (string)($j['url'] ?? $j['baseUrl'] ?? '');
                $row['exit'] = (string)($j['exitCode'] ?? '');
            }
        }
        $items[] = $row;
    }
}

usort($items, fn ($a, $b) => strcmp($b['id'], $a['id']));
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>Harness UI - Runs</title>
  <style>
    body{font-family:sans-serif;margin:24px;max-width:1100px}
    table{border-collapse:collapse;width:100%}
    th,td{border:1px solid #ddd;padding:8px;text-align:left}
    th{background:#f5f5f5}
    code{font-family:monospace}
  </style>
</head>
<body>
  <h2>Past runs</h2>
  <div><a href="/">Run a new suite</a></div>
  <table>
    <thead>
      <tr>
        <th>Run ID</th>
        <th>Started (UTC)</th>
        <th>Env</th>
        <th>URL</th>
        <th>Exit</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($items as $r): ?>
      <tr>
        <td><a href="/view.php?id=<?= urlencode($r['id']) ?>"><?= htmlspecialchars($r['id']) ?></a></td>
        <td><?= htmlspecialchars($r['started']) ?></td>
        <td><?= htmlspecialchars($r['env']) ?></td>
        <td><code><?= htmlspecialchars($r['url']) ?></code></td>
        <td><code><?= htmlspecialchars($r['exit']) ?></code></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</body>
</html>
