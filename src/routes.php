<?php

declare(strict_types=1);

use LibraTrack\Controllers\AuthController;
use LibraTrack\Core\Config;
use LibraTrack\Core\Database;
use LibraTrack\Core\Request;
use LibraTrack\Core\Response;
use LibraTrack\Core\Router;
use LibraTrack\Repositories\MemberRepository;
use LibraTrack\Repositories\RefreshTokenRepository;
use LibraTrack\Repositories\UserRepository;
use LibraTrack\Services\AuthService;
use LibraTrack\Services\PasswordService;
use LibraTrack\Services\TokenService;

$config = Config::fromProjectRoot(dirname(__DIR__));
$pdo = Database::fromConfig($config)->pdo();

$users = new UserRepository($pdo);
$refreshTokens = new RefreshTokenRepository($pdo);
$members = new MemberRepository($pdo);
$passwords = new PasswordService();
$tokens = new TokenService($config);
$authService = new AuthService($users, $refreshTokens, $members, $passwords, $tokens);
$auth = new AuthController($authService, $tokens, $config->bool('COOKIE_SECURE', false));

$router = new Router();

$router->add('GET', '/api/health/', function (Request $request, array $params): Response {
    return Response::success(['ok' => true]);
});
$router->add('POST', '/api/auth/login/', fn (Request $request, array $params): Response => $auth->login($request));
$router->add('POST', '/api/auth/signup/', fn (Request $request, array $params): Response => $auth->signup($request));
$router->add('POST', '/api/auth/refresh/', fn (Request $request, array $params): Response => $auth->refresh($request));
$router->add('POST', '/api/auth/logout/', fn (Request $request, array $params): Response => $auth->logout($request));
$router->add('GET', '/api/auth/me/', fn (Request $request, array $params): Response => $auth->me($request));
$router->add('PATCH', '/api/auth/me/', fn (Request $request, array $params): Response => $auth->updateMe($request));
$router->add('PATCH', '/api/auth/change-password/', fn (Request $request, array $params): Response => $auth->changePassword($request));

return $router;
