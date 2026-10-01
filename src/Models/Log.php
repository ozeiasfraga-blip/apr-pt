<?php

namespace App\Models;

use App\Database;

class Log
{
    public static function create(array $data): void
    {
        $stmt = Database::getInstance()->prepare(
            'INSERT INTO log_auditoria (user_id, acao, entidade, entidade_id, detalhes, ip)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $data['user_id'] ?? null,
            $data['acao'],
            $data['entidade'] ?? null,
            $data['entidade_id'] ?? null,
            isset($data['detalhes']) ? json_encode($data['detalhes'], JSON_UNESCAPED_UNICODE) : null,
            $data['ip'] ?? null,
        ]);
    }

    public static function listRecent(int $limit = 100): array
    {
        $limit = max(1, min(500, $limit));
        $stmt = Database::getInstance()->query(
            "SELECT l.*, u.name AS user_name
             FROM log_auditoria l
             LEFT JOIN users u ON u.id = l.user_id
             ORDER BY l.id DESC
             LIMIT {$limit}"
        );
        return $stmt->fetchAll();
    }
}
