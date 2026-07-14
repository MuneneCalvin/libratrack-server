<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use LibraTrack\Core\App;
use LibraTrack\Core\Config;

$config = Config::fromProjectRoot(dirname(__DIR__));
$app = App::fromConfig($config);

$app->run();
