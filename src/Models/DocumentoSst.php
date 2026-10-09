<?php

namespace App\Models;

use App\Database;

class DocumentoSst
{
    public const STATUSES = [
        'rascunho',
        'aguardando_aprovacao',
        'liberada',
        'em_execucao',
        'encerrada',
        'cancelada',
    ];

    /**
     * Cria ou atualiza um documento usando o uuid gerado no app (idempotente para sync offline).
     */
    public static function createOrUpdateByUuid(array $data): int
    {
        $db = Database::getInstance();

        $existing = self::findByUuid($data['uuid']);
        $payload = [
            'os_id' => (int)$data['os_id'],
            'tecnico_id' => (int)$data['tecnico_id'],
            'nr10' => !empty($data['nr10']) ? 1 : 0,
            'nr35' => !empty($data['nr35']) ? 1 : 0,
            'normas' => self::json($data['normas'] ?? null),
            'riscos' => self::json($data['riscos'] ?? null),
            'epis' => self::json($data['epis'] ?? null),
            'epcs' => self::json($data['epcs'] ?? null),
            'checklist' => self::json($data['checklist'] ?? null),
            'ferramentas' => self::json($data['ferramentas'] ?? null),
            'medidas' => self::json($data['medidas'] ?? null),
            'restricoes' => $data['restricoes'] ?? null,
            'treinamento_nr10' => !empty($data['treinamento_nr10']) ? 1 : 0,
            'treinamento_nr35' => !empty($data['treinamento_nr35']) ? 1 : 0,
            'aso_apto' => !empty($data['aso_apto']) ? 1 : 0,
            'atividades' => $data['atividades'] ?? null,
            'local_atividade' => $data['local_atividade'] ?? null,
            'responsavel_apr' => $data['responsavel_apr'] ?? null,
            'equipe_envolvida' => $data['equipe_envolvida'] ?? null,
            'etapas' => $data['etapas'] ?? null,
            'observacoes' => $data['observacoes'] ?? null,
            'validade_inicio' => self::dt($data['validade_inicio'] ?? null),
            'validade_fim' => self::dt($data['validade_fim'] ?? null),
        ];

        if ($existing) {
            // Não sobrescreve o documento depois de assinado/encerrado
            if (in_array($existing['status'], ['liberada', 'em_execucao', 'encerrada'], true)) {
                return (int)$existing['id'];
            }
            $sets = [];
            $values = [];
            foreach ($payload as $k => $v) {
                $sets[] = "{$k} = ?";
                $values[] = $v;
            }
            $values[] = $existing['id'];
            $stmt = $db->prepare('UPDATE documentos_sst SET ' . implode(', ', $sets) . ' WHERE id = ?');
            $stmt->execute($values);
            return (int)$existing['id'];
        }

        $stmt = $db->prepare(
            'INSERT INTO documentos_sst
                (uuid, os_id, tecnico_id, status, nr10, nr35, normas, riscos, epis, epcs, checklist,
                 ferramentas, medidas, restricoes, treinamento_nr10, treinamento_nr35, aso_apto,
                 atividades, local_atividade, responsavel_apr, equipe_envolvida, etapas, observacoes,
                 validade_inicio, validade_fim)
             VALUES
                (:uuid, :os_id, :tecnico_id, :status, :nr10, :nr35, :normas, :riscos, :epis, :epcs, :checklist,
                 :ferramentas, :medidas, :restricoes, :treinamento_nr10, :treinamento_nr35, :aso_apto,
                 :atividades, :local_atividade, :responsavel_apr, :equipe_envolvida, :etapas, :observacoes,
                 :validade_inicio, :validade_fim)'
        );
        $stmt->execute([
            ':uuid' => $data['uuid'],
            ':os_id' => $payload['os_id'],
            ':tecnico_id' => $payload['tecnico_id'],
            ':status' => in_array($data['status'] ?? '', self::STATUSES, true) ? $data['status'] : 'rascunho',
            ':nr10' => $payload['nr10'],
            ':nr35' => $payload['nr35'],
            ':normas' => $payload['normas'],
            ':riscos' => $payload['riscos'],
            ':epis' => $payload['epis'],
            ':epcs' => $payload['epcs'],
            ':checklist' => $payload['checklist'],
            ':ferramentas' => $payload['ferramentas'],
            ':medidas' => $payload['medidas'],
            ':restricoes' => $payload['restricoes'],
            ':treinamento_nr10' => $payload['treinamento_nr10'],
            ':treinamento_nr35' => $payload['treinamento_nr35'],
            ':aso_apto' => $payload['aso_apto'],
            ':atividades' => $payload['atividades'],
            ':local_atividade' => $payload['local_atividade'],
            ':responsavel_apr' => $payload['responsavel_apr'],
            ':equipe_envolvida' => $payload['equipe_envolvida'],
            ':etapas' => $payload['etapas'],
            ':observacoes' => $payload['observacoes'],
            ':validade_inicio' => $payload['validade_inicio'],
            ':validade_fim' => $payload['validade_fim'],
        ]);

        return (int)$db->lastInsertId();
    }

