<?php

namespace App\Controllers;

use App\Models\User;
use App\Models\Log;
use App\Helpers\Response;
use App\Helpers\Validator;
use App\Middleware;

class UserController
{
    /** GET /api/users?role=&ativo=&search= */
    public static function index(): void
    {
        $auth = Middleware::requireSupervisor();
        $users = User::getAll();

        // Supervisor enxerga somente técnicos
        $role = Middleware::isAdmin($auth) ? ($_GET['role'] ?? null) : 'tecnico';
        $search = $_GET['search'] ?? null;

        if ($role) {
            $users = array_values(array_filter($users, fn($u) => $u['role'] === $role));
        }
        if ($search) {
            $s = mb_strtolower($search);
            $users = array_values(array_filter($users, fn($u) =>
                str_contains(mb_strtolower($u['name']), $s) || str_contains(mb_strtolower($u['email']), $s)
            ));
        }

        Response::success($users);
    }

    /** POST /api/users */
    public static function create(): void
    {
        $auth = Middleware::requireSupervisor();
        $data = json_decode(file_get_contents('php://input'), true) ?? [];

        $v = new Validator();
        $v->required($data, ['name', 'email', 'password', 'role']);
        $v->email($data, 'email');
        $v->minLength($data, 'password', 6);
        $v->inList($data, 'role', ['tecnico', 'supervisor', 'admin']);
        if (!$v->passes()) {
            Response::error('Dados inválidos.', 422, $v->errors());
        }

        // Supervisor só pode criar técnico
        if (!Middleware::isAdmin($auth) && $data['role'] !== 'tecnico') {
            Response::error('Supervisores só podem criar usuários técnicos.', 403);
        }

        // Somente admin pode criar outro admin
        if ($data['role'] === 'admin' && !Middleware::isAdmin($auth)) {
            Response::error('Apenas administradores podem criar administradores.', 403);
        }

        if (User::getByEmail(trim($data['email']))) {
            Response::error('E-mail já cadastrado.', 409);
        }

        $ixcId = isset($data['ixc_funcionario_id']) && $data['ixc_funcionario_id'] !== ''
            ? (int)$data['ixc_funcionario_id'] : null;

        if ($ixcId !== null && User::getByIxcId($ixcId)) {
            Response::error('Já existe um usuário vinculado a este funcionário do IXC.', 409);
        }

        $id = User::create([
            'name' => trim($data['name']),
            'email' => trim($data['email']),
            'password_hash' => password_hash($data['password'], PASSWORD_BCRYPT),
            'role' => $data['role'],
            'ixc_funcionario_id' => $ixcId,
            'ativo' => $data['ativo'] ?? 1,
        ]);

        Log::create([
            'user_id' => $auth->user_id,
            'acao' => 'criar_usuario',
            'entidade' => 'user',
            'entidade_id' => $id,
            'detalhes' => ['role' => $data['role'], 'ixc_funcionario_id' => $ixcId],
            'ip' => Middleware::clientIp(),
        ]);

        Response::success(User::getById($id), 'Usuário criado com sucesso.', 201);
    }

    /** PUT /api/users/{id} — somente admin (supervisor só cria técnico). */
    public static function update(string $id): void
    {
        $auth = Middleware::requireAdmin();
        $userId = (int)$id;
        $user = User::getById($userId);
        if (!$user) {
            Response::error('Usuário não encontrado.', 404);
        }

        $data = json_decode(file_get_contents('php://input'), true) ?? [];

        $v = new Validator();
        if (isset($data['email'])) $v->email($data, 'email');
        if (isset($data['role']))   $v->inList($data, 'role', ['tecnico', 'supervisor', 'admin']);
        if (!$v->passes()) {
            Response::error('Dados inválidos.', 422, $v->errors());
        }

        // Alterações de papel que envolvem admin ficam restritas a admin
        $mexeEmAdmin = ($user['role'] === 'admin') || (($data['role'] ?? null) === 'admin');
        if ($mexeEmAdmin && !Middleware::isAdmin($auth)) {
            Response::error('Apenas administradores podem gerenciar administradores.', 403);
        }

        if (isset($data['email'])) {
            $existing = User::getByEmail(trim($data['email']));
            if ($existing && (int)$existing['id'] !== $userId) {
                Response::error('E-mail já está em uso.', 409);
            }
        }

        $upd = [];
        foreach (['name', 'email', 'role', 'ativo'] as $f) {
            if (array_key_exists($f, $data)) $upd[$f] = is_string($data[$f]) ? trim($data[$f]) : $data[$f];
        }
        if (array_key_exists('ixc_funcionario_id', $data)) {
            $ixcId = $data['ixc_funcionario_id'] !== '' && $data['ixc_funcionario_id'] !== null
                ? (int)$data['ixc_funcionario_id'] : null;
            if ($ixcId !== null) {
                $outro = User::getByIxcId($ixcId);
                if ($outro && (int)$outro['id'] !== $userId) {
                    Response::error('Já existe um usuário vinculado a este funcionário do IXC.', 409);
                }
            }
            $upd['ixc_funcionario_id'] = $ixcId;
        }

        User::update($userId, $upd);

        Log::create([
            'user_id' => $auth->user_id,
            'acao' => 'editar_usuario',
            'entidade' => 'user',
            'entidade_id' => $userId,
            'detalhes' => $upd,
            'ip' => Middleware::clientIp(),
        ]);

        Response::success(User::getById($userId), 'Usuário atualizado.');
    }

