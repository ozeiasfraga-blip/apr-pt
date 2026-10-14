<?php

namespace App\Services;

/**
 * Google Drive via OAuth 2.0 com a conta de uma pessoa (não conta de serviço).
 *
 * Contas de serviço criadas após 15/04/2025 têm cota de armazenamento ZERO e
 * não gravam no "Meu Drive" de conta pessoal (só em unidades compartilhadas,
 * que exigem Google Workspace). Por isso o envio usa o token de um usuário
 * real: os arquivos ficam no Drive dele e usam a cota dele.
 *
 * Configuração (.env):
 *   GOOGLE_OAUTH_CLIENT_ID=...apps.googleusercontent.com
 *   GOOGLE_OAUTH_CLIENT_SECRET=...
 *   GOOGLE_OAUTH_REDIRECT_URI=https://rede.nwt.net.br/oauth/drive/callback
 *
 * Fluxo (uma única vez):
 *   GET /oauth/drive/iniciar  → tela de consentimento do Google
 *   GET /oauth/drive/callback → troca o código pelo refresh_token e grava
 *
 * Os tokens ficam em storage/credentials/google-oauth.json (fora do git).
 * Depois disso o envio é automático: o access_token é renovado sozinho.
 */
class GoogleOAuthService
{
    private const ESCOPO = 'https://www.googleapis.com/auth/drive.file';
    private const ARQUIVO_TOKEN = 'credentials/google-oauth.json';

    // ── Configuração ────────────────────────────────────────────

    public static function clientId(): string
    {
        return trim((string)($_ENV['GOOGLE_OAUTH_CLIENT_ID'] ?? ''));
    }

    public static function clientSecret(): string
    {
        return trim((string)($_ENV['GOOGLE_OAUTH_CLIENT_SECRET'] ?? ''));
    }

    public static function redirectUri(): string
    {
        return trim((string)($_ENV['GOOGLE_OAUTH_REDIRECT_URI'] ?? ''));
    }

    public static function configurado(): bool
    {
        return self::clientId() !== '' && self::clientSecret() !== '' && self::redirectUri() !== '';
    }

    public static function conectado(): bool
    {
        $t = self::tokens();
        return !empty($t['refresh_token']);
    }

    // ── Tokens (gravados fora do git) ───────────────────────────

    private static function caminho(): string
    {
        return StorageService::basePath() . '/' . self::ARQUIVO_TOKEN;
    }

    public static function tokens(): array
    {
        $arq = self::caminho();
        if (!is_file($arq)) {
            return [];
        }
        $dados = json_decode((string)file_get_contents($arq), true);
        return is_array($dados) ? $dados : [];
    }

    private static function salvarTokens(array $dados): void
    {
        $arq = self::caminho();
        $dir = dirname($arq);
        if (!is_dir($dir) && !mkdir($dir, 0770, true) && !is_dir($dir)) {
            throw new \RuntimeException('Não foi possível criar o diretório de credenciais.');
        }
        file_put_contents($arq, json_encode($dados, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        @chmod($arq, 0600);
    }

    // ── Fluxo de autorização ────────────────────────────────────

    /** URL de consentimento do Google (o "state" protege contra CSRF). */
    public static function authorizeUrl(): string
    {
        $state = bin2hex(random_bytes(16));
        self::salvarTokens(array_merge(self::tokens(), ['state' => $state]));

        return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
            'client_id' => self::clientId(),
            'redirect_uri' => self::redirectUri(),
            'response_type' => 'code',
            'scope' => self::ESCOPO,
            'access_type' => 'offline',
            'prompt' => 'consent',
            'state' => $state,
        ]);
    }

    public static function stateEsperado(): string
    {
        return (string)(self::tokens()['state'] ?? '');
    }

    /** Troca o código do consentimento por tokens. Lança RuntimeException em erro. */
    public static function trocarCodigo(string $code): void
    {
        $resp = self::post('https://oauth2.googleapis.com/token', [
            'code' => $code,
            'client_id' => self::clientId(),
            'client_secret' => self::clientSecret(),
            'redirect_uri' => self::redirectUri(),
            'grant_type' => 'authorization_code',
        ]);

        if (empty($resp['refresh_token']) && empty(self::tokens()['refresh_token'])) {
            throw new \RuntimeException('O Google não devolveu refresh_token. Revogue o acesso em '
                . 'myaccount.google.com/permissions e repita a autorização.');
        }

        self::salvarTokens([
            'access_token' => $resp['access_token'] ?? null,
            'refresh_token' => $resp['refresh_token'] ?? self::tokens()['refresh_token'] ?? null,
            'expires_at' => isset($resp['expires_in']) ? time() + (int)$resp['expires_in'] : 0,
            'token_type' => $resp['token_type'] ?? 'Bearer',
        ]);
    }

    /** Desfaz a autorização (apaga os tokens locais). */
    public static function desconectar(): void
    {
        $arq = self::caminho();
        if (is_file($arq)) {
            unlink($arq);
        }
    }

    // ── Envio ───────────────────────────────────────────────────

