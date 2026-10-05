<?php

namespace App\Controllers;

use App\Models\OrdemServico;
use App\Models\DocumentoSst;
use App\Helpers\Response;
use App\Middleware;

class OrdemServicoController
{
    /**
     * Lista as OS. Técnico vê as suas; supervisor/admin pode filtrar.
     * GET /api/os?date=YYYY-MM-DD&id_tecnico=&status=&search=
     */
    public static function index(): void
    {
        $authUser = Middleware::requireAuth();
        $date = $_GET['date'] ?? null;

        if ($authUser->role === 'tecnico') {
            $ixcId = $authUser->ixc_funcionario_id ?? null;
            if (!$ixcId) {
                Response::success([], 'Usuário técnico sem vínculo com funcionário do IXC.');
            }
            $os = OrdemServico::listByTecnico((int)$ixcId, $date);
        } else {
            $os = OrdemServico::list([
                'id_tecnico' => $_GET['id_tecnico'] ?? null,
                'status' => $_GET['status'] ?? null,
                'date' => $date,
                'search' => $_GET['search'] ?? null,
            ]);
        }

        // Anexa o status do documento APR/PT (se existir)
        foreach ($os as &$row) {
            $doc = DocumentoSst::getByOsId((int)$row['id']);
            $row['documento'] = $doc ? [
                'id' => (int)$doc['id'],
                'uuid' => $doc['uuid'],
                'status' => $doc['status'],
                'supervisor_id' => $doc['supervisor_id'] !== null ? (int)$doc['supervisor_id'] : null,
            ] : null;
        }
        unset($row);

        Response::success($os);
    }

    public static function show(string $id): void
    {
        $authUser = Middleware::requireAuth();
        $os = OrdemServico::getById((int)$id);
        if (!$os) {
            Response::error('OS não encontrada.', 404);
        }

        if ($authUser->role === 'tecnico'
            && (int)$os['id_tecnico'] !== (int)($authUser->ixc_funcionario_id ?? 0)) {
            Response::error('Acesso negado a esta OS.', 403);
        }

        $doc = DocumentoSst::getByOsId((int)$os['id']);
        if ($doc) {
            $doc['assinaturas'] = \App\Models\Assinatura::listByDocumento((int)$doc['id']);
            $doc['equipe'] = DocumentoSst::listarEquipe((int)$doc['id']);
        }
        $os['documento'] = $doc;

        // Usuário do técnico vinculado (para o admin/supervisor atribuir a APR corretamente)
        $tecnicoUser = !empty($os['id_tecnico']) ? \App\Models\User::getByIxcId((int)$os['id_tecnico']) : null;
        $os['tecnico_user_id'] = $tecnicoUser ? (int)$tecnicoUser['id'] : null;

        Response::success($os);
    }
}
