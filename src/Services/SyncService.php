<?php

namespace App\Services;

use App\Database;
use App\Models\OrdemServico;
use Exception;

class SyncService
{
    /**
     * Sincroniza as OS agendadas para uma data (e, opcionalmente, de um técnico específico).
     *
     * @return array{total:int, sincronizadas:int, erros:array}
     */
    public static function ordensServico(string $date, ?int $ixcTecnicoId = null): array
    {
        $ixc = new IxcService();

        $filters = [
            ['field' => 'su_oss_chamado.data_agenda', 'value' => $date . ' 00:00:00', 'oper' => '>='],
            ['field' => 'su_oss_chamado.data_agenda', 'value' => $date . ' 23:59:59', 'oper' => '<='],
        ];

        if ($ixcTecnicoId !== null) {
            $filters[] = ['field' => 'su_oss_chamado.id_tecnico', 'value' => $ixcTecnicoId, 'oper' => '='];
        }

        try {
            $rows = $ixc->listAll('su_oss_chamado', $filters, 'su_oss_chamado.id');
        } catch (Exception $e) {
            self::registrarLog('su_oss_chamado', 0, 'erro', $e->getMessage());
            throw $e;
        }

        $count = 0;
        $erros = [];
        foreach ($rows as $row) {
            if (empty($row['id'])) {
                continue;
            }
            try {
                OrdemServico::upsertFromIxc($row);
                $count++;
            } catch (Exception $e) {
                $erros[] = 'OS ' . $row['id'] . ': ' . $e->getMessage();
            }
        }

        self::registrarLog(
            'su_oss_chamado',
            $count,
            empty($erros) ? 'ok' : 'erro',
            empty($erros) ? null : implode(' | ', array_slice($erros, 0, 20))
        );

        return [
            'total' => count($rows),
            'sincronizadas' => $count,
            'erros' => $erros,
        ];
    }

    /**
     * Sincroniza os funcionários do IXC (base para vincular técnicos).
     */
    public static function funcionarios(): array
    {
        $ixc = new IxcService();
        $rows = $ixc->listAll('funcionarios', [], 'funcionarios.id');
        return ['total' => count($rows), 'registros' => $rows];
    }

    private static function registrarLog(string $recurso, int $total, string $status, ?string $mensagem): void
    {
        $stmt = Database::getInstance()->prepare(
            'INSERT INTO sync_log (recurso, total, status, mensagem, iniciado_em, finalizado_em)
             VALUES (?, ?, ?, ?, NOW(), NOW())'
        );
        $stmt->execute([$recurso, $total, $status, $mensagem]);
    }
}
