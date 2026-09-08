<?php

declare(strict_types=1);

namespace JKBMS\Auth;

use JKBMS\Core\Request;
use JKBMS\Core\Response;

/**
 * Single shared-password gate for the whole app.
 *
 * MVP Access Control (see context/foundation/prd.md): one password for
 * everyone, stored server-side in .env, no accounts, no roles. Per-user
 * accounts are a Non-Goal for MVP.
 */
class AuthController
{
    public function loginForm(Request $req): void
    {
        if (self::check()) {
            Response::redirect('/');
        }

        Response::view('login', ['error' => null]);
    }

    public function login(Request $req): void
    {
        $config   = require dirname(__DIR__, 2) . '/config/app.php';
        $password = (string) $req->input('password', '');

        $expected = (string) $config['app_password'];

        if ($expected === '' || !hash_equals($expected, $password)) {
            Response::view('login', ['error' => 'Nieprawidłowe hasło.']);
            return;
        }

        $_SESSION['authenticated'] = true;
        Response::redirect('/');
    }

    public function logout(Request $req): void
    {
        unset($_SESSION['authenticated']);
        Response::redirect('/login');
    }

    public static function check(): bool
    {
        return !empty($_SESSION['authenticated']);
    }

    public static function requireAuth(): void
    {
        if (!self::check()) {
            Response::redirect('/login');
        }
    }
}
