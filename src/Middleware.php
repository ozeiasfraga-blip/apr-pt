<?php

namespace App;

use App\Helpers\JWT;
use App\Helpers\Response;

class Middleware
{
    public static function cors(): void
    {
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization');

        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            http_response_code(204);
            exit;
        }
    }

    public static function clientIp(): string
    {
        return $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
    }

    public static function getAuthUser(): ?object
    {
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';

        if (empty($authHeader) && function_exists('getallheaders')) {
            $headers = getallheaders();
            $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? '';
        }

        if (empty($authHeader) && !empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
            $authHeader = 'Bearer ' . $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
        }

        if (empty($authHeader) && !empty($_COOKIE['ap_token'])) {
            $authHeader = 'Bearer ' . $_COOKIE['ap_token'];
        }

        if (!preg_match('/^Bearer\s+(.+)$/i', $authHeader, $matches)) {
            return null;
        }

        return JWT::decode($matches[1]);
    }

    public static function requireAuth(): object
    {
        $user = self::getAuthUser();
        if ($user === null) {
            Response::error('Não autorizado. Token inválido ou expirado.', 401);
        }
        return $user;
    }

    public static function requireRole(array $roles): object
    {
        $user = self::requireAuth();
        if (!isset($user->role) || !in_array($user->role, $roles, true)) {
            Response::error('Acesso negado. Permissão insuficiente.', 403);
        }
        return $user;
    }

    public static function requireSupervisor(): object
    {
        return self::requireRole(['supervisor', 'admin']);
    }

    public static function requireAdmin(): object
    {
        return self::requireRole(['admin']);
    }

    public static function isAdmin(object $user): bool
    {
        return isset($user->role) && $user->role === 'admin';
    }
}
