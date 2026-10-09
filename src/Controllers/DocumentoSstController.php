<?php

namespace App\Controllers;

use App\Models\DocumentoSst;
use App\Models\Assinatura;
use App\Models\OrdemServico;
use App\Models\User;
use App\Models\Log;
use App\Services\StorageService;
use App\Services\PdfService;
use App\Services\DriveService;
use App\Services\RcloneService;
use App\Services\GoogleOAuthService;
use App\Helpers\Response;
use App\Helpers\Validator;
use App\Middleware;

class DocumentoSstController
{
    /**
     * GET /api/documentos?status=&tecnico_id=&os_id=
     */
    public static function index(): void
    {
        $authUser = Middleware::requireAuth();
        $filters = [
            'status' => $_GET['status'] ?? null,
            'os_id' => $_GET['os_id'] ?? null,
            'date' => $_GET['date'] ?? null,
            'search' => $_GET['search'] ?? null,
        ];

        if ($authUser->role === 'tecnico') {
            $filters['tecnico_id'] = (int)$authUser->user_id;
        } elseif (!empty($_GET['tecnico_id'])) {
            $filters['tecnico_id'] = (int)$_GET['tecnico_id'];
        }

        Response::success(DocumentoSst::list($filters));
    }

    /** GET /api/documentos/resumo — contagem por status (foco APR/PT). */
    public static function resumo(): void
    {
        $authUser = Middleware::requireAuth();
        $tecnicoId = $authUser->role === 'tecnico' ? (int)$authUser->user_id : null;
        Response::success(DocumentoSst::contarPorStatus($tecnicoId));
    }

    public static function show(string $id): void
    {
        $authUser = Middleware::requireAuth();
        $doc = DocumentoSst::getById((int)$id);
        if (!$doc) {
            Response::error('Documento não encontrado.', 404);
        }
        self::autorizar($authUser, $doc);

        $doc['assinaturas'] = Assinatura::listByDocumento((int)$doc['id']);
        $doc['os'] = OrdemServico::getById((int)$doc['os_id']);

        Response::success($doc);
    }

