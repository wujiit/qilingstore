<?php

declare(strict_types=1);

namespace Qiling\Controllers;

use Qiling\Core\Database;
use Qiling\Core\PasswordResetService;
use Qiling\Support\Request;
use Qiling\Support\Response;

final class PasswordResetController
{
    public static function request(): void
    {
        self::sendNoStoreHeaders();
        $data = Request::jsonBody();
        $account = Request::str($data, 'account');
        $email = Request::str($data, 'email');
        $ip = PasswordResetService::resolveClientIp();

        try {
            PasswordResetService::requestEmailReset(Database::pdo(), $account, $email, $ip);
        } catch (\Throwable) {
            // Use generic response to avoid user/account probing.
        }

        Response::json([
            'message' => 'If account info matches, a verification code has been sent',
        ]);
    }

    public static function confirm(): void
    {
        self::sendNoStoreHeaders();
        $data = Request::jsonBody();
        $account = Request::str($data, 'account');
        $email = Request::str($data, 'email');
        $code = Request::str($data, 'code');
        $newPassword = Request::str($data, 'new_password');
        $ip = PasswordResetService::resolveClientIp();

        if ($account === '' || $email === '' || $code === '' || $newPassword === '') {
            Response::json(['message' => 'account, email, code, new_password are required'], 422);
            return;
        }

        try {
            PasswordResetService::confirmEmailReset(
                Database::pdo(),
                $account,
                $email,
                $code,
                $newPassword,
                $ip
            );
            Response::json([
                'message' => 'password reset success',
            ]);
        } catch (\RuntimeException $e) {
            Response::json(['message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            Response::serverError('password reset failed', $e);
        }
    }

    private static function sendNoStoreHeaders(): void
    {
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('Expires: 0');
    }
}
