<?php

namespace App\Controllers;

use App\Models\User;
use App\Models\Log;
use App\Helpers\JWT;
use App\Helpers\Response;
use App\Helpers\Validator;
use App\Middleware;

class AuthController
{
    public static function login(): void
    {
        $data = json_decode(file_get_contents('php://input'), true) ?? [];

        $v = new Validator();
        $v->required($data, ['email', 'password']);
        if (!$v->passes()) {
            Response::error('Dados inválidos.', 422, $v->errors());
        }

        $user = User::getByEmail($data['email']);
        if (!$user || !password_verify($data['password'], $user['password_hash'])) {
            Response::error('Credenciais inválidas.', 401);
        }
        if ((int)$user['ativo'] !== 1) {
            Response::error('Usuário inativo.', 403);
        }

        unset($user['password_hash']);

        $token = JWT::encode([
            'user_id' => (int)$user['id'],
            'role' => $user['role'],
            'email' => $user['email'],
            'ixc_funcionario_id' => $user['ixc_funcionario_id'] !== null ? (int)$user['ixc_funcionario_id'] : null,
        ]);

        User::createToken((int)$user['id'], $token, (int)($_ENV['JWT_EXPIRY'] ?? 43200));

        Log::create([
            'user_id' => $user['id'],
            'acao' => 'login',
            'entidade' => 'user',
            'entidade_id' => $user['id'],
            'ip' => Middleware::clientIp(),
        ]);

        Response::success([
            'access_token' => $token,
            'token_type' => 'Bearer',
            'expires_in' => (int)($_ENV['JWT_EXPIRY'] ?? 43200),
            'user' => $user,
        ], 'Login realizado com sucesso.');
    }

    public static function me(): void
    {
        $authUser = Middleware::requireAuth();
        $user = User::getById((int)$authUser->user_id);
        if (!$user) {
            Response::error('Usuário não encontrado.', 404);
        }
        Response::success($user);
    }

    public static function logout(): void
    {
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (preg_match('/^Bearer\s+(.+)$/i', $authHeader, $m)) {
            $decoded = JWT::decode($m[1]);
            User::deleteToken($m[1]);
            if ($decoded) {
                Log::create([
                    'user_id' => $decoded->user_id,
                    'acao' => 'logout',
                    'entidade' => 'user',
                    'entidade_id' => $decoded->user_id,
                    'ip' => Middleware::clientIp(),
                ]);
            }
        }
        Response::success(null, 'Logout realizado.');
    }

    public static function register(): void
    {
        $authUser = Middleware::requireAdmin();
        $data = json_decode(file_get_contents('php://input'), true) ?? [];

        $v = new Validator();
        $v->required($data, ['name', 'email', 'password']);
        $v->email($data, 'email');
        $v->minLength($data, 'password', 6);
        $v->inList($data, 'role', ['tecnico', 'supervisor', 'admin']);
        if (!$v->passes()) {
            Response::error('Dados inválidos.', 422, $v->errors());
        }

        if (User::getByEmail($data['email'])) {
            Response::error('E-mail já cadastrado.', 409);
        }

        $userId = User::create([
            'name' => trim($data['name']),
            'email' => trim($data['email']),
            'password_hash' => password_hash($data['password'], PASSWORD_BCRYPT),
            'role' => $data['role'] ?? 'tecnico',
            'ixc_funcionario_id' => $data['ixc_funcionario_id'] ?? null,
        ]);

        Log::create([
            'user_id' => $authUser->user_id,
            'acao' => 'register_user',
            'entidade' => 'user',
            'entidade_id' => $userId,
            'detalhes' => ['role' => $data['role'] ?? 'tecnico'],
            'ip' => Middleware::clientIp(),
        ]);

        Response::success(User::getById($userId), 'Usuário criado com sucesso.', 201);
    }

    public static function changePassword(): void
    {
        $authUser = Middleware::requireAuth();
        $data = json_decode(file_get_contents('php://input'), true) ?? [];

        if (empty($data['current_password']) || empty($data['new_password'])) {
            Response::error('Senha atual e nova senha são obrigatórias.', 422);
        }
        if (strlen($data['new_password']) < 6) {
            Response::error('A nova senha deve ter no mínimo 6 caracteres.', 422);
        }

        $user = User::getByEmail($authUser->email);
        if (!$user || !password_verify($data['current_password'], $user['password_hash'])) {
            Response::error('Senha atual incorreta.', 403);
        }

        User::updatePassword((int)$authUser->user_id, password_hash($data['new_password'], PASSWORD_BCRYPT));

        Log::create([
            'user_id' => $authUser->user_id,
            'acao' => 'change_password',
            'entidade' => 'user',
            'entidade_id' => $authUser->user_id,
            'ip' => Middleware::clientIp(),
        ]);

        Response::success(null, 'Senha alterada com sucesso.');
    }
}
