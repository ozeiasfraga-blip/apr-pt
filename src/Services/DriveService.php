<?php

namespace App\Services;

use Google\Client as GoogleClient;
use Google\Service\Drive as GoogleDrive;
use Google\Service\Drive\DriveFile;

/**
 * Envio do PDF final da APR/PT ao Google Drive (conta de serviço).
 *
 * Configuração (.env):
 *   GOOGLE_DRIVE_CREDENTIALS=storage/credentials/google-drive.json
 *   GOOGLE_DRIVE_FOLDER_ID=<id da pasta compartilhada com a conta de serviço>
 *
 * Sem essa configuração o envio é ignorado (o PDF local continua normal).
 * Reencerrar a mesma PT atualiza o mesmo arquivo no Drive (busca pelo uuid).
 */
class DriveService
{
    public static function credPath(): ?string
    {
        $rel = trim((string)($_ENV['GOOGLE_DRIVE_CREDENTIALS'] ?? ''));
        if ($rel === '') {
            return null;
        }
        // Aceita caminho relativo à raiz do projeto ou absoluto
        if ($rel[0] !== '/') {
            $rel = dirname(__DIR__, 2) . '/' . $rel;
        }
        return $rel;
    }

    public static function folderId(): string
    {
        return trim((string)($_ENV['GOOGLE_DRIVE_FOLDER_ID'] ?? ''));
    }

    public static function configurado(): bool
    {
        if (!class_exists(GoogleClient::class)) {
            return false;
        }
        $cred = self::credPath();
        return self::folderId() !== '' && $cred !== null && is_file($cred);
    }

    /**
     * Envia (ou atualiza) o PDF no Drive e devolve o ID do arquivo.
     * Devolve null quando o Drive não está configurado.
     */
    public static function enviarPdf(string $caminhoAbsoluto, string $nome, string $uuid): ?string
    {
        if (!self::configurado()) {
            return null;
        }
        if (!is_file($caminhoAbsoluto)) {
            throw new \RuntimeException('PDF não encontrado para envio ao Drive.');
        }

        $client = new GoogleClient();
        $client->setAuthConfig(self::credPath());
        $client->setScopes([GoogleDrive::DRIVE_FILE]);
        $service = new GoogleDrive($client);
        $pasta = self::folderId();

        $conteudo = file_get_contents($caminhoAbsoluto);
        if ($conteudo === false) {
            throw new \RuntimeException('Não foi possível ler o PDF para envio ao Drive.');
        }

        // Unidades compartilhadas: supportsAllDrives vale nas 3 chamadas,
        // mas includeItemsFromAllDrives só existe em listFiles.
        $compartilhada = ['supportsAllDrives' => true];
        $listagem = ['supportsAllDrives' => true, 'includeItemsFromAllDrives' => true];

        // Reencerrar não duplica: atualiza o arquivo que já tem este uuid
        $achados = $service->files->listFiles(array_merge($listagem, [
            'q' => sprintf(
                "'%s' in parents and appProperties has { key='uuid' and value='%s' } and trashed = false",
                str_replace("'", "\\'", $pasta),
                str_replace("'", "\\'", $uuid)
            ),
            'fields' => 'files(id)',
            'pageSize' => 1,
        ]))->getFiles();

        if (!empty($achados)) {
            $id = $achados[0]->getId();
            $service->files->update($id, new DriveFile(), array_merge($compartilhada, [
                'data' => $conteudo,
                'mimeType' => 'application/pdf',
                'uploadType' => 'multipart',
            ]));
            return $id;
        }

        $meta = new DriveFile([
            'name' => $nome,
            'parents' => [$pasta],
            'mimeType' => 'application/pdf',
            'appProperties' => ['uuid' => $uuid],
        ]);
        $criado = $service->files->create($meta, array_merge($compartilhada, [
            'data' => $conteudo,
            'mimeType' => 'application/pdf',
            'uploadType' => 'multipart',
        ]));

        return $criado->getId();
    }
}
