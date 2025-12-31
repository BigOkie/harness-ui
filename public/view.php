<?php
declare(strict_types=1);

$config = require '/srv/harness-ui/bootstrap.php';
$runsDir = $config['runs_dir'];

$id = (string)($_GET['id'] ?? '');
$id = basename($id);

if (!preg_match('/^[0-9]{8}_[0-9]{6}_[a-fA-F0-9]{6,32}$/', $id)) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Invalid id\n";
    exit;
}

$runPath = $runsDir . '/' . $id;

$metaFile = $runPath . '/meta.json';
$outFile  = $runPath . '/stdout.log';
$errFile  = $runPath . '/stderr.log';

if (!is_dir($runPath)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Run not found: {$id}\n";
    exit;
}

$meta = is_file($metaFile) ? json_decode((string)file_get_contents($metaFile), true) : [];
$out  = is_file($outFile) ? (string)file_get_contents($outFile) : '';
$err  = is_file($errFile) ? (string)file_get_contents($errFile) : '';
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>Run <?= htmlspecialchars($id) ?></title>
  <style>
    body{font-family:sans-serif;margin:24px;max-width:1100px}
    pre{background:#111;color:#eee;padding:12px;white-space:pre-wrap}
    .box{margin-top:14px}
  </style>
</head>
<body>
  <h2>Run <?= htmlspecialchars($id) ?></h2>
  <div><a href="/runs.php">Back to runs</a></div>

  <div class="box">
    <h3>Metadata</h3>
    <pre><?= htmlspecialchars(json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}', ENT_NOQUOTES) ?></pre>
  </div>

  <div class="box">
    <h3>stdout</h3>
    <pre><?= htmlspecialchars($out, ENT_NOQUOTES) ?></pre>
  </div>

  <div class="box">
    <h3>stderr</h3>
    <pre><?= htmlspecialchars($err, ENT_NOQUOTES) ?></pre>
  <div class="box">
    <h3>Artifacts</h3>
    <?php
      $artifactsDir = $runPath . '/artifacts';
      $files = [];
      if (is_dir($artifactsDir)) {
          foreach (scandir($artifactsDir) as $f) {
              if ($f === '.' || $f === '..') continue;
              $p = $artifactsDir . '/' . $f;
              if (is_file($p)) {
                  $files[] = $f;
              }
          }
      }
      sort($files);
    ?>
    <?php if (empty($files)): ?>
      <div>(none)</div>
    <?php else: ?>
      <ul>
        <?php foreach ($files as $f): ?>
          <li>
            <a href="/download.php?run=<?= urlencode($id) ?>&file=<?= urlencode($f) ?>">
              <?= htmlspecialchars($f) ?>
            </a>
            (<?= (int)filesize($artifactsDir . '/' . $f) ?> bytes)
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </div>

  </div>
</body>
</html>
