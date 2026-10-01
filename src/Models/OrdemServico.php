<?php

namespace App\Models;

use App\Database;

class OrdemServico
{
    /**
     * Insere/atualiza uma OS a partir de um registro retornado pelo IXC.
     * Chave de idempotência: ixc_id.
     */
    public static function upsertFromIxc(array $row): void
    {
        $db = Database::getInstance();

        $sql = 'INSERT INTO ordens_servico
            (ixc_id, protocolo, tipo, id_assunto, assunto, id_cliente, cliente, id_estrutura,
             id_login, id_tecnico, id_filial, endereco, complemento, referencia, bairro, cidade,
             latitude, longitude, prioridade, status_ixc, data_agenda, data_abertura, data_final,
             mensagem, raw_json, sincronizado_em)
            VALUES
            (:ixc_id, :protocolo, :tipo, :id_assunto, :assunto, :id_cliente, :cliente, :id_estrutura,
             :id_login, :id_tecnico, :id_filial, :endereco, :complemento, :referencia, :bairro, :cidade,
             :latitude, :longitude, :prioridade, :status_ixc, :data_agenda, :data_abertura, :data_final,
             :mensagem, :raw_json, NOW())
            ON DUPLICATE KEY UPDATE
                protocolo = VALUES(protocolo),
                tipo = VALUES(tipo),
                id_assunto = VALUES(id_assunto),
                assunto = VALUES(assunto),
                id_cliente = VALUES(id_cliente),
                cliente = VALUES(cliente),
                id_estrutura = VALUES(id_estrutura),
                id_login = VALUES(id_login),
                id_tecnico = VALUES(id_tecnico),
                id_filial = VALUES(id_filial),
                endereco = VALUES(endereco),
                complemento = VALUES(complemento),
                referencia = VALUES(referencia),
                bairro = VALUES(bairro),
                cidade = VALUES(cidade),
                latitude = VALUES(latitude),
                longitude = VALUES(longitude),
                prioridade = VALUES(prioridade),
                status_ixc = VALUES(status_ixc),
                data_agenda = VALUES(data_agenda),
                data_abertura = VALUES(data_abertura),
                data_final = VALUES(data_final),
                mensagem = VALUES(mensagem),
                raw_json = VALUES(raw_json),
                sincronizado_em = NOW()';

        $stmt = $db->prepare($sql);
        $stmt->execute([
            ':ixc_id' => (int)$row['id'],
            ':protocolo' => self::str($row['protocolo'] ?? null, 30),
            ':tipo' => self::str($row['tipo'] ?? 'C', 1),
            ':id_assunto' => self::intOrNull($row['id_assunto'] ?? null),
            ':assunto' => self::str($row['assunto'] ?? null, 200),
            ':id_cliente' => self::intOrNull($row['id_cliente'] ?? null),
            ':cliente' => self::str($row['cliente'] ?? null, 200),
            ':id_estrutura' => self::intOrNull($row['id_estrutura'] ?? null),
            ':id_login' => self::intOrNull($row['id_login'] ?? null),
            ':id_tecnico' => self::intOrNull($row['id_tecnico'] ?? null),
            ':id_filial' => self::intOrNull($row['id_filial'] ?? null),
            ':endereco' => self::str($row['endereco'] ?? null, 200),
            ':complemento' => self::str($row['complemento'] ?? null, 120),
            ':referencia' => self::str($row['referencia'] ?? null, 120),
            ':bairro' => self::str($row['bairro'] ?? null, 120),
            ':cidade' => self::str($row['cidade'] ?? $row['cidade_nome'] ?? null, 120),
            ':latitude' => self::str($row['latitude'] ?? null, 50),
            ':longitude' => self::str($row['longitude'] ?? null, 50),
            ':prioridade' => self::str($row['prioridade'] ?? 'N', 1),
            ':status_ixc' => self::str($row['status'] ?? null, 5),
            ':data_agenda' => self::dt($row['data_agenda'] ?? null),
            ':data_abertura' => self::dt($row['data_abertura'] ?? null),
            ':data_final' => self::dt($row['data_final'] ?? null),
            ':mensagem' => $row['mensagem'] ?? null,
            ':raw_json' => json_encode($row, JSON_UNESCAPED_UNICODE),
        ]);
    }

    public static function findByIxcId(int $ixcId): ?array
    {
        $stmt = Database::getInstance()->prepare('SELECT * FROM ordens_servico WHERE ixc_id = ?');
        $stmt->execute([$ixcId]);
        return $stmt->fetch() ?: null;
    }

    public static function getById(int $id): ?array
    {
        $stmt = Database::getInstance()->prepare('SELECT * FROM ordens_servico WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Lista OS por técnico do IXC, opcionalmente filtrando por data de agendamento (Y-m-d).
     */
    public static function listByTecnico(int $ixcTecnicoId, ?string $date = null, ?string $since = null): array
    {
        $sql = 'SELECT os.*, fc.nome AS tecnico_nome
                FROM ordens_servico os
                LEFT JOIN funcionarios_cache fc ON fc.ixc_id = os.id_tecnico
                WHERE os.id_tecnico = ?';
        $params = [$ixcTecnicoId];

        if ($date !== null) {
            $sql .= ' AND DATE(data_agenda) = ?';
            $params[] = $date;
        }
        if ($since !== null) {
            $sql .= ' AND updated_at >= ?';
            $params[] = $since;
        }

        $sql .= ' ORDER BY data_agenda ASC, prioridade DESC';
        $stmt = Database::getInstance()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * Lista OS com filtros livres (uso do supervisor).
     */
    public static function list(array $filters = [], int $limit = 200, int $offset = 0): array
    {
        $sql = 'SELECT os.*, fc.nome AS tecnico_nome,
                    (SELECT d.status FROM documentos_sst d WHERE d.os_id = os.id ORDER BY d.id DESC LIMIT 1) AS doc_status
                FROM ordens_servico os
                LEFT JOIN funcionarios_cache fc ON fc.ixc_id = os.id_tecnico
                WHERE 1=1';
        $params = [];

        if (!empty($filters['id_tecnico'])) {
            $sql .= ' AND os.id_tecnico = ?';
            $params[] = (int)$filters['id_tecnico'];
        }
        if (!empty($filters['status'])) {
            $sql .= ' AND os.status_ixc = ?';
            $params[] = $filters['status'];
        }
        if (!empty($filters['date'])) {
            $sql .= ' AND DATE(os.data_agenda) = ?';
            $params[] = $filters['date'];
        }
        if (!empty($filters['search'])) {
            $sql .= ' AND (os.cliente LIKE ? OR os.protocolo LIKE ? OR os.endereco LIKE ? OR fc.nome LIKE ?)';
            $like = '%' . $filters['search'] . '%';
            array_push($params, $like, $like, $like, $like);
        }

        $limit = max(1, min(500, $limit));
        $offset = max(0, $offset);
        $sql .= " ORDER BY os.data_agenda DESC, os.id DESC LIMIT {$limit} OFFSET {$offset}";

        $stmt = Database::getInstance()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    private static function str(mixed $value, int $max): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        return mb_substr(trim((string)$value), 0, $max);
    }

    private static function intOrNull(mixed $value): ?int
    {
        if ($value === null || $value === '' || $value === '0') {
            return $value === '0' || $value === 0 ? (int)$value : null;
        }
        return (int)$value;
    }

    private static function dt(mixed $value): ?string
    {
        if (empty($value) || $value === '0000-00-00 00:00:00') {
            return null;
        }
        $ts = strtotime((string)$value);
        return $ts ? date('Y-m-d H:i:s', $ts) : null;
    }
}
