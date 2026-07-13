<?php

declare(strict_types=1);

namespace LibraTrack\Controllers;

use LibraTrack\Core\Request;
use LibraTrack\Core\Response;
use LibraTrack\Core\ValidationException;
use LibraTrack\Services\AuthService;
use LibraTrack\Services\TokenService;

final class AuthController
{
    public function __construct(
        private readonly AuthService $auth,
        private readonly TokenService $tokens,
        private readonly bool $secureCookie
    ) {
    }

    public function login(Request $request): Response
    {
        $session = $this->auth->login((string) ($request->json['email'] ?? ''), (string) ($request->json['password'] ?? ''));
        return $this->sessionResponse($session);
    }

    public function signup(Request $request): Response
    {
        $session = $this->auth->signup($request->json ?? []);
        return $this->sessionResponse($session, true);
    }

    public function refresh(Request $request): Response
    {
        $token = $request->cookies['refreshToken'] ?? '';
        $session = $this->auth->refresh($token);
        return $this->sessionResponse($session);
    }

    public function logout(Request $request): Response
    {
        $token = $request->cookies['refreshToken'] ?? '';
        if ($token !== '') {
            $this->auth->logout($token);
        }

        return new Response(
            ['status' => 'success', 'data' => null],
            200,
            ['Set-Cookie' => $this->clearCookieHeader()]
        );
    }

    public function me(Request $request): Response
    {
        $payload = $this->requirePayload($request);
        return Response::success($this->auth->currentUser((int) $payload['sub']));
    }

    public function updateMe(Request $request): Response
    {
        $payload = $this->requirePayload($request);
        $email = (string) ($request->json['email'] ?? '');
        if ($email === '') {
            throw new ValidationException('email is required');
        }
        return Response::success($this->auth->updateEmail((int) $payload['sub'], $email));
    }

    public function changePassword(Request $request): Response
    {
        $payload = $this->requirePayload($request);
        $this->auth->changePassword((int) $payload['sub'], (string) ($request->json['password'] ?? ''));
        return Response::success(null);
    }

    private function sessionResponse(array $session, bool $includeUser = false): Response
    {
        $data = ['accessToken' => $session['accessToken']];
        if ($includeUser) {
            $data['user'] = $session['user'];
        }

        return new Response(
            ['status' => 'success', 'data' => $data],
            200,
            ['Set-Cookie' => $this->cookieHeader($session['refreshToken'])]
        );
    }

    private function requirePayload(Request $request): array
    {
        $token = $request->bearerToken();
        if ($token === null) {
            throw new ValidationException('Authentication required', 401);
        }
        return $this->tokens->decodeAccessToken($token);
    }

    private function cookieHeader(string $token): string
    {
        $secure = $this->secureCookie ? '; Secure' : '';
        return "refreshToken={$token}; Path=/; HttpOnly; SameSite=Lax{$secure}";
    }

    private function clearCookieHeader(): string
    {
        $secure = $this->secureCookie ? '; Secure' : '';
        return "refreshToken=; Path=/; HttpOnly; SameSite=Lax; Max-Age=0{$secure}";
    }
}
