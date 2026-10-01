<?php

namespace App\Controllers;

use App\Helpers\Response;
use App\Middleware;
use App\Services\StorageService;

class ArquivoController
{
    /**
     * GET /api/arquivo?p=assinaturas/arquivo.png
     * Serve arquivos do storage (assinaturas, PDFs) de forma autenticada.
     * A autenticação aceita Bearer ou o cookie ap_token (para uso em <img>/<a>).
     */
    public static function show(): void
    {
        Middleware::requireAuth();

        $p = $_GET['p'] ?? '';
        if ($p === '' || str_contains($p, '..')) {
            Response::error('Caminho inválido.', 400);
        }

        $base = realpath(StorageService::basePath());
        $full = realpath(StorageService::basePath() . '/' . ltrim($p, '/'));

        // Garante que o arquivo está dentro do storage
        if ($base === false || $full === false || !str_starts_with($full, $base) || !is_file($full)) {
            Response::error('Arquivo não encontrado.', 404);
        }

        // Somente extensões permitidas
        $ext = strtolower(pathinfo($full, PATHINFO_EXTENSION));
        $tipos = ['pdf' => 'application/pdf', 'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg'];
        if (!isset($tipos[$ext])) {
            Response::error('Tipo de arquivo não permitido.', 415);
        }

        header('Content-Type: ' . $tipos[$ext]);
        header('Content-Length: ' . filesize($full));
        header('Cache-Control: private, max-age=300');
        readfile($full);
        exit;
    }
}
