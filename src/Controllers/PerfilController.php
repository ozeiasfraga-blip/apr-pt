<?php

namespace App\Controllers;

use App\Models\User;
use App\Models\Log;
use App\Helpers\Response;
use App\Middleware;
use App\Services\StorageService;

/**
 * Perfil do próprio usuário logado: foto + dados retornados no login.
 *
 * A troca de senha usa POST /api/auth/change-password (AuthController).
 * A foto é servida por GET /api/arquivo?p=fotos/arquivo.png (ArquivoController),
 * que aceita Bearer ou cookie — funciona em <img>.
 */
class PerfilController
{
    private const MAX_BYTES = 2 * 1024 * 1024; // 2 MB (após decode do base64)

    /** POST /api/perfil/foto — { imagem_base64 } */
    public static function salvarFoto(): void
    {
        $authUser = Middleware::requireAuth();

        $data = json_decode(file_get_contents('php://input'), true) ?? [];
        $base64 = $data['imagem_base64'] ?? null;
        if (empty($base64)) {
            Response::error('Envie a foto em imagem_base64.', 422);
        }

        // Valida tamanho antes de gravar (base64 tem ~33% de overhead)
        $payload = $base64;
        if (preg_match('#^data:image/\w+;base64,(.*)$#s', $base64, $m)) {
            $payload = $m[1];
        }
        $bin = base64_decode($payload, true);
        if ($bin === false) {
            Response::error('Foto inválida (base64).', 422);
        }
        if (strlen($bin) > self::MAX_BYTES) {
            Response::error('Foto muito grande (máximo 2 MB).', 413);
        }
        $info = @getimagesizefromstring($bin);
        if ($info === false || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_GIF, IMAGETYPE_WEBP], true)) {
            Response::error('A foto precisa ser JPG, PNG, GIF ou WebP.', 422);
        }

        $atual = User::getById((int)$authUser->user_id);
        if (!$atual) {
            Response::error('Usuário não encontrado.', 404);
        }

        $rel = StorageService::saveBase64Image($base64, 'fotos', 'perfil_' . (int)$authUser->user_id);

        // Apaga a foto anterior (não acumula arquivo velho)
        if (!empty($atual['foto_path']) && $atual['foto_path'] !== $rel) {
            $velha = StorageService::absolute($atual['foto_path']);
            $base = realpath(StorageService::basePath());
            $real = realpath($velha);
            if ($base !== false && $real !== false && str_starts_with($real, $base) && is_file($real)) {
                @unlink($real);
            }
        }

        User::update((int)$authUser->user_id, ['foto_path' => $rel]);

        Log::create([
            'user_id' => $authUser->user_id,
            'acao' => 'perfil_foto',
            'entidade' => 'user',
            'entidade_id' => (int)$authUser->user_id,
            'ip' => Middleware::clientIp(),
        ]);

        Response::success(User::getById((int)$authUser->user_id), 'Foto atualizada.');
    }

    /** DELETE /api/perfil/foto — remove a foto (volta às iniciais) */
    public static function removerFoto(): void
    {
        $authUser = Middleware::requireAuth();

        $atual = User::getById((int)$authUser->user_id);
        if (!$atual) {
            Response::error('Usuário não encontrado.', 404);
        }

        if (!empty($atual['foto_path'])) {
            $velha = StorageService::absolute($atual['foto_path']);
            $base = realpath(StorageService::basePath());
            $real = realpath($velha);
            if ($base !== false && $real !== false && str_starts_with($real, $base) && is_file($real)) {
                @unlink($real);
            }
            User::update((int)$authUser->user_id, ['foto_path' => null]);
        }

        Response::success(User::getById((int)$authUser->user_id), 'Foto removida.');
    }
}