    /**
     * POST /api/documentos — cria/atualiza de forma idempotente (uuid gerado no app).
     */
    public static function store(): void
    {
        $authUser = Middleware::requireAuth();
        $data = json_decode(file_get_contents('php://input'), true) ?? [];

        $v = new Validator();
        $v->required($data, ['uuid', 'os_id']);
        if (!$v->passes()) {
            Response::error('Dados inválidos.', 422, $v->errors());
        }

        $os = OrdemServico::getById((int)$data['os_id']);
        if (!$os) {
            Response::error('OS não encontrada. Sincronize as ordens antes de criar o documento.', 404);
        }

        // Técnico só cria para si mesmo
        if ($authUser->role === 'tecnico') {
            $tecnicoId = (int)$authUser->user_id;
            if ((int)$os['id_tecnico'] !== (int)($authUser->ixc_funcionario_id ?? 0)) {
                Response::error('Acesso negado a esta OS.', 403);
            }
        } else {
            $tecnicoId = (int)($data['tecnico_id'] ?? 0);
            if ($tecnicoId <= 0 && !empty($os['id_tecnico'])) {
                // Resolve o usuário do técnico vinculado ao funcionário do IXC
                $tecnicoUser = \App\Models\User::getByIxcId((int)$os['id_tecnico']);
                $tecnicoId = $tecnicoUser ? (int)$tecnicoUser['id'] : 0;
            }
            if ($tecnicoId <= 0) {
                Response::error('Não foi possível identificar o técnico desta OS. Cadastre o técnico com o vínculo ao funcionário do IXC (ou informe tecnico_id) antes de preencher a APR/PT.', 422);
            }
        }

        // Data/hora preenchidas automaticamente pelo sistema
        if (empty($data['validade_inicio'])) {
            $data['validade_inicio'] = date('Y-m-d H:i:s');
        }
        if (empty($data['validade_fim'])) {
            $horas = 8;
            try {
                $stmt = \App\Database::getInstance()->prepare("SELECT valor FROM config_textos WHERE chave = 'validade_horas'");
                $stmt->execute();
                $h = $stmt->fetchColumn();
                if ($h !== false && $h !== null && $h !== '') {
                    $horas = (int)$h;
                }
            } catch (\Throwable $e) {}
            $data['validade_fim'] = date('Y-m-d H:i:s', strtotime($data['validade_inicio'] . " +{$horas} hours"));
        }

        $id = DocumentoSst::createOrUpdateByUuid([
            'uuid' => $data['uuid'],
            'os_id' => (int)$data['os_id'],
            'tecnico_id' => $tecnicoId,
            'status' => $data['status'] ?? 'rascunho',
            'nr10' => $data['nr10'] ?? false,
            'nr35' => $data['nr35'] ?? false,
            'normas' => $data['normas'] ?? null,
            'riscos' => $data['riscos'] ?? null,
            'epis' => $data['epis'] ?? null,
            'epcs' => $data['epcs'] ?? null,
            'checklist' => $data['checklist'] ?? null,
            'ferramentas' => $data['ferramentas'] ?? null,
            'medidas' => $data['medidas'] ?? null,
            'restricoes' => $data['restricoes'] ?? null,
            'treinamento_nr10' => $data['treinamento_nr10'] ?? false,
            'treinamento_nr35' => $data['treinamento_nr35'] ?? false,
            'aso_apto' => $data['aso_apto'] ?? false,
            'atividades' => $data['atividades'] ?? null,
            'local_atividade' => $data['local_atividade'] ?? null,
            'responsavel_apr' => $data['responsavel_apr'] ?? null,
            'equipe_envolvida' => $data['equipe_envolvida'] ?? null,
            'etapas' => $data['etapas'] ?? null,
            'observacoes' => $data['observacoes'] ?? null,
            'validade_inicio' => $data['validade_inicio'] ?? null,
            'validade_fim' => $data['validade_fim'] ?? null,
        ]);

        $doc = DocumentoSst::getById($id);
        $hash = DocumentoSst::calcularHash($doc);
        DocumentoSst::setHash($id, $hash);

        // Equipe envolvida (registro e assinatura individual) — só enquanto editável
        if ($doc['status'] === 'rascunho' && isset($data['equipe']) && is_array($data['equipe'])) {
            DocumentoSst::limparEquipe($id);
            foreach (array_values($data['equipe']) as $i => $m) {
                $path = null;
                if (!empty($m['assinatura_base64'])) {
                    $path = StorageService::saveBase64Image($m['assinatura_base64'], 'assinaturas', 'eq' . $id . '_' . ($i + 1));
                } elseif (!empty($m['assinatura_path'])) {
                    $path = $m['assinatura_path'];
                }
                DocumentoSst::addEquipe($id, $i + 1, $m['nome'] ?? null, $m['funcao'] ?? null, $m['cpf'] ?? null, $path);
            }
        }

        Log::create([
            'user_id' => $authUser->user_id,
            'acao' => 'documento_salvar',
            'entidade' => 'documentos_sst',
            'entidade_id' => $id,
            'detalhes' => ['uuid' => $data['uuid'], 'status' => $doc['status']],
            'ip' => Middleware::clientIp(),
        ]);

        Response::success(DocumentoSst::getById($id), 'Documento salvo com sucesso.', 201);
    }

    /**
     * POST /api/documentos/{id}/assinar — assinatura do técnico.
     */
    public static function assinar(string $id): void
    {
        $authUser = Middleware::requireAuth();
        $doc = DocumentoSst::getById((int)$id);
        if (!$doc) {
            Response::error('Documento não encontrado.', 404);
        }
        self::autorizar($authUser, $doc);

        if (!in_array($doc['status'], ['rascunho', 'aguardando_aprovacao'], true)) {
            Response::error('Documento não pode mais ser assinado pelo técnico (status: ' . $doc['status'] . ').', 409);
        }
        if (Assinatura::existe((int)$doc['id'], 'tecnico')) {
            Response::error('O técnico já assinou este documento.', 409);
        }

        $data = json_decode(file_get_contents('php://input'), true) ?? [];

        $imagemPath = StorageService::saveBase64Image(
            $data['imagem_base64'] ?? null,
            'assinaturas',
            'tec_' . $doc['uuid']
        );

        $hash = DocumentoSst::calcularHash(array_merge($doc, ['assinatura_tecnico' => (string)time()]));

        Assinatura::create([
            'documento_id' => (int)$doc['id'],
            'tipo' => 'tecnico',
            'user_id' => (int)$authUser->user_id,
            'imagem_path' => $imagemPath,
            'ip' => Middleware::clientIp(),
            'latitude' => $data['latitude'] ?? null,
            'longitude' => $data['longitude'] ?? null,
            'dispositivo' => $data['dispositivo'] ?? null,
            'hash' => $hash,
        ]);

        DocumentoSst::setHash((int)$doc['id'], $hash);
        DocumentoSst::updateStatus((int)$doc['id'], 'aguardando_aprovacao');

        Log::create([
            'user_id' => $authUser->user_id,
            'acao' => 'documento_assinar_tecnico',
            'entidade' => 'documentos_sst',
            'entidade_id' => (int)$doc['id'],
            'ip' => Middleware::clientIp(),
        ]);

        Response::success(DocumentoSst::getById((int)$doc['id']), 'Assinatura registrada. Aguardando aprovação do supervisor.');
    }

