<?php

namespace App\Models;

use App\Database;

class Assinatura
{
    public static function create(array $data): int
    {
        $db = Database::getInstance();
        $stmt = $db->prepare(
            'INSERT INTO assinaturas
                (documento_id, tipo, user_id, imagem_path, ip, latitude, longitude, dispositivo, hash, assinado_em)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())'
        );
        $stmt->execute([
            $data['documento_id'],
            $data['tipo'],
            $data['user_id'],
            $data['imagem_path'] ?? null,
            $data['ip'] ?? null,
            $data['latitude'] ?? null,
            $data['longitude'] ?? null,
            $data['dispositivo'] ?? null,
            $data['hash'] ?? null,
        ]);
        return (int)$db->lastInsertId();
    }

    public static function listByDocumento(int $documentoId): array
    {
        $stmt = Database::getInstance()->prepare(
            'SELECT a.*, u.name AS user_nome, u.role AS user_role
             FROM assinaturas a
             INNER JOIN users u ON u.id = a.user_id
             WHERE a.documento_id = ?
             ORDER BY a.id ASC'
        );
        $stmt->execute([$documentoId]);
        return $stmt->fetchAll();
    }

    public static function existe(int $documentoId, string $tipo): bool
    {
        $stmt = Database::getInstance()->prepare(
            'SELECT 1 FROM assinaturas WHERE documento_id = ? AND tipo = ? LIMIT 1'
        );
        $stmt->execute([$documentoId, $tipo]);
        return (bool)$stmt->fetch();
    }
}
