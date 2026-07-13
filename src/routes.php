<?php

declare(strict_types=1);

use LibraTrack\Controllers\AuthController;
use LibraTrack\Controllers\BookController;
use LibraTrack\Controllers\CategoryController;
use LibraTrack\Controllers\FineController;
use LibraTrack\Controllers\MemberController;
use LibraTrack\Controllers\ReservationController;
use LibraTrack\Controllers\SettingsController;
use LibraTrack\Controllers\TransactionController;
use LibraTrack\Core\Config;
use LibraTrack\Core\Database;
use LibraTrack\Core\Request;
use LibraTrack\Core\Response;
use LibraTrack\Core\Router;
use LibraTrack\Middleware\AuthMiddleware;
use LibraTrack\Middleware\RoleMiddleware;
use LibraTrack\Repositories\BookRepository;
use LibraTrack\Repositories\CategoryRepository;
use LibraTrack\Repositories\FineRepository;
use LibraTrack\Repositories\MemberRepository;
use LibraTrack\Repositories\RefreshTokenRepository;
use LibraTrack\Repositories\ReservationRepository;
use LibraTrack\Repositories\SettingsRepository;
use LibraTrack\Repositories\TransactionRepository;
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
$settingsRepository = new SettingsRepository($pdo);
$settings = new SettingsController($settingsRepository);
$categoryRepository = new CategoryRepository($pdo);
$categoryController = new CategoryController($categoryRepository, $authMiddleware, $roleMiddleware);
$bookRepository = new BookRepository($pdo);
$bookController = new BookController($bookRepository, $categoryRepository, $authMiddleware, $roleMiddleware);
$memberController = new MemberController($members, $users, $passwords, $authMiddleware, $roleMiddleware);
$transactionRepository = new TransactionRepository($pdo);
$fineRepository = new FineRepository($pdo);
$fineController = new FineController($fineRepository, $members, $authMiddleware, $roleMiddleware);
$transactionController = new TransactionController(
    $transactionRepository,
    $fineRepository,
    $members,
    $bookRepository,
    $settingsRepository,
    $authMiddleware,
    $roleMiddleware
);
$reservationRepository = new ReservationRepository($pdo);
$reservationController = new ReservationController(
    $reservationRepository,
    $members,
    $bookRepository,
    $settingsRepository,
    $authMiddleware,
    $roleMiddleware
);

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
// PUT is intentionally aliased to the same partial-update handler as PATCH (deliberate simplification;
// unlike the Django reference's stricter, full-replace-required PUT). See README.md API Overview.
$router->add('PUT', '/api/categories/{id}/', fn (Request $request, array $params): Response => $categoryController->update($request, $params));
$router->add('DELETE', '/api/categories/{id}/', fn (Request $request, array $params): Response => $categoryController->destroy($request, $params));
$router->add('GET', '/api/books/', fn (Request $request, array $params): Response => $bookController->index($request));
$router->add('POST', '/api/books/', fn (Request $request, array $params): Response => $bookController->store($request));
$router->add('GET', '/api/books/{id}/', fn (Request $request, array $params): Response => $bookController->show($request, $params));
$router->add('PATCH', '/api/books/{id}/', fn (Request $request, array $params): Response => $bookController->update($request, $params));
// PUT is intentionally aliased to the same partial-update handler as PATCH (deliberate simplification;
// unlike the Django reference's stricter, full-replace-required PUT). See README.md API Overview.
$router->add('PUT', '/api/books/{id}/', fn (Request $request, array $params): Response => $bookController->update($request, $params));
$router->add('DELETE', '/api/books/{id}/', fn (Request $request, array $params): Response => $bookController->destroy($request, $params));
$router->add('GET', '/api/members/', fn (Request $request, array $params): Response => $memberController->index($request));
$router->add('POST', '/api/members/', fn (Request $request, array $params): Response => $memberController->store($request));
$router->add('GET', '/api/members/{id}/', fn (Request $request, array $params): Response => $memberController->show($request, $params));
$router->add('PATCH', '/api/members/{id}/', fn (Request $request, array $params): Response => $memberController->update($request, $params));
$router->add('DELETE', '/api/members/{id}/', fn (Request $request, array $params): Response => $memberController->destroy($request, $params));
$router->add('GET', '/api/transactions/', fn (Request $request, array $params): Response => $transactionController->index($request));
$router->add('POST', '/api/transactions/', fn (Request $request, array $params): Response => $transactionController->store($request));
$router->add('GET', '/api/transactions/{id}/', fn (Request $request, array $params): Response => $transactionController->show($request, $params));
$router->add('POST', '/api/transactions/{id}/return/', fn (Request $request, array $params): Response => $transactionController->returnItems($request, $params));
$router->add('GET', '/api/members/{id}/transactions/', fn (Request $request, array $params): Response => $transactionController->forMember($request, $params));
$router->add('GET', '/api/reservations/', fn (Request $request, array $params): Response => $reservationController->index($request));
$router->add('POST', '/api/reservations/', fn (Request $request, array $params): Response => $reservationController->store($request));
$router->add('GET', '/api/reservations/{id}/', fn (Request $request, array $params): Response => $reservationController->show($request, $params));
$router->add('PATCH', '/api/reservations/{id}/cancel/', fn (Request $request, array $params): Response => $reservationController->cancel($request, $params));
$router->add('PATCH', '/api/reservations/{id}/fulfill/', fn (Request $request, array $params): Response => $reservationController->fulfill($request, $params));
$router->add('GET', '/api/members/{id}/reservations/', fn (Request $request, array $params): Response => $reservationController->forMember($request, $params));
$router->add('GET', '/api/fines/', fn (Request $request, array $params): Response => $fineController->index($request));
$router->add('GET', '/api/fines/{id}/', fn (Request $request, array $params): Response => $fineController->show($request, $params));
$router->add('PATCH', '/api/fines/{id}/pay/', fn (Request $request, array $params): Response => $fineController->pay($request, $params));
$router->add('PATCH', '/api/fines/{id}/waive/', fn (Request $request, array $params): Response => $fineController->waive($request, $params));
$router->add('GET', '/api/members/{id}/fines/', fn (Request $request, array $params): Response => $fineController->forMember($request, $params));

return $router;
