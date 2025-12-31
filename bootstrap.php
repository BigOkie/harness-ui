<?php
declare(strict_types=1);

$CONFIG_PATH = '/srv/harness-ui/config.php';
if (!is_file($CONFIG_PATH)) {
    throw new RuntimeException("Missing config: {$CONFIG_PATH}");
}
return require $CONFIG_PATH;