    /** POST /api/users/{id}/senha — admin (qualquer um) ou supervisor (só técnicos). */
    public static function resetPassword(string $id): void
    {
        $auth = Middleware::requireSupervisor();
        $userId = (int)$id;
        $user = User::getById($userId);
        if (!$user) {
            Response::error('Usuário não encontrado.', 404);
        }
        if ($user['role'] === 'admin' && !Middleware::isAdmin($auth)) {
            Response::error('Apenas administradores podem redefinir senha de administradores.', 403);
        }
        // Supervisor só mexe em técnico (não em outro supervisor, nem em si mesmo)
        if (!Middleware::isAdmin($auth) && $user['role'] !== 'tecnico') {
            Response::error('Supervisores só podem redefinir a senha de técnicos.', 403);
        }

        $data = json_decode(file_get_contents('php://input'), true) ?? [];
        if (empty($data['password']) || strlen($data['password']) < 6) {
            Response::error('A nova senha deve ter no mínimo 6 caracteres.', 422);
        }

        User::updatePassword($userId, password_hash($data['password'], PASSWORD_BCRYPT));
        User::deleteUserTokens($userId);

        Log::create([
            'user_id' => $auth->user_id,
            'acao' => 'resetar_senha',
            'entidade' => 'user',
            'entidade_id' => $userId,
            'ip' => Middleware::clientIp(),
        ]);

        Response::success(null, 'Senha redefinida com sucesso.');
    }

    /** DELETE /api/users/{id} — somente admin. */
    public static function destroy(string $id): void
    {
        $auth = Middleware::requireAdmin();
        $userId = (int)$id;
        $user = User::getById($userId);
        if (!$user) {
            Response::error('Usuário não encontrado.', 404);
        }
        if ($userId === (int)$auth->user_id) {
            Response::error('Você não pode excluir o próprio usuário.', 409);
        }
        if ($user['role'] === 'admin' && !Middleware::isAdmin($auth)) {
            Response::error('Apenas administradores podem excluir administradores.', 403);
        }

        // Usuário com histórico (documentos ou assinaturas) não pode ser
        // apagado: a FK travaria e o histórico legal se perderia.
        // Em vez disso, desativa (o login já bloqueia `ativo = 0`).
        $vinc = User::countVinculos($userId);
        if ($vinc['documentos'] > 0 || $vinc['assinaturas'] > 0) {
            User::update($userId, ['ativo' => 0]);
            User::deleteUserTokens($userId);

            Log::create([
                'user_id' => $auth->user_id,
                'acao' => 'desativar_usuario',
                'entidade' => 'user',
                'entidade_id' => $userId,
                'detalhes' => $vinc,
                'ip' => Middleware::clientIp(),
            ]);

            Response::success(
                User::getById($userId),
                'Usuário desativado (possui histórico e não pode ser excluído).'
            );
        }

        User::deleteUserTokens($userId);
        User::delete($userId);

        Log::create([
            'user_id' => $auth->user_id,
            'acao' => 'excluir_usuario',
            'entidade' => 'user',
            'entidade_id' => $userId,
            'ip' => Middleware::clientIp(),
        ]);

        Response::success(null, 'Usuário excluído.');
    }
}