    /**
     * POST /api/documentos/{id}/aprovar — PT: liberação pelo Supervisor/Emissor.
     * body: imagem_base64, pt_autorizacao (AUTORIZADA|NAO_AUTORIZADA), pt_supervisor_nome, pt_supervisor_registro
     */
    public static function aprovar(string $id): void
    {
        $authUser = Middleware::requireSupervisor();
        $doc = DocumentoSst::getById((int)$id);
        if (!$doc) {
            Response::error('Documento não encontrado.', 404);
        }
        if ($doc['status'] !== 'aguardando_aprovacao') {
            Response::error('Documento não está aguardando aprovação (status: ' . $doc['status'] . ').', 409);
        }

        $data = json_decode(file_get_contents('php://input'), true) ?? [];

        $autorizacao = strtoupper((string)($data['pt_autorizacao'] ?? 'AUTORIZADA'));
        if (!in_array($autorizacao, ['AUTORIZADA', 'NAO_AUTORIZADA'], true)) {
            Response::error('Status da permissão inválido.', 422);
        }

        $emissor = User::getById((int)$authUser->user_id);
        $nome = trim((string)($data['pt_supervisor_nome'] ?? '')) ?: ($emissor['name'] ?? null);
        $registro = trim((string)($data['pt_supervisor_registro'] ?? '')) ?: null;
        if (!$registro) {
            Response::error('Selecione o cargo do supervisor/emissor para autorizar a PT.', 422);
        }

        $imagemPath = StorageService::saveBase64Image(
            $data['imagem_base64'] ?? null,
            'assinaturas',
            'sup_' . $doc['uuid']
        );

        $assinatura = Assinatura::create([
            'documento_id' => (int)$doc['id'],
            'tipo' => 'supervisor',
            'user_id' => (int)$authUser->user_id,
            'imagem_path' => $imagemPath,
            'ip' => Middleware::clientIp(),
            'latitude' => $data['latitude'] ?? null,
            'longitude' => $data['longitude'] ?? null,
            'dispositivo' => $data['dispositivo'] ?? null,
        ]);

        DocumentoSst::setSupervisor((int)$doc['id'], (int)$authUser->user_id);
        DocumentoSst::setPtLiberacao((int)$doc['id'], $autorizacao, $nome, $registro);
        DocumentoSst::updateStatus((int)$doc['id'], $autorizacao === 'AUTORIZADA' ? 'liberada' : 'cancelada');

        $doc['supervisor_id'] = (int)$authUser->user_id;
        DocumentoSst::setHash((int)$doc['id'], DocumentoSst::calcularHash($doc));

        try {
            $pdfPath = PdfService::gerarDocumento(DocumentoSst::getById((int)$doc['id']));
            DocumentoSst::setPdfPath((int)$doc['id'], $pdfPath);
        } catch (\Throwable $e) {
            error_log('Falha ao gerar PDF do documento ' . $doc['id'] . ': ' . $e->getMessage());
        }

        Log::create([
            'user_id' => $authUser->user_id,
            'acao' => 'documento_liberacao_pt',
            'entidade' => 'documentos_sst',
            'entidade_id' => (int)$doc['id'],
            'detalhes' => ['autorizacao' => $autorizacao, 'assinatura_id' => $assinatura],
            'ip' => Middleware::clientIp(),
        ]);

        Response::success(
            DocumentoSst::getById((int)$doc['id']),
            $autorizacao === 'AUTORIZADA' ? 'PT autorizada pelo supervisor.' : 'PT NÃO autorizada.'
        );
    }

