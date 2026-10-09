<?php

/**
 * Verifica a 2ª cópia (Google Drive) de TODAS as PTs encerradas e repara o que
 * faltar. É o "seguro" do backup: roda por cron.
 *
 * Para cada PT encerrada ele checa:
 *   1. existe `pdf_drive_id` gravado?
 *   2. o arquivo ainda existe no Drive? (alguém pode ter apagado a pasta)
 *   3. o nome no Drive é o mesmo do servidor? (OS-<ixc_id>.pdf)
 *
 * O que estiver errado é **reenviado na hora** e o resultado é registrado em
 * `log_auditoria`. Falha geral (sem token, sem rede) também é registrada.
 *
 * Uso:
 *   php scripts/verificar_drive.php          # verifica e repara
 *   php scripts/verificar_drive.php --dry-run  # só mostra, não reenvia
 */

require_once __DIR__ . '/../src/bootstrap.php';

use App\Database;
use App\Models\DocumentoSst;
use App\Models\Log;
use App\Services\DriveService;
use App\Services\GoogleOAuthService;
use App\Services\RcloneService;
use App\Services\StorageService;

$dryRun = in_array('--dry-run', $argv ?? [], true);
$saida = function (string $txt) {
    echo $txt . PHP_EOL;
};

// ── Nuvem configurada? ─────────────────────────────────────────
if (!GoogleOAuthService::conectado() && !DriveService::configurado() && !RcloneService::configurado()) {
    $msg = 'verificar_drive: nenhuma nuvem configurada';
    fwrite(STDERR, $msg . PHP_EOL);
    if (!$dryRun) {
        Log::create(['acao' => 'drive_verificacao_falha', 'entidade' => 'documentos_sst',
            'detalhes' => ['motivo' => $msg]]);
    }
    exit(1);
}

// ── Estado atual no Drive (1 chamada só) ───────────────────────
$noDrive = [];   // uuid => ['id' => ..., 'nome' => ...]
$erroDrive = null;

if (GoogleOAuthService::conectado()) {
    try {
        $ref = new ReflectionMethod(\App\Services\GoogleOAuthService::class, 'api');
        $ref->setAccessible(true);
        $token = GoogleOAuthService::tokens()['access_token'] ?? '';
        if ($token === '') {
            // força a renovação do access_token
            $force = new ReflectionMethod(\App\Services\GoogleOAuthService::class, 'accessToken');
            $force->setAccessible(true);
            $token = $force->invoke(null);
        }

        $resp = $ref->invoke(null, 'GET', 'https://www.googleapis.com/drive/v3/files?' . http_build_query([
            'q' => 'trashed = false',
            'fields' => 'files(id,name,appProperties)',
            'pageSize' => 1000,
            'supportsAllDrives' => 'true',
            'includeItemsFromAllDrives' => 'true',
        ]), $token);

        foreach ($resp['files'] as $f) {
            $uuid = $f['appProperties']['uuid'] ?? null;
            if ($uuid) {
                $noDrive[$uuid] = ['id' => $f['id'], 'nome' => $f['name']];
            }
        }
    } catch (\Throwable $e) {
        $erroDrive = $e->getMessage();
    }
}

if ($erroDrive !== null) {
    $msg = 'verificar_drive: não consegui listar o Drive — ' . $erroDrive;
    fwrite(STDERR, $msg . PHP_EOL);
    if (!$dryRun) {
        Log::create(['acao' => 'drive_verificacao_falha', 'entidade' => 'documentos_sst',
            'detalhes' => ['motivo' => $erroDrive]]);
    }
    // Sem conseguir ler o Drive não dá para julgar nada: aborta sem "reparar"
    // por cima (evitaria apagar cópias boas com base em leitura errada).
    exit(2);
}

// ── Conferência PT por PT ──────────────────────────────────────
$docs = Database::getInstance()->query(
    "SELECT d.id, d.uuid, d.pdf_path, d.pdf_drive_id, d.encerrado_em, o.ixc_id
       FROM documentos_sst d
       LEFT JOIN ordens_servico o ON o.id = d.os_id
      WHERE d.status = 'encerrada'
      ORDER BY d.id"
)->fetchAll();

