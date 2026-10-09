<?php

/**
 * Callback do OAuth do Google Drive (APR/PT).
 *
 * Servido em https://rede.nwt.net.br/oauth/... (proxy Apache para a porta 8089),
 * porque o Google exige HTTPS no endereço de retorno.
 *
 * Rota:
 *   /oauth/drive/callback → troca o código pelo refresh_token
 *
 * A URL de consentimento é gerada por GET /api/drive/oauth-url (só admin),
 * então quem inicia o fluxo é sempre um administrador logado no app.
 */

require_once __DIR__ . '/../../src/bootstrap.php';

use App\Services\GoogleOAuthService;
use App\Services\DriveService;

$uri = $_SERVER['REQUEST_URI'] ?? '/';
$uri = strtok($uri, '?');
$uri = rtrim($uri, '/') ?: '/';

function pagina(string $titulo, string $corpo, string $tom = 'ok'): void
{
    $borda = $tom === 'ok' ? '#2e7d32' : '#c62828';
    http_response_code($tom === 'ok' ? 200 : 400);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html lang="pt-BR"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<title>' . htmlspecialchars($titulo) . '</title></head>'
        . '<body style="font-family:system-ui,sans-serif;max-width:520px;margin:60px auto;padding:0 20px;'
        . 'border:2px solid ' . $borda . ';border-radius:12px">'
        . '<h1 style="font-size:22px;margin-top:0">' . htmlspecialchars($titulo) . '</h1>'
        . '<p style="line-height:1.6">' . $corpo . '</p>'
        . '<p style="color:#666;font-size:14px">Você já pode fechar esta aba.</p>'
        . '</body></html>';
    exit;
}

switch ($uri) {
    case '/oauth/drive/callback':
        if (isset($_GET['error'])) {
            pagina('Autorização recusada', 'O Google devolveu: <b>'
                . htmlspecialchars((string)$_GET['error']) . '</b>.', 'erro');
        }

        $state = (string)($_GET['state'] ?? '');
        $code = (string)($_GET['code'] ?? '');

        if ($state === '' || $state !== GoogleOAuthService::stateEsperado()) {
            pagina('Estado inválido', 'O parâmetro de segurança não bate. Inicie a conexão '
                . 'de novo pela tela do APR/PT.', 'erro');
        }

        if ($code === '') {
            pagina('Código ausente', 'O Google não enviou o código de autorização.', 'erro');
        }

        try {
            GoogleOAuthService::trocarCodigo($code);
            $pasta = DriveService::folderId();
            pagina(
                'Google Drive conectado',
                'Pronto! Os PDFs das PTs encerradas serão enviados automaticamente'
                . ($pasta !== '' ? ' para a pasta <b>' . htmlspecialchars($pasta) . '</b>' : '')
                . '.'
            );
        } catch (\Throwable $e) {
            pagina('Falha ao conectar', htmlspecialchars($e->getMessage()), 'erro');
        }

    default:
        pagina('Rota não encontrada', 'Use /oauth/drive/iniciar ou /oauth/drive/callback.', 'erro');
}
