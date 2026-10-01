<?php

namespace App\Services;

use App\Helpers\Response;

class StorageService
{
    /**
     * Diretório absoluto de armazenamento.
     */
    public static function basePath(): string
    {
        $relative = $_ENV['STORAGE_PATH'] ?? 'storage';
        return rtrim(dirname(__DIR__, 2) . '/' . trim($relative, '/'), '/');
    }

    /**
     * Salva uma imagem em base64 (ex.: assinatura) e devolve o caminho relativo a partir da raiz do projeto.
     */
    public static function saveBase64Image(?string $base64, string $folder, string $prefix): ?string
    {
        if (empty($base64)) {
            return null;
        }

        // Aceita "data:image/png;base64,...."
        $payload = $base64;
        $ext = 'png';
        if (preg_match('#^data:image/(\w+);base64,(.*)$#s', $base64, $m)) {
            $ext = strtolower($m[1]) === 'jpeg' ? 'jpg' : strtolower($m[1]);
            $payload = $m[2];
        }

        $binary = base64_decode($payload, true);
        if ($binary === false) {
            Response::error('Assinatura inválida (base64).', 422);
        }

        $dir = self::basePath() . '/' . trim($folder, '/');
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            Response::error('Não foi possível criar o diretório de armazenamento.', 500);
        }

        $filename = $prefix . '_' . date('YmdHis') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
        $fullPath = $dir . '/' . $filename;

        if (file_put_contents($fullPath, $binary) === false) {
            Response::error('Falha ao gravar a assinatura.', 500);
        }

        return $folder . '/' . $filename;
    }

    public static function absolute(string $relative): string
    {
        return self::basePath() . '/' . ltrim($relative, '/');
    }
}
