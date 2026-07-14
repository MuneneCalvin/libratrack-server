<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

$_ENV['APP_ENV'] = 'test';
$_ENV['JWT_SECRET'] = 'test-secret-that-is-long-enough-for-hs256-jwt';
$_ENV['JWT_ACCESS_TTL_MINUTES'] = '15';
$_ENV['JWT_REFRESH_TTL_DAYS'] = '7';