$total = count($docs);
$semNuvem = 0;
$reparados = 0;
$ok = 0;
$problemas = [];

foreach ($docs as $doc) {
    $uuid = (string)$doc['uuid'];
    $esperado = $doc['pdf_path'] ? basename($doc['pdf_path']) : '';
    $achei = $noDrive[$uuid] ?? null;

    $faltaId = ($doc['pdf_drive_id'] ?? '') === '';
    $faltaArquivo = $achei === null;
    $nomeDiferente = $achei !== null && $esperado !== '' && $achei['nome'] !== $esperado;

    if (!$faltaId && !$faltaArquivo && !$nomeDiferente) {
        $ok++;
        continue;
    }

    $motivo = [];
    if ($faltaId) {
        $motivo[] = 'sem pdf_drive_id';
    }
    if ($faltaArquivo) {
        $motivo[] = 'apagado do Drive';
    }
    if ($nomeDiferente) {
        $motivo[] = 'nome "' . ($achei['nome'] ?? '') . '" != "' . $esperado . '"';
    }
    $motivo = implode('; ', $motivo);
    $problemas[] = "doc {$doc['id']} (OS " . ($doc['ixc_id'] ?? '?') . "): {$motivo}";
    $semNuvem++;

    if ($dryRun) {
        $saida("[dry-run] doc {$doc['id']}: CORRIGIRIA — {$motivo}");
        continue;
    }

    // Repara: gera o PDF se sumiu do disco e reenvia (atualiza conteúdo e nome)
    try {
        $rel = (!empty($doc['pdf_path']) && is_file(StorageService::absolute($doc['pdf_path'])))
            ? $doc['pdf_path']
            : \App\Services\PdfService::gerarDocumento($doc);
        if ($rel !== ($doc['pdf_path'] ?? '')) {
            DocumentoSst::setPdfPath((int)$doc['id'], $rel);
        }

        $id = GoogleOAuthService::enviarPdf(StorageService::absolute($rel), basename($rel), $uuid)
            ?? DriveService::enviarPdf(StorageService::absolute($rel), basename($rel), $uuid)
            ?? RcloneService::enviarPdf(StorageService::absolute($rel), basename($rel), $uuid);

        if ($id) {
            DocumentoSst::setPdfDriveId((int)$doc['id'], $id);
            $reparados++;
            $saida("doc {$doc['id']}: REPARADO ({$motivo}) -> {$id}");

            Log::create([
                'acao' => 'drive_backup_reparado',
                'entidade' => 'documentos_sst',
                'entidade_id' => (int)$doc['id'],
                'detalhes' => ['motivo' => $motivo, 'drive_id' => $id],
            ]);
        } else {
            $problemas[] = "  ^ doc {$doc['id']}: NENHUM backend aceitou o envio";
            $saida("doc {$doc['id']}: FALHOU — nenhum backend aceitou o envio");
        }
    } catch (\Throwable $e) {
        $problemas[] = "  ^ doc {$doc['id']}: {$e->getMessage()}";
        fwrite(STDERR, "doc {$doc['id']}: FALHOU — {$e->getMessage()}" . PHP_EOL);
        if (!$dryRun) {
            Log::create([
                'acao' => 'drive_backup_reparo_falhou',
                'entidade' => 'documentos_sst',
                'entidade_id' => (int)$doc['id'],
                'detalhes' => ['motivo' => $e->getMessage()],
            ]);
        }
    }
}

// ── Resumo ─────────────────────────────────────────────────────
$saida(sprintf(
    'Conferência: %d encerradas | %d íntegras | %d com problema | %d reparado(s)%s',
    $total,
    $ok,
    $semNuvem,
    $reparados,
    $dryRun ? ' [dry-run]' : ''
));

if ($problemas && $dryRun) {
    $saida('--- o que seria feito ---');
    foreach ($problemas as $p) {
        $saida($p);
    }
}

// Código 3 = ainda tem problema depois do reparo (o cron registra no log)
exit(($semNuvem - $reparados) > 0 ? 3 : 0);
