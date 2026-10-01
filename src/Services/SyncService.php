<?php

namespace App\Services;

use App\Database;
use App\Models\OrdemServico;
use PDO;
use Exception;

class SyncService
{
    /** Limite de novos clientes/cidades resolvidos por execução (evita sincronizações longas). */
    private const MAX_LOOKUPS_POR_RUN = 60;

    /**
     * Sincroniza as OS agendadas para uma data (e, opcionalmente, de um técnico).
     *
     * A API do IXC aceita apenas UM filtro por requisição, então filtramos por
     * `data_agenda >= início do dia` (ordenado crescente) e paramos ao passar do fim do dia.
     *
     * @return array{total:int, sincronizadas:int, erros:array}
     */
    public static function ordensServico(string $date, ?int $ixcTecnicoId = null): array
    {
        $ixc = new IxcService();
        $inicio = $date . ' 00:00:00';
        $fim = $date . ' 23:59:59';
        $pageSize = (int)($_ENV['IXC_PAGE_SIZE'] ?? 100);

        $rows = [];
        $page = 1;
        try {
            do {
                $res = $ixc->list(
                    'su_oss_chamado',
                    [['field' => 'su_oss_chamado.data_agenda', 'value' => $inicio, 'oper' => '>=']],
                    $page,
                    $pageSize,
                    'su_oss_chamado.data_agenda',
                    'asc'
                );
                $lote = $res['registros'];
                $passou = false;

                foreach ($lote as $row) {
                    $da = trim((string)($row['data_agenda'] ?? ''));
                    if ($da === '') {
                        continue;
                    }
                    if ($da > $fim) {         // ordenado asc: daqui pra frente é outro dia
                        $passou = true;
                        break;
                    }
                    if (substr($da, 0, 10) !== $date) {
                        continue;
                    }
                    if ($ixcTecnicoId !== null && (int)($row['id_tecnico'] ?? 0) !== $ixcTecnicoId) {
                        continue;
                    }
                    $rows[] = $row;
                }

                $page++;
            } while (!$passou && count($lote) === $pageSize && $page <= 500);
        } catch (Exception $e) {
            self::registrarLog('su_oss_chamado', 0, 'erro', $e->getMessage());
            throw $e;
        }

        // ── Enriquecimento de nomes ─────────────────────────────
        $assuntos = self::mapaAssuntos($ixc);
        [$clientes, $cidades] = self::mapasClientesCidades($ixc, $rows);

        $count = 0;
        $erros = [];
        foreach ($rows as $row) {
            $row['assunto'] = $assuntos[(int)($row['id_assunto'] ?? 0)] ?? null;
            $row['cliente'] = $clientes[(int)($row['id_cliente'] ?? 0)] ?? null;
            $row['cidade'] = $cidades[(int)($row['id_cidade'] ?? 0)] ?? null;

            try {
                OrdemServico::upsertFromIxc($row);
                $count++;
            } catch (Exception $e) {
                $erros[] = 'OS ' . ($row['id'] ?? '?') . ': ' . $e->getMessage();
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

    /** Mapa id => descrição dos assuntos de OS (lista pequena, buscada por inteiro). */
    private static function mapaAssuntos(IxcService $ixc): array
    {
        $mapa = [];
        try {
            $rows = $ixc->listAll('su_oss_assunto', [], 'su_oss_assunto.id', 10);
            foreach ($rows as $r) {
                $mapa[(int)$r['id']] = $r['assunto'] ?? ($r['descricao'] ?? null);
            }
        } catch (Exception $e) {
            // Enriquecimento é opcional: não interrompe a sincronização
        }
        return $mapa;
    }

    /**
     * Resolve nomes de clientes e cidades usando cache local.
     * Só consulta o IXC para IDs ainda desconhecidos (com limite por execução).
     */
    private static function mapasClientesCidades(IxcService $ixc, array $rows): array
    {
        $idClientes = [];
        $idCidades = [];
        foreach ($rows as $r) {
            if (!empty($r['id_cliente'])) $idClientes[(int)$r['id_cliente']] = true;
            if (!empty($r['id_cidade']))  $idCidades[(int)$r['id_cidade']] = true;
        }

        $clientes = self::nomesCache('clientes_cache', array_keys($idClientes), 'nome');
        $cidades = self::nomesCache('cidades_cache', array_keys($idCidades), 'nome');

        $restantes = self::MAX_LOOKUPS_POR_RUN;

        foreach (array_keys($idClientes) as $id) {
            if (isset($clientes[$id]) || $restantes <= 0) continue;
            try {
                $res = $ixc->list('cliente', [['field' => 'cliente.id', 'value' => $id, 'oper' => '=']], 1, 1);
                $c = $res['registros'][0] ?? null;
                if ($c) {
                    $nome = $c['razao'] ?? ($c['fantasia'] ?? ($c['nome'] ?? null));
                    self::gravarCacheCliente($id, $nome, $c['fantasia'] ?? null, $c['cnpj_cpf'] ?? null);
                    $clientes[$id] = $nome;
                }
            } catch (Exception $e) {}
            $restantes--;
        }

        foreach (array_keys($idCidades) as $id) {
            if (isset($cidades[$id]) || $restantes <= 0) continue;
            try {
                $res = $ixc->list('cidade', [['field' => 'cidade.id', 'value' => $id, 'oper' => '=']], 1, 1);
                $cidade = $res['registros'][0] ?? null;
                if ($cidade) {
                    self::gravarCacheCidade($id, $cidade['nome'] ?? null, $cidade['uf'] ?? null);
                    $cidades[$id] = $cidade['nome'] ?? null;
                }
            } catch (Exception $e) {}
            $restantes--;
        }

        return [$clientes, $cidades];
    }

    private static function nomesCache(string $tabela, array $ids, string $coluna): array
    {
        if (empty($ids)) return [];
        $in = implode(',', array_map('intval', $ids));
        $stmt = Database::getInstance()->query("SELECT ixc_id, {$coluna} FROM {$tabela} WHERE ixc_id IN ({$in})");
        $mapa = [];
        foreach ($stmt->fetchAll() as $r) {
            $mapa[(int)$r['ixc_id']] = $r[$coluna];
        }
        return $mapa;
    }

    private static function gravarCacheCliente(int $id, ?string $nome, ?string $fantasia, ?string $doc): void
    {
        $stmt = Database::getInstance()->prepare(
            'INSERT INTO clientes_cache (ixc_id, nome, fantasia, cnpj_cpf, atualizado_em)
             VALUES (?, ?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE nome=VALUES(nome), fantasia=VALUES(fantasia),
                cnpj_cpf=VALUES(cnpj_cpf), atualizado_em=NOW()'
        );
        $stmt->execute([$id, $nome, $fantasia, $doc]);
    }

    private static function gravarCacheCidade(int $id, ?string $nome, ?string $uf): void
    {
        $stmt = Database::getInstance()->prepare(
            'INSERT INTO cidades_cache (ixc_id, nome, uf, atualizado_em)
             VALUES (?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE nome=VALUES(nome), uf=VALUES(uf), atualizado_em=NOW()'
        );
        $stmt->execute([$id, $nome, $uf]);
    }

    /** Sincroniza funcionários do IXC (base para vincular técnicos). */
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
