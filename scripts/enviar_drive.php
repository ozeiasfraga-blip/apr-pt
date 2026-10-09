<?php

/**
 * Envia ao Google Drive os PDFs das PTs encerradas ainda sem cópia lá.
 *
 * Uso:
 *   php scripts/enviar_drive.php        # só as que faltam
 *   php scripts/enviar_drive.php --all  # reenvia/atualiza todas as encerradas
 */

require_once __DIR__ . '/../src/bootstrap.php';

use App\Database;
use App\Models\DocumentoSst;
use App\Services\DriveService;
use App\Services\PdfService;
use App\Services\StorageService;

if (!DriveService::configurado()) {
    fwrite(STDERR, "Drive não configurado: preencha GOOGLE_DRIVE_FOLDER_ID no .env e coloque a credencial em " . (DriveService::credPath() ?? '?') . PHP_EOL);
    exit(1);
}

$reenviarTodas = in_array('--all', $argv ?? [], true);
$where = $reenviarTodas
    ? "status = 'encerrada'"
    : "status = 'encerrada' AND (pdf_drive_id IS NULL OR pdf_drive_id = '')";

$docs = Database::getInstance()->query("SELECT * FROM documentos_sst WHERE {$where} ORDER BY id")->fetchAll();
if (!$docs) {
    echo "Nada a enviar." . PHP_EOL;
    exit(0);
}

$ok = 0;
foreach ($docs as $doc) {
    try {
        $rel = (!empty($doc['pdf_path']) && is_file(StorageService::absolute($doc['pdf_path'])))
            ? $doc['pdf_path']
            : PdfService::gerarDocumento($doc);
        $id = DriveService::enviarPdf(StorageService::absolute($rel), basename($rel), (string)$doc['uuid']);
        if ($id) {
            DocumentoSst::setPdfDriveId((int)$doc['id'], $id);
            echo "doc {$doc['id']} ({$doc['uuid']}): enviado ($id)" . PHP_EOL;
            $ok++;
        }
    } catch (\Throwable $e) {
        fwrite(STDERR, "doc {$doc['id']}: FALHOU — {$e->getMessage()}" . PHP_EOL);
    }
}
echo "Concluído: {$ok}/" . count($docs) . " enviado(s)." . PHP_EOL;
