<?php

namespace App\Controllers;

use App\Models\DocumentoSst;
use App\Models\Assinatura;
use App\Models\OrdemServico;
use App\Models\Log;
use App\Services\StorageService;
use App\Services\PdfService;
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
        ];

        if ($authUser->role === 'tecnico') {
            $filters['tecnico_id'] = (int)$authUser->user_id;
        } elseif (!empty($_GET['tecnico_id'])) {
            $filters['tecnico_id'] = (int)$_GET['tecnico_id'];
        }

        Response::success(DocumentoSst::list($filters));
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
            if ($tecnicoId <= 0) {
                Response::error('tecnico_id é obrigatório para este perfil.', 422);
            }
        }

        $id = DocumentoSst::createOrUpdateByUuid([
            'uuid' => $data['uuid'],
            'os_id' => (int)$data['os_id'],
            'tecnico_id' => $tecnicoId,
            'status' => $data['status'] ?? 'rascunho',
            'nr10' => $data['nr10'] ?? false,
            'nr35' => $data['nr35'] ?? false,
            'riscos' => $data['riscos'] ?? null,
            'epis' => $data['epis'] ?? null,
            'medidas' => $data['medidas'] ?? null,
            'atividades' => $data['atividades'] ?? null,
            'observacoes' => $data['observacoes'] ?? null,
        ]);

        $doc = DocumentoSst::getById($id);
        $hash = DocumentoSst::calcularHash($doc);
        DocumentoSst::setHash($id, $hash);

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
     * POST /api/documentos/{id}/aprovar — assinatura do supervisor (PT liberada).
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
        DocumentoSst::updateStatus((int)$doc['id'], 'liberada');

        $doc['supervisor_id'] = (int)$authUser->user_id;
        DocumentoSst::setHash((int)$doc['id'], DocumentoSst::calcularHash($doc));

        // Gera o PDF consolidado
        try {
            $pdfPath = PdfService::gerarDocumento(DocumentoSst::getById((int)$doc['id']));
            DocumentoSst::setPdfPath((int)$doc['id'], $pdfPath);
        } catch (\Throwable $e) {
            error_log('Falha ao gerar PDF do documento ' . $doc['id'] . ': ' . $e->getMessage());
        }

        Log::create([
            'user_id' => $authUser->user_id,
            'acao' => 'documento_aprovar',
            'entidade' => 'documentos_sst',
            'entidade_id' => (int)$doc['id'],
            'detalhes' => ['assinatura_id' => $assinatura],
            'ip' => Middleware::clientIp(),
        ]);

        Response::success(DocumentoSst::getById((int)$doc['id']), 'PT liberada pelo supervisor.');
    }

    /**
     * POST /api/documentos/{id}/encerrar
     */
    public static function encerrar(string $id): void
    {
        $authUser = Middleware::requireAuth();
        $doc = DocumentoSst::getById((int)$id);
        if (!$doc) {
            Response::error('Documento não encontrado.', 404);
        }
        self::autorizar($authUser, $doc);

        if (!in_array($doc['status'], ['liberada', 'em_execucao'], true)) {
            Response::error('Somente documentos liberados podem ser encerrados (status: ' . $doc['status'] . ').', 409);
        }

        DocumentoSst::marcarEncerrado((int)$doc['id']);

        Log::create([
            'user_id' => $authUser->user_id,
            'acao' => 'documento_encerrar',
            'entidade' => 'documentos_sst',
            'entidade_id' => (int)$doc['id'],
            'ip' => Middleware::clientIp(),
        ]);

        Response::success(DocumentoSst::getById((int)$doc['id']), 'Documento encerrado.');
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
        header('Content-Disposition: inline; filename="apr_pt_' . $doc['uuid'] . '.pdf"');
        header('Content-Length: ' . filesize($full));
        readfile($full);
        exit;
    }

    private static function autorizar(object $authUser, array $doc): void
    {
        if ($authUser->role === 'tecnico' && (int)$doc['tecnico_id'] !== (int)$authUser->user_id) {
            Response::error('Acesso negado a este documento.', 403);
        }
    }
}
