<?php

declare(strict_types=1);

use LibraTrack\Core\Request;
use LibraTrack\Core\Response;
use LibraTrack\Core\Router;

$router = new Router();

$router->add('GET', '/api/health/', function (Request $request, array $params): Response {
    return Response::success(['ok' => true]);
});

return $router;