    /**
     * POST /api/documentos/{id}/encerrar — encerramento da PT pelo supervisor.
     * body: imagem_base64, pt_encerramento_status (CONCLUIDO|SUSPENSO), encerramento_obs
     */
    public static function encerrar(string $id): void
    {
        $authUser = Middleware::requireSupervisor();
        $doc = DocumentoSst::getById((int)$id);
        if (!$doc) {
            Response::error('Documento não encontrado.', 404);
        }

        if (!in_array($doc['status'], ['liberada', 'em_execucao'], true)) {
            Response::error('Somente PT liberadas podem ser encerradas (status: ' . $doc['status'] . ').', 409);
        }

        $data = json_decode(file_get_contents('php://input'), true) ?? [];
        $ptStatus = strtoupper((string)($data['pt_encerramento_status'] ?? 'CONCLUIDO'));
        if (!in_array($ptStatus, ['CONCLUIDO', 'SUSPENSO'], true)) {
            Response::error('Status de encerramento inválido.', 422);
        }
        $obs = $data['encerramento_obs'] ?? null;

        $imagemPath = StorageService::saveBase64Image(
            $data['imagem_base64'] ?? null,
            'assinaturas',
            'supenc_' . $doc['uuid']
        );

        $assinatura = Assinatura::create([
            'documento_id' => (int)$doc['id'],
            'tipo' => 'supervisor_encerramento',
            'user_id' => (int)$authUser->user_id,
            'imagem_path' => $imagemPath,
            'ip' => Middleware::clientIp(),
            'latitude' => $data['latitude'] ?? null,
            'longitude' => $data['longitude'] ?? null,
            'dispositivo' => $data['dispositivo'] ?? null,
        ]);

        $concluido = $ptStatus === 'CONCLUIDO';
        DocumentoSst::marcarEncerrado(
            (int)$doc['id'],
            $concluido ? 1 : 0,
            $obs,
            $ptStatus,
            $concluido ? 'encerrada' : 'cancelada'
        );

        try {
            $pdfPath = PdfService::gerarDocumento(DocumentoSst::getById((int)$doc['id']));
            DocumentoSst::setPdfPath((int)$doc['id'], $pdfPath);
            // Segunda cópia na nuvem. Tenta nesta ordem: OAuth com conta real
            // (funciona em qualquer conta), conta de serviço (só Workspace /
            // unidades compartilhadas) e rclone. Ignora se nada estiver configurado.
            $nuvemId = GoogleOAuthService::enviarPdf(
                StorageService::absolute($pdfPath),
                basename($pdfPath),
                (string)$doc['uuid']
            ) ?? DriveService::enviarPdf(
                StorageService::absolute($pdfPath),
                basename($pdfPath),
                (string)$doc['uuid']
            ) ?? RcloneService::enviarPdf(
                StorageService::absolute($pdfPath),
                basename($pdfPath),
                (string)$doc['uuid']
            );
            if ($nuvemId) {
                DocumentoSst::setPdfDriveId((int)$doc['id'], $nuvemId);
            }
        } catch (\Throwable $e) {
            error_log('Falha ao gerar PDF no encerramento ' . $doc['id'] . ': ' . $e->getMessage());
        }

        Log::create([
            'user_id' => $authUser->user_id,
            'acao' => 'documento_encerramento_pt',
            'entidade' => 'documentos_sst',
            'entidade_id' => (int)$doc['id'],
            'detalhes' => ['status' => $ptStatus, 'assinatura_id' => $assinatura],
            'ip' => Middleware::clientIp(),
        ]);

        Response::success(
            DocumentoSst::getById((int)$doc['id']),
            $concluido ? 'PT encerrada com sucesso.' : 'PT encerrada como suspensa/cancelada.'
        );
    }

    /**
     * GET /api/documentos/{id}/pdf
     */
    public static function pdf(string $id): void
    {
        $authUser = Middleware::requireAuth();
        $doc = DocumentoSst::getById((int)$id);
        if (!$doc) {
            Response::error('Documento não encontrado.', 404);
        }
        self::autorizar($authUser, $doc);

        try {
            $path = PdfService::gerarDocumento($doc);
            DocumentoSst::setPdfPath((int)$doc['id'], $path);
        } catch (\Throwable $e) {
            Response::error('Falha ao gerar PDF: ' . $e->getMessage(), 500);
        }

        $full = StorageService::absolute($path);
        if (!is_file($full)) {
            Response::error('PDF indisponível.', 500);
        }

        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="' . basename($path) . '"');
        header('Content-Length: ' . filesize($full));
        readfile($full);
        exit;
    }