    public static function findByUuid(string $uuid): ?array
    {
        $stmt = Database::getInstance()->prepare('SELECT * FROM documentos_sst WHERE uuid = ?');
        $stmt->execute([$uuid]);
        return $stmt->fetch() ?: null;
    }

    public static function getById(int $id): ?array
    {
        $stmt = Database::getInstance()->prepare('SELECT * FROM documentos_sst WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    public static function getByOsId(int $osId): ?array
    {
        $stmt = Database::getInstance()->prepare(
            'SELECT * FROM documentos_sst WHERE os_id = ? ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute([$osId]);
        return $stmt->fetch() ?: null;
    }

    public static function list(array $filters = [], int $limit = 200, int $offset = 0): array
    {
        $sql = 'SELECT d.*, os.ixc_id, os.cliente, os.endereco, os.data_agenda, os.protocolo,
                       u.name AS tecnico_nome, s.name AS supervisor_nome
                FROM documentos_sst d
                INNER JOIN ordens_servico os ON os.id = d.os_id
                INNER JOIN users u ON u.id = d.tecnico_id
                LEFT JOIN users s ON s.id = d.supervisor_id
                WHERE 1=1';
        $params = [];

        if (!empty($filters['tecnico_id'])) {
            $sql .= ' AND d.tecnico_id = ?';
            $params[] = (int)$filters['tecnico_id'];
        }
        if (!empty($filters['status'])) {
            $sql .= ' AND d.status = ?';
            $params[] = $filters['status'];
        }
        if (!empty($filters['os_id'])) {
            $sql .= ' AND d.os_id = ?';
            $params[] = (int)$filters['os_id'];
        }
        if (!empty($filters['date'])) {
            $sql .= ' AND DATE(os.data_agenda) = ?';
            $params[] = $filters['date'];
        }
        if (!empty($filters['search'])) {
            $sql .= ' AND (os.cliente LIKE ? OR os.protocolo LIKE ? OR os.ixc_id LIKE ? OR u.name LIKE ?)';
            $like = '%' . $filters['search'] . '%';
            array_push($params, $like, $like, $like, $like);
        }

        $limit = max(1, min(500, $limit));
        $offset = max(0, $offset);
        $sql .= " ORDER BY d.id DESC LIMIT {$limit} OFFSET {$offset}";

        $stmt = Database::getInstance()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /** Contagem de documentos por status (opcionalmente de um técnico). */    public static function contarPorStatus(?int $tecnicoId = null): array
    {
        $sql = 'SELECT status, COUNT(*) AS qtd FROM documentos_sst';
        $params = [];
        if ($tecnicoId !== null) {
            $sql .= ' WHERE tecnico_id = ?';
            $params[] = $tecnicoId;
        }
        $sql .= ' GROUP BY status';
        $stmt = Database::getInstance()->prepare($sql);
        $stmt->execute($params);

        $out = array_fill_keys(self::STATUSES, 0);
        foreach ($stmt->fetchAll() as $r) {
            $out[$r['status']] = (int)$r['qtd'];
        }
        $out['total'] = array_sum($out);
        return $out;
    }

    public static function updateStatus(int $id, string $status): void
    {
        $stmt = Database::getInstance()->prepare('UPDATE documentos_sst SET status = ? WHERE id = ?');
        $stmt->execute([$status, $id]);
    }

    public static function setSupervisor(int $id, int $supervisorId): void
    {
        $stmt = Database::getInstance()->prepare('UPDATE documentos_sst SET supervisor_id = ? WHERE id = ?');
        $stmt->execute([$supervisorId, $id]);
    }

    public static function setPtLiberacao(int $id, string $autorizacao, ?string $nome, ?string $registro): void
    {
        $stmt = Database::getInstance()->prepare(
            'UPDATE documentos_sst SET pt_autorizacao = ?, pt_supervisor_nome = ?, pt_supervisor_registro = ? WHERE id = ?'
        );
        $stmt->execute([$autorizacao, $nome, $registro, $id]);
    }

    public static function marcarInicio(int $id): void
    {
        $stmt = Database::getInstance()->prepare(
            "UPDATE documentos_sst SET status = 'em_execucao', iniciado_em = NOW() WHERE id = ?"
        );
        $stmt->execute([$id]);
    }

    public static function marcarEncerrado(int $id, ?int $conforme = null, ?string $obs = null, ?string $ptStatus = null, string $status = 'encerrada'): void
    {
        $stmt = Database::getInstance()->prepare(
            'UPDATE documentos_sst
             SET status = ?, encerrado_em = NOW(), servico_conforme = ?, encerramento_obs = ?, pt_encerramento_status = ?
             WHERE id = ?'
        );
        $stmt->execute([$status, $conforme, $obs, $ptStatus, $id]);
    }

    public static function setPdfPath(int $id, string $path): void
    {
        $stmt = Database::getInstance()->prepare('UPDATE documentos_sst SET pdf_path = ? WHERE id = ?');
        $stmt->execute([$path, $id]);
    }

    public static function setPdfDriveId(int $id, string $driveId): void
    {
        $stmt = Database::getInstance()->prepare('UPDATE documentos_sst SET pdf_drive_id = ? WHERE id = ?');
        $stmt->execute([$driveId, $id]);
    }

    public static function limparEquipe(int $docId): void
    {
        $stmt = Database::getInstance()->prepare('DELETE FROM documento_equipe WHERE documento_id = ?');
        $stmt->execute([$docId]);
    }

    public static function addEquipe(int $docId, int $ordem, ?string $nome, ?string $funcao, ?string $cpf, ?string $assinaturaPath): void
    {
        $stmt = Database::getInstance()->prepare(
            'INSERT INTO documento_equipe (documento_id, ordem, nome, funcao, cpf, assinatura_path, assinado_em)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$docId, $ordem, $nome, $funcao, $cpf, $assinaturaPath, $assinaturaPath ? date('Y-m-d H:i:s') : null]);
    }

    public static function listarEquipe(int $docId): array
    {
        $stmt = Database::getInstance()->prepare(
            'SELECT * FROM documento_equipe WHERE documento_id = ? ORDER BY ordem, id'
        );
        $stmt->execute([$docId]);
        return $stmt->fetchAll();
    }

    public static function setHash(int $id, string $hash): void
    {
        $stmt = Database::getInstance()->prepare('UPDATE documentos_sst SET hash_documento = ? WHERE id = ?');
        $stmt->execute([$hash, $id]);
    }

    /** Preserva a data/hora em que o documento foi criado no aparelho (preenchimento offline). */
    public static function setCriadoEm(int $id, string $quando): void
    {
        $stmt = Database::getInstance()->prepare('UPDATE documentos_sst SET created_at = ? WHERE id = ?');
        $stmt->execute([$quando, $id]);
    }

    /**
     * Gera um hash encadeado (integridade do documento) a partir do conteúdo relevante.
     */
    public static function calcularHash(array $doc): string
    {
        $base = json_encode([
            'uuid' => $doc['uuid'] ?? null,
            'os_id' => $doc['os_id'] ?? null,
            'tecnico_id' => $doc['tecnico_id'] ?? null,
            'nr10' => $doc['nr10'] ?? null,
            'nr35' => $doc['nr35'] ?? null,
            'riscos' => $doc['riscos'] ?? null,
            'epis' => $doc['epis'] ?? null,
            'medidas' => $doc['medidas'] ?? null,
            'observacoes' => $doc['observacoes'] ?? null,
            'hash_anterior' => $doc['hash_documento'] ?? null,
        ], JSON_UNESCAPED_UNICODE);

        return hash('sha256', $base);
    }

    private static function json(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_string($value)) {
            // Já pode vir como string JSON do app
            json_decode($value);
            return json_last_error() === JSON_ERROR_NONE ? $value : json_encode($value, JSON_UNESCAPED_UNICODE);
        }
        return json_encode($value, JSON_UNESCAPED_UNICODE);
    }

    private static function dt(mixed $value): ?string
    {
        if (empty($value)) {
            return null;
        }
        $ts = strtotime((string)$value);
        return $ts ? date('Y-m-d H:i:s', $ts) : null;
    }
}
