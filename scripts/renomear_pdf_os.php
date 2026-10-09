<?php

/**
 * Renomeia os PDFs gerados com o padrão antigo (apr_pt_<uuid>.pdf) para o
 * padrão novo com o número da OS (OS-<ixc_id>.pdf) e corrige `pdf_path`.
 *
 * Uso: php scripts/renomear_pdf_os.php
 *
 * Não mexe em PT sem OS vinculada nem em arquivo já no padrão novo.
 * O nome no Drive é atualizado pelo próximo `scripts/enviar_drive.php --all`.
 */

require_once __DIR__ . '/../src/bootstrap.php';

use App\Database;
use App\Models\DocumentoSst;
use App\Services\StorageService;

$docs = Database::getInstance()->query(
    'SELECT d.id, d.uuid, d.pdf_path, d.os_id, o.ixc_id
       FROM documentos_sst d
       JOIN ordens_servico o ON o.id = d.os_id
      WHERE d.pdf_path IS NOT NULL AND d.pdf_path <> ""
      ORDER BY d.id'
)->fetchAll();

$renomeados = $pulados = 0;

foreach ($docs as $doc) {
    $antigo = $doc['pdf_path'];
    $numero = preg_replace('/[^0-9]/', '', (string)$doc['ixc_id']);

    // Já está no padrão novo (OS-<numero>.pdf ou OS-<numero>_<uuid>.pdf)?
    if ($numero === '' || preg_match('#^pdfs/OS-' . $numero . '([_.]|\.pdf$)#', $antigo)) {
        echo "doc {$doc['id']}: já no padrão novo, ok" . PHP_EOL;
        $pulados++;
        continue;
    }

    $base = 'pdfs/OS-' . $numero;
    $novo = $base . '.pdf';

    // Outra PT já usa este nome? Desempata com o uuid (mesma regra do PdfService).
    if ($novo !== $antigo) {
        $stmt = Database::getInstance()->prepare(
            'SELECT id FROM documentos_sst WHERE pdf_path = ? AND id <> ? LIMIT 1'
        );
        $stmt->execute([$novo, (int)$doc['id']]);
        if ($stmt->fetch()) {
            $novo = $base . '_' . substr((string)$doc['uuid'], 0, 8) . '.pdf';
        }
    }

    if ($novo === $antigo) {
        $pulados++;
        continue;
    }

    $origem = StorageService::absolute($antigo);
    $destino = StorageService::absolute($novo);

    if (!is_file($origem)) {
        // O PDF ainda não existe localmente: só corrige o campo
        DocumentoSst::setPdfPath((int)$doc['id'], $novo);
        echo "doc {$doc['id']}: pdf_path -> {$novo} (arquivo local inexistente)" . PHP_EOL;
        $renomeados++;
        continue;
    }

    if (is_file($destino)) {
        fwrite(STDERR, "doc {$doc['id']}: DESTINO já existe ({$novo}) — pulado" . PHP_EOL);
        $pulados++;
        continue;
    }

    if (!rename($origem, $destino)) {
        fwrite(STDERR, "doc {$doc['id']}: falha ao renomear" . PHP_EOL);
        $pulados++;
        continue;
    }

    DocumentoSst::setPdfPath((int)$doc['id'], $novo);
    echo "doc {$doc['id']}: {$antigo}  ->  {$novo}" . PHP_EOL;
    $renomeados++;
}

echo "Concluído: {$renomeados} renomeado(s), {$pulados} mantido(s)." . PHP_EOL;