    /**
     * POST /api/documentos/sync
     *
     * Recebe um documento preenchido no aparelho (mesmo sem internet) e aplica
     * tudo no servidor de forma idempotente: a chave é o `uuid` gerado no app,
     * então o mesmo documento pode ser reenviado sem duplicar.
     */
    public static function sincronizar(): void
    {
        $authUser = Middleware::requireAuth();
        $data = json_decode(file_get_contents('php://input'), true) ?? [];

        $uuid = trim((string)($data['uuid'] ?? ''));
        $osId = (int)($data['os_id'] ?? 0);
        if ($uuid === '' || $osId <= 0) {
            Response::error('Dados inválidos (uuid/os_id).', 422);
        }

        $os = OrdemServico::getById($osId);
        if (!$os) {
            Response::error('OS não encontrada no servidor. Sincronize as ordens do IXC antes de enviar.', 409);
        }

        // Quem é o técnico responsável pelo documento
        if ($authUser->role === 'tecnico') {
            if ((int)$os['id_tecnico'] !== (int)($authUser->ixc_funcionario_id ?? 0)) {
                Response::error('Acesso negado a esta OS.', 403);
            }
            $tecnicoId = (int)$authUser->user_id;
        } else {
            $tecnicoId = (int)($data['tecnico_id'] ?? 0);
            if ($tecnicoId <= 0 && !empty($os['id_tecnico'])) {
                $tecnicoUser = User::getByIxcId((int)$os['id_tecnico']);
                $tecnicoId = $tecnicoUser ? (int)$tecnicoUser['id'] : 0;
            }
            if ($tecnicoId <= 0) {
                Response::error('Não foi possível identificar o técnico desta OS.', 422);
            }
        }

        $campos = is_array($data['campos'] ?? null) ? $data['campos'] : [];
        $status = (string)($data['status'] ?? 'rascunho');
        if (!in_array($status, ['rascunho', 'aguardando_aprovacao', 'liberada', 'em_execucao', 'encerrada', 'cancelada'], true)) {
            $status = 'rascunho';
        }

        // Validade: mantém a do aparelho (preenchimento offline); senão calcula agora
        if (empty($campos['validade_inicio'])) {
            $campos['validade_inicio'] = date('Y-m-d H:i:s');
        }
        if (empty($campos['validade_fim'])) {
            $horas = 8;
            try {
                $stmt = \App\Database::getInstance()->prepare("SELECT valor FROM config_textos WHERE chave = 'validade_horas'");
                $stmt->execute();
                $h = $stmt->fetchColumn();
                if ($h !== false && $h !== null && $h !== '') {
                    $horas = (int)$h;
                }
            } catch (\Throwable $e) {}
            $campos['validade_fim'] = date('Y-m-d H:i:s', strtotime($campos['validade_inicio'] . " +{$horas} hours"));
        }

        $id = DocumentoSst::createOrUpdateByUuid([
            'uuid' => $uuid,
            'os_id' => $osId,
            'tecnico_id' => $tecnicoId,
            'status' => $status,
            'nr10' => $campos['nr10'] ?? false,
            'nr35' => $campos['nr35'] ?? false,
            'normas' => $campos['normas'] ?? null,
            'riscos' => $campos['riscos'] ?? null,
            'epis' => $campos['epis'] ?? null,
            'epcs' => $campos['epcs'] ?? null,
            'checklist' => $campos['checklist'] ?? null,
            'ferramentas' => $campos['ferramentas'] ?? null,
            'medidas' => $campos['medidas'] ?? null,
            'restricoes' => $campos['restricoes'] ?? null,
            'treinamento_nr10' => $campos['treinamento_nr10'] ?? false,
            'treinamento_nr35' => $campos['treinamento_nr35'] ?? false,
            'aso_apto' => $campos['aso_apto'] ?? false,
            'atividades' => $campos['atividades'] ?? null,
            'local_atividade' => $campos['local_atividade'] ?? null,
            'responsavel_apr' => $campos['responsavel_apr'] ?? null,
            'equipe_envolvida' => $campos['equipe_envolvida'] ?? null,
            'etapas' => $campos['etapas'] ?? null,
            'observacoes' => $campos['observacoes'] ?? null,
            'validade_inicio' => $campos['validade_inicio'],
            'validade_fim' => $campos['validade_fim'],
        ]);

        // Preserva a data/hora em que o documento foi criado no aparelho
        $criadoEm = self::dataHoraCliente($data['criado_em'] ?? null);
        if ($criadoEm) {
            DocumentoSst::setCriadoEm($id, $criadoEm);
        }

        // Equipe envolvida + assinaturas dos integrantes
        if (isset($data['equipe']) && is_array($data['equipe'])) {
            DocumentoSst::limparEquipe($id);
            foreach (array_values($data['equipe']) as $i => $m) {
                $path = null;
                if (!empty($m['assinatura_base64'])) {
                    $path = StorageService::saveBase64Image($m['assinatura_base64'], 'assinaturas', 'eq' . $id . '_' . ($i + 1));
                } elseif (!empty($m['assinatura_path'])) {
                    $path = $m['assinatura_path'];
                }
                DocumentoSst::addEquipe($id, $i + 1, $m['nome'] ?? null, $m['funcao'] ?? null, $m['cpf'] ?? null, $path);
            }
        }

        // Assinaturas na ordem em que aconteceram no aparelho (cada tipo só entra uma vez)
        foreach ((array)($data['assinaturas'] ?? []) as $a) {
            if (!is_array($a)) continue;
            $tipo = (string)($a['tipo'] ?? '');
            if (!in_array($tipo, ['tecnico', 'supervisor', 'supervisor_encerramento'], true)) continue;

            $doc = DocumentoSst::getById($id);
            if (!$doc || Assinatura::existe($id, $tipo)) continue;
            if ($tipo !== 'tecnico' && !in_array($authUser->role, ['supervisor', 'admin'], true)) {
                Response::error('Somente supervisor pode enviar a assinatura de ' . $tipo . '.', 403);
            }
            self::aplicarAssinaturaOffline($id, $doc, $tipo, $a, $authUser);
        }

        // Garante o status que o aparelho mostrava (sem retroceder etapas concluídas)
        $doc = DocumentoSst::getById($id);
        if ($doc && $doc['status'] !== $status && !in_array($doc['status'], ['encerrada', 'cancelada'], true)) {
            DocumentoSst::updateStatus($id, $status);
        }

        DocumentoSst::setHash($id, DocumentoSst::calcularHash(DocumentoSst::getById($id)));

        Log::create([
            'user_id' => $authUser->user_id,
            'acao' => 'documento_sync_offline',
            'entidade' => 'documentos_sst',
            'entidade_id' => $id,
            'detalhes' => ['uuid' => $uuid, 'status' => $status, 'assinaturas' => count((array)($data['assinaturas'] ?? []))],
            'ip' => Middleware::clientIp(),
        ]);

        Response::success(DocumentoSst::getById($id), 'Documento sincronizado.');
    }