    /**
     * Envia (ou atualiza) o PDF no Drive e devolve o ID do arquivo.
     * Reencerrar a mesma PT atualiza o mesmo arquivo (busca pelo uuid).
     */
    public static function enviarPdf(string $caminhoAbsoluto, string $nome, string $uuid): ?string
    {
        if (!self::configurado() || !self::conectado()) {
            return null;
        }
        if (!is_file($caminhoAbsoluto)) {
            throw new \RuntimeException('PDF não encontrado para envio ao Drive.');
        }

        $pasta = DriveService::folderId();
        $token = self::accessToken();

        // Reencerrar não duplica: procura o arquivo já enviado com este uuid
        $achados = self::api(
            'GET',
            'https://www.googleapis.com/drive/v3/files?' . http_build_query([
                'q' => sprintf(
                    "appProperties has { key='uuid' and value='%s' } and trashed = false",
                    str_replace("'", "\\'", $uuid)
                ),
                'fields' => 'files(id)',
                'pageSize' => 1,
                'supportsAllDrives' => 'true',
                'includeItemsFromAllDrives' => 'true',
            ]),
            $token
        );

        $existe = $achados['files'][0]['id'] ?? null;
        $conteudo = file_get_contents($caminhoAbsoluto);
        if ($conteudo === false) {
            throw new \RuntimeException('Não foi possível ler o PDF para envio ao Drive.');
        }

        if ($existe) {
            // Atualiza conteúdo E nome (o PDF pode ter sido renomeado para OS-xxx.pdf)
            $meta = json_encode(['name' => $nome]);
            $borda = 'aprpt_' . bin2hex(random_bytes(8));
            $body = "--{$borda}\r\nContent-Type: application/json; charset=UTF-8\r\n\r\n{$meta}\r\n"
                . "--{$borda}\r\nContent-Type: application/pdf\r\n\r\n{$conteudo}\r\n"
                . "--{$borda}--";

            self::api(
                'PATCH',
                'https://www.googleapis.com/upload/drive/v3/files/' . $existe
                . '?uploadType=multipart&supportsAllDrives=true',
                $token,
                $body,
                'multipart/related; boundary=' . $borda
            );
            return $existe;
        }

        $meta = json_encode([
            'name' => $nome,
            'parents' => $pasta !== '' ? [$pasta] : [],
            'mimeType' => 'application/pdf',
            'appProperties' => ['uuid' => $uuid],
        ]);

        // multipart: metadados + conteúdo
        $borda = 'aprpt_' . bin2hex(random_bytes(8));
        $body = "--{$borda}\r\nContent-Type: application/json; charset=UTF-8\r\n\r\n{$meta}\r\n"
            . "--{$borda}\r\nContent-Type: application/pdf\r\n\r\n{$conteudo}\r\n"
            . "--{$borda}--";

        $criado = self::api(
            'POST',
            'https://www.googleapis.com/upload/drive/v3/files?uploadType=multipart&supportsAllDrives=true',
            $token,
            $body,
            'multipart/related; boundary=' . $borda
        );

        return $criado['id'] ?? null;
    }

    // ── Infra ───────────────────────────────────────────────────

    /** Devolve um access_token válido, renovando pelo refresh_token se preciso. */
    public static function accessToken(): string
    {
        $t = self::tokens();
        if (!empty($t['access_token']) && (int)($t['expires_at'] ?? 0) > time() + 60) {
            return $t['access_token'];
        }
        if (empty($t['refresh_token'])) {
            throw new \RuntimeException('Google Drive não autorizado (sem refresh_token).');
        }

        $resp = self::post('https://oauth2.googleapis.com/token', [
            'client_id' => self::clientId(),
            'client_secret' => self::clientSecret(),
            'refresh_token' => $t['refresh_token'],
            'grant_type' => 'refresh_token',
        ]);

        if (empty($resp['access_token'])) {
            throw new \RuntimeException('Falha ao renovar o token do Google Drive.');
        }

        self::salvarTokens(array_merge($t, [
            'access_token' => $resp['access_token'],
            'expires_at' => time() + (int)($resp['expires_in'] ?? 3600),
        ]));

        return $resp['access_token'];
    }

    private static function post(string $url, array $campos): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($campos),
            CURLOPT_TIMEOUT => 60,
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
        ]);
        $saida = curl_exec($ch);
        $codigo = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $dados = json_decode((string)$saida, true);
        if ($codigo < 200 || $codigo >= 300) {
            $motivo = $dados['error_description'] ?? $dados['error']['message'] ?? ($saida ?: 'sem resposta');
            throw new \RuntimeException('Google OAuth HTTP ' . $codigo . ': ' . $motivo);
        }
        return is_array($dados) ? $dados : [];
    }

    private static function api(string $metodo, string $url, string $token, ?string $corpo = null, ?string $contentType = null): array
    {
        $ch = curl_init($url);
        $headers = ['Authorization: Bearer ' . $token];
        if ($corpo !== null) {
            $headers[] = 'Content-Type: ' . ($contentType ?: 'application/json');
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $metodo,
            CURLOPT_POSTFIELDS => $corpo,
            CURLOPT_TIMEOUT => 120,
            CURLOPT_HTTPHEADER => $headers,
        ]);
        $saida = curl_exec($ch);
        $codigo = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $dados = json_decode((string)$saida, true);
        if ($codigo < 200 || $codigo >= 300) {
            $motivo = $dados['error']['message'] ?? ($saida ?: 'sem resposta');
            throw new \RuntimeException('Google Drive HTTP ' . $codigo . ': ' . $motivo);
        }
        return is_array($dados) ? $dados : [];
    }
}
