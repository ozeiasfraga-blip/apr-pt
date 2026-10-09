<?php

namespace App\Controllers;

use App\Services\SyncService;
use App\Helpers\Response;
use App\Middleware;
use Exception;

class SyncController
{
    /**
     * POST /api/sync/os  { date?: "YYYY-MM-DD", id_tecnico?: int }
     * Admin (sincronização manual; o cron faz a automática).
     */
    public static function os(): void
    {
        Middleware::requireAdmin();
        $data = json_decode(file_get_contents('php://input'), true) ?? [];

        $date = $data['date'] ?? $_GET['date'] ?? date('Y-m-d');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            Response::error('Data inválida. Use o formato YYYY-MM-DD.', 422);
        }

        $ixcTecnicoId = isset($data['id_tecnico']) && $data['id_tecnico'] !== ''
            ? (int)$data['id_tecnico']
            : (isset($_GET['id_tecnico']) ? (int)$_GET['id_tecnico'] : null);

        try {
            $result = SyncService::ordensServico($date, $ixcTecnicoId);
        } catch (Exception $e) {
            Response::error('Falha na sincronização: ' . $e->getMessage(), 502);
        }

        Response::success($result, "Sincronização concluída ({$result['sincronizadas']} de {$result['total']} OS).");
    }

    /**
     * GET /api/ixc/funcionarios — lista funcionários do IXC para vincular aos usuários.
     * Supervisor/admin.
     */
    public static function funcionarios(): void
    {
        Middleware::requireSupervisor();

        try {
            $rows = SyncService::funcionarios()['registros'];
        } catch (Exception $e) {
            Response::error('Falha ao consultar funcionários no IXC: ' . $e->getMessage(), 502);
        }

        $lista = array_map(fn($r) => [
            'id' => (int)$r['id'],
            'nome' => $r['funcionario'] ?? ('Funcionário ' . $r['id']),
            'funcao' => $r['id_funcao'] ?? null,
            'email' => $r['email'] ?? null,
            'ativo' => $r['ativo'] ?? null,
        ], $rows);

        usort($lista, fn($a, $b) => strcoll($a['nome'], $b['nome']));

        Response::success($lista);
    }
}
