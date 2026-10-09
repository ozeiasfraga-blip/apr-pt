<?php

namespace App\Controllers;

use App\Services\GoogleOAuthService;
use App\Services\DriveService;
use App\Services\RcloneService;
use App\Helpers\Response;
use App\Middleware;

/**
 * Status e conexão do Google Drive (só admin).
 *
 * A URL de consentimento é gerada aqui (exige admin logado) e o callback
 * /oauth/drive/callback valida o "state" para confirmar que veio do fluxo
 * iniciado por este servidor.
 */
class DriveController
{
    /**
     * GET /api/drive/status
     */
    public static function status(): void
    {
        Middleware::requireAdmin();

        Response::success([
            'oauth_configurado' => GoogleOAuthService::configurado(),
            'oauth_conectado' => GoogleOAuthService::conectado(),
            'pasta_id' => DriveService::folderId(),
            'servico_configurado' => DriveService::configurado(),
            'rclone_configurado' => RcloneService::configurado(),
            'arquivo' => 'pdf_drive_id em documentos_sst',
        ], 'ok');
    }

    /**
     * GET /api/drive/oauth-url — devolve a URL de consentimento do Google.
     */
    public static function oauthUrl(): void
    {
        Middleware::requireAdmin();

        if (!GoogleOAuthService::configurado()) {
            Response::error('Google Drive não configurado: preencha GOOGLE_OAUTH_CLIENT_ID e '
                . 'GOOGLE_OAUTH_CLIENT_SECRET no .env.', 409);
        }

        Response::success(['url' => GoogleOAuthService::authorizeUrl()], 'ok');
    }

    /**
     * DELETE /api/drive/oauth — desfaz a autorização local.
     */
    public static function desconectar(): void
    {
        Middleware::requireAdmin();

        GoogleOAuthService::desconectar();
        Response::success(['oauth_conectado' => false], 'Google Drive desconectado.');
    }
}