    /** Aplica uma assinatura feita sem internet (mesmas regras dos endpoints online). */
    private static function aplicarAssinaturaOffline(int $id, array $doc, string $tipo, array $a, object $authUser): void
    {
        $quando = self::dataHoraCliente($a['criado_em'] ?? null);
        $dispositivo = $a['dispositivo'] ?? null;

        if ($tipo === 'tecnico') {
            $path = StorageService::saveBase64Image($a['imagem_base64'] ?? null, 'assinaturas', 'tec_' . $doc['uuid']);
            $hash = DocumentoSst::calcularHash(array_merge($doc, ['assinatura_tecnico' => (string)time()]));
            $assinatura = Assinatura::create([
                'documento_id' => $id,
                'tipo' => 'tecnico',
                'user_id' => (int)$authUser->user_id,
                'imagem_path' => $path,
                'ip' => Middleware::clientIp(),
                'latitude' => $a['latitude'] ?? null,
                'longitude' => $a['longitude'] ?? null,
                'dispositivo' => $dispositivo,
                'hash' => $hash,
            ]);
            if ($quando) {
                Assinatura::setAssinadoEm($assinatura, $quando);
            }
            DocumentoSst::setHash($id, $hash);
            DocumentoSst::updateStatus($id, 'aguardando_aprovacao');
            return;
        }

        if ($tipo === 'supervisor') {
            $autorizacao = strtoupper((string)($a['pt_autorizacao'] ?? 'AUTORIZADA'));
            if (!in_array($autorizacao, ['AUTORIZADA', 'NAO_AUTORIZADA'], true)) {
                $autorizacao = 'AUTORIZADA';
            }
            $emissor = User::getById((int)$authUser->user_id);
            $nome = trim((string)($a['pt_supervisor_nome'] ?? '')) ?: ($emissor['name'] ?? null);
            $registro = trim((string)($a['pt_supervisor_registro'] ?? '')) ?: null;
            if (!$registro) {
                Response::error('Selecione o cargo do supervisor/emissor para autorizar a PT.', 422);
            }

            $path = StorageService::saveBase64Image($a['imagem_base64'] ?? null, 'assinaturas', 'sup_' . $doc['uuid']);
            $assinatura = Assinatura::create([
                'documento_id' => $id,
                'tipo' => 'supervisor',
                'user_id' => (int)$authUser->user_id,
                'imagem_path' => $path,
                'ip' => Middleware::clientIp(),
                'latitude' => $a['latitude'] ?? null,
                'longitude' => $a['longitude'] ?? null,
                'dispositivo' => $dispositivo,
            ]);
            if ($quando) {
                Assinatura::setAssinadoEm($assinatura, $quando);
            }

            DocumentoSst::setSupervisor($id, (int)$authUser->user_id);
            DocumentoSst::setPtLiberacao($id, $autorizacao, $nome, $registro);
            DocumentoSst::updateStatus($id, $autorizacao === 'AUTORIZADA' ? 'liberada' : 'cancelada');

            $doc['supervisor_id'] = (int)$authUser->user_id;
            DocumentoSst::setHash($id, DocumentoSst::calcularHash($doc));

            try {
                $pdfPath = PdfService::gerarDocumento(DocumentoSst::getById($id));
                DocumentoSst::setPdfPath($id, $pdfPath);
            } catch (\Throwable $e) {
                error_log('Falha ao gerar PDF (sync) do documento ' . $id . ': ' . $e->getMessage());
            }
            return;
        }

        // supervisor_encerramento
        $ptStatus = strtoupper((string)($a['pt_encerramento_status'] ?? 'CONCLUIDO'));
        if (!in_array($ptStatus, ['CONCLUIDO', 'SUSPENSO'], true)) {
            $ptStatus = 'CONCLUIDO';
        }
        $path = StorageService::saveBase64Image($a['imagem_base64'] ?? null, 'assinaturas', 'supenc_' . $doc['uuid']);
        $assinatura = Assinatura::create([
            'documento_id' => $id,
            'tipo' => 'supervisor_encerramento',
            'user_id' => (int)$authUser->user_id,
            'imagem_path' => $path,
            'ip' => Middleware::clientIp(),
            'latitude' => $a['latitude'] ?? null,
            'longitude' => $a['longitude'] ?? null,
            'dispositivo' => $dispositivo,
        ]);
        if ($quando) {
            Assinatura::setAssinadoEm($assinatura, $quando);
        }

        $concluido = $ptStatus === 'CONCLUIDO';
        DocumentoSst::marcarEncerrado(
            $id,
            $concluido ? 1 : 0,
            $a['encerramento_obs'] ?? null,
            $ptStatus,
            $concluido ? 'encerrada' : 'cancelada'
        );

        try {
            $pdfPath = PdfService::gerarDocumento(DocumentoSst::getById($id));
            DocumentoSst::setPdfPath($id, $pdfPath);
        } catch (\Throwable $e) {
            error_log('Falha ao gerar PDF (sync) do documento ' . $id . ': ' . $e->getMessage());
        }
    }

    /** Aceita "YYYY-MM-DD HH:MM:SS" vindo do aparelho, com limites de sanidade. */
    private static function dataHoraCliente($valor): ?string
    {
        $valor = trim((string)$valor);
        if ($valor === '') {
            return null;
        }
        $ts = strtotime($valor);
        if ($ts === false) {
            return null;
        }
        $agora = time();
        if ($ts > $agora + 300) {          // no máximo 5 minutos no futuro
            return null;
        }
        if ($ts < $agora - 60 * 86400) {   // no máximo 60 dias para trás
            return null;
        }
        return date('Y-m-d H:i:s', $ts);
    }

    private static function autorizar(object $authUser, array $doc): void
    {
        if ($authUser->role === 'tecnico' && (int)$doc['tecnico_id'] !== (int)$authUser->user_id) {
            Response::error('Acesso negado a este documento.', 403);
        }
    }
}
