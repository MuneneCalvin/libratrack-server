<?php

declare(strict_types=1);

use LibraTrack\Controllers\AuthController;
use LibraTrack\Controllers\CategoryController;
use LibraTrack\Controllers\SettingsController;
use LibraTrack\Core\Config;
use LibraTrack\Core\Database;
use LibraTrack\Core\Request;
use LibraTrack\Core\Response;
use LibraTrack\Core\Router;
use LibraTrack\Middleware\AuthMiddleware;
use LibraTrack\Middleware\RoleMiddleware;
use LibraTrack\Repositories\CategoryRepository;
use LibraTrack\Repositories\MemberRepository;
use LibraTrack\Repositories\RefreshTokenRepository;
use LibraTrack\Repositories\SettingsRepository;
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
$authMiddleware = new AuthMiddleware($tokens);
$roleMiddleware = new RoleMiddleware();
$auth = new AuthController($authService, $authMiddleware, $config->bool('COOKIE_SECURE', false));
$settings = new SettingsController(new SettingsRepository($pdo));
$categoryController = new CategoryController(new CategoryRepository($pdo), $authMiddleware, $roleMiddleware);

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
$router->add('GET', '/api/settings/', fn (Request $request, array $params): Response => $settings->show($request));
$router->add('PATCH', '/api/settings/', fn (Request $request, array $params): Response => $settings->update($request));
$router->add('PUT', '/api/settings/', fn (Request $request, array $params): Response => $settings->update($request));
$router->add('GET', '/api/categories/', fn (Request $request, array $params): Response => $categoryController->index($request));
$router->add('POST', '/api/categories/', fn (Request $request, array $params): Response => $categoryController->store($request));
$router->add('GET', '/api/categories/{id}/', fn (Request $request, array $params): Response => $categoryController->show($request, $params));
$router->add('PATCH', '/api/categories/{id}/', fn (Request $request, array $params): Response => $categoryController->update($request, $params));
$router->add('PUT', '/api/categories/{id}/', fn (Request $request, array $params): Response => $categoryController->update($request, $params));
$router->add('DELETE', '/api/categories/{id}/', fn (Request $request, array $params): Response => $categoryController->destroy($request, $params));

return $router;
